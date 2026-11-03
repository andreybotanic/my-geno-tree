<?php

namespace App\Application\TreeMerge\Ai\Dto;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

final class MergeAiResponse
{
    public MergeAction $action = MergeAction::REFUSAL;
    #[Schema(maxLength: 0)]
    public string $reply = '';
    public ?MergeAiProposal $proposal = null;
    /** @var list<string> Existing person IDs whose confirmed data answers the question. */
    #[Schema(maxItems: 5)]
    public array $answerPersonIds = [];
}
