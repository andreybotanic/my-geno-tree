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
tool arguments, regardless of interface language. Existing names change only on an
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

Chat history remains available during a ban. The main
agent receives a safe notice in place of blocked text, so it can acknowledge
the warning when discussing history. The original text stays visible to the user
and is excluded from inference. Technical message IDs are internal and
must not appear in conversational quotes or summaries. Sending and
voice transcription return `403 CHAT_BANNED`; pending chat decisions are also blocked.
Manual tree editing and external MCP remain available. A failed or invalid checking-model
response blocks inference without sanctions and returns `503 CHAT_UNAVAILABLE`.
Shared cache records this outage for 30 seconds. The panel receives access state
through action responses and Mercure. It performs one scheduled refresh at the
known temporary-ban, outage or exhausted-budget deadline; `tokenBudget.retryAt`
reports when exhausted tokens become available. There is no idle or focus polling.
A failed scheduled refresh offers an explicit retry. Changes from another device
can be learned on the next send attempt.

Mercure credentials renew before expiry using overlapping connections. A planned
handover loads no chat or tree snapshot and preserves confirmations, camera,
scroll position and unsent input. A genuine interruption gets a five-second grace
period before showing the reconnect notice, then a catch-up snapshot on recovery.
Unchanged messages retain their references during state
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

The external read tools include find_trees(query), get_tree_context(treeId,
referencePersonId?), find_people(treeId, query) and get_person_context(treeId,
personId). Tree discovery accepts
a fuzzy title or an exact UUID and returns up to five {id, name} candidates.
It queries only the authenticated owner's trees. Names use the existing
normalization, PostgreSQL trigram matching and Levenshtein distance.

Resolve identities from stored records and ask the user about ambiguous matches.
Each call explicitly names its tree. Both transports expose the single-action
write tools described below. Person creation and deletion require MCP form
elicitation; clients without it can still read and use the other write tools.
The site chat has no tree discovery or switching and displays confirmations
inline. REST OAuth endpoints and consent types are documented in OpenAPI.

Both registries also expose list_people(treeId, personIds?, groups?, cursor?,
limit?, expectedEtag?), list_relationships(treeId, kind, personIds?, cursor?,
limit?, expectedEtag?), list_events(treeId, personIds?, types?, source?, cursor?,
limit?, expectedEtag?) and find_places(query). Person field groups are identity,
life, places and metadata; identity and life are selected by default. Event
descriptions are returned in full. No tool returns photographs or media URLs.
Lists return items, total, returned, nextCursor and etag. Follow nextCursor until
null to cover the complete selection. Pages default to 100 rows, permit at most
200 rows and stay within 128 KiB; large records reduce the page length. Signed
cursors bind the selection and tree version. Reuse expectedEtag across different
lists for a consistent analysis; a changed tree returns TREE_VERSION_CONFLICT.
Place lookup reports unavailable without fabricating coordinates.

List adapters resolve the tree through `TreeContextService`, whose access decision
is delegated to `FamilyTreeVoter`. `TreeReadService` receives the resolved tree
and only coordinates input validation, signed cursors, version consistency and
response size. Its explicit people, parents, marriages and events methods use
their corresponding entity repositories. Query builders stay inside repositories;
the registered `GenealogyRecordHydrator` assembles read records without managed
entities or media. Tool metadata describes arguments, paging and mutation
semantics; the system prompt contains general conversational and reasoning rules.

Both registries reuse TreeReadTools and TreeWriteTools. ChatConfirmationHooks enforces the
selected tree; McpToolExecutor applies the same confirmation policy to external
clients and translates application errors into machine-readable MCP tool results.
Tree search lives in FamilyTreeRepository.

