<?php

namespace App\Tool;

use Symfony\AI\Platform\Contract\JsonSchema\Provider\SchemaProviderInterface;

/** Shared value contracts for single tree operations in Symfony AI and MCP. */
final class TreeActionSchema implements SchemaProviderInterface
{
    private const array UNKNOWN = [
        'type'                 => 'object',
        'properties'           => ['state' => ['const' => 'unknown']],
        'required'             => ['state'],
        'additionalProperties' => false,
    ];
    public const array TEXT = [
        'oneOf' => [
            self::UNKNOWN,
            [
                'type'       => 'object',
                'properties' => [
                    'state' => ['const' => 'known'],
                    'value' => ['type' => 'string', 'minLength' => 1],
                ],
                'required'             => ['state', 'value'],
                'additionalProperties' => false,
            ],
        ],
    ];
    private const array DATE_PARTS = [
        'year'  => ['type' => 'integer'],
        'month' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
        'day'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 31],
    ];
    public const array DATE = [
        'oneOf' => [
            self::UNKNOWN,
            [
                'type'       => 'object',
                'properties' => [
                    'state' => ['const' => 'known'],
                    'kind'  => ['enum' => ['exact', 'approximate', 'before', 'after', 'between']],
                    ...self::DATE_PARTS,
                    'end' => [
                        'type'                 => 'object',
                        'properties'           => self::DATE_PARTS,
                        'required'             => ['year'],
                        'additionalProperties' => false,
                    ],
                ],
                'required'             => ['state', 'kind', 'year'],
                'additionalProperties' => false,
            ],
        ],
    ];
    public const array OPTIONAL_DATE = ['oneOf' => [['type' => 'null'], self::DATE]];
    public const array ID = ['type' => 'string', 'minLength' => 1, 'maxLength' => 64];
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
            'birthSurname' => self::TEXT,
            'givenName'    => self::TEXT,
            'patronymic'   => self::TEXT,
            'gender'       => ['enum' => ['male', 'female', 'unknown']],
            'birthDate'    => self::DATE,
            'deathDate'    => self::OPTIONAL_DATE,
            'birthPlace'   => self::PLACE,
            'deathPlace'   => self::PLACE,
        ],
    ];
    public const array MARRIAGE = [
        ...self::OBJECT,
        'properties' => [
            'marriageDate'     => self::DATE,
            'dissolutionDate'  => self::OPTIONAL_DATE,
            'marriagePlace'    => self::PLACE,
            'dissolutionPlace' => self::PLACE,
        ],
    ];
    public const array MARRIAGE_CREATE = [
        ...self::MARRIAGE,
        'required'   => ['marriageDate'],
        'properties' => [
            ...self::MARRIAGE['properties'],
            'maleNewSurname'   => ['type' => ['string', 'null']],
            'femaleNewSurname' => ['type' => ['string', 'null']],
        ],
    ];
    public const array EVENT = [
        ...self::OBJECT,
        'properties' => [
            'type'        => ['enum' => ['custom', 'residence', 'surname_change']],
            'date'        => self::DATE,
            'title'       => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
            'description' => ['type' => ['string', 'null'], 'maxLength' => 5000],
            'newSurname'  => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
            'place'       => self::PLACE,
        ],
        'description' => 'Create: type and date required; custom also title and description, '
            . 'residence description, surname_change newSurname. Patch: send only changed fields.',
    ];

    public const array CONNECTION = [
        'description' => 'type describes the NEW person relative to existing personId: '
            . 'parent creates their parent (role is the NEW parent); '
            . 'child creates their child (role is the EXISTING parent). '
            . 'For a daughter of an existing mother use type=child and role=mother. '
            . 'Supply otherParentId when both parents exist. Otherwise an unnamed second parent is created '
            . 'in the same transaction. Reuse their returned ID to fill in facts later.',
        'oneOf' => [
            ['type' => 'null'],
            [
                'type'       => 'object',
                'properties' => [
                    'type'          => ['enum' => ['parent', 'child']],
                    'personId'      => self::ID,
                    'role'          => ['enum' => ['mother', 'father']],
                    'otherParentId' => ['oneOf' => [['type' => 'null'], self::ID]],
                ],
                'required'             => ['type', 'personId', 'role'],
                'additionalProperties' => false,
            ],
            [
                'type'       => 'object',
                'properties' => [
                    'type'     => ['const' => 'spouse'],
                    'personId' => self::ID,
                    'marriage' => self::MARRIAGE_CREATE,
                ],
                'required'             => ['type', 'personId', 'marriage'],
                'additionalProperties' => false,
            ],
        ],
    ];

    public function getSchemaFragment(array $context = []): array
    {
        return match ($context['type'] ?? null) {
            'person'          => self::PERSON,
            'marriage'        => self::MARRIAGE,
            'marriage_create' => self::MARRIAGE_CREATE,
            'event'           => self::EVENT,
            'place'           => self::PLACE,
            'connection'      => self::CONNECTION,
            default           => throw new \InvalidArgumentException('Unknown action schema.'),
        };
    }
}
