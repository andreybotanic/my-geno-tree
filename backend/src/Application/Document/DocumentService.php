<?php

namespace App\Application\Document;

use App\Api\ApiException;
use App\Api\DocumentViewFactory;
use App\Application\Media\MediaStorage;
use App\Application\Person\PersonLookup;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Entity\MediaVariant;
use App\Entity\MediaVariantType;
use App\Entity\TreeDocument;
use App\Repository\TreeDocumentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DocumentService
{
    public function __construct(
        private DocumentFiles $files,
        private MediaStorage $storage,
        private TreeDocumentRepository $documents,
        private PersonLookup $people,
        private TreeVersion $versions,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private MessageBusInterface $bus,
        private DocumentViewFactory $views,
        private LoggerInterface $logger,
    ) {
    }

    /** @return array<string, mixed> */
    public function listing(
        FamilyTree $tree,
        ?string $personId,
        string $scope,
        ?string $cursor,
        int $limit,
    ): array {
        if ($limit < 1 || $limit > 100 || (null !== $cursor && !Uuid::isValid($cursor))
            || !in_array($scope, ['all', 'tree', 'person'], true) || ('person' === $scope && null === $personId)) {
            throw new ApiException(422, 'DOCUMENT_QUERY_INVALID');
        }
        $person = null === $personId ? null : $this->people->require($tree, $personId);
        $rows = $this->documents->page($tree, $person, $scope, $cursor, $limit + 1);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return [
            'items'      => array_map($this->views->create(...), $rows),
            'total'      => $this->documents->countVisible($tree, $person, $scope),
            'nextCursor' => $more ? array_last($rows)?->getId()->toRfc4122() : null,
            'etag'       => $this->versions->etag($tree),
        ];
    }

    public function upload(
        FamilyTree $tree,
        ?string $personId,
        ?UploadedFile $file,
        ?string $ifMatch,
    ): TreeDocument {
        $input = $this->files->validate($file);
        $now = $this->clock->now();
        $media = $this->files->store($tree, $input['contents'], $input['mime'], $now);
        try {
            $document = $this->entityManager->wrapInTransaction(
                function () use (
                    $tree,
                    $personId,
                    $input,
                    $media,
                    $now,
                    $ifMatch,
                ): TreeDocument {
                    $this->lock($tree, $ifMatch);
                    $person = null === $personId ? null : $this->people->require($tree, $personId);
                    $document = new TreeDocument(
                        $tree,
                        $person,
                        $media,
                        $input['name'],
                        $input['mime'],
                        $input['bytes'],
                        $input['checksum'],
                        $now,
                    );
                    $this->entityManager->persist($document);
                    $tree->touch($now);

                    return $document;
                }
            );
        } catch (\Throwable $error) {
            $this->storage->remove($media);
            throw $error;
        }
        $this->publish($document);

        return $document;
    }

    public function require(FamilyTree $tree, string $id): TreeDocument
    {
        $document = Uuid::isValid($id) ? $this->documents->inTree($tree, $id) : null;

        return $document ?? throw new ApiException(404, 'DOCUMENT_NOT_FOUND');
    }

    public function original(TreeDocument $document): MediaVariant
    {
        return $document->getMedia()->variant(MediaVariantType::ORIGINAL)
            ?? throw new \LogicException('Document original is missing.');
    }

    public function contents(TreeDocument $document): string
    {
        return $this->storage->contents($this->original($document));
    }

    public function retry(FamilyTree $tree, string $id): TreeDocument
    {
        $document = $this->entityManager->wrapInTransaction(
            function () use ($tree, $id): TreeDocument {
                $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
                $document = $this->require($tree, $id);
                $this->entityManager->lock($document, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($document);
                $stale = in_array($document->getStatus(), ['queued', 'processing'], true)
                    && $document->getUpdatedAt() <= $this->clock->now()->modify('-5 minutes');
                if ('failed' !== $document->getStatus() && !$stale) {
                    throw new ApiException(409, 'DOCUMENT_NOT_RETRYABLE');
                }
                $document->queued($this->clock->now());

                return $document;
            }
        );
        $this->publish($document);

        return $document;
    }

    public function delete(FamilyTree $tree, string $id, ?string $ifMatch): void
    {
        $document = $this->entityManager->wrapInTransaction(
            function () use ($tree, $id, $ifMatch): TreeDocument {
                $this->lock($tree, $ifMatch);
                $document = $this->require($tree, $id);
                $document->delete($this->clock->now());
                $tree->touch($this->clock->now());

                return $document;
            }
        );
        $this->publish($document);
    }

    /** Called inside the tree deletion transaction.
     * @return list<TreeDocument>
     */
    public function detachTree(FamilyTree $tree): array
    {
        $documents = $this->documents->forTree($tree);
        foreach ($documents as $document) {
            $document->delete($this->clock->now(), true);
        }

        return $documents;
    }

    /** @param list<TreeDocument> $documents */
    public function publishAll(array $documents): void
    {
        foreach ($documents as $document) {
            $this->publish($document);
        }
    }

    public function publish(TreeDocument $document): bool
    {
        try {
            $this->bus->dispatch(
                $document->isDeleted()
                                                ? new RemoveDocument($document->getId()->toRfc4122())
                                                : new IndexDocument($document->getId()->toRfc4122(), $document->getGeneration())
            );

            return true;
        } catch (\Throwable $error) {
            $document->transition('failed', $this->clock->now(), 'DOCUMENT_QUEUE_UNAVAILABLE');
            $this->entityManager->flush();
            $this->logger->error('Document queue unavailable.', ['exception' => $error]);

            return false;
        }
    }

    private function lock(FamilyTree $tree, ?string $ifMatch): void
    {
        $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->refresh($tree);
        $this->versions->assertMatches($tree, $ifMatch);
    }
}
