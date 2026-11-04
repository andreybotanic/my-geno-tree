<?php

namespace App\Tests\Api\Tree;

use App\Api\PersonViewFactory;
use App\Application\Person\PersonDataPolicy;
use App\Application\Tree\ChangeSet\ParentChange;
use App\Application\Tree\ChangeSet\PersonChange;
use App\Application\Tree\ChangeSet\TreeChangeSet;
use App\Application\Tree\ChangeSet\TreeChangeSetExecutor;
use App\Application\TreeMerge\Ai\Dto\MergeAiResponse;
use App\Application\TreeMerge\Ai\MergeAiResult;
use App\Entity\FamilyTree;
use App\Entity\TreeMergeDraft;
use App\Entity\Value\ParentRole;
use App\Repository\PersonRepository;
use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Ai\FixtureTreeMergeAi;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\SerializerInterface;

/** @phpstan-import-type ContextPerson from MergeAiResult */
final class TreeMergeNameMatchingApiTest extends ApiWebTestCase
{
    /** @var array<string, string> */
    private array $headers;
    private string $treeId;
    private string $path;
    /** @var array<string, string> */
    private array $ids;

    private function seed(bool $namesake): void
    {
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration(['language' => 'ru']));
        $this->headers = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Named relatives'], $this->headers);
        $this->treeId = $this->responseString('id');
        $etag = $this->responseString('etag');
        $container = static::getContainer();
        $tree = $container->get(EntityManagerInterface::class)->getRepository(FamilyTree::class)->find($this->treeId);
        self::assertNotNull($tree);
        $fields = ['givenName' => ['state' => 'known', 'value' => 'Иван'], 'birthSurname' => ['state' => 'unknown'],
            'patronymic'       => ['state' => 'unknown'], 'gender' => 'male', 'birthDate' => ['state' => 'unknown'], 'deathDate' => null];
        $policy = $container->get(PersonDataPolicy::class);
        $people = [new PersonChange('i', null, $policy->create($fields))];
        if ($namesake) {
            $fields['patronymic'] = ['state' => 'known', 'value' => 'Витальевич'];
            $people[] = new PersonChange('f', null, $policy->create($fields));
        }
        $receipt = $container->get(TreeChangeSetExecutor::class)->execute($tree, new TreeChangeSet($people,
            $namesake ? [new ParentChange('f', 'i', ParentRole::FATHER)] : [], []), $etag);
        $this->ids = $receipt['personIds'];
        $base = '/api/v1/trees/' . $this->treeId . '/merges';
        $this->jsonRequest('POST', $base, ['referencePersonId' => null], $this->headers);
        self::assertResponseStatusCodeSame(201);
        $this->path = $base . '/' . $this->responseString('id');
    }

    public function testUniqueIvanIsMatchedAndHisNamesakeFatherIsNewWithoutAnIdentityPicker(): void
    {
        $this->seed(false);
        $this->process('Родители Ивана — Иван Витальевич и Анна Андреевна. Все живы.', true);
        self::assertSame('ready', $this->responseString('state'));
        self::assertSame('existing', $this->responseString('proposal', 'people', '0', 'resolution'));
        self::assertSame($this->ids['i'], $this->responseString('proposal', 'people', '0', 'existingPersonId'));
        self::assertSame('new', $this->responseString('proposal', 'people', '1', 'resolution'));
        self::assertSame([], $this->responseData()['questions']);
        $draft = $this->responseData();
        $this->jsonRequest('POST', $this->path . '/apply', ['version' => $draft['version'], 'proposalVersion' => $draft['proposalVersion']],
            $this->headers + ['If-Match' => $this->responseString('treeEtag')]);
        self::assertSame('applied', $this->responseString('state'));
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertIsArray($people = $this->responseData()['people']);
        self::assertCount(3, $people);
        self::assertIsArray($links = $this->responseData()['parentLinks']);
        self::assertCount(2, $links);
    }

    public function testTwoIvansRequireClarificationButAUniqueFullNameDoesNot(): void
    {
        $this->seed(true);
        $this->process('Иван жив.', false);
        self::assertSame('questions', $this->responseString('state'));
        self::assertSame('undecided', $this->responseString('proposal', 'people', '0', 'resolution'));
        self::assertIsArray($questions = $this->responseData()['questions']);
        self::assertContains('person_match:i', $questions);
        $this->process('Я имею в виду Ивана Витальевича.', false, true);
        self::assertSame('no_changes', $this->responseString('state'));
        self::assertSame($this->ids['f'], $this->responseString('proposal', 'people', '0', 'existingPersonId'));
    }

    private function process(string $text, bool $parents, bool $fullName = false): void
    {
        FixtureTreeMergeAi::$handler = function (TreeMergeDraft $draft) use ($parents, $fullName): MergeAiResult {
            self::assertNotEmpty($draft->messages);
            $source = array_find(array_reverse($draft->messages), static fn ($message) => 'user' === $message['role']);
            self::assertNotNull($source);
            $evidence = [['messageId' => $source['id'], 'quote' => $source['content']]];
            $id = $this->ids[$fullName ? 'f' : 'i'];
            $person = static::getContainer()->get(PersonRepository::class)->findInTree($draft->tree, $id);
            self::assertNotNull($person);
            /** @var ContextPerson $context */
            $context = array_intersect_key(static::getContainer()->get(PersonViewFactory::class)->create($person), array_flip(['id', 'givenName', 'patronymic', 'birthSurname', 'gender', 'birthDate', 'deathDate']));
            $people = [['id' => 'i', 'existingPersonId' => $id, 'resolution' => 'existing', 'lifeStatus' => 'alive',
                'fields'     => $fullName ? ['patronymic' => 'Витальевич'] : new \stdClass(), 'evidence' => $evidence]];
            if ($parents) {
                // Real models sometimes put the identifying name only on the parent link.
                $people[0]['evidence'] = [['messageId' => $source['id'], 'quote' => 'Все живы.']];
                $people[] = ['id' => 'f', 'existingPersonId' => null, 'resolution' => 'new', 'lifeStatus' => 'alive',
                    'fields'      => ['givenName' => 'Иван', 'patronymic' => 'Витальевич', 'gender' => 'male'], 'evidence' => $evidence];
                $people[] = ['id' => 'm', 'existingPersonId' => null, 'resolution' => 'new', 'lifeStatus' => 'alive',
                    'fields'      => ['givenName' => 'Анна', 'patronymic' => 'Андреевна', 'gender' => 'female'], 'evidence' => $evidence];
            }
            $response = static::getContainer()->get(SerializerInterface::class)->deserialize(json_encode(['action' => 'proposal', 'proposal' => [
                'people' => $people, 'parents' => $parents ? [['parent' => 'f', 'child' => 'i', 'role' => 'father', 'evidence' => $evidence],
                    ['parent' => 'm', 'child' => 'i', 'role' => 'mother', 'evidence' => $evidence]] : [], 'marriages' => [], 'questions' => []]], JSON_THROW_ON_ERROR), MergeAiResponse::class, 'json');

            return new MergeAiResult($response, [$id => $context]);
        };
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        $version = $this->responseData()['version'];
        self::assertIsInt($version);
        $request = 'message-' . $version;
        $this->jsonRequest('POST', $this->path . '/messages', ['version' => $this->responseData()['version'], 'requestId' => $request, 'content' => $text], $this->headers);
        $this->jsonRequest('POST', $this->path . '/process', ['version' => $this->responseData()['version'], 'requestId' => $request], $this->headers);
        self::assertResponseIsSuccessful();
    }
}
