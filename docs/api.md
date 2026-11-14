# API response mapping

API output uses typed readonly DTOs in `backend/src/Api/View` with the native
Symfony ObjectMapper. DTOs remain in service discovery so Symfony 8.1 registers
their reverse `#[Map(source: ...)]` metadata and maps nested objects according to
the destination property type. Domain entities carry no API mapping attributes.
`TreeViewReader` loads summaries, snapshots and person details in bounded queries
and returns typed DTOs. Serialization happens at HTTP and tool output boundaries;
HTTP responses retain their headers, cookies and status codes. There is no custom
mapper adapter or nested-object transform. See the
[native mapping verification](qa/native-view-mapping-2026-11-09.md).
Controllers use Symfony's `#[Serialize]` for plain DTO/array results and the native
`AbstractController::json()` helper for responses with dynamic headers or cookies.
Authentication uses an explicit public `AuthView`; refresh tokens remain solely
in cookies. See the [response simplification checks](qa/json-responses-2026-11-09.md).

The [OpenAPI contract](../openapi/openapi.yaml) describes the public endpoints.
