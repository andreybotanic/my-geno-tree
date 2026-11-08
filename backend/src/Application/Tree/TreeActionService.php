<?php

namespace App\Application\Tree;

use App\Api\ApiException;
use App\Api\PersonViewFactory;
use App\Application\Event\EventPlaceService;
use App\Application\Event\ManualEventService;
use App\Application\Person\CreateFirstPersonService;
use App\Application\Person\PersonDataPolicy;
use App\Application\Person\PersonLookup;
use App\Application\Person\UpdatePersonService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/** One independently committed domain operation, shared by every tool transport. */
final readonly class TreeActionService
{
    private const array PERSON_DEFAULTS = [
        'birthSurname' => ['state' => 'unknown'],
        'givenName'    => ['state' => 'unknown'],
        'patronymic'   => ['state' => 'unknown'],
        'gender'       => 'unknown',
        'birthDate'    => ['state' => 'unknown'],
        'deathDate'    => null,
    ];
    public const array NAMES = [
        'create_person',
        'update_person',
        'delete_person',
        'add_parent_link',
        'remove_parent_link',
        'create_marriage_period',
        'update_marriage_period',
        'remove_marriage_period',
        'create_event',
        'update_event',
        'delete_event',
        'set_event_place',
        'reorder_events',
    ];

    public function __construct(
        private TreeContextService $trees,
        private TreeVersion $versions,
        private CreateFirstPersonService $firstPerson,
        private StructuralGraphMutationService $graph,
        private UpdatePersonService $people,
        private PersonLookup $lookup,
        private PersonViewFactory $personViews,
        private UpdateMarriagePeriodService $marriages,
        private ManualEventService $events,
        private EventPlaceService $places,
        private ManagerRegistry $doctrine,
        private PersonDataPolicy $personData,
    ) {
    }

    public static function needsConfirmation(string $name): bool
    {
        return in_array($name, ['create_person', 'delete_person'], true);
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function describe(string $name, array $arguments): array
    {
        $tree = $this->trees->tree($this->string($arguments, 'treeId'), true);
        $this->versions->assertMatches($tree, $this->string($arguments, 'ifMatch'));
        if ('delete_person' === $name) {
            return ['person' => $this->personViews->create(
                $this->lookup->require($tree, $this->string($arguments, 'personId')),
            )];
        }

        $fields = $this->object($arguments, 'fields');
        $this->personData->create($fields + self::PERSON_DEFAULTS);
        if (null === ($arguments['connection'] ?? null) && null !== $tree->getReferencePrimaryPerson()) {
            throw new ApiException(422, 'TREE_ACTION_CONNECTION_REQUIRED', fields: ['connection' => ['initial_connection_required']]);
        }
        $summary = ['fields' => (object)$fields, 'connection' => $arguments['connection'] ?? null];
        if (is_array($summary['connection'])) {
            $summary['relative'] = $this->personViews->create(
                $this->lookup->require($tree, $this->string($this->object($arguments, 'connection'), 'personId')),
            );
        }

        return $summary;
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function execute(string $name, array $arguments): array
    {
        try {
            return $this->perform($name, $arguments);
        } catch (ApiException $error) {
            // A rejected ORM transaction closes its manager. Later tools must be able to read and replan.
            $manager = $this->doctrine->getManager();
            if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
                $this->doctrine->resetManager();
            }

            return ['error' => [
                'code'    => $error->getErrorCode(),
                'status'  => $error->getStatus(),
                'fields'  => $error->getFields(),
                'details' => $error->getDetails(),
            ]];
        }
    }

    /** @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function perform(string $name, array $a): array
    {
        $tree = $this->trees->tree($this->string($a, 'treeId'), true);
        $etag = $this->string($a, 'ifMatch');
        $result = ['newPersonIds' => []];
        switch ($name) {
            case 'create_person':
                $fields = $this->object($a, 'fields') + self::PERSON_DEFAULTS;
                if (null === ($a['connection'] ?? null)) {
                    $person = $this->firstPerson->create(
                        $tree,
                        ['type' => 'create_first_person', 'person' => $fields],
                        $etag,
                    );
                    $result['newPersonIds'] = [$person->getId()->toRfc4122()];
                } else {
                    $connection = $this->object($a, 'connection');
                    $other = $this->string($connection, 'personId');
                    $relative = ['person' => $fields];
                    $payload = match ($connection['type'] ?? null) {
                        'parent' => [
                            'type'     => 'add_parent',
                            'childId'  => $other,
                            'role'     => $this->string($connection, 'role'),
                            'relative' => $relative,
                        ],
                        'child' => [
                            'type'    => 'add_child',
                            'parents' => [
                                [
                                    'personId' => $other,
                                    'role'     => $this->string($connection, 'role'),
                                ],
                            ],
                            'relative' => $relative,
                        ],
                        'spouse' => $this->marriagePayload($other, $relative, $this->object($connection, 'marriage')),
                        default  => throw new ApiException(422, 'TREE_ACTION_INVALID'),
                    };
                    $result = $this->graph->mutate($tree, $payload, $etag);
                }
                $result['personId'] = $result['newPersonIds'][0];
                break;
            case 'update_person':
                $person = $this->lookup->require($tree, $this->string($a, 'personId'));
                $this->people->update($tree, $person, $this->object($a, 'fields'), $etag);
                $result['person'] = $this->personViews->create($person);
                break;
            case 'delete_person':
            case 'remove_parent_link':
            case 'remove_marriage_period':
                $key = match ($name) {
                    'delete_person'      => 'personId',
                    'remove_parent_link' => 'parentLinkId',
                    default              => 'marriageId',
                };
                $id = $this->string($a, $key);
                $result = $this->graph->mutate($tree, ['type' => $name, $key => $id], $etag);
                $result['deletedId'] = $id;
                break;
            case 'add_parent_link':
                $result = $this->graph->mutate(
                    $tree,
                    [
                        'type'     => 'link_parent_child',
                        'parentId' => $this->string($a, 'parentId'),
                        'childId'  => $this->string($a, 'childId'),
                        'role'     => $this->string($a, 'role'),
                    ],
                    $etag,
                );
                break;
            case 'create_marriage_period':
                $result = $this->graph->mutate(
                    $tree,
                    $this->marriagePayload(
                        $this->string($a, 'first'),
                        ['personId' => $this->string($a, 'second')],
                        $this->object($a, 'fields'),
                    ),
                    $etag,
                );
                break;
            case 'update_marriage_period':
                $marriage = $this->marriages->update(
                    $tree,
                    $this->string($a, 'marriageId'),
                    $this->object($a, 'fields'),
                    $etag,
                );
                $result['marriageId'] = $marriage->getId()->toRfc4122();
                break;
            case 'create_event':
            case 'update_event':
            case 'delete_event':
            case 'reorder_events':
                $person = $this->lookup->require($tree, $this->string($a, 'personId'));
                if ('create_event' === $name) {
                    $event = $this->events->create($tree, $person, $this->object($a, 'event'), $etag);
                    $result['eventId'] = $event->getId()->toRfc4122();
                } elseif ('update_event' === $name) {
                    $event = $this->events->update(
                        $tree,
                        $person,
                        $this->string($a, 'eventId'),
                        $this->object($a, 'fields'),
                        $etag,
                    );
                    $result['eventId'] = $event->getId()->toRfc4122();
                } elseif ('delete_event' === $name) {
                    $this->events->delete($tree, $person, $this->string($a, 'eventId'), $etag);
                    $result['deletedId'] = $a['eventId'];
                } else {
                    $this->events->reorder($tree, $person, ['eventIds' => $a['eventIds'] ?? []], $etag);
                }
                break;
            case 'set_event_place':
                $id = $this->string($a, 'eventId');
                if (null === ($a['place'] ?? null)) {
                    $this->places->delete($tree, $id, $etag);
                } else {
                    $this->places->put($tree, $id, $this->object($a, 'place'), $etag);
                }
                $result['eventId'] = $id;
                break;
            default:
                throw new ApiException(422, 'TREE_ACTION_INVALID');
        }

        return [
            ...$result,
            'treeId' => $tree->getId()->toRfc4122(),
            'etag'   => $result['etag'] ?? $this->versions->etag($tree),
        ];
    }

    /** @param array<string, mixed> $relative
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function marriagePayload(string $id, array $relative, array $fields): array
    {
        return [
            'type'     => 'add_marriage_period',
            'personId' => $id,
            'relative' => $relative,
            ...$fields,
        ] + [
            'marriageDate'     => ['state' => 'unknown'],
            'dissolutionDate'  => null,
            'maleNewSurname'   => null,
            'femaleNewSurname' => null,
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function string(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;
        if (!is_string($value) || '' === $value) {
            throw new ApiException(422, 'TREE_ACTION_INVALID');
        }

        return $value;
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function object(array $arguments, string $key): array
    {
        $value = $arguments[$key] ?? null;
        if (!is_array($value) || ([] !== $value && array_is_list($value))) {
            throw new ApiException(422, 'TREE_ACTION_INVALID');
        }

        return $value;
    }
}
