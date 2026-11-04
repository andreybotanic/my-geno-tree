<?php

namespace App\Application\TreeMerge\Ai\Dto;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/** Final recovery call has no proposal or action fields: it can only ask questions. */
final class MergeClarification
{
    /** @var list<string> One to three essential questions using the specific validation feedback. */
    #[Schema(minItems: 1, maxItems: 3)]
    public array $questions = [];
}
