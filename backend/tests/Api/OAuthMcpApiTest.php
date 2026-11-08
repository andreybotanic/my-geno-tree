<?php

namespace App\Tests\Api;

use App\Application\OAuth\OAuthMetadata;
use App\Entity\EventPlace;
use App\Entity\FamilyTree;
use App\Entity\GenealogicalEvent;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Entity\User;
use App\Entity\Value\DateKind;
use App\Entity\Value\EventType;
use App\Entity\Value\Gender;
use App\Entity\Value\GenealogicalDate;
use App\Entity\Value\KnownText;
use App\Entity\Value\ParentRole;
use App\Repository\FamilyTreeRepository;
use App\Tests\Infrastructure\Doctrine\QueryCountingMiddleware;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Mcp\Server\Stateless\RequestStateCodec;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

final class OAuthMcpApiTest extends ApiWebTestCase
{
    private string $loginToken;
    private string $clientId;
    private string $treeId;
    private string $etag;
    private string $accessToken;
    private string $refreshToken;
    /** @var array<string, mixed> */
    private array $clientCapabilities = [];
    private const string VERIFIER = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG';

    protected function setUp(): void
    {
        parent::setUp();
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        $this->loginToken = $this->responseString('accessToken');
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Романовы'], $this->headers());
        $this->treeId = $this->responseString('id');
        $this->etag = $this->responseString('etag');
        $this->jsonRequest(
            'POST',
            '/api/v1/oauth/register',
            ['client_name' => 'External agent', 'redirect_uris' => ['http://localhost:6274/oauth/callback']],
        );
        self::assertResponseStatusCodeSame(201);
        $this->clientId = $this->responseString('client_id');
    }

    public function testDiscoveryAndSeparateAuthentication(): void
    {
        $this->jsonRequest('GET', '/.well-known/oauth-authorization-server');
        self::assertResponseIsSuccessful();
        self::assertSame(['S256'], $this->responseArray('code_challenge_methods_supported'));
        $this->jsonRequest('GET', '/.well-known/oauth-protected-resource/api/v1/mcp');
        self::assertSame($this->resource(), $this->responseString('resource'));
        $this->jsonRequest('POST', '/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString(
            'resource_metadata=',
            (string)$this->client->getResponse()->headers->get('WWW-Authenticate'),
        );
        $this->jsonRequest('POST', '/api/v1/mcp', [], $this->headers());
        self::assertResponseStatusCodeSame(401);
        $this->authorize();
        $this->jsonRequest('GET', '/api/v1/me', [], ['Authorization' => 'Bearer ' . $this->accessToken]);
        self::assertResponseStatusCodeSame(401);
        $this->mcp('tools/list');
        self::assertResponseIsSuccessful();
        /**
         * @var list<array{
         *     name: string,
         *     description: string,
         *     annotations: array{
         *         readOnlyHint: bool,
         *         idempotentHint?: bool
         *     }
         * }> $tools
         */
        $tools = $this->responseArray('result', 'tools');
        $tools = array_column($tools, null, 'name');
        self::assertEqualsCanonicalizing(
            [
                'find_trees',
                'get_tree_context',
                'find_people',
                'get_person_context',
                'create_person',
                'update_person',
                'delete_person',
                'add_parent_link',
                'remove_parent_link',
                'create_marriage_period',
                'update_marriage_period',
                'remove_marriage_period',
                'create_event',
                'update_event',
                'delete_event',
                'set_event_place',
                'reorder_events',
                'list_people',
                'list_relationships',
                'list_events',
                'find_places',
            ],
            array_keys($tools),
        );
        self::assertTrue($tools['find_trees']['annotations']['readOnlyHint']);
        foreach (['list_people', 'list_relationships', 'list_events'] as $name) {
            self::assertStringContainsString('nextCursor', $tools[$name]['description']);
            self::assertStringContainsString('expectedEtag', $tools[$name]['description']);
            self::assertStringContainsString('128 KiB', $tools[$name]['description']);
        }
        self::assertFalse($tools['create_person']['annotations']['readOnlyHint']);
        self::assertArrayHasKey('idempotentHint', $tools['create_person']['annotations']);
        self::assertSame(false, $tools['create_person']['annotations']['idempotentHint'] ?? null);
        self::assertStringContainsString('confirmation', $tools['create_person']['description']);
    }

