<?php

namespace App\Tests\Unit\Application\Chat;

use App\Application\Chat\MercureChatEventPublisher;
use App\Security\ChatVoter;
use App\Tests\Infrastructure\Ai\ConversationFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class MercureChatEventPublisherTest extends TestCase
{
    public function testProgressIsPrivateAndPublishedOnlyWhenTheVoterAllowsIt(): void
    {
        /** @var \ArrayObject<int, Update> $updates */
        $updates = new \ArrayObject();
        $hub = new MockHub(
            'https://localhost/.well-known/mercure',
            new StaticTokenProvider('test'),
            static function (Update $update) use ($updates): string {
                $updates[] = $update;

                return 'event';
            },
        );
        $conversation = ConversationFixture::create();
        $conversation->beginAttempt(new \DateTimeImmutable(), 'turn');
        $conversation->progress = ['model_request', 'get_tree_context'];
        $conversation->usage = ['input' => 10, 'output' => 5, 'total' => 15];
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization
            ->expects($this->exactly(2))
            ->method('isGranted')
            ->with(ChatVoter::USE, $conversation)
            ->willReturnOnConsecutiveCalls(false, true);
        $events = new MercureChatEventPublisher(
            $hub,
            $authorization,
            new NullLogger(),
            'https://localhost/api/v1/chat-events/sessions/{sessionId}/conversations/',
        );
        $events->progress($conversation);
        self::assertCount(0, $updates);
        $events->progress($conversation);
        self::assertCount(1, $updates);
        self::assertNotNull($updates[0]);
        self::assertTrue($updates[0]->isPrivate());
        self::assertSame(
            [
                'https://localhost/api/v1/chat-events/sessions/' . $conversation->sessionId->toRfc4122()
                    . '/conversations/'
                    . $conversation->id->toRfc4122(),
            ],
            $updates[0]->getTopics(),
        );
        /** @var array{attemptId: string, version: int, tokenUsage: array{total: int}} $packet */
        $packet = json_decode($updates[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($conversation->attemptId, $packet['attemptId']);
        self::assertSame($conversation->version, $packet['version']);
        self::assertSame(15, $packet['tokenUsage']['total']);
        self::assertArrayNotHasKey('contexts', $packet);
        $events->conversation($conversation, []);
        self::assertCount(1, $updates);
    }

    public function testHubFailureIsLoggedWithoutUndoingAnApplicationOperation(): void
    {
        $hub = new MockHub(
            'https://localhost/.well-known/mercure',
            new StaticTokenProvider('test'),
            static fn () => throw new \RuntimeException('private diagnostics'),
        );
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(3))->method('warning')->with('Chat event delivery failed: RuntimeException');
        $events = new MercureChatEventPublisher(
            $hub,
            $authorization,
            $logger,
            'https://localhost/api/v1/chat-events/sessions/{sessionId}/conversations/',
        );
        $conversation = ConversationFixture::create();
        $events->conversation($conversation, ['id' => $conversation->id->toRfc4122()]);
        $events->removed($conversation);
        $events->toolApplied($conversation, 'merge', 'operation', ['affectedPersonIds' => ['person']]);
    }
}
