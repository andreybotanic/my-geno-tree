<?php

namespace App\Tests\Unit\Application\Chat;

use App\Api\ApiException;
use App\Application\Chat\Ai\CancellableChatHttpClient;
use App\Application\Chat\ChatActivity;
use App\Model\Conversation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class CancellableChatHttpClientTest extends TestCase
{
    public function testStopsInferenceDuringProgressAndDoesNotStartAnotherRequest(): void
    {
        $activity = new ChatActivity(new ArrayAdapter(), new LockFactory(new InMemoryStore()), \App\Tests\Infrastructure\Ai\TestMergeEvents::publisher($this->createStub(\Doctrine\ORM\EntityManagerInterface::class)));
        $now = new \DateTimeImmutable();
        $draft = \App\Tests\Infrastructure\Ai\ConversationFixture::create();
        $draft->beginAttempt($now, 'turn');
        $requests = new RequestStack();
        $request = new Request();
        $request->attributes->set(Conversation::class, $draft);
        $requests->push($request);
        $progress = null;
        $calls = 0;
        $http = new MockHttpClient(function ($method, $url, $options) use (&$progress, &$calls): MockResponse {
            ++$calls;
            $progress = $options['on_progress'];

            return new MockResponse('OK');
        });
        $client = new CancellableChatHttpClient($http, $activity, $requests);
        $observed = [];
        $client->request('POST', 'http://model.test', ['on_progress' => static function ($downloaded, $size, $info) use (&$observed): void {
            $observed = [$downloaded, $size, $info];
        }])->getContent();
        self::assertIsCallable($progress);
        $progress(12, 30, ['http_code' => 200]);
        self::assertSame([12, 30, ['http_code' => 200]], $observed);
        $activity->stop($draft, 'turn');
        foreach ([static fn () => $progress(15, 30, []), static fn () => $client->request('POST', 'http://model.test')] as $operation) {
            try {
                $operation();
                self::fail('Stopped inference must abort.');
            } catch (ApiException $error) {
                self::assertSame('CHAT_STOPPED', $error->getErrorCode());
            }
        }
        self::assertSame(1, $calls);
        $draft->beginAttempt($now, 'next');
        self::assertSame('OK', $client->request('POST', 'http://model.test')->getContent());
        $requests->pop();
        self::assertSame('OK', $client->request('GET', 'http://unrelated.test')->getContent());
        self::assertSame(3, $calls);
    }
}
