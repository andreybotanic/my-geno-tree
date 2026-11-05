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

### Tree merge chat and change sets

The AI panel is an ordinary Symfony AI conversation. The configured agent reads
records, resolves identities, asks questions and prepares proposals through native
PHP tools. Its final text is displayed verbatim as escaped plain text. Ctrl+Enter
sends; Enter inserts a line break. Closing the panel preserves unsent input while
the panel remains mounted. Nothing is written to browser storage.

Each tree has one `TreeMergeDraft`, enforced by a unique database constraint.
`GET /api/v1/trees/{treeId}/merges` returns `{draft, tokenBudget}`, where `draft`
may be null. POST creates or returns that tree's current draft. Cancellation and
starting another conversation reset the same record, with a new internal
conversation ID. There is no list of historical drafts or correction archive.
The migration retains the newest unfinished draft per tree, or its newest receipt
when no unfinished draft exists, and removes obsolete drafts without changing
people, relationships or events.

Proposal `state` and `chatState` are independent. Only successful `merge` changes
the reviewed proposal and its version. A factual reply, Stop or provider failure
preserves the proposal. Apply is disabled during processing and requires the
separate confirmation button, the current draft/proposal versions and tree ETag.
A tree conflict revalidates the proposal and requires another confirmation.
An applied receipt makes repeated Apply idempotent until another proposal replaces it or the draft is reset.
A failed snapshot refresh can be retried with Reload conversation. The same chat stays usable after Apply, including live updates in other tabs.

Conversation persistence uses the official `symfony/ai-cache-message-store`,
configured in `backend/config/packages/ai.yaml`. Symfony AI owns message history
and the entire tool loop. Its cache uses Docker tmpfs with a one-hour TTL and a
namespace for the authenticated login session. The store's conversation ID is
independent of proposal versions: another login can change the shared proposal
without invalidating this session's history. Another login cannot read that
history, and container restarts discard it. Application code adds request IDs and
localized failure metadata and projects messages for the UI; it implements no
message-store backend or Doctrine persistence hooks.

User messages total at most 10,000 characters per session conversation, with at
most 200 visible messages. The model receives each user message once, labelled
with its evidence ID. Durable evidence quotes are exact substrings of at most
300 characters, checked in both schemas and the backend validator. Recordings are
never attached to a tree or stored in conversation history.

`TreeMergeAgentVoter` rechecks the active login and delegates tree ownership to
`FamilyTreeVoter`. Backend attempt/version guards reject writes and final answers
after Stop, logout, a reset, a reviewed edit or a newer message. The configured
agent uses `get_tree_context`, `find_people`, `get_person_context` and `merge`, with
at most eight context reads, sixteen tool calls and ten new people per proposal.
Existing endpoints must have been observed through a current context read. The
model receives no application credentials or user/session identity.

A successful `merge` saves the validated proposal, then its ToolCallSucceeded
event publishes the preview before the next model request. Invalid tools produce
field diagnostics and no preview. Final conversation updates are published after
commit for both REST and MCP entry points. Only explicit confirmation applies
people, links, automatic events, ETag and layout invalidation atomically.
The change-set executor has no AI dependency. Graph validation uses three bounded
queries without media variants, and application constructs the prospective graph
once under the tree lock. Child-birth event updates load only affected children.

Private Mercure SSE carries committed drafts, progress and token usage. The hub
has no event history. Opening or reconnecting fetches one snapshot; no periodic
chat-state GET is used. A five-minute Secure, HttpOnly, SameSite=Strict cookie is
renewed before expiry. Caddy checks its JWT and current login session through a
loopback-only authorization subrequest before each connection. Publications also
recheck revocation, so an already-open connection receives no private events after
logout. Logout clears the subscriber cookie. Keep `MERCURE_URL`,
`MERCURE_PUBLIC_URL`, `MERCURE_JWT_ISSUER` and both Caddy configurations consistent.

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

The `tree_merge_tokens` Symfony limiter counts every model round, including input
and completion usage. The initial limit is 10,000,000 tokens per user per day,
configured by `TREE_MERGE_TOKEN_LIMIT`. Already spent tokens are counted even when
a response crosses the remaining quota. New rounds then stop with a localized
notice. Attempts are limited to twenty per user per hour; inference has a
270-second timeout. Tests use prepared HTTP responses and require no live model.

See the [Symfony AI Agent documentation](https://symfony.com/doc/current/ai/components/agent.html)
and [CLIProxyAPI source](https://github.com/router-for-me/CLIProxyAPI).

### Voice and external MCP clients

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

The Symfony MCP Bundle exposes authenticated Streamable HTTP at
`https://my-geno-tree.localhost/mcp/tree`. External clients use the application's
Bearer session header. Tools are `get_tree_context`, `find_people`,
`get_person_context`, `prepare_tree_merge` and `get_tree_merge`. Preparation uses
the same current draft and returns a preview link; no MCP tool applies changes.
The in-application agent invokes native PHP tools directly.

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

To add the demo account and tree to an existing local database without
removing current data, run:

```bash
docker compose exec backend php bin/console doctrine:fixtures:load --append --no-interaction
```

Do not run `doctrine:fixtures:load` without `--append` against a database that
contains data you need to keep: Doctrine purges it by default.

Validation (2026-11-05): 222 backend tests (6,113 assertions) and 384 frontend tests
pass. Line coverage is 92.68% backend and 90.16% frontend. Every production file
with measurable behavior exceeds 75%; EventPlaceRepository and
MediaVariantRepository contain only Doctrine constructor wiring (one uncovered
line each) and have no custom behavior to measure. Backend/frontend quality,
OpenAPI generation, production build, Composer validation, dev/prod container
checks, schema validation and all 20 migrations on a fresh disposable PostgreSQL
database pass. The backend suite retains nine existing PHPUnit mock-expectation
notices in map and GEDCOM tests; Vite reports its existing bundle-size advisory.

PhpStorm WARNING inspections covered every affected PHP file, with changed files
re-inspected. No actionable findings remain. The IDE mistakes the FamilyTree and
Person constructor arguments of the Doctrine entity TreeMergeDraft for service
injections; they are entity associations. Migration SQL also reports the absence
of an IDE data source. These IDE configuration/association findings have no quick
fix and are checked by Doctrine schema validation, real migrations and PHPStan.
Composer audit separately reports 21 advisories in 12 pre-existing packages;
this change adds the official message-store bridge without updating their
versions.

Manual HTTPS/Chrome validation used the synthetic tree
"Symfony AI — разделённые состояния 05.11": a live CLIProxyAPI/Symfony AI tool
cycle prepared three people while the tree stayed empty until Apply. Stop kept
the ready proposal; refresh restored the conversation and preview. A second tab
received ordinary answers and the applied result through Mercure. After Apply,
the same conversation prepared and applied Vera's rename to Veronika, preserving
all three people, birth years and family links after refresh. A follow-up answer
also reached the second tab without reload after both applications. Other trees
were preserved. API tests additionally verify provider failures, stale answers,
ownership, login-session isolation, revoked subscriptions, evidence limits and
complete multi-round token usage.
