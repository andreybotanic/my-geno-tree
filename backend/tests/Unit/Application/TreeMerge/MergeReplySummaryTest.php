<?php

namespace App\Tests\Unit\Application\TreeMerge;

use App\Api\ApiException;
use App\Application\TreeMerge\Ai\Dto\MergeAiEvidence;
use App\Application\TreeMerge\Ai\Dto\MergeAiOmission;
use App\Application\TreeMerge\Ai\Dto\MergeAiResponse;
use App\Application\TreeMerge\Ai\MergeReplySummary;
use App\Entity\FamilyTree;
use App\Entity\TreeMergeDraft;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class MergeReplySummaryTest extends TestCase
{
    public function testCountsNewPeopleAndExplainsMatchedAndDeferredPeopleInBothLanguages(): void
    {
        foreach (['ru', 'en'] as $language) {
            $draft = $this->draft($language);
            $response = $this->response($draft);
            $summary = (new MergeReplySummary())->describe($draft, $response, ['people' => [
                ['resolution' => 'new'], ['resolution' => 'new'], ['resolution' => 'existing'], ['resolution' => 'undecided'],
            ]]);
            self::assertStringContainsString('ru' === $language ? 'Новых персон к добавлению: 2.' : 'New people to add: 2.', $summary);
            self::assertStringContainsString('ru' === $language ? 'Уже есть в дереве: 1' : 'Already in the tree: 1', $summary);
            self::assertStringContainsString('Olga', $summary);
            self::assertStringContainsString('User deferred her until the next turn.', $summary);
            self::assertStringContainsString('0.', (new MergeReplySummary())->describe($draft, $response, null));
        }
    }

    public function testRejectsInventedOmissionEvidence(): void
    {
        $draft = $this->draft('en');
        $response = $this->response($draft);
        $response->omittedPeople[0]->evidence->quote = 'Not a user quote';
        $this->expectException(ApiException::class);
        (new MergeReplySummary())->describe($draft, $response, null);
    }

    public function testRejectsExcessiveOmissions(): void
    {
        $draft = $this->draft('en');
        $response = $this->response($draft);
        $response->omittedPeople = array_fill(0, 21, $response->omittedPeople[0]);
        $this->expectException(ApiException::class);
        (new MergeReplySummary())->describe($draft, $response, null);
    }

    private function draft(string $language): TreeMergeDraft
    {
        $now = new \DateTimeImmutable();
        $user = new User('test', 'test@example.test', 'hash', $language, $now);
        $draft = new TreeMergeDraft(new FamilyTree($user, 'tree', $now), null, 'etag', $now);
        $draft->addMessage('request', 'Add Anna and her son. Leave Olga for later.', $now);

        return $draft;
    }

    private function response(TreeMergeDraft $draft): MergeAiResponse
    {
        $response = new MergeAiResponse();
        $omission = new MergeAiOmission();
        $omission->people = 'Olga';
        $omission->reason = 'User deferred her until the next turn.';
        $omission->evidence = new MergeAiEvidence();
        $omission->evidence->messageId = $draft->messages[0]['id'];
        $omission->evidence->quote = 'Leave Olga for later.';
        $response->omittedPeople = [$omission];

        return $response;
    }
}
