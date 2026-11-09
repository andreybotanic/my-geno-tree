# Document controls — 2026-11-09

All three binary switches now contain their labels or view icons inside one
track, with an active half. The upload target offers Shared/Personal alongside
the file picker in one row. It defaults to the current person; a shared upload
omits `personId`. Without a person, personal upload/filter controls are disabled.
Both interface languages are localized. The narrow layout uses the shorter
Files label while retaining the file input's accessible Choose files name.

The modal no longer shows the person's name. Shared documents have no attachment
caption; personal documents carry a Personal badge. Download buttons retain their
SVG icon and measure 32 px high in tiles and rows, matching Add relative buttons.
The existing fixed dialog size and confirmation for deletion are preserved.

Changing All/Personal previously inserted loading text above the toolbar. This
moved the complete toolbar, including the view switch. Loading feedback now
stays inside the fixed list area; stale rows are hidden there while loading.
The view switch remains mounted, usable and preserves the selected view. A test
with a deferred response exercises switching the view during a scope load.

## Manual HTTPS verification

The existing synthetic RAG tree and demo account were used in the in-app browser.

- Opened documents from the person card: no name appears inside the modal.
  Checked tiles and compact rows, internal switch labels/icons and icon tooltips.
- Measured Add buttons and Download buttons: both 32 px, including list mode.
  Changed the view with Space to check keyboard interaction.
- Uploaded `document-controls-personal.md` (161 bytes) with the default personal
  target. The Personal badge appeared; the Personal filter showed only this file.
- Uploaded `document-controls-shared.txt` (99 bytes) using Shared. It stayed out
  of the Personal filter and appeared without an attachment caption under All.
- At a 390 px viewport, both upload fields had the same vertical position and
  no content overflow after the narrow-layout adjustment. Restored the default
  viewport afterwards.
- Verified both temporary files' binding, original size and downloaded SHA-256
  against the original fixtures, then removed them through the authenticated API.
  The original eight corpus document IDs remained unchanged.
- An upload from the now-stale dialog produced the version-conflict message;
  Refresh recovered the current version and count of eight. No extra file was
  saved by the rejected upload.
- Measured the view switch before/after two scope changes: its rectangle remained
  exactly `x=1123, y=312.90625, width=78, height=34` in all three measurements.
  Reopened the modal with its clean default state and the eight original files.

## Automated checks

- Frontend: **461 tests**, **91.12%** line coverage; document panel **94.54%**,
  document dialog **100%**. Full suite used `--maxWorkers=2`.
- Backend: **303 tests / 10986 assertions**, **93.75%** line coverage.
- Voice gateway: **28 tests**, **98.45%** line coverage.
- Frontend lint/typecheck/OpenAPI checks and production build, backend quality,
  dev/prod container validation, application schema validation, gateway quality
  and production build all passed.
- All **28 migrations** ran on a separate empty database; schema validation
  passed and the disposable database was dropped through Doctrine Console.
- No PHP production files changed, so there were no PHP files to inspect in
  PhpStorm. No automated browser E2E tests were added.

The existing two PHPUnit notices, Vite bundle-size advisory and empty
`EventPlaceRepository` constructor coverage exception remain. `spec` and unrelated
working-tree changes were left untouched.
