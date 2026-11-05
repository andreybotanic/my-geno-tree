# MyGenoTree

Local HTTPS MVP for creating and editing private genealogy trees.

## Requirements

- Docker Desktop with Docker Compose v2
- a browser supported by the project specification

No host PHP, Composer, Node.js, npm or PostgreSQL installation is required.

## Start

Set `MERCURE_JWT_SECRET`, `TREE_MERGE_AGENT_KEY` and `TREE_MERGE_MCP_KEY` in the
ignored root `.env` to independent random secrets of at least 32 bytes.
For a new checkout, this command generates them inside Docker (the temporary
`setup` values only permit Compose configuration parsing):

```bash
MERCURE_JWT_SECRET=setup TREE_MERGE_AGENT_KEY=setup TREE_MERGE_MCP_KEY=setup docker compose run --rm --no-deps -v .:/workspace --entrypoint node frontend -e 'const fs=require("node:fs"); const p="/workspace/.env"; const s=fs.existsSync(p)?fs.readFileSync(p,"utf8"):""; for(const key of ["MERCURE_JWT_SECRET","TREE_MERGE_AGENT_KEY","TREE_MERGE_MCP_KEY"]) if(!new RegExp("^"+key+"=.+$","m").test(s)) fs.appendFileSync(p,"\n"+key+"="+require("node:crypto").randomBytes(32).toString("hex")+"\n",{mode:0o600});'
```

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

Chat updates use `symfony/mercure-bundle` and a Mercure 1.0 hub behind the same
HTTPS proxy at `/.well-known/mercure`. `POST .../{mergeId}/events` issues a
five-minute Secure, HttpOnly, SameSite=Strict subscriber cookie limited to the
current login session. The browser renews that authorization before expiry;
it receives committed draft changes, processing steps and token usage over SSE.
There is no periodic chat-state GET. Opening or reconnecting the stream fetches
one snapshot to recover missed changes; versions and attempt IDs prevent stale
events from replacing a newer answer. Temporary failures show a localized
reconnection status and preserve the conversation and unsent input.

The hub uses its in-memory `local` transport without retained event history or
published host ports. Conversations and tokens never appear in subscription URLs.
Progress publications omit MCP contexts. Hub delivery failures are logged without
chat contents and do not roll back successful mutations. `MERCURE_URL` is the
internal publish endpoint; `MERCURE_PUBLIC_URL` and `MERCURE_JWT_ISSUER` match
the hub's configured audience and issuer. Keep these values and the two Caddy
configurations synchronized when changing the deployment hostname.

`PUT .../{mergeId}/proposal` validates explicit corrections against the complete
prospective graph without writing people or relationships. A proposal can create
up to ten new people, update existing core fields, and add parent or marriage links.
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
and no merge-specific person limit. Other application workflows can compose its ORM
transaction with their own receipt. Its projection reads the graph in three
queries, and application repeats validation under the tree lock.

The versioned preview is projected onto the tree without saving its people,
relationships or layout. Existing people are faded; new people keep their normal
colors. The preview consists of graph cards and relationships, without detailed
person forms. Corrections and essential clarifications go through the chat and
are checked on the server. The separate apply button confirms the current checked
proposal directly, without an additional checkbox.
Messages have compact bubbles: user messages on the right, assistant replies on
the left. Ctrl+Enter sends a message; Enter inserts a line break. Opening the chat
hides the person or network sidebar and releases its grid column without clearing
the selected person or the sidebar's edit state.
The preview URL contains `?merge={mergeId}` and restores the conversation after
refresh within the same login session. Applying refreshes the snapshot, highlights the affected people and
focuses the camera on them without changing the layout reference.

The agent configured in `backend/config/packages/ai.yaml` uses the versioned
tree-only prompt and MCP tool contract. `POST .../{mergeId}/process` starts a
scoped attempt for the latest message's requestId and draft version. No model
request runs inside an ORM transaction. Each user is limited to 20 attempts per
hour. The agent receives the entire session conversation, including earlier
questions and user answers; only user messages can substantiate source quotes.

