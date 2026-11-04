<?php

namespace App\Tests\Unit\Application\TreeMerge;

use App\Api\ApiException;
use App\Application\TreeMerge\Ai\Dto\MergeAiEvidence;
use App\Application\TreeMerge\Ai\Dto\MergeAiFields;
use App\Application\TreeMerge\Ai\Dto\MergeAiInference;
use App\Application\TreeMerge\Ai\Dto\MergeAiParent;
use App\Application\TreeMerge\Ai\Dto\MergeAiPerson;
use App\Application\TreeMerge\Ai\Dto\MergeAiProposal;
use App\Application\TreeMerge\Ai\MergeInferenceResolver;
use App\Entity\Value\ParentRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MergeInferenceResolverTest extends TestCase
{
    public function testRelativeAgeUsesOnlyApproximateYearAndHandlesOlderPeople(): void
    {
        [$person, $source, $proposal] = $this->family('birthDate');
        $source->fields->birthDate = '1990-04-01';
        $person->inferences[0]->yearOffset = 2;
        self::assertSame(['birthDate'], (new MergeInferenceResolver())->resolve($person, $proposal, []));
        self::assertSame('~1992', $person->fields->birthDate);
        $person->evidence[0]->quote = 'Anna is three years older.';
        $person->inferences[0]->yearOffset = -3;
        (new MergeInferenceResolver())->resolve($person, $proposal, []);
        self::assertSame('~1987', $person->fields->birthDate);
    }

    public function testCopiesKnownSiblingNamesAndDerivesRegularPaternalName(): void
    {
        foreach (['birthSurname' => 'Иванов', 'patronymic' => 'Иванович'] as $field => $name) {
            [$person, $source, $proposal] = $this->family($field);
            $person->fields->{$field} = $source->fields->{$field} = $name;
            foreach ([$person, $source] as $child) {
                $link = new MergeAiParent();
                $link->parent = 'father';
                $link->child = $child->id;
                $link->role = ParentRole::FATHER;
                $proposal->parents[] = $link;
            }
            self::assertSame([$field], (new MergeInferenceResolver())->resolve($person, $proposal, []));
            $proposal->parents = [$proposal->parents[0]];
            $proposal->parents[0]->parent = $source->id;
            $source->fields->givenName = 'Иван';
            self::assertSame([$field], (new MergeInferenceResolver())->resolve($person, $proposal, []));
            if ('patronymic' === $field) {
                $person->fields->gender = 'female';
                $person->fields->patronymic = 'Ивановна';
                self::assertSame([$field], (new MergeInferenceResolver())->resolve($person, $proposal, []));
            }
        }
    }

    #[DataProvider('invalidCases')]
    public function testRejectsUnsupportedDeductions(string $case): void
    {
        [$person, $source, $proposal] = $this->family('birthDate');
        $source->fields->birthDate = '1990';
        $person->inferences[0]->yearOffset = 2;
        match ($case) {
            'existing'         => $person->resolution = 'existing',
            'source'           => $person->inferences[0]->sourcePersonId = 'missing',
            'self'             => $person->inferences[0]->sourcePersonId = $person->id,
            'field'            => $person->inferences[0]->field = 'givenName',
            'explanation'      => $person->inferences[0]->explanation = '',
            'unknown'          => $source->fields->birthDate = 'unknown',
            'bounded'          => $source->fields->birthDate = '<1990',
            'explicit_unknown' => $person->fields->birthDate = 'unknown',
            'offset'           => $person->inferences[0]->yearOffset = null,
            'range'            => $person->inferences[0]->yearOffset = 151,
            'direction'        => $person->inferences[0]->yearOffset = -2,
            'evidence'         => $person->evidence = [],
            'unobserved'       => $source->existingPersonId = 'unobserved',
            'chain'            => $source->inferences = $person->inferences,
            'duplicate'        => $person->inferences[] = $person->inferences[0],
            default            => self::fail('Unknown test case'),
        };
        $this->expectException(ApiException::class);
        (new MergeInferenceResolver())->resolve($person, $proposal, []);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCases(): iterable
    {
        foreach (['existing', 'source', 'self', 'field', 'explanation', 'unknown', 'bounded', 'explicit_unknown', 'offset', 'range', 'direction', 'evidence', 'unobserved', 'chain', 'duplicate'] as $case) {
            yield $case => [$case];
        }
    }

    public function testMarriageAloneDoesNotEstablishBirthSurnameOrPatronymic(): void
    {
        [$person, $source, $proposal] = $this->family('birthSurname');
        $person->fields->birthSurname = $source->fields->birthSurname = 'Иванов';
        $this->expectException(ApiException::class);
        (new MergeInferenceResolver())->resolve($person, $proposal, []);
    }

    /** @return array{MergeAiPerson, MergeAiPerson, MergeAiProposal} */
    private function family(string $field): array
    {
        $person = new MergeAiPerson();
        $person->id = 'p';
        $person->resolution = 'new';
        $person->fields = new MergeAiFields();
        $person->fields->gender = 'male';
        $source = clone $person;
        $source->id = 's';
        $source->fields = clone $person->fields;
        $inference = new MergeAiInference();
        $inference->field = $field;
        $inference->sourcePersonId = 's';
        $inference->explanation = 'Inferred from the stated family relationship.';
        $person->inferences = [$inference];
        $evidence = new MergeAiEvidence();
        $evidence->quote = 'Она на пару лет младше.';
        $person->evidence = [$evidence];
        $proposal = new MergeAiProposal();
        $proposal->people = [$person, $source];

        return [$person, $source, $proposal];
    }
}
