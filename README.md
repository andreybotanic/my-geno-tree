# MyGenoTree

Local HTTPS MVP for creating and editing private genealogy trees.

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
tool adapts this service to the active conversation and publishes the validated
preview before the next model request. Factual answers are escaped plain text;
JSON-looking replies have no special meaning. Ctrl+Enter sends; Enter inserts a
line break. Unsent input stays only in the mounted component.

`GET /api/v1/trees/{treeId}/chat` returns `{conversation, tokenBudget}`; POST
creates or returns the current conversation. Common operations use
`/api/v1/chats/{chatId}`. POST `/messages` saves and executes a turn in one request;
there is no separate process endpoint. Each authenticated login has one current conversation
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

Validation (2026-11-06): 220 backend tests and 382 frontend tests pass. Line
coverage is 93.00% backend and 90.12% frontend. Every production file with
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

API tests cover signature, issuer, audience, expiry, not-before and session
validation through Symfony's access_token firewall; application and subscription
credentials are not interchangeable. Revoked logins cannot reconnect or receive
late private publications. A reset publishes its removal notification even after
the MessageStore item has been deleted. Existing tests also cover session
isolation, ETag conflicts, TTL expiry, stale agent saves, Stop/provider failures,
evidence/read limits, token accounting and another configured agent profile.