<?php

namespace App\Tests\Api\Tree;

use App\Model\Conversation;
use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Ai\FixtureTreeAgent;
use App\Tests\Infrastructure\Ai\TestMergeEvents;

final class ChatEventsApiTest extends ApiWebTestCase
{
    public function testSubscriberCookieIsRejectedImmediatelyAfterLogoutAndWhenForgedOrExpired(): void
    {
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        $headers = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Revoked stream'], $headers);
        $base = '/api/v1/trees/' . $this->responseString('id') . '/chat';
        $this->jsonRequest('POST', $base, ['referencePersonId' => null], $headers);
        $path = '/api/v1/chats/' . $this->responseString('id');
        $this->jsonRequest('GET', '/api/v1/chat-events/authorize');
        self::assertResponseStatusCodeSame(401);
        $this->jsonRequest('POST', $path . '/events', [], $headers);
        $cookie = array_find(
            $this->client->getResponse()->headers->getCookies(),
            static fn ($cookie) => '__Secure-mercure_access_token' === $cookie->getName(),
        );
        self::assertNotNull($cookie);
        $value = (string)$cookie->getValue();
        $this->jsonRequest('GET', '/api/v1/chat-events/authorize', headers: $headers);
        self::assertResponseStatusCodeSame(401);
        $this->jsonRequest('GET', $path, headers: ['Authorization' => 'Bearer ' . $value]);
        self::assertResponseStatusCodeSame(401);
        // Caddy forwards the subscriber cookie as a Bearer credential to Symfony's access_token firewall.
        $this->jsonRequest('GET', '/api/v1/chat-events/authorize', headers: ['Authorization' => 'Bearer ' . $value]);
        self::assertResponseStatusCodeSame(204);
        $this->jsonRequest(
            'GET',
            '/api/v1/chat-events/authorize',
            headers: ['Authorization' => 'Bearer invalid.token.signature'],
        );
        self::assertResponseStatusCodeSame(401);
        $authorization = static::getContainer()->get(\Symfony\Component\Mercure\Authorization::class);
        /** @var array{sub: string} $issuedClaims */
        $issuedClaims = json_decode(
            (string)base64_decode(strtr(explode('.', $value)[1], '-_', '+/')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ([
            ['exp' => null],
            ['sub' => 'not-a-session'],
            ['sub' => \Symfony\Component\Uid\Uuid::v7()->toRfc4122()],
            ['iss' => 'https://another-issuer.example'],
            ['aud' => ['https://another-hub.example/.well-known/mercure']],
            ['nbf' => new \DateTimeImmutable('+1 minute')],
        ] as $claims) {
            $invalid = $authorization->createCookie(
                \Symfony\Component\HttpFoundation\Request::create('https://localhost'),
                [],
                additionalClaims: array_replace(['sub' => $issuedClaims['sub']], $claims),
            );
            $this->jsonRequest(
                'GET',
                '/api/v1/chat-events/authorize',
                headers: ['Authorization' => 'Bearer ' . $invalid->getValue()],
            );
            self::assertResponseStatusCodeSame(401);
            self::assertSame('UNAUTHORIZED', $this->responseString('code'));
        }
        $expired = $authorization->createCookie(
            \Symfony\Component\HttpFoundation\Request::create('https://localhost'),
            [],
            additionalClaims: ['exp' => new \DateTimeImmutable('-1 minute')]
        );
        $this->jsonRequest(
            'GET',
            '/api/v1/chat-events/authorize',
            headers: ['Authorization' => 'Bearer ' . (string)$expired->getValue()],
        );
        self::assertResponseStatusCodeSame(401);
        $this->jsonRequest('POST', '/api/v1/auth/logout', [], $headers);
        self::assertResponseStatusCodeSame(204);
        self::assertNotNull(
            array_find(
                $this->client->getResponse()->headers->getCookies(),
                static fn ($cookie) => '__Secure-mercure_access_token' === $cookie->getName()
                    && 1 === $cookie->getExpiresTime(),
            ),
        );
        $this->jsonRequest('GET', '/api/v1/chat-events/authorize', headers: ['Authorization' => 'Bearer ' . $value]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testRevokedSessionDoesNotReceiveLatePrivateEvents(): void
    {
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        $headers = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Private events'], $headers);
        $base = '/api/v1/trees/' . $this->responseString('id') . '/chat';
        $this->jsonRequest('POST', $base, ['referencePersonId' => null], $headers);
        $path = '/api/v1/chats/' . $this->responseString('id');
        FixtureTreeAgent::$handler = static function (Conversation $conversation): string {
            $container = static::getContainer();
            $request = $container->get(\Symfony\Component\HttpFoundation\RequestStack::class)->getMainRequest();
            self::assertNotNull($request);
            $session = $request->attributes->get(\App\Entity\AuthSession::class);
            self::assertInstanceOf(\App\Entity\AuthSession::class, $session);
            $session->revoke(new \DateTimeImmutable());
            $container->get(\Doctrine\ORM\EntityManagerInterface::class)->flush();
            TestMergeEvents::$updates = [];
            $container->get(\App\Application\Chat\ChatEventPublisherInterface::class)->conversation(
                $conversation,
                ['id' => $conversation->id->toRfc4122(), 'messages' => ['private']],
            );

            return 'Late private answer.';
        };
        try {
            $this->jsonRequest(
                'POST',
                $path . '/messages',
                ['version' => 1, 'requestId' => 'turn', 'content' => 'My family'],
                $headers,
            );
            self::assertSame([], TestMergeEvents::$updates);
            $messages = $this->responseArray('messages');
            self::assertCount(1, $messages);
        } finally {
            FixtureTreeAgent::$handler = null;
        }
    }

    public function testSubscriptionCookieAndEventsArePrivateAndLoginSessionScoped(): void
    {
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        $headers = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Stream test'], $headers);
        $base = '/api/v1/trees/' . $this->responseString('id') . '/chat';
        $this->jsonRequest('POST', $base, ['referencePersonId' => null], $headers);
        $path = '/api/v1/chats/' . $this->responseString('id');
        $version = $this->responseData()['version'];
        TestMergeEvents::$updates = [];
        $this->jsonRequest('POST', $path . '/events', [], $headers);
        self::assertResponseIsSuccessful();
        $url = $this->responseString('url');
        self::assertStringStartsWith('/.well-known/mercure?match=', $url);
        self::assertSame(300, $this->responseData()['expiresIn']);
        self::assertStringContainsString(
            'no-store',
            (string)$this->client->getResponse()->headers->get('Cache-Control'),
        );
        $cookie = array_find(
            $this->client->getResponse()->headers->getCookies(),
            static fn ($cookie) => '__Secure-mercure_access_token' === $cookie->getName(),
        );
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('strict', $cookie->getSameSite());
        self::assertNull($cookie->getDomain());
        self::assertSame('/.well-known/mercure', $cookie->getPath());
        /**
         * @var array{
         *     authorization_details: list<array{
         *         actions: list<string>,
         *         topics: mixed
         *     }>,
         *     iss: string,
         *     aud: mixed,
         *     exp: float
         * } $claims
         */
        $claims = json_decode(
            (string)base64_decode(strtr(explode('.', (string)$cookie->getValue())[1], '-_', '+/')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(['subscribe'], $claims['authorization_details'][0]['actions']);
        $topic = rawurldecode(substr($url, strlen('/.well-known/mercure?match=')));
        self::assertSame(
            [['match' => substr($topic, 0, strrpos($topic, '/') + 1) . '*', 'match_type' => 'urlpattern']],
            $claims['authorization_details'][0]['topics'],
        );
        self::assertSame('https://my-geno-tree.localhost', $claims['iss']);
        self::assertSame(['https://my-geno-tree.localhost/.well-known/mercure'], (array)$claims['aud']);
        self::assertLessThanOrEqual(microtime(true) + 300, $claims['exp']);
        self::assertSame([], TestMergeEvents::$updates);
        $this->jsonRequest('GET', $path, headers: $headers);
        self::assertSame([], TestMergeEvents::$updates);
        FixtureTreeAgent::$handler = static fn (Conversation $draft) => FixtureTreeAgent::reply(
            $draft,
            'Please describe your family.',
        );
        try {
            $this->jsonRequest(
                'POST',
                $path . '/messages',
                ['version' => $version, 'requestId' => 'stream', 'content' => 'My family'],
                $headers,
            );
        } finally {
            FixtureTreeAgent::$handler = null;
        }
        self::assertResponseIsSuccessful();
        /** @var list<array<string, mixed>> $packets */
        $packets = array_map(
            static fn ($update) => json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR),
            TestMergeEvents::updates(),
        );
        self::assertSame('progress', $packets[1]['kind']);
        self::assertSame(['model_request'], $packets[1]['progress']);
        self::assertSame('conversation', $packets[2]['kind']);
        $response = $this->responseData();
        self::assertIsArray($packets[2]['conversation']);
        self::assertIsArray($packets[2]['conversation']['access']);
        $response['access'] = $this->responseArray('access');
        $response['access']['serverTime'] = $packets[2]['conversation']['access']['serverTime'];
        self::assertSame($response, $packets[2]['conversation']);
        self::assertIsArray($packets[0]['conversation']);
        self::assertSame('processing', $packets[0]['conversation']['status']);
        foreach (TestMergeEvents::updates() as $event) {
            self::assertSame([$topic], $event->getTopics());
            self::assertTrue($event->isPrivate());
        }
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/login',
            ['identifier' => 'family_admin', 'password' => 'StrongPass1!'],
        );
        $otherSession = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', $path . '/events', [], $otherSession);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('GET', $path, headers: $otherSession);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('POST', $path . '/events');
        self::assertResponseStatusCodeSame(401);
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration(['username' => 'outsider', 'email' => 'outsider@example.test']),
        );
        $outsider = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', $path . '/events', [], $outsider);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Another tree'], $headers);
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->responseString('id') . '/chat', headers: $headers);
        self::assertNull($this->responseData()['conversation']);
        self::assertCount(3, TestMergeEvents::$updates);
    }
}
