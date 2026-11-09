# JSON response simplification — 2026-11-09

Controllers no longer call `serialize()` followed by `JsonResponse::fromJsonString()`.
They use Symfony's native `AbstractController::json()` helper for DTO responses
with dynamic ETag, Location, status or cookies. Plain tree/document listings use
`#[Serialize]`. No application response adapter, trait or listener was introduced.
Dependencies remain readonly properties; the base controller manages its own
service locator for the built-in helpers.

`AuthResponseFactory` was removed. Login, registration and refresh map their token
bundle to the public `AuthView`, call `json()` and attach the existing secure
refresh cookie. The DTO allowlist contains only `accessToken`,
`accessTokenExpiresAt` and `user`; neither the refresh token nor ORM session data
is serialized into the response body. The API contract remains unchanged.

The private import upload parser was renamed from `file()` to `uploadedFile()`
to avoid overriding the framework's file-response helper.

Reference: Symfony's official [JSON response helper and automatic serialization](https://symfony.com/doc/current/controller.html#returning-json-response).

## Verification

- Backend: **303 tests / 10986 assertions**, **93.75%** line coverage. The new API
  test verifies the public response keys, dates and scoped refresh cookie for all
  three token endpoints. The existing two PHPUnit notices remain.
- Frontend: **459 tests**, **91.11%** line coverage (`--maxWorkers=2`).
- Voice gateway: **28 tests**, **98.45%** line coverage.
- PHPStan, PHP CS Fixer, Twig lint, YAML lint, dev/prod container validation,
  frontend lint/typecheck, OpenAPI checks, gateway quality and production builds
  passed. The existing Vite bundle-size advisory remains.
- All 28 migrations ran on an empty disposable database, followed by successful
  schema validation and removal through Doctrine Console. The application schema
  also validates. No schema or dependency changes were needed.
- PhpStorm MCP at port **64354**: all **21 affected PHP files** inspected at
  `WARNING`, **zero findings**.
- Every measured production file has at least 75% line coverage except the
  existing empty `EventPlaceRepository` constructor (one line with no behavior).

Manual checks used the local HTTPS application and the demo account in the
in-app browser: logout, wrong-password error, successful login, page reload with
session restoration, tree summaries, opening the synthetic RAG tree, person
details with unknown values and the document modal with eight original files.
No tree/person/document data was changed. Chrome DevTools MCP returned a closed
transport; the browser checks above were completed through the working in-app
browser. No automated browser E2E tests were added.

`spec` and unrelated working-tree changes were left untouched.
