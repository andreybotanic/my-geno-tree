<?php

namespace App\Tests\Api\Tree;

use App\Application\Person\PersonDataPolicy;
use App\Application\Tree\ChangeSet\PersonChange;
use App\Application\Tree\ChangeSet\TreeChangeSet;
use App\Application\Tree\ChangeSet\TreeChangeSetExecutor;
use App\Application\Tree\ChangeSet\TreeChangeSetProjector;
use App\Entity\FamilyTree;
use App\Entity\GenealogicalEvent;
use App\Entity\Person;
use App\Repository\FamilyTreeRepository;
use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Doctrine\QueryCountingMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class TreeMergeProposalApiTest extends ApiWebTestCase
{
    /** @var array<string, string> */
    private array $headers;
    private string $treeId;
    private string $etag;
    private string $path;
    private string $chatPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        $this->headers = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Merge preview'], $this->headers);
        $this->treeId = $this->responseString('id');
        $this->etag = $this->responseString('etag');
        $this->newDraft();
    }

    public function testMessageStoreTtlRemovesHistoryAndProposalTogetherWithoutAnHttpCleanup(): void
    {
        $this->revise($this->family());
        $id = Uuid::fromString(basename($this->chatPath));
        self::assertInstanceOf(\Symfony\Component\Uid\UuidV7::class, $id);
        $store = new \Symfony\AI\Agent\Bridge\Cache\MessageStore(
            static::getContainer()->get('cache.chat'),
            'chat',
            ttl: 1,
        );
        $messages = $store->load($id);
        $conversation = $messages->getMetadata()->get(\App\Model\Conversation::METADATA);
        self::assertInstanceOf(\App\Model\Conversation::class, $conversation);
        self::assertArrayHasKey('merge', $conversation->tools);
        self::assertNotNull($messages->getUserMessage());
        $store->save($messages);
        usleep(1_100_000);
        $expired = $store->load($id);
        self::assertCount(0, $expired);
        self::assertFalse($expired->getMetadata()->has(\App\Model\Conversation::METADATA));
        $this->apply();
        self::assertResponseStatusCodeSame(404);
        $this->snapshot();
        self::assertSame([], $this->responseData()['people']);
    }

    public function testDiscardRemovesOnlyTheProposalAndPreservesConversationAndUnappliedTree(): void
    {
        $this->revise($this->family());
        $before = $this->responseData();
        $this->jsonRequest('DELETE', $this->path, ['version' => 2], $this->headers);
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('merge', $this->responseArray('tools'));
        self::assertSame($before['id'], $this->responseData()['id']);
        self::assertSame($before['messages'], $this->responseArray('messages'));
        $version = $this->responseData()['version'];
        $this->jsonRequest('DELETE', $this->path, ['version' => 2], $this->headers);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest(
            'POST',
            $this->chatPath . '/messages',
            ['version' => $version, 'requestId' => 'next', 'content' => 'Let us continue.'],
            $this->headers,
        );
        self::assertResponseIsSuccessful();
        self::assertCount(4, $this->arrayAt('messages'));
        $this->snapshot();
        self::assertSame([], $this->responseData()['people']);
    }

    public function testPreviewIsReadOnlyAndConsumedProposalCannotBeAppliedTwice(): void
    {
        $proposal = $this->family();
        $this->revise($proposal);
        self::assertResponseIsSuccessful();
        self::assertSame('ready', $this->responseString('tools', 'merge', 'state'));
        $this->snapshot();
        self::assertSame([], $this->responseData()['people']);
        self::assertSame($this->etag, $this->responseString('etag'));
        $this->apply();
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('merge', $this->responseArray('conversation', 'tools'));
        $this->apply();
        self::assertResponseStatusCodeSame(404);
        $this->snapshot();
        self::assertCount(4, $this->arrayAt('people'));
        self::assertCount(3, $this->arrayAt('parentLinks'));
        self::assertCount(1, $this->arrayAt('marriages'));
        self::assertNotSame($this->etag, $this->responseString('etag'));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(13, $em->getRepository(GenealogicalEvent::class)->count([]));
        $vera = $em->getRepository(Person::class)->findOneBy(['givenName.value' => 'Вера']);
        self::assertInstanceOf(Person::class, $vera);
        self::assertSame('approximate', $vera->getBirthDate()->getKind()?->value);
        self::assertNotNull($vera->getDeathDate());
        self::assertFalse($vera->getDeathDate()->isKnown());
    }

    public function testAChatBanDoesNotPreventApplyingAnAlreadyPreparedProposal(): void
    {
        $this->revise($this->family());
        self::assertResponseIsSuccessful();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $manager->getRepository(\App\Entity\User::class)->findOneBy(['username' => 'family_admin']);
        self::assertInstanceOf(\App\Entity\User::class, $user);
        $now = new \DateTimeImmutable();
        $user->warnAboutChat($now, 3600, 0);
        $user->warnAboutChat($now, 3600, 0);
        $ban = $user->warnAboutChat($now, 3600, 0);
        self::assertNotNull($ban);
        $manager->persist($ban);
        $manager->flush();
        $this->apply();
        self::assertResponseIsSuccessful();
        self::assertSame('permanent_ban', $this->responseString('conversation', 'access', 'status'));
        $this->snapshot();
        self::assertCount(4, $this->arrayAt('people'));
    }

    public function testRevalidationConflictsWhenTheProposedPersonWasDeleted(): void
    {
        $this->revise($this->family());
        $this->apply();
        $personId = $this->responseString('result', 'personIds', 'i');
        $this->snapshot();
        $this->etag = $this->responseString('etag');
        $this->newDraft();
        $this->revise(
            [
                'people' => [
                    [
                        'id'               => 'i',
                        'existingPersonId' => $personId,
                        'resolution'       => 'existing',
                        'fields'           => ['givenName' => ['state' => 'known', 'value' => 'Ирина Михайловна']],
                        'lifeStatus'       => 'deceased',
                        'evidence'         => [],
                    ],
                ],
                'parents'   => [],
                'marriages' => [],
                'questions' => [],
            ]
        );
        self::assertResponseIsSuccessful();
        self::assertSame('ready', $this->responseString('tools', 'merge', 'state'));
        $proposalId = $this->responseString('tools', 'merge', 'id');
        $this->jsonRequest(
            'POST',
            '/api/v1/trees/' . $this->treeId . '/graph-mutations',
            ['type' => 'delete_person', 'personId' => $personId],
            $this->headers + ['If-Match' => $this->etag],
        );
        self::assertResponseIsSuccessful();
        $this->snapshot();
        $this->etag = $this->responseString('etag');
        $this->jsonRequest('POST', $this->path . '/revalidate', ['version' => 2], $this->headers);
        self::assertResponseIsSuccessful();
        self::assertSame('conflict', $this->responseString('tools', 'merge', 'state'));
        self::assertSame($proposalId, $this->responseString('tools', 'merge', 'id'));
        self::assertSame(3, $this->responseInt('tools', 'merge', 'version'));
        self::assertSame($this->etag, $this->responseString('tools', 'merge', 'treeEtag'));
        self::assertContains(
            'TREE_MERGE_PROPOSAL_INVALID',
            $this->responseArray('tools', 'merge', 'proposal', 'questions'),
        );
        $this->apply(3);
        self::assertResponseStatusCodeSame(409);
        $this->snapshot();
        self::assertCount(3, $this->responseArray('people'));
    }

    public function testUpdatesExistingValuesAndChildBirthEventsAndDeduplicatesRelationships(): void
    {
        $this->revise($this->family());
        $this->apply();
        $ids = $this->arrayAt('result', 'personIds');
        $this->snapshot();
        $this->etag = $this->responseString('etag');
        $this->newDraft($ids['i']);
        $proposal = $this->family();
        foreach ($proposal['people'] as &$person) {
            $person['existingPersonId'] = $ids[$person['id']];
            $person['resolution'] = 'existing';
        }
        unset($person);
        $proposal['people'][0]['fields']['givenName'] = ['state' => 'known', 'value' => 'Ирина Михайловна'];
        $proposal['people'][0]['fields']['birthDate'] = ['state' => 'known', 'kind' => 'exact', 'year' => 1962];
        $this->revise($proposal);
        self::assertResponseIsSuccessful();
        self::assertSame(
            'Ирина',
            $this->responseString('tools', 'merge', 'proposal', 'people', '0', 'before', 'givenName', 'value'),
        );
        $this->apply();
        self::assertResponseIsSuccessful();
        $this->snapshot();
        self::assertCount(4, $this->arrayAt('people'));
        self::assertCount(3, $this->arrayAt('parentLinks'));
        self::assertCount(1, $this->arrayAt('marriages'));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $irina = $em->getRepository(Person::class)->find($ids['i']);
        self::assertInstanceOf(Person::class, $irina);
        self::assertSame('Ирина Михайловна', $irina->getGivenName()->getValue());
        $events = array_values(
            array_filter(
                $em->getRepository(GenealogicalEvent::class)->findAll(),
                static fn (GenealogicalEvent $event) => 'child_birth' === $event->getType()->value
                    && ($event->getData()['childId'] ?? null) === $ids['i'],
            ),
        );
        self::assertCount(1, $events);
        self::assertSame(1962, $events[0]->getDate()->getYear());
    }

    public function testQuestionsAndVersionConflictRequireAnotherConfirmation(): void
    {
        $proposal = $this->family();
        $proposal['people'][0]['lifeStatus'] = 'uncertain';
        $proposal['people'][0]['resolution'] = 'undecided';
        $proposal['questions'] = ['Is this a new person?'];
        $this->revise($proposal);
        self::assertSame('questions', $this->responseString('tools', 'merge', 'state'));
        self::assertNotContains('life_status:i', $this->arrayAt('tools', 'merge', 'proposal', 'questions'));
        self::assertSame('alive', $this->responseString('tools', 'merge', 'proposal', 'people', '0', 'lifeStatus'));
        self::assertContains('Is this a new person?', $this->arrayAt('tools', 'merge', 'proposal', 'questions'));
        $this->apply();
        self::assertResponseStatusCodeSame(409);
        $this->revise($this->family(), 2);
        $this->jsonRequest(
            'PATCH',
            '/api/v1/trees/' . $this->treeId,
            ['name' => 'Renamed concurrently'],
            $this->headers + ['If-Match' => $this->etag]
        );
        self::assertResponseIsSuccessful();
        $newEtag = $this->responseString('etag');
        $this->apply(3);
        self::assertResponseStatusCodeSame(412);
        $this->jsonRequest('GET', $this->chatPath, headers: $this->headers);
        self::assertSame(5, $this->responseInt('tools', 'merge', 'version'));
        self::assertSame('ready', $this->responseString('tools', 'merge', 'state'));
        self::assertSame($newEtag, $this->responseString('tools', 'merge', 'treeEtag'));
        $this->apply(3);
        self::assertResponseStatusCodeSame(409);
        $this->etag = $newEtag;
        $this->apply(5);
        self::assertResponseIsSuccessful();
    }

    public function testRejectsCyclesChronologyForeignReferencesAndUnsupportedOperations(): void
    {
        $bad = $this->family();
        $bad['parents'][] = ['parent' => 'i', 'child' => 'v', 'role' => 'mother', 'evidence' => []];
        $this->revise($bad);
        self::assertResponseStatusCodeSame(422);
        $bad = $this->family();
        $bad['people'][1]['fields']['birthDate'] = ['state' => 'known', 'kind' => 'exact', 'year' => 2000];
        $bad['people'][0]['fields']['birthDate'] = ['state' => 'known', 'kind' => 'exact', 'year' => 1960];
        $this->revise($bad);
        self::assertResponseStatusCodeSame(422);
        $bad = $this->family();
        $bad['people'][0]['existingPersonId'] = Uuid::v7()->toRfc4122();
        $bad['people'][0]['resolution'] = 'existing';
        $this->revise($bad);
        self::assertResponseStatusCodeSame(422);
        foreach ([
            'id'         => '!bad',
            'resolution' => 'delete',
            'lifeStatus' => 'maybe',
            'fields'     => ['biography' => 'x'],
            'evidence'   => [['messageId' => 'unknown', 'quote' => 'Invented']],
        ] as $key => $value) {
            $bad = $this->family();
            $bad['people'][0][$key] = $value;
            $this->revise($bad);
            self::assertResponseStatusCodeSame(422);
        }
        $bad = $this->family();
        $bad['people'][] = $bad['people'][0];
        $this->revise($bad);
        self::assertResponseStatusCodeSame(422);
        $bad = $this->family();
        $bad['parents'] = [];
        $bad['marriages'] = [];
        $this->revise($bad);
        self::assertResponseStatusCodeSame(422);
        $bad = $this->family();
        $bad['parents'][0]['parent'] = 'missing';
        $this->revise($bad);
        self::assertResponseStatusCodeSame(422);
        $bad = $this->family();
        $bad['marriages'][0]['newPeriod'] = 'yes';
        $this->revise($bad);
        self::assertResponseStatusCodeSame(422);
        $this->snapshot();
        self::assertSame([], $this->responseData()['people']);
        self::assertSame($this->etag, $this->responseString('etag'));
    }

    public function testEmptyProposalRevalidationAndChatMessagesHaveSeparateVersions(): void
    {
        $this->jsonRequest('POST', $this->path . '/revalidate', ['version' => 99], $this->headers);
        self::assertResponseStatusCodeSame(409);
        $this->revise(['people' => [], 'parents' => [], 'marriages' => [], 'questions' => []]);
        self::assertSame('no_changes', $this->responseString('tools', 'merge', 'state'));
        $this->revise($this->family(), 2);
        $this->jsonRequest('POST', $this->path . '/revalidate', ['version' => 3], $this->headers);
        self::assertResponseIsSuccessful();
        $chatVersion = $this->responseData()['version'];
        \App\Tests\Infrastructure\Ai\FixtureTreeAgent::$handler = static fn () => 'The proposal is ready for review.';
        $this->jsonRequest(
            'POST',
            $this->chatPath . '/messages',
            ['version' => $chatVersion, 'requestId' => 'clarification', 'content' => 'Please explain the preview.'],
            $this->headers,
        );
        self::assertResponseIsSuccessful();
        self::assertSame(4, $this->responseInt('tools', 'merge', 'version'));
        self::assertCount(4, $this->arrayAt('messages'));
        $this->apply(4);
        self::assertResponseIsSuccessful();
    }

    public function testOuterWorkflowRollbackIncludesPeopleEventsAndTreeVersion(): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $tree = $container->get(FamilyTreeRepository::class)->find($this->treeId);
        self::assertInstanceOf(FamilyTree::class, $tree);
        $data = $container
            ->get(PersonDataPolicy::class)
            ->create(
                [
                    'birthSurname' => ['state' => 'unknown'],
                    'givenName'    => ['state' => 'known', 'value' => 'Rollback'],
                    'patronymic'   => ['state' => 'unknown'],
                    'gender'       => 'unknown',
                    'birthDate'    => ['state' => 'unknown'],
                    'deathDate'    => null,
                ]
            );
        $changes = new TreeChangeSet([new PersonChange('first', null, $data)]);
        $executor = $container->get(TreeChangeSetExecutor::class);
        try {
            $em->wrapInTransaction(
                function () use ($executor, $tree, $changes): void {
                    $executor->execute($tree, $changes, $this->etag);
                    throw new \RuntimeException('Outer workflow failed');
                }
            );
        } catch (\RuntimeException $error) {
            self::assertSame('Outer workflow failed', $error->getMessage());
        }
        $this->snapshot();
        self::assertSame([], $this->responseData()['people']);
        self::assertSame($this->etag, $this->responseString('etag'));
        self::assertSame(
            0,
            static::getContainer()
                ->get(EntityManagerInterface::class)
                ->getRepository(GenealogicalEvent::class)
                ->count([])
        );
    }

    public function testProjectionLoadsGraphWithConstantQueryCount(): void
    {
        $this->revise($this->family());
        $this->apply();
        $container = static::getContainer();
        $tree = $container->get(FamilyTreeRepository::class)->find($this->treeId);
        self::assertInstanceOf(FamilyTree::class, $tree);
        $counter = $container->get(QueryCountingMiddleware::class . '.default');
        self::assertInstanceOf(QueryCountingMiddleware::class, $counter);
        $counter->reset();
        $projector = $container->get(TreeChangeSetProjector::class);
        $projection = $projector->project($tree, new TreeChangeSet([]), new \DateTimeImmutable());
        self::assertCount(4, $projection->people);
        self::assertSame(3, $counter->count());
    }

    public function testLimitsMalformedProposalsAndClosedDrafts(): void
    {
        foreach ([[], ['version' => 1], ['version' => 1, 'proposal' => null]] as $payload) {
            $this->jsonRequest('PUT', $this->path, $payload, $this->headers);
            self::assertResponseStatusCodeSame([] === $payload ? 400 : 422);
        }
        $proposal = $this->family();
        for ($index = 0; $index < 7; ++$index) {
            $person = $proposal['people'][0];
            $person['id'] = 'extra' . $index;
            $proposal['people'][] = $person;
        }
        $this->revise($proposal);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['new_people_limit'], $this->arrayAt('fields', 'people'));
        $proposal = $this->family();
        $proposal['questions'] = [12];
        $this->revise($proposal);
        self::assertResponseStatusCodeSame(422);
        $proposal['questions'] = ['Which person?'];
        $this->revise($proposal);
        self::assertSame('questions', $this->responseString('tools', 'merge', 'state'));
        $this->jsonRequest(
            'POST',
            $this->chatPath . '/reset',
            ['version' => $this->responseData()['version']],
            $this->headers,
        );
        self::assertResponseStatusCodeSame(204);
        $this->newDraft();
        $this->revise($this->family());
        self::assertResponseIsSuccessful();
        self::assertSame('ready', $this->responseString('tools', 'merge', 'state'));
        $this->jsonRequest('POST', $this->path . '/apply', ['version' => 'invalid'], $this->headers);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAllowsTenNewPeopleAlongsideExistingPeople(): void
    {
        $this->revise($this->family());
        $this->apply();
        $ids = $this->arrayAt('result', 'personIds');
        $this->snapshot();
        $this->etag = $this->responseString('etag');
        $this->newDraft();
        $proposal = $this->family();
        foreach ($proposal['people'] as &$person) {
            $person['resolution'] = 'existing';
            $person['existingPersonId'] = $ids[$person['id']];
        }
        unset($person);
        for ($index = 0; $index < 10; ++$index) {
            $id = 'child' . $index;
            $proposal['people'][] = [
                'id'               => $id,
                'existingPersonId' => null,
                'resolution'       => 'new',
                'fields'           => ['givenName' => ['state' => 'known', 'value' => 'Child ' . $index]],
                'lifeStatus'       => 'alive',
                'evidence'         => [],
            ];
            $proposal['parents'][] = ['parent' => 'v', 'child' => $id, 'role' => 'mother', 'evidence' => []];
        }
        $this->revise($proposal);
        self::assertResponseIsSuccessful();
        self::assertSame('ready', $this->responseString('tools', 'merge', 'state'));
        self::assertCount(14, $this->arrayAt('tools', 'merge', 'proposal', 'people'));
        $this->snapshot();
        self::assertCount(4, $this->arrayAt('people'));
        $this->apply();
        self::assertResponseIsSuccessful();
        $this->snapshot();
        self::assertCount(14, $this->arrayAt('people'));
        self::assertCount(13, $this->arrayAt('parentLinks'));
    }

    public function testExistingMatchNeedsExplicitNewMarriagePeriodAndDetectsNoChanges(): void
    {
        $this->revise($this->family());
        $this->apply();
        $ids = $this->arrayAt('result', 'personIds');
        $this->snapshot();
        $this->etag = $this->responseString('etag');
        $this->newDraft($ids['i']);
        $proposal = $this->family();
        foreach ($proposal['people'] as &$person) {
            $person['resolution'] = 'existing';
            $person['existingPersonId'] = $ids[$person['id']];
        }
        unset($person);
        $this->revise($proposal);
        self::assertResponseIsSuccessful();
        self::assertSame('no_changes', $this->responseString('tools', 'merge', 'state'));
        $proposal['marriages'][0]['marriageDate'] = ['state' => 'known', 'kind' => 'exact', 'year' => 1960];
        $this->revise($proposal, 2);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('MARRIAGE_PERIOD_CHOICE_REQUIRED', $this->responseString('code'));
        $proposal['marriages'][0]['newPeriod'] = true;
        $this->revise($proposal, 2);
        self::assertResponseIsSuccessful();
        $this->apply(3);
        self::assertResponseIsSuccessful();
        $this->snapshot();
        self::assertCount(2, $this->arrayAt('marriages'));
    }

    /** @param array<string, mixed> $proposal */
    private function revise(array $proposal, int $version = 1): void
    {
        $this->jsonRequest('PUT', $this->path, ['version' => $version, 'proposal' => $proposal], $this->headers);
    }

    private function apply(int $version = 2): void
    {
        $this->jsonRequest(
            'POST',
            $this->path . '/apply',
            ['version' => $version],
            $this->headers + ['If-Match' => $this->etag]
        );
    }

    private function snapshot(): void
    {
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertResponseIsSuccessful();
    }

    private function newDraft(mixed $reference = null): void
    {
        $base = '/api/v1/trees/' . $this->treeId . '/chat';
        $this->jsonRequest('GET', $base, headers: $this->headers);
        $current = $this->responseData()['conversation'];
        if (is_array($current)) {
            self::assertIsString($current['id']);
            $this->jsonRequest(
                'POST',
                '/api/v1/chats/' . $current['id'] . '/reset',
                ['version' => $current['version']],
                $this->headers,
            );
        }
        $this->jsonRequest('POST', $base, ['referencePersonId' => $reference], $this->headers);
        self::assertResponseIsSuccessful();
        $this->chatPath = '/api/v1/chats/' . $this->responseString('id');
        \App\Tests\Infrastructure\Ai\FixtureTreeAgent::$handler =
            static fn (\App\Model\Conversation $conversation) => \App\Tests\Infrastructure\Ai\FixtureTreeAgent::reply(
                $conversation,
                'Please review.',
                ['people' => [], 'parents' => [], 'marriages' => [], 'questions' => []],
            );
        $this->jsonRequest(
            'POST',
            $this->chatPath . '/messages',
            ['version' => 1, 'requestId' => 'initial', 'content' => 'Fictional family for this test.'],
            $this->headers,
        );
        self::assertResponseIsSuccessful();
        $this->path = '/api/v1/trees/' . $this->treeId . '/merge-proposals/'
            . $this->responseString('tools', 'merge', 'id');
    }

    /**
     * @return array{
     *     people: list<array{
     *         id: string,
     *         existingPersonId: mixed,
     *         resolution: string,
     *         fields: array<string, mixed>,
     *         lifeStatus: string,
     *         evidence: list<mixed>
     *     }>,
     *     parents: list<array<string, mixed>>,
     *     marriages: list<array<string, mixed>>,
     *     questions: list<string>
     * }
     */
    private function family(): array
    {
        $people = [];
        foreach ([
            ['i', 'Ирина', 'female'],
            ['v', 'Вера', 'female'],
            ['m', 'Михаил', 'male'],
            ['a', 'Александр', 'male'],
        ] as [$id, $name, $gender]) {
            $people[] = [
                'id'               => $id,
                'existingPersonId' => null,
                'resolution'       => 'new',
                'fields'           => [
                    'givenName' => ['state' => 'known', 'value' => $name],
                    'gender'    => $gender,
                    'birthDate' => 'v' === $id ? [
                        'state' => 'known',
                        'kind'  => 'approximate',
                        'year'  => 1938,
                    ] : ['state' => 'unknown'],
                ],
                'lifeStatus' => 'deceased',
                'evidence'   => [],
            ];
        }

        return [
            'people'  => $people,
            'parents' => [
                ['parent' => 'v', 'child' => 'i', 'role' => 'mother', 'evidence' => []],
                ['parent' => 'v', 'child' => 'a', 'role' => 'mother', 'evidence' => []],
                ['parent' => 'm', 'child' => 'a', 'role' => 'father', 'evidence' => []],
            ],
            'marriages' => [
                [
                    'first'           => 'v',
                    'second'          => 'm',
                    'marriageDate'    => ['state' => 'unknown'],
                    'dissolutionDate' => null,
                    'newPeriod'       => false,
                    'evidence'        => [],
                ],
            ],
            'questions' => [],
        ];
    }

    /** @return array<array-key, mixed> */
    private function arrayAt(string ...$path): array
    {
        $value = $this->responseData();
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }
        self::assertIsArray($value);

        return $value;
    }
}
