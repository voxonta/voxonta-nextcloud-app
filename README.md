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

- **Nextcloud 30 or newer.** Developed and tested against 33.
- **Talk with a High Performance Backend (HPB).** Audio is captured through the
  signalling server, so a Talk installation without an HPB is not supported.
- **The transcription service**, reachable from Nextcloud. It does the recording
  and the speech recognition; this app is what you and your users see.

No Docker and no AppAPI are needed for the app itself.

## Install

1. Unpack the release archive into your Nextcloud `apps/` directory:

   ```bash
   tar -xzf done_transcription-1.0.0.tar.gz -C /var/www/nextcloud/apps/
   chown -R www-data:www-data /var/www/nextcloud/apps/done_transcription
   ```

2. Enable it:

   ```bash
   occ app:enable done_transcription
   ```

   To try it with one team first, enable it for their group only — this hides
   the app from everyone else:

   ```bash
   occ app:enable done_transcription --groups pilot-team
   ```

3. Register the chat bot, so people can stop a recording from the conversation:

   ```bash
   occ talk:bot:install "Done Transcription" \
       "$(openssl rand -hex 32)" \
       "nextcloudapp://done_transcription" \
       "Transcribes calls and accepts the recording commands" \
       --feature event --feature response
   ```

   The secret is unused for an in-app bot — Talk requires the argument — but it
   should still be random.

4. Open **Administration settings → Artificial intelligence → Done
   Transcription** and enter the address and token of your transcription
   service.

## Upgrade

Replace the directory with the new release and run `occ upgrade`. Settings and
transcripts are unaffected.

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

The app is the Nextcloud side of the system, and only that: the meeting list,
the transcripts, the settings and the chat commands.

Recording and speech recognition happen in a separate transcription service.
It joins each call as an internal client of the signalling server and receives
one audio stream per speaker — that is why the transcript can say who said
what, and why a High Performance Backend is required. When a call ends, the
service produces the summary and stores the result; this app reads it back.

The service token stays on the server. Every archive request passes through
this app, which scopes the answer to the person asking: the service isolates by
tenant, not by user, and only Nextcloud knows who is on the other end of the
request.

## Development

```bash
composer install
npm ci

npm run watch      # rebuild the frontend on change
composer test      # controller tests, no Nextcloud instance needed
python3 -m pytest tests    # translations and packaging
```

Symlink the checkout into a Nextcloud installation's `apps/` directory and
enable it as usual.

Notes for contributors:

- **Every user-visible string goes through `t()`**, in Vue and in PHP alike,
  and into `l10n/ru.json` *and* `l10n/ru.js`. The tests check this: an
  untranslated string is not a failure anyone notices at runtime.
- **The bundle name and `Util::addScript` must agree.** They have drifted
  before; the symptom is a page that renders empty with no error.
- **The archive endpoints derive the user from the session, never from the
  request.** A `?user=` parameter would be a request to read someone else's
  calls, and the tests treat it as such.
- The chat bot runs on Talk's `BotInvokeEvent`, not a webhook — which is why
  the opt-out command also works in one-to-one calls.

## Support

https://github.com/devsoftmus/done-transcription-app/issues

## Licence

AGPL-3.0-or-later.
