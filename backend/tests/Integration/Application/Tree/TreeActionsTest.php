<?php

namespace App\Tests\Integration\Application\Tree;

use App\Application\Event\EventTimelineService;
use App\Application\Person\PersonLookup;
use App\Application\Tree\TreeActionService;
use App\Application\Tree\TreeContextService;
use App\Tests\Api\ApiWebTestCase;
use App\Tool\TreeWriteTools;

final class TreeActionsTest extends ApiWebTestCase
{
    private string $treeId;
    private string $etag;
    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        $this->headers = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Atomic actions'], $this->headers);
        $this->treeId = $this->responseString('id');
        $this->etag = $this->responseString('etag');
    }

    public function testEachOperationReturnsTheEtagAndRealIdsForItsSuccessor(): void
    {
        $first = $this->action('create_person', ['fields' => ['gender' => 'female'], 'connection' => null]);
        $mother = $first['personId'];
        self::assertIsString($mother);
        $created = $this->action(
            'create_person',
            [
                'fields'     => [],
                'connection' => ['type' => 'child', 'personId' => $mother, 'role' => 'mother'],
            ]
        );
        $child = $created['personId'];
        self::assertIsArray($created['parentLinkIds']);
        self::assertCount(2, $created['parentLinkIds']);
        self::assertIsArray($created['people']);
        $unknownFather = array_find(
            $created[
            'people'
        ],
            static fn ($person) => is_array(
                $person
            ) && 'male' === $person[
            'gender'
        ]
        );
        self::assertIsArray($unknownFather);
        $father = ['personId' => $unknownFather['id'], 'parentLinkIds' => [$created['parentLinkIds'][1]]];
        $marriage = $this->action(
            'create_marriage_period',
            [
                'first'  => $father['personId'],
                'second' => $mother,
                'fields' => ['marriageDate' => ['state' => 'unknown']],
            ]
        );
        self::assertIsArray($marriage['marriageIds']);
        self::assertCount(1, $marriage['marriageIds']);
        $this->action(
            'update_person',
            [
                'personId' => $child,
                'fields'   => ['givenName' => ['state' => 'known', 'value' => 'Éléonore']],
            ]
        );
        $this->action('remove_parent_link', ['parentLinkId' => $father['parentLinkIds'][0]]);
        $link = $this->action(
            'add_parent_link',
            [
                'parentId' => $father['personId'],
                'childId'  => $child,
                'role'     => 'father',
            ]
        );
        self::assertIsArray($link['parentLinkIds']);
        self::assertCount(1, $link['parentLinkIds']);
        $this->action('remove_marriage_period', ['marriageId' => $marriage['marriageIds'][0]]);
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertSame($this->etag, $this->responseString('etag'));
        self::assertCount(3, $this->responseArray('people'));
        self::assertCount(2, $this->responseArray('parentLinks'));
        self::assertSame([], $this->responseArray('marriages'));
    }

    public function testPersonAndMarriagePlacesSynchronizeTheirAutomaticEvents(): void
    {
        $person = $this->action('create_person', ['fields' => ['gender' => 'male'], 'connection' => null]);
        $wife = $this->action(
            'create_person',
            [
                'fields'     => ['gender' => 'female'],
                'connection' => [
                    'type'     => 'spouse',
                    'personId' => $person['personId'],
                    'marriage' => ['marriageDate' => ['state' => 'unknown']],
                ],
            ]
        );
        $place = ['name' => 'A place', 'coordinates' => null];
        $updated = $this->action(
            'update_person',
            [
                'personId' => $person['personId'],
                'fields'   => ['birthPlace' => $place, 'deathDate' => ['state' => 'unknown'], 'deathPlace' => $place],
            ]
        );
        self::assertIsArray($updated['person']);
        self::assertIsArray($updated['person']['birthPlace']);
        self::assertIsArray($updated['person']['deathPlace']);
        self::assertIsArray($wife['marriageIds']);
        self::assertSame('A place', $updated['person']['birthPlace']['name']);
        self::assertSame('A place', $updated['person']['deathPlace']['name']);
        $this->action(
            'update_marriage_period',
            [
                'marriageId' => $wife['marriageIds'][0],
                'fields'     => [
                    'marriagePlace'    => $place,
                    'dissolutionDate'  => ['state' => 'unknown'],
                    'dissolutionPlace' => $place,
                ],
            ]
        );
        $this->action(
            'update_marriage_period',
            [
                'marriageId' => $wife['marriageIds'][0],
                'fields'     => ['marriagePlace' => null, 'dissolutionDate' => null],
            ]
        );
        $alive = $this->action(
            'update_person',
            [
                'personId' => $person['personId'],
                'fields'   => ['birthPlace' => null, 'deathDate' => null],
            ]
        );
        self::assertIsArray($alive['person']);
        self::assertNull($alive['person']['birthPlace']);
        self::assertNull($alive['person']['deathPlace']);
        $this->action('delete_person', ['personId' => $wife['personId']]);
        $this->action('delete_person', ['personId' => $person['personId']]);
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertNull($this->responseArray('tree')['reference']);
        self::assertSame([], $this->responseArray('people'));
    }

    public function testAChildAndItsUnknownParentAreOneAtomicActionWithUsableResultIds(): void
    {
        $mother = $this->action('create_person', ['fields' => ['gender' => 'female'], 'connection' => null]);
        $arguments = [
            'treeId'     => $this->treeId,
            'ifMatch'    => $this->etag,
            'fields'     => ['givenName' => ['state' => 'known', 'value' => 'Darya']],
            'connection' => ['type' => 'child', 'personId' => $mother['personId'], 'role' => 'mother'],
        ];
        $service = static::getContainer()->get(TreeActionService::class);
        $summary = $service->describe('create_person', $arguments);
        self::assertTrue($summary['createsUnknownParent']);
        $child = $this->action('create_person', $arguments);
        self::assertIsArray($child['people']);
        self::assertIsArray($child['parentLinkIds']);
        self::assertCount(2, $child['parentLinkIds']);
        self::assertCount(2, $child['people']);
        $father = array_find(
            $child[
            'people'
        ],
            static fn ($person) => is_array(
                $person
            ) && 'male' === $person[
            'gender'
        ]
        );
        self::assertIsArray($father);
        self::assertNotSame($child['personId'], $father['id']);
        self::assertSame(['state' => 'unknown'], $father['givenName']);
        $this->action(
            'update_person',
            [
                'personId' => $father['id'],
                'fields'   => ['givenName' => ['state' => 'known', 'value' => 'Ivan']],
            ]
        );
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertCount(3, $this->responseArray('people'));
        self::assertCount(2, $this->responseArray('parentLinks'));
        self::assertSame([], $this->responseArray('marriages'));
        self::assertSame($this->etag, $this->responseString('etag'));
    }

    public function testTwoExistingParentsDoNotCreateAnUnnecessaryUnknownPerson(): void
    {
        $mother = $this->action('create_person', ['fields' => ['gender' => 'female'], 'connection' => null]);
        $father = $this->action(
            'create_person',
            [
                'fields'     => ['gender' => 'male'],
                'connection' => [
                    'type'     => 'spouse',
                    'personId' => $mother['personId'],
                    'marriage' => ['marriageDate' => ['state' => 'unknown']],
                ],
            ]
        );
        $arguments = [
            'treeId'     => $this->treeId,
            'ifMatch'    => $this->etag,
            'fields'     => [],
            'connection' => [
                'type'          => 'child',
                'personId'      => $mother['personId'],
                'role'          => 'mother',
                'otherParentId' => $father['personId'],
            ],
        ];
        $service = static::getContainer()->get(TreeActionService::class);
        $summary = $service->describe('create_person', $arguments);
        self::assertFalse($summary['createsUnknownParent']);
        self::assertIsArray($summary['otherRelative']);
        self::assertSame($father['personId'], $summary['otherRelative']['id']);
        $child = $this->action('create_person', $arguments);
        self::assertIsArray($child['people']);
        self::assertCount(1, $child['people']);
        self::assertIsArray($child['parentLinkIds']);
        self::assertCount(2, $child['parentLinkIds']);
    }

    public function testAnInvalidChildRollsBackTheUnknownParentAndBothLinks(): void
    {
        $mother = $this->action(
            'create_person',
            [
                'fields' => [
                    'gender'    => 'female',
                    'birthDate' => [
                        'state' => 'known',
                        'kind'  => 'exact',
                        'year'  => 2000,
                    ],
                ],
                'connection' => null,
            ]
        );
        $service = static::getContainer()->get(TreeActionService::class);
        $result = $service->execute(
            'create_person',
            [
                'treeId'     => $this->treeId,
                'ifMatch'    => $this->etag,
                'fields'     => ['birthDate' => ['state' => 'known', 'kind' => 'exact', 'year' => 1990]],
                'connection' => ['type' => 'child', 'personId' => $mother['personId'], 'role' => 'mother'],
            ]
        );
        self::assertArrayHasKey('error', $result);
        self::assertArrayNotHasKey('etag', $result);
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertCount(1, $this->responseArray('people'));
        self::assertSame([], $this->responseArray('parentLinks'));
        self::assertSame($this->etag, $this->responseString('etag'));
    }

    public function testManualEventsAndCanonicalPlacesHaveIndependentAtomicOperations(): void
    {
        $created = $this->action('create_person', ['fields' => [], 'connection' => null]);
        $id = $created['personId'];
        self::assertIsString($id);
        $first = $this->action(
            'create_event',
            [
                'personId' => $id,
                'event'    => [
                    'type'        => 'custom',
                    'date'        => ['state' => 'unknown'],
                    'title'       => 'First',
                    'description' => null,
                ],
            ]
        );
        $second = $this->action(
            'create_event',
            [
                'personId' => $id,
                'event'    => [
                    'type'        => 'custom',
                    'date'        => ['state' => 'unknown'],
                    'title'       => 'Second',
                    'description' => null,
                ],
            ]
        );
        $this->action(
            'update_event',
            [
                'personId' => $id,
                'eventId'  => $first['eventId'],
                'fields'   => ['title' => 'Edited'],
            ]
        );
        $this->action(
            'set_event_place',
            [
                'eventId' => $first['eventId'],
                'place'   => [
                    'name'        => 'Home',
                    'coordinates' => [
                        'latitude'  => 50,
                        'longitude' => 60,
                    ],
                ],
            ]
        );
        $this->action('set_event_place', ['eventId' => $first['eventId'], 'place' => null]);
        $container = static::getContainer();
        $person = $container
            ->get(PersonLookup::class)
            ->require(
                $container->get(TreeContextService::class)->tree($this->treeId),
                $id,
            );
        $timeline = $container->get(EventTimelineService::class)->forPerson($person);
        $order = array_map(static fn ($event) => $event->getId()->toRfc4122(), $timeline);
        [$order[0], $order[1]] = [$order[1], $order[0]];
        $this->action('reorder_events', ['personId' => $id, 'eventIds' => $order]);
        $this->action('delete_event', ['personId' => $id, 'eventId' => $first['eventId']]);
        $this->action('delete_event', ['personId' => $id, 'eventId' => $second['eventId']]);
    }

    public function testAnExternalWriteBreaksTheEtagChainWithoutUndoingCommittedActions(): void
    {
        $created = $this->action('create_person', ['fields' => [], 'connection' => null]);
        $this->action('update_person', ['personId' => $created['personId'], 'fields' => ['gender' => 'male']]);
        $this->jsonRequest(
            'PATCH',
            '/api/v1/trees/' . $this->treeId,
            ['name' => 'Concurrent change'],
            [
                ...$this->headers,
                'If-Match' => $this->etag,
            ]
        );
        self::assertResponseIsSuccessful();
        $result = static::getContainer()
            ->get(TreeActionService::class)
            ->execute(
                'update_person',
                [
                    'treeId'   => $this->treeId,
                    'ifMatch'  => $this->etag,
                    'personId' => $created['personId'],
                    'fields'   => ['gender' => 'female'],
                ],
            );
        self::assertIsArray($result['error']);
        self::assertSame('TREE_VERSION_CONFLICT', $result['error']['code']);
        self::assertArrayNotHasKey('etag', $result);
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertCount(1, $this->responseArray('people'));
        self::assertSame('male', $this->responseString('people', '0', 'gender'));
        self::assertSame('Concurrent change', $this->responseString('tree', 'name'));
    }

    public function testARejectedStepDoesNotRollbackEarlierStepsOrPoisonTheNextTransaction(): void
    {
        $initialEtag = $this->etag;
        $created = $this->action('create_person', ['fields' => [], 'connection' => null]);
        $service = static::getContainer()->get(TreeActionService::class);
        $conflict = $service->execute(
            'update_person',
            [
                'treeId'   => $this->treeId,
                'ifMatch'  => $initialEtag,
                'personId' => $created['personId'],
                'fields'   => ['gender' => 'male'],
            ]
        );
        self::assertIsArray($conflict['error']);
        self::assertSame('TREE_VERSION_CONFLICT', $conflict['error']['code']);
        $this->action('update_person', ['personId' => $created['personId'], 'fields' => ['gender' => 'male']]);
        $failed = $service->execute(
            'create_person',
            [
                'treeId'     => $this->treeId,
                'ifMatch'    => $this->etag,
                'fields'     => [],
                'connection' => null,
            ]
        );
        self::assertIsArray($failed['error']);
        self::assertSame('FIRST_PERSON_ALREADY_EXISTS', $failed['error']['code']);
        $child = $this->action(
            'create_person',
            [
                'fields'     => [],
                'connection' => ['type' => 'child', 'personId' => $created['personId'], 'role' => 'father'],
            ]
        );
        self::assertIsArray($child['parentLinkIds']);
        $failed = $service->execute(
            'remove_parent_link',
            [
                'treeId'       => $this->treeId,
                'ifMatch'      => $this->etag,
                'parentLinkId' => $child['parentLinkIds'][0],
            ]
        );
        self::assertArrayHasKey('error', $failed);
        self::assertIsArray($child['people']);
        $unknownParent = array_find(
            $child['people'],
            static fn ($person) => is_array($person) && $person['id'] !== $child['personId'],
        );
        self::assertIsArray($unknownParent);
        $this->action('delete_person', ['personId' => $unknownParent['id']]);
        $this->action('delete_person', ['personId' => $child['personId']]);
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertCount(1, $this->responseArray('people'));
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function action(string $name, array $arguments): array
    {
        $previous = $this->etag;
        $method = array_search($name, TreeWriteTools::OPERATIONS, true);
        self::assertIsString($method);
        $tools = static::getContainer()->get(TreeWriteTools::class);
        $result = new \ReflectionMethod($tools, $method)->invokeArgs(
            $tools,
            ['treeId' => $this->treeId, 'ifMatch' => $previous, ...$arguments],
        );
        self::assertIsArray($result);
        self::assertArrayNotHasKey('error', $result, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertIsString($result['etag']);
        self::assertNotSame($previous, $result['etag']);
        $this->etag = $result['etag'];

        return $result;
    }
}
