<?php

namespace App\Tool;

use App\Application\Tree\TreeActionService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

#[AsTool(
    name: 'create_person',
    description: self::CREATE_PERSON . self::ETAG,
    method: 'createPerson',
)]
#[AsTool(
    name: 'update_person',
    description: self::UPDATE_PERSON . self::ETAG,
    method: 'updatePerson',
)]
#[AsTool(
    name: 'delete_person',
    description: self::DELETE_PERSON . self::ETAG,
    method: 'deletePerson',
)]
#[AsTool(
    name: 'add_parent_link',
    description: self::ADD_PARENT_LINK . self::ETAG,
    method: 'addParentLink',
)]
#[AsTool(
    name: 'remove_parent_link',
    description: self::REMOVE_PARENT_LINK . self::ETAG,
    method: 'removeParentLink',
)]
#[AsTool(
    name: 'create_marriage_period',
    description: self::CREATE_MARRIAGE_PERIOD . self::ETAG,
    method: 'createMarriagePeriod',
)]
#[AsTool(
    name: 'update_marriage_period',
    description: self::UPDATE_MARRIAGE_PERIOD . self::ETAG,
    method: 'updateMarriagePeriod',
)]
#[AsTool(
    name: 'remove_marriage_period',
    description: self::REMOVE_MARRIAGE_PERIOD . self::ETAG,
    method: 'removeMarriagePeriod',
)]
#[AsTool(
    name: 'create_event',
    description: self::CREATE_EVENT . self::ETAG,
    method: 'createEvent',
)]
#[AsTool(
    name: 'update_event',
    description: self::UPDATE_EVENT . self::ETAG,
    method: 'updateEvent',
)]
#[AsTool(
    name: 'delete_event',
    description: self::DELETE_EVENT . self::ETAG,
    method: 'deleteEvent',
)]
#[AsTool(
    name: 'set_event_place',
    description: self::SET_EVENT_PLACE . self::ETAG,
    method: 'setEventPlace',
)]
#[AsTool(
    name: 'reorder_events',
    description: self::REORDER_EVENTS . self::ETAG,
    method: 'reorderEvents',
)]
final readonly class TreeWriteTools
{
    private const string ETAG = ' Pass the result etag to the next ifMatch without rereading the tree.';

    private const string CREATE_PERSON = 'Create a person. In a nonempty tree connection attaches them '
        . 'as parent, child or '
        . 'spouse of an existing person. Requires confirmation. '
        . 'Pass connection separately from fields. Parent connections always create both parent links; '
        . 'an unspecified second parent is created unnamed. The result identifies every created person.';

    private const string UPDATE_PERSON = 'Patch only requested fields of an existing person.';

    private const string DELETE_PERSON = 'Delete one person and their own events, attachments and relationships '
        . 'if domain rules '
        . 'allow. Requires confirmation.';

    private const string ADD_PARENT_LINK = 'Link an existing parent and child. Parenthood is independent of marriage.';

    private const string REMOVE_PARENT_LINK = 'Remove one parent link if the remaining tree stays connected.';

    private const string CREATE_MARRIAGE_PERIOD = 'Create one marriage period between existing people.';

    private const string UPDATE_MARRIAGE_PERIOD = 'Patch dates or places of a marriage period.';

    private const string REMOVE_MARRIAGE_PERIOD = 'Remove one marriage period, preserving parent links.';

    private const string CREATE_EVENT = 'Create one manual event for one person. Repeat for other participants.';

    private const string UPDATE_EVENT = 'Patch one manual event. Automatic events are changed through their source.';

    private const string DELETE_EVENT = 'Delete one manual event.';

    private const string SET_EVENT_PLACE = 'Set or clear the canonical place of an automatic or manual event.';

    private const string REORDER_EVENTS = 'Reorder events within an ambiguous chronology group.';

    public const array OPERATIONS = [
        'createPerson'         => 'create_person',
        'updatePerson'         => 'update_person',
        'deletePerson'         => 'delete_person',
        'addParentLink'        => 'add_parent_link',
        'removeParentLink'     => 'remove_parent_link',
        'createMarriagePeriod' => 'create_marriage_period',
        'updateMarriagePeriod' => 'update_marriage_period',
        'removeMarriagePeriod' => 'remove_marriage_period',
        'createEvent'          => 'create_event',
        'updateEvent'          => 'update_event',
        'deleteEvent'          => 'delete_event',
        'setEventPlace'        => 'set_event_place',
        'reorderEvents'        => 'reorder_events',
    ];

    public function __construct(private TreeActionService $actions)
    {
    }

    /**
     * @param array<string, mixed>      $fields
     * @param array<string, mixed>|null $connection
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'create_person',
        description: self::CREATE_PERSON . self::ETAG,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    public function createPerson(
        string $treeId,
        string $ifMatch,
        #[Schema(provider: TreeActionSchema::class, context: ['type' => 'person'])]
        #[\Mcp\Capability\Attribute\Schema(definition: TreeActionSchema::PERSON)]
        array $fields,
        #[Schema(provider: TreeActionSchema::class, context: ['type' => 'connection'])]
        #[\Mcp\Capability\Attribute\Schema(definition: TreeActionSchema::CONNECTION)]
        ?array $connection = null,
    ): array {
        return $this->actions->execute(
            'create_person',
            compact('treeId', 'ifMatch', 'fields', 'connection'),
        );
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'update_person',
        description: self::UPDATE_PERSON . self::ETAG,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    public function updatePerson(
        string $treeId,
        string $ifMatch,
        string $personId,
        #[Schema(provider: TreeActionSchema::class, context: ['type' => 'person'])]
        #[\Mcp\Capability\Attribute\Schema(definition: TreeActionSchema::PERSON)]
        array $fields,
    ): array {
        return $this->actions->execute(
            'update_person',
            compact('treeId', 'ifMatch', 'personId', 'fields'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'delete_person',
        description: self::DELETE_PERSON . self::ETAG,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    public function deletePerson(
        string $treeId,
        string $ifMatch,
        string $personId,
    ): array {
        return $this->actions->execute(
            'delete_person',
            compact('treeId', 'ifMatch', 'personId'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'add_parent_link',
        description: self::ADD_PARENT_LINK . self::ETAG,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    public function addParentLink(
        string $treeId,
        string $ifMatch,
        string $parentId,
        string $childId,
        string $role,
    ): array {
        return $this->actions->execute(
            'add_parent_link',
            compact('treeId', 'ifMatch', 'parentId', 'childId', 'role'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'remove_parent_link',
        description: self::REMOVE_PARENT_LINK . self::ETAG,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    public function removeParentLink(
        string $treeId,
        string $ifMatch,
        string $parentLinkId,
    ): array {
        return $this->actions->execute(
            'remove_parent_link',
            compact('treeId', 'ifMatch', 'parentLinkId'),
        );
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'create_marriage_period',
        description: self::CREATE_MARRIAGE_PERIOD . self::ETAG,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    public function createMarriagePeriod(
        string $treeId,
        string $ifMatch,
        string $first,
        string $second,
        #[Schema(provider: TreeActionSchema::class, context: ['type' => 'marriage_create'])]
        #[\Mcp\Capability\Attribute\Schema(definition: TreeActionSchema::MARRIAGE_CREATE)]
        array $fields,
    ): array {
        return $this->actions->execute(
            'create_marriage_period',
            compact('treeId', 'ifMatch', 'first', 'second', 'fields'),
        );
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'update_marriage_period',
        description: self::UPDATE_MARRIAGE_PERIOD . self::ETAG,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    public function updateMarriagePeriod(
        string $treeId,
        string $ifMatch,
        string $marriageId,
        #[Schema(provider: TreeActionSchema::class, context: ['type' => 'marriage'])]
        #[\Mcp\Capability\Attribute\Schema(definition: TreeActionSchema::MARRIAGE)]
        array $fields,
    ): array {
        return $this->actions->execute(
            'update_marriage_period',
            compact('treeId', 'ifMatch', 'marriageId', 'fields'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'remove_marriage_period',
        description: self::REMOVE_MARRIAGE_PERIOD . self::ETAG,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    public function removeMarriagePeriod(
        string $treeId,
        string $ifMatch,
        string $marriageId,
    ): array {
        return $this->actions->execute(
            'remove_marriage_period',
            compact('treeId', 'ifMatch', 'marriageId'),
        );
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'create_event',
        description: self::CREATE_EVENT . self::ETAG,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    public function createEvent(
        string $treeId,
        string $ifMatch,
        string $personId,
        #[Schema(provider: TreeActionSchema::class, context: ['type' => 'event'])]
        #[\Mcp\Capability\Attribute\Schema(definition: TreeActionSchema::EVENT)]
        array $event,
    ): array {
        return $this->actions->execute(
            'create_event',
            compact('treeId', 'ifMatch', 'personId', 'event'),
        );
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'update_event',
        description: self::UPDATE_EVENT . self::ETAG,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    public function updateEvent(
        string $treeId,
        string $ifMatch,
        string $personId,
        string $eventId,
        #[Schema(provider: TreeActionSchema::class, context: ['type' => 'event'])]
        #[\Mcp\Capability\Attribute\Schema(definition: TreeActionSchema::EVENT)]
        array $fields,
    ): array {
        return $this->actions->execute(
            'update_event',
            compact('treeId', 'ifMatch', 'personId', 'eventId', 'fields'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'delete_event',
        description: self::DELETE_EVENT . self::ETAG,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    public function deleteEvent(
        string $treeId,
        string $ifMatch,
        string $personId,
        string $eventId,
    ): array {
        return $this->actions->execute(
            'delete_event',
            compact('treeId', 'ifMatch', 'personId', 'eventId'),
        );
    }

    /**
     * @param array<string, mixed>|null $place
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'set_event_place',
        description: self::SET_EVENT_PLACE . self::ETAG,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    public function setEventPlace(
        string $treeId,
        string $ifMatch,
        string $eventId,
        #[Schema(provider: TreeActionSchema::class, context: ['type' => 'place'])]
        #[\Mcp\Capability\Attribute\Schema(definition: TreeActionSchema::PLACE)]
        ?array $place,
    ): array {
        return $this->actions->execute(
            'set_event_place',
            compact('treeId', 'ifMatch', 'eventId', 'place'),
        );
    }

    /**
     * @param list<string> $eventIds
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'reorder_events',
        description: self::REORDER_EVENTS . self::ETAG,
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    public function reorderEvents(
        string $treeId,
        string $ifMatch,
        string $personId,
        #[Schema(minItems: 2, uniqueItems: true)]
        #[\Mcp\Capability\Attribute\Schema(
            type: 'array',
            minItems: 2,
            uniqueItems: true,
            items: ['type' => 'string'],
        )]
        array $eventIds,
    ): array {
        return $this->actions->execute(
            'reorder_events',
            compact('treeId', 'ifMatch', 'personId', 'eventIds'),
        );
    }
}
