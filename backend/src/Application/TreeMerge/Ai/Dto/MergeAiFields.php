<?php

namespace App\Application\TreeMerge\Ai\Dto;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

final class MergeAiFields
{
    public ?string $givenName = null;
    public ?string $birthSurname = null;
    public ?string $patronymic = null;
    /** @var list<string> Name fields explicitly cleared to unknown: givenName, birthSurname or patronymic. Usually empty. */
    #[Schema(maxItems: 3)]
    public array $unknownNames = [];
    /** female, male or unknown; null leaves an existing value unchanged. */
    #[Schema(enum: ['female', 'male', 'unknown', null])]
    public ?string $gender = null;
    /** Date: unknown, YYYY[-MM[-DD]], ~YYYY, <YYYY, >YYYY or YYYY..YYYY. Never invent missing parts. */
    public ?string $birthDate = null;
    /** Same date notation; null for alive or an unchanged existing field. */
    public ?string $deathDate = null;
}