OAuthConsent implements the one-time, session-bound consent workflow;
OAuthClientRegistration implements DCR and safe CIMD document retrieval. These
application operations are not provided by the OAuth bundle. OAuthConnections
delegates token validation and issuing to League's TokenController and adds a
short transaction, serialized refresh rotation and per-user/client revocation.
Authentication uses Symfony's access_token firewall and League's ResourceServer;
FamilyTreeVoter continues to authorize each tree. OAuthClient extends the bundle's
supported client model with normal ORM attributes; no metadata event listener is
needed. REST and MCP exception listeners use the matched firewall configuration.

### Manual event batches and text-only places

ManualEventService::createMany creates independent events for a list of people
in one transaction. It validates every participant and date before persisting
events, rejects repeated or foreign participants and updates the tree revision
once. Single-person REST creation delegates to this same operation. The core
operation can also support a future participant-selection UI;
no shared-event entity or new UI is introduced.

Person and event place inputs accept coordinates=null alongside a nonempty name.
Existing geocoding responses continue to return resolved coordinates. Text-only
places preserve historical or unresolved names without inventing a map position.

### Single tree actions and confirmations

TreeWriteTools exposes create_person, update_person, delete_person,
add_parent_link, remove_parent_link, create_marriage_period,
update_marriage_period, remove_marriage_period, create_event, update_event,
delete_event, set_event_place and reorder_events to Symfony AI and MCP.
TreeActionService delegates each action to existing application services.
Each successful action commits independently, synchronizing automatic events,
reference changes, ETag and layout invalidation within its own transaction.
A later rejection does not undo prior actions. Physical media cleanup follows commit.

Read the initial ETag once. Every successful mutation returns its own etag and
real resource IDs directly in the tool result; pass that etag as the next ifMatch.
There is no intermediate snapshot/read requirement after an agent's own writes.
All normal ownership, connectivity, date and deletion rules still apply.
A nonempty tree requires create_person to attach the new person through an
initial parent, child or spouse connection. Parent connections create both parent
links atomically. If only one parent is specified, the operation creates an unnamed
second parent in the same transaction. Supply otherParentId to reuse an existing
second parent. The result identifies the requested person separately and returns
all created people with their IDs; update the unnamed person's fields when more
facts become available. Read again when facts are missing,
a version conflict occurs or a mutation response is lost; never replay blindly.

Creating and deleting a person require a separate confirmation for each call.
The website stores the immutable call and its decision with native MessageBag
history in the existing one-hour tmpfs cache. ToolCallRequested intercepts the
call, ToolCallsExecuted finishes the native round, and the chat enters
awaiting_confirmation without holding a database transaction or HTTP request.
The panel displays a short permission request immediately above the composer,
with Allow and Reject. It disappears after the decision and is not added to chat
messages. A button below the input opens an icon menu with Ask for permission and
Approve for me; new chats always start in the first mode. The circular Stop
button also cancels pending actions. Typing a clarification restores Send.

POST /api/v1/chats/{chatId}/confirmations/{confirmationId}/decision accepts
the conversation version, decision and optional autoApprovePersonActions.
PATCH /api/v1/chats/{chatId}/permissions changes that mode between executions.
Approval executes exactly the stored arguments and feeds the real tool result
back to the configured Symfony AI agent, continuing the same user request.
An external tree change invalidates the saved ifMatch. Repeated decisions
cannot repeat the mutation. Stop or a new message cancels a pending call.
Pending requests survive reloads and synchronize through private Mercure events.
Decisions remain in server-side conversation metadata without visual history cards.

MCP clients must support form elicitation for person creation/deletion.
Legacy clients receive elicitation/create; modern clients receive input_required
and return their answer with the original call. Signed request state binds the
call arguments and OAuth identity, expires after 120 seconds and is consumed once.
Decline, cancellation, timeout, replay or unsupported elicitation cannot apply
the action. Website automatic permissions never bypass MCP elicitation.

ChatService and ChatPanel remain reusable across profiles. The configured
Symfony AI agent owns inference, progress, token accounting and cancellation.
ChatToolExecutor permits multiple read calls but rejects any round containing
a mutation alongside another call before side effects. Mutations therefore
consume the actual result and ETag of their predecessor.
The client refreshes committed data without switching representation or framing
new people, retaining camera, selection and composer state. Reconnect reloads
the snapshot to recover missed notifications.

