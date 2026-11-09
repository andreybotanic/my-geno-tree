<?php

namespace App\MessageHandler\Document;

use App\Application\Document\DocumentFiles;
use App\Application\Document\DocumentVectors;
use App\Entity\TreeDocument;
use App\Message\Document\IndexDocumentMessage;
use App\Repository\TreeDocumentRepository;
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
                    $this->chunks($document),
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
            $index = 0;
            foreach ($chunks as $chunk) {
                if (!$chunk instanceof TextDocument) {
                    throw new \LogicException('Text loader returned a non-text document.');
                }
                yield new TextDocument(
                    Uuid::v5($document->getId(), $document->getGeneration() . ':' . $index)->toRfc4122(),
                    (string)$chunk->getContent(),
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
