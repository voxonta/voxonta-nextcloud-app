"""Whether recording is on, per conversation — shared by the bot and the capture.

Two different things can tell us a call must not be recorded:

  * the Talk bot, when someone types the opt-out command. Nextcloud pushes that
    to us, so it arrives immediately and — importantly — it also works in
    one-to-one conversations, where polling the chat over the REST API does not.
  * the capture's own chat polling, which is the fallback in group rooms.

They meet here. The bot writes, the capture reads before every audio frame, and
the answer is deliberately biased: unknown conversation means recording is
allowed (the default is to transcribe), but once someone has opted out, that
sticks until they say otherwise.

State lives in memory on purpose. It describes a call that is happening right
now; if the app restarts mid-call the capture restarts with it, and a stale
"stopped" flag surviving a restart would be worse than re-asking.
"""
from __future__ import annotations

import logging
import time

logger = logging.getLogger(__name__)

# room_token -> (recording, when, who)
_state: dict[str, tuple[bool, float, str]] = {}


def set_recording(room_token: str, recording: bool, *, actor: str = "") -> None:
    """Record someone's decision about this conversation."""
    _state[room_token] = (recording, time.time(), actor)
    logger.info("recording=%s for %s (by %s)", recording, room_token,
                actor or "unknown")


def is_recording(room_token: str) -> bool:
    """Should this conversation be transcribed right now?

    Defaults to True: a conversation nobody has spoken about is transcribed,
    which is what the administrator enabled the app for. Only an explicit
    opt-out turns it off.
    """
    entry = _state.get(room_token)
    return True if entry is None else entry[0]


def opted_out(room_token: str) -> bool:
    """True only if someone actively stopped recording here."""
    entry = _state.get(room_token)
    return entry is not None and not entry[0]


def forget(room_token: str) -> None:
    """Drop the state when a call ends.

    Called on call teardown so the next call in the same room starts from the
    default. An opt-out applies to the call it was made in — carrying it over
    silently would be a different promise than the one the app makes.
    """
    _state.pop(room_token, None)


def snapshot() -> dict[str, dict]:
    """Current state, for diagnostics and the health endpoint."""
    return {
        token: {"recording": rec, "since": when, "actor": actor}
        for token, (rec, when, actor) in _state.items()
    }
