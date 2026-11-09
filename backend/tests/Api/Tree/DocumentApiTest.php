<?php

namespace App\Tests\Api\Tree;

use App\Api\DocumentViewFactory;
use App\Entity\FamilyTree;
use App\Entity\TreeDocument;
use App\Repository\TreeDocumentRepository;
use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Doctrine\QueryCountingMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** @phpstan-type Context array{token: string, tree: string, etag: string, person: string} */
final class DocumentApiTest extends ApiWebTestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testOriginalBytesSizeOwnershipAndGenerationArePreserved(): void
    {
        $context = $this->context();
        $bytes = "\xEF\xBB\xBF# Семья\r\nПисьмо о работе на железной дороге.\r\n";
        $this->upload($context, $bytes, 'Письмо.MD', $context['person']);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(strlen($bytes), $this->responseInt('document', 'originalSizeBytes'));
        self::assertSame('text/markdown', $this->responseString('document', 'mimeType'));
        self::assertSame('queued', $this->responseString('document', 'status'));
        self::assertSame($context['person'], $this->responseString('document', 'personId'));
        $id = $this->responseString('document', 'id');
        $etag = $this->responseString('etag');
        self::assertNotSame($context['etag'], $etag);
        $path = '/api/v1/trees/' . $context['tree'] . '/documents/' . $id;

        $this->jsonRequest('GET', $path . '/download', headers: $this->headers($context));
        self::assertResponseIsSuccessful();
        self::assertSame($bytes, $this->client->getResponse()->getContent());
        self::assertSame((string)strlen($bytes), $this->client->getResponse()->headers->get('Content-Length'));
        self::assertStringContainsString('attachment;', $this->client->getResponse()->headers->get('Content-Disposition') ?? '');
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'));
        $document = static::getContainer()->get(TreeDocumentRepository::class)->find($id);
        self::assertInstanceOf(TreeDocument::class, $document);
        self::assertSame(hash('sha256', $bytes), $document->getChecksum());
        self::assertSame(strlen($bytes), $document->getOriginalSizeBytes());
        self::assertSame(1, $document->getGeneration());

        $document->transition('ready', new \DateTimeImmutable('-1 hour'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->jsonRequest('POST', $path . '/retry', headers: $this->headers($context));
        self::assertResponseStatusCodeSame(409);
        $this->jsonRequest('DELETE', $path, headers: $this->headers($context));
        self::assertResponseStatusCodeSame(412);
        $context['etag'] = $etag;
        $this->jsonRequest('DELETE', $path, headers: $this->headers($context));
        self::assertResponseIsSuccessful();
        $this->jsonRequest('GET', $path . '/download', headers: $this->headers($context));
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('GET', '/api/v1/trees/' . $context['tree'] . '/documents', headers: $this->headers($context));
        self::assertSame([], $this->responseArray('items'));

        $this->jsonRequest('GET', $path . '/download');
        self::assertResponseStatusCodeSame(401);
    }

    public function testListsSharedAndPersonDocumentsWithPaginationAndBoundedQueries(): void
    {
        $context = $this->context();
        foreach ([null, $context['person'], null] as $person) {
            $this->upload($context, 'A family letter.', person: $person);
            self::assertResponseStatusCodeSame(202);
            $context['etag'] = $this->responseString('etag');
        }
        $base = '/api/v1/trees/' . $context['tree'] . '/documents';
        $this->jsonRequest('GET', $base . '?limit=1', headers: $this->headers($context));
        self::assertCount(1, $this->responseArray('items'));
        self::assertSame(3, $this->responseData()['total']);
        $cursor = $this->responseString('nextCursor');
        $this->jsonRequest('GET', $base . '?cursor=' . $cursor, headers: $this->headers($context));
        self::assertCount(2, $this->responseArray('items'));
        self::assertNull($this->responseData()['nextCursor']);
        self::assertSame(3, $this->responseData()['total']);
        $this->jsonRequest('GET', $base . '?personId=' . $context['person'], headers: $this->headers($context));
        self::assertCount(3, $this->responseArray('items'));
        $this->jsonRequest(
            'GET',
            $base . '?scope=person&personId=' . $context['person'],
            headers: $this->headers($context),
        );
        self::assertCount(1, $this->responseArray('items'));
        self::assertSame(1, $this->responseData()['total']);
        $this->jsonRequest('GET', $base . '?scope=tree', headers: $this->headers($context));
        self::assertCount(2, $this->responseArray('items'));
        self::assertSame(2, $this->responseData()['total']);
        foreach (['limit=0', 'limit=101', 'limit=x', 'cursor=bad', 'scope=bad', 'scope=person'] as $query) {
            $this->jsonRequest('GET', $base . '?' . $query, headers: $this->headers($context));
            self::assertResponseStatusCodeSame(422);
        }
        $this->jsonRequest('GET', $base . '?personId=bad', headers: $this->headers($context));
        self::assertResponseStatusCodeSame(404);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $tree = $em->find(FamilyTree::class, $context['tree']);
        self::assertInstanceOf(FamilyTree::class, $tree);
        $counter = static::getContainer()->get(QueryCountingMiddleware::class);
        self::assertInstanceOf(QueryCountingMiddleware::class, $counter);
        $counter->reset();
        $rows = static::getContainer()->get(TreeDocumentRepository::class)->page($tree, null, 'all', null, 100);
        foreach ($rows as $row) {
            (new DocumentViewFactory())->create($row);
        }
        self::assertLessThanOrEqual(2, $counter->selectCount());
    }

    public function testRejectsInvalidFilesAndForeignAttachmentsWithoutSavingAnything(): void
    {
        $context = $this->context();
        foreach ([
            ['', 'empty.txt'],
            ['  ', 'blank.md'],
            ["\xFF\xFE", 'bad.txt'],
            ["binary\0data", 'binary.txt'],
            ['text', 'text.pdf'],
            ['text', "bad\nname.txt"],
        ] as [$contents, $name]) {
            $this->upload($context, $contents, $name);
            self::assertResponseStatusCodeSame(422);
        }
        $this->upload($context, str_repeat('x', 10485761));
        self::assertResponseStatusCodeSame(413);
        $this->upload($context, 'Valid document', person: 'not-a-person');
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('GET', '/api/v1/trees/' . $context['tree'] . '/documents', headers: $this->headers($context));
        self::assertSame([], $this->responseArray('items'));
    }

    public function testPersonDeletionMakesDocumentsSharedAndTreeDeletionRetainsCleanupState(): void
    {
        $context = $this->context();
        $this->upload($context, 'Personal letter', person: $context['person']);
        $id = $this->responseString('document', 'id');
        $context['etag'] = $this->responseString('etag');
        $this->jsonRequest(
            'POST',
            '/api/v1/trees/' . $context['tree'] . '/graph-mutations',
            ['type' => 'delete_person', 'personId' => $context['person']],
            $this->headers($context),
        );
        self::assertResponseIsSuccessful();
        $context['etag'] = $this->responseString('etag');
        $this->jsonRequest('GET', '/api/v1/trees/' . $context['tree'] . '/documents?scope=tree', headers: $this->headers($context));
        self::assertCount(1, $this->responseArray('items'));
        $first = $this->responseArray('items')[0];
        self::assertIsArray($first);
        self::assertNull($first['personId']);

        $this->jsonRequest('DELETE', '/api/v1/trees/' . $context['tree'], headers: $this->headers($context));
        self::assertResponseStatusCodeSame(204);
        $document = static::getContainer()->get(TreeDocumentRepository::class)->find($id);
        self::assertInstanceOf(TreeDocument::class, $document);
        self::assertTrue($document->isDeleted());
        self::assertNull($document->getTree());
    }

    public function testOtherOwnersCannotReadUploadDeleteOrRetry(): void
    {
        $context = $this->context();
        $this->upload($context, 'Private document');
        $id = $this->responseString('document', 'id');
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            $this->validRegistration(
                [
                    'username' => 'other_reader',
                    'email'    => 'reader@example.test',
                ]
            )
        );
        $context['token'] = $this->responseString('accessToken');
        $base = '/api/v1/trees/' . $context['tree'] . '/documents';
        foreach ([
            ['GET', $base],
            ['GET', $base . '/' . $id . '/download'],
            ['DELETE', $base . '/' . $id],
            ['POST', $base . '/' . $id . '/retry'],
        ] as [$method, $path]) {
            $this->jsonRequest($method, $path, headers: $this->headers($context));
            self::assertResponseStatusCodeSame(404);
        }
        $this->upload($context, 'Another document');
        self::assertResponseStatusCodeSame(404);
    }

    public function testReadyMetadataRefreshesAnAttachmentChangedByBulkMutation(): void
    {
        $context = $this->context();
        $this->upload($context, 'An archival family document.', person: $context['person']);
        $id = $this->responseString('document', 'id');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $document = $em->find(TreeDocument::class, $id);
        self::assertInstanceOf(TreeDocument::class, $document);
        $tree = $document->getTree();
        self::assertInstanceOf(FamilyTree::class, $tree);
        $person = $document->getPerson();
        self::assertInstanceOf(\App\Entity\Person::class, $person);
        $document->transition('ready', new \DateTimeImmutable());
        $em->flush();
        $repository = static::getContainer()->get(TreeDocumentRepository::class);
        self::assertSame($person, $repository->ready($tree, [$id])[0]->getPerson());

        $repository->detachPerson($person);
        $refreshed = $repository->ready($tree, [$id]);
        self::assertNull($refreshed[0]->getPerson());
        self::assertSame(1, $refreshed[0]->getGeneration());
    }

    public function testQueueFailureKeepsOriginalAndCanBeRetriedWithoutChangingTreeVersion(): void
    {
        $context = $this->context();
        $this->client->disableReboot();
        $bus = new class implements MessageBusInterface {
            public bool $fail = true;

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($this->fail) {
                    throw new \RuntimeException('Broker offline.');
                }

                return Envelope::wrap($message, $stamps);
            }
        };
        static::getContainer()->set('messenger.bus.default', $bus);
        $this->upload($context, 'Saved while broker offline');
        self::assertResponseStatusCodeSame(202);
        self::assertSame('failed', $this->responseString('document', 'status'));
        self::assertSame('DOCUMENT_QUEUE_UNAVAILABLE', $this->responseString('document', 'errorCode'));
        $id = $this->responseString('document', 'id');
        $etag = $this->responseString('etag');
        $bus->fail = false;
        $base = '/api/v1/trees/' . $context['tree'];
        $this->jsonRequest('POST', $base . '/documents/' . $id . '/retry', headers: $this->headers($context));
        self::assertResponseStatusCodeSame(202);
        self::assertSame('queued', $this->responseString('document', 'status'));
        $this->jsonRequest('GET', $base . '/snapshot', headers: $this->headers($context));
        self::assertSame($etag, $this->responseString('etag'));
    }

    /** @return Context */
    private function context(): array
    {
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        $token = $this->responseString('accessToken');
        $headers = ['Authorization' => 'Bearer ' . $token];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Document tests'], $headers);
        $tree = $this->responseString('id');
        $headers['If-Match'] = $this->client->getResponse()->headers->get('ETag') ?? '';
        $this->jsonRequest(
            'POST',
            '/api/v1/trees/' . $tree . '/graph-mutations',
            [
                'type'   => 'create_first_person',
                'person' => [
                    'birthSurname' => ['state' => 'unknown'],
                    'givenName'    => ['state' => 'known', 'value' => 'Anna'],
                    'patronymic'   => ['state' => 'unknown'],
                    'gender'       => 'unknown',
                    'birthDate'    => ['state' => 'unknown'],
                    'deathDate'    => null,
                ],
            ],
            $headers
        );
        self::assertResponseIsSuccessful();

        return [
            'token'  => $token,
            'tree'   => $tree,
            'etag'   => $this->responseString('etag'),
            'person' => $this->responseString('people', '0', 'id'),
        ];
    }

    /** @param Context $context
     * @return array<string, string>
     */
    private function headers(array $context): array
    {
        return ['Authorization' => 'Bearer ' . $context['token'], 'If-Match' => $context['etag']];
    }

    /** @param Context $context */
    private function upload(array $context, string $contents, string $name = 'letter.txt', ?string $person = null): void
    {
        $path = tempnam(sys_get_temp_dir(), 'document-test-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;
        $this->client->request(
            'POST',
            'https://localhost/api/v1/trees/' . $context['tree'] . '/documents',
            null === $person ? [] : ['personId' => $person],
            ['file'  => new UploadedFile($path, $name, test: true)],
            ['HTTPS' => 'on', 'HTTP_AUTHORIZATION' => 'Bearer ' . $context['token'], 'HTTP_IF_MATCH' => $context['etag']],
        );
    }
}
