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

import l10n
import recording_state

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

# The language to answer in, learned when the app is enabled. Module-level
# because a chat message arrives without a Nextcloud session to ask, and asking
# per message would put a settings lookup in front of every reply.
_language = ""

_STOPPED = ("Recording stopped. Nothing said from now on in this call will be "
            "transcribed.")
_STARTED = "Recording resumed for this call."


def _reply(recording: bool) -> str:
    """The confirmation, with the command needed to undo it.

    The commands stay untranslated on purpose — both spellings work in either
    language, and quoting the one the room is most likely to type is more useful
    than quoting the one that matches the reply's grammar.
    """
    if recording:
        return l10n.translate(_STARTED, _language)
    resume = "/запись" if l10n._normalise(_language) == "ru" else "/record"
    return f"{l10n.translate(_STOPPED, _language)} {resume}"


async def register(nc: AsyncNextcloudApp, enabled: bool) -> None:
    """Register (or drop) the bot. Called from /enabled, so it must be fast and
    must not raise on its own — a failure there blocks the whole app."""
    global _language
    try:
        import settings
        _language = await settings.current_language(nc)
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
        # Applies immediately: the capture checks this before every audio frame.
        # Pushed to us by Nextcloud, so it also works in one-to-one calls, where
        # polling the chat over REST does not see the message at all.
        recording_state.set_recording(
            token, recording, actor=message.actor_display_name or "",
        )
        await BOT.send_message(_reply(recording), message)
    except Exception:
        logger.exception("failed to answer the recording command in %s", token)
