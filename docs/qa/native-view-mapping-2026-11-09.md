# Native DTO mapping — 2026-11-09

`MapObject` and `ViewMapper` have been deleted, with all production consumers
migrated to Symfony's `ObjectMapperInterface`. DTO service discovery was restored:
Symfony 8.1's reverse mapping compiler pass now registers `#[Map(source: ...)]`
and chooses nested DTO targets from their declared property types. The mapper
supports several DTOs for one source, including coordinates and interval ends.
Domain entities have no presentation mapping attributes. No dependency or vendor
file was changed.

`TreeViewReader` returns typed aggregate DTOs, composed from mapped entities and
the existing bounded repository queries. HTTP controllers serialize DTOs through
the native Serializer; profile endpoints use `#[Serialize]`. ETag, status codes,
cookies, nullable fields and empty JSON objects retain their contracts. Agent/MCP
tool output is normalized at its public boundary. Manual event patches reuse
the existing domain date without converting it to JSON and back. Agent response
text and source metadata handling were not changed in this follow-up.

Computed death/dissolution dates still distinguish a living person or active
marriage (`null`) from an ended life/marriage with an unknown date. These getters
have no readable backing property, so their DTO mappings use an explicit scalar
transform triggered by the readable entity ID; the returned date is then mapped
recursively by Symfony.

## Automated verification

- Backend: **302 tests, 10946 assertions**, **93.80%** line coverage. Three new
  integration tests exercise the configured mapper, typed nested DTOs, competing
  targets, unknown values, computed dates and empty event data serialization.
- Frontend: **459 tests**, **91.11%** line coverage. The default parallel run hit
  the existing five-second limit in the 101-person force simulation; the complete
  suite passed with `--maxWorkers=2`, without changing tests or timeouts.
- Voice gateway: **28 tests**, **98.45%** line coverage.
- PHPStan, PHP CS Fixer, Twig lint, YAML/container lint (dev and prod), frontend
  lint/typecheck, OpenAPI generation verification, gateway quality and both
  production builds passed. The existing Vite bundle-size advisory remains.
- All measured production files exceed 75% line coverage except the pre-existing
  empty `EventPlaceRepository` constructor (one executable line, no behavior).
  The two existing PHPUnit notices remain.
- All **28 migrations** ran on a separate empty database; Doctrine schema
  validation passed there and against the application database. The disposable
  database was then removed through Doctrine Console. No migration was added.
- PhpStorm MCP, port **64354**: all **38 affected PHP files** inspected at
  `WARNING`; final result: **zero findings**. IDE synchronization resolved an
  initial stale PSI/index error before the successful complete inspection.

## Manual HTTPS verification

The synthetic acceptance tree was used at
`https://my-geno-tree.localhost/trees/01a1bfe8-09d3-7146-907c-fe511d704816/tree`.

1. Tree snapshot, person count, person details and automatic birth event loaded.
   Unknown text/date fields and the living-person date remained readable.
2. The document modal displayed eight existing documents, names, sizes and tree
   attachment labels. `archive-01.txt` downloaded successfully (201050 bytes).
3. A synthetic patronymic was saved in one tab. A second tab with the previous
   version was rejected when saving another value. Its unsaved input remained
   visible alongside the conflict and refresh action.
4. The original unknown patronymic was restored, the conflicting tab refreshed,
   and the tree reloaded. The unknown value and eight documents remained intact.
5. Navigation to the profile displayed the demo account and registration date;
   returning to the tree and reopening documents succeeded.

No new browser E2E tests were added. `spec` and unrelated working-tree changes
were left untouched.
