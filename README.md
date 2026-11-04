# MyGenoTree

Local HTTPS MVP for creating and editing private genealogy trees.

## Requirements

- Docker Desktop with Docker Compose v2
- a browser supported by the project specification

No host PHP, Composer, Node.js, npm or PostgreSQL installation is required.

## Start

```bash
docker compose build
docker compose run --rm backend composer install
docker compose run --rm frontend npm ci
docker compose up -d
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
```

Open <https://localhost>.

### Map services

Create `backend/.env.local` and keep the Yandex credentials on the backend:

```dotenv
YANDEX_TILES_API_KEY=your-api-key
YANDEX_TILES_SIGNING_SECRET=your-base64url-signing-secret
```

The browser requests authenticated same-origin tile URLs. The backend rate
limits and signs them, then redirects the browser to Yandex; the signing secret
is never included in frontend assets. Nominatim is configured through
`NOMINATIM_BASE_URI` and `NOMINATIM_USER_AGENT`; the default is intended for
local development and a deployment should use an identifying contact URL or
email in its user agent.

Yandex `Optional + WithTTL` signing is acceptable only while developing this
local MVP. Before exposing the application outside a trusted environment,
disable `Optional` in the Yandex dashboard and verify that the equivalent
unsigned Tiles request returns `403`. A signed redirect can be replayed during
its validity period without reaching the application's limiter. Use a full
backend tile proxy instead of redirects in an untrusted public environment.

The reverse proxy creates a local development certificate on first start. To
trust its certificate authority on Windows, export it and add it to the trusted
root store:

```powershell
docker compose cp proxy:/data/caddy/pki/authorities/local/root.crt .\mygenotree-local-ca.crt
Import-Certificate -FilePath .\mygenotree-local-ca.crt -CertStoreLocation Cert:\CurrentUser\Root
```

The exported certificate can be deleted after it is trusted. The certificate
authority and application data are retained in named Docker volumes and are not
committed to Git.

## Development commands

### Tree merge chat and change sets

The AI tab opens a session chat in the right panel. Name people directly: a
unique matching Ivan is selected from the tree; multiple matches require a
clarification. A supplied patronymic or surname narrows the match. The narrative
reference is optional and the layout reference is unaffected. Closing the panel
preserves the current session; cancelling the merge is a separate action.

Full messages are not ORM fields and recordings are never attached to a tree.
Conversation memory is scoped to the authenticated login session in a Docker
tmpfs cache (`TREE_MERGE_SESSION_DIR`), with a one-hour lifetime. Refreshing within
that session restores messages; another login session cannot read them. Container
restarts discard them. Unsent text stays in the mounted panel only, with no browser
storage. Durable merge proposals, their short supporting quotes, corrections and
application receipts remain available for review and atomic, idempotent application.

The `/api/v1/trees/{treeId}/merges` contract covers draft creation, recent drafts,
reading, idempotent messages and cancellation. Message writes require the draft
version and a unique `requestId`; concurrent edits return `409` and preserve
the user's input. All drafts use the tree's ownership voter. Source text is
limited to 10,000 characters in total. A numeric source-length counter survives
session expiry without retaining the full source text.

`PUT .../{mergeId}/proposal` validates explicit corrections against the complete
prospective graph without writing people or relationships. A proposal can create
up to five people, update existing core fields, and add parent or marriage links.
Only unresolved identities and information essential to valid relationships block
application. Missing surnames, patronymics and dates remain unknown; new people
are considered alive unless death is mentioned. Existing death information is
preserved unless explicitly corrected. Candidates
come from the existing name search. Unique exact supplied name parts are checked server-side; ambiguous or diminutive names require clarification.
`POST .../apply` requires both the draft and proposal versions and `If-Match`.
It saves the application receipt in the same ORM transaction as people, links,
automatic events, tree version and layout invalidation. Retrying returns that
receipt. A tree conflict revalidates the proposal and requires confirmation again.
`POST .../revalidate` is available for tree-version conflicts; changed source
messages must be parsed again or explicitly corrected.

The general `Application/Tree/ChangeSet` executor has no chat or AI dependency
and no five-person limit. Other application workflows can compose its ORM
transaction with their own receipt. Its projection reads the graph in three
queries, and application repeats validation under the tree lock.

