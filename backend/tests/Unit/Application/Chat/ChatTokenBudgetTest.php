<?php

namespace App\Tests\Unit\Application\Chat;

use App\Api\ApiException;
use App\Api\ConversationNormalizer;
use App\EventListener\ChatAgentHooks;
use App\Tests\Infrastructure\Ai\ChatRuntimeFixture;
use App\Tests\Infrastructure\Ai\TestMergeEvents;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Event\InvocationEvent;
use Symfony\AI\Platform\Event\ResultEvent;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ChatTokenBudgetTest extends TestCase
{
    public function testEachModelStepIsCountedOnceIncludingSpentTokensPastTheLimit(): void
    {
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        $runtime = new ChatRuntimeFixture($authorization);
        $factory = new RateLimiterFactory(
            ['id' => 'tokens', 'policy' => 'fixed_window', 'limit' => 100, 'interval' => '1 day'],
            new CacheStorage(new ArrayAdapter()),
            new LockFactory(new InMemoryStore())
        );
        $hooks = new ChatAgentHooks(
            $runtime->store,
            TestMergeEvents::publisher($authorization),
            $runtime->requests,
            $factory,
        );
        $view = new ConversationNormalizer($runtime->store, $factory);
        $events = new EventDispatcher();
        $events->addListener(InvocationEvent::class, $hooks->beforeModel(...));
        $events->addListener(ResultEvent::class, $hooks->onResult(...));
        $result = new TextResult('fixture');
        $result->getMetadata()->add(
            'token_usage',
            new TokenUsage(promptTokens: 30, completionTokens: 20, totalTokens: 50),
        );
        $metered = MockPlatformFactory::createPlatform(static fn () => clone $result, eventDispatcher: $events);
        self::assertSame(100, $view->budget($runtime->conversation->userId)['remaining']);
        $first = $metered->invoke('model', 'first');
        self::assertSame('fixture', $first->asText());
        $first->asText();
        self::assertSame(['input' => 30, 'output' => 20, 'total' => 50], $runtime->conversation->usage);
        self::assertSame(50, $view->budget($runtime->conversation->userId)['remaining']);
        $result->getMetadata()->set(
            ['token_usage' => new TokenUsage(promptTokens: 100, completionTokens: 70, totalTokens: 170)],
        );
        $metered->invoke('model', 'second')->asText();
        self::assertSame(220, $runtime->conversation->usage['total']);
        self::assertSame(0, $view->budget($runtime->conversation->userId)['remaining']);
        $this->expectException(ApiException::class);
        $metered->invoke('model', 'blocked')->asText();
    }
}
