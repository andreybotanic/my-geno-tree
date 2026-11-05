<?php

namespace App\Application\TreeMerge;

use App\Api\ApiException;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Entity\TreeMergeDraft;
use App\Repository\PersonRepository;
use App\Repository\TreeMergeDraftRepository;
use App\Security\TreeMergeAgentContext;
use App\Security\TreeMergeAgentVoter;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class TreeMergeDraftService
{
    public const int MAX_SOURCE_LENGTH = 10000;

    public function __construct(
        private TreeMergeDraftRepository $drafts,
        private PersonRepository $people,
        private TreeVersion $versions,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private RequestStack $requests,
        private AuthorizationCheckerInterface $authorization,
        private MergeActivity $activity,
        private TreeMergeChat $chat,
    ) {
    }

    public function active(string $treeId): TreeMergeDraft
    {
        $context = $this->requests->getCurrentRequest()?->attributes->get(TreeMergeAgentContext::class);
        if (!$context instanceof TreeMergeAgentContext) {
            throw new ApiException(401, 'TREE_MERGE_AGENT_REQUIRED');
        }
        if ($context->treeId !== $treeId) {
            throw new ApiException(404, 'TREE_NOT_FOUND');
        }
        $draft = $this->drafts->find($context->draftId) ?? throw new ApiException(404, 'TREE_MERGE_NOT_FOUND');
        if (!$this->authorization->isGranted(TreeMergeAgentVoter::USE, $draft)) {
            throw new ApiException(403, 'TREE_MERGE_AGENT_REQUIRED');
        }
        $this->entityManager->refresh($draft);
        if ($draft->attemptId !== $context->attemptId || $draft->version !== $context->version
            || 'processing' !== $draft->chatState) {
            throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT');
        }
        $this->activity->assertRunning($draft);

        return $draft;
    }

    public function current(FamilyTree $tree): ?TreeMergeDraft
    {
        return $this->drafts->current($tree);
    }

    public function get(FamilyTree $tree, string $id): TreeMergeDraft
    {
        $draft = Uuid::isValid($id) ? $this->drafts->findInTree($tree, $id) : null;

        return $draft ?? throw new ApiException(404, 'TREE_MERGE_NOT_FOUND', 'tree_merge_not_found');
    }

    /** @param array<string, mixed> $payload */
    public function create(FamilyTree $tree, array $payload): TreeMergeDraft
    {
        if (['referencePersonId'] !== array_keys($payload)) {
            $this->invalid('referencePersonId');
        }
        $id = $payload['referencePersonId'];
        if (null !== $id && (!is_string($id) || !Uuid::isValid($id))) {
            $this->invalid('referencePersonId');
        }
        $person = null === $id ? null : $this->people->findInTree($tree, $id);
        if (null !== $id && null === $person) {
            $this->invalid('referencePersonId');
        }

        return $this->entityManager->wrapInTransaction(function () use ($tree, $person): TreeMergeDraft {
            $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
            $draft = $this->drafts->current($tree);
            if (null === $draft) {
                $draft = new TreeMergeDraft($tree, $person, $this->versions->etag($tree), $this->clock->now());
                $this->entityManager->persist($draft);
            } elseif ($draft->referencePerson !== $person) {
                $this->chat->clear($draft);
                $draft->reset($this->clock->now(), $person);
            }

            return $draft;
        });
    }

    /** @param array<string, mixed> $payload */
    public function append(TreeMergeDraft $draft, array $payload): void
    {
        $expectedKeys = ['version', 'requestId', 'content'];
        if (count($payload) !== count($expectedKeys) || array_diff($expectedKeys, array_keys($payload))) {
            $this->invalid('message');
        }
        $content = $payload['content'];
        $requestId = $payload['requestId'];
        if (!is_string($content) || '' === trim($content) || mb_strlen($content) > self::MAX_SOURCE_LENGTH) {
            $this->invalid('content');
        }
        if (!is_string($requestId) || !preg_match('/^[a-zA-Z0-9-]{1,64}$/D', $requestId)) {
            $this->invalid('requestId');
        }
        if (!is_int($payload['version'])) {
            $this->invalid('version');
        }
        $this->entityManager->wrapInTransaction(function () use ($draft, $payload, $content, $requestId): void {
            $this->entityManager->lock($draft, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($draft);
            $messages = $this->chat->messages($draft);
            foreach ($messages as $message) {
                if ('user' === $message['role'] && $message['requestId'] === $requestId) {
                    if ($message['content'] !== $content) {
                        throw new ApiException(409, 'TREE_MERGE_REQUEST_CONFLICT', 'tree_merge_request_conflict');
                    }

                    return;
                }
            }
            $this->assertEditable($draft, $payload['version']);
            $length = mb_strlen($content);
            foreach ($messages as $message) {
                if ('user' === $message['role']) {
                    $length += mb_strlen($message['content']);
                }
            }
            if ($length > self::MAX_SOURCE_LENGTH) {
                $this->invalid('content', 'tree_merge_source_too_long');
            }
            if (count($messages) >= 200) {
                $this->invalid('content', 'tree_merge_message_limit');
            }
            $draft->messageAdded($this->clock->now());
            $this->chat->append($draft, $requestId, $content);
        });
    }

    public function cancel(TreeMergeDraft $draft, mixed $version): void
    {
        $this->entityManager->wrapInTransaction(function () use ($draft, $version): void {
            $this->entityManager->lock($draft, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($draft);
            if (null === $draft->proposal && [] === $this->chat->messages($draft) && 'idle' === $draft->chatState) {
                return;
            }
            if ($draft->version !== $version) {
                throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT');
            }
            $this->chat->clear($draft);
            $draft->reset($this->clock->now());
        });
    }

    private function assertEditable(TreeMergeDraft $draft, mixed $version): void
    {
        if ($draft->version !== $version) {
            throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT', 'tree_merge_version_conflict');
        }
    }

    private function invalid(string $field, string $code = 'tree_merge_invalid'): never
    {
        throw new ApiException(422, 'TREE_MERGE_INVALID', $code, [$field => [$code]]);
    }
}
