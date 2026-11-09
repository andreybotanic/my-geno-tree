# Documents revision — manual acceptance, 9 November 2026

The answer-dependent source selection recorded below is historical and superseded
by [source metadata without answer processing](document-source-metadata-2026-11-09.md).

The local HTTPS application was checked using the demo account and its synthetic
`RAG proof — 1.6 MB / 1128 chunks` tree. Original corpus files and prior results
remain in [the first acceptance report](document-rag-2026-11-09.md).

## Interface

- Person details contain a Documents launcher with a counter; no overlaid tree toolbar action.
- Dialog dimensions stayed **1180 × 672 CSS px** in a 1280 × 720 viewport for
  eight tiled documents, the compact list and an empty Personal filter.
- Tiles show four cards per row. Both switches behave as toggles; view endpoints
  use SVGs with localized hover titles. List entries use two text lines.
- Names show byte size in parentheses. Ready files have no status badge.
  Non-ready files use “Not indexed”, with their actual state/error in the tooltip.
- Download remains an SVG button in cards; delete is a right-aligned trash icon.
- Two TXT/MD files uploaded together to the person, produced separate successful
  results, reached ready state and changed the counter from 8 to 10. The Personal
  filter showed only those files. Opening and cancelling delete preserved the file.
- Download from a document card and from an inline answer citation succeeded.
  Chat sources show filenames and sizes, without document passage previews.
- The sources section is separated by a border and uses a brand-colored SVG.

## Actual retrieval and selected sources

The [native message/tool record](document-revision-proof.json) contains both new
queries, full answers, retrieved chunks and persisted citation metadata.
Each native `similarity_search` returned **six 1000-character fragments from five
files**, rather than reading the 1.6 MB corpus into the agent.

1. “Кто починил механизм, отбивающий время на башне, деталью от домашнего
   швейного устройства? Назови человека, сезон и год.”
   Expected from archive-01: Antonina Mikhailovna Berezina, autumn 1907, a gear
   from a broken sewing machine. Actual answer gave all three facts correctly,
   preserving the English name. **One cited file** shown, despite five retrieved
   candidate files. Manually checked against the original English passage: PASS.
2. “Сопоставь сведения о переселении Корнеевых в Омск: какие даты указывают
   источники, насколько уверенно и что известно о причине? Не объединяй разные
   даты в один интервал.” Expected: autumn 1912 in a February 1913 letter, job at
   a bookbinding workshop; summer 1914 in an expressly uncertain later memory.
   Actual answer kept both dates separate, described confidence and the reason
   correctly. **Two cited files**, archive-07 and archive-05, shown. Manually
   checked against both originals: PASS.

Source selection now means files explicitly cited by their protected download URL
in the answer. Retrieval candidates alone do not become displayed sources. This
does not claim that every nearest neighbour semantically supports an answer.

`QACORPUS-END-01-UNREQUESTED` is a synthetic EOF canary added by the test corpus
generator. Its absence from the retrieved chunks helps demonstrate partial
retrieval. The application does not insert it into user uploads. Existing
originals were not edited to remove this test data.

## Persistence and verification

All eight pre-existing originals retained their exact sizes and SHA-256 hashes
after migration from media variants to independent document paths. New uploads
create neither MediaObject nor MediaVariant rows. The 28 migrations replayed on
a clean disposable PostgreSQL database; the new migration also passed down/up
with both a live document and a detached deletion tombstone populated.

The custom retry UI, endpoint and command are removed. Native Messenger retries
and failure transport remain. The commit-to-publication gap is accepted until
the framework provides outbox support.

Automated verification: 299 backend tests / 10876 assertions, 459 frontend tests,
28 gateway tests; line coverage 93.71%, 91.12% and 98.45%. Static analysis,
linters, OpenAPI generation, schema/container validation and production builds pass.

PhpStorm inspections at WARNING were run through port 64354 and rechecked after
fixing the redundant regular-expression escape. Remaining IDE notices are
configuration-only: SQL dialect is not assigned to migrations, and Symfony's
plugin treats Doctrine entity constructor arguments as service injections.
`debug:container --show-hidden` confirms those entities are excluded from DI.
No suppression or production-code workaround was added for these notices.

## DTO mapping follow-up

The adapter-based mapping described in this historical section was subsequently
replaced with native nested mapping; see the
[native mapping verification](native-view-mapping-2026-11-09.md).

All twelve remaining `*ViewFactory` classes were replaced with typed output DTOs
and Symfony ObjectMapper/Serializer. Aggregate queries moved to `TreeViewReader`;
their existing joins, batched relationship loading and query-count tests remain.
Nested mappings use an explicit reusable `MapObject` transform, while collections
use native `MapCollection`. DTOs are excluded from service registration. HTTP
response construction retains its headers/cookie responsibilities.

Two tests now compare JSON object contents independently of member enumeration
order. Optional date/text fields still omit nulls, explicit nullable fields remain,
and empty event data is still an object rather than a list.

Manual HTTPS checks after this refactor: tree summaries and person counters,
refresh, a seven-person snapshot, related people/marriage/events/place/photo data,
and saving then restoring the synthetic person's name. The document dialog still
opens correctly. The two temporary uploads were removed through the normal API;
native cleanup messages completed, both queues are empty, and the eight original
corpus hashes/sizes were checked again.

Final checks: **299 backend tests / 10876 assertions**, **459 frontend tests**,
**28 gateway tests**. Line coverage: **93.69% / 91.12% / 98.45%**. All meaningful
measured production files exceed 75%; the existing empty EventPlaceRepository
constructor has no meaningful behavior to cover. Quality, OpenAPI, production
builds, dev/prod container validation and schema validation pass. All 28 migrations
passed again on a clean disposable database. **43 affected PHP files passed
PhpStorm WARNING with zero findings** for the DTO refactor. Existing PHPUnit
notices and the Vite bundle-size advisory remain unchanged.
