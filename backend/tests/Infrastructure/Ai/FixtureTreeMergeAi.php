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

    public function __construct(TreeMergeAgentTools $tools, private readonly TreeReadTools $reads)
    {
        self::$tools = $tools;
    }

    public function respond(TreeMergeDraft $draft, string $attemptId): string
    {
        ++self::$calls;
        if (null === self::$handler) {
            throw new \LogicException('Missing AI fixture');
        }

        return (string)(self::$handler)($draft, $attemptId, self::$tools, $this->reads);
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
