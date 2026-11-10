<?php

namespace App\Tests\Integration\Application\Document;

use App\Api\ApiException;
use App\Application\Document\DocumentFiles;
use App\Application\Document\DocumentService;
use App\Application\Document\DocumentVectors;
use App\Application\Document\TreeDocumentRetriever;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Entity\TreeDocument;
use App\Entity\User;
use App\Message\Document\IndexDocumentMessage;
use App\Message\Document\RemoveDocumentMessage;
use App\MessageHandler\Document\IndexDocumentMessageHandler;
use App\MessageHandler\Document\RemoveDocumentMessageHandler;
use App\Model\Conversation;
use App\Repository\TreeDocumentRepository;
use App\Tests\Api\ApiWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\IndexerInterface;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

final class DocumentPipelineTest extends ApiWebTestCase
{
    private FamilyTree $tree;
    private EntityManagerInterface $em;
    /** @var list<string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $user = new User('document_pipeline', 'pipeline@example.test', 'hash', 'en', $now);
        $this->tree = new FamilyTree($user, 'Pipeline tree', $now);
        $this->em->persist($user);
        $this->em->persist($this->tree);
        $this->em->flush();
        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($user, 'main', ['ROLE_USER']),
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->ids as $id) {
            static::getContainer()->get(DocumentVectors::class)->remove($id);
        }
        parent::tearDown();
    }

    public function testRealQdrantIndexingRedeliveryGenerationsSourcesAndCleanup(): void
    {
        $document = $this->document(
            "\xEF\xBB\xBF# Family\r\n" . str_repeat('An archival railway letter. ', 100),
            'story.md',
        );
        $worker = static::getContainer()->get(IndexDocumentMessageHandler::class);
        $message = new IndexDocumentMessage($document->getId()->toRfc4122(), 1);
        $etag = static::getContainer()->get(TreeVersion::class)->etag($this->tree);
        $worker->__invoke($message);
        self::assertSame('ready', $document->getStatus());
        self::assertSame($etag, static::getContainer()->get(TreeVersion::class)->etag($this->tree));
        $first = $this->points($document);
        self::assertGreaterThan(1, count($first));
        $worker->__invoke($message);
        self::assertCount(count($first), $this->points($document));
        self::assertSame([], $first[0]->getMetadata()->getSource() ? ['leaked_path'] : []);

        $requests = static::getContainer()->get(RequestStack::class);
        $request = new Request();
        $request->attributes->set(
            Conversation::class,
            new Conversation(
                'tree:' . $this->tree->getId(),
                'tree',
                ['treeId' => $this->tree->getId()->toRfc4122()],
                Uuid::v7(),
                $this->tree->getOwner()->getId(),
                new \DateTimeImmutable(),
            )
        );
        $requests->push($request);
        try {
            $retriever = static::getContainer()->get(TreeDocumentRetriever::class);
            $found = iterator_to_array($retriever->retrieve('Who worked on railway?', ['filter' => []]), false);
            self::assertNotEmpty($found);
            self::assertLessThanOrEqual(6, count($found));
            self::assertSame('story.md', $found[0]->getMetadata()->getTitle());
            self::assertStringStartsWith('/trees/', $found[0]->getMetadata()->getSource() ?? '');
            self::assertStringNotContainsString(
                sys_get_temp_dir(),
                json_encode($found[0]->getMetadata(), JSON_THROW_ON_ERROR),
            );

            $other = $this->document('An archival railway letter.');
            $worker->__invoke(new IndexDocumentMessage($other->getId()->toRfc4122(), 1));
            $document->changeMetadata('Irkutsk', 'telegraph', new \DateTimeImmutable());
            $this->em->flush();
            $visible = iterator_to_array($retriever->retrieve('railway'), false);
            self::assertCount(1, $visible);
            self::assertSame($other->getId()->toRfc4122(), $visible[0]->getMetadata()['documentId']);
            $worker->__invoke($message);
            self::assertSame('queued', $document->getStatus());
            $worker->__invoke(new IndexDocumentMessage($document->getId()->toRfc4122(), 2));
            self::assertSame('ready', $document->getStatus());
            foreach (['Irkutsk', 'telegraph'] as $query) {
                $matches = iterator_to_array($retriever->retrieve($query), false);
                self::assertSame($document->getId()->toRfc4122(), $matches[0]->getMetadata()['documentId']);
                self::assertStringNotContainsString($query, $matches[0]->getMetadata()->getText() ?? '');
            }
            $other->delete(new \DateTimeImmutable());
            $this->em->flush();
            $second = $this->points($document);
            self::assertCount(count($first), $second);
            self::assertNotSame($first[0]->getId(), $second[0]->getId());

            $document->delete(new \DateTimeImmutable());
            $this->em->flush();
            self::assertSame([], iterator_to_array($retriever->retrieve('railway'), false));
            $id = $document->getId()->toRfc4122();
            static::getContainer()->get(RemoveDocumentMessageHandler::class)->__invoke(new RemoveDocumentMessage($id));
            static::getContainer()->get(RemoveDocumentMessageHandler::class)->__invoke(new RemoveDocumentMessage($id));
            $worker->__invoke(new IndexDocumentMessage($id, 2));
            self::assertNull($this->em->find(TreeDocument::class, $id));
            self::assertSame([], $this->points($document));
        } finally {
            $requests->pop();
        }
    }

    public function testFailureCanBeRedeliveredAndDeletionDuringIndexingNeverBecomesReady(): void
    {
        $document = $this->document('A short original letter.');
        $native = static::getContainer()->get('ai.indexer.documents');
        self::assertInstanceOf(IndexerInterface::class, $native);
        $attempts = 0;
        $indexer = $this->createStub(IndexerInterface::class);
        $indexer->method('index')->willReturnCallback(
            static function (string|iterable|object $input, array $options) use (&$attempts, $native): void {
                if (1 === ++$attempts) {
                    throw new \RuntimeException('Temporary embedding failure');
                }
                self::assertTrue(is_iterable($input));
                $native->index($input, $options);
            },
        );
        $worker = $this->worker($indexer);
        $message = new IndexDocumentMessage($document->getId()->toRfc4122(), 1);
        try {
            $worker->__invoke($message);
            self::fail('Expected indexing failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('Temporary embedding failure', $error->getMessage());
        }
        self::assertSame('failed', $document->getStatus());
        self::assertSame('DOCUMENT_INDEX_FAILED', $document->getErrorCode());
        $worker->__invoke($message);
        self::assertSame('ready', $document->getStatus());

        $document->queued(new \DateTimeImmutable());
        $this->em->flush();
        $indexer = $this->createStub(IndexerInterface::class);
        $indexer->method('index')->willReturnCallback(
            function () use ($document): void {
                $document->delete(new \DateTimeImmutable());
                $this->em->flush();
            }
        );
        $this->worker($indexer)->__invoke(new IndexDocumentMessage($document->getId()->toRfc4122(), 2));
        self::assertTrue($document->isDeleted());
        self::assertNotSame('ready', $document->getStatus());
        self::assertSame([], $this->points($document));
        static::getContainer()->get(RemoveDocumentMessageHandler::class)->__invoke(
            new RemoveDocumentMessage($document->getId()->toRfc4122()),
        );
    }

    public function testEditingDuringIndexingDiscardsTheObsoleteGeneration(): void
    {
        $document = $this->document('The original text.');
        $id = $document->getId()->toRfc4122();
        $native = static::getContainer()->get('ai.indexer.documents');
        self::assertInstanceOf(IndexerInterface::class, $native);
        $indexer = $this->createStub(IndexerInterface::class);
        $indexer->method('index')->willReturnCallback(
            function (iterable $input, array $options) use ($document, $native): void {
                $native->index($input, $options);
                $document->changeMetadata('Irkutsk', 'telegraph', new \DateTimeImmutable());
                $this->em->flush();
            }
        );
        $this->worker($indexer)->__invoke(new IndexDocumentMessage($id, 1));
        self::assertSame('queued', $document->getStatus());
        self::assertSame([], $this->points($document));
        $worker = static::getContainer()->get(IndexDocumentMessageHandler::class);
        $worker->__invoke(new IndexDocumentMessage($id, 2));
        $worker->__invoke(new IndexDocumentMessage($id, 1));
        self::assertSame('ready', $document->getStatus());
        $points = $this->points($document);
        self::assertCount(1, $points);
        self::assertSame(2, $points[0]->getMetadata()['generation']);
        self::assertSame('The original text.', $points[0]->getMetadata()->getText());
    }

    public function testEmptySearchAndMissingConversationFailClosed(): void
    {
        $retriever = static::getContainer()->get(TreeDocumentRetriever::class);
        $this->expectException(ApiException::class);
        iterator_to_array($retriever->retrieve('railway'), false);
    }

    public function testRealRabbitMqRoundTripUsesPersistentMessages(): void
    {
        $factory = new AmqpTransportFactory();
        $transport = $factory->createTransport(
            'amqp://mygenotree:${LOCAL_SERVICE_PASSWORD}@rabbitmq:5672/%2f/documents_test',
            [
                'confirm_timeout' => 5,
                'exchange'        => ['name' => 'documents_test'],
                'queues'          => ['documents_test' => []],
            ],
            new PhpSerializer(),
        );
        $id = Uuid::v7()->toRfc4122();
        $transport->send(new Envelope(new IndexDocumentMessage($id, 3)));
        $received = iterator_to_array($transport->get(), false);
        self::assertNotEmpty($received);
        $found = false;
        foreach ($received as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof IndexDocumentMessage && $message->documentId === $id) {
                self::assertSame(3, $message->generation);
                $found = true;
            }
            $transport->ack($envelope);
        }
        self::assertTrue($found);
    }

    private function document(string $contents, string $name = 'letter.txt'): TreeDocument
    {
        $path = tempnam(sys_get_temp_dir(), 'pipeline-document-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        try {
            $document = static::getContainer()->get(DocumentService::class)->upload(
                $this->tree,
                null,
                new UploadedFile($path, $name, test: true),
                static::getContainer()->get(TreeVersion::class)->etag($this->tree),
            );
            $this->ids[] = $document->getId()->toRfc4122();

            return $document;
        } finally {
            unlink($path);
        }
    }

    /** @return list<\Symfony\AI\Store\Document\VectorDocumentInterface> */
    private function points(TreeDocument $document): array
    {
        $store = static::getContainer()->get('ai.store.qdrant.documents');
        self::assertInstanceOf(StoreInterface::class, $store);
        $data = array_fill(0, 1024, 0.0);
        $data[0] = 1.0;

        return iterator_to_array(
            $store->query(
                new VectorQuery(new Vector($data)),
                [
                    'filter' => [
                        'must' => [
                            ['key' => 'documentId', 'match' => ['value' => $document->getId()->toRfc4122()]],
                        ],
                    ],
                    'limit' => 100,
                ]
            ),
            false
        );
    }

    private function worker(IndexerInterface $indexer): IndexDocumentMessageHandler
    {
        $container = static::getContainer();

        return new IndexDocumentMessageHandler(
            $container->get(TreeDocumentRepository::class),
            $this->em,
            $container->get(DocumentFiles::class),
            $container->get(DocumentVectors::class),
            $indexer,
            $container->get('app.document_locks'),
            $container->get(ClockInterface::class),
        );
    }
}
