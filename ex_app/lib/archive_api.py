"""The archive endpoints the UI talks to.

Two reasons this app proxies the backend instead of letting the browser call it
directly:

  * **The token stays here.** It grants access to every meeting in the
    instance; handing it to a browser would mean anyone with devtools open
    walks away with it.
  * **Someone has to scope the results to the person asking.** The backend
    isolates by tenant, not by user — it will happily return the whole
    company's meetings. Nextcloud knows who is asking, the backend does not, so
    that filter has to be applied on this side.

The scoping is the important part, and it is deliberately not optional: every
handler here derives the user from the authenticated request rather than from
anything the client sends. A `?user=` parameter from the browser would be a
request to read someone else's calls.
"""
from __future__ import annotations

import logging

import aiohttp
from fastapi import APIRouter, Depends, HTTPException, Request
from nc_py_api import AsyncNextcloudApp
from nc_py_api.ex_app import anc_app

logger = logging.getLogger(__name__)

ROUTER = APIRouter(prefix="/v1")

# Set once at startup from CaptureConfig — the same backend the finished calls
# are delivered to.
_backend_url = ""
_backend_token = ""


def configure(url: str, token: str) -> None:
    global _backend_url, _backend_token
    _backend_url = url.rstrip("/")
    _backend_token = token


async def _get(path: str, *, params: dict | None = None) -> dict:
    if not _backend_url:
        raise HTTPException(503, "archive backend is not configured")
    headers = {"Accept": "application/json"}
    if _backend_token:
        headers["Authorization"] = f"Bearer {_backend_token}"
    try:
        async with aiohttp.ClientSession() as session:
            async with session.get(f"{_backend_url}{path}", params=params,
                                   headers=headers, timeout=30) as resp:
                if resp.status == 404:
                    raise HTTPException(404, "not found")
                if resp.status >= 400:
                    logger.error("archive backend returned %s for %s",
                                 resp.status, path)
                    raise HTTPException(502, "archive backend error")
                return await resp.json()
    except aiohttp.ClientError:
        logger.exception("archive backend unreachable")
        raise HTTPException(503, "archive backend unreachable")


def _current_user(nc: AsyncNextcloudApp) -> str:
    """Who is asking. Empty means we could not tell — and then we show nothing
    rather than everything."""
    return getattr(nc, "user", "") or ""


@ROUTER.get("/meetings")
async def list_meetings(request: Request,
                        nc: AsyncNextcloudApp = Depends(anc_app)):
    """Meetings this person attended.

    The user filter comes from the session, never from the query string: a
    client asking for someone else's meetings is asking for something it should
    not have.
    """
    user = _current_user(nc)
    if not user:
        raise HTTPException(401, "unknown user")

    try:
        limit = min(int(request.query_params.get("limit", 50)), 200)
        offset = max(int(request.query_params.get("offset", 0)), 0)
    except ValueError:
        raise HTTPException(400, "bad paging")

    data = await _get("/v1/meetings", params={
        "user": user, "limit": limit, "offset": offset,
    })
    return {"meetings": data.get("meetings", [])}


async def _meeting_or_403(session_id: str, nc: AsyncNextcloudApp) -> dict:
    """Fetch a meeting and confirm the caller took part in it.

    Knowing a session id is not authorisation: ids travel in chat messages and
    links. Without this check, one forwarded URL would open a call to someone
    who was never in it.
    """
    user = _current_user(nc)
    if not user:
        raise HTTPException(401, "unknown user")

    meeting = await _get(f"/v1/meetings/{session_id}")
    participants = meeting.get("participants") or []
    if user not in participants:
        logger.warning("%s asked for meeting %s they did not attend",
                       user, session_id)
        # 404, not 403: a "forbidden" would confirm the meeting exists.
        raise HTTPException(404, "not found")
    return meeting


@ROUTER.get("/meetings/{session_id}")
async def get_meeting(session_id: str,
                      nc: AsyncNextcloudApp = Depends(anc_app)):
    return await _meeting_or_403(session_id, nc)


@ROUTER.get("/meetings/{session_id}/transcript")
async def get_transcript(session_id: str,
                         nc: AsyncNextcloudApp = Depends(anc_app)):
    await _meeting_or_403(session_id, nc)
    return await _get(f"/v1/meetings/{session_id}/transcript")


@ROUTER.get("/meetings/{session_id}/analysis")
async def list_analysis(session_id: str,
                        nc: AsyncNextcloudApp = Depends(anc_app)):
    await _meeting_or_403(session_id, nc)
    return await _get(f"/v1/meetings/{session_id}/analysis")


@ROUTER.get("/meetings/{session_id}/analysis/{name}")
async def get_artifact(session_id: str, name: str,
                       nc: AsyncNextcloudApp = Depends(anc_app)):
    await _meeting_or_403(session_id, nc)
    return await _get(f"/v1/meetings/{session_id}/analysis/{name}")
