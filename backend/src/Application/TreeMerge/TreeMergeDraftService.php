<?php

namespace App\Application\TreeMerge;

use App\Api\ApiException;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Entity\TreeMergeDraft;
use App\Repository\PersonRepository;
use App\Repository\TreeMergeDraftRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
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
    ) {
    }

    /** @return list<TreeMergeDraft> */
    public function recent(FamilyTree $tree): array
    {
        return array_values(array_filter($this->drafts->recentForTree($tree), static fn (TreeMergeDraft $draft) => $draft->chatSessionAvailable));
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
        $draft = new TreeMergeDraft($tree, $person, $this->versions->etag($tree), $this->clock->now());
        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        return $draft;
    }

    /** @param array<string, mixed> $payload */
    public function append(TreeMergeDraft $draft, array $payload, bool $source = true): void
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
        $this->entityManager->wrapInTransaction(function () use ($draft, $payload, $content, $requestId, $source): void {
            $this->entityManager->lock($draft, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($draft);
            foreach ($draft->messages as $message) {
                if ('user' === $message['role'] && $message['requestId'] === $requestId) {
                    if ($message['content'] !== $content) {
                        throw new ApiException(409, 'TREE_MERGE_REQUEST_CONFLICT', 'tree_merge_request_conflict');
                    }

                    return;
                }
            }
            $this->assertEditable($draft, $payload['version']);
            $length = $draft->sourceLength + mb_strlen($content);
            foreach ($draft->sourceMessages() as $message) {
                if (!($message['source'] ?? true)) {
                    $length += mb_strlen($message['content']);
                }
            }
            if ($length > self::MAX_SOURCE_LENGTH) {
                $this->invalid('content', 'tree_merge_source_too_long');
            }
            if (count($draft->messages) >= 200) {
                $this->invalid('content', 'tree_merge_message_limit');
            }
            $draft->addMessage($requestId, $content, $this->clock->now(), $source);
        });
    }

    public function cancel(TreeMergeDraft $draft, mixed $version): void
    {
        $this->entityManager->wrapInTransaction(function () use ($draft, $version): void {
            $this->entityManager->lock($draft, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($draft);
            if ('cancelled' === $draft->state) {
                return;
            }
            $this->assertEditable($draft, $version);
            $draft->cancel($this->clock->now());
        });
    }

    private function assertEditable(TreeMergeDraft $draft, mixed $version): void
    {
        if ($draft->version !== $version) {
            throw new ApiException(409, 'TREE_MERGE_VERSION_CONFLICT', 'tree_merge_version_conflict');
        }
        if (in_array($draft->state, ['cancelled', 'applied'], true)) {
            throw new ApiException(409, 'TREE_MERGE_CLOSED', 'tree_merge_closed');
        }
    }

    private function invalid(string $field, string $code = 'tree_merge_invalid'): never
    {
        throw new ApiException(422, 'TREE_MERGE_INVALID', $code, [$field => [$code]]);
    }
}
