"""Runs the capture inside the app: watch for calls, stream audio, hand off.

The capture itself comes from the talk_capture package — signalling, per-speaker
audio, resolving a finished call. What lives here is the part specific to being a
Nextcloud app: reading the administrator's settings, honouring the opt-out that
arrived through the bot, and posting the finished call to the backend.

Ordering inside `_should_capture` is not incidental. Cheap local checks come
first and network calls last, because this runs for every call in the instance,
including the ones we are not supposed to touch.
"""
from __future__ import annotations

import asyncio
import logging
import os
import time
import uuid

import aiohttp
from talk_capture.config import CaptureConfig
from talk_capture.events import to_transcript_ready_event
from talk_capture.outbox import MAX_ATTEMPTS, Outbox, is_permanent
from talk_capture.monitor import CallInfo, CallMonitor
from talk_capture.resolve import resolve_finished_call
from talk_capture.transcriber_backend import make_transcriber

import recording_state
import settings

logger = logging.getLogger(__name__)


class CallCapture:
    """One call: connect, stream audio, resolve when it ends."""

    def __init__(self, config: CaptureConfig, call: CallInfo):
        self.config = config
        self.call = call
        self.session_id = str(uuid.uuid4())
        self._transcriber = make_transcriber(config, model=None)
        self._spreed = None
        self._started = time.time()
        self._task: asyncio.Task | None = None

    async def start(self) -> None:
        # Imported here rather than at module load: pulling aiortc costs a
        # second and is pointless in an instance where no call ever happens.
        from talk_capture.spreed_client import SpreedClient

        self._spreed = SpreedClient(
            config=self.config,
            room_token=self.call.room_token,
            on_audio_frame=self._on_audio_frame,
        )
        self._task = asyncio.create_task(self._run())

    async def _run(self) -> None:
        for attempt in range(5):
            try:
                await self._spreed.connect_and_join()
                return
            except asyncio.CancelledError:
                return
            except Exception:
                logger.exception("capture error in %s (attempt %d/5)",
                                 self.call.room_token, attempt + 1)
                if attempt < 4:
                    await asyncio.sleep(3 * (attempt + 1))
                    try:
                        await self._spreed.disconnect()
                    except Exception:
                        pass

    async def _on_audio_frame(self, speaker_sid: str, frame) -> None:
        # Checked per frame, not per call: someone may opt out mid-conversation
        # and everything said after that must not be transcribed.
        if not recording_state.is_recording(self.call.room_token):
            return
        await self._transcriber.process_audio_frame(
            speaker_sid, frame, self._started)

    async def stop(self, emit) -> dict | None:
        ended = time.time()
        if self._task:
            self._task.cancel()
            try:
                await self._task
            except asyncio.CancelledError:
                pass

        await self._transcriber.finalize_all(self._started)

        # Someone opted out: the audio was never transcribed, so there is
        # nothing to hand over. Say so in the log and stop here — emitting an
        # empty call would put a stray entry in the archive.
        if recording_state.opted_out(self.call.room_token):
            logger.info("call %s ended opted-out — nothing emitted",
                        self.call.room_token)
            return None

        finished = await resolve_finished_call(
            self._spreed, self._transcriber,
            room_token=self.call.room_token,
            room_name=self.call.room_name,
            room_type=self.call.room_type,
            call_start=self._started,
            call_end=ended,
            language=self.config.transcript_language,
            local_tz=self.config.daily_report_timezone,
        )
        if not finished.transcript:
            logger.info("call %s produced no speech", self.call.room_token)
            return None

        event = await emit(finished, self.session_id)
        try:
            self._transcriber.clear()
        except Exception:
            pass
        return event