GET /api/v1/trees/{treeId}/chat returns the current session-bound conversation;
POST opens it. Common operations use /api/v1/chats/{chatId}, including messages,
stop, reset and events. There is no SQL chat table. Expiry, reset and container
restart remove chat history, confirmations and per-chat permissions together.
Legacy cached tool proposals are discarded while visible messages are preserved.
The original spec directory is unchanged.

The tree agent uses the official `symfony/ai-open-responses-platform` bridge
and streams `/v1/responses` through CLIProxyAPI. Symfony AI `CommentaryComplete`
events update the current action with a whole commentary block before tools execute.
The panel shows `Thinking...` (`Думаю...`) until commentary arrives, with elapsed
time. Tool names and reasoning are excluded from progress. Final answers remain
complete messages. Partial commentary deltas stay internal. Commentary snapshots
are ordered by `attemptId` and `progressSequence`; shorter updates and
cleared commentary therefore survive delayed or repeated SSE packets. Long
commentary wraps within the panel. The latest nonempty action stays visible
between model rounds until new commentary replaces it; empty commentary blocks
do not restore the Thinking fallback. A new user turn, Stop and completion clear it.

Per-response output limits in `backend/config/packages/ai.yaml` are 16384 tokens
for the tree agent and 1024 for moderation. Streaming usage is debited as reported,
including usage received before a later failure. Truncated answers are rejected;
provider rate limits, context/output limits, tool-round exhaustion and refusals
have distinct localized notices. Search-tool schemas expose the existing 2–255
character query bounds; application normalization and validation remain in place.

