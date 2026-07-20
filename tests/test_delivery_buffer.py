"""Undelivered calls must survive an unavailable backend.

By the time a call is handed over it is already over: the audio is gone and this
event is the only remaining copy of what was said. A backend that happens to be
restarting must not cost the transcript — nobody can reproduce it.

These tests drive the runner's delivery path against a backend that fails, comes
back, and fails again.

Run from the repository root:
    python3 -m pytest tests -v
"""
from __future__ import annotations

import asyncio
import os
import sys
import tempfile

import pytest

sys.path.insert(0, os.path.abspath(
    os.path.join(os.path.dirname(__file__), "..", "ex_app", "lib")))

pytest.importorskip("talk_capture")

from talk_capture.outbox import Outbox  # noqa: E402

import capture_runner  # noqa: E402


class _Runner(capture_runner.CaptureRunner):
    """Runner with the network replaced by a switch we control."""

    def __init__(self, outbox, *, backend_up: bool, status=None):
        self.config = type("C", (), {
            "nc_capture_s2_url": "http://backend/v1/events",
            "s2_intake_token": "",
            "nc_capture_replay_interval": 0.01,
        })()
        self._outbox = outbox
        self._http = None
        self.backend_up = backend_up
        self.status = status          # None = network error; int = HTTP status
        self.delivered: list[str] = []

    async def _deliver(self, session_id, event):
        """Stand-in for the network: `status` lets a test say HOW it failed."""
        if not self.backend_up:
            return False, self.status
        self.delivered.append(session_id)
        return True, 200


def _finished():
    """Minimal finished call — only what the serialiser reads."""
    return type("F", (), {
        "transcript": {"sid1": [{"time": 0.0, "end": 1.0, "text": "привет"}]},
        "speakers": {"sid1": "Alice"},
        "participant_user_ids": {"alice"},
        "name_to_uid": {"Alice": "alice"},
        "presence_events": [],
        "uncaptured": [],
        "attendees": None,
        "present_count": 1,
        "room_token": "room1",
        "room_name": "Standup",
        "room_type": 2,
        "call_start_ts": 1000.0,
        "call_end_ts": 1100.0,
        "language": "ru",
        "local_tz": "Europe/Moscow",
        "speaker_stat_inputs": [],
        "source": "nc",
    })()


def _outbox():
    return Outbox(tempfile.NamedTemporaryFile(suffix=".db", delete=False).name)


def test_transcript_is_kept_when_the_backend_is_down():
    ob = _outbox()
    runner = _Runner(ob, backend_up=False)
    asyncio.run(runner._emit(_finished(), "sess-1"))

    pending = ob.all_pending()
    assert len(pending) == 1, "the transcript was lost when the backend was down"
    session_id, event = pending[0]
    assert session_id == "sess-1"
    # The buffered copy must be the whole event, not a reference to something
    # that no longer exists.
    assert event["transcript"]["segments"][0]["text"] == "привет"
    assert event["publish_context"]["room_name"] == "Standup"


def test_delivered_calls_are_not_kept():
    ob = _outbox()
    runner = _Runner(ob, backend_up=True)
    asyncio.run(runner._emit(_finished(), "sess-2"))

    assert ob.all_pending() == [], "a delivered call was left in the buffer"
    assert runner.delivered == ["sess-2"]


def test_buffered_call_is_delivered_once_the_backend_returns():
    ob = _outbox()
    runner = _Runner(ob, backend_up=False)
    asyncio.run(runner._emit(_finished(), "sess-3"))
    assert len(ob.all_pending()) == 1

    runner.backend_up = True

    async def replay_once():
        # Drive one pass directly: the loop's own tick is 30s, and the point
        # here is the retry behaviour, not the timer.
        for session_id, event, attempts in ob.due(now=1e12):
            await runner._attempt(session_id, event, attempts)

    asyncio.run(replay_once())
    assert runner.delivered == ["sess-3"]
    assert ob.all_pending() == [], "delivered call still buffered"


def test_buffer_survives_a_restart():
    """The buffer matters most exactly when the container restarts — that is
    why it lives on the persistent volume rather than in memory."""
    path = tempfile.NamedTemporaryFile(suffix=".db", delete=False).name
    ob = Outbox(path)
    runner = _Runner(ob, backend_up=False)
    asyncio.run(runner._emit(_finished(), "sess-4"))
    ob.close()

    reopened = Outbox(path)               # "restart"
    pending = reopened.all_pending()
    assert len(pending) == 1 and pending[0][0] == "sess-4"


def test_retry_does_not_duplicate_the_entry():
    """Replays re-send the same session id; receivers deduplicate by it, and the
    buffer must not grow with every attempt."""
    ob = _outbox()
    runner = _Runner(ob, backend_up=False)
    asyncio.run(runner._emit(_finished(), "sess-5"))
    asyncio.run(runner._emit(_finished(), "sess-5"))
    assert len(ob.all_pending()) == 1


def test_a_rejected_event_is_not_retried_forever():
    """A backend that cannot parse the event never will. Retrying a 400 forever
    buries real outages in noise — park it for a human instead."""
    ob = _outbox()
    runner = _Runner(ob, backend_up=False, status=400)
    asyncio.run(runner._emit(_finished(), "sess-bad"))

    assert ob.due(now=1e12) == [], "a permanently rejected event is still retried"
    assert ob.dead_count() == 1
    # Kept, not deleted: the transcript cannot be reproduced.
    assert len(ob.all_pending()) == 1


def test_server_errors_keep_retrying():
    """503 is temporary — exactly what the buffer exists for."""
    ob = _outbox()
    runner = _Runner(ob, backend_up=False, status=503)
    asyncio.run(runner._emit(_finished(), "sess-503"))

    assert ob.dead_count() == 0
    assert len(ob.due(now=1e12)) == 1


def test_auth_failures_keep_retrying():
    """401 usually means an administrator has to fix a token — once they do,
    the buffered call should still go through."""
    ob = _outbox()
    runner = _Runner(ob, backend_up=False, status=401)
    asyncio.run(runner._emit(_finished(), "sess-401"))
    assert ob.dead_count() == 0


def test_backoff_grows_and_is_not_due_immediately():
    """Without this the loop would hammer a struggling backend every tick."""
    from talk_capture.outbox import next_delay

    ob = _outbox()
    runner = _Runner(ob, backend_up=False, status=503)
    asyncio.run(runner._emit(_finished(), "sess-wait"))

    assert ob.due() == [], "retried immediately — no backoff applied"
    assert next_delay(1) < next_delay(5) < next_delay(20), "delay does not grow"
    # Ceiling, so an outage never pushes the retry a week out.
    assert next_delay(100) <= 900 * 1.25


def test_giving_up_keeps_the_transcript():
    """After many failures we stop retrying — but the call is still there."""
    from talk_capture.outbox import MAX_ATTEMPTS

    ob = _outbox()
    runner = _Runner(ob, backend_up=False, status=503)
    asyncio.run(runner._emit(_finished(), "sess-many"))
    for _ in range(MAX_ATTEMPTS):
        ob.mark_failed("sess-many", delay=0)
    asyncio.run(runner._attempt("sess-many", {}, MAX_ATTEMPTS))

    assert ob.dead_count() == 1
    assert len(ob.all_pending()) == 1, "the transcript was thrown away"
