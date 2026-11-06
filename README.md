# MyGenoTree

Local HTTPS MVP for creating and editing private genealogy trees.

## AI chat moderation

The site agent checks each submitted text, including reviewed voice transcripts,
with the separate Symfony AI `chat_moderation` agent. It uses the existing
CLIProxyAPI platform, `gpt-5.6-luna`, native Structured Output and Validator.
The checking agent has no tools or message store. Ordinary off-topic questions,
quoted instructions and family narratives are allowed.

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

Chat history and prepared proposals remain available during a ban. Sending and
voice transcription return `403 CHAT_BANNED`; manual tree editing, proposal
application and external MCP remain available. A failed or invalid checking-model
response blocks inference without sanctions and returns `503 CHAT_UNAVAILABLE`.
Shared cache records this outage for 30 seconds. The panel receives access state
through snapshots and Mercure and refreshes on focus, expiry and every 15 seconds
while open.

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
A five-minute Secure, HttpOnly, SameSite=Strict cookie is renewed before expiry.
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
configured by `CHAT_TOKEN_LIMIT`. Already spent tokens are counted even when
a response crosses the remaining quota. New rounds then stop with a localized
notice. Attempts are limited to twenty per user per hour; inference has a
270-second timeout. Tests use prepared HTTP responses and require no live model.

See the [Symfony AI Agent documentation](https://symfony.com/doc/current/ai/components/agent.html)
and [CLIProxyAPI source](https://github.com/router-for-me/CLIProxyAPI).

### Voice

Voice recording fills the composer for review before Send. The backend checks
actual MIME, codec, a 10 MiB limit and at most 90 seconds of decoded audio, then
normalizes it with FFmpeg to mono PCM WAV at 16 kHz. Uploads are deleted before
returning. The CPU Speaches/Whisper service is optional:

```sh
docker compose --profile ai up -d whisper
docker compose exec -T backend curl --fail -X POST http://whisper:8000/v1/models/Systran/faster-whisper-small
```

`WHISPER_URL` and `WHISPER_MODEL` configure recognition; its model volume is
`whisper_models`. Each user has ten recognition attempts per hour.

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
