<?php

namespace App\Tests\Api\Tree;

use App\Application\Tree\TreeContextService;
use App\Entity\FamilyTree;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Entity\Value\DateKind;
use App\Entity\Value\Gender;
use App\Entity\Value\GenealogicalDate;
use App\Entity\Value\KnownText;
use App\Entity\Value\ParentRole;
use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Ai\FixtureTreeAgent;
use App\Tests\Infrastructure\Doctrine\QueryCountingMiddleware;
use App\Tool\TreeReadTools;
use Doctrine\ORM\EntityManagerInterface;

final class TreeContextApiTest extends ApiWebTestCase
{
    private string $treeId;
    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        FixtureTreeAgent::$handler = null;
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        $this->headers = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'MCP family'], $this->headers);
        $this->treeId = $this->responseString('id');
    }

    public function testPopulatedContextIsBoundedAndDoesNotLoadEachRelativeSeparately(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tree = $em->getRepository(FamilyTree::class)->find($this->treeId);
        self::assertNotNull($tree);
        $now = new \DateTimeImmutable();
        $reference = new Person(
            $tree,
            KnownText::unknown(),
            KnownText::known('Vera'),
            KnownText::unknown(),
            Gender::FEMALE,
            GenealogicalDate::known(DateKind::BETWEEN, 1938, 1, 2, 1939, 3, 4),
            null,
            $now
        );
        $em->persist($reference);
        for ($i = 0; $i < 12; ++$i) {
            $relative = new Person(
                $tree,
                KnownText::known('Child ' . $i),
                KnownText::known('Vera'),
                KnownText::unknown(),
                Gender::MALE,
                GenealogicalDate::unknown(),
                null,
                $now,
            );
            $em->persist($relative);
            $em->persist(new ParentLink($tree, $reference, $relative, ParentRole::MOTHER));
        }
        $spouse = new Person(
            $tree,
            KnownText::unknown(),
            KnownText::known('Mikhail'),
            KnownText::unknown(),
            Gender::MALE,
            GenealogicalDate::unknown(),
            GenealogicalDate::unknown(),
            $now,
        );
        $em->persist($spouse);
        $em->persist(new MarriagePeriod($tree, $spouse, $reference, GenealogicalDate::unknown(), null, $now));
        $tree->referencePerson($reference);
        $em->flush();
        $id = $reference->getId()->toRfc4122();
        $tools = static::getContainer()->get(TreeReadTools::class);
        $data = $tools->tree($this->treeId);
        self::assertSame($id, $data['referencePersonId']);
        self::assertSame($data, $tools->tree($this->treeId, null));
        self::assertIsArray($data['parents']);
        self::assertIsArray($data['marriages']);
        self::assertIsArray($data['people']);
        self::assertSame(14, $data['personCount']);
        self::assertCount(10, $data['parents']);
        self::assertCount(1, $data['marriages']);
        self::assertCount(12, $data['people']);
        self::assertTrue($data['truncated']);
        $referenceViews = array_values(
            array_filter($data['people'], static fn ($person) => is_array($person) && $person['id'] === $id),
        );
        self::assertIsArray($referenceViews[0]);
        self::assertSame(
            [
                'state' => 'known',
                'kind'  => 'between',
                'year'  => 1938,
                'month' => 1,
                'day'   => 2,
                'end'   => ['year' => 1939, 'month' => 3, 'day' => 4],
            ],
            $referenceViews[0]['birthDate']
        );
        $candidates = static::getContainer()->get(TreeContextService::class)->find($this->treeId, 'Vera');
        self::assertIsArray($candidates);
        self::assertCount(5, $candidates);

        $spouseId = $spouse->getId()->toRfc4122();
        self::assertSame($spouseId, $tools->tree($this->treeId, $spouseId)['referencePersonId']);
        $tree->referencePerson($spouse);
        $em->flush();
        self::assertSame($spouseId, $tools->tree($this->treeId)['referencePersonId']);
        self::assertSame($id, $tools->tree($this->treeId, $id)['referencePersonId']);

        $tree->referenceMarriage($spouse, $reference);
        $em->flush();
        $pair = [$id, $spouseId];
        sort($pair);
        $marriageContext = $tools->tree($this->treeId);
        self::assertSame($pair[0], $marriageContext['referencePersonId']);
        self::assertIsArray($marriageContext['people']);
        self::assertContains($spouseId, array_column($marriageContext['people'], 'id'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $counter = static::getContainer()->get(QueryCountingMiddleware::class . '.default');
        self::assertInstanceOf(QueryCountingMiddleware::class, $counter);
        $counter->reset();
        $tools->tree($this->treeId);
        self::assertGreaterThan(0, $counter->count());
        self::assertLessThanOrEqual(
            8,
            $counter->count(),
            'Immediate-family context must use bounded, batched queries.',
        );
    }

    public function testEmptyTreeWithoutAnExplicitPersonHasNoReferenceOrPeople(): void
    {
        $context = static::getContainer()->get(TreeReadTools::class)->tree($this->treeId);
        self::assertSame(0, $context['personCount']);
        self::assertNull($context['referencePersonId']);
        self::assertSame([], $context['people']);
        self::assertSame([], $context['parents']);
        self::assertSame([], $context['marriages']);
        self::assertFalse($context['truncated']);
    }
}
