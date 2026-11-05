<?php

namespace App\Application\TreeChat;

use App\Api\ApiException;
use App\Api\ChatViewFactory;
use App\Application\Chat\Chat;
use App\Application\Chat\ChatEvents;
use App\Application\TreeMerge\TreeMergeService;
use App\Entity\AuthSession;
use App\Entity\FamilyTree;
use App\Model\Conversation;
use App\Model\TreeMergeProposal;
use App\Repository\PersonRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/** Binds the reusable chat to the tree profile and its pending merge tool. */
final readonly class TreeAssistant
{
    public function __construct(private Chat $chat, private PersonRepository $people, private TreeMergeService $merge,
        private ClockInterface $clock, private ChatEvents $events, private ChatViewFactory $views, private RequestStack $requests)
    {
    }

    public function current(FamilyTree $tree): ?Conversation
    {
        return $this->chat->current('tree:' . $tree->getId()->toRfc4122());
    }

    /** @param array<string, mixed> $payload */
    public function create(FamilyTree $tree, array $payload): Conversation
    {
        if (['referencePersonId'] !== array_keys($payload) || (null !== $payload['referencePersonId']
            && (!is_string($payload['referencePersonId']) || !Uuid::isValid($payload['referencePersonId'])
                || null === $this->people->findInTree($tree, $payload['referencePersonId'])))) {
            throw new ApiException(422, 'CHAT_INVALID');
        }

        return $this->chat->create('tree:' . $tree->getId()->toRfc4122(), 'tree',
            ['treeId' => $tree->getId()->toRfc4122(), 'referencePersonId' => $payload['referencePersonId']]);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>|Conversation
     */
    public function proposal(FamilyTree $tree, string $id, string $action, array $payload, ?string $ifMatch = null): array|Conversation
    {
        $keys = 'revise' === $action ? ['version', 'proposal'] : ['version'];
        if (count($keys) !== count($payload) || array_diff($keys, array_keys($payload)) || !is_int($payload['version'] ?? null)) {
            throw new ApiException(422, 'TREE_MERGE_INVALID');
        }
        $contextKey = 'tree:' . $tree->getId()->toRfc4122();

        return $this->chat->synchronized($contextKey, function () use ($tree, $id, $action, $payload, $ifMatch): array|Conversation {
            $conversation = $this->current($tree) ?? throw new ApiException(404, 'CHAT_NOT_FOUND');
            $proposal = $conversation->tools['merge'] ?? null;
            if (!$proposal instanceof TreeMergeProposal || $proposal->id->toRfc4122() !== $id) {
                throw new ApiException(404, 'TREE_MERGE_NOT_FOUND');
            }
            if ($proposal->version !== ($payload['version'] ?? null)) {
                throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT');
            }
            if ('apply' === $action && 'processing' === $conversation->status) {
                throw new ApiException(409, 'TREE_MERGE_NOT_READY');
            }
            if ('revise' === $action || 'revalidate' === $action) {
                $next = 'revise' === $action
                    ? $this->merge->prepare($tree, $payload['proposal'] ?? null, $this->chat->messages($conversation), $proposal, true, $proposal->version + 1)
                    : $this->merge->revalidate($tree, $proposal);
                $conversation->tools['merge'] = $next;
                $conversation->toolVersions['merge'] = $next->version;
                $conversation->invalidateAttempt($this->clock->now());
                $this->chat->save($conversation, true);

                return $conversation;
            }
            // Consume before the tree transaction: retries cannot apply this proposal twice, even without a receipt.
            unset($conversation->tools['merge']);
            $conversation->toolVersions['merge'] = $proposal->version + 1;
            $conversation->invalidateAttempt($this->clock->now());
            $this->chat->save($conversation, true);
            if ('discard' === $action) {
                return $conversation;
            }
            try {
                $result = $this->merge->apply($tree, $proposal, $ifMatch);
            } catch (ApiException $error) {
                $restored = 412 === $error->getStatus() ? $this->merge->revalidate($tree, $proposal) : $proposal;
                $conversation->tools['merge'] = $restored;
                $conversation->toolVersions['merge'] = $restored->version;
                $conversation->changed($this->clock->now());
                $this->chat->save($conversation, true);
                throw $error;
            }
            $session = $this->requests->getMainRequest()?->attributes->get(AuthSession::class);
            if ($session instanceof AuthSession) {
                $this->events->conversation($session, $this->views->create($conversation));
                $this->events->tool($session, $conversation, 'merge', 'applied', $proposal->id->toRfc4122(), $result);
            }

            return ['conversation' => $this->views->create($conversation), 'operationId' => $proposal->id->toRfc4122(), 'result' => $result];
        });
    }
}
