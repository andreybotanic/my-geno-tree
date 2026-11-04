<?php

namespace App\Application\TreeMerge\Ai\Dto;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

final class MergeAiInference
{
    #[Schema(enum: ['birthDate', 'birthSurname', 'patronymic'])]
    public string $field = '';
    /** Temporary ID of the person supplying the known date or family name. */
    public string $sourcePersonId = '';
    /** Birth-year offset from the source: younger is positive, older negative. Only for birthDate. */
    #[Schema(minimum: -150, maximum: 150)]
    public ?int $yearOffset = null;
    /** Short explanation in the interface language, explicitly identifying this as an inference. */
    #[Schema(minLength: 1, maxLength: 500)]
    public string $explanation = '';
}
