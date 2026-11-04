<?php

namespace App\Tests\Api\Tree;

use App\Application\TreeMerge\Ai\Dto\MergeAiResponse;
use App\Application\TreeMerge\Ai\MergeAiResult;
use App\Application\TreeMerge\TreeContextService;
use App\Entity\FamilyTree;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Entity\TreeMergeDraft;
use App\Entity\Value\DateKind;
use App\Entity\Value\Gender;
use App\Entity\Value\GenealogicalDate;
use App\Entity\Value\KnownText;
use App\Entity\Value\ParentRole;
use App\Security\TreeMergeReadGrant;
use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Ai\FixtureTreeMergeAi;
use App\Tests\Infrastructure\Doctrine\QueryCountingMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Client\Stateless\HeaderFactory;
use Mcp\Client\Stateless\RequestEnvelope;
use Mcp\Client\Stateless\ToolCatalog;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Symfony\Component\Uid\Uuid;

final class TreeMcpApiTest extends ApiWebTestCase
{
    private string $treeId;
    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        FixtureTreeMergeAi::$handler = null;
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        $this->headers = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'MCP family'], $this->headers);
        $this->treeId = $this->responseString('id');
    }

    public function testExternalToolsAreBoundedAndNeverExposeApply(): void
    {
        $this->rpc('tools/list');
        self::assertResponseIsSuccessful();
        $tools = $this->responseData()['result'];
        self::assertIsArray($tools);
        self::assertIsArray($tools['tools']);
        $names = array_column($tools['tools'], 'name');
        sort($names);
        self::assertSame(['find_people', 'get_person_context', 'get_tree_context', 'get_tree_merge', 'prepare_tree_merge'], $names);
        $this->call('get_tree_context', ['treeId' => $this->treeId]);
        self::assertResponseIsSuccessful();
        $context = $this->toolData();
        self::assertSame($this->treeId, $context['treeId']);
        self::assertSame([], $context['people']);
        self::assertSame(0, $context['personCount']);
        $this->call('find_people', ['treeId' => $this->treeId, 'query' => 'Vera']);
        self::assertSame([], $this->toolData()['candidates']);
        $this->call('get_person_context', ['treeId' => $this->treeId, 'personId' => Uuid::v7()->toRfc4122()]);
        self::assertArrayHasKey('error', $this->responseData());
        $this->call('apply_tree_merge', ['treeId' => $this->treeId]);
        self::assertArrayHasKey('error', $this->responseData());
    }

    public function testExternalPreparationUsesTheConversationPipelineAndReturnsAPreviewLink(): void
    {
        FixtureTreeMergeAi::$handler = static fn () => new MergeAiResult(new MergeAiResponse());
        $this->call('prepare_tree_merge', ['treeId' => $this->treeId, 'description' => 'A non-tree question']);
        self::assertResponseIsSuccessful();
        $draft = $this->toolData();
        self::assertIsString($draft['previewUrl']);
        self::assertIsArray($draft['messages']);
        self::assertStringContainsString('/trees/' . $this->treeId . '/tree?merge=', $draft['previewUrl']);
        self::assertCount(2, $draft['messages']);
        self::assertNull($draft['proposal']);
        $this->call('get_tree_merge', ['treeId' => $this->treeId, 'mergeId' => $draft['id']]);
        self::assertSame($draft, $this->toolData());
    }

    public function testPopulatedContextIsBoundedAndDoesNotLoadEachRelativeSeparately(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tree = $em->getRepository(FamilyTree::class)->find($this->treeId);
        self::assertNotNull($tree);
        $now = new \DateTimeImmutable();
        $reference = new Person($tree, KnownText::unknown(), KnownText::known('Vera'), KnownText::unknown(), Gender::FEMALE,
            GenealogicalDate::known(DateKind::BETWEEN, 1938, 1, 2, 1939, 3, 4), null, $now);
        $em->persist($reference);
        for ($i = 0; $i < 12; ++$i) {
            $relative = new Person($tree, KnownText::known('Child ' . $i), KnownText::known('Vera'), KnownText::unknown(), Gender::MALE, GenealogicalDate::unknown(), null, $now);
            $em->persist($relative);
            $em->persist(new ParentLink($tree, $reference, $relative, ParentRole::MOTHER));
        }
        $spouse = new Person($tree, KnownText::unknown(), KnownText::known('Mikhail'), KnownText::unknown(), Gender::MALE, GenealogicalDate::unknown(), GenealogicalDate::unknown(), $now);
        $em->persist($spouse);
        $em->persist(new MarriagePeriod($tree, $spouse, $reference, GenealogicalDate::unknown(), null, $now));
        $em->flush();
        $id = $reference->getId()->toRfc4122();
        $this->call('get_person_context', ['treeId' => $this->treeId, 'personId' => $id]);
        $data = $this->toolData();
        self::assertIsArray($data['parents']);
        self::assertIsArray($data['marriages']);
        self::assertIsArray($data['people']);
        self::assertSame(14, $data['personCount']);
        self::assertCount(10, $data['parents']);
        self::assertCount(1, $data['marriages']);
        self::assertCount(12, $data['people']);
        self::assertTrue($data['truncated']);
        $referenceViews = array_values(array_filter($data['people'], static fn ($person) => is_array($person) && $person['id'] === $id));
        self::assertIsArray($referenceViews[0]);
        self::assertSame(['state' => 'known', 'kind' => 'between', 'year' => 1938, 'month' => 1, 'day' => 2,
            'end'                 => ['year' => 1939, 'month' => 3, 'day' => 4]], $referenceViews[0]['birthDate']);
        $this->call('find_people', ['treeId' => $this->treeId, 'query' => 'Vera']);
        $candidates = $this->toolData()['candidates'];
        self::assertIsArray($candidates);
        self::assertCount(5, $candidates);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $counter = static::getContainer()->get(QueryCountingMiddleware::class . '.default');
        self::assertInstanceOf(QueryCountingMiddleware::class, $counter);
        $counter->reset();
        static::getContainer()->get(TreeContextService::class)->context($this->treeId, $id);
        self::assertGreaterThan(0, $counter->count());
        self::assertLessThanOrEqual(8, $counter->count(), 'Immediate-family context must use bounded, batched queries.');
    }

    public function testMcpRequiresAuthenticationAndChecksOwnershipForEveryTool(): void
    {
        $original = $this->headers;
        $this->headers = [];
        $this->rpc('tools/list');
        self::assertResponseStatusCodeSame(401);
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration(['username' => 'other', 'email' => 'other@example.test']));
        $this->headers = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        foreach (['get_tree_context' => ['treeId' => $this->treeId],
            'find_people'            => ['treeId' => $this->treeId, 'query' => 'Vera'],
            'get_person_context'     => ['treeId' => $this->treeId, 'personId' => Uuid::v7()->toRfc4122()],
            'get_tree_merge'         => ['treeId' => $this->treeId, 'mergeId' => Uuid::v7()->toRfc4122()],
            'prepare_tree_merge'     => ['treeId' => $this->treeId, 'description' => 'Mother Vera'],
        ] as $tool => $arguments) {
            $this->call($tool, $arguments);
            self::assertArrayHasKey('error', $this->responseData());
        }
        $this->headers = $original;
    }

    public function testInternalGrantIsLimitedToReadToolsTreeAndAttemptAndRejectedByRest(): void
    {
        $base = '/api/v1/trees/' . $this->treeId . '/merges';
        $this->jsonRequest('POST', $base, ['referencePersonId' => null], $this->headers);
        $id = $this->responseString('id');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $draft = $em->getRepository(TreeMergeDraft::class)->find($id);
        self::assertNotNull($draft);
        $attempt = $draft->beginAttempt(new \DateTimeImmutable());
        $em->flush();
        $grants = static::getContainer()->get(TreeMergeReadGrant::class);
        $token = $grants->issue($draft, $attempt);
        $this->headers = ['Authorization' => 'Merge ' . $token];
        $this->rpc('tools/list', endpoint: '/mcp/read');
        self::assertResponseIsSuccessful();
        $tools = $this->responseData()['result'];
        self::assertIsArray($tools);
        self::assertIsArray($tools['tools']);
        self::assertCount(3, $tools['tools']);
        $this->call('get_tree_context', ['treeId' => $this->treeId], '/mcp/read');
        self::assertSame($this->treeId, $this->toolData()['treeId']);
        $this->call('get_tree_context', ['treeId' => Uuid::v7()->toRfc4122()], '/mcp/read');
        self::assertArrayHasKey('error', $this->responseData());
        $this->call('prepare_tree_merge', ['treeId' => $this->treeId, 'description' => 'Vera'], '/mcp/read');
        self::assertArrayHasKey('error', $this->responseData());
        $this->jsonRequest('POST', $base . '/' . $id . '/apply', ['version' => 2, 'proposalVersion' => 1], $this->headers);
        self::assertResponseStatusCodeSame(401);
        $grants->revoke($token);
        $this->rpc('tools/list', endpoint: '/mcp/read');
        self::assertResponseStatusCodeSame(401);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $draft = $em->getRepository(TreeMergeDraft::class)->find($id);
        self::assertNotNull($draft);
        $stale = $grants->issue($draft, $attempt);
        $draft->beginAttempt(new \DateTimeImmutable());
        $em->flush();
        $this->headers = ['Authorization' => 'Merge ' . $stale];
        $this->rpc('tools/list', endpoint: '/mcp/read');
        self::assertResponseStatusCodeSame(401);
    }

    public function testProtocolOverheadLeavesRoomForEightReadsButNotANinth(): void
    {
        $this->jsonRequest('POST', '/api/v1/trees/' . $this->treeId . '/merges', ['referencePersonId' => null], $this->headers);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $draft = $em->getRepository(TreeMergeDraft::class)->find($this->responseString('id'));
        self::assertNotNull($draft);
        $attempt = $draft->beginAttempt(new \DateTimeImmutable());
        $em->flush();
        $token = static::getContainer()->get(TreeMergeReadGrant::class)->issue($draft, $attempt);
        $this->headers = ['Authorization' => 'Merge ' . $token];
        for ($i = 0; $i < 3; ++$i) {
            $this->rpc('tools/list', endpoint: '/mcp/read');
            self::assertResponseIsSuccessful();
        }
        // Discovery/transport requests consume authentication budget, not the tool-read budget.
        for ($i = 0; $i < 8; ++$i) {
            $this->rpc('tools/list', endpoint: '/mcp/read');
            self::assertResponseIsSuccessful();
            $this->call('get_tree_context', ['treeId' => $this->treeId], '/mcp/read');
            self::assertResponseIsSuccessful();
            self::assertSame($this->treeId, $this->toolData()['treeId']);
        }
        $this->call('get_tree_context', ['treeId' => $this->treeId], '/mcp/read');
        self::assertResponseStatusCodeSame(400);
        self::assertArrayHasKey('error', $this->responseData());
    }

    /** @param array<string, mixed> $arguments */
    private function call(string $name, array $arguments, string $endpoint = '/mcp/tree'): void
    {
        $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments], $endpoint);
    }

    /** @param array<string, mixed> $params */
    private function rpc(string $method, array $params = [], string $endpoint = '/mcp/tree'): void
    {
        $version = ProtocolVersion::V2026_07_28;
        $envelope = new RequestEnvelope($version, new ClientCapabilities(), new Implementation('test', '1'));
        $body = $envelope->stamp(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        $headers = (new HeaderFactory(new ToolCatalog()))->forMessage($body, $version);
        $this->jsonRequest('POST', $endpoint, $body, $this->headers + $headers + ['Accept' => 'application/json, text/event-stream']);
    }

    /** @return array<string, mixed> */
    private function toolData(): array
    {
        $result = $this->responseData()['result'];
        self::assertIsArray($result);
        self::assertFalse($result['isError'] ?? false, json_encode($result, JSON_THROW_ON_ERROR));

        if (isset($result['structuredContent'])) {
            $data = $result['structuredContent'];
        } else {
            self::assertIsArray($result['content']);
            self::assertIsArray($result['content'][0]);
            self::assertIsString($result['content'][0]['text']);
            $data = json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        }
        self::assertIsArray($data);

        return $data;
    }
}
