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

import l10n

logger = logging.getLogger(__name__)

FORM_ID = "done_transcription_admin"

# Keys the app reads back at runtime via appconfig_ex.
KEY_ENABLED = "transcription_enabled"
KEY_ROOM_ALLOWLIST = "room_allowlist"
KEY_LANGUAGE = "language"
KEY_PUBLISH_TO_CHAT = "publish_to_chat"
KEY_ALLOWED_GROUPS = "allowed_groups"

# The language a fresh install speaks. It is the form's default too, and the two
# must not drift: appconfig holds nothing until an administrator presses Save,
# so a mismatch means the app talks in one language while the settings screen
# claims another.
DEFAULT_LANGUAGE = "ru"


def build_form(lang: str = "") -> SettingsForm:
    def _(text: str) -> str:
        return l10n.translate(text, lang)

    return SettingsForm(
        id=FORM_ID,
        section_type="admin",
        section_id="ai_integration_team",
        title="Done Transcription",
        description=_(
            "Calls are transcribed per speaker and posted back to the room. "
            "The signalling server address and secret are set when the app is "
            "deployed and cannot be changed here."
        ),
        priority=50,
        fields=[
            SettingsField(
                id=KEY_ENABLED,
                title=_("Transcribe calls"),
                description=_(
                    "Turn transcription off without uninstalling the app. "
                    "Calls already running are not interrupted."
                ),
                type=SettingsFieldType.CHECKBOX,
                default=True,
            ),
            SettingsField(
                id=KEY_ROOM_ALLOWLIST,
                title=_("Limit to these conversations"),
                description=_(
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
                title=_("Language"),
                description=_(
                    "The language spoken in your calls. Also selects the "
                    "language of this app's own interface."
                ),
                type=SettingsFieldType.SELECT,
                default=DEFAULT_LANGUAGE,
                options={_("Russian"): "ru", _("English"): "en",
                         "Auto": "auto"},
            ),
            SettingsField(
                id=KEY_ALLOWED_GROUPS,
                title=_("Who can open the archive"),
                description=_(
                    "Comma-separated group names. Leave empty to let everyone "
                    "read the meetings they took part in. Note that this "
                    "restricts access to the data, not the menu entry — "
                    "Nextcloud does not let an external app hide that per "
                    "group."
                ),
                type=SettingsFieldType.TEXT,
                default="",
                placeholder="managers, hr",
            ),
            SettingsField(
                id=KEY_PUBLISH_TO_CHAT,
                title=_("Post the transcript to the conversation"),
                description=_(
                    "When a call ends, share the transcript as a file card in "
                    "the room. Turn off to keep transcripts in the archive only."
                ),
                type=SettingsFieldType.CHECKBOX,
                default=True,
            ),
        ],
    )


async def current_language(nc: AsyncNextcloudApp) -> str:
    """The language the app should speak in.

    Falls back to the form's own default, not to an empty string: until an
    administrator saves the form there is nothing in appconfig, and treating
    that as "no language" would show a Russian instance an English menu.
    """
    try:
        return str(await nc.appconfig_ex.get_value(
            KEY_LANGUAGE, default=DEFAULT_LANGUAGE) or DEFAULT_LANGUAGE)
    except Exception:
        # Not worth failing a registration over; English is a usable answer.
        logger.warning("could not read the language setting", exc_info=True)
        return DEFAULT_LANGUAGE


async def register(nc: AsyncNextcloudApp) -> None:
    """Register the admin form. Called from /enabled, so it must be quick and
    must not raise: a failure there blocks the whole app from enabling."""
    try:
        await nc.ui.settings.register_form(build_form(await current_language(nc)))
    except Exception:
        logger.exception("settings form registration failed")
        raise


async def unregister(nc: AsyncNextcloudApp) -> None:
    try:
        await nc.ui.settings.unregister_form(FORM_ID)
    except Exception:
        logger.exception("settings form removal failed")
