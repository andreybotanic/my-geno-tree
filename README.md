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

The tree editor's **Create from description** action opens a persistent chat
draft in the right panel. Populated trees require a narrative reference person;
this does not change the layout reference. Messages survive reopening the panel
and refreshing the page. Cancelling a draft is a separate action.

The `/api/v1/trees/{treeId}/merges` contract covers draft creation, recent drafts,
reading, idempotent messages and cancellation. Message writes require the draft
version and a unique `requestId`; concurrent edits return `409` and preserve
the user's input. All drafts use the tree's ownership voter. Source text is
limited to 10,000 characters in total.

`PUT .../{mergeId}/proposal` validates explicit corrections against the complete
prospective graph without writing people or relationships. A proposal can create
up to five people, update existing core fields, and add parent or marriage links.
Unknown life status and unresolved person matches block application. Candidates
come from the existing name search; an equal name never silently merges people.
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

The chat pins a versioned preview with editable person cards, explicit candidate
selection, before/after values and source quotations. Corrections are checked on
the server before the review checkbox and apply button become available again.
The preview URL contains `?merge={mergeId}` and restores the conversation after
refresh. Applying refreshes the snapshot, highlights the affected people and
focuses the camera on them without changing the layout reference.

Text extraction uses Symfony AI Platform structured PHP DTOs and a versioned,
tree-only system prompt (`MergePrompt`). `POST .../{mergeId}/process` processes
the latest saved message with its `requestId` and draft version. Inference runs
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

Start the development text provider with Docker Compose (CPU is supported):

```sh
docker compose --profile ai up -d --build
docker compose exec -T ollama ollama pull qwen3:4b-instruct
```

`OLLAMA_URL`, `TREE_MERGE_MODEL` and `TREE_MERGE_MCP_URL` are backend settings.
The pinned Ollama image stores models in `ollama_models`; its context window is
32,768 tokens. Tests use prepared responses and do not require a running model.

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

Validation: backend API tests cover persistence, retries, conflicts, source
limits and ownership; component tests cover reference selection, errors and
input preservation. PhpStorm inspections report two non-actionable environment
findings: constructor arguments on an ORM entity are misidentified as service
injections, and generated migration SQL has no IDE data source. The entity is
created explicitly by the application service; Doctrine migration and schema
checks validate the SQL against PostgreSQL. The generated Symfony configuration
reference also triggers IDE-only `list<...>` type warnings; it is regenerated by
Symfony, not edited manually. PHPStan and container checks validate application
types and service configuration.

Manual browser checks for the merge chat include restoring a saved conversation,
retrying a provider failure, reading an existing person's date through HTTP MCP,
refusing a role-changing programming request, correcting a live Qwen proposal
(names, life status, parent role and unwanted links), and confirming the resulting
four-person branch. Sending "apply" kept the reviewed version and left the tree
empty until the separate confirmation control was used. Two-tab ETag conflicts,
reconfirmation and existing-person updates were also checked.

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