class CaptureRunner:
    """Watches the instance for calls and runs a CallCapture for each."""

    def __init__(self, config: CaptureConfig, nc_app=None):
        self.config = config
        self._nc = nc_app
        self._calls: dict[str, CallCapture] = {}
        self._monitor: CallMonitor | None = None
        self._http: aiohttp.ClientSession | None = None
        self._task: asyncio.Task | None = None
        self._outbox: Outbox | None = None
        self._replay: asyncio.Task | None = None

    async def start(self) -> None:
        if not self.config.hpb_url:
            logger.warning(
                "no signalling server configured — capture stays off. "
                "Set the HPB URL in the app's deploy options.")
            return
        self._http = aiohttp.ClientSession()

        # Undelivered calls survive here. AppAPI gives the app a persistent
        # volume; anything else would lose the buffer on the next container
        # restart, which is exactly when it is needed.
        storage = os.environ.get("APP_PERSISTENT_STORAGE", "/tmp")
        os.makedirs(storage, exist_ok=True)
        self._outbox = Outbox(os.path.join(storage, "outbox.db"))
        pending = self._outbox.count()
        if pending:
            logger.warning("%d call(s) from a previous run still undelivered",
                           pending)
        self._replay = asyncio.create_task(self._replay_loop())

        self._monitor = CallMonitor(
            config=self.config,
            on_call_started=self._on_call_started,
            on_call_ended=self._on_call_ended,
        )
        self._task = asyncio.create_task(self._monitor.start())
        logger.info("capture started (polling every %ss)",
                    self.config.poll_interval)

    async def stop(self) -> None:
        if self._replay:
            self._replay.cancel()
        if self._task:
            self._task.cancel()
        for token in list(self._calls):
            await self._on_call_ended(token)
        if self._http:
            await self._http.close()
        if self._outbox:
            self._outbox.close()

    async def _should_capture(self, call: CallInfo) -> bool:
        """Cheapest checks first — this runs for every call in the instance."""
        if call.room_token in self._calls:
            return False
        if recording_state.opted_out(call.room_token):
            logger.info("skipping %s — opted out", call.room_token)
            return False

        prefs = await self._read_settings()
        if not prefs.get("enabled", True):
            return False
        allow = prefs.get("allowlist") or ()
        if allow and call.room_token not in allow:
            logger.debug("skipping %s — not in the configured conversations",
                         call.room_token)
            return False
        return True

    async def _read_settings(self) -> dict:
        """Administrator settings, best-effort.

        If Nextcloud cannot be reached we fall back to capturing: the app was
        enabled for a reason, and going silent on a transient error would lose
        calls without anyone noticing.
        """
        if self._nc is None:
            return {}
        try:
            enabled = await self._nc.appconfig_ex.get_value(
                settings.KEY_ENABLED, default="1")
            raw = await self._nc.appconfig_ex.get_value(
                settings.KEY_ROOM_ALLOWLIST, default="")
            return {
                "enabled": str(enabled).lower() not in ("0", "false", "no"),
                "allowlist": tuple(t.strip() for t in str(raw).split(",") if t.strip()),
            }
        except Exception:
            logger.exception("could not read settings — capturing anyway")
            return {}

    async def _on_call_started(self, call: CallInfo) -> None:
        if not await self._should_capture(call):
            return
        logger.info("capturing %s (%s)", call.room_name, call.room_token)
        capture = CallCapture(self.config, call)
        self._calls[call.room_token] = capture
        await capture.start()

    async def _on_call_ended(self, room_token: str) -> None:
        capture = self._calls.pop(room_token, None)
        if capture is None:
            return
        try:
            await capture.stop(self._emit)
        except Exception:
            logger.exception("failed to finish %s", room_token)
        finally:
            # The opt-out applied to this call; the next one starts fresh.
            recording_state.forget(room_token)

    async def _emit(self, finished, session_id: str) -> dict | None:
        """Hand the finished call to the backend, durably.

        The event is written to the outbox BEFORE the first delivery attempt and
        removed only once the backend confirms. By this point the call is over
        and the audio is gone — this event is the only remaining copy of what was
        said, so a backend that happens to be restarting must not cost it.
        """
        event = to_transcript_ready_event(
            finished, session_id=session_id, correlation_id=session_id)

        if self._outbox is not None:
            self._outbox.add(session_id, event)

        if not await self._attempt(session_id, event, 0):
            logger.warning("%s buffered for retry", session_id)
        return event

    async def _deliver(self, session_id: str, event: dict) -> tuple[bool, int | None]:
        """One delivery attempt.

        Returns (delivered, status). The status matters: it decides whether
        retrying this event can ever help.
        """
        url = self.config.nc_capture_s2_url
        if not url:
            logger.error("no backend URL configured — %s stays buffered",
                         session_id)
            return False, None
        headers = {}
        if self.config.s2_intake_token:
            headers["Authorization"] = f"Bearer {self.config.s2_intake_token}"
        try:
            async with self._http.post(url, json=event, headers=headers,
                                       timeout=30) as resp:
                if resp.status in (200, 202):
                    logger.info("delivered %s", session_id)
                    return True, resp.status
                logger.error("backend rejected %s: HTTP %s",
                             session_id, resp.status)
                return False, resp.status
        except Exception:
            logger.exception("could not deliver %s", session_id)
            return False, None

    async def _attempt(self, session_id: str, event: dict, attempts: int) -> bool:
        """Deliver once and record what happened. True if it landed."""
        delivered, status = await self._deliver(session_id, event)
        if self._outbox is None:
            return delivered
        if delivered:
            self._outbox.remove(session_id)
            return True

        if is_permanent(status):
            # The backend understood the event and refused it. Retrying cannot
            # change that, and doing so forever would bury real outages in noise.
            self._outbox.mark_dead(session_id, f"rejected with HTTP {status}")
            logger.error(
                "%s will not be retried: backend rejected it with HTTP %s. "
                "The transcript is kept and needs a look.", session_id, status)
            return False

        tries = self._outbox.mark_failed(session_id)
        if tries >= MAX_ATTEMPTS:
            self._outbox.mark_dead(session_id, f"gave up after {tries} attempts")
            logger.error(
                "%s not delivered after %d attempts — kept, but no longer "
                "retried. The backend has been unreachable for a long time.",
                session_id, tries)
        return False

    async def _replay_loop(self) -> None:
        """Retry buffered calls when their backoff has elapsed.

        The loop ticks often; the outbox decides what is actually due, so a
        backend that is down is contacted on a widening interval rather than
        every tick.
        """
        while True:
            try:
                await asyncio.sleep(30)
                if self._outbox is None:
                    continue
                due = self._outbox.due()
                if not due:
                    continue
                logger.info("retrying %d buffered call(s)", len(due))
                for session_id, event, attempts in due:
                    await self._attempt(session_id, event, attempts)
            except asyncio.CancelledError:
                return
            except Exception:
                logger.exception("replay loop error")
