"""Done Transcription — Nextcloud ExApp entrypoint.

The Nextcloud side of the product: app lifecycle, admin settings, UI and the Talk
bot. Call audio is captured through the signalling server (one stream per
speaker) and transcribed by the backend service; this app registers the
integration and surfaces the results.

AppAPI lifecycle — the parts that are easy to get wrong:
  * GET  /heartbeat  — health probe, MUST stay UNAUTHENTICATED (a global auth
                       middleware in front of it breaks startup). 10-min window.
  * POST /init       — where slow work belongs (40-min default timeout).
  * PUT  /enabled    — 30 SECONDS only: registrations, nothing slow.

Run locally:
    python3 -m uvicorn main:APP --port 9031
"""
from __future__ import annotations

import logging
from contextlib import asynccontextmanager

from fastapi import BackgroundTasks, Depends, FastAPI
from nc_py_api import AsyncNextcloudApp, talk_bot
from nc_py_api.ex_app import AppAPIAuthMiddleware, LogLvl, atalk_bot_msg, run_app, set_handlers

import archive_api
import settings
import talk_bot as bot

logger = logging.getLogger(__name__)


@asynccontextmanager
async def lifespan(_app: FastAPI):
    # set_handlers must run in lifespan, not at module level: with multiple
    # uvicorn workers a module-level call executes once per worker.
    set_handlers(_app, enabled_handler)
    yield


APP = FastAPI(lifespan=lifespan)
# Read-only archive endpoints for the UI. Registered here so the browser reaches
# them through the AppAPI proxy; the backend token never leaves this process.
APP.include_router(archive_api.ROUTER)
# Global AppAPI auth. set_handlers() exempts /heartbeat itself; do not add our
# own auth in front of it.
APP.add_middleware(AppAPIAuthMiddleware)


async def enabled_handler(enabled: bool, nc: AsyncNextcloudApp) -> str:
    """Called by AppAPI on enable/disable. Budget: 30 seconds — registrations
    only, never model loading or network waits.

    Returns "" on success; any non-empty string is reported as an error.
    """
    try:
        if enabled:
            await settings.register(nc)
            await bot.register(nc, True)
            await nc.log(LogLvl.WARNING, "Done Transcription enabled")
            # TODO: top-menu entry + Vue UI for the transcript archive.
        else:
            await bot.register(nc, False)
            await settings.unregister(nc)
            await nc.log(LogLvl.WARNING, "Done Transcription disabled")
        return ""
    except Exception as e:  # never crash the lifecycle call
        logger.exception("enabled_handler failed")
        return f"enabled_handler failed: {e}"


@APP.post(bot.CALLBACK_URL)
async def talk_bot_message(
    background: BackgroundTasks,
    message: talk_bot.TalkBotMessage = Depends(atalk_bot_msg),
):
    """Chat messages from Nextcloud. The signature is verified by the
    atalk_bot_msg dependency, so an unsigned POST never reaches this body.

    Handling runs in the background and the endpoint answers immediately:
    Nextcloud is waiting on this request, and an opt-out that takes a round trip
    to acknowledge feels broken to the person who asked for it.
    """
    background.add_task(bot.handle_message, message)
    return {}


if __name__ == "__main__":
    run_app("main:APP", log_level="info")
