# External MCP and OAuth

Connect a remote HTTP MCP client to
https://my-geno-tree.localhost/api/v1/mcp. It discovers OAuth automatically,
registers a client, opens the existing MyGenoTree sign-in and asks for consent.
Consent grants scope mcp: reading and changing **all trees owned by that user**.
The local development CA must be trusted by the client. Cloud agents require an
externally reachable HTTPS installation; this change does not deploy one.

The [installation guide](../README.md) generates the OAuth signing and encryption keys.

Keep these keys stable across container restarts. Configure APP_URL as the
canonical HTTPS origin and OAUTH_MCP_HOST as its hostname. OAUTH_MCP_ORIGIN
adds an allowed browser-client origin; local MCP Inspector origins are included.
Discovery uses RFC 8414 and RFC 9728. Client registration supports public HTTPS
CIMD documents and DCR public or confidential clients. CIMD requests reject
private networks, redirects and documents larger than 64 KiB.

OAuth uses authorization code + PKCE S256, five-minute one-time consent/code,
15-minute resource-bound access tokens, and rotating 30-day refresh tokens.
Refresh-token replay and /api/v1/oauth/revoke revoke the user's connection to
that client, including its tokens and outstanding codes. MCP credentials do not
authorize ordinary site APIs. Legacy MCP sessions are bound to user and client;
current stateless MCP requests authenticate independently.

The OAuth server has one resource: the canonical MCP endpoint. Clients such as
Codex CLI 0.132 that omit the optional RFC 8707 `resource` parameter use that
endpoint by default, including code exchange and refresh. An explicitly supplied
different resource is rejected, and every access token retains the MCP audience.
For a local Windows Codex host, connect from PowerShell:

~~~powershell
codex mcp add mygenotree --url https://my-geno-tree.localhost/api/v1/mcp
codex mcp login mygenotree --scopes mcp
~~~

Start authorization in the agent rather than opening `/oauth/authorize` directly:
the page requires the client's authorization parameters, including the redirect
URI and PKCE challenge. Invalid requests must be restarted in the agent.
Network/server failures can be retried
on the consent page; a failed decision requires reviewing fresh consent.

The external read tools include find_trees(query), get_tree_context(treeId,
referencePersonId?), find_people(treeId, query) and get_person_context(treeId,
personId). Tree discovery accepts
a fuzzy title or an exact UUID and returns up to five {id, name} candidates.
It queries only the authenticated owner's trees. Names use the existing
normalization, PostgreSQL trigram matching and Levenshtein distance.

Resolve identities from stored records and ask the user about ambiguous matches.
Each call explicitly names its tree. Both transports expose the single-action
write tools described below. Person creation and deletion require MCP form
elicitation; clients without it can still read and use the other write tools.
The site chat has no tree discovery or switching and displays confirmations
inline. REST OAuth endpoints and consent types are documented in OpenAPI.

Both registries also expose list_people(treeId, personIds?, groups?, cursor?,
limit?, expectedEtag?), list_relationships(treeId, kind, personIds?, cursor?,
limit?, expectedEtag?), list_events(treeId, personIds?, types?, source?, cursor?,
limit?, expectedEtag?) and find_places(query). Person field groups are identity,
life, places and metadata; identity and life are selected by default. Event
descriptions are returned in full. No tool returns photographs or media URLs.
Lists return items, total, returned, nextCursor and etag. Follow nextCursor until
null to cover the complete selection. Pages default to 100 rows, permit at most
200 rows and stay within 128 KiB; large records reduce the page length. Signed
cursors bind the selection and tree version. Reuse expectedEtag across different
lists for a consistent analysis; a changed tree returns TREE_VERSION_CONFLICT.
Place lookup reports unavailable without fabricating coordinates.

List adapters resolve the tree through `TreeContextService`, whose access decision
is delegated to `FamilyTreeVoter`. `TreeReadService` receives the resolved tree
and only coordinates input validation, signed cursors, version consistency and
response size. Its explicit people, parents, marriages and events methods use
their corresponding entity repositories. Query builders stay inside repositories;
the registered `GenealogyRecordHydrator` assembles read records without managed
entities or media. Tool metadata describes arguments, paging and mutation
semantics; the system prompt contains general conversational and reasoning rules.

Both registries reuse TreeReadTools and TreeWriteTools. ChatConfirmationHooks enforces the
selected tree; McpToolExecutor applies the same confirmation policy to external
clients and translates application errors into machine-readable MCP tool results.
Tree search lives in FamilyTreeRepository.

OAuthConsent implements the one-time, session-bound consent workflow;
OAuthClientRegistration implements DCR and safe CIMD document retrieval. These
application operations are not provided by the OAuth bundle. OAuthConnections
delegates token validation and issuing to League's TokenController and adds a
short transaction, serialized refresh rotation and per-user/client revocation.
Authentication uses Symfony's access_token firewall and League's ResourceServer;
FamilyTreeVoter continues to authorize each tree. OAuthClient extends the bundle's
supported client model with normal ORM attributes; no metadata event listener is
needed. REST and MCP exception listeners use the matched firewall configuration.
