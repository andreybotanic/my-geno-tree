# Voice validation history

Voice-limit validation (2026-11-09): all 286 backend, 443 frontend and 28 gateway
tests pass, with line coverage of 93.49%, 90.98% and 98.45%. Tests cover more than
ten attempts through both APIs, accepted 180-second audio, rejected 181-second
uploads, grant lifetime, and timer boundaries at 169/170/179/180 seconds.
Quality, OpenAPI, production builds, Composer/container checks and all 26
migrations on a clean database pass. PhpStorm WARNING inspections are clean for
all six affected PHP files. Manual HTTPS checks covered the restored chat,
editing, and microphone permission failure with unchanged input geometry and a
preserved draft. The in-app browser denied microphone access; live browser
recording remains unverified. Automated tests verify duration and the red timer.

Audio histogram validation (2026-11-07): all 271 backend and 431 frontend tests
pass. Line coverage is 93.24% backend and 90.46% frontend; new histogram/capture
files have 100% line coverage. Backend/frontend quality, OpenAPI, Composer
validation, schema and production-container checks, production build, and all
26 migrations on a fresh disposable database pass. No PHP files changed.
Manual Chrome HTTPS validation covered starting the microphone, the centered
histogram, disabled editing during recording, Stop releasing capture, and the
localized empty-recording error returning the composer to an editable state.

Streaming voice validation (2026-11-07): all 278 backend, 433 frontend and 27
gateway tests pass. Line coverage is 93.32%, 90.58% and 98.43%, respectively;
every measured production file meets 75%. Backend/frontend/gateway quality,
OpenAPI, Composer validation, dev/prod container checks, schema validation,
production builds and all 26 migrations on a fresh disposable database pass.
PhpStorm WARNING inspections are clean for all five affected PHP files. Existing
PHPUnit notices and the Vite bundle-size advisory remain.

Manual Chrome HTTPS validation used an isolated empty tree and disposable
synthetic Russian audio. It covered centered bars during speech, phrases arriving
before Stop, finishing mid-speech, preserving existing draft text, editing before
manual Send, and receiving the real agent's acknowledgment without changing the
tree. Stopping the gateway preserved completed phrases and restored editing;
after restarting it, another recording appended to the same draft. A simulated
microphone-permission denial showed the localized error. The temporary audio,
browser fixture and QA tree were removed, and the original tree list restored.

Voice presentation follow-up (2026-11-07): all 278 backend, 437 frontend and 27
gateway tests pass. Frontend line coverage is 90.63%; histogram/history files
have 100%, and every measurable production source file remains above 75%.
The complete frontend coverage run used two workers after the existing
101-person force-simulation test exceeded its five-second limit under parallel
load. Quality, OpenAPI, coverage, production builds and fresh-database migration
checks pass. No PHP files changed in this follow-up.
Manual Chrome HTTPS checks with disposable synthetic audio covered stable
scrolling bars, both edge fades, successive phrases joined by spaces, Stop
mid-speech, final-phrase delivery, editing the draft and microphone denial.
Temporary fixtures and the empty QA tree were removed; the user's open draft
was preserved.
