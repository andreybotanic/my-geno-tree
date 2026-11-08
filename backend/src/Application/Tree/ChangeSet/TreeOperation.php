<?php

namespace App\Application\Tree\ChangeSet;

use App\Api\ApiException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** A source-independent, validated operation envelope; field policies validate its values. */
#[Exclude]
final readonly class TreeOperation
{
    public const array FIELDS = [
        'create_person'          => ['id', 'fields'],
        'update_person'          => ['personId', 'fields'],
        'delete_person'          => ['personId'],
        'add_parent_link'        => ['parent', 'child', 'role'],
        'remove_parent_link'     => ['parentLinkId'],
        'create_marriage_period' => ['first', 'second', 'fields', 'newPeriod'],
        'update_marriage_period' => ['marriageId', 'fields'],
        'remove_marriage_period' => ['marriageId'],
        'create_events'          => ['personIds', 'event'],
        'update_event'           => ['eventId', 'fields'],
        'delete_event'           => ['eventId'],
        'set_event_place'        => ['eventId', 'place'],
        'reorder_events'         => ['personId', 'eventIds'],
    ];
    public string $type;

    /** @param array<string, mixed> $arguments */
    public function __construct(public array $arguments)
    {
        $type = $arguments['type'] ?? null;
        if (!is_string($type) || !isset(self::FIELDS[$type])) {
            throw new ApiException(422, 'TREE_CHANGE_INVALID', fields: ['type' => ['operation_type_invalid']]);
        }
        $required = ['type', ...self::FIELDS[$type]];
        if ([] !== array_diff($required, array_keys($arguments))
            || [] !== array_diff(array_keys($arguments), [...$required, 'evidence'])) {
            throw new ApiException(422, 'TREE_CHANGE_INVALID', fields: ['operations' => ['operation_fields_invalid']]);
        }
        $this->type = $type;
    }

    public function string(string $key): string
    {
        $value = $this->arguments[$key] ?? null;
        if (!is_string($value) || '' === $value || strlen($value) > 64) {
            throw new ApiException(422, 'TREE_CHANGE_INVALID', fields: [$key => ['reference_invalid']]);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function object(string $key): array
    {
        $value = $this->arguments[$key] ?? null;
        if (!is_array($value) || ([] !== $value && array_is_list($value))) {
            throw new ApiException(422, 'TREE_CHANGE_INVALID', fields: [$key => ['object_required']]);
        }

        return $value;
    }

    /** @return list<string> */
    public function strings(string $key): array
    {
        $value = $this->arguments[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)
            || array_any($value, static fn (mixed $item): bool => !is_string($item) || '' === $item)) {
            throw new ApiException(422, 'TREE_CHANGE_INVALID', fields: [$key => ['reference_list_invalid']]);
        }

        return $value;
    }

    public function boolean(string $key): bool
    {
        $value = $this->arguments[$key] ?? null;
        if (!is_bool($value)) {
            throw new ApiException(422, 'TREE_CHANGE_INVALID', fields: [$key => ['boolean_required']]);
        }

        return $value;
    }
}
