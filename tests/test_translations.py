"""Translations must stay in step with the code.

Translating by looking the English source string up in a dictionary has one
failure mode, and it is silent: edit the wording at the call site, forget the
dictionary, and the interface quietly reverts to English for everyone. Nothing
raises, no test about behaviour notices, and it is only caught by a Russian
speaker opening the page.

So these tests read the source strings straight out of the code — Python and
Vue alike — and check that each one has a Russian translation. They also flag
entries nobody asks for any more, which are the same mistake seen from the
other side.

Run from the repository root:
    python3 -m pytest tests -v
"""
from __future__ import annotations

import ast
import os
import re
import sys

sys.path.insert(0, os.path.abspath(
    os.path.join(os.path.dirname(__file__), "..", "ex_app", "lib")))

import l10n  # noqa: E402

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
LIB = os.path.join(ROOT, "ex_app", "lib")
SRC = os.path.join(ROOT, "ex_app", "src")

# Both sides share one language, so both are checked against their own
# dictionary — a string may live in either.
RU = l10n.TRANSLATIONS["ru"]


def _python_sources() -> set[str]:
    """Every string passed through the `_()` shorthand or l10n.translate().

    Arguments are often a module-level constant rather than a literal — the
    bot's replies are written that way — so those are resolved too. Missing
    them would make the "stale entry" check accuse live translations of being
    dead.
    """
    found: set[str] = set()
    for name in os.listdir(LIB):
        if not name.endswith(".py") or name == "l10n.py":
            continue
        tree = ast.parse(open(os.path.join(LIB, name), encoding="utf-8").read())
        constants = {
            target.id: node.value.value
            for node in tree.body
            if isinstance(node, ast.Assign)
            and isinstance(node.value, ast.Constant)
            and isinstance(node.value.value, str)
            for target in node.targets
            if isinstance(target, ast.Name)
        }
        for node in ast.walk(tree):
            if not isinstance(node, ast.Call) or not node.args:
                continue
            func = node.func
            callee = (getattr(func, "id", "")
                      or getattr(func, "attr", ""))
            if callee not in ("_", "translate"):
                continue
            arg = node.args[0]
            if isinstance(arg, ast.Constant) and isinstance(arg.value, str):
                found.add(arg.value)
            elif isinstance(arg, ast.Name) and arg.id in constants:
                found.add(constants[arg.id])
    return found


def _js_sources() -> set[str]:
    """Every string passed through t() or translate() in the frontend."""
    pattern = re.compile(r"(?:\bt|translate)\(\s*'((?:[^'\\]|\\.)*)'")
    found: set[str] = set()
    for base, _dirs, files in os.walk(SRC):
        for name in files:
            if not name.endswith((".vue", ".js")) or name == "l10n.js":
                continue
            text = open(os.path.join(base, name), encoding="utf-8").read()
            found.update(m.replace("\\'", "'") for m in pattern.findall(text))
    return found


def _js_keys() -> set[str]:
    """The keys of the ru block in l10n.js, read as text — no JS runtime here.

    A key is either a quoted string or, for single words, a bare identifier.
    Both forms are what the object literal actually allows.
    """
    source = open(os.path.join(SRC, "l10n.js"), encoding="utf-8").read()
    pattern = re.compile(r"^\t\t(?:'((?:[^'\\]|\\.)*)'|(\w+)):", re.M)
    return {(quoted or bare).replace("\\'", "'")
            for quoted, bare in pattern.findall(source)}


def test_every_server_string_is_translated():
    missing = sorted(s for s in _python_sources() if s not in RU)
    assert not missing, (
        "these strings reach Nextcloud untranslated:\n  "
        + "\n  ".join(repr(m) for m in missing))


def test_every_interface_string_is_translated():
    known = _js_keys()
    missing = sorted(s for s in _js_sources() if s not in known)
    assert not missing, (
        "these strings show up in English in the archive UI:\n  "
        + "\n  ".join(repr(m) for m in missing))


def test_no_stale_server_translations():
    """An entry for a string the code no longer contains is dead weight, and
    usually the leftover half of a reworded message."""
    used = _python_sources()
    # Proper nouns kept deliberately identical in both languages are not dead.
    unused = sorted(k for k in RU if k not in used and RU[k] != k)
    js = _js_sources()
    unused = [k for k in unused if k not in js]
    assert not unused, (
        "translated, but nothing uses them any more:\n  "
        + "\n  ".join(repr(u) for u in unused))


def test_placeholders_survive_translation():
    """A translation that drops a {placeholder} loses the number or name it was
    meant to show."""
    for key, value in RU.items():
        assert set(re.findall(r"\{(\w+)\}", key)) \
            == set(re.findall(r"\{(\w+)\}", value)), \
            f"placeholders differ between {key!r} and its translation"


def test_auto_falls_back_to_english():
    """'Auto' is about speech recognition, not about the interface — there is no
    language to render the menu in, so it must stay English rather than become
    an empty string."""
    assert l10n.translate("Meetings", "auto") == "Meetings"
    assert l10n.translate("Meetings", "") == "Meetings"
    assert l10n.translate("Meetings", "ru_RU") == "Встречи"


def test_the_language_default_matches_the_form():
    """Until an administrator presses Save there is nothing in appconfig, so the
    fallback and the form's own default have to be the same value — otherwise a
    fresh install speaks one language while the settings screen shows another."""
    import settings

    field = next(f for f in settings.build_form().fields
                 if f.id == settings.KEY_LANGUAGE)
    assert field.default == settings.DEFAULT_LANGUAGE
