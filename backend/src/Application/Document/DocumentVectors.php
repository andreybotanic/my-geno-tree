<?php

namespace App\Application\Document;

use Symfony\AI\Store\ManagedStoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Lifecycle operations missing from the standard bridge: filtered deletion and payload indexes. */
final readonly class DocumentVectors
{
    public function __construct(
        #[Autowire(service: 'ai.store.qdrant.documents')] private ManagedStoreInterface $store,
        #[Autowire(service: 'document_vectors.client')] private HttpClientInterface $client,
        #[Autowire('%app.document_collection%')] private string $collection,
    ) {
    }

    public function setup(): void
    {
        $this->store->setup();
        foreach (['treeId', 'documentId'] as $field) {
            $this->client
                ->request(
                    'PUT',
                    'collections/' . $this->collection . '/index',
                    [
                        'query' => ['wait' => 'true'],
                        'json'  => ['field_name' => $field, 'field_schema' => 'keyword'],
                    ],
                )
                ->getContent();
        }
    }

    public function remove(string $documentId, ?int $generation = null): void
    {
        $must = [['key' => 'documentId', 'match' => ['value' => $documentId]]];
        if (null !== $generation) {
            $must[] = ['key' => 'generation', 'match' => ['value' => $generation]];
        }
        $response = $this->client->request(
            'POST',
            'collections/' . $this->collection . '/points/delete',
            [
                'query' => ['wait' => 'true'],
                'json'  => ['filter' => ['must' => $must]],
            ],
        );
        if (404 !== $response->getStatusCode()) {
            $response->getContent();
        }
    }
}
