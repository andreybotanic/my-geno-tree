<?php

namespace App\Tests\Api\Tree;

use App\Application\Person\PersonDataPolicy;
use App\Application\Tree\ChangeSet\PersonChange;
use App\Application\Tree\ChangeSet\TreeChangeSet;
use App\Application\Tree\ChangeSet\TreeChangeSetExecutor;
use App\Entity\FamilyTree;
use App\Entity\TreeMergeDraft;
use App\Mcp\TreeReadTools;
use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Ai\FixtureTreeMergeAi;
use Doctrine\ORM\EntityManagerInterface;

final class TreeMergeFamilyProposalApiTest extends ApiWebTestCase
{
    public function testWholeFamilyIncludingUnnamedChildrenAndContextInferencesIsReviewedAndApplied(): void
    {
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration(['language' => 'ru']));
        $headers = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Complete family'], $headers);
        $treeId = $this->responseString('id');
        $tree = static::getContainer()->get(EntityManagerInterface::class)->find(FamilyTree::class, $treeId);
        self::assertNotNull($tree);
        $data = static::getContainer()->get(PersonDataPolicy::class)->create([
            'givenName'  => ['state' => 'known', 'value' => 'Иван'], 'birthSurname' => ['state' => 'known', 'value' => 'Иванов'],
            'patronymic' => ['state' => 'known', 'value' => 'Иванович'], 'gender' => 'male',
            'birthDate'  => ['state' => 'known', 'kind' => 'exact', 'year' => 1990, 'month' => 4, 'day' => 1], 'deathDate' => null,
        ]);
        $receipt = static::getContainer()->get(TreeChangeSetExecutor::class)->execute($tree,
            new TreeChangeSet([new PersonChange('ivan', null, $data)], [], []), $this->responseString('etag'));
        $ivanId = $receipt['personIds']['ivan'];
        $base = '/api/v1/trees/' . $treeId . '/merges';
        $this->jsonRequest('POST', $base, ['referencePersonId' => null], $headers);
        $path = $base . '/' . $this->responseString('id');
        $text = 'Добавь Ивану родителей - Иван Васильевич (10.10.1970) и Ирина Геннадьевна (1980). У нах есть еще один сын - Петр. У Ивана Ивановича с его женой Анной (она на пару лет младше) есть два сына, но о них ничего не известно.';
        $this->jsonRequest('POST', $path . '/messages', ['version' => $this->responseData()['version'], 'requestId' => 'family', 'content' => $text], $headers);
        FixtureTreeMergeAi::$handler = static function (TreeMergeDraft $draft) use ($ivanId, $text): string {
            $evidence = [['messageId' => FixtureTreeMergeAi::messages($draft)[0]['id'], 'quote' => $text]];
            $people = [];
            foreach (['i' => [], 'f' => ['givenName' => 'Иван', 'patronymic' => 'Васильевич', 'birthDate' => '1970-10-10', 'gender' => 'male'],
                'm'       => ['givenName' => 'Ирина', 'patronymic' => 'Геннадьевна', 'birthDate' => '1980', 'gender' => 'female'],
                'p'       => ['givenName' => 'Петр', 'birthSurname' => 'Иванов', 'patronymic' => 'Иванович', 'gender' => 'male'],
                'a'       => ['givenName' => 'Анна', 'birthDate' => '~1992', 'gender' => 'female'],
                'c1'      => ['gender' => 'male'], 'c2' => ['gender' => 'male']] as $id => $fields) {
                foreach (['givenName', 'birthSurname', 'patronymic'] as $field) {
                    if (isset($fields[$field])) {
                        $fields[$field] = ['state' => 'known', 'value' => $fields[$field]];
                    }
                }
                if (isset($fields['birthDate'])) {
                    $fields['birthDate'] = match ($id) {
                        'f'     => ['state' => 'known', 'kind' => 'exact', 'year' => 1970, 'month' => 10, 'day' => 10],
                        'm'     => ['state' => 'known', 'kind' => 'exact', 'year' => 1980],
                        'a'     => ['state' => 'known', 'kind' => 'approximate', 'year' => 1992],
                        default => throw new \LogicException('Unexpected dated fixture'),
                    };
                }
                $people[] = ['id' => $id, 'existingPersonId' => 'i' === $id ? $ivanId : null, 'resolution' => 'i' === $id ? 'existing' : 'new',
                    'lifeStatus'  => 'alive', 'fields' => $fields, 'evidence' => $evidence];
            }
            $parents = [];
            foreach (['i' => ['f', 'm'], 'p' => ['f', 'm'], 'c1' => ['i', 'a'], 'c2' => ['i', 'a']] as $child => [$father, $mother]) {
                $parents[] = ['parent' => $father, 'child' => $child, 'role' => 'father', 'evidence' => $evidence];
                $parents[] = ['parent' => $mother, 'child' => $child, 'role' => 'mother', 'evidence' => $evidence];
            }
            $parents[1]['evidence'][0]['quote'] = 'Иван Васильевич (10.10.1970) и Ирина Геннадьевна (1980)';
            static::getContainer()->get(TreeReadTools::class)->person($draft->tree->getId()->toRfc4122(), $ivanId);

            return FixtureTreeMergeAi::reply($draft, 'К добавлению готово 6 персон.', [
                'people' => $people, 'parents' => $parents, 'marriages' => [['first' => 'i', 'second' => 'a',
                    'marriageDate'                                                   => ['state' => 'unknown'], 'dissolutionDate' => null, 'newPeriod' => false, 'evidence' => $evidence]],
                'questions' => [],
            ]);
        };
        $this->jsonRequest('POST', $path . '/process', ['version' => $this->responseData()['version'], 'requestId' => 'family'], $headers);
        self::assertResponseIsSuccessful();
        self::assertSame('ready', $this->responseString('state'));
        self::assertSame('К добавлению готово 6 персон.', $this->responseString('messages', '1', 'content'));
        self::assertSame(['state' => 'known', 'kind' => 'approximate', 'year' => 1992], $this->arrayAt('proposal', 'people', '4', 'fields', 'birthDate'));
        self::assertSame('Иванович', $this->responseString('proposal', 'people', '3', 'fields', 'patronymic', 'value'));
        self::assertSame('Иванов', $this->responseString('proposal', 'people', '3', 'fields', 'birthSurname', 'value'));
        foreach (['5', '6'] as $index) {
            self::assertSame('unknown', $this->responseString('proposal', 'people', $index, 'fields', 'givenName', 'state'));
            self::assertSame('male', $this->responseString('proposal', 'people', $index, 'fields', 'gender'));
        }
        $prepared = $this->responseData();
        $etag = $this->responseString('treeEtag');
        $this->jsonRequest('GET', '/api/v1/trees/' . $treeId . '/snapshot', headers: $headers);
        self::assertCount(1, $this->arrayAt('people'));
        $this->jsonRequest('POST', $path . '/apply', ['version' => $prepared['version'], 'proposalVersion' => $prepared['proposalVersion']],
            $headers + ['If-Match' => $etag]);
        self::assertSame('applied', $this->responseString('state'));
        $this->jsonRequest('GET', '/api/v1/trees/' . $treeId . '/snapshot', headers: $headers);
        self::assertCount(7, $this->arrayAt('people'));
        self::assertCount(8, $this->arrayAt('parentLinks'));
        self::assertCount(1, $this->arrayAt('marriages'));
    }

    /** @return array<array-key, mixed> */
    private function arrayAt(string ...$path): array
    {
        $value = $this->responseData();
        foreach ($path as $key) {
            self::assertIsArray($value);
            $value = $value[$key];
        }
        self::assertIsArray($value);

        return $value;
    }
}
