# MyGenoTree

Local HTTPS MVP for creating and editing private genealogy trees.

## AI chat moderation

The site agent checks each submitted text, including reviewed voice transcripts,
with the separate Symfony AI `chat_moderation` agent. It uses the existing
CLIProxyAPI platform, `gpt-5.6-luna`, native Structured Output and Validator.
The checking agent has no tools or message store. Ordinary off-topic questions,
quoted instructions and family narratives are allowed.

Both agents load their system prompts through the bundle's `prompt.file` option:
`backend/prompts/tree.txt` and `backend/prompts/chat_moderation.txt`.
The tree agent preserves the source spelling, script, capitalization and
diacritics of given names, birth surnames and patronymics in replies and
proposals, regardless of interface language. Existing names change only on an
explicit user request.

Application parameters in `backend/config/services.yaml`:

| Parameter prefix: `app.chat_moderation.` | Default |
| --- | --- |
| `ban_seconds` | 3600 |
| `penalty_tokens` | 50000 |
| `temporary_bans` | 2 |
| `audit_threshold` | 0.5 |
| `warning_threshold` | 0.9 |
| `retention_days` | 30 |

Scores below the audit threshold pass without an SQL record. Scores between the
thresholds are logged and pass. Scores at or above the warning threshold block
the message and issue a localized warning. Every third warning resets the count
and creates a ban: two temporary bans, followed by a permanent ban represented
by a null expiration. Changing settings does not recalculate existing bans.
Each ban debits at most the remaining daily quota; unpaid penalties never carry
forward. Both models' actual usage is metered separately from penalties.

The independent SQL journal stores only suspicious text, risk, warning outcome,
time and an opaque unique retry key. It contains no chat, message or tree links.
Ban history and the user's counters survive chat reset and journal cleanup.
Audio stays temporary and is deleted even when access is denied.

Chat history and prepared proposals remain available during a ban. The main
agent receives a safe notice in place of blocked text, so it can acknowledge
the warning when discussing history. The original text stays visible to the user
and is excluded from evidence. Technical message IDs are reserved for tools and
must not appear in conversational quotes or summaries. Sending and
voice transcription return `403 CHAT_BANNED`; manual tree editing, proposal
application and external MCP remain available. A failed or invalid checking-model
response blocks inference without sanctions and returns `503 CHAT_UNAVAILABLE`.
Shared cache records this outage for 30 seconds. The panel receives access state
through action responses and Mercure. It performs one scheduled refresh at the
known temporary-ban, outage or exhausted-budget deadline; `tokenBudget.retryAt`
reports when exhausted tokens become available. There is no idle or focus polling.
A failed scheduled refresh offers an explicit retry. Changes from another device
can be learned on the next send attempt.

Mercure credentials renew before expiry using overlapping connections. A planned
handover loads no chat or tree snapshot and preserves the current preview, camera,
scroll position and unsent input. A genuine interruption gets a five-second grace
period before showing the reconnect notice, then a catch-up snapshot on recovery.
Unchanged messages and proposal objects retain their references during state
reconciliation. Access tokens nearing their known expiry are refreshed on demand
before an authorized request; no background authentication timer is used.

Start the hourly cleanup worker with `docker compose up -d chat-maintenance`.
It consumes `scheduler_default` using Symfony Scheduler and Messenger without
an external broker. The stateful schedule runs the last missed cleanup after a
restart. Run cleanup manually with
`docker compose exec -T backend php bin/console app:chat-moderation:purge`.
Only expired suspicious-message records are removed; bans and counters remain.

## Requirements

- Docker Desktop with Docker Compose v2
- a browser supported by the project specification

No host PHP, Composer, Node.js, npm or PostgreSQL installation is required.

## Start

Set `MERCURE_JWT_SECRET` and `CLI_PROXY_API_KEY` in the
ignored root `.env` to independent random secrets of at least 32 bytes.
For a new checkout, this command generates them inside Docker (the temporary
`setup` values only permit Compose configuration parsing):

```bash
MERCURE_JWT_SECRET=setup CLI_PROXY_API_KEY=setup docker compose run --rm --no-deps -v .:/workspace --entrypoint node frontend -e 'const fs=require("node:fs"); const p="/workspace/.env"; const s=fs.existsSync(p)?fs.readFileSync(p,"utf8"):""; for(const key of ["MERCURE_JWT_SECRET","CLI_PROXY_API_KEY"]) if(!new RegExp("^"+key+"=.+$","m").test(s)) fs.appendFileSync(p,"\n"+key+"="+require("node:crypto").randomBytes(32).toString("hex")+"\n",{mode:0o600});'
```

