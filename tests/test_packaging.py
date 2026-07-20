"""The parts that only break in a real installation.

Each check here guards a mistake whose symptom is an empty page or a missing
menu entry — nothing throws, nothing appears in a log, and the cause is a name
in one file not matching a name in another.

Run from the repository root:
    python3 -m pytest tests -v
"""
from __future__ import annotations

import os
import re
import xml.etree.ElementTree as ET

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
APP_ID = "done_transcription"


def _info() -> ET.Element:
    return ET.parse(os.path.join(ROOT, "appinfo", "info.xml")).getroot()


def test_the_bundle_name_matches_what_the_page_asks_for():
    """webpack emits {package name}-{entry}.js; PageController asks for a name.
    When they drift the script tag points at a file that does not exist."""
    page = open(os.path.join(ROOT, "lib", "Controller", "PageController.php"),
                encoding="utf-8").read()
    asked = re.search(r"Util::addScript\([^;]*?APP_ID \. '(-[\w-]+)'", page)
    assert asked, "could not find the addScript call"

    package = open(os.path.join(ROOT, "package.json"), encoding="utf-8").read()
    name = re.search(r'"name":\s*"([^"]+)"', package).group(1)
    assert name == APP_ID, (
        f"the package is named {name!r}, so webpack emits {name}-main.js while "
        f"the page asks for {APP_ID}{asked.group(1)}.js")


def test_the_navigation_entry_points_at_a_real_route():
    """A navigation route that does not resolve removes the entry from the
    menu, silently."""
    nav = _info().find("./navigations/navigation")
    assert nav is not None, "the app would have no menu entry at all"
    route = nav.findtext("route")
    assert route == f"{APP_ID}.page.index", route

    routes = open(os.path.join(ROOT, "appinfo", "routes.php"),
                  encoding="utf-8").read()
    assert "'name' => 'page#index'" in routes, \
        f"{route} has no matching entry in routes.php"


def test_the_navigation_icon_exists():
    icon = _info().findtext("./navigations/navigation/icon")
    assert os.path.exists(os.path.join(ROOT, "img", icon)), \
        f"img/{icon} is missing, so the menu entry renders without an icon"


def test_the_release_archive_carries_what_the_app_needs_to_run():
    """Forgetting a directory here produces an app that installs and then fails
    in a way that looks nothing like a packaging problem."""
    script = open(os.path.join(ROOT, "build-release.sh"), encoding="utf-8").read()
    packed = re.search(r"for item in ([^;]+); do", script).group(1).split()
    for needed in ("appinfo", "lib", "templates", "img", "js", "l10n"):
        assert needed in packed, f"the archive would ship without {needed}/"


def test_the_release_archive_leaves_development_files_out():
    """node_modules and vendor are two orders of magnitude larger than the app
    and contain code nobody reviewed for this release."""
    script = open(os.path.join(ROOT, "build-release.sh"), encoding="utf-8").read()
    packed = re.search(r"for item in ([^;]+); do", script).group(1).split()
    for excluded in ("node_modules", "vendor", "src", "tests"):
        assert excluded not in packed, f"{excluded}/ would end up in the archive"
