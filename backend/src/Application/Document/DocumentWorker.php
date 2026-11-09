<?php

namespace App\Application\Document;

use App\Application\Media\MediaStorage;
use App\Entity\TreeDocument;
use App\Repository\TreeDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Store\IndexerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class DocumentWorker
{
    public function __construct(
        private TreeDocumentRepository $documents,
        private EntityManagerInterface $entityManager,
        private DocumentService $service,
        private MediaStorage $storage,
        private DocumentChunks $chunks,
        private DocumentVectors $vectors,
        #[Autowire(service: 'ai.indexer.documents')] private IndexerInterface $indexer,
        #[Autowire(service: 'app.document_locks')] private LockFactory $locks,
        private ClockInterface $clock,
    ) {
    }

    #[AsMessageHandler]
    public function index(IndexDocument $message): void
    {
        $lock = $this->locks->createLock('document-' . $message->documentId);
        $lock->acquire(true);
        try {
            $document = $this->documents->find($message->documentId);
            if ($document instanceof TreeDocument) {
                $this->entityManager->refresh($document);
            }
            if (!$document instanceof TreeDocument || $document->isDeleted()
                || $message->generation !== $document->getGeneration() || 'ready' === $document->getStatus()) {
                return;
            }
            $document->transition('processing', $this->clock->now());
            $this->entityManager->flush();
            try {
                $this->vectors->setup();
                $this->vectors->remove($message->documentId);
                $this->indexer->index(
                    $this->chunks->load($document, $this->service->contents($document)),
                    ['chunk_size' => 16],
                );
                $this->entityManager->refresh($document);
                if ($document->isDeleted() || $message->generation !== $document->getGeneration()) {
                    $this->vectors->remove($message->documentId, $message->generation);

                    return;
                }
                $document->transition('ready', $this->clock->now());
                $this->entityManager->flush();
            } catch (\Throwable $error) {
                $this->entityManager->refresh($document);
                if (!$document->isDeleted() && $message->generation === $document->getGeneration()) {
                    $document->transition('failed', $this->clock->now(), 'DOCUMENT_INDEX_FAILED');
                    $this->entityManager->flush();
                }
                throw $error;
            }
        } finally {
            $lock->release();
        }
    }

    #[AsMessageHandler]
    public function remove(RemoveDocument $message): void
    {
        $lock = $this->locks->createLock('document-' . $message->documentId);
        $lock->acquire(true);
        try {
            $document = $this->documents->find($message->documentId);
            if ($document instanceof TreeDocument) {
                $this->entityManager->refresh($document);
            }
            if (!$document instanceof TreeDocument || !$document->isDeleted()) {
                return;
            }
            $this->vectors->remove($message->documentId);
            $media = $document->getMedia();
            $this->storage->remove($media);
            $this->entityManager->wrapInTransaction(
                function () use ($document, $media): void {
                    $this->entityManager->remove($document);
                    $this->entityManager->remove($media);
                }
            );
        } finally {
            $lock->release();
        }
    }
}
