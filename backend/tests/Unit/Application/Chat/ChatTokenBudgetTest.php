<?php

namespace App\Tests\Unit\Application\Chat;

use App\Api\ApiException;
use App\Application\Chat\Ai\ChatModelUsageListener;
use App\Application\Chat\ChatActivity;
use App\Application\Chat\ChatTokenBudget;
use App\Entity\FamilyTree;
use App\Entity\User;
use App\Model\Conversation;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Event\InvocationEvent;
use Symfony\AI\Platform\Event\ResultEvent;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

final class ChatTokenBudgetTest extends TestCase
{
    public function testCountsEveryModelStepOnceAndRejectsFurtherCallsAcrossDraftsOfTheSameUser(): void
    {
        $cache = new ArrayAdapter();
        $locks = new LockFactory(new InMemoryStore());
        $activity = new ChatActivity($cache, $locks, \App\Tests\Infrastructure\Ai\TestMergeEvents::publisher($this->createStub(\Doctrine\ORM\EntityManagerInterface::class)));
        $factory = new RateLimiterFactory(['id' => 'tokens', 'policy' => 'fixed_window', 'limit' => 100, 'interval' => '1 day'], new CacheStorage($cache), $locks);
        $budget = new ChatTokenBudget($factory, $activity);
        $now = new \DateTimeImmutable();
        $user = new User('test', 'test@example.test', 'hash', 'en', $now);
        $tree = new FamilyTree($user, 'tree', $now);
        $draft = \App\Tests\Infrastructure\Ai\ConversationFixture::create($user);
        $draft->beginAttempt($now, 'turn');
        self::assertSame(['remaining' => 100, 'limit' => 100], $budget->remaining($user->getId()));
        $result = new TextResult('fixture');
        $result->getMetadata()->add('token_usage', new TokenUsage(promptTokens: 30, completionTokens: 20, totalTokens: 50));
        $requests = new RequestStack();
        $request = new Request();
        $request->attributes->set(Conversation::class, $draft);
        $requests->push($request);
        $listener = new ChatModelUsageListener($budget, $requests);
        $events = new EventDispatcher();
        $events->addListener(InvocationEvent::class, $listener->beforeInvocation(...));
        $events->addListener(ResultEvent::class, $listener->onResult(...));
        $metered = MockPlatformFactory::createPlatform(static fn () => clone $result, eventDispatcher: $events);
        $first = $metered->invoke('model', 'classify');
        self::assertSame('fixture', $first->asText());
        $first->asText();
        self::assertSame(['input' => 30, 'output' => 20, 'total' => 50], $activity->read($draft)['usage']);
        self::assertSame(['remaining' => 50, 'limit' => 100], $budget->remaining($user->getId()));
        $metered->invoke('model', 'repair')->asText();
        self::assertSame(100, $activity->read($draft)['usage']['total']);
        self::assertSame(['remaining' => 0, 'limit' => 100], $budget->remaining($user->getId()));
        self::assertSame(100, $budget->remaining((new User('other', 'other@example.test', 'hash', 'en', $now))->getId())['remaining']);
        $otherDraft = \App\Tests\Infrastructure\Ai\ConversationFixture::create($user);
        $this->expectException(ApiException::class);
        $budget->assertAvailable($otherDraft);
    }

    public function testAlreadySpentOverBudgetTokensAreNotLostAndToolContextIsBounded(): void
    {
        $cache = new ArrayAdapter();
        $locks = new LockFactory(new InMemoryStore());
        $activity = new ChatActivity($cache, $locks, \App\Tests\Infrastructure\Ai\TestMergeEvents::publisher($this->createStub(\Doctrine\ORM\EntityManagerInterface::class)));
        $factory = new RateLimiterFactory(['id' => 'tokens', 'policy' => 'fixed_window', 'limit' => 100, 'interval' => '1 day'], new CacheStorage($cache), $locks);
        $budget = new ChatTokenBudget($factory, $activity);
        $now = new \DateTimeImmutable();
        $draft = \App\Tests\Infrastructure\Ai\ConversationFixture::create();
        $draft->beginAttempt($now, 'turn');
        $attempt = $draft->attemptId;
        self::assertNotNull($attempt);
        $budget->record($draft, 150, 70);
        self::assertSame(220, $activity->read($draft)['usage']['total']);
        self::assertLessThanOrEqual(0, $factory->create($draft->userId->toRfc4122())->consume(0)->getRemainingTokens());
        for ($i = 0; $i < 8; ++$i) {
            $activity->observed($draft->id->toRfc4122(), $attempt, 'tree_loaded', ['people' => []]);
        }
        self::assertSame(['tree_loaded'], $activity->read($draft)['progress']);
        $this->expectException(ApiException::class);
        $activity->observed($draft->id->toRfc4122(), $attempt, 'people_loaded', ['text' => str_repeat('x', 60001)]);
    }
}
