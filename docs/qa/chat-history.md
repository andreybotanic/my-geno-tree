# Chat and tree validation history

These dated records describe their original revisions. Merge previews and batch
tools mentioned below have been superseded by single actions and confirmations.

## Compact permissions and atomic parents (2026-11-08)

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