    public function testPersonMutationsRequireBoundClientElicitationAndChainTheirEtags(): void
    {
        $this->authorize();
        $arguments = [
            'treeId'     => $this->treeId,
            'ifMatch'    => $this->etag,
            'fields'     => ['givenName' => ['state' => 'known', 'value' => 'Vera']],
            'connection' => null,
        ];
        $this->call('create_person', $arguments);
        self::assertTrue($this->responseArray('result')['isError']);
        self::assertSame('CONFIRMATION_UNSUPPORTED', $this->responseString('result', 'structuredContent', 'code'));
        $this->clientCapabilities = ['elicitation' => ['form' => new \stdClass()]];
        $this->call('create_person', $arguments);
        self::assertSame('input_required', $this->responseString('result', 'resultType'));
        self::assertSame('elicitation/create', $this->responseString('result', 'inputRequests', 'approval', 'method'));
        $state = $this->responseString('result', 'requestState');
        $answer = ['approval' => ['action' => 'accept', 'content' => ['approved' => true]]];
        $changed = $arguments;
        $changed['fields']['givenName']['value'] = 'Changed without approval';
        $this->mcp(
            'tools/call',
            [
                'name'           => 'create_person',
                'arguments'      => $changed,
                'requestState'   => $state,
                'inputResponses' => $answer,
            ]
        );
        self::assertSame('CONFIRMATION_INVALID', $this->responseString('result', 'structuredContent', 'code'));
        $this->mcp(
            'tools/call',
            [
                'name'           => 'create_person',
                'arguments'      => $arguments,
                'requestState'   => $state,
                'inputResponses' => $answer,
            ]
        );
        self::assertFalse(
            $this->responseArray(
                'result'
            )['isError'] ?? false,
            json_encode(
                $this->responseData(),
                JSON_THROW_ON_ERROR
            )
        );
        $created = $this->responseArray('result', 'structuredContent');
        self::assertNotSame($this->etag, $created['etag']);
        $this->call(
            'update_person',
            [
                'treeId'   => $this->treeId,
                'ifMatch'  => $created['etag'],
                'personId' => $created['personId'],
                'fields'   => ['gender' => 'female'],
            ]
        );
        $updated = $this->responseArray('result', 'structuredContent');
        self::assertNotSame($created['etag'], $updated['etag']);
        $delete = ['treeId' => $this->treeId, 'ifMatch' => $updated['etag'], 'personId' => $created['personId']];
        $this->call('delete_person', $delete);
        self::assertSame('input_required', $this->responseString('result', 'resultType'));
        $deleteState = $this->responseString('result', 'requestState');
        $this->mcp(
            'tools/call',
            [
                'name'           => 'delete_person',
                'arguments'      => $delete,
                'requestState'   => $deleteState,
                'inputResponses' => ['approval' => ['action' => 'decline']],
            ]
        );
        self::assertSame('TOOL_DENIED', $this->responseString('result', 'structuredContent', 'code'));
        $this->mcp(
            'tools/call',
            [
                'name'           => 'delete_person',
                'arguments'      => $delete,
                'requestState'   => $deleteState,
                'inputResponses' => $answer,
            ]
        );
        self::assertSame('CONFIRMATION_USED', $this->responseString('result', 'structuredContent', 'code'));
        $this->call('delete_person', $delete);
        $deleteState = $this->responseString('result', 'requestState');
        $this->mcp(
            'tools/call',
            [
                'name'           => 'delete_person',
                'arguments'      => $delete,
                'requestState'   => $deleteState,
                'inputResponses' => $answer,
            ]
        );
        self::assertSame($created['personId'], $this->responseString('result', 'structuredContent', 'deletedId'));
    }

