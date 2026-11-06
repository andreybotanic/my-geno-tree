<?php

namespace App\Tests\Infrastructure\Ai;

use App\Application\Chat\Moderation\ModerationAssessment;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Result;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\Attribute\When;

#[When('test')]
#[AsDecorator('ai.agent.chat_moderation')]
final class FixtureModerationAgent implements AgentInterface
{
    public static float $risk = 0;
    public static bool $useReal = false;
    public static ?\Closure $handler = null;
    public static int $calls = 0;

    public function __construct(#[AutowireDecorated] private readonly AgentInterface $inner)
    {
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }

    public function call(string|MessageBag|UserMessage $input, array $options = []): ExecutionInterface
    {
        if (self::$useReal) {
            return $this->inner->call($input, $options);
        }

        return new Execution(function () use ($input): \Generator {
            ++self::$calls;
            $risk = null === self::$handler ? self::$risk : (self::$handler)($input);
            yield new Result(new ObjectResult(new ModerationAssessment($risk)));
        });
    }
}
