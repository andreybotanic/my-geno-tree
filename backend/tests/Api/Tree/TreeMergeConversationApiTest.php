<?php

namespace App\Tests\Api\Tree;

use App\Application\TreeMerge\TreeMergeConversationService;
use App\Entity\TreeMergeDraft;
use App\Mcp\TreeMergeAgentTools;
use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Ai\FixtureTreeMergeAi;
use Doctrine\ORM\EntityManagerInterface;

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

    public function testOnlyMcpCommandsSubmitProposalsAndRepliesAreVerbatim(): void
    {
        FixtureTreeMergeAi::$handler = fn (TreeMergeDraft $draft) => FixtureTreeMergeAi::reply($draft, 'Ready to add 1 person; Boris is omitted at your request.', $this->proposal($draft));
        $this->message('Vera was born about 1938; skip Boris.', 'first');
        $this->process('first');
        self::assertSame('ready', $this->responseString('state'));
        self::assertSame('Ready to add 1 person; Boris is omitted at your request.', $this->responseString('messages', '1', 'content'));
        $prepared = $this->responseData();
        $this->process('first');
        self::assertSame(1, FixtureTreeMergeAi::$calls);
        self::assertSame($prepared, $this->responseData());
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertSame([], $this->responseData()['people']);
        FixtureTreeMergeAi::$handler = static fn (TreeMergeDraft $draft) => FixtureTreeMergeAi::reply($draft, 'Use the confirmation button to apply the proposal.');
        $this->message('Apply everything now.', 'apply');
        $this->process('apply');
        self::assertSame($prepared['proposal'], $this->responseData()['proposal']);
        self::assertSame($prepared['proposalVersion'], $this->responseData()['proposalVersion']);
        self::assertSame('ready', $this->responseString('state'));
    }

    public function testFreeTextAndJsonFromProviderHaveNoEffectsAndFailureIsNotAnAssistantReply(): void
    {
        FixtureTreeMergeAi::$handler = static fn () => '{"action":"proposal","reply":"Done","proposal":{"people":[]}}';
        $this->message('Add Vera.', 'first');
        $this->process('first');
        self::assertSame('error', $this->responseString('state'));
        self::assertNull($this->responseData()['proposal']);
        self::assertSame('system', $this->responseString('messages', '1', 'role'));
        self::assertSame('', $this->responseString('messages', '1', 'content'));
        self::assertSame('reply_missing', $this->responseString('messages', '1', 'failureCode'));
        $failedId = $this->responseString('messages', '1', 'id');
        FixtureTreeMergeAi::$handler = fn (TreeMergeDraft $draft) => FixtureTreeMergeAi::reply($draft, 'Ready to add 1 person.', $this->proposal($draft));
        $this->process('first');
        self::assertSame('ready', $this->responseString('state'));
        self::assertSame($failedId, $this->responseString('messages', '1', 'id'));
        self::assertIsArray($this->responseData()['messages']);
        self::assertCount(2, $this->responseData()['messages']);
        self::assertSame('assistant', $this->responseString('messages', '1', 'role'));
    }

    public function testAgentCorrectsRejectedCommandWithoutServerInferenceOrExtraModelInvocation(): void
    {
        FixtureTreeMergeAi::$handler = function (TreeMergeDraft $draft, string $attempt, TreeMergeAgentTools $tools): void {
            $proposal = $this->proposal($draft);
            $proposal['parents'] = [['parent' => 'missing', 'child' => 'v', 'role' => 'mother', 'evidence' => [['messageId' => $draft->messages[0]['id'], 'quote' => 'Vera.']]]];
            $failed = $tools->prepare($this->treeId, $proposal);
            self::assertArrayHasKey('error', $failed);
            self::assertStringContainsString('person_reference_unknown', json_encode($failed, JSON_THROW_ON_ERROR));
            self::assertNull($draft->proposal);
            self::assertCount(1, $draft->messages);
            FixtureTreeMergeAi::reply($draft, 'Ready to add 1 person.', $this->proposal($draft));
        };
        $this->message('Vera.', 'first');
        $this->process('first');
        self::assertSame('ready', $this->responseString('state'));
        self::assertSame(1, FixtureTreeMergeAi::$calls);
    }

    public function testInvalidReplyIsRejectedNotRewrittenAndStagingAloneDoesNotSubmit(): void
    {
        FixtureTreeMergeAi::$handler = function (TreeMergeDraft $draft, string $attempt, TreeMergeAgentTools $tools): void {
            $prepared = $tools->prepare($this->treeId, $this->proposal($draft));
            self::assertArrayNotHasKey('error', $prepared);
            self::assertIsString($prepared['proposalId']);
            self::assertSame(1, $prepared['newPeople']);
            foreach (['', "First.\nSecond.", 'First. Second.', str_repeat('x', 351)] as $text) {
                self::assertArrayHasKey('error', $tools->reply($this->treeId, $text, $prepared['proposalId']));
            }
            self::assertNull($draft->proposal);
            self::assertCount(1, $draft->messages);
        };
        $this->message('Vera.', 'first');
        $this->process('first');
        self::assertSame('error', $this->responseString('state'));
        self::assertNull($this->responseData()['proposal']);
    }

    public function testClarificationUsesExactAgentSentenceAndRetainsWholeConversation(): void
    {
        FixtureTreeMergeAi::$handler = static fn (TreeMergeDraft $draft) => FixtureTreeMergeAi::reply($draft, '0 people ready; which Vera do you mean?', clarification: true);
        $this->message('Add Vera.', 'first');
        $this->process('first');
        self::assertSame('questions', $this->responseString('state'));
        self::assertSame(['0 people ready; which Vera do you mean?'], $this->responseData()['questions']);
        FixtureTreeMergeAi::$handler = function (TreeMergeDraft $draft): void {
            self::assertSame('Add Vera.', $draft->messages[0]['content']);
            self::assertSame('0 people ready; which Vera do you mean?', $draft->messages[1]['content']);
            self::assertSame('A new person.', $draft->messages[2]['content']);
            FixtureTreeMergeAi::reply($draft, 'Ready to add 1 person.', $this->proposal($draft));
        };
        $this->message('A new person.', 'answer');
        $this->process('answer');
        self::assertSame('ready', $this->responseString('state'));
    }

    public function testProviderFailureDoesNotInventTextAndCannotUndoCompletedMcpReply(): void
    {
        FixtureTreeMergeAi::$handler = static fn () => throw new \RuntimeException('offline');
        $this->message('Vera.', 'first');
        $this->process('first');
        self::assertSame('provider_unavailable', $this->responseString('messages', '1', 'failureCode'));
        self::assertSame('', $this->responseString('messages', '1', 'content'));
        FixtureTreeMergeAi::$handler = static function (TreeMergeDraft $draft): never {
            FixtureTreeMergeAi::reply($draft, 'Please specify the relationship.');
            throw new \RuntimeException('connection closed after command');
        };
        $this->process('first');
        self::assertSame('Please specify the relationship.', $this->responseString('messages', '1', 'content'));
        self::assertSame('assistant', $this->responseString('messages', '1', 'role'));
    }

    public function testNewMessageAndStopInvalidateTheOldAgentCommands(): void
    {
        FixtureTreeMergeAi::$handler = function (TreeMergeDraft $draft, string $attempt, TreeMergeAgentTools $tools): void {
            $draft->addMessage('new', 'Her name is Veronika.', new \DateTimeImmutable());
            static::getContainer()->get(EntityManagerInterface::class)->flush();
            self::assertArrayHasKey('error', $tools->reply($this->treeId, 'Obsolete reply.'));
        };
        $this->message('Vera.', 'first');
        $this->process('first');
        self::assertIsArray($this->responseData()['messages']);
        self::assertCount(2, $this->responseData()['messages']);
        self::assertSame('collecting', $this->responseString('state'));
        FixtureTreeMergeAi::$handler = function (TreeMergeDraft $draft, string $attempt, TreeMergeAgentTools $tools): void {
            static::getContainer()->get(TreeMergeConversationService::class)->stop($draft, 'new');
            self::assertArrayHasKey('error', $tools->reply($this->treeId, 'Obsolete reply.'));
        };
        $this->process('new');
        self::assertSame('system', $this->responseString('messages', '2', 'role'));
        self::assertSame('stopped', $this->responseString('messages', '2', 'failureCode'));
    }

    /** @return array<string, mixed> */
    private function proposal(TreeMergeDraft $draft): array
    {
        return ['people' => [['id' => 'v', 'existingPersonId' => null, 'resolution' => 'new', 'lifeStatus' => 'alive',
            'fields'               => ['givenName' => ['state' => 'known', 'value' => 'Vera'], 'gender' => 'female',
                'birthDate'                        => ['state' => 'known', 'kind' => 'approximate', 'year' => 1938]],
            'evidence' => [['messageId' => $draft->messages[0]['id'], 'quote' => $draft->messages[0]['content']]]]],
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
