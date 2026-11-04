<?php

namespace App\Application\TreeMerge\Ai\Dto;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

final class MergeAiResponse
{
    public MergeAction $action = MergeAction::REFUSAL;
    #[Schema(maxLength: 0)]
    public string $reply = '';
    public ?MergeAiProposal $proposal = null;
    /** @var list<string> Essential clarification questions when no valid proposal can be prepared. */
    #[Schema(maxItems: 3)]
    public array $questions = [];
    /** @var list<string> Existing person IDs whose confirmed data answers the question. */
    #[Schema(maxItems: 5)]
    public array $answerPersonIds = [];
    /** @var list<MergeAiOmission> Every mentioned person not represented in the complete proposal, with a concrete reason. */
    #[Schema(maxItems: 20)]
    public array $omittedPeople = [];
}
