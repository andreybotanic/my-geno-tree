<?php

namespace App\Tool;

use App\Api\ApiException;
use App\Application\Chat\ChatEventPublisherInterface;
use App\Application\Chat\ChatInterface;
use App\Application\Tree\TreeContextService;
use App\Application\Tree\TreeVersion;
use App\Application\TreeMerge\TreeMergeService;
use App\Entity\FamilyTree;
use App\Model\Conversation;
use App\Model\TreeMergeProposal;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Uid\Uuid;

/** The only chat adapter for pending merge proposals; tree mutations live in TreeMergeService. */
#[AsTool(
    name: 'merge',
    description: <<<'DESCRIPTION'
    Validate the complete tree proposal. Without new people apply immediately; otherwise review the entire packet.
    Returns exact new/existing person counts and the proposal version.
    Correct rejected arguments using the returned field diagnostics.
    DESCRIPTION
)]
final readonly class TreeMergeTool
{
    public function __construct(
        private ChatInterface $chat,
        private TreeMergeService $merge,
        private TreeContextService $trees,
        private ChatEventPublisherInterface $events,
        private TreeVersion $versions,
    ) {
    }

    /** Apply directly for external clients; the chat's merge tool only prepares a preview.
     * @param array<string, mixed>                                   $proposal
     * @param list<array{id: string, role: string, content: string}> $sources
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'merge',
        description: 'APPLY changes immediately to the specified tree, subject to your client ' .
            'approval settings. Read full person context first. Send the latest ETag as ' .
            'ifMatch and exact user quotes with their source message IDs. A conflict ' .
            'requires a fresh read and a new proposal.',
        annotations: new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: true,
            idempotentHint: false,
            openWorldHint: false,
        ),
    )]
    public function apply(
        string $treeId,
        string $ifMatch,
        #[\Mcp\Capability\Attribute\Schema(definition: TreeMergeToolSchema::PROPOSAL)]
        array $proposal,
        #[\Mcp\Capability\Attribute\Schema(
            type: 'array',
            minItems: 1,
            maxItems: 30,
            items: [
                'type'       => 'object',
                'properties' => [
                    'id'      => ['type' => 'string', 'minLength' => 1],
                    'role'    => ['const' => 'user'],
                    'content' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20000],
                ],
                'required'             => ['id', 'role', 'content'],
                'additionalProperties' => false,
            ],
        )]
        array $sources,
    ): array {
        $tree = $this->trees->tree($treeId, true);
        $this->versions->assertMatches($tree, $ifMatch);

        return $this->merge->apply($tree, $this->merge->prepare($tree, $proposal, $sources), $ifMatch);
    }

    /** @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    public function __invoke(string $treeId, #[Schema(provider: TreeMergeToolSchema::class)] array $proposal): array
    {
        return $this->submit($treeId, $proposal);
    }

    /** @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    public function submit(string $treeId, array $proposal, ?string $ifMatch = null): array
    {
        try {
            $execution = $this->chat->execution();
            $conversation = $execution->conversation;
            if (($conversation->context['treeId'] ?? null) !== $treeId) {
                throw new ApiException(404, 'TREE_NOT_FOUND');
            }
            $tree = $this->trees->tree($treeId, true);
            $version = $ifMatch ?? $this->versions->etag($tree);
            $this->versions->assertMatches($tree, $version);
            $observed = $events = $relationships = [];
            foreach ($execution->observations as $context) {
                if (($context['treeId'] ?? null) !== $treeId || ($context['etag'] ?? null) !== $version) {
                    continue;
                }
                foreach (is_array($context['people'] ?? null) ? $context['people'] : [] as $person) {
                    if (is_array($person) && is_string($person['id'] ?? null)) {
                        $observed[] = $person['id'];
                    }
                }
                foreach (['events', 'relationships'] as $group) {
                    foreach (is_array($context[$group] ?? null) ? $context[$group] : [] as $row) {
                        if (is_array($row) && is_string($row['id'] ?? null)) {
                            if ('events' === $group) {
                                $events[] = $row['id'];
                            } else {
                                $relationships[] = $row['id'];
                            }
                        }
                    }
                }
            }
            $new = [];
            $operations = is_array($proposal['operations'] ?? null) ? $proposal['operations'] : [];
            foreach ($operations as $row) {
                if (is_array($row) && 'create_person' === ($row['type'] ?? null) && is_string($row['id'] ?? null)) {
                    $new[] = $row['id'];
                }
            }
            foreach ($operations as $row) {
                if (!is_array($row)) {
                    throw new ApiException(422, 'TREE_CHANGE_INVALID');
                }
                foreach (['personId', 'parent', 'child', 'first', 'second'] as $key) {
                    if (isset($row[$key]) && !in_array($row[$key], [...$observed, ...$new], true)) {
                        throw new ApiException(422, 'TREE_MERGE_UNOBSERVED_PERSON');
                    }
                }
                foreach (is_array($row['personIds'] ?? null) ? $row['personIds'] : [] as $id) {
                    if (!in_array($id, [...$observed, ...$new], true)) {
                        throw new ApiException(422, 'TREE_MERGE_UNOBSERVED_PERSON');
                    }
                }
                foreach (['eventId', 'parentLinkId', 'marriageId'] as $key) {
                    $allowed = 'eventId' === $key ? $events : $relationships;
                    if (isset($row[$key]) && !in_array($row[$key], $allowed, true)) {
                        throw new ApiException(422, 'TREE_CHANGE_UNOBSERVED_RESOURCE');
                    }
                }
                foreach (is_array($row['eventIds'] ?? null) ? $row['eventIds'] : [] as $id) {
                    if (!in_array($id, $events, true)) {
                        throw new ApiException(422, 'TREE_CHANGE_UNOBSERVED_RESOURCE');
                    }
                }
            }
            foreach (is_array($proposal['people'] ?? null) ? $proposal['people'] : [] as $person) {
                if (is_array($person) && null !== ($person['existingPersonId'] ?? null)
                    && !in_array($person['existingPersonId'], $observed, true)) {
                    throw new ApiException(422, 'TREE_MERGE_UNOBSERVED_PERSON');
                }
            }
            $current = $conversation->tools['merge'] ?? null;
            $pending = $this->merge->prepare(
                $tree,
                $proposal,
                $this->chat->sources($conversation),
                $current instanceof TreeMergeProposal ? $current : null,
                false,
                ($conversation->toolVersions['merge'] ?? 0) + 1
            );
            /** @var list<array{resolution: string}> $people */
            $people = $pending->proposal['people'];
            $newCount = count(array_filter($people, static fn ($person) => 'new' === $person['resolution']));
            if (0 === $newCount && 'ready' === $pending->state) {
                $this->chat->execution();
                $result = $this->merge->apply($tree, $pending, $version);
                $this->chat->replaceTool($conversation, 'merge', null, false);
                $this->events->toolApplied(
                    $conversation,
                    isset($proposal['operations']) ? 'change_tree' : 'merge',
                    Uuid::v7()->toRfc4122(),
                    $result,
                );

                return ['state' => 'applied', 'result' => $result, 'newPeople' => 0];
            }
            $this->chat->replaceTool($conversation, 'merge', $pending, false);

            return [
                'newPeople' => count(
                    array_filter($people, static fn ($person) => 'new' === $person['resolution']),
                ),
                'existingPeople' => count(
                    array_filter($people, static fn ($person) => 'existing' === $person['resolution']),
                ),
                'state'           => $pending->state,
                'proposalVersion' => $pending->version,
            ];
        } catch (ApiException $error) {
            return [
                'error' => [
                    'code'    => $error->getErrorCode(),
                    'fields'  => $error->getFields(),
                    'details' => $error->getDetails(),
                ],
            ];
        }
    }

    /** UI-only operations; none of these methods is exposed as an AI tool.
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|Conversation
     */
    public function proposal(
        FamilyTree $tree,
        string $id,
        string $action,
        array $payload,
        ?string $ifMatch = null,
    ): array|Conversation {
        $keys = 'revise' === $action ? ['version', 'proposal'] : ['version'];
        if (count($keys) !== count($payload) || array_diff($keys, array_keys($payload))
            || !is_int($payload['version'] ?? null)) {
            throw new ApiException(422, 'TREE_MERGE_INVALID');
        }
        $context = 'tree:' . $tree->getId()->toRfc4122();
        $conversation = $this->chat->current($context) ?? throw new ApiException(404, 'CHAT_NOT_FOUND');
        $pending = $conversation->tools['merge'] ?? null;
        if (!$pending instanceof TreeMergeProposal || $pending->id->toRfc4122() !== $id) {
            throw new ApiException(404, 'TREE_MERGE_NOT_FOUND');
        }
        if ($pending->version !== $payload['version']) {
            throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT');
        }
        if ('apply' === $action && ('processing' === $conversation->status || 'ready' !== $pending->state)) {
            throw new ApiException(409, 'TREE_MERGE_NOT_READY');
        }
        if ('revise' === $action || 'revalidate' === $action) {
            $next = 'revise' === $action
                ? $this->merge->prepare(
                    $tree,
                    $payload['proposal'] ?? null,
                    $this->chat->sources($conversation),
                    $pending,
                    true,
                )
                : $this->merge->revalidate($tree, $pending);
            $next->version = ($conversation->toolVersions['merge'] ?? 0) + 1;

            return $this->chat->replaceTool($conversation, 'merge', $next);
        }
        // Consuming before the SQL transaction prevents duplicate application without storing receipts.
        $consumed = $this->chat->replaceTool($conversation, 'merge', null);
        if ('discard' === $action) {
            return $consumed;
        }
        try {
            $result = $this->merge->apply($tree, $pending, $ifMatch);
        } catch (ApiException $error) {
            $latest = $this->chat->current($context);
            if (null !== $latest && $latest->id->equals($consumed->id) && !isset($latest->tools['merge'])
                && $latest->toolVersions['merge'] === $consumed->toolVersions['merge']) {
                $restored = 412 === $error->getStatus() ? $this->merge->revalidate($tree, $pending) : $pending;
                $restored->version = $latest->toolVersions['merge'] + 1;
                try {
                    $this->chat->replaceTool($latest, 'merge', $restored);
                } catch (ApiException) { /* Concurrent replacement/reset wins over restoring an old proposal. */
                }
            }
            throw $error;
        }
        $current = $this->chat->current($context);
        $view = null !== $current && $current->id->equals($consumed->id) ? $current : $consumed;
        $this->events->toolApplied($view, 'merge', $pending->id->toRfc4122(), $result);

        return [
            'conversation' => $this->chat->snapshot($view),
            'operationId'  => $pending->id->toRfc4122(),
            'result'       => $result,
        ];
    }
}
