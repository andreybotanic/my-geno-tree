<?php

namespace App\Tool;

use App\Application\Tree\TreeContextService;
use App\Application\Tree\TreeVersion;
use App\Application\TreeMerge\TreeMergeService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

#[AsTool(
    name: 'change_tree',
    description: self::CHANGE_DESCRIPTION . ' Without create_person applies immediately. '
        . 'Any create_person requires review of the whole packet in the site chat. Read every existing target first. '
        . 'Use temporary create_person IDs within the packet. '
        . 'Never retry after losing a mutation response; read first.',
)]
final readonly class TreeChangeTool
{
    private const string CHANGE_DESCRIPTION = 'Change the tree atomically using the latest read ETag. '
        . 'At most ten new people per packet. Automatic events are edited through their source person or '
        . 'relationship; set_event_place can edit their canonical place directly.';

    public function __construct(
        private TreeMergeTool $chat,
        private TreeMergeService $changes,
        private TreeContextService $trees,
        private TreeVersion $versions,
    ) {
    }

    /** @param list<array<string, mixed>> $operations
     * @return array<string, mixed>
     */
    public function __invoke(
        string $treeId,
        string $ifMatch,
        #[Schema(provider: TreeChangeToolSchema::class)] array $operations,
    ): array {
        return $this->chat->submit($treeId, ['operations' => $operations], $ifMatch);
    }

    /** @param list<array<string, mixed>> $operations
     * @param list<array{id: string, role: string, content: string}> $sources
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'change_tree',
        description: self::CHANGE_DESCRIPTION . ' Apply immediately, including creation, without server review. '
            . 'Read all targets and the latest ETag first. Sources contain exact user instructions. '
            . 'A conflict or lost response requires a new read, never a blind retry.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    ),]
    public function apply(
        string $treeId,
        string $ifMatch,
        #[\Mcp\Capability\Attribute\Schema(definition: TreeChangeToolSchema::OPERATIONS)] array $operations,
        #[\Mcp\Capability\Attribute\Schema(definition: TreeChangeToolSchema::SOURCES)] array $sources,
    ): array {
        $tree = $this->trees->tree($treeId, true);
        $this->versions->assertMatches($tree, $ifMatch);

        return $this->changes->apply(
            $tree,
            $this->changes->prepare($tree, ['operations' => $operations], $sources),
            $ifMatch,
        );
    }
}
