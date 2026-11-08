<?php

namespace App\Tests\Infrastructure\Ai;

use App\Application\Chat\ChatInterface;
use App\Application\Chat\Moderation\ModerationInputProcessor;
use App\Model\Conversation;
use App\Tool\TreeReadTools;
use App\Tool\TreeWriteTools;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Result as ResultUpdate;
use Symfony\AI\Agent\Input;
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
    private static TreeWriteTools $writes;
    private static ChatInterface $chat;

    public function __construct(
        #[AutowireDecorated]
        private readonly AgentInterface $inner,
        TreeWriteTools $writes,
        TreeReadTools $reads,
        ChatInterface $chat,
        #[Target('chat')]
        private readonly MessageStoreInterface $store,
        private readonly ModerationInputProcessor $moderation,
    ) {
        self::$writes = $writes;
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
                $conversation = self::$chat->execution()->conversation;
                $this->moderation->processInput(new Input('fixture', $this->store->load($conversation->id)));
                ++self::$calls;
                yield new \Symfony\AI\Agent\Execution\Update\Progress(
                    \Symfony\AI\Agent\Execution\Update\Progress::STAGE_MODEL_REQUEST,
                );
                $conversation = self::$chat->execution()->conversation;
                $text = null === self::$handler ? 'Fixture reply.' : (string)(self::$handler)(
                    $conversation,
                    $conversation->attemptId,
                    self::$writes,
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
     *     failureCode?: string,
     *     blocked?: bool,
     *     warningNumber?: int
     * }>
     */
    public static function messages(Conversation $conversation): array
    {
        return self::$chat->messages($conversation);
    }

    public static function reply(Conversation $conversation, string $reply): string
    {
        return $reply;
    }
}
