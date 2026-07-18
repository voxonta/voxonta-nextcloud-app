"""Admin settings — where the signalling server is configured.

Two of these values also exist as deploy-time environment variables, and that is
deliberate: those are read once when the container is created and cannot be
changed without reinstalling the app. Anything an administrator may need to
adjust while the app is running belongs here instead.

Section ids are not free-form: AppAPI can only attach a form to a section that
some PHP app already registered, and it ships two — `ai_integration_team` and
`declarative_settings`. A form pointing anywhere else simply never renders.
"""
from __future__ import annotations

import logging

from nc_py_api import AsyncNextcloudApp
from nc_py_api.ex_app import SettingsField, SettingsFieldType, SettingsForm

logger = logging.getLogger(__name__)

FORM_ID = "done_transcription_admin"

# Keys the app reads back at runtime via appconfig_ex.
KEY_ENABLED = "transcription_enabled"
KEY_ROOM_ALLOWLIST = "room_allowlist"
KEY_LANGUAGE = "language"
KEY_PUBLISH_TO_CHAT = "publish_to_chat"


def build_form() -> SettingsForm:
    return SettingsForm(
        id=FORM_ID,
        section_type="admin",
        section_id="ai_integration_team",
        title="Done Transcription",
        description=(
            "Calls are transcribed per speaker and posted back to the room. "
            "The signalling server address and secret are set when the app is "
            "deployed and cannot be changed here."
        ),
        priority=50,
        fields=[
            SettingsField(
                id=KEY_ENABLED,
                title="Transcribe calls",
                description=(
                    "Turn transcription off without uninstalling the app. "
                    "Calls already running are not interrupted."
                ),
                type=SettingsFieldType.CHECKBOX,
                default=True,
            ),
            SettingsField(
                id=KEY_ROOM_ALLOWLIST,
                title="Limit to these conversations",
                description=(
                    "Comma-separated conversation tokens. Leave empty to "
                    "transcribe every call. Useful for a pilot: switch it on for "
                    "one team before rolling it out."
                ),
                type=SettingsFieldType.TEXT,
                default="",
                placeholder="abc123xy, def456uv",
            ),
            SettingsField(
                id=KEY_LANGUAGE,
                title="Spoken language",
                description="Main language of your calls.",
                type=SettingsFieldType.SELECT,
                default="ru",
                options={"Russian": "ru", "English": "en", "Auto-detect": "auto"},
            ),
            SettingsField(
                id=KEY_PUBLISH_TO_CHAT,
                title="Post the transcript to the conversation",
                description=(
                    "When a call ends, share the transcript as a file card in "
                    "the room. Turn off to keep transcripts in the archive only."
                ),
                type=SettingsFieldType.CHECKBOX,
                default=True,
            ),
        ],
    )


async def register(nc: AsyncNextcloudApp) -> None:
    """Register the admin form. Called from /enabled, so it must be quick and
    must not raise: a failure there blocks the whole app from enabling."""
    try:
        await nc.ui.settings.register_form(build_form())
    except Exception:
        logger.exception("settings form registration failed")
        raise


async def unregister(nc: AsyncNextcloudApp) -> None:
    try:
        await nc.ui.settings.unregister_form(FORM_ID)
    except Exception:
        logger.exception("settings form removal failed")
