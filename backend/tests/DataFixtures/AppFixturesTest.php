<?php

namespace App\Tests\DataFixtures;

use App\DataFixtures\AppFixtures;
use App\Entity\FamilyTree;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithEnvironmentVariable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixturesTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = static::getContainer()->get(Connection::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection->executeStatement('TRUNCATE app_user CASCADE');
    }

    #[WithEnvironmentVariable('DEMO_PASSWORD', '')]
    public function testFirstLoadRequiresADemoPassword(): void
    {
        $fixture = static::getContainer()->get(AppFixtures::class);
        $this->expectException(\InvalidArgumentException::class);
        $fixture->load($this->entityManager);
    }

    #[WithEnvironmentVariable('DEMO_PASSWORD', 'fixture-example')]
    public function testNewAccountUsesTheConfiguredPassword(): void
    {
        $fixture = static::getContainer()->get(AppFixtures::class);
        $fixture->load($this->entityManager);
        $this->entityManager->clear();

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => 'demo']);
        self::assertInstanceOf(User::class, $user);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, 'fixture-example'));
    }

    public function testAppendFixturesPreserveExistingTreesAndAreIdempotent(): void
    {
        $createdAt = new \DateTimeImmutable('2026-08-01T00:00:00+00:00');
        $demo = new User('demo', 'demo@example.test', 'existing-password-hash', 'ru', $createdAt);
        $this->entityManager->persist($demo);
        $this->entityManager->persist(new FamilyTree($demo, 'Существующее дерево', $createdAt));
        $this->entityManager->flush();

        $fixture = static::getContainer()->get(AppFixtures::class);
        $fixture->load($this->entityManager);

        $expected = [
            'Горем'                   => 24,
            'Семья Орловых'           => 25,
            'Стресс-тест раскладки'   => 242,
            'Существующее дерево'     => 0,
            'Шесть поколений предков' => 63,
            'Широкая семья Семёновых' => 101,
        ];
        self::assertSame($expected, $this->personCounts());

        $fixture->load($this->entityManager);

        self::assertSame($expected, $this->personCounts());
        self::assertSame('existing-password-hash', $demo->getPassword());
    }

    /** @return array<string, int> */
    private function personCounts(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT tree.name, COUNT(person.id) AS person_count
            FROM family_tree tree
            LEFT JOIN person ON person.tree_id = tree.id
            INNER JOIN app_user owner ON owner.id = tree.owner_id
            WHERE owner.email = 'demo@example.test'
            GROUP BY tree.name
            ORDER BY tree.name
            SQL);

        $counts = [];
        foreach ($rows as $row) {
            $name = $row['name'];
            $personCount = $row['person_count'];
            if (!is_string($name) || (!is_string($personCount) && !is_int($personCount))) {
                throw new \LogicException('Fixture tree count query returned an unexpected row.');
            }
            $counts[$name] = (int)$personCount;
        }

        return $counts;
    }
}