User, assistant and system messages show their local 24-hour time below
the content on the right; the tooltip includes the full localized date and time.
Hovering over an assistant message reveals response duration and token usage to
the left of its timestamp. Metrics are saved in message metadata for each new
completed response, including moderation and tool rounds, and survive reloads
and later turns. Older messages without metrics keep their timestamp alone.
The chat uses 12 px fades only at scroll edges with hidden content, hides scrollbar
arrow buttons, and offers Delete chat to remove the conversation.
Assistant replies use
[`react-markdown`](https://github.com/remarkjs/react-markdown) with
[`remark-gfm`](https://github.com/remarkjs/remark-gfm) for Markdown, including
nested lists, tables and code. Raw HTML is escaped and unsafe URL protocols are
filtered by the renderer's defaults. User and system messages remain plain text.
The fixed-size chat button sits beside the top toolbar with a localized native
tooltip; the toolbar reserves its width whether the drawer is open or closed.
Search and view/export controls share one toolbar with separate groups. Camera
position and zoom controls use the same grouped panel structure below the graph.
Inline confirmation controls wrap within the chat panel when needed.

Without an explicit narrative reference, `get_tree_context` uses the tree's
current primary reference person, including the first person of a canonical
reference pair. An explicit person takes priority. Empty trees return no people.
The resolved reference and its bounded immediate family include structured dates;
this context is not a complete listing of every person in the tree.

Each creation tool adds the requested person and any required unnamed parent.
Reads and tool calls have no separate count
limit; cancellation, model timeouts and the token budget remain active. Tool
results supply IDs and ETags for subsequent actions. User messages total at most
10,000 characters and 200 visible messages per conversation. The model receives
no application credentials or user/session identity. Graph validation uses
bounded repository queries and executes under the tree lock.

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

Backend/API tests cover ownership, session isolation, persisted confirmations,
Stop/provider failures, stale attempts, ETag chains and conflicts, atomic actions,
MCP elicitation and token accounting through the configured agent. Fixtures verify
domain integration; browser scenarios exercise the live model separately.

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

## Compact permissions and atomic parents (2026-11-08)

Permission requests replace the composer with the action text, Allow, Reject,
Clarify and the circular Stop button. A decision or Stop restores the composer;
resolved requests are absent from the message log. Clarify opens the input without
deciding the action; returning to approval preserves the draft, and sending the
clarification cancels the old call. In the input's bottom control row, the permission menu sits on the
left; the token allowance, microphone and Send/Stop buttons sit on the right.
The menu uses icons and short labels: Ask / Auto-approve in English and
Спрашивать / Автоподтверждение in Russian. Tooltips explain each mode. While
recording, the elapsed-time indicator replaces the permission menu in the same
row. The normal circular Stop button handles
both inference and an outstanding permission request; entering a clarification
restores Send.

Parent/child creation persists both parent links together. A missing second parent
is created unnamed, with its own returned UUID. When both parents exist, the call
uses otherParentId and creates only the requested person. Failed validation rolls
back all new people and links. The same behavior applies to website and MCP tools
and structural REST creation.

Symfony AI's supported tools.execution_strategy option configures ChatToolExecutor
in ai.yaml; Kernel contains no custom compiler pass. Canonical place changes now
stay within the existing person and marriage application services; the extra
SourcePlaceService has been removed.

Calls saved before the parent contract changed are cancelled during chat-state
migration. Visible messages are preserved. MCP approval state also binds the
contract version, so an older permission cannot authorize the new behavior.

All 286 backend, 442 frontend and 27 gateway tests pass. Line coverage is 93.49%,
90.96% and 98.43%. Both new UI components have 100% line coverage. Backend,
frontend and gateway quality checks, OpenAPI verification, production builds,
Composer validation and dev/prod container checks pass. All 26 migrations were
replayed and schema-validated on a disposable database. PhpStorm WARNING
inspections are clean for all sixteen affected PHP files. The existing constructor-only
repository coverage exceptions, two PHPUnit notices and Vite size advisory remain.

Manual local HTTPS checks used the synthetic tree `Проверка компактных разрешений ИИ`.
English and Russian permission menus and compact requests were checked. A child
with one known parent added exactly two people and both links; a child with two
existing parents added exactly one person. Automatic mode was enabled and then
disabled through the menu. A second-tab rename invalidated pending approval and
required another request; rejection kept all seven people. Resolved requests no
longer appear in the restored message history. The circular Stop button cancelled
a fresh pending request, removed its prompt and left all seven people unchanged.

Composer control-row validation (2026-11-09): all 286 backend, 442 frontend and
27 gateway tests pass, with line coverage of 93.49%, 90.96% and 98.43%.
Quality checks, OpenAPI verification, production builds, Composer validation,
dev/prod container checks and all 26 migrations on a fresh disposable database
pass. No PHP files changed. Manual local HTTPS checks covered both languages,
permission-mode switching, restoration after navigation and the 360 px viewport.
The menu, quota tooltip and controls stay within the input without overlapping.

The replacement behavior was checked against Codex's open-source
[BottomPane](https://github.com/openai/codex/blob/main/codex-rs/tui/src/bottom_pane/mod.rs)
and approval snapshots. The official
[desktop screenshot](https://developers.openai.com/images/codex/app/computer-use-approval-light.webp)
also illustrates action and decision controls, although its request stays in the
message area. This application uses the requested composer replacement.

Composer replacement validation (2026-11-09): all 286 backend, 444 frontend and
27 gateway tests pass; line coverage is 93.49%, 90.97% and 98.43%.
Quality, OpenAPI, production builds, Composer/container validation and all 26
migrations on a clean database pass. No PHP files changed. Manual HTTPS checks
covered Russian/English controls, the 360 px viewport, pending-state restoration
after reload, clarification draft preservation, rejection without tree changes,
and approval followed by the restored composer and an updated tree. The synthetic
tree now contains eight people. Live microphone capture remained at the in-app
browser's connecting stage; component tests cover hiding permissions during
recording, showing the timer and restoring permissions afterward.

## Prior single-action validation (2026-11-08)

The complete suites pass: 282 backend tests, 437 frontend tests and 27 gateway
tests. Line coverage is 93.50%, 90.83% and 98.43%, respectively. The frontend
coverage run uses two workers because the existing 101-person force simulation
can exceed its timeout under parallel load. Measured application behavior meets
75% per-file coverage. Kernel compiler wiring runs before cached-container test
coverage; the two constructor-only repository exceptions remain as documented
below. The native-agent API tests exercise the configured executor and its
single-mutation-round restriction.

Backend/frontend/gateway quality checks, OpenAPI generation verification,
production builds, Composer validation, schema validation and dev/prod container
checks pass. All 26 migrations were applied and schema-validated on a clean
disposable PostgreSQL database. No migration or SQL table was added. PhpStorm
WARNING inspections cover all 30 affected PHP files; no findings remain.
Two existing PHPUnit notices and the existing Vite chunk-size advisory remain.

Automated scenarios include one-read mutation chains, a concurrent write between
steps, a conflict during website and MCP approval, immutable call arguments,
double decisions, rejection, cancellation, expired MCP state, automatic mode,
Stop, new-message cancellation, restored waiting state, lost-response recovery,
session isolation and legacy history migration with and without an old proposal.

Manual local HTTPS checks used the synthetic tree
`Проверка подтверждений ИИ — конфликт`: creation and reload during confirmation,
approval, denial of deletion, automatic mode and its disabling, Stop while
waiting, corrected calls after a domain error, and conflict from a second tab.
Both Russian and English cards were checked. Sequential person/event updates
kept the blood-network representation and its 69% zoom. Applied changes refreshed
the tree without a merge preview. The synthetic four-person tree is retained.

## Historical validation records

These dated records describe their original revisions. Merge previews and batch
tools mentioned below have been superseded by single actions and confirmations.

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

Camera resize validation (2026-11-08): the first person selection centers using
the canvas's measured bounds, even before React Flow processes the sidebar
resize. Canvas resize notifications preserve the current graph center and zoom
throughout the chat animation. The workspace and chat share the same transition
duration and easing, so camera and tree tools follow the moving workspace edge.
Both transitions respect reduced-motion preferences.

All 278 backend, 443 frontend and 27 gateway tests pass, including six new camera
regressions. Frontend line coverage is 91.00%; the new camera hook has 100%
coverage. Quality, OpenAPI, production builds, Composer validation, schema and
production container checks pass. All 26 migrations passed on a fresh disposable
database, which was removed. Existing PHPUnit notices and bundle-size advisories
remain; no PHP files changed.

Manual Chrome HTTPS checks covered the first selection with chat open and closed,
closing and reopening both panels, preserving the center after panning at 120%
zoom, chat/workspace transition settings, and resetting the camera with both
panels open. The selected card and workspace centers matched in both axes.
Entering an invalid 201% zoom kept the previous 120% camera state.

Sidebar first-frame validation (2026-11-08): person focus now starts in a layout
effect before painting. The camera first compensates for the sidebar moving the
canvas origin, then animates from the card's previous screen position to the new
center. Tests check the first frame with animation frames held back, with chat
both open and closed, and verify horizontal/vertical origin compensation.

Manual Chrome HTTPS frame sampling reproduced the old horizontal path
`960 -> 1350 -> 1155` and verified the corrected monotonic `960 -> 1155` path.
With chat open, the path was monotonic from `720` to `915`. Switching to another
person also centered correctly. Temporary frame diagnostics were removed.
All 278 backend, 446 frontend and 27 gateway tests pass. Frontend line coverage
is 91.00%, with 100% line coverage for the camera hook. Quality, OpenAPI,
production builds, Composer/schema/container checks and all 26 migrations on a
fresh disposable database pass. The database was removed. Existing PHPUnit
notices and the Vite advisory remain; no PHP files changed.
