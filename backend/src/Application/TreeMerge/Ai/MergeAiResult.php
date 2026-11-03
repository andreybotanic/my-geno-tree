<?php

namespace App\Application\TreeMerge\Ai;

use App\Application\TreeMerge\Ai\Dto\MergeAiResponse;

/** @phpstan-type ContextDate array{state: string, kind?: string, year?: int, month?: int, day?: int, end?: array{year: int, month?: int, day?: int}}
 * @phpstan-type ContextPerson array{id: string, givenName: array{state: string, value?: string}, birthSurname: array{state: string, value?: string}, patronymic: array{state: string, value?: string}, gender: string, birthDate: ContextDate, deathDate: ContextDate|null}
 */
final readonly class MergeAiResult
{
    /** @param array<string, ContextPerson> $people */
    public function __construct(public MergeAiResponse $response, public array $people = [])
    {
    }
}