Sending starts the agent immediately. Private Mercure SSE delivers processing
stages and draft updates; there is no periodic chat-status polling. While the agent
runs, Send becomes Stop. `POST .../{mergeId}/stop` targets that message's requestId,
invalidates subsequent MCP commands and preserves the conversation. Old commands cannot
overwrite newer input, a stopped turn or a reviewed proposal. Interrupted
attempts can be retried after five minutes.

MCP validation errors include the field path, rule and diagnostic. The agent
corrects its own commands within the tool budget, or sends one essential
clarification question itself. The application does not invent fallback questions
or replies. A provider failure or missing reply command produces a retryable
system notice; the source and earlier reviewed proposal remain available.
Application of tree changes still requires the separate preview confirmation.

`backend/config/packages/ai.yaml` configures the ordinary chat through the stock
Symfony AI Generic platform and `ai.agent.tree_merge`. The HTTP endpoint is
`http://hermes:8642`; the selected model is `gpt-5.6-luna`, the lightest model
verified in this account's catalog on 2026-11-05. Change the endpoint, API key
reference and model in this file to select another OpenAI-compatible agent.
The application does not launch agents or use an ACP client, custom runner or
local MCP toolbox. Clear the Symfony cache after production configuration changes.

### Hermes in Docker Compose

The pinned official Hermes image runs independently as the `hermes` service.
Its native `/v1/chat/completions` API and the internal MCP port are accessible
only on the Compose network. `docker/hermes/config.yaml` selects ChatGPT browser
authentication (`openai-codex`), permits the HTTP request's model selection,
disables memory and native tools, and registers one permanent MCP connection.
Only the five named tree-chat tools are enabled.

Authenticate once inside the service (open the displayed login URL in your browser):

```sh
docker compose up -d
docker compose exec -u hermes hermes hermes auth add openai-codex --type oauth --no-browser
docker compose restart hermes
```

Existing Hermes authentication can instead be imported once using
`docker compose cp /path/to/auth.json hermes:/opt/data/auth.json`, followed by
`docker compose exec -u root hermes chown hermes:hermes /opt/data/auth.json`
and a service restart. Keep credentials out of the repository.
The `hermes_data` volume stores Hermes authentication, sessions and logs;
backend has no access to it. Recreating the container preserves that data.
Do not enable memory or extra native tools for this shared web-chat service.

`TREE_MERGE_AGENT_KEY` authenticates backend requests to Hermes.
`TREE_MERGE_MCP_KEY` authenticates Hermes to the internal MCP endpoint.
These independent random secrets belong in the ignored root `.env`.
The service key permits discovery; it does not identify a website user or grant
access to any tree. Each tool also requires the backend-issued `accessToken`
for a particular chat/login session and the current `attemptId`.
The chat key is reused between messages, expires after one hour of inactivity,
and cannot be used as a REST login token. Backend rechecks the active login,
current attempt and tree ownership on every call. User identity exists only
during that call, never on the shared MCP connection. Logout or Stop therefore
blocks subsequent tool actions without reconfiguring Hermes or its connection.

The chat key is a cryptographically random UUIDv4; the tool schemas describe its
format so small models can copy it reliably. Agents must stop after an authorization
or stale-attempt error. Hermes 0.22.7 has a non-configurable per-server circuit
breaker: three consecutive MCP failures pause that connection for 60 seconds,
including other HTTP requests using it. This affects availability, not tree access;
the UI preserves the conversation and permits another message after recovery.