The versioned preview is projected onto the tree without saving its people,
relationships or layout. Existing people are faded; new people keep their normal
colors. Clicking a proposed person opens corrections, candidate selection,
before/after values and source quotations on the canvas. The chat contains a short
reply and any essential questions. Corrections are checked on
the server before the review checkbox and apply button become available again.
The preview URL contains `?merge={mergeId}` and restores the conversation after
refresh within the same login session. Applying refreshes the snapshot, highlights the affected people and
focuses the camera on them without changing the layout reference.

Text extraction uses Symfony AI Platform structured PHP DTOs and a versioned,
tree-only system prompt (`MergePrompt`). `POST .../{mergeId}/process` processes
the latest session message with its `requestId` and draft version. Inference runs
outside database transactions. A persisted attempt/version check discards late
responses; failures retain the source and can be retried. Informational answers
and refusals leave the proposal version unchanged. Tree answers render observed
facts; arbitrary model prose cannot become a saved tree fact or apply a merge.
Each user is limited to 20 processing attempts per hour.
Context gathering and structured extraction are separate Symfony AI calls, so
the provider need not support tool calling and a JSON schema in the same call.
AI DTOs use plain name strings and compact dates (`~1938`, `1938..1940`,
`unknown`); the mapper converts these into the existing KnownText/date contract
and the domain policy validates them. This notation is internal to inference.
The chat polls saved processing attempts; after five minutes an interrupted
attempt can be retried without sending the source message again.

Proposal validation allows two automatic repair calls after the initial model
response (three generations maximum per processing attempt). Each repair receives
the rejected response and machine-readable diagnostics, including a precise field
path, rule and explanation. Unknown relationship endpoints include the available
temporary IDs. Repairs reuse the bounded HTTP MCP context and do not restart
scope classification or tool gathering. Each returned proposal is checked again
under a short tree/draft lock; no model request runs inside an ORM transaction.
New messages, manual edits and cancellation supersede any in-flight repair.

When repairs are exhausted, the session chat contains a localized assistant reply
with `failureCode=validation_failed`; provider failures use `provider_unavailable`
without automatic provider retries. Source messages and the reviewed proposal
remain available, and no tree data is applied. A failed reply does not mark its
source as successfully answered: manual retry replaces that reply without
duplicating the message. The UI restores failures after refresh and offers retry
or a new clarification. Fixtures cover recovery, exhaustion and stale responses;
live-provider checks use synthetic data without applying changes.

The selected local development agent is OpenCode 1.18.32 from WSL using
`openai/gpt-5.6-luna` (the installed provider catalogue does not list gpt6-luna).
`compose.opencode.yaml` mounts the installed Linux executable and credentials
read-only. Set `OPENCODE_BINARY_PATH` and `OPENCODE_AUTH_PATH` in the ignored root
`.env`, and include the override in `COMPOSE_FILE`; never commit credentials.
The backend seeds only the selected provider into a private credential volume
with mode 0600. Rotated OAuth credentials survive calls and container restarts;
concurrent calls cannot refresh the same credential simultaneously. WSL files
remain unchanged. After a fresh `opencode auth login` in WSL, recreate the backend
to refresh its seed (`docker compose up -d --force-recreate backend`). Conversation
data stays in RAM and is never written into the credential volume.

Each call starts a separate loopback OpenCode server with random Basic auth, an
isolated RAM home and no project configuration. All built-in tools are denied;
only the three tree MCP read tools are allowed. No personal MCP servers are
inherited. Runtime data, SQLite sessions, logs and temporary credentials are
removed when the call finishes, including failures. OpenCode is rejected outside
the Symfony dev environment. The default provider remains Ollama unless
`TREE_MERGE_AI=opencode` is set by this Compose override.

