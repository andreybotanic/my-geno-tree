<?php

namespace App\Application\TreeMerge\Ai\Dto;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

final class MergeAiEvidence
{
    public string $messageId = '';
    #[Schema(minLength: 1, maxLength: 500)]
    public string $quote = '';
}
