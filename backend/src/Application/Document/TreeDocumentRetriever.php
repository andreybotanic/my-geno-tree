<?php

namespace App\Application\Document;

use App\Api\ApiException;
use App\Application\Tree\TreeContextService;
use App\Model\Conversation;
use App\Repository\TreeDocumentRepository;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\RetrieverInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/** The model never chooses its security scope or supplies vector-store options. */
final readonly class TreeDocumentRetriever implements RetrieverInterface
{
    public function __construct(
        #[Autowire(service: 'ai.retriever.documents')] private RetrieverInterface $retriever,
        private TreeDocumentRepository $documents,
        private TreeContextService $trees,
        private RequestStack $requests,
    ) {
    }

    public function retrieve(string $query, array $options = []): iterable
    {
        $conversation = $this->requests->getMainRequest()?->attributes->get(Conversation::class);
        $treeId = $conversation instanceof Conversation ? ($conversation->context['treeId'] ?? null) : null;
        if (!is_string($treeId) || '' === trim($query) || mb_strlen($query) > 4000) {
            throw new ApiException(422, 'DOCUMENT_SEARCH_INVALID');
        }
        $tree = $this->trees->tree($treeId);
        $active = $this->documents->searchable($tree);
        if ([] === $active) {
            return;
        }
        $generations = array_map(
            static fn (array $row): array => [
                'must' => [
                    ['key' => 'documentId', 'match' => ['value' => $row['id']->toRfc4122()]],
                    ['key' => 'generation', 'match' => ['value' => $row['generation']]],
                ],
            ],
            $active,
        );
        $instruction = "Instruct: Retrieve passages from family documents that answer the query.\nQuery: " . $query;
        $candidates = iterator_to_array(
            $this->retriever->retrieve(
                $instruction,
                [
                    'filter' => [
                        'must'   => [['key' => 'treeId', 'match' => ['value' => $treeId]]],
                        'should' => $generations,
                    ],
                    'limit' => 32,
                ],
            ),
            false,
        );
        $ids = [];
        foreach ($candidates as $candidate) {
            $id = $candidate->getMetadata()['documentId'] ?? null;
            if (is_string($id) && Uuid::isValid($id)) {
                $ids[] = $id;
            }
        }
        $allowed = [];
        foreach ($this->documents->ready($tree, array_values(array_unique($ids))) as $document) {
            $allowed[$document->getId()->toRfc4122()] = $document;
        }
        $count = 0;
        foreach ($candidates as $candidate) {
            $metadata = $candidate->getMetadata();
            $id = $metadata['documentId'] ?? null;
            $document = is_string($id) ? ($allowed[$id] ?? null) : null;
            if (null === $document || ($metadata['generation'] ?? null) !== $document->getGeneration()
                || ($metadata['treeId'] ?? null) !== $treeId) {
                continue;
            }
            $url = '/trees/' . $treeId . '/documents/' . $id . '/download';
            yield new VectorDocument(
                $candidate->getId(),
                $candidate->getVector(),
                new Metadata(
                    [
                        'documentId'         => $id,
                        'treeId'             => $treeId,
                        'personId'           => $document->getPerson()?->getId()->toRfc4122(),
                        'chunkIndex'         => is_int($metadata['chunkIndex'] ?? null) ? $metadata['chunkIndex'] : 0,
                        'originalSizeBytes'  => $document->getOriginalSizeBytes(),
                        Metadata::KEY_TITLE  => $document->getOriginalName(),
                        Metadata::KEY_SOURCE => $url,
                        Metadata::KEY_TEXT   => $metadata->getText() ?? '',
                    ]
                ),
                $candidate->getScore(),
            );
            if (6 === ++$count) {
                break;
            }
        }
    }
}
