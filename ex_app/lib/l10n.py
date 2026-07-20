"""Translations for the strings this app sends to Nextcloud.

The browser side can look at the language the interface is rendered in; this
side cannot. Everything here — the top-menu entry, the settings form, the bot's
replies — is registered once per instance rather than once per viewer, so there
is no "current user" to ask, and Nextcloud does not expose `default_language`
over its API by design.

So the language comes from the app's own setting. That is honest for the bot
(it answers in the room, where people speak that language) and it is the best
available answer for the menu entry and the form: an admin who set the calls to
Russian is served better by a Russian interface than by an English one.

English is the source language, and an untranslated string falls through to it.
"""
from __future__ import annotations

TRANSLATIONS: dict[str, dict[str, str]] = {
    "ru": {
        "Meetings": "Встречи",

        # Bot
        # The bot's own name and description are registered when the module
        # is imported, before any setting can be read, so they stay English.
        "Recording stopped. Nothing said from now on in this call will be "
        "transcribed.":
            "Запись остановлена. Всё сказанное дальше в этом звонке "
            "расшифровываться не будет.",
        "Recording resumed for this call.":
            "Запись этого звонка возобновлена.",

        # Settings
        "Calls are transcribed per speaker and posted back to the room. The "
        "signalling server address and secret are set when the app is deployed "
        "and cannot be changed here.":
            "Звонки расшифровываются по говорящим, результат публикуется в "
            "беседе. Адрес сигнального сервера и секрет задаются при "
            "развёртывании приложения и здесь не меняются.",
        "Transcribe calls": "Расшифровывать звонки",
        "Turn transcription off without uninstalling the app. Calls already "
        "running are not interrupted.":
            "Выключить расшифровку, не удаляя приложение. Уже идущие звонки "
            "не прерываются.",
        "Limit to these conversations": "Только эти беседы",
        "Comma-separated conversation tokens. Leave empty to transcribe every "
        "call. Useful for a pilot: switch it on for one team before rolling it "
        "out.":
            "Токены бесед через запятую. Пусто — расшифровывать все звонки. "
            "Удобно для пилота: включить одной команде до общего запуска.",
        "Language": "Язык",
        "The language spoken in your calls. Also selects the language of this "
        "app's own interface.":
            "Язык, на котором говорят в ваших звонках. Им же выбирается язык "
            "интерфейса самого приложения.",
        "Who can open the archive": "Кому доступен архив",
        "Comma-separated group names. Leave empty to let everyone read the "
        "meetings they took part in. Note that this restricts access to the "
        "data, not the menu entry — Nextcloud does not let an external app "
        "hide that per group.":
            "Названия групп через запятую. Пусто — каждый читает встречи, в "
            "которых участвовал. Ограничивается доступ к данным, но не пункт "
            "меню: скрыть его для отдельных групп внешнее приложение в "
            "Nextcloud не может.",
        "Post the transcript to the conversation":
            "Публиковать расшифровку в беседе",
        "When a call ends, share the transcript as a file card in the room. "
        "Turn off to keep transcripts in the archive only.":
            "По окончании звонка выложить расшифровку файлом в беседе. "
            "Выключите, чтобы расшифровки оставались только в архиве.",
        "Russian": "Русский",
        "English": "Английский",
    },
}


def translate(text: str, lang: str) -> str:
    """Translate a source string, falling back to the English original."""
    return TRANSLATIONS.get(_normalise(lang), {}).get(text, text)


def _normalise(lang: str) -> str:
    """Reduce a setting value to a language code.

    "auto" means the speech recogniser picks the language per call — a runtime
    decision that cannot be made here, at registration time. English is the
    neutral answer for the interface in that case.
    """
    code = str(lang or "").split("-")[0].split("_")[0].lower()
    return "" if code in ("", "auto") else code
