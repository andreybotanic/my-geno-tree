# Per-document attachment controls — 2026-11-10

Every document tile and list row now contains a Shared/Personal switch. A shared
file can be attached to the current person; a personal file can be made shared.
The upload form has no attachment switch. Uploads from a person card retain
their existing default person attachment; uploads without a person remain shared.
Personal attachment is unavailable without a current person, while detaching an
existing personal document remains possible.

`PATCH /api/v1/trees/{treeId}/documents/{documentId}` accepts the required JSON
field `personId` (UUID or null), checks the tree voter, document/person membership
and `If-Match`, then changes the association in a short ORM transaction. Changes
update the tree ETag; a no-op preserves it. Original bytes, size, checksum, index
generation, status and error code remain unchanged. No indexing message is sent.
Search already resolves current attachment metadata through Postgres.

The frontend uses the generated response type, saves returned ETags for the next
mutation and displays failures without changing an unconfirmed attachment.
Metadata revalidation keeps the current list visible; changing the filter still
uses the existing loading area. The chosen tile/list view is preserved.

The list no longer reserves a permanent scrollbar gutter or right padding.
Tiles and rows use the full available width when scrolling is unnecessary.

## Verification

- Backend: **305 tests / 11176 assertions**, **93.75%** line coverage. New API
  tests cover both attachment directions, no-op ETags, unchanged original bytes,
  unchanged generation/status, absence of additional queue messages, filtered
  lists, malformed bodies, missing/stale versions, foreign people/owners and
  deleted documents.
- Frontend: **462 tests**, **91.14%** line coverage. Tests exercise per-document
  state, consecutive ETags, view preservation, rejected changes and refresh.
- Gateway: **28 tests**, **98.45%** line coverage. Backend quality, dev/prod
  container checks, schema validation, frontend quality/OpenAPI generation,
  gateway quality and production builds passed.
- All **28 migrations** ran on a disposable empty database, followed by schema
  validation and removal through Doctrine Console. No migration was needed.
- PhpStorm inspected all **four affected PHP files** at `WARNING`. Three files
  are clean. The two remaining Symfony plugin warnings refer to unchanged
  `FamilyTree`/`Person` constructor parameters of `TreeDocument`. These are domain
  constructor arguments, not service injections. `debug:container` confirms
  `container.excluded` because this is a Doctrine entity, no usages, and removal
  during compilation. No suppression or domain-code workaround was introduced.
- Existing two PHPUnit notices, Vite bundle-size advisory and the one-line empty
  `EventPlaceRepository` constructor coverage exception remain.

## Manual HTTPS acceptance

Used the existing synthetic RAG tree through the in-app browser:

1. Opened documents from a person, confirmed switches on every card and no upload
   target control. Changed `archive-01.txt` to Personal.
2. Reloaded the page and reopened documents: the attachment persisted. Personal
   filtering showed only that file. Switched to list view and verified its control.
3. Measured tiles and a single-row list without scrolling: scrollbar gutter and
   right-edge gap were both **0 px**.
4. Opened a second session, then restored the file to Shared in the first one.
   It disappeared from the Personal filter. A change in the stale second session
   was rejected with the version-conflict notice; Refresh recovered the state.
5. Returned to All/tiles with all eight original documents shared. No files or
   people were added or removed by these checks.

No automated browser E2E tests were added. `spec` and unrelated working-tree
changes were preserved.
