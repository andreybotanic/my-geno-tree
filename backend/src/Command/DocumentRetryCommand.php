<?php

namespace App\Command;

use App\Application\Document\DocumentService;
use App\Entity\TreeDocument;
use App\Repository\TreeDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:documents:retry', description: 'Republish indexing or cleanup for a document after a delivery gap.')]
final readonly class DocumentRetryCommand
{
    public function __construct(
        private TreeDocumentRepository $documents,
        private DocumentService $service,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(#[Argument] string $documentId, SymfonyStyle $io): int
    {
        $document = Uuid::isValid($documentId) ? $this->documents->find($documentId) : null;
        if (!$document instanceof TreeDocument) {
            $io->error('Document not found.');

            return Command::FAILURE;
        }
        if (!$document->isDeleted()) {
            $document->queued($this->clock->now());
            $this->entityManager->flush();
        }
        if (!$this->service->publish($document)) {
            $io->error('The broker is unavailable; the original and retry state have been retained.');

            return Command::FAILURE;
        }
        $io->success('Document work republished.');

        return Command::SUCCESS;
    }
}
