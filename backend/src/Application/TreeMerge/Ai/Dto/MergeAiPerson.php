<?php

namespace App\Application\TreeMerge\Ai\Dto;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

final class MergeAiPerson
{
    /** Stable temporary ASCII identifier such as p1; every relationship refers to this identifier. */
    #[Schema(pattern: '^[a-zA-Z0-9_-]{1,64}$')]
    public string $id = '';
    public ?string $existingPersonId = null;
    /** new, existing or undecided; never assume identity from a name match. */
    #[Schema(enum: ['new', 'existing', 'undecided'])]
    public string $resolution = 'undecided';
    /** alive, deceased or uncertain; null death date means alive. */
    #[Schema(enum: ['alive', 'deceased', 'uncertain'])]
    public string $lifeStatus = 'uncertain';
    public MergeAiFields $fields;
    /** @var list<MergeAiEvidence> */
    #[Schema(minItems: 1, maxItems: 3)]
    public array $evidence = [];
    /** @var list<MergeAiInference> Supported deductions for NEW people, never unmarked guesses. */
    #[Schema(maxItems: 3)]
    public array $inferences = [];
}
