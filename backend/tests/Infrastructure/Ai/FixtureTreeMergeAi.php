<?php

namespace App\Tests\Infrastructure\Ai;

use App\Application\TreeMerge\Ai\TreeMergeAiInterface;
use App\Entity\TreeMergeDraft;
use App\Mcp\TreeMergeAgentTools;
use App\Mcp\TreeReadTools;
use App\Security\TreeMergeAgentGrant;
use Symfony\Component\HttpFoundation\RequestStack;

final class FixtureTreeMergeAi implements TreeMergeAiInterface
{
    public static ?\Closure $handler = null;
    public static int $calls = 0;
    private static TreeMergeAgentTools $tools;
    public static string $accessToken;
    public static string $attemptId;

    public function __construct(TreeMergeAgentTools $tools, private readonly RequestStack $requests, private readonly TreeReadTools $reads, private readonly TreeMergeAgentGrant $grants)
    {
        self::$tools = $tools;
    }

    public function respond(TreeMergeDraft $draft, string $attemptId): void
    {
        ++self::$calls;
        self::$accessToken = $this->grants->issue($draft);
        self::$attemptId = $attemptId;
        $request = $this->requests->getCurrentRequest() ?? throw new \LogicException('Missing request');
        $request->attributes->set(TreeMergeAgentGrant::class, ['draftId' => $draft->id->toRfc4122(),
            'treeId'                                                     => $draft->tree->getId()->toRfc4122(), 'attemptId' => $attemptId, 'version' => $draft->version]);
        try {
            if (null === self::$handler) {
                throw new \LogicException('Missing AI fixture');
            }
            (self::$handler)($draft, $attemptId, self::$tools, $this->reads);
        } finally {
            $request->attributes->remove(TreeMergeAgentGrant::class);
        }
    }

    /** @param array<string, mixed>|null $proposal */
    public static function reply(TreeMergeDraft $draft, string $reply, ?array $proposal = null, bool $clarification = false): void
    {
        $id = null;
        if (null !== $proposal) {
            $prepared = self::$tools->prepare($draft->tree->getId()->toRfc4122(), $proposal, self::$accessToken, self::$attemptId);
            if ($prepared->isError) {
                throw new \LogicException(json_encode($prepared, JSON_THROW_ON_ERROR));
            }
            $data = $prepared->structuredContent;
            if (!is_array($data) || !is_string($data['proposalId'] ?? null)) {
                throw new \LogicException('Missing proposal id');
            }
            $id = $data['proposalId'];
        }
        $result = self::$tools->reply($draft->tree->getId()->toRfc4122(), $reply, self::$accessToken, self::$attemptId, $id, $clarification);
        if ($result->isError) {
            throw new \LogicException(json_encode($result, JSON_THROW_ON_ERROR));
        }
    }
}
