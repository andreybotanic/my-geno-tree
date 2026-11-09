<?php

namespace App\Application\Document;

use App\Entity\TreeDocument;
use Symfony\AI\Store\Document\Loader\MarkdownLoader;
use Symfony\AI\Store\Document\Loader\TextFileLoader;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\Transformer\TextSplitTransformer;
use Symfony\Component\Uid\Uuid;

final class DocumentChunks
{
    /** @return iterable<TextDocument> */
    public function load(TreeDocument $document, string $contents): iterable
    {
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
            $namespace = Uuid::fromString($document->getId()->toRfc4122());
            $index = 0;
            foreach ($chunks as $chunk) {
                if (!$chunk instanceof TextDocument) {
                    throw new \LogicException('Text loader returned a non-text document.');
                }
                yield new TextDocument(
                    Uuid::v5($namespace, $document->getGeneration() . ':' . $index)->toRfc4122(),
                    (string)$chunk->getContent(),
                    new Metadata(
                        [
                            'documentId'       => $document->getId()->toRfc4122(),
                            'treeId'           => $document->getTree()?->getId()->toRfc4122(),
                            'generation'       => $document->getGeneration(),
                            'chunkIndex'       => $index++,
                            Metadata::KEY_TEXT => $chunk->getContent(),
                        ]
                    ),
                );
            }
        } finally {
            unlink($path);
        }
    }
}
