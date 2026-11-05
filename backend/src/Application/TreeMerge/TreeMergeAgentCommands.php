<?php

namespace App\Application\TreeMerge;

use App\Api\ApiException;
use App\Api\TreeMergeDraftViewFactory;
use App\Application\Tree\TreeVersion;
use App\Entity\AuthSession;
use App\Entity\TreeMergeDraft;
use App\Repository\TreeMergeDraftRepository;
use App\Security\TreeMergeAgentContext;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/** The agent's only write boundary: explicit MCP arguments, never interpreted user or model prose. */
final readonly class TreeMergeAgentCommands
{
    public function __construct(
        private TreeMergeDraftRepository $drafts,
        private EntityManagerInterface $entityManager,
        private TreeContextService $context,
        private TreeMergeProposalValidator $validator,
        private TreeVersion $versions,
        private MergeActivity $activity,
        private RequestStack $requests,
        private ClockInterface $clock,
        #[Target('cache.merge_session')] private CacheItemPoolInterface $cache,
        private TreeMergeEvents $events,
        private TreeMergeDraftViewFactory $views,
    ) {
    }

    /** @param array<string, mixed> $proposal
     * @return array{proposalId: string, newPeople: int, existingPeople: int, state: string}
     */
    public function prepare(string $treeId, array $proposal): array
    {
        $draft = $this->active($treeId);
        $this->entityManager->refresh($draft->tree);
        $etag = $this->versions->etag($draft->tree);
        $this->activity->step($draft, 'validating');
        $observed = [];
        foreach ($this->activity->read($draft)['contexts'] as $context) {
            foreach (is_array($context['people'] ?? null) ? $context['people'] : [] as $person) {
                if (is_array($person) && is_string($person['id'] ?? null)) {
                    $observed[] = $person['id'];
                }
            }
        }
        foreach (is_array($proposal['people'] ?? null) ? $proposal['people'] : [] as $person) {
            if (is_array($person) && null !== ($person['existingPersonId'] ?? null)
                && !in_array($person['existingPersonId'], $observed, true)) {
                throw new ApiException(422, 'TREE_MERGE_UNOBSERVED_PERSON', details: ['instruction' => 'Read the existing person with get_person_context before referencing it.']);
            }
        }
        $validated = $this->validator->validate($draft, $proposal);
        $state = match (true) {
            [] !== $validated->questions => 'questions',
            $validated->hasChanges       => 'ready',
            default                      => 'no_changes',
        };
        $proposalId = Uuid::v7()->toRfc4122();
        $item = $this->cache->getItem('proposal_' . $proposalId);
        $this->cache->save($item->set([
            'draftId' => $draft->id->toRfc4122(), 'attemptId' => $draft->attemptId, 'version' => $draft->version,
            'etag'    => $etag, 'proposal' => $validated->proposal, 'questions' => $validated->questions, 'state' => $state,
        ])->expiresAfter(300));
        /** @var list<array{resolution: string}> $people */
        $people = $validated->proposal['people'];

        return ['proposalId' => $proposalId, 'newPeople' => count(array_filter($people, static fn ($person) => 'new' === $person['resolution'])),
            'existingPeople' => count(array_filter($people, static fn ($person) => 'existing' === $person['resolution'])), 'state' => $state];
    }

    /** @return array{state: string, version: int} */
    public function reply(string $treeId, string $reply, ?string $proposalId = null, bool $needsClarification = false): array
    {
        // Reject invalid output; never truncate, translate, combine, or rewrite the agent's sentence.
        if ('' === trim($reply) || mb_strlen($reply) > 350 || preg_match('/[\r\n]/u', $reply)
            || preg_match_all('/[.!?]+(?=\s|$)/u', $reply) > 1 || (null !== $proposalId && $needsClarification)) {
            throw new ApiException(422, 'TREE_MERGE_REPLY_INVALID', details: ['instruction' => 'Send one short sentence of at most 350 characters; use either proposalId or needsClarification.']);
        }
        $draft = $this->active($treeId);
        $staged = null;
        if (null !== $proposalId) {
            if (!Uuid::isValid($proposalId)) {
                throw new ApiException(422, 'TREE_MERGE_PROPOSAL_UNAVAILABLE');
            }
            /** @var array{draftId: string, attemptId: string, version: int, etag: string, proposal: array<string, mixed>, questions: list<string>, state: string}|null $staged */
            $staged = $this->cache->getItem('proposal_' . $proposalId)->get();
            if (null === $staged || $staged['draftId'] !== $draft->id->toRfc4122() || $staged['attemptId'] !== $draft->attemptId || $staged['version'] !== $draft->version) {
                throw new ApiException(409, 'TREE_MERGE_PROPOSAL_UNAVAILABLE');
            }
        }
        $this->entityManager->wrapInTransaction(function () use ($draft, $treeId, $reply, $needsClarification, $staged): void {
            $this->entityManager->lock($draft->tree, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($draft->tree);
            $this->entityManager->lock($draft, LockMode::PESSIMISTIC_WRITE);
            $this->active($treeId);
            $requestId = array_find(array_reverse($draft->messages), static fn ($message) => 'user' === $message['role'])['requestId']
                ?? throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT');
            if (null !== $staged) {
                if ($staged['etag'] !== $this->versions->etag($draft->tree)) {
                    throw new ApiException(409, 'TREE_MERGE_TREE_CONFLICT');
                }
                $draft->markSource($requestId);
                $draft->propose($staged['proposal'], $staged['questions'], $staged['etag'], $staged['state'], false, $this->clock->now());
            }
            if ($needsClarification) {
                $draft->ask($requestId, $reply, $this->clock->now());
            } else {
                $draft->answer($requestId, $reply, $this->clock->now());
            }
        });
        $session = $this->requests->getMainRequest()?->attributes->get(AuthSession::class);
        if ($session instanceof AuthSession) {
            $this->events->draft($session, $this->views->create($draft));
        }

        return ['state' => $draft->state, 'version' => $draft->version];
    }

    private function active(string $treeId): TreeMergeDraft
    {
        $context = $this->requests->getCurrentRequest()?->attributes->get(TreeMergeAgentContext::class);
        if (!$context instanceof TreeMergeAgentContext || $context->treeId !== $treeId) {
            throw new ApiException(403, 'TREE_MERGE_AGENT_REQUIRED');
        }
        $this->context->tree($treeId, true);
        $draft = $this->drafts->find($context->draftId) ?? throw new ApiException(404, 'TREE_MERGE_NOT_FOUND');
        $this->entityManager->refresh($draft);
        if ($draft->attemptId !== $context->attemptId || $draft->version !== $context->version
            || 'processing' !== $draft->state || !$draft->chatSessionAvailable) {
            throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT');
        }
        $this->activity->assertRunning($draft);

        return $draft;
    }
}
