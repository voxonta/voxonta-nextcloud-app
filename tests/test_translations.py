"""Translations must stay in step with the code.

The failure mode is silent: reword a string at the call site, forget the
translation file, and the interface quietly reverts to English. Nothing raises,
no behavioural test notices, and it surfaces only when a Russian speaker opens
the page.

So these read the source strings straight out of the code — Vue and PHP alike —
and check each one against the translation files Nextcloud actually loads.

Run from the repository root:
    python3 -m pytest tests -v
"""
from __future__ import annotations

import json
import os
import re

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SRC = os.path.join(ROOT, "src")
LIB = os.path.join(ROOT, "lib")
APP_ID = "done_transcription"


def _ru() -> dict[str, str]:
    with open(os.path.join(ROOT, "l10n", "ru.json"), encoding="utf-8") as fh:
        return json.load(fh)["translations"]


def _js_sources() -> set[str]:
    """Strings passed to t('done_transcription', '…') in the frontend."""
    pattern = re.compile(r"\bt\(\s*'" + APP_ID + r"',\s*'((?:[^'\\]|\\.)*)'")
    found: set[str] = set()
    for base, _dirs, files in os.walk(SRC):
        for name in files:
            if name.endswith((".vue", ".js")):
                text = open(os.path.join(base, name), encoding="utf-8").read()
                found.update(m.replace("\\'", "'")
                             for m in pattern.findall(text))
    return found


def _php_sources() -> set[str]:
    """Strings passed through the app's own translation helpers."""
    pattern = re.compile(r"(?:\$this->l|\$this->l10n->t)\(\s*'((?:[^'\\]|\\.)*)'")
    found: set[str] = set()
    for base, _dirs, files in os.walk(LIB):
        for name in files:
            if name.endswith(".php"):
                text = open(os.path.join(base, name), encoding="utf-8").read()
                found.update(m.replace("\\'", "'")
                             for m in pattern.findall(text))
    return found


def test_every_interface_string_is_translated():
    ru = _ru()
    missing = sorted(s for s in _js_sources() if s not in ru)
    assert not missing, ("these show up in English in the archive UI:\n  "
                         + "\n  ".join(repr(m) for m in missing))


def test_every_server_string_is_translated():
    ru = _ru()
    missing = sorted(s for s in _php_sources() if s not in ru)
    assert not missing, ("these reach the user in English:\n  "
                         + "\n  ".join(repr(m) for m in missing))


def test_no_stale_translations():
    """An entry for a string nothing uses is usually the leftover half of a
    reworded message."""
    used = _js_sources() | _php_sources()
    ru = _ru()
    # Proper nouns deliberately identical in both languages are not dead.
    unused = sorted(k for k, v in ru.items() if k not in used and v != k)
    assert not unused, ("translated, but nothing uses them:\n  "
                        + "\n  ".join(repr(u) for u in unused))


def test_the_javascript_and_php_translation_files_agree():
    """Nextcloud loads the .json for PHP and the .js for the browser. Updating
    one and not the other leaves half the interface translated."""
    js = open(os.path.join(ROOT, "l10n", "ru.js"), encoding="utf-8").read()
    body = js[js.index("{"):js.rindex("}") + 1]
    assert json.loads(body) == _ru(), "l10n/ru.js and l10n/ru.json disagree"


def test_placeholders_survive_translation():
    """A translation that drops a {placeholder} loses the number it was meant
    to show."""
    for key, value in _ru().items():
        assert set(re.findall(r"\{(\w+)\}", key)) \
            == set(re.findall(r"\{(\w+)\}", value)), \
            f"placeholders differ between {key!r} and its translation"
