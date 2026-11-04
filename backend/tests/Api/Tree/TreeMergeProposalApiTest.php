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

    public function testPreviewIsReadOnlyAndApplyIsAtomicAndIdempotent(): void
    {
        $proposal = $this->family();
        $this->revise($proposal);
        self::assertResponseIsSuccessful();
        self::assertSame('ready', $this->responseString('state'));
        $this->snapshot();
        self::assertSame([], $this->responseData()['people']);
        self::assertSame($this->etag, $this->responseString('etag'));
        $this->apply();
        self::assertResponseIsSuccessful();
        self::assertSame('applied', $this->responseString('state'));
        $receipt = $this->responseData();
        $this->apply();
        self::assertSame($receipt, $this->responseData());
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
        self::assertSame('Ирина', $this->responseString('proposal', 'people', '0', 'before', 'givenName', 'value'));
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
        foreach ($em->getRepository(GenealogicalEvent::class)->findAll() as $event) {
            if ('child_birth' === $event->getType()->value && ($event->getData()['childId'] ?? null) === $ids['i']) {
                self::assertSame(1962, $event->getDate()->getYear());
            }
        }
    }

    public function testQuestionsAndVersionConflictRequireAnotherConfirmation(): void
    {
        $proposal = $this->family();
        $proposal['people'][0]['lifeStatus'] = 'uncertain';
        $proposal['people'][0]['resolution'] = 'undecided';
        $this->revise($proposal);
        self::assertSame('questions', $this->responseString('state'));
        self::assertNotContains('life_status:i', $this->arrayAt('questions'));
        self::assertSame('alive', $this->responseString('proposal', 'people', '0', 'lifeStatus'));
        self::assertContains('person_match:i', $this->arrayAt('questions'));
        $this->apply();
        self::assertResponseStatusCodeSame(409);
        $this->revise($this->family(), 2);
        $this->jsonRequest('PATCH', '/api/v1/trees/' . $this->treeId, ['name' => 'Renamed concurrently'],
            $this->headers + ['If-Match' => $this->etag]);
        self::assertResponseIsSuccessful();
        $newEtag = $this->responseString('etag');
        $this->apply(3, 2);
        self::assertResponseStatusCodeSame(412);
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        self::assertSame(3, $this->responseData()['proposalVersion']);
        self::assertSame('ready', $this->responseString('state'));
        self::assertSame($newEtag, $this->responseString('treeEtag'));
        $this->apply(3, 2);
        self::assertResponseStatusCodeSame(409);
        $this->etag = $newEtag;
        $this->apply(4, 3);
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
        foreach (['id' => '!bad', 'resolution' => 'delete', 'lifeStatus' => 'maybe', 'fields' => ['biography' => 'x'],
            'evidence' => [['messageId' => 'unknown', 'quote' => 'Invented']]] as $key => $value) {
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

    public function testEmptyProposalAndRevalidationAndChangedSource(): void
    {
        $this->jsonRequest('POST', $this->path . '/revalidate', ['version' => 1], $this->headers);
        self::assertResponseStatusCodeSame(409);
        $this->revise(['people' => [], 'parents' => [], 'marriages' => [], 'questions' => []]);
        self::assertSame('no_changes', $this->responseString('state'));
        $this->revise($this->family(), 2);
        $this->jsonRequest('POST', $this->path . '/revalidate', ['version' => 3], $this->headers);
        self::assertResponseIsSuccessful();
        $this->jsonRequest('POST', $this->path . '/messages',
            ['version' => 4, 'requestId' => 'clarification', 'content' => 'Вера родилась в 1940 году.'], $this->headers);
        self::assertResponseIsSuccessful();
        $this->apply(5, 4);
        self::assertResponseStatusCodeSame(409);
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        self::assertSame('collecting', $this->responseString('state'));
        self::assertCount(1, $this->arrayAt('messages'));
    }

    public function testOuterWorkflowRollbackIncludesPeopleEventsAndTreeVersion(): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $tree = $container->get(FamilyTreeRepository::class)->find($this->treeId);
        self::assertInstanceOf(FamilyTree::class, $tree);
        $data = $container->get(PersonDataPolicy::class)->create([
            'birthSurname' => ['state' => 'unknown'], 'givenName' => ['state' => 'known', 'value' => 'Rollback'],
            'patronymic'   => ['state' => 'unknown'], 'gender' => 'unknown',
            'birthDate'    => ['state' => 'unknown'], 'deathDate' => null,
        ]);
        $changes = new TreeChangeSet([new PersonChange('first', null, $data)]);
        $executor = $container->get(TreeChangeSetExecutor::class);
        try {
            $em->wrapInTransaction(function () use ($executor, $tree, $changes): void {
                $executor->execute($tree, $changes, $this->etag);
                throw new \RuntimeException('Receipt storage failed');
            });
        } catch (\RuntimeException $error) {
            self::assertSame('Receipt storage failed', $error->getMessage());
        }
        $this->snapshot();
        self::assertSame([], $this->responseData()['people']);
        self::assertSame($this->etag, $this->responseString('etag'));
        self::assertSame(0, static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(GenealogicalEvent::class)->count([]));
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
            $this->jsonRequest('PUT', $this->path . '/proposal', $payload, $this->headers);
            self::assertResponseStatusCodeSame([] === $payload ? 400 : 422);
        }
        $proposal = $this->family();
        for ($index = 0; $index < 2; ++$index) {
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
        self::assertSame('questions', $this->responseString('state'));
        $this->jsonRequest('POST', $this->path . '/cancel', ['version' => 2], $this->headers);
        self::assertResponseIsSuccessful();
        $this->revise($this->family(), 3);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('TREE_MERGE_CLOSED', $this->responseString('code'));
        $this->jsonRequest('POST', $this->path . '/apply', ['version' => 3], $this->headers);
        self::assertResponseStatusCodeSame(422);
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
        self::assertSame('no_changes', $this->responseString('state'));
        $proposal['marriages'][0]['marriageDate'] = ['state' => 'known', 'kind' => 'exact', 'year' => 1960];
        $this->revise($proposal, 2);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('MARRIAGE_PERIOD_CHOICE_REQUIRED', $this->responseString('code'));
        $proposal['marriages'][0]['newPeriod'] = true;
        $this->revise($proposal, 2);
        self::assertResponseIsSuccessful();
        $this->apply(3, 2);
        self::assertResponseIsSuccessful();
        $this->snapshot();
        self::assertCount(2, $this->arrayAt('marriages'));
    }

    /** @param array<string, mixed> $proposal */
    private function revise(array $proposal, int $version = 1): void
    {
        $this->jsonRequest('PUT', $this->path . '/proposal', ['version' => $version, 'proposal' => $proposal], $this->headers);
    }

    private function apply(int $version = 2, int $proposalVersion = 1): void
    {
        $this->jsonRequest('POST', $this->path . '/apply', ['version' => $version, 'proposalVersion' => $proposalVersion],
            $this->headers + ['If-Match' => $this->etag]);
    }

    private function snapshot(): void
    {
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertResponseIsSuccessful();
    }

    private function newDraft(mixed $reference = null): void
    {
        $base = '/api/v1/trees/' . $this->treeId . '/merges';
        $this->jsonRequest('POST', $base, ['referencePersonId' => $reference], $this->headers);
        self::assertResponseIsSuccessful();
        $this->path = $base . '/' . $this->responseString('id');
    }

    /** @return array{people: list<array{id: string, existingPersonId: mixed, resolution: string, fields: array<string, mixed>, lifeStatus: string, evidence: list<mixed>}>, parents: list<array<string, mixed>>, marriages: list<array<string, mixed>>, questions: list<string>} */
    private function family(): array
    {
        $people = [];
        foreach ([['i', 'Ирина', 'female'], ['v', 'Вера', 'female'], ['m', 'Михаил', 'male'], ['a', 'Александр', 'male']] as [$id, $name, $gender]) {
            $people[] = ['id' => $id, 'existingPersonId' => null, 'resolution' => 'new',
                'fields'      => ['givenName' => ['state' => 'known', 'value' => $name], 'gender' => $gender,
                    'birthDate'               => 'v' === $id ? ['state' => 'known', 'kind' => 'approximate', 'year' => 1938] : ['state' => 'unknown']],
                'lifeStatus' => 'deceased', 'evidence' => []];
        }

        return ['people' => $people, 'parents' => [
            ['parent' => 'v', 'child' => 'i', 'role' => 'mother', 'evidence' => []],
            ['parent' => 'v', 'child' => 'a', 'role' => 'mother', 'evidence' => []],
            ['parent' => 'm', 'child' => 'a', 'role' => 'father', 'evidence' => []],
        ], 'marriages' => [
            ['first'              => 'v', 'second' => 'm', 'marriageDate' => ['state' => 'unknown'],
                'dissolutionDate' => null, 'newPeriod' => false, 'evidence' => []],
        ], 'questions' => []];
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
