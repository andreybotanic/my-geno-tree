<?php

namespace App\Tool;

use App\Api\ApiException;
use App\Application\Tree\TreeContextService;
use App\Entity\User;
use App\Repository\FamilyTreeRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Bundle\SecurityBundle\Security;

#[AsTool(
    name: 'get_tree_context',
    description: <<<'DESCRIPTION'
    Read the selected tree, narrative reference, bounded immediate family and ETag.
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
final readonly class TreeReadTools
{
    public function __construct(
        private TreeContextService $context,
        private FamilyTreeRepository $trees,
        private Security $security,
    ) {
    }

    /** MCP only: the site's Symfony AI registry exposes only the three AsTool methods.
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
        description: 'Read the specified tree, optional reference person, immediate family and current ETag.',
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
    public function find(string $treeId, string $query): array
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
}
