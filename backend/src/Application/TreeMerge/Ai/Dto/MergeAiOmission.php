<?php

namespace App\Application\TreeMerge\Ai\Dto;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

final class MergeAiOmission
{
    /** Identify the omitted person or group, including its size, in the interface language. */
    #[Schema(minLength: 1, maxLength: 200)]
    public string $people = '';
    /** Concrete reason for omission, in the interface language. Missing names or dates are not a reason. */
    #[Schema(minLength: 1, maxLength: 500)]
    public string $reason = '';
    public MergeAiEvidence $evidence;
}
