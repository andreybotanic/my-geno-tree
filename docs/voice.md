# Voice recognition

Voice recording streams PCM16 mono at 24 kHz through the same-origin WebSocket
gateway to Speaches. Completed phrases fill the composer while recording; Stop
finishes the last phrase before enabling editing and Send. Nothing is submitted
to the AI chat until the user sends the reviewed text. A disconnected recording
stops the microphone and keeps completed phrases; another recording can continue
the draft, without reconnecting or replaying old audio automatically.

During recording, a centered bar histogram shows the last ten seconds of
microphone volume. Four-pixel bars are separated by two-pixel gaps and use the
maximum of twenty-millisecond peaks in each recording-aligned fragment. Fragment
boundaries are fixed when the recording first gets a nonzero display width;
completed bars retain their height while moving left, including after resizing.
Alpha gradients at both edges fade bars in and out. Successive recognized phrases
join with one space, preserving paragraphs already typed in the draft. The same
microphone stream feeds recording and the silent AudioWorklet; stopping releases both.

The CPU Speaches/Whisper service and gateway are optional. Install the gateway's
locked dependencies once, then start recognition:

```sh
docker compose run --rm --no-deps voice-gateway npm ci
docker compose --profile ai up -d whisper
docker compose exec -T backend curl --fail -X POST http://whisper:8000/v1/models/Systran/faster-whisper-small
docker compose --profile ai up -d voice-gateway
docker compose exec -T proxy caddy reload --config /etc/caddy/Caddyfile
```

The gateway builds its strict TypeScript on startup. Restart it after changing
its source. `WHISPER_MODEL` configures its recognition model; `SPEACHES_URL`,
`VOICE_AUTH_URL` and `VOICE_ORIGINS` configure the internal connections and browser
origin allowlist. The existing `WHISPER_URL` and `WHISPER_MODEL` backend settings
still configure file transcription. The model volume is `whisper_models`.

Symfony issues one-use recording grants bound to the login. The gateway consumes
them through a Docker-internal authorization listener, checks active access every
two seconds and before releasing phrases, and releases temporary state on close.
The public authorization URL returns 404. Both voice APIs allow an unrestricted
number of attempts. Credentials never appear in URLs or application logs.

Recordings are limited to 180 seconds; the timer turns red for the last ten
seconds, starting at 170 seconds. Recording grants remain valid for 420 seconds
to cover startup, capture and finalization. Inbound frames are limited to 64 KiB and send queues to
256 KiB. Speaches VAD uses a 700 ms pause; the gateway orders completed phrases
and handles its 0.8.3 configuration warning explicitly. Internal silence finishes
active speech on Stop and is excluded from duration. Finalization has a 180-second
timeout. `LOOPBACK_HOST_URL` is required for Speaches' internal transcription call.

The original file endpoint remains compatible: it validates actual MIME/codec,
10 MiB and 180 seconds, normalizes to 16 kHz WAV with FFmpeg, and deletes uploads.
The browser uses the streaming path without automatic fallback. No audio, media
objects or database recording sessions are persisted.

Gateway verification runs independently inside Docker:

```sh
docker compose run --rm --no-deps voice-gateway npm run quality
docker compose run --rm --no-deps voice-gateway npm run test:coverage
docker compose run --rm --no-deps voice-gateway npm run build
```
