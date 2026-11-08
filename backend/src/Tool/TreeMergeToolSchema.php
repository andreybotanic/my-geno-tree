<?php

namespace App\Tool;

use Symfony\AI\Platform\Contract\JsonSchema\Provider\SchemaProviderInterface;

/** Explicit tool input schema; the same value objects are validated by the REST proposal validator. */
final class TreeMergeToolSchema implements SchemaProviderInterface
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
    public const array EVIDENCE = [
        'type'     => 'array',
        'minItems' => 1,
        'maxItems' => 10,
        'items'    => [
            'type'       => 'object',
            'properties' => [
                'messageId' => ['type' => 'string'],
                'quote'     => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
            ],
            'required'             => ['messageId', 'quote'],
            'additionalProperties' => false,
        ],
    ];
    private const array REFERENCE = ['type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]{1,64}$'];
    public const array PROPOSAL = [
        'type'                 => 'object',
        'additionalProperties' => false,
        'required'             => ['people', 'parents', 'marriages', 'questions'],
        'properties'           => [
            'people' => [
                'type'        => 'array',
                'maxItems'    => 20,
                'description' => 'At most ten NEW people; unchanged existing fields may be omitted.',
                'items'       => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => [
                        'id',
                        'existingPersonId',
                        'resolution',
                        'fields',
                        'lifeStatus',
                        'evidence',
                    ],
                    'properties' => [
                        'id'               => self::REFERENCE,
                        'existingPersonId' => ['type' => ['string', 'null']],
                        'resolution'       => ['enum' => ['new', 'existing', 'undecided']],
                        'lifeStatus'       => ['enum' => ['alive', 'deceased', 'uncertain']],
                        // Tool payloads decode an empty JSON object as an empty PHP array.
                        'fields' => [
                            'type'                 => ['object', 'array'],
                            'maxItems'             => 0,
                            'additionalProperties' => false,
                            'properties'           => [
                                'birthSurname' => self::TEXT,
                                'givenName'    => self::TEXT,
                                'patronymic'   => self::TEXT,
                                'gender'       => ['enum' => ['male', 'female', 'unknown']],
                                'birthDate'    => self::DATE,
                                'deathDate'    => self::OPTIONAL_DATE,
                            ],
                        ],
                        'evidence' => self::EVIDENCE,
                    ],
                ],
            ],
            'parents' => [
                'type'     => 'array',
                'maxItems' => 40,
                'items'    => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => ['parent', 'child', 'role', 'evidence'],
                    'properties'           => [
                        'parent'   => self::REFERENCE,
                        'child'    => self::REFERENCE,
                        'role'     => ['enum' => ['father', 'mother']],
                        'evidence' => self::EVIDENCE,
                    ],
                ],
            ],
            'marriages' => [
                'type'     => 'array',
                'maxItems' => 20,
                'items'    => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => [
                        'first',
                        'second',
                        'marriageDate',
                        'dissolutionDate',
                        'newPeriod',
                        'evidence',
                    ],
                    'properties' => [
                        'first'           => self::REFERENCE,
                        'second'          => self::REFERENCE,
                        'marriageDate'    => self::DATE,
                        'dissolutionDate' => self::OPTIONAL_DATE,
                        'newPeriod'       => ['type' => 'boolean'],
                        'evidence'        => self::EVIDENCE,
                    ],
                ],
            ],
            'questions' => [
                'type'     => 'array',
                'maxItems' => 30,
                'items'    => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000],
            ],
        ],
    ];

    public function getSchemaFragment(array $context = []): array
    {
        return self::PROPOSAL;
    }
}
