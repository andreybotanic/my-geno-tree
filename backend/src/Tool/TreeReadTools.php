<?php

namespace App\Tool;

use App\Api\ApiException;
use App\Application\Map\GeoapifyGeocoder;
use App\Application\Tree\TreeContextService;
use App\Application\Tree\TreeReadService;
use App\Entity\User;
use App\Repository\FamilyTreeRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Bundle\SecurityBundle\Security;

#[AsTool(
    name: 'get_tree_context',
    description: <<<'DESCRIPTION'
    Start tree work here to read its name, metadata, server currentDate, total personCount and ETag.
    Read a person and bounded immediate family; follow links for more distant relatives.
    Omitted or null referencePersonId uses the tree's current primary reference, not the user's identity.
    An empty tree has no reference and returns no people.
    DESCRIPTION,
    method: 'tree'
)]
#[AsTool(
    name: 'find_people',
    description: <<<'DESCRIPTION'
    Search names; read candidate records before deciding
    whether a name uniquely identifies someone.
    DESCRIPTION,
    method: 'find'
)]
#[AsTool(
    name: 'get_person_context',
    description: <<<'DESCRIPTION'
    Read a person and bounded immediate family in the selected tree.
    DESCRIPTION,
    method: 'person'
)]
#[AsTool(
    name: 'list_people',
    description: self::PEOPLE_DESCRIPTION,
    method: 'people',
)]
#[AsTool(
    name: 'list_relationships',
    description: self::RELATIONSHIPS_DESCRIPTION,
    method: 'relationships',
)]
#[AsTool(
    name: 'list_events',
    description: self::EVENTS_DESCRIPTION,
    method: 'events',
)]
#[AsTool(
    name: 'find_places',
    description: 'Find place candidates. If no reliable match exists, preserve the name without coordinates.',
    method: 'places',
)]
final readonly class TreeReadTools
{
    private const string PAGINATION_DESCRIPTION = ' Returns items, total, returned, nextCursor and etag. '
        . 'Pass nextCursor back as cursor until it is null to read the complete selection. '
        . 'Pages default to 100 records, at most 200 and 128 KiB; large records shorten a page. '
        . 'Reuse expectedEtag across lists for one tree version. A version conflict requires a fresh read.';
    private const string PEOPLE_DESCRIPTION = 'List every person, or only personIds when supplied (empty means none). '
        . 'ID is always returned. Groups: identity (names and gender), life (birth/death dates), '
        . 'places (birth/death places and available coordinates), metadata (creation/update dates). '
        . 'Default groups are identity and life. All four groups return every direct person field except photos.'
        . self::PAGINATION_DESCRIPTION;
    private const string RELATIONSHIPS_DESCRIPTION = 'List parent links or marriage periods. '
        . 'Optional personIds matches either participant (empty means none). '
        . 'Returns participant IDs, parent roles, and complete marriage dates, places and metadata.'
        . self::PAGINATION_DESCRIPTION;
    private const string EVENTS_DESCRIPTION = 'List complete events for every person or selected personIds. '
        . 'Optional types and source (manual or automatic) filter the selection. '
        . 'Returns full descriptions, dates, places, typed data, source references, manual order and metadata. '
        . 'Descriptions are never truncated. Photos are excluded.' . self::PAGINATION_DESCRIPTION;

    public function __construct(
        private TreeContextService $context,
        private FamilyTreeRepository $trees,
        private Security $security,
        private TreeReadService $reads,
        private GeoapifyGeocoder $geocoder,
    ) {
    }

    /** MCP only: the site already selects its tree.
     * @return array{trees: list<array{id: string, name: string}>}
     */
    #[McpTool(
        name: 'find_trees',
        description: 'Find your trees by fuzzy title or exact UUID. Returns up to five candidates. ' .
            'Ask the user when the target tree is ambiguous.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function findTrees(string $query): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new ApiException(401, 'INVALID_SESSION');
        }

        return ['trees' => $this->trees->searchForOwner($user, $query)];
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'get_tree_context',
        description: <<<'DESCRIPTION'
        Start tree work here to read its name, metadata, server currentDate, total personCount and ETag.
        Read a person and bounded immediate family; follow links for more distant relatives.
        Omitted or null referencePersonId uses the tree's current primary reference, not the user's identity.
        An empty tree has no reference and returns no people.
        DESCRIPTION,
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function tree(string $treeId, ?string $referencePersonId = null): array
    {
        return $this->context->context($treeId, $referencePersonId);
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'find_people',
        description: 'Search names in the specified tree. Read candidate context before choosing a person.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function find(string $treeId, #[Schema(minLength: 2, maxLength: 255)] string $query): array
    {
        return ['query' => $query, 'candidates' => $this->context->find($treeId, $query)];
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'get_person_context',
        description: 'Read a person and immediate relatives from the specified tree.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function person(string $treeId, string $personId): array
    {
        return $this->context->context($treeId, $personId);
    }

    /** @param list<string>|null $personIds
     * @param list<string> $groups
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_people',
        description: self::PEOPLE_DESCRIPTION,
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function people(
        string $treeId,
        ?array $personIds = null,
        array $groups = ['identity', 'life'],
        ?string $cursor = null,
        #[Schema(minimum: 1, maximum: 200)]
        #[\Mcp\Capability\Attribute\Schema(minimum: 1, maximum: 200)]
        int $limit = 100,
        ?string $expectedEtag = null,
    ): array {
        return $this->reads->people(
            $this->context->tree($treeId),
            $personIds,
            $groups,
            cursor: $cursor,
            limit: $limit,
            expectedEtag: $expectedEtag,
        );
    }

    /** @param list<string>|null $personIds
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_relationships',
        description: self::RELATIONSHIPS_DESCRIPTION,
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function relationships(
        string $treeId,
        #[Schema(enum: ['parents', 'marriages'])]
        #[\Mcp\Capability\Attribute\Schema(enum: ['parents', 'marriages'])]
        string $kind,
        ?array $personIds = null,
        ?string $cursor = null,
        #[Schema(minimum: 1, maximum: 200)]
        #[\Mcp\Capability\Attribute\Schema(minimum: 1, maximum: 200)]
        int $limit = 100,
        ?string $expectedEtag = null,
    ): array {
        if (!in_array($kind, ['parents', 'marriages'], true)) {
            throw new ApiException(422, 'TREE_READ_INVALID');
        }

        $tree = $this->context->tree($treeId);
        $read = 'parents' === $kind ? $this->reads->parents(...) : $this->reads->marriages(...);

        return $read(
            $tree,
            $personIds,
            cursor: $cursor,
            limit: $limit,
            expectedEtag: $expectedEtag,
        );
    }

    /** @param list<string>|null $personIds
     * @param list<string>|null $types
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_events',
        description: self::EVENTS_DESCRIPTION,
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function events(
        string $treeId,
        ?array $personIds = null,
        ?array $types = null,
        ?string $source = null,
        ?string $cursor = null,
        #[Schema(minimum: 1, maximum: 200)]
        #[\Mcp\Capability\Attribute\Schema(minimum: 1, maximum: 200)]
        int $limit = 100,
        ?string $expectedEtag = null,
    ): array {
        return $this->reads->events(
            $this->context->tree($treeId),
            $personIds,
            types: $types,
            source: $source,
            cursor: $cursor,
            limit: $limit,
            expectedEtag: $expectedEtag,
        );
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'find_places',
        description: 'Find place candidates. If unavailable, save the original name with coordinates=null.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true),
    )]
    public function places(string $query): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new ApiException(401, 'INVALID_SESSION');
        }
        try {
            return ['items' => $this->geocoder->autocomplete($query, $user->getPreferredLanguage())];
        } catch (ApiException $error) {
            if ($error->getStatus() < 500) {
                throw $error;
            }

            return ['items' => [], 'unavailable' => true];
        }
    }
}
