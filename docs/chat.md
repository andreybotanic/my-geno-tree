# Tree chat

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

## Conversation and progress

GET /api/v1/trees/{treeId}/chat returns the current session-bound conversation;
POST opens it. Common operations use /api/v1/chats/{chatId}, including messages,
stop, reset and events. There is no SQL chat table. Expiry, reset and container
restart remove chat history, confirmations and per-chat permissions together.
Legacy cached tool proposals are discarded while visible messages are preserved.

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

## CLIProxyAPI in Docker Compose

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

The browser login uses callback port 1455. If a second installation uses a different host port, use the default port on an installation with that port available when connecting the provider.
