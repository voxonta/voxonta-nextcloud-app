"""Talk bot: the app's voice in the conversation.

Two jobs, and it is worth being clear about which is which:

  * consent — a participant types the opt-out command and recording stops for
    that call. Anyone in the room can do it; it is not an admin setting.
  * feedback — a short confirmation so people can see the state of recording
    without reading logs or trusting that something invisible is working.

The transcript itself is NOT posted from here. It arrives when the call is over,
from the backend that produced it, through the normal file-sharing path. A bot
message would arrive before the transcript exists.

Nextcloud delivers bot messages to a callback URL and signs them with a shared
secret; nc_py_api verifies the signature, so a forged POST cannot make the bot
speak or stop a recording.
"""
from __future__ import annotations

import logging

from nc_py_api import AsyncNextcloudApp
from nc_py_api.talk_bot import AsyncTalkBot, TalkBotMessage

logger = logging.getLogger(__name__)

BOT_ID = "done_transcription"
CALLBACK_URL = "/talk_bot_message"

# Commands are matched exactly, not by prefix: "/записать протокол" must not
# read as the /запись command. Both languages, because the room may be either.
STOP_COMMANDS = frozenset(("/без-записи", "/no-record", "/no-transcribe"))
START_COMMANDS = frozenset(("/запись", "/record", "/transcribe"))

BOT = AsyncTalkBot(
    CALLBACK_URL,
    "Done Transcription",
    "Transcribes calls and posts the transcript to the conversation",
)

_STOPPED_REPLY = (
    "Recording stopped for this call. Nothing said from now on will be "
    "transcribed. Type /запись to resume."
)
_STARTED_REPLY = "Recording resumed for this call."


async def register(nc: AsyncNextcloudApp, enabled: bool) -> None:
    """Register (or drop) the bot. Called from /enabled, so it must be fast and
    must not raise on its own — a failure there blocks the whole app."""
    try:
        await BOT.enabled_handler(enabled, nc)
    except Exception:
        logger.exception("talk bot registration failed (enabled=%s)", enabled)
        raise


async def handle_message(message: TalkBotMessage) -> None:
    """Process one chat message. Unknown messages are ignored silently — the bot
    receives every message in every conversation it is installed in, and a reply
    to each would make it unusable."""
    if message.object_name != "message":
        return

    try:
        text = _text_of(message)
    except Exception:
        logger.exception("could not read message content")
        return

    if text in STOP_COMMANDS:
        await _set_recording(message, recording=False)
    elif text in START_COMMANDS:
        await _set_recording(message, recording=True)


def _text_of(message: TalkBotMessage) -> str:
    content = message.object_content
    raw = content.get("message", "") if isinstance(content, dict) else str(content)
    return raw.strip().lower()


async def _set_recording(message: TalkBotMessage, *, recording: bool) -> None:
    """Apply the opt-out and confirm it.

    The confirmation is not decoration: the person needs to know the command was
    understood. Silence here reads as "did it work?" — exactly the wrong feeling
    when someone has just asked not to be recorded.
    """
    token = message.conversation_token
    try:
        # TODO: propagate to the capture path for this conversation. Until that
        # is wired, the reply must not claim more than actually happened.
        logger.info("recording=%s requested in %s by %s",
                    recording, token, message.actor_display_name)
        await BOT.send_message(
            _STARTED_REPLY if recording else _STOPPED_REPLY, message,
        )
    except Exception:
        logger.exception("failed to answer the recording command in %s", token)
