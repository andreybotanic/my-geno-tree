<?php

namespace App\Tests\Unit\Application\TreeMerge;

use App\Api\ApiException;
use App\Application\TreeMerge\Ai\Dto\MergeAiResponse;
use App\Application\TreeMerge\Ai\MergeAiRepair;
use App\Application\TreeMerge\Ai\MergeAiResult;
use PHPUnit\Framework\TestCase;

final class MergeAiRepairTest extends TestCase
{
    public function testFallbackQuestionsUseValidationReasonsWhenTheProviderCannotAsk(): void
    {
        foreach ([
            [['people' => ['new_people_limit']], 'TREE_MERGE_PROPOSAL_INVALID', 'ten', 'десяти'],
            [['people[0].fields.birthDate' => ['invalid']], 'TREE_MERGE_PROPOSAL_INVALID', 'dates', 'даты'],
            [[], 'TREE_CHRONOLOGY_INVALID', 'dates', 'даты'],
            [['parents[0].parent' => ['person_reference_unknown']], 'TREE_MERGE_PROPOSAL_INVALID', 'connect', 'связать'],
            [[], 'TREE_PARENT_INVALID', 'connect', 'связать'],
            [[], 'TREE_CONNECTIVITY_INVALID', 'connect', 'связать'],
            [['evidence' => ['evidence_not_found']], 'TREE_MERGE_PROPOSAL_INVALID', 'verify', 'подтвердить'],
        ] as [$fields, $code, $english, $russian]) {
            $error = new ApiException(422, $code, fields: $fields, details: ['reason' => 'fixture']);
            $repair = new MergeAiRepair(new MergeAiResult(new MergeAiResponse()), $error, true);
            self::assertStringContainsString($english, $repair->fallbackQuestion(false));
            self::assertStringContainsString($russian, $repair->fallbackQuestion(true));
            self::assertSame(['code' => $code, 'fields' => $fields, 'details' => ['reason' => 'fixture']], $repair->feedback());
            self::assertStringContainsString('Do NOT produce another proposal', $repair->instruction());
        }
        self::assertStringContainsString('Correct the COMPLETE proposal', (new MergeAiRepair(new MergeAiResult(new MergeAiResponse()), new ApiException(422, 'INVALID')))->instruction());
    }
}