This installed OpenCode version fails to serialize responses when its native
`format=json_schema` option is used (`OutputFormatJsonSchema`). The adapter sends
the Symfony AI DTO schema in the prompt, deserializes the response into the same
PHP DTOs and allows one format repair with concrete diagnostics. Domain validation
still allows at most two repairs and application still requires the preview button.
See [OpenCode server configuration](https://opencode.ai/docs/server/) and
[tool permissions](https://opencode.ai/docs/permissions/).

The chat displays elapsed thinking time, completed MCP read stages, validation
and repair progress. A donut in the composer shows the remaining daily token
percentage; its keyboard-accessible tooltip shows the exact shared user quota.
The textarea grows to seven lines before scrolling, independently of its buttons.
The `tree_merge_tokens` Symfony rate limiter
counts all completed inference steps, including classification, tool rounds,
format and domain repairs, cached input and reasoning output. Its initial limit
is **10,000,000 tokens per user per day** (`TREE_MERGE_TOKEN_LIMIT`). Calls are
checked before starting; already spent tokens are recorded even if an in-flight
response crosses the limit. Further calls then stop with a localized explanation.
Ollama calls use the same accounting via a Platform decorator.

The alternative local text provider can be started with Docker Compose (CPU is supported):

```sh
docker compose --profile ai up -d --build
docker compose exec -T ollama ollama pull qwen3:8b
```

`OLLAMA_URL`, `TREE_MERGE_MODEL` and `TREE_MERGE_MCP_URL` are backend settings.
The pinned Ollama image stores models in `ollama_models`; its context window is
32,768 tokens. Tests use prepared responses and do not require a running model.

For an NVIDIA GPU available to Docker, enable `compose.gpu.yaml`. Add these
settings to the ignored root `.env` so ordinary Compose commands keep using it
on both Windows and Linux:

```dotenv
COMPOSE_PATH_SEPARATOR=,
COMPOSE_FILE=compose.yaml,compose.gpu.yaml
```

Then recreate only the inference service:

```sh
docker compose --profile ai up -d --no-deps ollama
docker compose exec -T ollama nvidia-smi
docker compose exec -T ollama ollama ps
```

`ollama ps` shows CPU/GPU placement after the first model request. The GPU
override preserves the 32,768-token context and enables Flash Attention with
an 8-bit KV cache, one loaded model and one parallel request to limit VRAM use.
Qwen3 8B may use partial CPU offloading on an 8 GB GPU; actual placement depends
on other applications using the device. See the [Ollama memory settings](https://docs.ollama.com/faq#how-can-i-set-the-quantization-type-for-the-kv-cache).
The base Compose configuration still supports CPU-only machines. Remove the two
root `.env` settings and recreate `ollama` to return to CPU operation. Models
remain in the same volume.

The AI chat opens from a small tab attached to the right screen edge. Its SVG
icon remains visible; hovering or keyboard focus reveals the AI label. The same
tab follows the animated panel and collapses it without losing the conversation,
unsent input, person selection or camera position. Reduced-motion preferences
disable the transitions. The composer is immediately available in any tree. Its
scrollable text area and microphone/send controls occupy separate rows.

Voice input is recorded directly using the microphone button inside the composer;
there is no file-upload control. Browsers record WebM/Opus, Ogg/Opus or MP4/AAC
according to their capabilities. The backend checks
the actual MIME, audio stream, 10 MiB size and decoded duration (at most 90 seconds),
then uses FFmpeg to produce mono PCM WAV at 16 kHz. Audio exists only during
`POST /api/v1/tree-merge-transcriptions`; the upload is deleted before the response
and no media objects, draft or tree are needed. Transcription fills the composer
without sending a chat message: review/edit the text, then Send. Closing the panel
stops recording. The browser releases recording buffers after the request. A failed
transcription asks for a new recording; there is no saved-audio retry or upload UI.

The development transcription adapter uses the pinned CPU Speaches/Whisper
service. Download its model once after starting the `ai` profile:

```sh
docker compose exec -T backend curl --fail -X POST http://whisper:8000/v1/models/Systran/faster-whisper-small
```

Backend settings `WHISPER_URL` and `WHISPER_MODEL` select this local service.
The model volume is `whisper_models`. Each user has at most ten recognition
attempts per hour. Tests use prepared provider responses and real FFmpeg container
conversion, without network ASR calls. Hugging Face activation remains deferred.
The versioned `MergePrompt::SYSTEM` is entirely in English while requiring
responses in the user's interface language and preserving original names/quotes.

The official `mcp/sdk`, Symfony MCP Bundle and Symfony AI MCP bridge expose
Streamable HTTP at `https://my-geno-tree.localhost/mcp/tree`. External clients
authenticate with the existing application's `Authorization: Bearer` session
token. Available tools are `get_tree_context`, `find_people`,
`get_person_context`, `prepare_tree_merge` and `get_tree_merge`. Preparation
returns an absolute preview link (`APP_URL`); no MCP tool applies changes.

The embedded agent uses real HTTP MCP requests to the separate internal
`/mcp/read` endpoint on proxy port 8082 and a separate PHP-FPM pool. That port is
not published to the host. Backend-issued access lasts five minutes, is bound
to the user/tree/processing attempt, permits at most 16 protocol requests and
is revoked after processing. It is rejected by REST mutations. The agent's
toolbox contains only the three read tools, allows eight context calls and
60 KB of cumulative tool output, and never calls `prepare_tree_merge` recursively.
Every tool checks the tree voter; context contains at most ten parent/child
links and ten marriages, and name search returns at most five candidates.
MCP sessions use separate cache prefixes and Symfony locks for each endpoint.

Validation: backend API tests cover session isolation, retries, conflicts, source
limits and ownership; component tests cover named-person input, timer/progress, errors and
input preservation. PhpStorm inspections report two non-actionable environment
findings: constructor arguments on `TreeMergeDraft` and the per-attempt
`MeteredMergePlatform` are misidentified as service injections,
and generated migration SQL has no IDE data source. Both objects are
created explicitly by application services; Doctrine migration and schema
checks validate the SQL against PostgreSQL. The generated Symfony configuration
reference also triggers IDE-only `list<...>` type warnings; it is regenerated by
Symfony, not edited manually. PHPStan and container checks validate application
types and service configuration.

Earlier browser checks (before the session-only/OpenCode changes) included restoring a conversation,
retrying a provider failure, reading an existing person's date through HTTP MCP,
refusing a role-changing programming request, correcting a live Qwen proposal
(names, life status, parent role and unwanted links), and confirming the resulting
four-person branch. Sending "apply" kept the reviewed version and left the tree
empty until the separate confirmation control was used. Two-tab ETag conflicts,
reconfirmation and existing-person updates were also checked.


Current revision: a real OpenCode request prepared two people and a mother link
through HTTP MCP in 22 seconds, with 10,340 tokens recorded and no tree mutation.
An existing-tree check resolved the only Ivan and proposed Ivan Vitalyevich and
Anna Andreyevna as new parents in 30 seconds, using 20,558 tokens, with no
clarification questions or changes before confirmation.
Browser validation on 4 November used a separate synthetic tree: the live OpenCode
agent prepared Irina and Vera without surname/date/life-status questions; the
canvas preview remained unsaved until confirmation. Editing an approximate birth
year, applying the branch, matching the existing Irina and adding her father worked.
Existing cards were faded and the new card retained its normal color. A concurrent
person edit caused an ETag conflict, refreshed the preview and cleared confirmation;
confirming the new version produced exactly three people and highlighted the branch.
The reviewed package also survived a new login without exposing the prior session's
messages. Closed/open panel shadows, displaced toolbars, seven-line input scrolling,
keyboard-accessible quota tooltips and Russian/English text were checked in Chrome.
Starting/stopping microphone recording and handling an empty recording were checked;
successful audio normalization/transcription is covered by prepared fixtures and
the local-provider API checks. No automatic browser E2E tests were added.
Run all application tooling inside its service container:

```bash
docker compose exec backend composer test
docker compose exec frontend npm test
```

Run the code-quality checks with:

```bash
docker compose exec backend composer quality
docker compose exec frontend npm run quality
```

Enable the versioned pre-commit hook once after cloning the repository:

```bash
git config core.hooksPath .githooks
```

The hook runs the backend and frontend quality commands through Docker Compose
and blocks the commit when any formatter, static-analysis, lint, or type check
fails. The Compose services must be running before committing.

## Demo fixture

To add the demo account and tree to an existing local database without
removing current data, run:

```bash
docker compose exec backend php bin/console doctrine:fixtures:load --append --no-interaction
```

Do not run `doctrine:fixtures:load` without `--append` against a database that
contains data you need to keep: Doctrine purges it by default.
