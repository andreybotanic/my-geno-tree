<?php

namespace App\Tool;

use App\Api\ApiException;
use App\Api\ChatViewFactory;
use App\Application\Chat\Chat;
use App\Application\Chat\ChatActivity;
use App\Application\Chat\ChatEvents;
use App\Application\Tree\TreeContextService;
use App\Application\TreeMerge\TreeMergeService;
use App\Entity\AuthSession;
use App\Model\TreeMergeProposal;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsTool(name: 'merge', description: <<<'DESCRIPTION'
    Validate and prepare the complete tree proposal for preview and separate UI confirmation.
    Returns exact new/existing person counts and the proposal version.
    Correct rejected arguments using the returned field diagnostics.
    DESCRIPTION)]
final readonly class TreeMergeTool
{
    public function __construct(private Chat $chat, private TreeMergeService $merge, private TreeContextService $trees,
        private ChatActivity $activity, private ChatEvents $events, private ChatViewFactory $views,
        private ClockInterface $clock, private RequestStack $requests)
    {
    }

    /** @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    public function __invoke(string $treeId, #[Schema(provider: TreeMergeToolSchema::class)] array $proposal): array
    {
        try {
            $conversation = $this->chat->active();

            return $this->chat->synchronized($conversation->contextKey, function () use ($treeId, $proposal): array {
                $conversation = $this->chat->active();
                if (($conversation->context['treeId'] ?? null) !== $treeId) {
                    throw new ApiException(404, 'TREE_NOT_FOUND');
                }
                $observed = [];
                foreach ($this->activity->read($conversation)['contexts'] as $context) {
                    foreach (is_array($context['people'] ?? null) ? $context['people'] : [] as $person) {
                        if (is_array($person) && is_string($person['id'] ?? null)) {
                            $observed[] = $person['id'];
                        }
                    }
                }
                foreach (is_array($proposal['people'] ?? null) ? $proposal['people'] : [] as $person) {
                    if (is_array($person) && null !== ($person['existingPersonId'] ?? null) && !in_array($person['existingPersonId'], $observed, true)) {
                        throw new ApiException(422, 'TREE_MERGE_UNOBSERVED_PERSON');
                    }
                }
                $this->activity->step($conversation, 'validating');
                $current = $conversation->tools['merge'] ?? null;
                $draft = $this->merge->prepare($this->trees->tree($treeId, true), $proposal, $this->chat->messages($conversation),
                    $current instanceof TreeMergeProposal ? $current : null, false, ($conversation->toolVersions['merge'] ?? 0) + 1);
                $conversation->tools['merge'] = $draft;
                $conversation->toolVersions['merge'] = $draft->version;
                $conversation->changed($this->clock->now());
                $this->chat->save($conversation);
                $session = $this->requests->getMainRequest()?->attributes->get(AuthSession::class);
                if ($session instanceof AuthSession) {
                    $this->events->begin($conversation);
                    $this->events->conversation($session, $this->views->create($conversation));
                }

                /** @var list<array{resolution: string}> $people */
                $people = $draft->proposal['people'];

                return ['newPeople'  => count(array_filter($people, static fn ($person) => 'new' === $person['resolution'])),
                    'existingPeople' => count(array_filter($people, static fn ($person) => 'existing' === $person['resolution'])),
                    'state'          => $draft->state, 'proposalVersion' => $draft->version];
            });
        } catch (ApiException $error) {
            return ['error' => ['code' => $error->getErrorCode(), 'fields' => $error->getFields(), 'details' => $error->getDetails()]];
        }
    }
}
