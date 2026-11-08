<?php

namespace App\Tool;

use Symfony\AI\Platform\Contract\JsonSchema\Provider\SchemaProviderInterface;

/** Shared operation contracts for Symfony AI and MCP. */
final class TreeChangeToolSchema implements SchemaProviderInterface
{
    private const array ID = ['type' => 'string', 'minLength' => 1, 'maxLength' => 64];
    public const array PLACE = [
        'oneOf' => [
            ['type' => 'null'],
            [
                'type'       => 'object',
                'properties' => [
                    'name'        => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                    'coordinates' => [
                        'oneOf' => [
                            ['type' => 'null'],
                            [
                                'type'       => 'object',
                                'properties' => [
                                    'latitude'  => ['type' => 'number', 'minimum' => -90, 'maximum' => 90],
                                    'longitude' => ['type' => 'number', 'minimum' => -180, 'maximum' => 180],
                                ],
                                'required'             => ['latitude', 'longitude'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                ],
                'required'             => ['name', 'coordinates'],
                'additionalProperties' => false,
            ],
        ],
    ];
    private const array OBJECT = ['type' => ['object', 'array'], 'maxItems' => 0, 'additionalProperties' => false];
    public const array PERSON = [
        ...self::OBJECT,
        'description' => 'Create: omitted names/dates are unknown; omitted deathDate means alive. '
            . 'Patch: provide only requested changes and preserve existing death information.',
        'properties' => [
            'birthSurname' => TreeMergeToolSchema::TEXT,
            'givenName'    => TreeMergeToolSchema::TEXT,
            'patronymic'   => TreeMergeToolSchema::TEXT,
            'gender'       => ['enum' => ['male', 'female', 'unknown']],
            'birthDate'    => TreeMergeToolSchema::DATE,
            'deathDate'    => TreeMergeToolSchema::OPTIONAL_DATE,
            'birthPlace'   => self::PLACE,
            'deathPlace'   => self::PLACE,
        ],
    ];
    public const array MARRIAGE = [
        ...self::OBJECT,
        'properties' => [
            'marriageDate'     => TreeMergeToolSchema::DATE,
            'dissolutionDate'  => TreeMergeToolSchema::OPTIONAL_DATE,
            'marriagePlace'    => self::PLACE,
            'dissolutionPlace' => self::PLACE,
        ],
    ];
    public const array EVENT = [
        ...self::OBJECT,
        'properties' => [
            'type'        => ['enum' => ['custom', 'residence', 'surname_change']],
            'date'        => TreeMergeToolSchema::DATE,
            'title'       => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
            'description' => ['type' => ['string', 'null'], 'maxLength' => 5000],
            'newSurname'  => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
            'place'       => self::PLACE,
        ],
        'description' => 'Create: type and date required; custom also title and description, '
            . 'residence description, surname_change newSurname. Patch: send only changed fields.',
    ];
    private const array COMMON = [
        'type'                 => 'object',
        'additionalProperties' => false,
    ];
    private const array EVIDENCE = ['evidence' => TreeMergeToolSchema::EVIDENCE];
    public const array OPERATIONS = [
        'type'     => 'array',
        'minItems' => 1,
        'items'    => [
            'oneOf' => [
                [
                    ...self::COMMON,
                    'properties' => [
                        'type'   => ['const' => 'create_person'],
                        'id'     => self::ID,
                        'fields' => self::PERSON,
                        ...self::EVIDENCE,
                    ],
                    'required' => ['type', 'id', 'fields', 'evidence'],
                ],
                [
                    ...self::COMMON,
                    'properties' => [
                        'type'     => ['const' => 'update_person'],
                        'personId' => self::ID,
                        'fields'   => self::PERSON,
                        ...self::EVIDENCE,
                    ],
                    'required' => ['type', 'personId', 'fields', 'evidence'],
                ],
                [
                    ...self::COMMON,
                    'properties' => ['type' => ['const' => 'delete_person'], 'personId' => self::ID, ...self::EVIDENCE],
                    'required'   => ['type', 'personId', 'evidence'],
                ],
                [
                    ...self::COMMON,
                    'properties' => [
                        'type'   => ['const' => 'add_parent_link'],
                        'parent' => self::ID,
                        'child'  => self::ID,
                        'role'   => ['enum' => ['mother', 'father']],
                        ...self::EVIDENCE,
                    ],
                    'required' => ['type', 'parent', 'child', 'role', 'evidence'],
                ],
                [
                    ...self::COMMON,
                    'properties' => [
                        'type'         => ['const' => 'remove_parent_link'],
                        'parentLinkId' => self::ID,
                        ...self::EVIDENCE,
                    ],
                    'required' => ['type', 'parentLinkId', 'evidence'],
                ],
                [
                    ...self::COMMON,
                    'properties' => [
                        'type'      => ['const' => 'create_marriage_period'],
                        'first'     => self::ID,
                        'second'    => self::ID,
                        'newPeriod' => ['type' => 'boolean'],
                        'fields'    => [
                            ...self::MARRIAGE,
                            'required'   => ['marriageDate'],
                            'properties' => [
                                ...self::MARRIAGE['properties'],
                                'maleNewSurname'   => ['type' => ['string', 'null']],
                                'femaleNewSurname' => ['type' => ['string', 'null']],
                            ],
                        ],
                        ...self::EVIDENCE,
                    ],
                    'required' => ['type', 'first', 'second', 'fields', 'newPeriod', 'evidence'],
                ],
                [
                    ...self::COMMON,
                    'properties' => [
                        'type'       => ['const' => 'update_marriage_period'],
                        'marriageId' => self::ID,
                        'fields'     => self::MARRIAGE,
                        ...self::EVIDENCE,
                    ],
                    'required' => ['type', 'marriageId', 'fields', 'evidence'],
                ],
                [
                    ...self::COMMON,
                    'properties' => [
                        'type'       => ['const' => 'remove_marriage_period'],
                        'marriageId' => self::ID,
                        ...self::EVIDENCE,
                    ],
                    'required' => ['type', 'marriageId', 'evidence'],
                ],
                [
                    ...self::COMMON,
                    'description' => 'Create a separate manual event record for every participant with shared data. '
                        . 'Subsequent edits are independent. Participants can be existing IDs or new temporary IDs.',
                    'properties' => [
                        'type'      => ['const' => 'create_events'],
                        'personIds' => ['type' => 'array', 'minItems' => 1, 'uniqueItems' => true, 'items' => self::ID],
                        'event'     => self::EVENT,
                        ...self::EVIDENCE,
                    ],
                    'required' => ['type', 'personIds', 'event', 'evidence'],
                ],
                [
                    ...self::COMMON,
                    'properties' => [
                        'type'    => ['const' => 'update_event'],
                        'eventId' => self::ID,
                        'fields'  => self::EVENT,
                        ...self::EVIDENCE,
                    ],
                    'required' => ['type', 'eventId', 'fields', 'evidence'],
                ],
                [
                    ...self::COMMON,
                    'properties' => ['type' => ['const' => 'delete_event'], 'eventId' => self::ID, ...self::EVIDENCE],
                    'required'   => ['type', 'eventId', 'evidence'],
                ],
                [
                    ...self::COMMON,
                    'properties' => [
                        'type'    => ['const' => 'set_event_place'],
                        'eventId' => self::ID,
                        'place'   => self::PLACE,
                        ...self::EVIDENCE,
                    ],
                    'required' => ['type', 'eventId', 'place', 'evidence'],
                ],
                [
                    ...self::COMMON,
                    'properties' => [
                        'type'     => ['const' => 'reorder_events'],
                        'personId' => self::ID,
                        'eventIds' => ['type' => 'array', 'minItems' => 2, 'uniqueItems' => true, 'items' => self::ID],
                        ...self::EVIDENCE,
                    ],
                    'required' => ['type', 'personId', 'eventIds', 'evidence'],
                ],
            ],
        ],
    ];
    public const array SOURCES = [
        'type'     => 'array',
        'minItems' => 1,
        'items'    => [
            'type'       => 'object',
            'properties' => [
                'id'      => ['type' => 'string', 'minLength' => 1],
                'role'    => ['const' => 'user'],
                'content' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20000],
            ],
            'required'             => ['id', 'role', 'content'],
            'additionalProperties' => false,
        ],
    ];

    public function getSchemaFragment(array $context = []): array
    {
        return self::OPERATIONS;
    }
}
