<?php

namespace App\Tests\Application\OAuth;

use App\Application\OAuth\OAuthClientRegistration;
use App\Tests\Api\ApiWebTestCase;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OAuthClientRegistrationTest extends ApiWebTestCase
{
    public function testCimdMetadataCreatesAndUpdatesAUrlIdentifiedPublicClient(): void
    {
        $id = 'https://8.8.8.8/agent/client-metadata.json';
        $responses = [
            new MockResponse(
                json_encode(
                    [
                        'client_id'     => $id,
                        'client_name'   => 'First name',
                        'redirect_uris' => ['https://agent.example/callback'],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
            new MockResponse(
                json_encode(
                    [
                        'client_id'     => $id,
                        'client_name'   => 'Updated name',
                        'redirect_uris' => ['https://agent.example/callback'],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        ];
        $service = new OAuthClientRegistration(
            static::getContainer()->get(ClientManagerInterface::class),
            new MockHttpClient($responses),
        );
        $client = $service->resolve($id);
        self::assertSame($id, $client->getIdentifier());
        self::assertSame('First name', $client->getName());
        self::assertFalse($client->isConfidential());
        self::assertSame('Updated name', $service->resolve($id)->getName());
    }

    public function testCimdRejectsInvalidOversizedRedirectedAndPrivateDocuments(): void
    {
        foreach ([
            ['https://8.8.8.8/client#fragment', new MockResponse('{}')],
            ['https://user@8.8.8.8/client', new MockResponse('{}')],
            ['https://127.0.0.1/client', new MockResponse('{}')],
            ['https://8.8.8.8/client', new MockResponse('{}')],
            [
                'https://8.8.8.8/client',
                new MockResponse(
                    '{}',
                    ['http_code' => 302, 'response_headers' => ['Location: https://127.0.0.1/client']],
                ),
            ],
            ['https://8.8.8.8/client', new MockResponse(str_repeat('a', 65537))],
            ['https://8.8.8.8/client', new MockResponse('not-json')],
        ] as [$id, $response]) {
            $service = new OAuthClientRegistration(
                static::getContainer()->get(ClientManagerInterface::class),
                new MockHttpClient($response),
            );
            try {
                $service->resolve($id);
                self::fail('Invalid CIMD metadata was accepted.');
            } catch (OAuthServerException $error) {
                self::assertSame('invalid_request', $error->getErrorType());
            }
        }
    }

    public function testConfidentialRegistrationReturnsSecretOnceAndPersistsItsHash(): void
    {
        $clients = static::getContainer()->get(ClientManagerInterface::class);
        $service = new OAuthClientRegistration($clients, new MockHttpClient());
        foreach (['client_secret_post', 'client_secret_basic'] as $method) {
            $registered = $service->register(
                [
                    'client_name'                => 'Secret client',
                    'redirect_uris'              => ['https://agent.example/callback'],
                    'token_endpoint_auth_method' => $method,
                ],
            );
            self::assertIsString($registered['client_id']);
            self::assertIsString($registered['client_secret']);
            $client = $clients->find($registered['client_id']);
            self::assertNotNull($client);
            self::assertTrue($client->isConfidential());
            self::assertNotSame($registered['client_secret'], $client->getSecret());
            $repository = static::getContainer()->get(ClientRepositoryInterface::class);
            self::assertTrue(
                $repository->validateClient(
                    $registered['client_id'],
                    $registered['client_secret'],
                    'authorization_code',
                ),
            );
            self::assertFalse(
                $repository->validateClient($registered['client_id'], 'incorrect', 'authorization_code'),
            );
        }
    }
}
