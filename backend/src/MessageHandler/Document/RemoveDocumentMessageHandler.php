<?php

namespace App\MessageHandler\Document;

use App\Application\Document\DocumentFiles;
use App\Application\Document\DocumentVectors;
use App\Entity\TreeDocument;
use App\Message\Document\RemoveDocumentMessage;
use App\Repository\TreeDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RemoveDocumentMessageHandler
{
    public function __construct(
        private TreeDocumentRepository $documents,
        private EntityManagerInterface $entityManager,
        private DocumentFiles $files,
        private DocumentVectors $vectors,
        #[Autowire(service: 'app.document_locks')] private LockFactory $locks,
    ) {
    }

    public function __invoke(RemoveDocumentMessage $message): void
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
            $this->files->remove($document->getStoragePath());
            $this->entityManager->remove($document);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }
    }
}
