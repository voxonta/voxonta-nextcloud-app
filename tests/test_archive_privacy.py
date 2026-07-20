"""You may only read meetings you were in.

The backend isolates by tenant, so on its own it returns the whole company's
calls. Scoping to the person asking happens in this app, which means these tests
guard the only thing standing between one employee and everyone else's
conversations.

Run from the repository root:
    python3 -m pytest tests -v
"""
from __future__ import annotations

import asyncio
import os
import sys
from types import SimpleNamespace

import pytest

sys.path.insert(0, os.path.abspath(
    os.path.join(os.path.dirname(__file__), "..", "ex_app", "lib")))

pytest.importorskip("fastapi")

import archive_api  # noqa: E402
from fastapi import HTTPException  # noqa: E402


def _nc(user):
    return SimpleNamespace(user=user)


def _backend(monkeypatch, meetings=None, meeting=None, calls=None):
    """Replace the backend with canned answers, recording what was asked."""
    async def fake_get(path, *, params=None):
        if calls is not None:
            calls.append((path, params))
        if path == "/v1/meetings":
            return {"meetings": meetings or []}
        if path.endswith("/transcript"):
            return {"segments": [{"text": "secret"}]}
        return meeting or {}
    monkeypatch.setattr(archive_api, "_get", fake_get)


def test_listing_is_scoped_to_the_caller(monkeypatch):
    """The backend must be asked for one person's meetings, not for all."""
    calls = []
    _backend(monkeypatch, meetings=[{"session_id": "s1"}], calls=calls)
    request = SimpleNamespace(query_params={})

    asyncio.run(archive_api.list_meetings(request, _nc("alice")))

    path, params = calls[0]
    assert params["user"] == "alice", \
        "the whole company's meetings were requested, not just this person's"


def test_a_client_cannot_ask_for_someone_elses_meetings(monkeypatch):
    """A `?user=` in the query is a request to read another person's calls."""
    calls = []
    _backend(monkeypatch, meetings=[], calls=calls)
    request = SimpleNamespace(query_params={"user": "bob", "limit": "50"})

    asyncio.run(archive_api.list_meetings(request, _nc("alice")))

    _, params = calls[0]
    assert params["user"] == "alice", "the query string overrode the session"


def test_meeting_you_did_not_attend_is_not_readable(monkeypatch):
    """Knowing an id is not permission — ids travel in links and chat."""
    _backend(monkeypatch, meeting={"session_id": "s1", "participants": ["bob"]})

    with pytest.raises(HTTPException) as exc:
        asyncio.run(archive_api.get_meeting("s1", _nc("alice")))
    assert exc.value.status_code == 404


def test_refusal_does_not_reveal_that_the_meeting_exists(monkeypatch):
    """403 would confirm there is a call with that id. 404 says nothing."""
    _backend(monkeypatch, meeting={"session_id": "s1", "participants": ["bob"]})

    with pytest.raises(HTTPException) as exc:
        asyncio.run(archive_api.get_meeting("s1", _nc("alice")))
    assert exc.value.status_code == 404, "a 403 leaks the meeting's existence"


def test_transcript_is_checked_too(monkeypatch):
    """The interesting content is the transcript — checking only the metadata
    endpoint would leave the actual words open."""
    _backend(monkeypatch, meeting={"session_id": "s1", "participants": ["bob"]})

    with pytest.raises(HTTPException) as exc:
        asyncio.run(archive_api.get_transcript("s1", _nc("alice")))
    assert exc.value.status_code == 404


def test_analysis_is_checked_too(monkeypatch):
    """Summaries paraphrase the conversation — same rule."""
    _backend(monkeypatch, meeting={"session_id": "s1", "participants": ["bob"]})

    with pytest.raises(HTTPException) as exc:
        asyncio.run(archive_api.list_analysis("s1", _nc("alice")))
    assert exc.value.status_code == 404


def test_a_participant_can_read_their_own_meeting(monkeypatch):
    _backend(monkeypatch, meeting={"session_id": "s1",
                                   "participants": ["alice", "bob"]})
    got = asyncio.run(archive_api.get_meeting("s1", _nc("alice")))
    assert got["session_id"] == "s1"


def test_an_unidentified_caller_gets_nothing(monkeypatch):
    """If we cannot tell who is asking, show nothing rather than everything."""
    _backend(monkeypatch, meetings=[{"session_id": "s1"}])
    request = SimpleNamespace(query_params={})

    with pytest.raises(HTTPException) as exc:
        asyncio.run(archive_api.list_meetings(request, _nc("")))
    assert exc.value.status_code == 401
