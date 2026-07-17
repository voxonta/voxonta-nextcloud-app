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

from fastapi import FastAPI
from nc_py_api import AsyncNextcloudApp
from nc_py_api.ex_app import AppAPIAuthMiddleware, LogLvl, run_app, set_handlers

logger = logging.getLogger(__name__)


@asynccontextmanager
async def lifespan(_app: FastAPI):
    # set_handlers must run in lifespan, not at module level: with multiple
    # uvicorn workers a module-level call executes once per worker.
    set_handlers(_app, enabled_handler)
    yield


APP = FastAPI(lifespan=lifespan)
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
            await nc.log(LogLvl.WARNING, "done-transcription-app enabled")
            # TODO(step 4): declarative settings (HPB_URL, HPB_SECRET as
            #   password/sensitive) — note AppAPI only allows the prebuilt
            #   sections `ai_integration_team` / `declarative_settings`.
            # TODO(step 5): nc.ui.top_menu.register(...) — the Vue UI only loads
            #   behind a top-menu entry.
            # TODO(step 6): nc.talk.register_bot(...) — trigger + publishing.
        else:
            await nc.log(LogLvl.WARNING, "done-transcription-app disabled")
        return ""
    except Exception as e:  # never crash the lifecycle call
        logger.exception("enabled_handler failed")
        return f"enabled_handler failed: {e}"


if __name__ == "__main__":
    run_app("main:APP", log_level="info")