```bash
docker compose build
docker compose run --rm backend composer install
docker compose run --rm frontend npm ci
docker compose up -d
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
```

Open <https://localhost>.

PostgreSQL is available to host tools at `127.0.0.1:5433`. The database,
username are `mygenotree`. The published port is bound only
to the host's loopback address.

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

### External MCP and OAuth

Connect a remote HTTP MCP client to
https://my-geno-tree.localhost/api/v1/mcp. It discovers OAuth automatically,
registers a client, opens the existing MyGenoTree sign-in and asks for consent.
Consent grants scope mcp: reading and changing **all trees owned by that user**.
The local development CA must be trusted by the client. Cloud agents require an
externally reachable HTTPS installation; this change does not deploy one.

After installing dependencies, initialize the ignored signing/encryption keys
and migrate through the application container:

~~~bash
docker compose exec backend php bin/console league:oauth2-server:generate-keypair --skip-if-exists --no-interaction
docker compose exec backend php -r 'file_exists("config/jwt/encryption.key") || file_put_contents("config/jwt/encryption.key", bin2hex(random_bytes(32)));'
docker compose exec backend chown root:www-data config/jwt/private.pem config/jwt/encryption.key
docker compose exec backend chmod 640 config/jwt/private.pem config/jwt/encryption.key
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
~~~

Keep these keys stable across container restarts. Configure APP_URL as the
canonical HTTPS origin and OAUTH_MCP_HOST as its hostname. OAUTH_MCP_ORIGIN
adds an allowed browser-client origin; local MCP Inspector origins are included.
Discovery uses RFC 8414 and RFC 9728. Client registration supports public HTTPS
CIMD documents and DCR public or confidential clients. CIMD requests reject
private networks, redirects and documents larger than 64 KiB.

OAuth uses authorization code + PKCE S256, five-minute one-time consent/code,
15-minute resource-bound access tokens, and rotating 30-day refresh tokens.
Refresh-token replay and /api/v1/oauth/revoke revoke the user's connection to
that client, including its tokens and outstanding codes. MCP credentials do not
authorize ordinary site APIs. Legacy MCP sessions are bound to user and client;
current stateless MCP requests authenticate independently.

The OAuth server has one resource: the canonical MCP endpoint. Clients such as
Codex CLI 0.132 that omit the optional RFC 8707 `resource` parameter use that
endpoint by default, including code exchange and refresh. An explicitly supplied
different resource is rejected, and every access token retains the MCP audience.
For a local Windows Codex host, connect from PowerShell:

~~~powershell
codex mcp add mygenotree --url https://my-geno-tree.localhost/api/v1/mcp
codex mcp login mygenotree --scopes mcp
~~~

Start authorization in the agent rather than opening `/oauth/authorize` directly:
the page requires the client's authorization parameters, including the redirect
URI and PKCE challenge. Invalid requests must be restarted in the agent.
Network/server failures can be retried
on the consent page; a failed decision requires reviewing fresh consent.

The external tools are find_trees(query), get_tree_context(treeId,
referencePersonId?), find_people(treeId, query), get_person_context(treeId,
personId) and merge(treeId, ifMatch, proposal, sources). Tree discovery accepts
a fuzzy title or an exact UUID and returns up to five {id, name} candidates.
It queries only the authenticated owner's trees. Names use the existing
normalization, PostgreSQL trigram matching and Levenshtein distance.

Read person context before selecting identities; ask the user about ambiguous
matches. Each call explicitly names its tree. The merge tool uses the existing
proposal schema and evidence quotes; sources is a list of original user messages
{id, role: "user", content}. Quotes must occur in the supplied source message.
These sources come from the external client, not from the site's chat history.

**External merge applies immediately.** It is marked as a modifying,
non-idempotent tool; the client controls approval and may auto-approve according
to the user's settings. There is no additional elicitation or site confirmation.
Send the latest ETag as ifMatch. Success returns personIds,
affectedPersonIds and the new etag; conflicts return TREE_VERSION_CONFLICT
without applying changes. Refresh context and review a new proposal after a
conflict rather than repeating a mutation blindly.

