<?php

namespace App\Tests\Infrastructure\Ai;

use App\Application\Chat\ChatInterface;
use App\Model\Conversation;
use App\Tool\TreeMergeTool;
use App\Tool\TreeReadTools;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Result as ResultUpdate;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\DependencyInjection\Attribute\When;

#[When('test')]
#[AsDecorator('ai.agent.tree')]
final class FixtureTreeAgent implements AgentInterface
{
    public static ?\Closure $handler = null;
    public static bool $useReal = false;
    public static int $calls = 0;
    public static TreeReadTools $reads;
    private static TreeMergeTool $merge;
    private static ChatInterface $chat;

    public function __construct(
        #[AutowireDecorated]
        private readonly AgentInterface $inner,
        TreeMergeTool $merge,
        TreeReadTools $reads,
        ChatInterface $chat,
        #[Target('chat')]
        private readonly MessageStoreInterface $store,
    ) {
        self::$merge = $merge;
        self::$reads = $reads;
        self::$chat = $chat;
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }

    /** @param array<string, mixed> $options */
    public function call(string|MessageBag|UserMessage $input, array $options = []): ExecutionInterface
    {
        if (self::$useReal) {
            return $this->inner->call($input, $options);
        }

        return new Execution(
            function (): \Generator {
                ++self::$calls;
                yield new \Symfony\AI\Agent\Execution\Update\Progress(
                    \Symfony\AI\Agent\Execution\Update\Progress::STAGE_MODEL_REQUEST,
                );
                $conversation = self::$chat->execution()->conversation;
                $text = null === self::$handler ? 'Fixture reply.' : (string)(self::$handler)(
                    $conversation,
                    $conversation->attemptId,
                    self::$merge,
                    self::$reads,
                );
                self::$chat->execution()->conversation;
                if ('' !== trim($text)) {
                    $message = Message::ofAssistant($text);
                    $message->getMetadata()->add('requestId', $conversation->attemptRequestId);
                    $this->store->save($this->store->load($conversation->id)->with($message));
                }

                yield new ResultUpdate(new TextResult($text));
            },
        );
    }

    /**
     * @return list<array{
     *     id: string,
     *     requestId: string,
     *     role: string,
     *     content: string,
     *     createdAt: string,
     *     failureCode?: string
     * }>
     */
    public static function messages(Conversation $conversation): array
    {
        return self::$chat->messages($conversation);
    }

    /** @param array<string, mixed>|null $proposal */
    public static function reply(Conversation $conversation, string $reply, ?array $proposal = null): string
    {
        if (null !== $proposal) {
            $result = (self::$merge)($conversation->context['treeId'] ?? '', $proposal);
            if (isset($result['error'])) {
                throw new \LogicException(json_encode($result, JSON_THROW_ON_ERROR));
            }
        }

        return $reply;
    }
}
