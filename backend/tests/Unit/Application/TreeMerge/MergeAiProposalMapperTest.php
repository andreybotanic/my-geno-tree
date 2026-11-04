<?php

namespace App\Tests\Unit\Application\TreeMerge;

use App\Api\ApiException;
use App\Application\TreeMerge\Ai\Dto\MergeAiFields;
use App\Application\TreeMerge\Ai\Dto\MergeAiPerson;
use App\Application\TreeMerge\Ai\Dto\MergeAiProposal;
use App\Application\TreeMerge\Ai\MergeAiProposalMapper;
use App\Entity\FamilyTree;
use App\Entity\TreeMergeDraft;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;

final class MergeAiProposalMapperTest extends KernelTestCase
{
    public function testDefaultsNewPeopleToAliveAndPreservesExistingDeathInformation(): void
    {
        self::bootKernel();
        $now = new \DateTimeImmutable();
        $draft = new TreeMergeDraft(new FamilyTree(new User('test', 'test@example.test', 'hash', 'ru', $now), 'tree', $now), null, 'etag', $now);
        $serializer = static::getContainer()->get(SerializerInterface::class);
        $mapper = new MergeAiProposalMapper();
        foreach (['Вера' => 'alive', 'Вера умерла' => 'deceased'] as $quote => $status) {
            $input = $serializer->deserialize(json_encode(['people' => [['id' => 'v', 'resolution' => 'new', 'fields' => ['givenName' => 'Вера'],
                'evidence'                                                    => [['messageId' => 'source', 'quote' => $quote]]]]], JSON_THROW_ON_ERROR), MergeAiProposal::class, 'json');
            $mapped = $mapper->map($draft, $input, []);
            self::assertIsArray($mapped['people']);
            self::assertIsArray($mapped['people'][0]);
            self::assertSame($status, $mapped['people'][0]['lifeStatus']);
            self::assertSame([], $mapped['questions']);
        }
        $draft->propose(['people' => [['existingPersonId' => 'p']]], [], 'etag', 'ready', true, $now);
        $input = $serializer->deserialize('{"people":[{"id":"v","existingPersonId":"p","resolution":"existing","lifeStatus":"alive","fields":{},"evidence":[]}]}', MergeAiProposal::class, 'json');
        $context = ['id' => 'p', 'givenName' => ['state' => 'known', 'value' => 'Вера'], 'birthSurname' => ['state' => 'unknown'],
            'patronymic' => ['state' => 'unknown'], 'gender' => 'female', 'birthDate' => ['state' => 'unknown'], 'deathDate' => ['state' => 'unknown']];
        $mapped = $mapper->map($draft, $input, ['p' => $context]);
        self::assertIsArray($mapped['people']);
        self::assertIsArray($mapped['people'][0]);
        self::assertSame('deceased', $mapped['people'][0]['lifeStatus']);
        self::assertSame(['deathDate' => ['state' => 'unknown']], $mapped['people'][0]['fields']);
    }

    public function testExplicitUnknownNamesRemainUnknownAndCannotTargetOtherFields(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User('test', 'test@example.test', 'hash', 'ru', $now);
        $draft = new TreeMergeDraft(new FamilyTree($user, 'tree', $now), null, 'etag', $now);
        $person = new MergeAiPerson();
        $person->id = 'p';
        $person->fields = new MergeAiFields();
        $person->fields->unknownNames = ['birthSurname'];
        $proposal = new MergeAiProposal();
        $proposal->people = [$person];
        $mapper = new MergeAiProposalMapper();
        $mapped = $mapper->map($draft, $proposal, []);
        self::assertIsArray($mapped['people']);
        self::assertIsArray($mapped['people'][0]);
        self::assertSame(['birthSurname' => ['state' => 'unknown'], 'deathDate' => null], $mapped['people'][0]['fields']);
        $person->fields->unknownNames = ['gender'];
        $this->expectException(ApiException::class);
        $mapper->map($draft, $proposal, []);
    }

    public function testRequiresIdentityChoiceAndCommonParentEvidenceAndPreservesUnknowns(): void
    {
        self::bootKernel();
        $now = new \DateTimeImmutable();
        $user = new User('test', 'test@example.test', 'hash', 'ru', $now);
        $draft = new TreeMergeDraft(new FamilyTree($user, 'tree', $now), null, 'etag', $now);
        $person = ['id'    => 'p', 'givenName' => ['state' => 'known', 'value' => 'Вера'],
            'birthSurname' => ['state' => 'unknown'], 'patronymic' => ['state' => 'unknown'],
            'gender'       => 'female', 'birthDate' => ['state' => 'unknown'], 'deathDate' => null];
        $input = static::getContainer()->get(SerializerInterface::class)->deserialize(json_encode([
            'people' => [['id' => 'v', 'existingPersonId' => 'p', 'resolution' => 'existing', 'lifeStatus' => 'alive',
                'fields'       => ['givenName' => 'Вероника', 'birthDate' => '1938..1939'],
                'evidence'     => [['messageId' => 'source', 'quote' => 'Вера и Саша — брат и сестра.']]]],
            'parents'   => [['parent' => 'v', 'child' => 'a', 'role' => 'mother', 'evidence' => [['messageId' => 'source', 'quote' => 'Брат и сестра']]]],
            'marriages' => [['first' => 'v', 'second' => 'a', 'marriageDate' => 'unknown',
                'dissolutionDate'    => '~1970', 'newPeriod' => true, 'evidence' => []]],
            'questions' => [],
        ], JSON_THROW_ON_ERROR), MergeAiProposal::class, 'json');
        $mapper = new MergeAiProposalMapper();
        $mapped = $mapper->map($draft, $input, ['p' => $person]);
        self::assertIsArray($mapped['people']);
        self::assertIsArray($mapped['people'][0]);
        self::assertSame('undecided', $mapped['people'][0]['resolution']);
        self::assertNull($mapped['people'][0]['existingPersonId']);
        self::assertIsArray($mapped['questions']);
        self::assertCount(3, $mapped['questions']);
        self::assertSame([['first' => 'v', 'second' => 'a', 'marriageDate' => ['state' => 'unknown'],
            'dissolutionDate'      => ['state' => 'known', 'kind' => 'approximate', 'year' => 1970], 'newPeriod' => false, 'evidence' => []]], $mapped['marriages']);
        $draft->propose(['people' => [['existingPersonId' => 'p']]], [], 'etag', 'ready', true, $now);
        $mapped = $mapper->map($draft, $input, ['p' => $person]);
        self::assertIsArray($mapped['people']);
        self::assertIsArray($mapped['people'][0]);
        self::assertSame('existing', $mapped['people'][0]['resolution']);
        $draft->propose(['people' => [], 'marriages' => [['first' => 'v', 'second' => 'a',
            'marriageDate'                                        => ['state' => 'unknown'], 'newPeriod' => true]]], [], 'etag', 'ready', true, $now);
        $reviewed = $mapper->map($draft, $input, ['p' => $person]);
        self::assertIsArray($reviewed['marriages']);
        self::assertIsArray($reviewed['marriages'][0]);
        self::assertTrue($reviewed['marriages'][0]['newPeriod']);
        $this->expectException(ApiException::class);
        $mapper->map($draft, $input, []);
    }
}