The site chat has its own explicit tool registry: no tree discovery, no tree
switching, and its existing preview plus Apply button remain the confirmation
mechanism. REST OAuth endpoints and consent types are documented in OpenAPI.

Both registries reuse TreeReadTools and TreeMergeTool. ChatAgentHooks enforce the
selected tree and record context reads for the site agent; the MCP registry adds
TreeReadTools::findTrees and exposes direct application through the merge tool's separate
MCP method. McpToolExecutor only translates application exceptions into MCP tool
errors. Tree search lives in FamilyTreeRepository, without a forwarding service.

OAuthConsent implements the one-time, session-bound consent workflow;
OAuthClientRegistration implements DCR and safe CIMD document retrieval. These
application operations are not provided by the OAuth bundle. OAuthConnections
delegates token validation and issuing to League's TokenController and adds a
short transaction, serialized refresh rotation and per-user/client revocation.
Authentication uses Symfony's access_token firewall and League's ResourceServer;
FamilyTreeVoter continues to authorize each tree. OAuthClient extends the bundle's
supported client model with normal ORM attributes; no metadata event listener is
needed. REST and MCP exception listeners use the matched firewall configuration.

### Chat profiles and the merge tool

The AI panel is a reusable Symfony AI conversation. Its backend-selected profile
chooses a ready agent from `ai.yaml`, with that agent's tools and prompt. The
common chat module and `ChatPanel` handle messages, processing, Stop, events,
voice and token usage without tree or merge types. `TreeMergeTool` and
`TreeChatPanel` connect the tree profile to its preview and confirmation UI.
A test runs a second configured profile without tree tools or the tree prompt.
`ChatService` implements the application `ChatInterface`. The configured agent owns
its inference loop; `ExecutionInterface` supplies progress and cancellation.
`ChatAgentHooks` binds native metadata, checks authorization and meters responses.
`ChatEventPublisherInterface` is the application notification contract.
`MercureChatEventPublisher` implements it through the standard Mercure hub and
rechecks `ChatVoter` before delivery. `ChatController` grants subscriptions using
Mercure `Authorization`; Symfony Security authenticates their JWT separately.
There is no custom agent or inference loop.

`TreeMergeService` prepares and applies a change set independently of chat, AI
and MCP. Its validator accepts explicit evidence sources. The native `merge`
tool adapts this service to the active conversation and publishes validated
proposals. The UI shows the latest proposal when the turn finishes, so intermediate
tool calls cannot expose a partial preview for confirmation. Apply and discard
share the preview's action block. Factual answers are escaped plain text;
JSON-looking replies have no special meaning. Ctrl+Enter sends; Enter inserts a
line break. Unsent input stays only in the mounted component.

`GET /api/v1/trees/{treeId}/chat` returns `{conversation, tokenBudget}`; POST
creates or returns the current conversation. Common operations use
`/api/v1/chats/{chatId}`. POST `/messages` saves and executes a turn in one request;
there is no separate process endpoint. The composer clears on send and restores
text only if delivery remains unconfirmed after a failure. A completed SSE snapshot
releases the local turn without waiting for HTTP; late responses preserve new input.
Each authenticated login has one current conversation
per context and at most one pending merge proposal for a tree. Another login
cannot read or replace its history or proposal. There is no SQL chat table:
`tree_merge_conversation` and its Doctrine coordinator were removed.

Persistence uses the official `symfony/ai-cache-message-store`. Message history
and pending tool state share one `MessageBag` cache item on Docker tmpfs. Its
one-hour TTL (`app.chat_ttl`) advances on writes. Expiry, eviction, reset and
container restart remove history and proposal together, without an HTTP cleanup
or background job. A separate private cache index contains only the current
conversation UUID for a login/context pair. It stores no history or proposal.

Symfony Lock coordinates short state changes. The `GuardedMessageStore`
decorator delegates storage to the official bridge and includes native agent
saves in the same lock and attempt/version checks. Locks are released while
waiting for the model, so Stop and concurrent actions remain available.
`ChatVoter` checks the owning active login; `FamilyTreeVoter` enforces ownership
for tree tools and tree endpoints. Obsolete responses cannot restore state after
Stop, logout, reset, proposal edits, discard or a newer message.

