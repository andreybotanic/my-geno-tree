<?php

namespace App\Tests\Infrastructure\Ai;

use App\Application\TreeMerge\Ai\TreeMergeAiInterface;
use App\Application\TreeMerge\TreeMergeAgentScope;
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

    public function __construct(TreeMergeAgentTools $tools, private readonly TreeReadTools $reads, private readonly TreeMergeAgentScope $scope)
    {
        self::$tools = $tools;
    }

    public function respond(TreeMergeDraft $draft, string $attemptId): void
    {
        ++self::$calls;
        $this->scope->run($draft, $attemptId, function () use ($draft, $attemptId): void {
            if (null === self::$handler) {
                throw new \LogicException('Missing AI fixture');
            }
            (self::$handler)($draft, $attemptId, self::$tools, $this->reads);
        });
    }

    /** @param array<string, mixed>|null $proposal */
    public static function reply(TreeMergeDraft $draft, string $reply, ?array $proposal = null, bool $clarification = false): void
    {
        $id = null;
        if (null !== $proposal) {
            $prepared = self::$tools->prepare($draft->tree->getId()->toRfc4122(), $proposal);
            if (isset($prepared['error'])) {
                throw new \LogicException(json_encode($prepared, JSON_THROW_ON_ERROR));
            }
            $data = $prepared;
            if (!is_string($data['proposalId'] ?? null)) {
                throw new \LogicException('Missing proposal id');
            }
            $id = $data['proposalId'];
        }
        $result = self::$tools->reply($draft->tree->getId()->toRfc4122(), $reply, $id, $clarification);
        if (isset($result['error'])) {
            throw new \LogicException(json_encode($result, JSON_THROW_ON_ERROR));
        }
    }
}
