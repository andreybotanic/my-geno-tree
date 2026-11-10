<?php

namespace App\MessageHandler\Document;

use App\Application\Document\DocumentFiles;
use App\Application\Document\DocumentVectors;
use App\Entity\TreeDocument;
use App\Message\Document\IndexDocumentMessage;
use App\Repository\TreeDocumentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Store\Document\Loader\MarkdownLoader;
use Symfony\AI\Store\Document\Loader\TextFileLoader;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\Transformer\TextSplitTransformer;
use Symfony\AI\Store\IndexerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class IndexDocumentMessageHandler
{
    public function __construct(
        private TreeDocumentRepository $documents,
        private EntityManagerInterface $entityManager,
        private DocumentFiles $files,
        private DocumentVectors $vectors,
        #[Autowire(service: 'ai.indexer.documents')] private IndexerInterface $indexer,
        #[Autowire(service: 'app.document_locks')] private LockFactory $locks,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(IndexDocumentMessage $message): void
    {
        $lock = $this->locks->createLock('document-' . $message->documentId);
        $lock->acquire(true);
        try {
            $document = $this->transition($message, 'processing');
            if (null === $document) {
                return;
            }
            try {
                $this->vectors->setup();
                $this->vectors->remove($message->documentId);
                $this->indexer->index(
                    $this->chunks($document),
                    ['chunk_size' => 16],
                );
                if (null === $this->transition($message, 'ready')) {
                    $this->vectors->remove($message->documentId, $message->generation);
                }
            } catch (\Throwable $error) {
                $this->transition($message, 'failed', 'DOCUMENT_INDEX_FAILED');
                throw $error;
            }
        } finally {
            $lock->release();
        }
    }

    private function transition(IndexDocumentMessage $message, string $status, ?string $error = null): ?TreeDocument
    {
        return $this->entityManager->wrapInTransaction(function () use ($message, $status, $error): ?TreeDocument {
            $document = $this->documents->find($message->documentId, LockMode::PESSIMISTIC_WRITE);
            if (null === $document || $document->isDeleted() || $document->getGeneration() !== $message->generation
                || 'ready' === $document->getStatus()) {
                return null;
            }
            $document->transition($status, $this->clock->now(), $error);

            return $document;
        });
    }

    /** @return iterable<TextDocument> */
    private function chunks(TreeDocument $document): iterable
    {
        $contents = $this->files->contents($document->getStoragePath());
        $text = str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
        $path = tempnam(sys_get_temp_dir(), 'genealogy-document-');
        if (false === $path) {
            throw new \RuntimeException('Unable to create document temporary file.');
        }
        try {
            if (false === file_put_contents($path, $text)) {
                throw new \RuntimeException('Unable to write document temporary file.');
            }
            $loader = 'text/markdown' === $document->getMimeType() ? new MarkdownLoader() : new TextFileLoader();
            $chunks = (new TextSplitTransformer(1000, 200))->transform($loader->load($path));
            $context = [];
            if (null !== $document->getTitle()) {
                $context[] = 'Title: ' . $document->getTitle();
            }
            if (null !== $document->getDescription()) {
                $context[] = 'Description: ' . $document->getDescription();
            }
            $prefix = [] === $context ? '' : implode("\n", $context) . "\n\n";
            $index = 0;
            foreach ($chunks as $chunk) {
                if (!$chunk instanceof TextDocument) {
                    throw new \LogicException('Text loader returned a non-text document.');
                }
                yield new TextDocument(
                    Uuid::v5($document->getId(), $document->getGeneration() . ':' . $index)->toRfc4122(),
                    $prefix . $chunk->getContent(),
                    new Metadata(
                        [
                            'documentId'       => $document->getId()->toRfc4122(),
                            'treeId'           => $document->getTree()?->getId()->toRfc4122(),
                            'generation'       => $document->getGeneration(),
                            'chunkIndex'       => $index++,
                            Metadata::KEY_TEXT => $chunk->getContent(),
                        ],
                    ),
                );
            }
        } finally {
            unlink($path);
        }
    }
}
