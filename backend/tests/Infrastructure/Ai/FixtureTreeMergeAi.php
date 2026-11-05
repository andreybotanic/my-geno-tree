<?php

namespace App\Tests\Infrastructure\Ai;

use App\Application\TreeMerge\Ai\TreeMergeAiInterface;
use App\Entity\TreeMergeDraft;
use App\Mcp\TreeMergeAgentTools;
use App\Mcp\TreeReadTools;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\When;

#[When('test')]
#[AsAlias(TreeMergeAiInterface::class, public: true)]
final class FixtureTreeMergeAi implements TreeMergeAiInterface
{
    public static ?\Closure $handler = null;
    public static int $calls = 0;
    private static TreeMergeAgentTools $tools;
    private static \App\Application\TreeMerge\TreeMergeChat $chat;

    public function __construct(TreeMergeAgentTools $tools, private readonly TreeReadTools $reads, \App\Application\TreeMerge\TreeMergeChat $chat,
        #[\Symfony\Component\DependencyInjection\Attribute\Target('tree_merge')] private readonly \Symfony\AI\Agent\MessageStore\MessageStoreInterface $store,
        private readonly \App\Application\TreeMerge\TreeMergeDraftService $drafts)
    {
        self::$tools = $tools;
        self::$chat = $chat;
    }

    /** @return list<array{id: string, requestId: string, role: string, content: string, createdAt: string, failureCode?: string}> */
    public static function messages(TreeMergeDraft $draft): array
    {
        return self::$chat->messages($draft);
    }

    public function respond(TreeMergeDraft $draft): string
    {
        ++self::$calls;
        if (null === self::$handler) {
            throw new \LogicException('Missing AI fixture');
        }

        $text = (string)(self::$handler)($draft, $draft->attemptId, self::$tools, $this->reads);
        $this->drafts->active($draft->tree->getId()->toRfc4122());
        if ('' !== trim($text) && !array_any(self::$chat->messages($draft), static fn ($message) => 'assistant' === $message['role'] && $draft->attemptRequestId === $message['requestId'])) {
            $message = \Symfony\AI\Platform\Message\Message::ofAssistant($text);
            $message->getMetadata()->add('requestId', $draft->attemptRequestId);
            $this->store->save($this->store->load($draft->conversationId)->with($message));
        }

        return $text;
    }

    /** @param array<string, mixed>|null $proposal */
    public static function reply(TreeMergeDraft $draft, string $reply, ?array $proposal = null): string
    {
        if (null !== $proposal) {
            $result = self::$tools->merge($draft->tree->getId()->toRfc4122(), $proposal);
            if (isset($result['error'])) {
                throw new \LogicException(json_encode($result, JSON_THROW_ON_ERROR));
            }
        }

        return $reply;
    }
}
