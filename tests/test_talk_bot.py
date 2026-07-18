"""Opt-out command parsing.

This is consent, not a feature toggle: if the bot misreads "/без-записи", someone
who asked not to be recorded is recorded anyway. So the matching is exact and
these tests pin the edges — prefixes, punctuation, ordinary sentences that merely
mention the command.

Run from the repository root:
    python3 -m pytest tests -v
"""
from __future__ import annotations

import asyncio
import os
import sys
from types import SimpleNamespace

sys.path.insert(0, os.path.abspath(
    os.path.join(os.path.dirname(__file__), "..", "ex_app", "lib")))

import recording_state  # noqa: E402
import talk_bot  # noqa: E402


def _msg(text: str, object_name: str = "message"):
    return SimpleNamespace(
        object_name=object_name,
        object_content={"message": text},
        conversation_token="room1",
        actor_display_name="Alice",
    )


def _run(text: str, object_name: str = "message", token: str = "room1",
         reset: bool = True):
    """Drive handle_message with the outgoing reply captured.

    reset=False keeps whatever decision was already made for this conversation —
    needed to check that a later message does not silently undo an opt-out.
    """
    if reset:
        recording_state.forget(token)
    sent = []

    async def fake_send(message, reply_to, *a, **kw):
        sent.append(message)
        return None, ""

    original = talk_bot.BOT.send_message
    talk_bot.BOT.send_message = fake_send
    try:
        msg = _msg(text, object_name)
        msg.conversation_token = token
        asyncio.run(talk_bot.handle_message(msg))
    finally:
        talk_bot.BOT.send_message = original
    return sent


def test_stop_command_is_answered():
    sent = _run("/без-записи")
    assert sent, "opt-out was ignored — the person is still being recorded"
    assert "stopped" in sent[0].lower()


def test_resume_command_is_answered():
    sent = _run("/запись")
    assert sent and "resumed" in sent[0].lower()


def test_english_aliases_work():
    """The room may be English-speaking; consent must not depend on language."""
    assert _run("/no-record")
    assert _run("/transcribe")


def test_command_is_case_and_space_insensitive():
    assert _run("  /БЕЗ-ЗАПИСИ  "), "typing it differently must still opt out"


def test_a_sentence_mentioning_the_command_is_not_a_command():
    """Discussing the command is not invoking it — otherwise explaining the
    feature to a colleague would silently stop the recording."""
    assert not _run("напиши /без-записи чтобы выключить запись")
    assert not _run("/записать протокол встречи")


def test_ordinary_chatter_is_ignored():
    """The bot sees every message in the room. Replying to any of them would
    make it unusable."""
    assert not _run("да, согласен")
    assert not _run("")


def test_non_message_objects_are_ignored():
    """Reactions and system events arrive through the same callback."""
    assert not _run("/без-записи", object_name="reaction")


def test_opt_out_actually_stops_recording():
    """The reply is not the point — the capture must see the decision. A bot
    that answers politely while recording continues is worse than no bot."""
    _run("/без-записи", token="room-optout")
    assert recording_state.is_recording("room-optout") is False
    assert recording_state.opted_out("room-optout") is True


def test_resume_turns_recording_back_on():
    _run("/без-записи", token="room-resume")
    _run("/запись", token="room-resume")
    assert recording_state.is_recording("room-resume") is True


def test_unknown_conversation_is_recorded_by_default():
    """No opt-out means transcribe — that is what the admin enabled the app for."""
    recording_state.forget("room-fresh")
    assert recording_state.is_recording("room-fresh") is True
    assert recording_state.opted_out("room-fresh") is False


def test_chatter_does_not_change_the_decision():
    _run("/без-записи", token="room-keep")
    _run("да, согласен", token="room-keep", reset=False)   # ordinary message
    assert recording_state.is_recording("room-keep") is False, \
        "an unrelated message must not undo someone's opt-out"


def test_state_is_dropped_when_the_call_ends():
    """An opt-out applies to the call it was made in. Carrying it into the next
    call would be a different promise than the app makes."""
    _run("/без-записи", token="room-ended")
    recording_state.forget("room-ended")
    assert recording_state.is_recording("room-ended") is True