The external agent chooses and executes MCP commands independently. Its HTTP
response text is discarded; only `reply_to_tree_merge` publishes the exact
single-sentence reply. The Generic platform uses the standard HTTP request and
usage fields, with no Hermes-specific application code. See the
[Symfony AI Generic bridge](https://symfony.com/doc/current/ai/platform/generic.html)
and [Hermes documentation](https://hermes-agent.nousresearch.com/).

The chat displays elapsed thinking time, completed MCP read stages, validation
progress. A donut in the composer shows the remaining daily token
percentage; its keyboard-accessible tooltip shows the exact shared user quota.
The compact quota ring stays centered alongside the buttons. The textarea grows
from two to seven lines before scrolling; measurement excludes the scrollbar so
wrapping does not add a premature extra line.
The `tree_merge_tokens` Symfony rate limiter
counts all completed agent steps, including tool rounds, cached input and
reasoning output. Its initial limit
is **10,000,000 tokens per user per day** (`TREE_MERGE_TOKEN_LIMIT`). Calls are
checked before starting; already spent tokens are recorded even if an in-flight
response crosses the limit. Further calls then stop with a localized explanation.
Symfony AI invocation/result events account for the external HTTP agent's reported
aggregate usage. The next attempt checks the updated quota. A remote turn can
finish across the remaining token limit, with a 270-second client timeout.

The optional Ollama provider can be started with Docker Compose (CPU is supported).

```sh
docker compose --profile ai up -d --build
docker compose exec -T ollama ollama pull qwen3:4b-instruct
```

Ollama remains an optional standalone development service and is not used by the
web chat. It stores models in `ollama_models`; its context window is 32,768 tokens.
Automated tests use prepared HTTP responses and do not require a running model.

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

The agent performs all interpretation, matching, extraction and deductions using
MCP and the full conversation. Application code does not classify prose, infer
names or dates, or execute final model text/JSON. The agent sends the visible answer
with `reply_to_tree_merge`: one short sentence, stored verbatim, including the
number of new people and any omission reasons. The server never adds summaries,
assumptions or explanations. Processing errors and Stop are localized system
notices, separate from assistant messages.

The prompt requires every mentioned relative, including unnamed counted children,
and supports approximate dates and inferred names when justified by MCP facts.
The agent supplies complete structured names and dates itself; validation checks
the explicit command, source quotes, ownership and tree invariants without trying
to interpret the user's text. A rejected command returns diagnostics to the agent.
Browser validation on 4 November covered the supplied Ivan family example in a
separate synthetic tree: six proposed people, Petr's inferred surname/patronymic,
Anna's approximate 1992 birth year and two unnamed sons. A concurrent edit in a
second tab blocked application until the refreshed proposal was confirmed again.
Application and page refresh retained exactly seven people and the conversation.

MCP-only browser verification (4 November): the live agent submitted all six new
people and replied exactly "К добавлению готово 6 персон."; the preview included
Anna's approximate 1992 birth year, Petr's surname/patronymic and both unnamed
sons. Applying in another tab updated the original tab through SSE. A subsequent
request for Vera while omitting Boris produced one sentence with count and reason.
Stop appeared as a separate system notice; continuing preserved the conversation
and existing people, and applying then refreshing retained eight people. Agent
instructions explicitly require empty fields for unchanged existing endpoints.

The official `mcp/sdk` and Symfony MCP Bundle expose
Streamable HTTP at `https://my-geno-tree.localhost/mcp/tree`. External clients
authenticate with the existing application's `Authorization: Bearer` session
token. Available tools are `get_tree_context`, `find_people`,
`get_person_context`, `prepare_tree_merge` and `get_tree_merge`. Preparation
returns an absolute preview link (`APP_URL`); no MCP tool applies changes.

The external agent uses real HTTP MCP requests to the separate internal
`/mcp/agent` endpoint on proxy port 8082 and a separate PHP-FPM pool. That port is
not published to the host. A service credential permits protocol discovery,
while each tool independently validates its chat key and current attempt.
The tool registry contains the three read tools, `prepare_tree_merge_proposal`
and `reply_to_tree_merge`, with at most eight context reads per attempt.
Hermes allows sixteen agent turns. Preparing validates and stages a proposal
for five minutes in ephemeral memory; replying with its proposalId atomically
submits it for UI review and stores the agent's sentence.
A stale version, changed tree, expired session or stopped attempt cannot submit
it. No tool applies tree mutations or recursively starts `prepare_tree_merge`.
Discovery and HTTP transport requests do not consume the context-read budget.
Search results are candidate hints: an existing person must also occur in a
full context read before a proposal can reference their ID.
Every tool checks the tree voter; context contains at most ten parent/child
links and ten marriages, and name search returns at most five candidates.
MCP sessions use separate cache prefixes and Symfony locks for each endpoint.

Validation: backend API tests cover session isolation, retries, conflicts, source
limits and ownership; component tests cover named-person input, timer/progress, errors and
input preservation. PhpStorm inspections report two non-actionable environment
findings: constructor arguments on `TreeMergeDraft` are misidentified as
service injections, and generated migration SQL has no IDE data source.
The draft is created explicitly by application services; Doctrine migration and schema
checks validate the SQL against PostgreSQL. The generated Symfony configuration
reference also triggers IDE-only `list<...>` type warnings; it is regenerated by
Symfony, not edited manually. PHPStan and container checks validate application
types and service configuration.

Mercure browser validation (2026-11-04): two tabs of the same test conversation
received messages, MCP progress and the ready proposal without reloading. Stopping
the hub displayed the reconnection status. A new message and Stop action still
completed over REST while the hub was down. After restarting it, reconnecting
restored the missed messages and stopped state while preserving unsent input in
the second tab. Subscription renewal and recovery, stale-attempt rejection,
private session grants and ownership failures are also covered by automated tests.
Resuming the conversation produced a ready proposal with the approximate year;
applying it in one tab updated the tree and closed the preview in both tabs.

Earlier browser checks (before the session-only/OpenCode changes) included restoring a conversation,
retrying a provider failure, reading an existing person's date through HTTP MCP,
refusing a role-changing programming request, correcting a live Qwen proposal
(names, life status, parent role and unwanted links), and confirming the resulting
four-person branch. Sending "apply" kept the reviewed version and left the tree
empty until the separate confirmation control was used. Two-tab ETag conflicts,
reconfirmation and existing-person updates were also checked.


Earlier runtime validation: a real OpenCode request prepared two people and a mother link
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
Additional Chrome checks on 4 November covered the 20 px quota ring aligned with
32 px buttons, two-line minimum input, real text wrapping without a premature row,
growth to seven lines and scrolling beyond that. Sending starts immediately with
Stop replacing Send. Stopping a live OpenCode request preserved the conversation
and removed its temporary runtime. A failed proposal produced specific assistant
questions; replying "Да" retained the full conversation and prepared Pavel and
Oleg with the existing Vera and Mikhail as their parents. Refresh restored the
questions and unconfirmed preview. Explicit confirmation added exactly two people
to the synthetic tree (three to five), with the resulting branch highlighted.
The original user's tree was not changed.
The ten-person limit was checked in Chrome with a separate empty tree: Anna and
her nine children appeared in the preview. Adding an eleventh person prompted a
choice and disabled confirmation. After deferring that person, explicit approval
created exactly ten people with nine parent links. API tests also cover ten new
people alongside existing relatives and rejection of eleven new people.
The simplified chat was also checked in Chrome on 4 November: opening it from
person editing or the network sidebar releases the left grid column, and closing
it restores the sidebar and selection. The tree camera stayed unchanged when
opening an empty chat. Enter inserted a newline; Ctrl+Enter sent the message.
User and assistant bubbles aligned right and left without visible author headings;
Russian and English send tooltips were checked. Clicking a proposed card no longer
opened detailed fields, and no review checkbox was present. A real OpenCode turn
prepared Pavel's daughter Sofia in the synthetic tree. A change in a second tab
produced a version conflict; the refreshed proposal waited for another explicit
apply click, then added exactly one person (five to six). Refresh preserved both
the saved result and the session conversation. The interface language was restored
to Russian after these checks.

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

Hermes HTTP browser validation (2026-11-05): the original Ivan/Anna example
produced a verbatim one-sentence proposal for six new people, Anna's approximate
1992 birth year, Petr Ivanovich's inferred surname/patronymic and both unnamed sons.
Applying and refreshing retained seven people and the existing Ivan's exact date.
Stopping Hermes showed a recoverable provider notice; after restart the agent
answered the saved question about Anna. Stop rejected late commands and a later
message completed. Progress arrived through Mercure without periodic chat polling.
The smallest model remains fallible: in a follow-up factual answer it confused
the father's patronymic despite correct MCP records; the stored relationships and
names were unchanged. No application code rewrites these answers.

Validation for this transport change: 209 backend tests and 385 frontend tests;
92.81% backend line coverage and 90.17% frontend line coverage, with every measured
production file above 75%. PHPStan, linters, OpenAPI checks, production builds,
clean-database migrations and affected-file PhpStorm WARNING inspections passed.
The backend suite retains nine existing PHPUnit mock-expectation notices in map
and GEDCOM tests.
