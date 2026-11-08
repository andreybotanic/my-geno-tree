<?php

namespace App\Application\Tree\ChangeSet;

use App\Api\ApiException;
use App\Application\Event\EventPlaceDataPolicy;
use App\Application\Event\ManualEventDataPolicy;
use App\Application\Person\PersonData;
use App\Application\Person\PersonDataPolicy;
use App\Entity\FamilyTree;
use App\Entity\Person;
use App\Entity\Value\Gender;
use App\Entity\Value\ParentRole;
use App\Repository\PersonRepository;
use Symfony\Component\Uid\Uuid;

/** Compiles operations for any application adapter, without chat state or approval policy. */
final readonly class TreeChangeSetFactory
{
    public function __construct(
        private PersonRepository $people,
        private PersonDataPolicy $personData,
        private EventPlaceDataPolicy $places,
        private ManualEventDataPolicy $events,
    ) {
    }

    /** @param list<array<string, mixed>> $rows */
    public function create(FamilyTree $tree, array $rows): TreeChangeSet
    {
        if ([] === $rows || !array_is_list($rows)) {
            throw new ApiException(422, 'TREE_CHANGE_INVALID');
        }
        $operations = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new ApiException(422, 'TREE_CHANGE_INVALID');
            }
            $operations[] = new TreeOperation($row);
        }
        $existing = [];
        foreach ($this->people->findAllForChangeSet($tree) as $person) {
            $existing[$person->getId()->toRfc4122()] = $person;
        }
        $changes = $new = [];
        foreach ($operations as $operation) {
            if (!in_array($operation->type, ['create_person', 'update_person'], true)) {
                continue;
            }
            $creating = 'create_person' === $operation->type;
            $reference = $operation->string($creating ? 'id' : 'personId');
            if (isset($changes[$reference]) || ($creating && isset($existing[$reference]))) {
                throw new ApiException(422, 'CHANGE_REFERENCE_DUPLICATE');
            }
            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $reference)) {
                throw new ApiException(422, 'TREE_CHANGE_INVALID');
            }
            $person = $creating ? null : $this->existing($existing, $reference);
            $fields = $operation->object('fields');
            $personal = array_diff_key($fields, ['birthPlace' => true, 'deathPlace' => true]);
            if (null === $person) {
                $new[$reference] = true;
                $data = $this->personData->create(
                    $fields + [
                        'birthSurname' => ['state' => 'unknown'],
                        'givenName'    => ['state' => 'unknown'],
                        'patronymic'   => ['state' => 'unknown'],
                        'gender'       => 'unknown',
                        'birthDate'    => ['state' => 'unknown'],
                        'deathDate'    => null,
                    ],
                );
            } else {
                if ([] === $fields) {
                    throw new ApiException(422, 'TREE_CHANGE_INVALID');
                }
                $data = $this->personData->patch($person, $personal + ['gender' => $person->getGender()->value]);
            }
            $places = [];
            foreach (['birthPlace', 'deathPlace'] as $field) {
                if (array_key_exists($field, $fields)) {
                    $places[$field] = null === $fields[$field]
                        ? null : $this->places->resolved($fields[$field], $field);
                }
            }
            if (null === $data->deathDate && null !== ($places['deathPlace'] ?? null)) {
                $errors = ['deathPlace' => ['death_place_requires_death']];
                throw new ApiException(422, 'TREE_CHANGE_INVALID', fields: $errors);
            }
            $changes[$reference] = new PersonChange($reference, $person?->getId()->toRfc4122(), $data, $places);
        }
        if (count($new) > 10) {
            throw new ApiException(422, 'TREE_CHANGE_INVALID', fields: ['operations' => ['new_people_limit']]);
        }
        $parents = $marriages = [];
        $targets = [];
        foreach ($operations as $operation) {
            $type = $operation->type;
            if ('add_parent_link' === $type) {
                $parent = $this->reference($existing, $new, $operation->string('parent'));
                $child = $this->reference($existing, $new, $operation->string('child'));
                $role = ParentRole::tryFrom($operation->string('role'));
                if (null === $role) {
                    throw new ApiException(422, 'TREE_CHANGE_INVALID', fields: ['role' => ['parent_role_invalid']]);
                }
                $change = $changes[$parent] ?? null;
                $gender = null !== $change ? $change->data->gender : $existing[$parent]->getGender();
                if (Gender::UNKNOWN === $gender) {
                    $data = null !== $change ? $change->data : $this->personData->patch(
                        $existing[$parent],
                        ['gender' => $role->requiredGender()->value],
                    );
                    $changes[$parent] = new PersonChange(
                        $parent,
                        null !== $change ? $change->existingPersonId : $parent,
                        new PersonData(
                            $data->birthSurname,
                            $data->givenName,
                            $data->patronymic,
                            $role->requiredGender(),
                            $data->birthDate,
                            $data->deathDate,
                            $data->birthPlace,
                            $data->deathPlace,
                        ),
                        null !== $change ? $change->places : [],
                    );
                }
                $parents[] = new ParentChange($parent, $child, $role);
            } elseif ('create_marriage_period' === $type) {
                $fields = $operation->object('fields');
                $this->allowedFields(
                    $fields,
                    [
                        'marriageDate',
                        'dissolutionDate',
                        'marriagePlace',
                        'dissolutionPlace',
                        'maleNewSurname',
                        'femaleNewSurname',
                    ],
                );
                $places = [];
                foreach (['marriagePlace', 'dissolutionPlace'] as $field) {
                    if (array_key_exists($field, $fields)) {
                        $places[$field] = null === $fields[$field]
                            ? null : $this->places->resolved($fields[$field], $field);
                    }
                }
                $dissolution = $this->personData->optionalDateValue(
                    $fields['dissolutionDate'] ?? null,
                    'dissolutionDate',
                );
                if (null === $dissolution && null !== ($places['dissolutionPlace'] ?? null)) {
                    throw new ApiException(422, 'TREE_CHANGE_INVALID');
                }
                $marriages[] = new MarriageChange(
                    $this->reference($existing, $new, $operation->string('first')),
                    $this->reference($existing, $new, $operation->string('second')),
                    $this->personData->dateValue($fields['marriageDate'] ?? null, 'marriageDate'),
                    $dissolution,
                    $operation->boolean('newPeriod'),
                    $places,
                    $this->surname($fields['maleNewSurname'] ?? null),
                    $this->surname($fields['femaleNewSurname'] ?? null),
                );
            } elseif ('create_events' === $type) {
                $participants = $operation->strings('personIds');
                if ([] === $participants || count(array_unique($participants)) !== count($participants)) {
                    throw new ApiException(422, 'EVENT_PARTICIPANTS_INVALID');
                }
                foreach ($participants as $reference) {
                    $this->reference($existing, $new, $reference);
                }
                $operation->object('event');
            } elseif ('reorder_events' === $type) {
                $this->reference($existing, $new, $operation->string('personId'));
                $this->events->eventOrder(['eventIds' => $operation->strings('eventIds')]);
            } elseif ('set_event_place' === $type && null !== $operation->arguments['place']) {
                $this->places->resolved($operation->object('place'));
            } elseif ('update_marriage_period' === $type) {
                $this->allowedFields(
                    $operation->object('fields'),
                    ['marriageDate', 'dissolutionDate', 'marriagePlace', 'dissolutionPlace'],
                );
            } elseif ('update_event' === $type) {
                $operation->object('fields');
            }
            foreach (['personId', 'parentLinkId', 'marriageId', 'eventId'] as $key) {
                if (!array_key_exists($key, $operation->arguments)) {
                    continue;
                }
                $id = $operation->string($key);
                if (!Uuid::isValid($id) && !('reorder_events' === $type && isset($new[$id]))) {
                    throw new ApiException(404, 'TREE_RESOURCE_NOT_FOUND');
                }
                if ('personId' === $key && !isset($new[$id])) {
                    $this->existing($existing, $id);
                }
                $target = $type . ':' . $id;
                if (isset($targets[$target])) {
                    throw new ApiException(422, 'TREE_CHANGE_INVALID', fields: ['operations' => ['duplicate_change']]);
                }
                $targets[$target] = true;
            }
        }

        return new TreeChangeSet(array_values($changes), $parents, $marriages, $operations);
    }

    /** @param array<string, Person> $people */
    private function existing(array $people, string $id): Person
    {
        if (!Uuid::isValid($id) || !isset($people[$id])) {
            throw new ApiException(404, 'PERSON_NOT_FOUND');
        }

        return $people[$id];
    }

    /** @param array<string, Person> $people
     * @param array<string, true> $new
     */
    private function reference(array $people, array $new, string $reference): string
    {
        if (!isset($new[$reference])) {
            $this->existing($people, $reference);
        }

        return $reference;
    }

    /** @param array<string, mixed> $fields
     * @param list<string> $allowed
     */
    private function allowedFields(array $fields, array $allowed): void
    {
        if ([] === $fields || [] !== array_diff(array_keys($fields), $allowed)) {
            throw new ApiException(422, 'TREE_CHANGE_INVALID', fields: ['fields' => ['fields_invalid']]);
        }
    }

    private function surname(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || '' === trim($value) || mb_strlen(trim($value)) > 100) {
            throw new ApiException(422, 'TREE_CHANGE_INVALID', fields: ['newSurname' => ['text_length_invalid']]);
        }

        return trim($value);
    }
}
