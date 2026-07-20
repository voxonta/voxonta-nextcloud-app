# Done Transcription

Automatic transcription and post-call analysis for **Nextcloud Talk**.

Calls are transcribed per speaker, published back to the room when the call
ends, and turned into a readable summary — who took part, what was decided, what
to do next.

## What it does

- **Per-speaker transcript.** Every participant is captured on their own audio
  track, so the transcript shows who said what instead of one merged block of
  text.
- **Published to the room.** When a call ends, the transcript is posted to the
  conversation as a file card and saved to Nextcloud.
- **Post-call analysis.** A summary with decisions and action items, produced
  from the transcript.
- **Opt-out at any time.** Participants can stop recording from the chat.

## Requirements

- **Nextcloud 32 or newer**
- **Talk with a High Performance Backend (HPB).** Audio is captured through the
  signalling server, so a Talk installation without an HPB is not supported.
- The HPB address and its internal secret, entered once when the app is
  deployed.

## Install

1. Install the app and register it with your Deploy Daemon.
2. Fill in **Deploy Options**:

   | Variable | Value |
   |---|---|
   | `DT_HPB_URL` | your signalling server, e.g. `wss://signal.example.com/spreed` |
   | `DT_HPB_INTERNAL_SECRET` | the `internalsecret` from your HPB configuration |

3. Enable the app.

> These values are read at deploy time. Changing them later requires
> reinstalling the app.

## Using it

There is nothing to switch on per call. Once the app is enabled, calls in your
Talk rooms are transcribed automatically, and the transcript is posted to the
room after the call.

**To stop recording**, post in the room chat:

| Command | Effect |
|---|---|
| `/без-записи`, `/no-record` | stop transcribing this call |
| `/запись`, `/record` | resume |

Either spelling works whatever language the room speaks. The command works
before the call starts and while it is running. Recording stops for everyone in
that call.

**Language.** The app speaks English and Russian. The interface follows the
language each person set in Nextcloud; the bot's replies, the menu entry and the
admin settings follow the **Language** setting, since those are registered once
for the whole instance rather than per viewer.

**One-to-one calls** start being transcribed only after the second person
answers, so a ringing call is never recorded.

## Privacy

- Audio is used to produce the transcript and is not kept afterwards.
- Transcripts are stored in Nextcloud and shared with the call participants.
- The opt-out command applies to the entire call, for every participant.
- Whether transcription and analysis run on your own infrastructure or on a
  hosted backend depends on how your administrator configured it.

## How it works

The app registers with Nextcloud through the AppAPI and joins each call as an
internal client of the signalling server, receiving one audio stream per
speaker. The streams are sent to the transcription engine; when the call ends,
the finished call is handed to the backend, which produces the summary and
publishes the result.

Speech recognition and analysis run in a separate backend service. This app is
the Nextcloud-side adapter: it captures the audio and surfaces the results.

## Development

```bash
pip install -r requirements.txt
python3 -m uvicorn ex_app.lib.main:APP --port 9031
```

Register the app against a running Nextcloud:

```bash
occ app_api:daemon:register manual_dev "Manual (dev)" \
    manual-install http localhost https://cloud.example.com

occ app_api:app:register done_transcription manual_dev --json-info \
  '{"id":"done_transcription","name":"Done Transcription",
    "daemon_config_name":"manual_dev","version":"0.1.0",
    "secret":"<secret>","port":9031}'
```

Notes for contributors:

- `/heartbeat` must stay unauthenticated — do not put the AppAPI auth middleware
  in front of it.
- `/enabled` has a 30-second budget: registrations only. Anything slow belongs in
  `/init` (40 minutes).
- The frontend is only loaded behind a top-menu entry, and asset paths go through
  the AppAPI proxy (`/apps/app_api/proxy`, `/apps/app_api/embedded`).
- Declarative settings can only be added to the `ai_integration_team` or
  `declarative_settings` sections.
- Traffic between Nextcloud and the app is authenticated with a shared secret, so
  it must run over a trusted network or TLS.

## Support

https://github.com/devsoftmus/done-transcription-app/issues

## Licence

AGPL-3.0-or-later.
