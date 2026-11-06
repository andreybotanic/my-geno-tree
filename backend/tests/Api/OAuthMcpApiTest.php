<?php

namespace App\Tests\Api;

use App\Application\OAuth\OAuthMetadata;
use App\Entity\User;
use App\Repository\FamilyTreeRepository;
use App\Tests\Infrastructure\Doctrine\QueryCountingMiddleware;
use Symfony\Component\Uid\Uuid;

final class OAuthMcpApiTest extends ApiWebTestCase
{
    private string $loginToken;
    private string $clientId;
    private string $treeId;
    private string $etag;
    private string $accessToken;
    private string $refreshToken;
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
            ['find_trees', 'get_tree_context', 'find_people', 'get_person_context', 'merge'],
            array_keys($tools),
        );
        self::assertTrue($tools['find_trees']['annotations']['readOnlyHint']);
        self::assertFalse($tools['merge']['annotations']['readOnlyHint']);
        self::assertArrayHasKey('idempotentHint', $tools['merge']['annotations']);
        self::assertSame(false, $tools['merge']['annotations']['idempotentHint'] ?? null);
        self::assertStringContainsString('APPLY', $tools['merge']['description']);
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
        foreach (['Романова', 'Романов', 'Романовы II', 'Семья Ёлкиных', 'Smith', 'Smyth'] as $name) {
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
        self::assertSame('Семья Ёлкиных', $this->responseString('result', 'structuredContent', 'trees', '0', 'name'));
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

    public function testMergeIsAtomicUsesExternalSourcesAndRejectsStaleOrInvalidProposals(): void
    {
        $this->authorize();
        $this->call('get_tree_context', ['treeId' => $this->treeId]);
        self::assertSame($this->etag, $this->responseString('result', 'structuredContent', 'etag'));
        $proposal = [
            'people' => [
                [
                    'id'               => 'anna',
                    'existingPersonId' => null,
                    'resolution'       => 'new',
                    'fields'           => [
                        'givenName'  => ['state' => 'known', 'value' => 'Анна'],
                        'patronymic' => ['state' => 'known', 'value' => 'Павловна'],
                        'gender'     => 'female',
                    ],
                    'lifeStatus' => 'alive',
                    'evidence'   => [['messageId' => 'source', 'quote' => 'Анна Павловна']],
                ],
            ],
            'parents'   => [],
            'marriages' => [],
            'questions' => [],
        ];
        $arguments = [
            'treeId'   => $this->treeId,
            'ifMatch'  => $this->etag,
            'proposal' => $proposal,
            'sources'  => [['id' => 'source', 'role' => 'user', 'content' => 'Анна Павловна']],
        ];
        $this->call('merge', $arguments);
        self::assertFalse($this->responseArray('result')['isError']);
        $anna = $this->responseString('result', 'structuredContent', 'personIds', 'anna');
        $nextEtag = $this->responseString('result', 'structuredContent', 'etag');
        self::assertNotSame($this->etag, $nextEtag);
        $this->call('merge', $arguments);
        self::assertSame('TREE_VERSION_CONFLICT', $this->responseString('result', 'structuredContent', 'code'));
        $this->call('find_people', ['treeId' => $this->treeId, 'query' => 'Анна']);
        self::assertSame($anna, $this->responseString('result', 'structuredContent', 'candidates', '0', 'id'));
        $this->call('get_person_context', ['treeId' => $this->treeId, 'personId' => $anna]);
        self::assertSame($anna, $this->responseString('result', 'structuredContent', 'people', '0', 'id'));
        $arguments['ifMatch'] = $nextEtag;
        $arguments['sources'][0]['content'] = 'No matching evidence';
        $this->call('merge', $arguments);
        self::assertSame('TREE_MERGE_PROPOSAL_INVALID', $this->responseString('result', 'structuredContent', 'code'));
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', [], $this->headers());
        self::assertCount(1, $this->responseArray('people'));
        self::assertSame($nextEtag, $this->responseString('etag'));
        $source = 'Добавь Анне Павловне мужа Ивана.';
        $evidence = [['messageId' => 'marriage-request', 'quote' => $source]];
        $this->call(
            'merge',
            [
                'treeId'   => $this->treeId,
                'ifMatch'  => $nextEtag,
                'sources'  => [['id' => 'marriage-request', 'role' => 'user', 'content' => $source]],
                'proposal' => [
                    'people' => [
                        [
                            'id'               => 'anna',
                            'existingPersonId' => $anna,
                            'resolution'       => 'existing',
                            'fields'           => [],
                            'lifeStatus'       => 'alive',
                            'evidence'         => $evidence,
                        ],
                        [
                            'id'               => 'ivan',
                            'existingPersonId' => null,
                            'resolution'       => 'new',
                            'fields'           => [
                                'givenName' => ['state' => 'known', 'value' => 'Иван'],
                                'gender'    => 'male',
                            ],
                            'lifeStatus' => 'alive',
                            'evidence'   => $evidence,
                        ],
                    ],
                    'parents'   => [],
                    'marriages' => [
                        [
                            'first'           => 'ivan',
                            'second'          => 'anna',
                            'marriageDate'    => ['state' => 'unknown'],
                            'dissolutionDate' => null,
                            'newPeriod'       => false,
                            'evidence'        => $evidence,
                        ],
                    ],
                    'questions' => [],
                ],
            ],
        );
        self::assertFalse($this->responseArray('result')['isError']);
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', [], $this->headers());
        self::assertCount(2, $this->responseArray('people'));
        self::assertCount(1, $this->responseArray('marriages'));
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
                        'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
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
