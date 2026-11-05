<?php

namespace App\Tests\Api\Tree;

use App\Application\TreeMerge\TreeMergeConversationService;
use App\Application\TreeMerge\TreeMergeProposalService;
use App\Entity\TreeMergeDraft;
use App\Mcp\TreeMergeAgentTools;
use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Ai\FixtureTreeMergeAi;

final class TreeMergeConversationApiTest extends ApiWebTestCase
{
    private string $treeId;
    private string $path;
    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        FixtureTreeMergeAi::$calls = 0;
        FixtureTreeMergeAi::$handler = null;
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        $this->headers = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Conversation'], $this->headers);
        $this->treeId = $this->responseString('id');
        $base = '/api/v1/trees/' . $this->treeId . '/merges';
        $this->jsonRequest('POST', $base, ['referencePersonId' => null], $this->headers);
        $this->path = $base . '/' . $this->responseString('id');
    }

    public function testMergeSavesThePreviewAndNormalRepliesPreserveItWithoutApplyingChanges(): void
    {
        $answer = "I prepared Vera's profile.\n\n" . str_repeat('Review the proposed details before applying them. ', 10);
        FixtureTreeMergeAi::$handler = fn (TreeMergeDraft $draft) => FixtureTreeMergeAi::reply($draft, $answer, $this->proposal($draft));
        $this->message('Vera was born about 1938.', 'first');
        $this->process('first');
        self::assertSame('ready', $this->responseString('state'));
        self::assertSame($answer, $this->responseString('messages', '1', 'content'));
        $prepared = $this->responseData();
        $this->process('first');
        self::assertSame(1, FixtureTreeMergeAi::$calls);
        self::assertSame($prepared, $this->responseData());
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertSame([], $this->responseData()['people']);
        FixtureTreeMergeAi::$handler = static fn () => 'Use the confirmation button to apply the proposal.';
        $this->message('Apply everything now.', 'apply');
        $this->process('apply');
        self::assertSame($prepared['proposal'], $this->responseData()['proposal']);
        self::assertSame($prepared['proposalVersion'], $this->responseData()['proposalVersion']);
        self::assertSame('ready', $this->responseString('state'));
    }

    public function testJsonLookingProviderTextIsAnOrdinaryMessageAndDoesNotCreateAProposal(): void
    {
        $text = '{"action":"proposal","reply":"Done","proposal":{"people":[]}}';
        FixtureTreeMergeAi::$handler = static fn () => $text;
        $this->message('Add Vera.', 'first');
        $this->process('first');
        self::assertNull($this->responseData()['proposal']);
        self::assertSame($text, $this->responseString('messages', '1', 'content'));
        self::assertSame('assistant', $this->responseString('messages', '1', 'role'));
    }

    public function testAgentCorrectsRejectedMergeAndThePreviewExistsBeforeItsAnswer(): void
    {
        FixtureTreeMergeAi::$handler = function (TreeMergeDraft $draft, string $attempt, TreeMergeAgentTools $tools): string {
            $proposal = $this->proposal($draft);
            $proposal['parents'] = [['parent' => 'missing', 'child' => 'v', 'role' => 'mother', 'evidence' => [['messageId' => FixtureTreeMergeAi::messages($draft)[0]['id'], 'quote' => 'Vera.']]]];
            $failed = $tools->merge($this->treeId, $proposal);
            self::assertArrayHasKey('error', $failed);
            self::assertStringContainsString('person_reference_unknown', json_encode($failed, JSON_THROW_ON_ERROR));
            self::assertNull($draft->proposal);
            $result = $tools->merge($this->treeId, $this->proposal($draft));
            self::assertArrayNotHasKey('error', $result);
            self::assertSame(1, $result['newPeople']);
            self::assertNotNull($draft->proposal);
            self::assertSame('processing', $draft->chatState);
            self::assertSame('ready', $draft->state);
            self::assertCount(1, FixtureTreeMergeAi::messages($draft));

            return 'Ready to add Vera.';
        };
        $this->message('Vera.', 'first');
        $this->process('first');
        self::assertSame('ready', $this->responseString('state'));
        self::assertSame('Ready to add Vera.', $this->responseString('messages', '1', 'content'));
    }

    public function testClarificationIsAnOrdinaryAnswerAndTheNextTurnRetainsTheHistory(): void
    {
        FixtureTreeMergeAi::$handler = static fn () => 'Which Vera do you mean?';
        $this->message('Add Vera.', 'first');
        $this->process('first');
        self::assertSame('collecting', $this->responseString('state'));
        self::assertSame('Which Vera do you mean?', $this->responseString('messages', '1', 'content'));
        FixtureTreeMergeAi::$handler = function (TreeMergeDraft $draft): string {
            self::assertSame('Add Vera.', FixtureTreeMergeAi::messages($draft)[0]['content']);
            self::assertSame('Which Vera do you mean?', FixtureTreeMergeAi::messages($draft)[1]['content']);
            self::assertSame('A new person.', FixtureTreeMergeAi::messages($draft)[2]['content']);

            return FixtureTreeMergeAi::reply($draft, 'Ready to add Vera.', $this->proposal($draft));
        };
        $this->message('A new person.', 'answer');
        $this->process('answer');
        self::assertSame('ready', $this->responseString('state'));
    }

    public function testEmptyAnswerCreatesARetryableNoticeInsteadOfAnAssistantMessage(): void
    {
        FixtureTreeMergeAi::$handler = static fn () => " \n";
        $this->message('Vera.', 'first');
        $this->process('first');
        self::assertSame('empty_response', $this->responseString('messages', '1', 'failureCode'));
        $noticeId = $this->responseString('messages', '1', 'id');
        FixtureTreeMergeAi::$handler = static fn () => 'Please describe Vera.';
        $this->process('first');
        self::assertSame($noticeId, $this->responseString('messages', '1', 'id'));
        self::assertSame('assistant', $this->responseString('messages', '1', 'role'));
        self::assertSame('Please describe Vera.', $this->responseString('messages', '1', 'content'));
    }

    public function testACommittedMergeSurvivesProviderFailureAndCanBeAppliedByButton(): void
    {
        FixtureTreeMergeAi::$handler = function (TreeMergeDraft $draft, string $attempt, TreeMergeAgentTools $tools): never {
            self::assertArrayNotHasKey('error', $tools->merge($this->treeId, $this->proposal($draft)));
            throw new \RuntimeException('connection lost after merge');
        };
        $this->message('Vera.', 'first');
        $this->process('first');
        self::assertSame('ready', $this->responseString('state'));
        self::assertSame('provider_unavailable', $this->responseString('messages', '1', 'failureCode'));
        $data = $this->responseData();
        $this->jsonRequest('POST', $this->path . '/apply', ['version' => $data['version'], 'proposalVersion' => $data['proposalVersion']],
            $this->headers + ['If-Match' => $this->responseString('treeEtag')]);
        self::assertResponseIsSuccessful();
        self::assertSame('applied', $this->responseString('state'));
        $receipt = $this->responseData()['result'];
        FixtureTreeMergeAi::$handler = static fn () => 'You can continue in this conversation.';
        $this->message('Can we continue?', 'after-apply');
        $this->process('after-apply');
        self::assertSame('applied', $this->responseString('state'));
        self::assertSame('idle', $this->responseString('chatState'));
        self::assertSame($receipt, $this->responseData()['result']);
    }

    public function testStopAndFailedOrdinaryQuestionsPreserveTheReadyProposal(): void
    {
        FixtureTreeMergeAi::$handler = fn (TreeMergeDraft $draft) => FixtureTreeMergeAi::reply($draft, 'Prepared.', $this->proposal($draft));
        $this->message('Vera.', 'first');
        $this->process('first');
        $proposal = $this->responseData()['proposal'];
        $proposalVersion = $this->responseData()['proposalVersion'];
        FixtureTreeMergeAi::$handler = function (TreeMergeDraft $draft): string {
            static::getContainer()->get(TreeMergeConversationService::class)->stop($draft, 'question');

            return 'Late answer.';
        };
        $this->message('How old is Vera?', 'question');
        $this->process('question');
        self::assertSame('ready', $this->responseString('state'));
        self::assertSame('idle', $this->responseString('chatState'));
        self::assertSame($proposalVersion, $this->responseData()['proposalVersion']);
        FixtureTreeMergeAi::$handler = static fn () => throw new \RuntimeException('offline');
        $this->message('Tell me her name.', 'failed-question');
        $this->process('failed-question');
        self::assertSame('ready', $this->responseString('state'));
        self::assertSame('error', $this->responseString('chatState'));
        self::assertSame($proposal, $this->responseData()['proposal']);
        self::assertSame($proposalVersion, $this->responseData()['proposalVersion']);
        FixtureTreeMergeAi::$handler = static fn () => 'Her name is Vera.';
        $this->message('Answer again.', 'answer');
        $this->process('answer');
        self::assertSame('idle', $this->responseString('chatState'));
        $data = $this->responseData();
        $this->jsonRequest('POST', $this->path . '/apply', ['version' => $data['version'], 'proposalVersion' => $proposalVersion],
            $this->headers + ['If-Match' => $this->responseString('treeEtag')]);
        self::assertResponseIsSuccessful();
        self::assertSame('applied', $this->responseString('state'));
    }

    public function testMergeRejectsEvidenceLongerThanTheDurableQuoteLimit(): void
    {
        FixtureTreeMergeAi::$handler = function (TreeMergeDraft $draft, string $attempt, TreeMergeAgentTools $tools): string {
            $proposal = $this->proposal($draft);
            $result = $tools->merge($this->treeId, $proposal);
            self::assertArrayHasKey('error', $result);
            self::assertStringContainsString('300', json_encode($result, JSON_THROW_ON_ERROR));

            return 'Please narrow the supporting quote.';
        };
        $this->message(str_repeat('A', 301), 'first');
        $this->process('first');
        self::assertNull($this->responseData()['proposal']);
    }

    public function testNewMessageAndStopRejectLateToolResultsAndOrdinaryAnswers(): void
    {
        FixtureTreeMergeAi::$handler = function (TreeMergeDraft $draft, string $attempt, TreeMergeAgentTools $tools): string {
            static::getContainer()->get(\App\Application\TreeMerge\TreeMergeDraftService::class)->append($draft,
                ['version' => $draft->version, 'requestId' => 'new', 'content' => 'Her name is Veronika.']);
            self::assertArrayHasKey('error', $tools->merge($this->treeId, $this->proposal($draft)));

            return 'Obsolete answer.';
        };
        $this->message('Vera.', 'first');
        $this->process('first');
        self::assertIsArray($this->responseData()['messages']);
        self::assertCount(2, $this->responseData()['messages']);
        FixtureTreeMergeAi::$handler = function (TreeMergeDraft $draft, string $attempt, TreeMergeAgentTools $tools): string {
            static::getContainer()->get(TreeMergeConversationService::class)->stop($draft, 'new');
            self::assertArrayHasKey('error', $tools->tree($this->treeId));

            return 'Obsolete answer.';
        };
        $this->process('new');
        self::assertSame('stopped', $this->responseString('messages', '2', 'failureCode'));
        self::assertSame('system', $this->responseString('messages', '2', 'role'));
    }

    public function testReviewedProposalEditsSupersedeTheRunningAgentAnswer(): void
    {
        FixtureTreeMergeAi::$handler = function (TreeMergeDraft $draft, string $attempt, TreeMergeAgentTools $tools): string {
            $proposal = $this->proposal($draft);
            self::assertArrayNotHasKey('error', $tools->merge($this->treeId, $proposal));
            self::assertIsArray($proposal['people']);
            self::assertIsArray($proposal['people'][0]);
            self::assertIsArray($proposal['people'][0]['fields']);
            $proposal['people'][0]['fields']['givenName'] = ['state' => 'known', 'value' => 'Veronika'];
            static::getContainer()->get(TreeMergeProposalService::class)->revise($draft, ['version' => $draft->version, 'proposal' => $proposal]);

            return 'An obsolete answer about Vera.';
        };
        $this->message('Vera.', 'first');
        $this->process('first');
        self::assertSame('ready', $this->responseString('state'));
        self::assertSame('Veronika', $this->responseString('proposal', 'people', '0', 'fields', 'givenName', 'value'));
        self::assertIsArray($this->responseData()['messages']);
        self::assertCount(1, $this->responseData()['messages']);
    }

    /** @return array<string, mixed> */
    private function proposal(TreeMergeDraft $draft): array
    {
        return ['people' => [['id' => 'v', 'existingPersonId' => null, 'resolution' => 'new', 'lifeStatus' => 'alive',
            'fields'               => ['givenName' => ['state' => 'known', 'value' => 'Vera'], 'gender' => 'female',
                'birthDate'                        => ['state' => 'known', 'kind' => 'approximate', 'year' => 1938]],
            'evidence' => [['messageId' => FixtureTreeMergeAi::messages($draft)[0]['id'], 'quote' => FixtureTreeMergeAi::messages($draft)[0]['content']]]]],
            'parents' => [], 'marriages' => [], 'questions' => []];
    }

    private function message(string $content, string $requestId): void
    {
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        $this->jsonRequest('POST', $this->path . '/messages', ['version' => $this->responseData()['version'], 'requestId' => $requestId, 'content' => $content], $this->headers);
        self::assertResponseIsSuccessful();
    }

    private function process(string $requestId): void
    {
        $this->jsonRequest('POST', $this->path . '/process', ['version' => $this->responseData()['version'], 'requestId' => $requestId], $this->headers);
        self::assertResponseIsSuccessful();
    }
}
