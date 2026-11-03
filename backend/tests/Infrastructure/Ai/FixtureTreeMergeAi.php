<?php

namespace App\Tests\Infrastructure\Ai;

use App\Application\TreeMerge\Ai\MergeAiResult;
use App\Application\TreeMerge\Ai\TreeMergeAiInterface;
use App\Entity\TreeMergeDraft;

final class FixtureTreeMergeAi implements TreeMergeAiInterface
{
    public static ?\Closure $handler = null;
    public static int $calls = 0;

    public function respond(TreeMergeDraft $draft, string $attemptId): MergeAiResult
    {
        ++self::$calls;

        return null === self::$handler ? throw new \LogicException('Missing AI fixture') : (self::$handler)($draft, $attemptId);
    }
}
