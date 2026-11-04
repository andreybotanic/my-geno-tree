<?php

namespace App\Tests\Api\Tree;

use App\Entity\TreeMergeDraft;
use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Ai\FixtureTreeMergeAi;
use App\Tests\Infrastructure\Ai\TestMergeEvents;

final class TreeMergeEventsApiTest extends ApiWebTestCase
{
    public function testSubscriptionCookieAndEventsArePrivateAndLoginSessionScoped(): void
    {
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        $headers = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Stream test'], $headers);
        $base = '/api/v1/trees/' . $this->responseString('id') . '/merges';
        $this->jsonRequest('POST', $base, ['referencePersonId' => null], $headers);
        $path = $base . '/' . $this->responseString('id');
        $version = $this->responseData()['version'];
        TestMergeEvents::$updates = [];
        $this->jsonRequest('POST', $path . '/events', [], $headers);
        self::assertResponseIsSuccessful();
        $url = $this->responseString('url');
        self::assertStringStartsWith('/.well-known/mercure?match=', $url);
        self::assertSame(300, $this->responseData()['expiresIn']);
        self::assertStringContainsString('no-store', (string)$this->client->getResponse()->headers->get('Cache-Control'));
        $cookie = array_find($this->client->getResponse()->headers->getCookies(), static fn ($cookie) => '__Secure-mercure_access_token' === $cookie->getName());
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('strict', $cookie->getSameSite());
        self::assertNull($cookie->getDomain());
        self::assertSame('/.well-known/mercure', $cookie->getPath());
        /** @var array{authorization_details: list<array{actions: list<string>, topics: mixed}>, iss: string, aud: mixed, exp: float} $claims */
        $claims = json_decode((string)base64_decode(strtr(explode('.', (string)$cookie->getValue())[1], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['subscribe'], $claims['authorization_details'][0]['actions']);
        $topic = rawurldecode(substr($url, strlen('/.well-known/mercure?match=')));
        self::assertSame([['match' => substr($topic, 0, strrpos($topic, '/') + 1) . '*', 'match_type' => 'urlpattern']], $claims['authorization_details'][0]['topics']);
        self::assertSame('https://my-geno-tree.localhost', $claims['iss']);
        self::assertSame(['https://my-geno-tree.localhost/.well-known/mercure'], (array)$claims['aud']);
        self::assertLessThanOrEqual(microtime(true) + 300, $claims['exp']);
        self::assertSame([], TestMergeEvents::$updates);
        $this->jsonRequest('GET', $path, headers: $headers);
        self::assertSame([], TestMergeEvents::$updates);
        $this->jsonRequest('POST', $path . '/messages', ['version' => $version, 'requestId' => 'stream', 'content' => 'My family'], $headers);
        self::assertResponseIsSuccessful();
        self::assertCount(1, TestMergeEvents::$updates);
        $update = TestMergeEvents::updates()[0];
        self::assertTrue($update->isPrivate());
        self::assertSame([$topic], $update->getTopics());
        self::assertSame(['kind' => 'draft', 'draft' => $this->responseData()], json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR));
        $version = $this->responseData()['version'];
        FixtureTreeMergeAi::$handler = static fn (TreeMergeDraft $draft) => FixtureTreeMergeAi::reply($draft, 'Please describe your family.');
        try {
            $this->jsonRequest('POST', $path . '/process', ['version' => $version, 'requestId' => 'stream'], $headers);
        } finally {
            FixtureTreeMergeAi::$handler = null;
        }
        self::assertResponseIsSuccessful();
        /** @var list<array<string, mixed>> $packets */
        $packets = array_map(static fn ($update) => json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR), TestMergeEvents::updates());
        self::assertSame('progress', $packets[1]['kind']);
        self::assertSame(['thinking'], $packets[1]['progress']);
        self::assertSame('draft', $packets[2]['kind']);
        self::assertSame('draft', $packets[3]['kind']);
        self::assertSame($this->responseData(), $packets[3]['draft']);
        foreach (TestMergeEvents::updates() as $event) {
            self::assertSame([$topic], $event->getTopics());
            self::assertTrue($event->isPrivate());
        }
        $this->jsonRequest('POST', '/api/v1/auth/login', ['identifier' => 'family_admin', 'password' => 'StrongPass1!']);
        $otherSession = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', $path . '/events', [], $otherSession);
        self::assertResponseIsSuccessful();
        self::assertNotSame($url, $this->responseString('url'));
        $this->jsonRequest('GET', $path, headers: $otherSession);
        self::assertSame([], $this->responseData()['messages']);
        $this->jsonRequest('POST', $path . '/events');
        self::assertResponseStatusCodeSame(401);
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration(['username' => 'outsider', 'email' => 'outsider@example.test']));
        $outsider = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', $path . '/events', [], $outsider);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Another tree'], $headers);
        $this->jsonRequest('POST', '/api/v1/trees/' . $this->responseString('id') . '/merges/' . basename($path) . '/events', [], $headers);
        self::assertResponseStatusCodeSame(404);
        self::assertCount(4, TestMergeEvents::$updates);
    }
}
