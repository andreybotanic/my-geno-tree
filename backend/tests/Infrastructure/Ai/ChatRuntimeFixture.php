<?php

namespace App\Tests\Infrastructure\Ai;

use App\Application\Chat\GuardedMessageStore;
use App\Entity\AuthSession;
use App\Entity\User;
use App\Model\Conversation;
use App\Security\ChatExecution;
use Symfony\AI\Agent\Bridge\Cache\MessageStore;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final readonly class ChatRuntimeFixture
{
    public MessageStore $inner;
    public GuardedMessageStore $store;
    public RequestStack $requests;
    public Conversation $conversation;
    public ChatExecution $execution;

    public function __construct(AuthorizationCheckerInterface $authorization)
    {
        $now = new \DateTimeImmutable();
        $user = new User('test', 'test@example.test', 'hash', 'en', $now);
        $session = new AuthSession($user, 'hash', $now->modify('+15 minutes'), $now->modify('+1 day'), $now);
        $this->conversation = new Conversation('test', 'tree', [], $session->getId(), $user->getId(), $now);
        $this->conversation->beginAttempt($now, 'turn');
        $this->execution = new ChatExecution($this->conversation, $this->conversation->attemptId ?? throw new \LogicException());
        $request = new Request();
        $request->attributes->set(AuthSession::class, $session);
        $request->attributes->set(Conversation::class, $this->conversation);
        $request->attributes->set(ChatExecution::class, $this->execution);
        $this->requests = new RequestStack();
        $this->requests->push($request);
        $this->inner = new MessageStore(new ArrayAdapter());
        $messages = MessageBag::forId($this->conversation->id);
        $messages->getMetadata()->add(Conversation::METADATA, $this->conversation);
        $this->inner->save($messages);
        $this->store = new GuardedMessageStore(
            $this->inner,
            new ArrayAdapter(),
            new NativeClock(),
            new LockFactory(new InMemoryStore()),
            $this->requests,
            $authorization
        );
    }
}
