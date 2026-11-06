<?php

namespace App\Tests\Unit\Application\Chat;

use App\Api\ApiException;
use App\Application\Chat\Ai\CancellableChatHttpClient;
use App\Model\Conversation;
use App\Security\ChatExecution;
use App\Tests\Infrastructure\Ai\ChatRuntimeFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class CancellableChatHttpClientTest extends TestCase
{
    public function testStopsInferenceDuringProgressAndDoesNotStartAnotherRequest(): void
    {
        $runtime = new ChatRuntimeFixture($this->createStub(AuthorizationCheckerInterface::class));
        $progress = null;
        $calls = 0;
        $http = new MockHttpClient(static function ($method, $url, $options) use (&$progress, &$calls): MockResponse {
            ++$calls;
            $progress = $options['on_progress'];

            return new MockResponse('OK');
        });
        $client = new CancellableChatHttpClient($http, $runtime->store, $runtime->requests);
        $observed = [];
        $client
        ->request('POST', 'http://model.test', [
            'on_progress' => static function ($downloaded, $size, $info) use (&$observed): void {
                $observed = [$downloaded, $size, $info];
            },
        ])
        ->getContent();
        self::assertIsCallable($progress);
        $progress(12, 30, ['http_code' => 200]);
        self::assertSame([12, 30, ['http_code' => 200]], $observed);
        $messages = $runtime->inner->load($runtime->conversation->id);
        $state = $messages->getMetadata()->get(Conversation::METADATA);
        self::assertInstanceOf(Conversation::class, $state);
        $state->finishAttempt(new \DateTimeImmutable());
        $runtime->inner->save($messages);
        foreach ([static fn () => $progress(15, 30, []), static fn () => $client->request('POST', 'http://model.test')] as $operation) {
            try {
                $operation();
                self::fail('Stopped inference must abort.');
            } catch (ApiException $error) {
                self::assertSame('CHAT_VERSION_CONFLICT', $error->getErrorCode());
            }
        }
        self::assertSame(1, $calls);
        $request = $runtime->requests->getMainRequest();
        self::assertNotNull($request);
        $request->attributes->remove(ChatExecution::class);
        self::assertSame('OK', $client->request('GET', 'http://unrelated.test')->getContent());
        self::assertSame(2, $calls);
    }
}
