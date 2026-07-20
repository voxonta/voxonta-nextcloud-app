"""Everything the browser asks for must be declared in info.xml.

AppAPI's proxy refuses any path the app did not declare, and it refuses it
inside Nextcloud — the request never reaches the container, so the app's own
logs stay silent and it looks like the frontend is simply broken. There is no
implicit default and no warning at registration; a missing entry is only
visible as a 404 in the browser console.

That cost an evening once. These tests check the declaration against the paths
the app actually serves.

Run from the repository root:
    python3 -m pytest tests -v
"""
from __future__ import annotations

import os
import re
import xml.etree.ElementTree as ET

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
INFO_XML = os.path.join(ROOT, "appinfo", "info.xml")
SRC = os.path.join(ROOT, "ex_app", "src")

# PUBLIC=0, USER=1, ADMIN=2 in AppAPI's own mapping.
ACCESS = {"PUBLIC", "USER", "ADMIN"}


def _routes() -> list[dict[str, str]]:
    root = ET.parse(INFO_XML).getroot()
    return [{child.tag: (child.text or "").strip() for child in route}
            for route in root.findall("./external-app/routes/route")]


def _matches(path: str) -> bool:
    """Mirror the proxy's own check: the regex is applied to the leading-slash
    form of the request path."""
    return any(re.match(route["url"], path, re.I) for route in _routes())


def test_the_frontend_bundle_is_reachable():
    """Without this the page loads and stays blank — the script 404s."""
    assert _matches("/js/done_transcription-main.js")


def test_the_menu_icon_is_reachable():
    assert _matches("/img/app.svg")


def test_the_archive_endpoints_are_reachable():
    for path in ("/v1/meetings",
                 "/v1/meetings/abc123",
                 "/v1/meetings/abc123/transcript",
                 "/v1/meetings/abc123/analysis",
                 "/v1/meetings/abc123/analysis/summary"):
        assert _matches(path), f"{path} would 404 inside Nextcloud"


def test_the_archive_is_not_public():
    """These endpoints answer with the caller's own meetings, so they need the
    authenticated user. A PUBLIC route would arrive without one."""
    for route in _routes():
        if route["url"].startswith("^/v1/"):
            assert route["access_level"] == "USER"


def test_access_levels_are_spelled_the_way_appapi_expects():
    """An unknown level is mapped to -1 and the route is silently dropped."""
    for route in _routes():
        assert route["access_level"] in ACCESS, route


def test_nothing_outside_the_declared_paths_is_exposed():
    """The declaration should stay a short list of what the app serves, not a
    catch-all — a `^/.*` here would put every internal endpoint, /init and
    /enabled included, behind a URL anyone can request."""
    for route in _routes():
        assert route["url"] not in ("^/.*", "^/", ".*"), \
            "a catch-all route exposes the app's lifecycle endpoints"


def test_the_bundle_name_matches_what_the_app_registers():
    """webpack's output name and the name passed to set_script have drifted
    apart before; the symptom is the same silent 404."""
    main = open(os.path.join(ROOT, "ex_app", "lib", "main.py"),
                encoding="utf-8").read()
    registered = re.search(r'set_script\(\s*"[^"]*",\s*"[^"]*",\s*"js/([^"]+)"',
                           main)
    assert registered, "could not find the set_script call"
    package = open(os.path.join(ROOT, "package.json"), encoding="utf-8").read()
    name = re.search(r'"name":\s*"([^"]+)"', package).group(1)
    assert registered.group(1) == f"{name}-main", (
        f"the app asks for js/{registered.group(1)}.js but webpack emits "
        f"{name}-main.js")
