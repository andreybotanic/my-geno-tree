# Source metadata without answer processing — 9 November 2026

Supersedes the answer-dependent source selection in the earlier documents revision.

## Contract

`SimilaritySearch` adds its retrieved documents to Symfony AI's native
`SourceCollection`. Chat persistence copies that structured metadata to
`documentSources`; the UI receives the array directly and deduplicates file URLs.
These are search results, rather than an inferred list of claims supported by the
answer. Symfony AI does not provide a per-answer “cited/used” flag for these sources.

Source selection never reads answer text. The backend citation regular expression,
frontend substring filter, and document-specific Markdown link replacement have
been removed. The answer uses ordinary Markdown rendering. Authenticated downloads
are provided separately by the sources block, without document passage previews.
Stored historical answers and their metadata are not rewritten.

## Automated regression checks

- The native-agent API test returns two documents with identical filenames and an
  answer containing neither filename nor URL. Both source URLs persist independently.
- Answer text, including leading/trailing spaces, blank lines and Markdown, matches
  the provider fixture exactly both on submission and after history reload.
- Frontend source metadata is displayed regardless of references in the answer.
  Both source-associated and other relative Markdown URLs remain ordinary links.
- Downloads in the sources block still use authentication and the original filename;
  repeated fragments of one file produce one entry. Failure and escaped-name cases
  remain covered.

## Manual HTTPS acceptance

Using the existing synthetic 1.6 MB corpus, asked:

> Кто продал музыкальный инструмент, чтобы отремонтировать протекавшую крышу?
> Что это был за инструмент, когда и за сколько его продали?

Expected and actual: Вера Никитична Орлова sold a cabinet piano in December 1923 for
137 rubles to fund the roof; the accordion stayed in the family. Manually checked
against the original archive-04 passage: PASS.

The [native tool and message record](document-source-metadata-proof.json) contains
six search fragments from three files. The UI showed all three files from metadata,
even though the answer linked only archive-04. The ordinary inline Markdown link
was left as emitted by the agent. Expanding sources and downloading archive-04
through its separate authenticated control succeeded. The answer and sources
survived opening the existing conversation; no passage previews were shown.

## Completion checks

299 backend tests / 10920 assertions, 459 frontend tests and 28 gateway tests pass.
Line coverage: 93.68%, 91.11%, 98.45%. All meaningful measured production files
remain above 75%; the existing empty EventPlaceRepository constructor remains
outside meaningful behavior coverage. Quality, OpenAPI checks, production builds,
Composer validation, dev/prod container validation and schema validation pass.
All 28 migrations replayed on a clean disposable database. Both affected PHP files
passed PhpStorm WARNING via port 64354 without findings. Existing PHPUnit notices
and the Vite bundle-size advisory remain unchanged.