`conversation.status` describes processing; `tools.merge.state` describes the
pending proposal. A successful `merge` replaces that single proposal. Ordinary
answers, Stop and provider failures preserve it. Proposal actions use
`/api/v1/trees/{treeId}/merge-proposals/{proposalId}`: DELETE discards only the
proposal; the chat and unsent input remain. Its button disappears immediately.
Reset deletes the whole conversation. Both actions reach other tabs by Mercure.

Apply requires a separate button, the proposal version and tree ETag. It consumes
the proposal before the structural tree transaction. People, links, automatic
events, ETag and layout invalidation change atomically. The result is returned
once in the HTTP response and a transient tool event, then discarded. Neither
an applied proposal nor an application receipt is retained in chat state.
Retries of the consumed proposal return 404 and cannot mutate the tree again.
An ambiguous response triggers a tree/state refresh; a failed local refresh can
reuse the transient result in the current tab. A tree version conflict refreshes
the preview and requires another explicit confirmation.

While the agent runs, the chat shows only its latest completed tool as
`Completed: <tool name>` (`Выполнено: <tool name>` in Russian) on one line with
elapsed time, falling back to Thinking before the first action.
Symfony AI tool-success events record completions in order, including repeated
calls. User, assistant and system messages show their local 24-hour time below
the content on the right; the tooltip includes the full localized date and time.
Assistant replies use
[`react-markdown`](https://github.com/remarkjs/react-markdown) with
[`remark-gfm`](https://github.com/remarkjs/remark-gfm) for Markdown, including
nested lists, tables and code. Raw HTML is escaped and unsafe URL protocols are
filtered by the renderer's defaults. User and system messages remain plain text.
The fixed-size chat button sits beside the top toolbar with a localized native
tooltip; the toolbar reserves its width whether the drawer is open or closed.
Search and view/export controls share one toolbar with separate groups. Camera
position and zoom controls use the same grouped panel structure below the graph.
Merge confirmation takes only the width of its controls and wraps when needed.

Without an explicit narrative reference, `get_tree_context` uses the tree's
current primary reference person, including the first person of a canonical
reference pair. An explicit person takes priority. Empty trees return no people.
The resolved reference and its bounded immediate family include structured dates;
this context is not a complete listing of every person in the tree.

The tree profile permits eight context reads, sixteen tool calls and ten new
people per proposal. Existing endpoints must have been observed through a current
context read. User messages total at most 10,000 characters and 200 visible
messages per conversation. Evidence quotes must be exact user-message substrings
of at most 300 characters. The model receives no application credentials or
user/session identity. Graph validation uses three bounded queries; the
independent change-set executor constructs the prospective graph once under the
tree lock and loads automatic child-birth events only for affected children.

Private Mercure events carry conversation snapshots, progress, token usage and
transient tool results. The hub retains no history. Opening or reconnecting
fetches one chat snapshot and refreshes the tree; there is no periodic chat GET.
A Secure, HttpOnly, SameSite=Strict cookie lasts at most one hour and never beyond
the login session's expiry. It renews one minute before expiry. In the session's
final minute it waits for expiry instead of repeatedly requesting the same token.
Caddy forwards that cookie as a Bearer credential on its loopback-only
authorization subrequest. The dedicated `chat_subscription` firewall uses the
standard Symfony `access_token` authenticator and `ChatSubscriptionTokenHandler`.
The handler validates signature, issuer, audience, expiry and the active login;
the existing API failure handler returns machine-readable authentication errors.
Application session credentials and subscription JWTs are accepted only by their
respective firewalls. Publications also recheck revocation. Logout clears the subscriber
cookie. Keep `MERCURE_URL`, `MERCURE_PUBLIC_URL`, `MERCURE_JWT_ISSUER` and both Caddy
configurations consistent.

See [Symfony access-token authentication](https://symfony.com/doc/current/security/access_token.html)
and [Caddy forward authentication](https://caddyserver.com/docs/caddyfile/directives/forward_auth).

### CLIProxyAPI in Docker Compose

CLIProxyAPI v8.0.56 provides an OpenAI-compatible API backed by ChatGPT/Codex OAuth.
Its model API is available only on the Compose network. Port 1455 is bound to
127.0.0.1 for the temporary browser OAuth callback. Management and request-body
logging are disabled.

```sh
docker compose up -d cli-proxy-api
docker compose exec cli-proxy-api /CLIProxyAPI/CLIProxyAPI -config /run/cli-proxy-api/config.yaml -codex-login -no-browser
```

Complete sign-in and consent in the browser. OAuth credentials are stored and
renewed in `cli_proxy_auth`, which is not mounted into backend. The ignored root
`.env` holds `CLI_PROXY_API_KEY`; backend sends it only in the HTTP Authorization
header. The startup script writes a private proxy configuration on tmpfs.
The provider URL, model and tool capabilities stay in `ai.yaml`; the selected
model is `gpt-5.6-luna` at `http://cli-proxy-api:8317`.

The `chat_tokens` Symfony limiter counts every model round, including input
and completion usage. The initial limit is 10,000,000 tokens per user per day,
configured by `app.chat_token_limit` in `backend/config/services.yaml`.
Already spent tokens are counted even when
a response crosses the remaining quota. New rounds then stop with a localized
notice. Attempts are limited to twenty per user per hour; inference has a
270-second timeout. Tests use prepared HTTP responses and require no live model.

See the [Symfony AI Agent documentation](https://symfony.com/doc/current/ai/components/agent.html)
and [CLIProxyAPI source](https://github.com/router-for-me/CLIProxyAPI).

### Voice

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
The public authorization URL returns 404. Both voice APIs share ten attempts per
user per hour. Credentials never appear in URLs or application logs.

Recordings are limited to 90 seconds, inbound frames to 64 KiB and send queues to
256 KiB. Speaches VAD uses a 700 ms pause; the gateway orders completed phrases
and handles its 0.8.3 configuration warning explicitly. Internal silence finishes
active speech on Stop and is excluded from duration. Finalization has a 180-second
timeout. `LOOPBACK_HOST_URL` is required for Speaches' internal transcription call.

The original file endpoint remains compatible: it validates actual MIME/codec,
10 MiB and 90 seconds, normalizes to 16 kHz WAV with FFmpeg, and deletes uploads.
The browser uses the streaming path without automatic fallback. No audio, media
objects or database recording sessions are persisted.

Gateway verification runs independently inside Docker:

```sh
docker compose run --rm --no-deps voice-gateway npm run quality
docker compose run --rm --no-deps voice-gateway npm run test:coverage
docker compose run --rm --no-deps voice-gateway npm run build
```

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

The configured Symfony AI agent invokes native PHP tools directly.

Backend/API tests cover ownership, session isolation, one-draft persistence,
Stop/provider failures with ready proposals, evidence limits, stale attempts,
ETag conflicts, atomic application and token accounting through the configured
agent. Family proposal fixtures verify domain integration, not live model
extraction quality. Browser scenarios are recorded with the completed change.

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

The local fixture creates the account `demo` with password supplied locally.

To add the demo account and tree to an existing local database without
removing current data, run:

```bash
docker compose exec backend php bin/console doctrine:fixtures:load --append --no-interaction
```

Do not run `doctrine:fixtures:load` without `--append` against a database that
contains data you need to keep: Doctrine purges it by default.

Previous chat validation (before external MCP, 2026-11-06): 220 backend tests and 385 frontend tests pass. Line
coverage is 93.00% backend and 90.18% frontend. Every production file with
measurable behavior exceeds 75%; EventPlaceRepository and MediaVariantRepository
contain only Doctrine constructor wiring (one uncovered line each). Backend and
frontend quality checks, OpenAPI generation, the production build, Composer
validation, dev/prod container checks, schema validation and all 23 migrations on
a fresh disposable PostgreSQL database pass. The backend suite retains nine
existing PHPUnit mock-expectation notices in map and GEDCOM tests; Vite reports
its existing bundle-size advisory.

PhpStorm WARNING inspections covered all 14 affected PHP files. No findings remain.

Manual HTTPS/Chrome validation (2026-11-06) used the synthetic tree
"Symfony AI — общий чат 05.11". A normal question read Olga's stored birth year.
The live CLIProxyAPI/Symfony AI cycle delivered progress over the authenticated
Mercure stream, prepared a birth-year correction and applied it only after button
confirmation. Apply removed the proposal while preserving history. A reverse
proposal restored the stored year to 1988. Reset removed the chat, and returning
to the tree showed the same three fictional people. Direct access to the
subscription authorization endpoint without a Bearer credential returned the
machine-readable UNAUTHORIZED error. Other trees were preserved.

The chat UI regression check prepared one proposal for all three fictional people.
The composer stayed empty after sending; the completed preview exposed Apply and
Discard together. Discard preserved both history and an unsent correction. A later
proposal was applied by button. Editing the same tree in a second tab caused the
expected ETag conflict and required another confirmation. The reverse proposal
restored all original years, verified after reload; no proposal remained.
Component tests also cover partial tool snapshots, SSE completion before HTTP,
late HTTP success/failure, and preserving the next unsent message.

API tests cover signature, issuer, audience, expiry, not-before and session
validation through Symfony's access_token firewall; application and subscription
credentials are not interchangeable. Revoked logins cannot reconnect or receive
late private publications. A reset publishes its removal notification even after
the MessageStore item has been deleted. Existing tests also cover session
isolation, ETag conflicts, TTL expiry, stale agent saves, Stop/provider failures,
evidence/read limits, token accounting and another configured agent profile.

External MCP validation (2026-11-06): 235 backend tests and 391 frontend tests
pass, with line coverage of 93.05% and 90.20%. Backend/frontend quality checks,
OpenAPI type verification, the production build, Composer validation and schema
validation pass. All 25 migrations were replayed and schema-validated in a fresh
disposable PostgreSQL database. The existing nine PHPUnit notices and Vite
bundle-size advisory remain.

PhpStorm WARNING inspections are clean for application and test PHP files.
The generated Symfony config/reference.php retains the IDE's unrecognized
PHPDoc list-type warnings; migration SQL retains its missing-IDE-data-source
warnings. Generated types and executable migrations were verified by PHPStan,
Symfony container checks and a fresh PostgreSQL migration replay instead of
editing generated framework documentation or suppressing SQL inspections.

Manual HTTPS validation used MCP Inspector 2.14.0 and the separate synthetic tree
"Романовы — внешний MCP 06.11". Discovery, DCR, sign-in, consent approval/denial,
the five-tool registry and tree/person reads were checked. After reconnecting
with the existing OAuth connection, merge added Ivan as Anna Pavlovna's husband
and returned person UUIDs plus a new ETag. The site showed two people and the
marriage. Editing Ivan's patronymic to Petrovich in another tab made the earlier
ETag stale: Inspector returned TREE_VERSION_CONFLICT (412), and refreshing the
site retained exactly two people and the saved change.

PHP formatting validation (2026-11-06): maintained application and test code now
follows the 120-character rule in AGENTS.md. Single-line throws required by the
formatter and indivisible prompt, fixture, URL, regex and SQL literals retain
their allowed exceptions; generated framework files are not hand-formatted.
Comparison of 310 PHP syntax trees found no behavior changes from formatting,
after folding equivalent string concatenations and normalizing SQL line endings.
MCP transport errors now use the SDK's named JSON-RPC constants.

The complete 235-test backend and 391-test frontend suites, coverage (93.05% and
90.20%), quality checks, OpenAPI checks and production build pass. All 25
migrations were verified again on a fresh disposable database. The OAuth metadata
URL was also opened successfully in the real HTTPS browser after formatting.
PhpStorm inspected 73 changed PHP files; its only three warnings treat TreeLayout's
entity constructor as service injection. Symfony debug:container explicitly marks
TreeLayout as container.excluded because it is a Doctrine entity, with no service
usages; SaveTreeLayoutService constructs the entity directly. These warnings are
plugin false positives and require no application change.

OAuth consent fix validation (2026-11-06): the complete 236-test backend and
399-test frontend suites pass, with line coverage of 93.05% and 90.21%.
Quality checks, OpenAPI generation checks, production build, schema validation,
and all 25 migrations on a fresh disposable database pass. PhpStorm WARNING
inspections are clean for all three changed PHP files. The existing nine PHPUnit
notices and Vite bundle-size advisory remain.

Manual local HTTPS checks covered a real Codex CLI 0.132 login without `resource`,
site sign-in preserving the authorization URL, approval in an empty test account,
successful CLI token exchange, denial returning `access_denied`, and opening the
page without authorization parameters. The consent layout was checked at the
normal viewport and at 375 px: headings wrap between words and callback URLs stay
inside the card. Temporary CLI credentials and OAuth clients were removed after
testing. API regression tests also check refresh without `resource`, the exact MCP
audience, rejected foreign resources, and denial of ordinary REST access.

AI chat moderation validation (2026-11-06): all 263 backend tests and 405 frontend
tests pass; line coverage is 93.22% and 90.27%. Every measured production file
exceeds 75%. Backend/frontend quality, OpenAPI generation checks, Composer
validation, schema validation and the production build pass. All 26 migrations
were replayed on a disposable database; the new migration was rolled back,
reapplied and schema-validated. The maintenance worker starts and restarts, and
Scheduler tests cover processing the last missed run without replaying it twice.
The existing nine PHPUnit notices and Vite bundle-size advisory remain.

PhpStorm WARNING inspections covered all 33 affected PHP files. Fixable findings
were corrected and re-inspected. Remaining IDE findings are documented exceptions:
35 unrecognized `list` types in the generated Symfony reference, 30 SQL assistance
warnings without an IDE data source, and three ORM false positives. Both new
entities are explicitly `container.excluded` with no service usages according to
`debug:container`; their User constructors express persisted associations. The
private operation key is read through ORM QueryBuilder/DQL and its unique index,
not a PHP getter. No unused getter or inspection suppression was added.

Manual local HTTPS validation used an isolated account and empty tree. A family
story quoting instructions passed the real moderation and main models. Three
deliberate security violations produced numbered Russian warnings, a ban, the
quota penalty and disabled text/microphone controls while preserving history.
The temporary deadline was shortened only in the QA fixture to check automatic
expiry without reloading. An unavailable moderation model showed the localized
outage notice without another warning or ban, then recovered automatically.
Temporary model configuration and the isolated account/tree were removed.
Automated checks additionally cover concurrent retries, interrupted history
finalization, both models' token usage, rejected evidence, temporary/permanent
cycles, audio cleanup, Mercure updates, focus/polling, prepared proposal application
and external MCP access during a site-chat ban.

Chat context and connection stability validation (2026-11-06): all 263 backend
tests and 414 frontend tests pass, with line coverage of 93.22% and 90.37%.
Every measured production file meets 75% coverage. Backend/frontend quality,
OpenAPI type checks, the production build and all 26 migrations on a fresh
disposable database pass. PhpStorm WARNING inspections are clean for all six
affected PHP files. Existing PHPUnit notices and the Vite bundle-size advisory
remain.

Manual HTTPS validation used an isolated account and empty tree. The real agent
acknowledged a blocked message without reconstructing its text, quoted an
available message without technical IDs, and prepared a proposal for Anna.
Stopping and restarting Mercure preserved unsent input and restored the stream.
An idle observation spanning 5 minutes 28 seconds preserved text, selection,
history, scroll position and the preview camera across credential renewal, with
no browser console warnings or errors. A short ban seeded outside the tab was
discovered on send; the draft survived and access recovered at the deadline
without reloading or moving the preview camera. The account, empty tree and audit
fixture were removed. The local proxy was also restarted after the browser
presented an expired TLS-session certificate despite the server holding a renewed
certificate; HTTPS was verified without bypassing certificate validation.

Mercure lifetime validation (2026-11-07): 269 backend and 419 frontend tests pass,
with line coverage of 93.22% and 90.37%; every measured production file meets 75%.
Backend/frontend quality, OpenAPI generation, the production build and all 26
migrations on a fresh database pass. The frontend coverage suite was rerun with
two workers after a force-simulation test timed out under parallel load.
PhpStorm WARNING inspections are clean for the controller and API test. Its
existing warning about injecting `User` in the `AuthSession` entity constructor
is a false positive: `debug:container` confirms that Doctrine excludes the entity
from services, and the constructor sets its owning user rather than injecting one.

Manual HTTPS validation covered a real chat response, subscription renewal with
a test login shortened to 125 seconds, and rejection of another message after
the login expired. History and the unsent draft survived renewal; the expired
subscription stopped, and the rejected send preserved the draft. The isolated
account and empty tree were removed. The proxy was restarted to discard a stale
TLS-session certificate; certificate validation remained enabled.

Chat presentation validation (2026-11-07): 269 backend and 422 frontend tests
pass, with line coverage of 93.22% and 90.37%; every measured production file
meets 75%. Backend/frontend quality, strict TypeScript, OpenAPI generation,
Composer validation, production container checks and the production build pass.
All 26 migrations were replayed and schema-validated on a disposable database.
The frontend coverage run uses two workers after the existing force-simulation
test exceeded five seconds under parallel load. The existing nine PHPUnit
notices and Vite bundle-size advisory remain. No PHP files changed.

Manual HTTPS validation in Chrome covered the restored conversation's nested
Markdown lists; a live AI reply with a heading, bold text and a table; the lone
thinking line; collapsing/reopening with an unsent draft; aligned toolbar and
chat-button geometry in both drawer states; and compact merge controls. Renaming
an isolated QA tree in another tab produced a tree-version conflict; the UI kept
the preview and applied it only after a second explicit confirmation, creating
both people. The QA tree and disposable migration database were removed.
`npm audit --omit=dev` reports existing advisories in the unchanged `maplibre-gl`
and `zod` dependencies; updating those packages is outside this presentation change.

Chat feedback and grouped controls validation (2026-11-07): 270 backend and 423
frontend tests pass. Line coverage is 93.22% and 90.37%, and every measured
production file meets 75%. Backend/frontend quality, OpenAPI generation,
production build, Composer validation, schema validation and dev/prod container
checks pass. All 26 migrations were replayed on a disposable database.
PhpStorm WARNING inspections are clean for all three affected PHP files. Its
nested-ternary finding in the changed chat service was replaced with a match.
The existing PHPUnit notices and Vite bundle-size advisory remain.

Manual Chrome HTTPS validation covered restored user/assistant timestamps and
their placement below the text; live transitions from Thinking to tree-context
completion and then name-search completion, with exactly one action visible;
Stop producing a timestamped notice; and restoring history after a refresh.
Search results, the empty-result state and Escape were checked in the shared
toolbar. Search with the chat open, zoom/reset controls and switching to the
blood-network view were also exercised. Both toolbars use the same panel/group
structure. The isolated QA tree and migration-check database were removed.

Default tree-context reference validation (2026-11-07): 271 backend and 423
frontend tests pass, with line coverage of 93.24% and 90.37%. Backend/frontend
quality, OpenAPI generation, production build, Composer validation, schema and
production container checks pass. All 26 migrations were replayed and validated
on a disposable database. PhpStorm WARNING inspections are clean for all three
affected PHP files. Existing PHPUnit notices and the Vite bundle-size advisory
remain.

Manual Chrome HTTPS validation covered an empty tree, context with structured
birth dates around the default reference, and changing the reference through
the person menu in the same conversation. Recorded tool calls omitted
`referencePersonId` and resolved the current reference on each call. Automated
tests additionally cover explicit overrides, null arguments, canonical reference
pairs and bounded query counts. The isolated QA tree and migration-check
database were removed.

Person-name prompt validation (2026-11-07): the full 271 backend and 423 frontend
tests pass, with line coverage of 93.24% and 90.37%. Backend/frontend quality,
OpenAPI checks, the production build, Composer validation, dev/prod container
checks, schema validation and all 26 migrations on a fresh database pass.
Existing PHPUnit notices and the Vite bundle-size advisory remain. No PHP files
changed.

Manual Chrome HTTPS validation used the computer-use skill and an isolated QA
tree. A Russian question that did not supply the names produced the stored
`Anna Hansdotter` and `Élodie Åström` unchanged, including accents, and correctly
reported unknown patronymics. The QA tree and disposable migration database
were removed.

Editor panel validation (2026-11-08): the chat reserves its actual width in the
workspace while person editing and force settings stay available. React Flow
observes the remaining canvas size for camera tools; merge preview and result
framing use those same bounds. Applied merges add no card outlines. Chat history
uses one scroll container with padding inside the scrollbar.

The full 278 backend, 437 frontend and 27 voice-gateway tests pass. Line coverage
is 93.32%, 90.96% and 98.43%, respectively. Frontend files meet 75% line coverage;
the two existing backend constructor-only repository exceptions remain as
documented above. Backend/frontend/gateway quality, OpenAPI generation, strict
TypeScript, production builds, Composer validation, schema and production
container checks pass. All 26 migrations were replayed and schema-validated on
a disposable database, which was removed. No PHP files changed. The existing
nine PHPUnit notices and Vite bundle-size advisory remain.

Manual Chrome HTTPS checks covered centering the empty-tree action with chat
open, live preview and application of a two-person merge without outlines,
opening and closing person details alongside chat, reset and fit with both
panels, long chat-history scrolling, force settings alongside chat, refreshing
the page, and a two-tab edit conflict followed by reloading the tree. The
fictional tree `QA панели чата 2026-11-08` is retained for inspection.
