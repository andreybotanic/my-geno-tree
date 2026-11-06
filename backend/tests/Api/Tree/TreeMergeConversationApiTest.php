<?php

namespace App\Tests\Api\Tree;

use App\Application\Chat\ChatInterface;
use App\Model\Conversation;
use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Ai\FixtureTreeAgent;
use App\Tool\TreeMergeTool;

final class TreeMergeConversationApiTest extends ApiWebTestCase
{
    private string $treeId;
    private string $path;
    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        FixtureTreeAgent::$calls = 0;
        FixtureTreeAgent::$handler = null;
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        $this->headers = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Conversation'], $this->headers);
        $this->treeId = $this->responseString('id');
        $base = '/api/v1/trees/' . $this->treeId . '/chat';
        $this->jsonRequest('POST', $base, ['referencePersonId' => null], $this->headers);
        $this->path = '/api/v1/chats/' . $this->responseString('id');
    }

    public function testMergeSavesThePreviewAndNormalRepliesPreserveItWithoutApplyingChanges(): void
    {
        $answer = "I prepared Vera's profile.\n\n"
            . str_repeat('Review the proposed details before applying them. ', 10);
        FixtureTreeAgent::$handler = fn (Conversation $draft) => FixtureTreeAgent::reply(
            $draft,
            $answer,
            $this->proposal($draft),
        );
        $this->send('Vera was born about 1938.', 'first');
        self::assertSame('ready', $this->responseString('tools', 'merge', 'state'));
        self::assertSame($answer, $this->responseString('messages', '1', 'content'));
        $prepared = $this->responseData();
        $this->send('Vera was born about 1938.', 'first');
        $preparedMerge = $this->responseArray('tools', 'merge');
        self::assertSame(1, FixtureTreeAgent::$calls);
        self::assertSame($prepared, $this->responseData());
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertSame([], $this->responseData()['people']);
        FixtureTreeAgent::$handler = static fn () => 'Use the confirmation button to apply the proposal.';
        $this->send('Apply everything now.', 'apply');
        self::assertSame($preparedMerge, $this->responseArray('tools', 'merge'));
        self::assertSame($preparedMerge['version'], $this->responseInt('tools', 'merge', 'version'));
        self::assertSame('ready', $this->responseString('tools', 'merge', 'state'));
    }

    public function testJsonLookingProviderTextIsAnOrdinaryMessageAndDoesNotCreateAProposal(): void
    {
        $text = '{"action":"proposal","reply":"Done","proposal":{"people":[]}}';
        FixtureTreeAgent::$handler = static fn () => $text;
        $this->send('Add Vera.', 'first');
        self::assertNull($this->responseArray('tools')['merge'] ?? null);
        self::assertSame($text, $this->responseString('messages', '1', 'content'));
        self::assertSame('assistant', $this->responseString('messages', '1', 'role'));
    }

    public function testAgentCorrectsRejectedMergeAndThePreviewExistsBeforeItsAnswer(): void
    {
        FixtureTreeAgent::$handler = function (Conversation $draft, string $attempt, TreeMergeTool $tools): string {
            $proposal = $this->proposal($draft);
            $proposal['parents'] = [
                [
                    'parent'   => 'missing',
                    'child'    => 'v',
                    'role'     => 'mother',
                    'evidence' => [['messageId' => FixtureTreeAgent::messages($draft)[0]['id'], 'quote' => 'Vera.']],
                ],
            ];
            $failed = $tools($this->treeId, $proposal);
            self::assertArrayHasKey('error', $failed);
            self::assertStringContainsString('person_reference_unknown', json_encode($failed, JSON_THROW_ON_ERROR));
            self::assertArrayNotHasKey('merge', $draft->tools);
            $result = $tools($this->treeId, $this->proposal($draft));
            self::assertArrayNotHasKey('error', $result);
            self::assertSame(1, $result['newPeople']);
            self::assertArrayHasKey('merge', $draft->tools);
            self::assertSame('processing', $draft->status);
            self::assertInstanceOf(\App\Model\TreeMergeProposal::class, $draft->tools['merge']);
            self::assertSame('ready', $draft->tools['merge']->state);
            self::assertCount(1, FixtureTreeAgent::messages($draft));

            return 'Ready to add Vera.';
        };
        $this->send('Vera.', 'first');
        self::assertSame('ready', $this->responseString('tools', 'merge', 'state'));
        self::assertSame('Ready to add Vera.', $this->responseString('messages', '1', 'content'));
    }

    public function testClarificationIsAnOrdinaryAnswerAndTheNextTurnRetainsTheHistory(): void
    {
        FixtureTreeAgent::$handler = static fn () => 'Which Vera do you mean?';
        $this->send('Add Vera.', 'first');
        self::assertArrayNotHasKey('merge', $this->responseArray('tools'));
        self::assertSame('Which Vera do you mean?', $this->responseString('messages', '1', 'content'));
        FixtureTreeAgent::$handler = function (Conversation $draft): string {
            self::assertSame('Add Vera.', FixtureTreeAgent::messages($draft)[0]['content']);
            self::assertSame('Which Vera do you mean?', FixtureTreeAgent::messages($draft)[1]['content']);
            self::assertSame('A new person.', FixtureTreeAgent::messages($draft)[2]['content']);

            return FixtureTreeAgent::reply($draft, 'Ready to add Vera.', $this->proposal($draft));
        };
        $this->send('A new person.', 'answer');
        self::assertSame('ready', $this->responseString('tools', 'merge', 'state'));
    }

    public function testEmptyAnswerCreatesARetryableNoticeInsteadOfAnAssistantMessage(): void
    {
        FixtureTreeAgent::$handler = static fn () => " \n";
        $this->send('Vera.', 'first');
        self::assertSame('empty_response', $this->responseString('messages', '1', 'failureCode'));
        $noticeId = $this->responseString('messages', '1', 'id');
        FixtureTreeAgent::$handler = static fn () => 'Please describe Vera.';
        $this->send('Vera.', 'first');
        self::assertSame($noticeId, $this->responseString('messages', '1', 'id'));
        self::assertSame('assistant', $this->responseString('messages', '1', 'role'));
        self::assertSame('Please describe Vera.', $this->responseString('messages', '1', 'content'));
    }

    public function testAPreparedMergeSurvivesProviderFailureAndApplyDeletesAllSavedProposalState(): void
    {
        FixtureTreeAgent::$handler = function (Conversation $conversation): never {
            FixtureTreeAgent::reply($conversation, 'Prepared.', $this->proposal($conversation));
            throw new \RuntimeException('connection lost after merge');
        };
        $this->send('Vera.', 'first');
        self::assertSame('ready', $this->responseString('tools', 'merge', 'state'));
        self::assertSame('provider_unavailable', $this->responseString('messages', '1', 'failureCode'));
        $pending = $this->responseArray('tools', 'merge');
        self::assertIsString($pending['id']);
        self::assertIsInt($pending['version']);
        self::assertIsString($pending['treeEtag']);
        $path = '/api/v1/trees/' . $this->treeId . '/merge-proposals/' . $pending['id'] . '/apply';
        $this->jsonRequest(
            'POST',
            $path,
            ['version' => $pending['version']],
            $this->headers + ['If-Match' => $pending['treeEtag']],
        );
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('result', $this->responseData());
        self::assertArrayNotHasKey('merge', $this->responseArray('conversation', 'tools'));
        $id = \Symfony\Component\Uid\Uuid::fromString(basename($this->path));
        self::assertInstanceOf(\Symfony\Component\Uid\UuidV7::class, $id);
        $store = static::getContainer()->get('ai.message_store.cache.chat');
        self::assertInstanceOf(\Symfony\AI\Agent\MessageStore\MessageStoreInterface::class, $store);
        $saved = $store->load($id)->getMetadata()->get(Conversation::METADATA);
        self::assertInstanceOf(Conversation::class, $saved);
        self::assertArrayNotHasKey('merge', $saved->tools);
        self::assertStringNotContainsString('personIds', serialize($store->load($id)));
        $this->jsonRequest(
            'POST',
            $path,
            ['version' => $pending['version']],
            $this->headers + ['If-Match' => $pending['treeEtag']],
        );
        self::assertResponseStatusCodeSame(404);
        FixtureTreeAgent::$handler = static fn () => 'You can continue in this conversation.';
        $this->send('Can we continue?', 'after-apply');
        self::assertArrayNotHasKey('merge', $this->responseArray('tools'));
        self::assertArrayNotHasKey('result', $this->responseData());
        self::assertSame('idle', $this->responseString('status'));
    }

    public function testStopAndFailedOrdinaryQuestionsPreserveTheReadyProposal(): void
    {
        FixtureTreeAgent::$handler = fn (Conversation $draft) => FixtureTreeAgent::reply(
            $draft,
            'Prepared.',
            $this->proposal($draft),
        );
        $this->send('Vera.', 'first');
        $proposal = ($this->responseArray('tools')['merge'] ?? null);
        $proposalVersion = $this->responseInt('tools', 'merge', 'version');
        FixtureTreeAgent::$handler = function (Conversation $draft): string {
            static::getContainer()->get(ChatInterface::class)->stop($draft, 'question');

            return 'Late answer.';
        };
        $this->send('How old is Vera?', 'question');
        self::assertSame('ready', $this->responseString('tools', 'merge', 'state'));
        self::assertSame('idle', $this->responseString('status'));
        self::assertSame($proposalVersion, $this->responseInt('tools', 'merge', 'version'));
        FixtureTreeAgent::$handler = static fn () => throw new \RuntimeException('offline');
        $this->send('Tell me her name.', 'failed-question');
        self::assertSame('ready', $this->responseString('tools', 'merge', 'state'));
        self::assertSame('error', $this->responseString('status'));
        self::assertSame($proposal, $this->responseArray('tools')['merge'] ?? null);
        self::assertSame($proposalVersion, $this->responseInt('tools', 'merge', 'version'));
        FixtureTreeAgent::$handler = static fn () => 'Her name is Vera.';
        $this->send('Answer again.', 'answer');
        self::assertSame('idle', $this->responseString('status'));
    }

    public function testMergeRejectsEvidenceLongerThanTheDurableQuoteLimit(): void
    {
        FixtureTreeAgent::$handler = function (Conversation $draft, string $attempt, TreeMergeTool $tools): string {
            $proposal = $this->proposal($draft);
            $result = $tools($this->treeId, $proposal);
            self::assertArrayHasKey('error', $result);
            self::assertStringContainsString('300', json_encode($result, JSON_THROW_ON_ERROR));

            return 'Please narrow the supporting quote.';
        };
        $this->send(str_repeat('A', 301), 'first');
        self::assertNull($this->responseArray('tools')['merge'] ?? null);
    }

    public function testConcurrentSendIsRejectedAndStopRejectsLateToolResultsAndAnswers(): void
    {
        FixtureTreeAgent::$handler = function (Conversation $draft, string $attempt, TreeMergeTool $tools): string {
            $chat = static::getContainer()->get(ChatInterface::class);
            try {
                $chat->send(
                    $draft,
                    ['version' => $draft->version, 'requestId' => 'new', 'content' => 'Her name is Veronika.'],
                );
                self::fail('An active execution must reject another send.');
            } catch (\App\Api\ApiException $error) {
                self::assertSame('CHAT_PROCESSING', $error->getErrorCode());
            }
            $chat->stop($draft, 'first');
            self::assertArrayHasKey('error', $tools($this->treeId, $this->proposal($draft)));

            return 'Obsolete answer.';
        };
        $this->send('Vera.', 'first');
        self::assertCount(2, $this->responseArray('messages'));
        self::assertSame('stopped', $this->responseString('messages', '1', 'failureCode'));
        self::assertSame('system', $this->responseString('messages', '1', 'role'));
        FixtureTreeAgent::$handler = static fn () => 'Her name is Veronika.';
        $this->send('Her name is Veronika.', 'new');
        self::assertSame('Her name is Veronika.', $this->responseString('messages', '3', 'content'));
    }

    public function testReviewedProposalEditsSupersedeTheRunningAgentAnswer(): void
    {
        FixtureTreeAgent::$handler = function (Conversation $draft, string $attempt, TreeMergeTool $tools): string {
            $proposal = $this->proposal($draft);
            self::assertArrayNotHasKey('error', $tools($this->treeId, $proposal));
            self::assertIsArray($proposal['people']);
            self::assertIsArray($proposal['people'][0]);
            self::assertIsArray($proposal['people'][0]['fields']);
            $proposal['people'][0]['fields']['givenName'] = ['state' => 'known', 'value' => 'Veronika'];
            $tree = static::getContainer()->get(\App\Repository\FamilyTreeRepository::class)->find($this->treeId);
            self::assertNotNull($tree);
            $pending = $draft->tools['merge'];
            self::assertInstanceOf(\App\Model\TreeMergeProposal::class, $pending);
            static::getContainer()->get(TreeMergeTool::class)->proposal(
                $tree,
                $pending->id->toRfc4122(),
                'revise',
                ['version' => $pending->version, 'proposal' => $proposal],
            );

            return 'An obsolete answer about Vera.';
        };
        $this->send('Vera.', 'first');
        self::assertSame('ready', $this->responseString('tools', 'merge', 'state'));
        self::assertSame(
            'Veronika',
            $this->responseString('tools', 'merge', 'proposal', 'people', '0', 'fields', 'givenName', 'value'),
        );
        self::assertCount(1, $this->responseArray('messages'));
    }

    /** @return array<string, mixed> */
    private function proposal(Conversation $draft): array
    {
        return [
            'people' => [
                [
                    'id'               => 'v',
                    'existingPersonId' => null,
                    'resolution'       => 'new',
                    'lifeStatus'       => 'alive',
                    'fields'           => [
                        'givenName' => ['state' => 'known', 'value' => 'Vera'],
                        'gender'    => 'female',
                        'birthDate' => ['state' => 'known', 'kind' => 'approximate', 'year' => 1938],
                    ],
                    'evidence' => [
                        [
                            'messageId' => FixtureTreeAgent::messages($draft)[0]['id'],
                            'quote'     => FixtureTreeAgent::messages($draft)[0]['content'],
                        ],
                    ],
                ],
            ],
            'parents'   => [],
            'marriages' => [],
            'questions' => [],
        ];
    }

    private function send(string $content, string $requestId): void
    {
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        $this->jsonRequest(
            'POST',
            $this->path . '/messages',
            ['version' => $this->responseData()['version'], 'requestId' => $requestId, 'content' => $content],
            $this->headers,
        );
        self::assertResponseIsSuccessful();
    }
}