    public function testCancelledAndExpiredMcpConfirmationsCannotCreatePeople(): void
    {
        $this->authorize();
        $arguments = ['treeId' => $this->treeId, 'ifMatch' => $this->etag, 'fields' => [], 'connection' => null];
        $this->clientCapabilities = ['elicitation' => ['url' => new \stdClass()]];
        $this->call('create_person', $arguments);
        self::assertSame('CONFIRMATION_UNSUPPORTED', $this->responseString('result', 'structuredContent', 'code'));
        $this->clientCapabilities = ['elicitation' => ['form' => new \stdClass()]];
        foreach ([['action' => 'cancel'], ['action' => 'accept', 'content' => ['approved' => false]]] as $answer) {
            $this->call('create_person', $arguments);
            $state = $this->responseString('result', 'requestState');
            $this->mcp(
                'tools/call',
                [
                    'name'           => 'create_person',
                    'arguments'      => $arguments,
                    'requestState'   => $state,
                    'inputResponses' => ['approval' => $answer],
                ]
            );
            self::assertSame('TOOL_DENIED', $this->responseString('result', 'structuredContent', 'code'));
        }
        $this->call('create_person', $arguments);
        $state = $this->responseString('result', 'requestState');
        $secret = (string)static::getContainer()->getParameter('kernel.secret');
        $codec = new RequestStateCodec(hash('sha256', 'mcp-confirmations:' . $secret, true), 120);
        $legacyPayload = $codec->verify($state);
        $canonical = $arguments;
        ksort($canonical);
        $legacyPayload['digest'] = hash('sha256', json_encode(['create_person', $canonical], JSON_THROW_ON_ERROR));
        $this->mcp(
            'tools/call',
            [
                'name'           => 'create_person',
                'arguments'      => $arguments,
                'requestState'   => $codec->mint($legacyPayload),
                'inputResponses' => ['approval' => ['action' => 'accept', 'content' => ['approved' => true]]],
            ]
        );
        self::assertSame('CONFIRMATION_INVALID', $this->responseString('result', 'structuredContent', 'code'));
        $expired = $codec->mint($codec->verify($state), time() - 121);
        $this->mcp(
            'tools/call',
            [
                'name'           => 'create_person',
                'arguments'      => $arguments,
                'requestState'   => $expired,
                'inputResponses' => ['approval' => ['action' => 'accept', 'content' => ['approved' => true]]],
            ]
        );
        self::assertArrayHasKey('error', $this->responseData());
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers());
        self::assertSame([], $this->responseArray('people'));
        self::assertSame($this->etag, $this->responseString('etag'));
    }

    public function testMcpApprovalKeepsTheOriginalEtagDuringConcurrentChanges(): void
    {
        $this->authorize();
        $this->clientCapabilities = ['elicitation' => ['form' => new \stdClass()]];
        $arguments = ['treeId' => $this->treeId, 'ifMatch' => $this->etag, 'fields' => [], 'connection' => null];
        $this->call('create_person', $arguments);
        $state = $this->responseString('result', 'requestState');
        $this->jsonRequest(
            'PATCH',
            '/api/v1/trees/' . $this->treeId,
            ['name' => 'Another editor'],
            [
                ...$this->headers(),
                'If-Match' => $this->etag,
            ]
        );
        self::assertResponseIsSuccessful();
        $this->mcp(
            'tools/call',
            [
                'name'           => 'create_person',
                'arguments'      => $arguments,
                'requestState'   => $state,
                'inputResponses' => ['approval' => ['action' => 'accept', 'content' => ['approved' => true]]],
            ]
        );
        self::assertSame('TREE_VERSION_CONFLICT', $this->responseString('result', 'structuredContent', 'code'));
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers());
        self::assertSame([], $this->responseArray('people'));
    }

    public function testConsentIsSessionBoundOneTimeAndCanBeDenied(): void
    {
        $id = $this->consent();
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/login',
            ['identifier' => 'family_admin', 'password' => 'StrongPass1!'],
        );
        $other = $this->responseString('accessToken');
        $this->jsonRequest(
            'POST',
            '/api/v1/oauth/authorization-requests/' . $id . '/decision',
            ['approved'      => true],
            ['Authorization' => 'Bearer ' . $other],
        );
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest(
            'POST',
            '/api/v1/oauth/authorization-requests/' . $id . '/decision',
            ['approved' => false],
            $this->headers(),
        );
        self::assertResponseIsSuccessful();
        parse_str((string)parse_url($this->responseString('redirectUrl'), PHP_URL_QUERY), $query);
        self::assertSame('access_denied', $query['error']);
        self::assertSame('opaque-client-state', $query['state']);
        self::assertSame($this->issuer(), $query['iss']);
        $this->jsonRequest(
            'POST',
            '/api/v1/oauth/authorization-requests/' . $id . '/decision',
            ['approved' => true],
            $this->headers(),
        );
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest(
            'POST',
            '/api/v1/oauth/authorization-requests/not-a-uuid/decision',
            ['approved' => true],
            $this->headers(),
        );
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest(
            'POST',
            '/api/v1/oauth/authorization-requests/' . $id . '/decision',
            ['approved' => 'yes'],
            $this->headers(),
        );
        self::assertResponseStatusCodeSame(422);
    }

    public function testLegacyClientCanAuthorizeExchangeAndRefreshWithoutResource(): void
    {
        $parameters = $this->parameters();
        unset($parameters['resource']);
        $this->jsonRequest('POST', '/api/v1/oauth/authorization-requests', $parameters, $this->headers());
        self::assertResponseStatusCodeSame(201);
        $id = $this->responseString('id');
        $this->jsonRequest(
            'POST',
            '/api/v1/oauth/authorization-requests/' . $id . '/decision',
            ['approved' => true],
            $this->headers(),
        );
        self::assertResponseIsSuccessful();
        parse_str((string)parse_url($this->responseString('redirectUrl'), PHP_URL_QUERY), $query);
        self::assertIsString($query['code'] ?? null);

        foreach (['authorization_code', 'refresh_token'] as $grant) {
            $payload = [
                'client_id'  => $this->clientId,
                'grant_type' => $grant,
                ...('authorization_code' === $grant ? [
                    'code'          => $query['code'],
                    'code_verifier' => self::VERIFIER,
                    'redirect_uri'  => $parameters['redirect_uri'],
                ] : ['refresh_token' => $this->refreshToken]),
            ];
            $this->token([...$payload, 'resource' => 'https://attacker.test/mcp']);
            self::assertResponseStatusCodeSame(400);
            $this->client->request(
                'POST',
                'https://localhost/api/v1/oauth/token',
                $payload,
                server: ['HTTPS' => 'on', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            );
            self::assertResponseIsSuccessful();
            $accessToken = $this->responseString('access_token');
            if ('' === $accessToken) {
                self::fail('Expected a non-empty access token.');
            }
            $this->accessToken = $accessToken;
            $this->refreshToken = $this->responseString('refresh_token');
            $token = new Parser(new JoseEncoder())->parse($accessToken);
            self::assertInstanceOf(UnencryptedToken::class, $token);
            self::assertSame([$this->resource()], $token->claims()->get('aud'));
            self::assertSame($this->issuer(), $token->claims()->get('iss'));
            $this->mcp('tools/list');
            self::assertResponseIsSuccessful();
            $this->jsonRequest('GET', '/api/v1/me', [], ['Authorization' => 'Bearer ' . $this->accessToken]);
            self::assertResponseStatusCodeSame(401);
        }
    }

    public function testBrowserPreflightAndLegacySessionsAreBoundToTheOAuthClient(): void
    {
        foreach (['/api/v1/mcp', '/api/v1/oauth/token', '/.well-known/oauth-authorization-server'] as $path) {
            $this->client->request(
                'OPTIONS',
                'https://localhost' . $path,
                server: ['HTTP_ORIGIN' => 'http://localhost:6274', 'HTTPS' => 'on'],
            );
            self::assertResponseStatusCodeSame(204);
            self::assertSame('*', $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
        }
        $this->authorize();
        $this->jsonRequest(
            'POST',
            '/api/v1/mcp',
            [
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => 'initialize',
                'params'  => [
                    'protocolVersion' => '2025-11-25',
                    'capabilities'    => new \stdClass(),
                    'clientInfo'      => ['name' => 'test', 'version' => '1'],
                ],
            ],
            ['Authorization' => 'Bearer ' . $this->accessToken, 'Accept' => 'application/json, text/event-stream'],
        );
        self::assertResponseIsSuccessful();
        $session = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotNull($session);
        $headers = [
            'Authorization'        => 'Bearer ' . $this->accessToken,
            'Accept'               => 'application/json, text/event-stream',
            'Mcp-Session-Id'       => $session,
            'MCP-Protocol-Version' => '2025-11-25',
        ];
        $this->jsonRequest(
            'POST',
            '/api/v1/mcp',
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            $headers,
        );
        self::assertResponseIsSuccessful();
        $this->jsonRequest('POST', '/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $headers);
        self::assertResponseIsSuccessful();
        $this->jsonRequest(
            'POST',
            '/api/v1/oauth/register',
            ['redirect_uris' => ['http://localhost:6274/oauth/callback']],
        );
        $this->clientId = $this->responseString('client_id');
        $this->authorize();
        $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        $this->jsonRequest('POST', '/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'], $headers);
        self::assertResponseStatusCodeSame(404);
        $headers['Mcp-Session-Id'] = 'invalid';
        $this->jsonRequest('POST', '/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list'], $headers);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRejectsRedirectPkceResourceAndClientMetadataAttacks(): void
    {
        foreach ([
            ['redirect_uri' => 'https://attacker.test/callback'],
            ['resource'              => 'https://attacker.test/mcp'],
            ['resource'              => null],
            ['resource'              => ''],
            ['resource'              => ['https://attacker.test/mcp']],
            ['code_challenge_method' => 'plain'],
            ['code_challenge'        => 'short'],
            ['scope'                 => 'admin'],
            ['response_type'         => 'token'],
            ['state'                 => ['not-a-string']],
            ['client_id'             => 'missing-client'],
            ['client_id'             => 'https://127.0.0.1/metadata.json'],
        ] as $override) {
            $this->jsonRequest(
                'POST',
                '/api/v1/oauth/authorization-requests',
                array_replace($this->parameters(), $override),
                $this->headers(),
            );
            self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
            self::assertArrayNotHasKey('redirectUrl', $this->responseData());
        }
        foreach ([
            ['redirect_uris' => ['javascript:alert(1)']],
            ['redirect_uris' => ['http://example.test/callback']],
            ['redirect_uris' => ['https://example.test/callback#fragment']],
            ['redirect_uris' => []],
            ['redirect_uris' => ['https://example.test/callback'], 'grant_types' => ['password']],
            ['redirect_uris' => ['https://example.test/callback'], 'grant_types' => [['invalid']]],
            ['redirect_uris' => ['https://example.test/callback'], 'token_endpoint_auth_method' => 'password'],
        ] as $payload) {
            $this->jsonRequest('POST', '/api/v1/oauth/register', $payload);
            self::assertResponseStatusCodeSame(400);
        }
    }

    public function testCodePkceRefreshRotationReplayAndRevocation(): void
    {
        $this->authorize();
        $oldRefresh = $this->refreshToken;
        $oldAccess = $this->accessToken;
        $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $oldRefresh]);
        self::assertResponseIsSuccessful();
        $currentAccess = $this->responseString('access_token');
        $currentRefresh = $this->responseString('refresh_token');
        self::assertNotSame($oldRefresh, $currentRefresh);
        $this->accessToken = $oldAccess;
        $this->mcp('tools/list');
        self::assertResponseStatusCodeSame(401);
        $this->accessToken = $currentAccess;
        $this->mcp('tools/list');
        self::assertResponseIsSuccessful();
        $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $oldRefresh]);
        self::assertResponseStatusCodeSame(400);
        $this->mcp('tools/list');
        self::assertResponseStatusCodeSame(401);
        $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $currentRefresh]);
        self::assertResponseStatusCodeSame(400);
        $this->authorize();
        $this->token(['token' => $this->refreshToken], '/api/v1/oauth/revoke');
        self::assertResponseIsSuccessful();
        $this->mcp('tools/list');
        self::assertResponseStatusCodeSame(401);
        $this->token(['token' => 'unknown'], '/api/v1/oauth/revoke');
        self::assertResponseIsSuccessful();
        $this->authorize();
        $this->token(['token' => $this->accessToken], '/api/v1/oauth/revoke');
        $this->mcp('tools/list');
        self::assertResponseStatusCodeSame(401);
    }

    public function testWrongVerifierCodeReuseAndWrongTokenResourceAreRejected(): void
    {
        $code = $this->code();
        $this->token(
            [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'code_verifier' => str_repeat('x', 43),
                'redirect_uri'  => 'http://localhost:6274/oauth/callback',
            ],
        );
        self::assertResponseStatusCodeSame(400);
        $this->token(
            [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'code_verifier' => self::VERIFIER,
                'redirect_uri'  => 'http://localhost:6274/oauth/callback',
            ],
        );
        self::assertResponseIsSuccessful();
        $this->token(
            [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'code_verifier' => self::VERIFIER,
                'redirect_uri'  => 'http://localhost:6274/oauth/callback',
            ],
        );
        self::assertResponseStatusCodeSame(400);
        $this->token(
            ['grant_type' => 'refresh_token', 'refresh_token' => 'invalid', 'resource' => 'https://wrong.test'],
        );
        self::assertResponseStatusCodeSame(400);
        $this->token(['grant_type' => 'refresh_token', 'refresh_token' => 'invalid', 'client_id' => 'invalid']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testMcpTokenWithValidSignatureButWrongAudienceIsRejected(): void
    {
        $this->authorize();
        $handler = new \App\Security\McpTokenHandler(
            static::getContainer()->get(\League\OAuth2\Server\ResourceServer::class),
            new OAuthMetadata('https://different-resource.example'),
            new \Symfony\Component\HttpFoundation\RequestStack(),
        );
        $this->expectException(\Symfony\Component\Security\Core\Exception\BadCredentialsException::class);
        $handler->getUserBadgeFrom($this->accessToken);
    }

    public function testStandardTokenEndpointSupportsBasicAuthenticationAndConnectionIsolation(): void
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/oauth/register',
            [
                'redirect_uris'              => ['http://localhost:6274/oauth/callback'],
                'token_endpoint_auth_method' => 'client_secret_basic',
            ],
        );
        $this->clientId = $this->responseString('client_id');
        $secret = $this->responseString('client_secret');
        $code = $this->code();
        $basic = 'Basic ' . base64_encode(urlencode($this->clientId) . ':' . urlencode($secret));
        $this->client->request(
            'POST',
            'https://localhost/api/v1/oauth/token',
            [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'code_verifier' => self::VERIFIER,
                'redirect_uri'  => 'http://localhost:6274/oauth/callback',
                'resource'      => $this->resource(),
            ],
            server: ['HTTPS' => 'on', 'HTTP_AUTHORIZATION' => $basic],
        );
        self::assertResponseIsSuccessful();
        $refresh = $this->responseString('refresh_token');
        $this->client->request(
            'POST',
            'https://localhost/api/v1/oauth/token',
            ['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'resource' => $this->resource()],
            server: ['HTTPS' => 'on', 'HTTP_AUTHORIZATION' => $basic],
        );
        self::assertResponseIsSuccessful();
        $firstAccess = $this->responseString('access_token');
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration(['username' => 'another_owner', 'email' => 'another@example.test']),
        );
        $this->loginToken = $this->responseString('accessToken');
        $code = $this->code();
        $this->token(
            [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'code_verifier' => self::VERIFIER,
                'redirect_uri'  => 'http://localhost:6274/oauth/callback',
                'client_secret' => $secret,
            ],
        );
        self::assertResponseIsSuccessful();
        $this->accessToken = $this->responseString('access_token');
        $this->token(['token' => $this->accessToken, 'client_secret' => $secret], '/api/v1/oauth/revoke');
        self::assertResponseIsSuccessful();
        $this->mcp('tools/list');
        self::assertResponseStatusCodeSame(401);
        $this->accessToken = $firstAccess;
        $this->mcp('tools/list');
        self::assertResponseIsSuccessful();
    }

    public function testTreeSearchRankingUuidOwnerIsolationAndBoundedQueries(): void
    {
        foreach ([
            'Романова',
            'Романов',
            'Романовы II',
            'Семья Ёлкиных',
            'Smith',
            'Smyth',
        ] as $name) {
            $this->jsonRequest('POST', '/api/v1/trees', ['name' => $name], $this->headers());
        }
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration(['username' => 'another_owner', 'email' => 'another@example.test']),
        );
        $otherToken = $this->responseString('accessToken');
        $this->jsonRequest(
            'POST',
            '/api/v1/trees',
            ['name'          => 'Романовы'],
            ['Authorization' => 'Bearer ' . $otherToken],
        );
        $otherTree = $this->responseString('id');
        $this->authorize();
        $this->call('find_trees', ['query' => 'романовы']);
        self::assertSame($this->treeId, $this->responseString('result', 'structuredContent', 'trees', '0', 'id'));
        self::assertLessThanOrEqual(5, count($this->responseArray('result', 'structuredContent', 'trees')));
        $this->call('find_trees', ['query' => 'РОМАНОВЫ']);
        self::assertSame($this->treeId, $this->responseString('result', 'structuredContent', 'trees', '0', 'id'));
        $this->call('find_trees', ['query' => 'Романвы']);
        self::assertNotEmpty($this->responseArray('result', 'structuredContent', 'trees'));
        $this->call('find_trees', ['query' => 'Семья Елкиных']);
        self::assertSame(
            'Семья Ёлкиных',
            $this->responseString(
                'result',
                'structuredContent',
                'trees',
                '0',
                'name'
            )
        );
        $this->call('find_trees', ['query' => 'Smit']);
        self::assertSame('Smith', $this->responseString('result', 'structuredContent', 'trees', '0', 'name'));
        foreach ([$otherTree, Uuid::v7()->toRfc4122(), 'zzzzzzzzzz'] as $query) {
            $this->call('find_trees', ['query' => $query]);
            self::assertSame([], $this->responseArray('result', 'structuredContent', 'trees'));
        }
        $this->call('find_trees', ['query' => $this->treeId]);
        self::assertSame($this->treeId, $this->responseString('result', 'structuredContent', 'trees', '0', 'id'));
        $this->call('get_tree_context', ['treeId' => $otherTree]);
        self::assertSame('TREE_NOT_FOUND', $this->responseString('result', 'structuredContent', 'code'));
        $this->call('find_trees', ['query' => ' ']);
        self::assertTrue($this->responseArray('result')['isError']);
        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'family_admin']);
        self::assertInstanceOf(User::class, $user);
        $counter = static::getContainer()->get(QueryCountingMiddleware::class . '.default');
        self::assertInstanceOf(QueryCountingMiddleware::class, $counter);
        $counter->reset();
        static::getContainer()->get(FamilyTreeRepository::class)->searchForOwner($user, 'Романовы');
        self::assertSame(1, $counter->count());
    }

    public function testSiteChatBanDoesNotAffectExternalMcpAccess(): void
    {
        $manager = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $user = $manager->getRepository(User::class)->findOneBy(['username' => 'family_admin']);
        self::assertInstanceOf(User::class, $user);
        $now = new \DateTimeImmutable();
        $user->warnAboutChat($now, 3600, 0);
        $user->warnAboutChat($now, 3600, 0);
        $ban = $user->warnAboutChat($now, 3600, 0);
        self::assertNotNull($ban);
        $manager->persist($ban);
        $manager->flush();
        $this->authorize();
        $this->call('find_trees', ['query' => $this->treeId]);
        self::assertSame($this->treeId, $this->responseString('result', 'structuredContent', 'trees', '0', 'id'));
        $this->call('get_tree_context', ['treeId' => $this->treeId]);
        self::assertSame($this->etag, $this->responseString('result', 'structuredContent', 'etag'));
    }

    public function testPagedReadsRejectEveryTreeDeniedByTheVoter(): void
    {
        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $other = new User('private_reader', 'private-reader@example.test', 'hash', 'en', $now);
        $tree = new FamilyTree($other, 'Private tree', $now);
        $em->persist($other);
        $em->persist($tree);
        $em->flush();
        $treeId = $tree->getId()->toRfc4122();
        $this->authorize();
        foreach ([
            ['list_people', []],
            ['list_relationships', ['kind' => 'parents']],
            ['list_relationships', ['kind' => 'marriages']],
            ['list_events', []],
        ] as [$name, $arguments]) {
            $this->call($name, ['treeId' => $treeId, ...$arguments]);
            self::assertTrue($this->responseArray('result')['isError']);
            self::assertSame('TREE_NOT_FOUND', $this->responseString('result', 'structuredContent', 'code'));
        }
    }

    public function testWholeTreeListsAreCompleteFilteredAndVersionBound(): void
    {
        $ids = $this->seedReadPeople(48);
        $this->authorize();
        $seen = [];
        $cursor = null;
        do {
            $this->call('list_people', ['treeId' => $this->treeId, 'limit' => 7, 'cursor' => $cursor]);
            self::assertFalse($this->responseArray('result')['isError']);
            self::assertSame(48, $this->responseInt('result', 'structuredContent', 'total'));
            $items = $this->responseArray('result', 'structuredContent', 'items');
            foreach ($items as $item) {
                self::assertIsArray($item);
                self::assertArrayNotHasKey('photoThumbnailUrl', $item);
                self::assertArrayNotHasKey('createdAt', $item);
                self::assertIsString($item['id']);
                $seen[] = $item['id'];
            }
            $cursor = $this->responseArray('result', 'structuredContent')['nextCursor'];
            self::assertTrue(null === $cursor || is_string($cursor));
        } while (null !== $cursor);
        self::assertSame($ids, $seen);
        $this->call(
            'list_people',
            [
                'treeId'    => $this->treeId,
                'personIds' => [$ids[0]],
                'groups'    => ['identity', 'life', 'places', 'metadata'],
            ],
        );
        $person = $this->responseArray('result', 'structuredContent', 'items', '0');
        self::assertSame(['state' => 'known', 'value' => 'Person 0'], $person['givenName']);
        self::assertSame(['state' => 'unknown'], $person['patronymic']);
        self::assertSame(
            ['state' => 'known', 'kind' => 'between', 'year' => 1900, 'end' => ['year' => 1902]],
            $person['birthDate'],
        );
        self::assertNull($person['deathDate']);
        self::assertSame(['name' => 'Historical place', 'coordinates' => null], $person['birthPlace']);
        self::assertArrayHasKey('createdAt', $person);
        $etag = $this->responseString('result', 'structuredContent', 'etag');
        $this->call(
            'list_relationships',
            ['treeId' => $this->treeId, 'kind' => 'parents', 'personIds' => [$ids[1]], 'expectedEtag' => $etag],
        );
        self::assertSame(1, $this->responseInt('result', 'structuredContent', 'total'));
        self::assertSame($ids[0], $this->responseString('result', 'structuredContent', 'items', '0', 'parentId'));
        $this->call('list_relationships', ['treeId' => $this->treeId, 'kind' => 'marriages']);
        self::assertSame(1, $this->responseInt('result', 'structuredContent', 'total'));
        self::assertNull($this->responseArray('result', 'structuredContent', 'items', '0')['dissolutionDate']);
        $this->call('list_people', ['treeId' => $this->treeId, 'personIds' => []]);
        self::assertSame(0, $this->responseInt('result', 'structuredContent', 'total'));
        $this->call('list_people', ['treeId' => $this->treeId, 'limit' => 1]);
        $cursor = $this->responseString('result', 'structuredContent', 'nextCursor');
        $this->call('list_people', ['treeId' => $this->treeId, 'cursor' => $cursor, 'groups' => ['identity']]);
        self::assertSame('TREE_CURSOR_INVALID', $this->responseString('result', 'structuredContent', 'code'));
        $this->jsonRequest(
            'PATCH',
            '/api/v1/trees/' . $this->treeId,
            ['name' => 'Changed'],
            $this->headers() + ['If-Match' => $etag],
        );
        self::assertResponseIsSuccessful();
        $this->call('list_people', ['treeId' => $this->treeId, 'cursor' => $cursor]);
        self::assertSame('TREE_VERSION_CONFLICT', $this->responseString('result', 'structuredContent', 'code'));
        $this->call('list_events', ['treeId' => $this->treeId, 'expectedEtag' => $etag]);
        self::assertSame('TREE_VERSION_CONFLICT', $this->responseString('result', 'structuredContent', 'code'));
    }

    public function testEventPagesPreserveFullDescriptionsAndBoundBytesWithoutNPlusOne(): void
    {
        $ids = $this->seedReadPeople(48);
        $this->authorize();
        $this->client->disableReboot();
        $counter = static::getContainer()->get(QueryCountingMiddleware::class . '.default');
        self::assertInstanceOf(QueryCountingMiddleware::class, $counter);
        $counter->reset();
        $this->call(
            'list_events',
            ['treeId' => $this->treeId, 'personIds' => [$ids[0]], 'source' => 'manual', 'types' => ['custom']],
        );
        $small = $counter->count();
        self::assertSame(
            str_repeat('Ж', 5000),
            $this->responseString('result', 'structuredContent', 'items', '0', 'description'),
        );
        self::assertSame([], $this->responseArray('result', 'structuredContent', 'items', '0', 'data'));
        $counter->reset();
        $this->call('list_events', ['treeId' => $this->treeId]);
        self::assertLessThanOrEqual($small + 1, $counter->count());
        self::assertSame(48, $this->responseInt('result', 'structuredContent', 'total'));
        self::assertLessThan(48, $this->responseInt('result', 'structuredContent', 'returned'));
        self::assertLessThanOrEqual(
            128 * 1024,
            strlen(json_encode($this->responseArray('result', 'structuredContent'), JSON_THROW_ON_ERROR)),
        );
        $this->responseString('result', 'structuredContent', 'nextCursor');
        $invalidSelections = [
            ['cursor' => 'invalid'],
            ['groups'    => ['bad']],
            ['source'    => 'bad'],
            ['types'     => ['bad']],
            ['personIds' => ['bad']],
        ];
        foreach ($invalidSelections as $invalid) {
            $this->call(
                isset($invalid['source']) || isset($invalid['types']) ? 'list_events' : 'list_people',
                ['treeId' => $this->treeId, ...$invalid],
            );
            self::assertTrue($this->responseArray('result')['isError']);
        }
        $this->call('list_people', ['treeId' => Uuid::v7()->toRfc4122()]);
        self::assertSame('TREE_NOT_FOUND', $this->responseString('result', 'structuredContent', 'code'));
        $this->call('list_relationships', ['treeId' => $this->treeId, 'kind' => 'marriages', 'personIds' => [$ids[2]]]);
        self::assertSame(0, $this->responseInt('result', 'structuredContent', 'total'));
    }

    public function testPlaceLookupUsesTheExistingGeocoderAndReportsUnavailableWithoutInventingCoordinates(): void
    {
        $this->authorize();
        $this->client->disableReboot();
        $cache = static::getContainer()->get('cache.map_geocoding');
        self::assertInstanceOf(\Psr\Cache\CacheItemPoolInterface::class, $cache);
        $cache->clear();
        $body = [
            'results' => [
                ['formatted' => 'Test city', 'lat' => 54.0, 'lon' => 83.0],
            ],
        ];
        $http = new MockHttpClient(
            [
                new MockResponse(json_encode($body, JSON_THROW_ON_ERROR)),
                new MockResponse('', ['http_code' => 503]),
            ],
        );
        static::getContainer()->set('geoapify.client', $http);
        $this->call('find_places', ['query' => 'Unique QA city']);
        self::assertSame('Test city', $this->responseString('result', 'structuredContent', 'items', '0', 'name'));
        $this->call('find_places', ['query' => 'Another unique QA city']);
        self::assertTrue($this->responseArray('result', 'structuredContent')['unavailable']);
        self::assertSame([], $this->responseArray('result', 'structuredContent', 'items'));
        $this->call('find_places', ['query' => '']);
        self::assertTrue($this->responseArray('result')['isError']);
    }

    /** @return list<string> */
    private function seedReadPeople(int $count): array
    {
        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $tree = static::getContainer()->get(FamilyTreeRepository::class)->find($this->treeId);
        self::assertInstanceOf(FamilyTree::class, $tree);
        $people = $ids = [];
        $now = new \DateTimeImmutable();
        for ($index = 0; $index < $count; ++$index) {
            $birthDate = 0 === $index
                ? GenealogicalDate::known(DateKind::BETWEEN, 1900, null, null, 1902)
                : GenealogicalDate::unknown();
            $person = new Person(
                $tree,
                KnownText::unknown(),
                KnownText::known('Person ' . $index),
                KnownText::unknown(),
                0 === $index ? Gender::FEMALE : Gender::MALE,
                $birthDate,
                null,
                $now,
            );
            $em->persist($person);
            $people[] = $person;
            $ids[] = $person->getId()->toRfc4122();
            if (0 === $index) {
                $place = EventPlace::imported('Historical place', null, null);
                $em->persist($place);
                $person->changeBirthPlace($place, $now);
                $tree->referencePerson($person);
            } else {
                $em->persist(new ParentLink($tree, $people[0], $person, ParentRole::MOTHER));
            }
            $event = GenealogicalEvent::manual(
                $person,
                EventType::CUSTOM,
                GenealogicalDate::unknown(),
                'A complete event',
                str_repeat('Ж', 5000),
                [],
                $now,
            );
            $em->persist($event);
        }
        $marriage = new MarriagePeriod(
            $tree,
            $people[1],
            $people[0],
            GenealogicalDate::unknown(),
            null,
            $now,
        );
        $em->persist($marriage);
        $tree->touch($now);
        $em->flush();

        return $ids;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->loginToken];
    }

    private function issuer(): string
    {
        return static::getContainer()->get(OAuthMetadata::class)->issuer();
    }

    private function resource(): string
    {
        return static::getContainer()->get(OAuthMetadata::class)->resource();
    }

    /** @return array<string, string> */
    private function parameters(): array
    {
        return [
            'response_type'  => 'code',
            'client_id'      => $this->clientId,
            'redirect_uri'   => 'http://localhost:6274/oauth/callback',
            'code_challenge' => rtrim(
                strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'),
                '=',
            ),
            'code_challenge_method' => 'S256',
            'resource'              => $this->resource(),
            'scope'                 => 'mcp',
            'state'                 => 'opaque-client-state',
        ];
    }

    private function consent(): string
    {
        $this->jsonRequest('POST', '/api/v1/oauth/authorization-requests', $this->parameters(), $this->headers());
        self::assertResponseStatusCodeSame(201);

        return $this->responseString('id');
    }

    private function code(): string
    {
        $id = $this->consent();
        $this->jsonRequest(
            'POST',
            '/api/v1/oauth/authorization-requests/' . $id . '/decision',
            ['approved' => true],
            $this->headers(),
        );
        self::assertResponseIsSuccessful();
        parse_str((string)parse_url($this->responseString('redirectUrl'), PHP_URL_QUERY), $query);
        self::assertIsString($query['code'] ?? null);

        return $query['code'];
    }

    private function authorize(): void
    {
        $this->token(
            [
                'grant_type'    => 'authorization_code',
                'code'          => $this->code(),
                'code_verifier' => self::VERIFIER,
                'redirect_uri'  => 'http://localhost:6274/oauth/callback',
            ],
        );
        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(899, $this->responseInt('expires_in'));
        self::assertLessThanOrEqual(900, $this->responseInt('expires_in'));
        $this->accessToken = $this->responseString('access_token');
        $this->refreshToken = $this->responseString('refresh_token');
    }

    /** @param array<string, string> $parameters */
    private function token(array $parameters, string $path = '/api/v1/oauth/token'): void
    {
        $this->client->request(
            'POST',
            'https://localhost' . $path,
            array_replace(['client_id' => $this->clientId, 'resource' => $this->resource()], $parameters),
            server: ['HTTPS' => 'on', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
        );
    }

    /** @param array<string, mixed> $arguments */
    private function call(string $name, array $arguments): void
    {
        $this->mcp('tools/call', ['name' => $name, 'arguments' => $arguments]);
    }

    /** @param array<string, mixed> $parameters */
    private function mcp(string $method, array $parameters = []): void
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/mcp',
            [
                'jsonrpc' => '2.0',
                'id'      => 1,
                'method'  => $method,
                'params'  => [
                    ...$parameters,
                    '_meta' => [
                        'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
                        'io.modelcontextprotocol/clientCapabilities' => (object)$this->clientCapabilities,
                    ],
                ],
            ],
            [
                'Authorization'        => 'Bearer ' . $this->accessToken,
                'Accept'               => 'application/json, text/event-stream',
                'MCP-Protocol-Version' => '2026-07-28',
                'Mcp-Method'           => $method,
                'Mcp-Name'             => is_string($parameters['name'] ?? null) ? $parameters['name'] : '',
            ],
        );
    }
}
