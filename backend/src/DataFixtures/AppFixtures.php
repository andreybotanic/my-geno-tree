<?php

namespace App\DataFixtures;

use App\Application\Event\AutomaticEventSynchronizer;
use App\Application\Person\Coordinates;
use App\Entity\FamilyTree;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Entity\User;
use App\Entity\Value\DatePrecision;
use App\Entity\Value\Gender;
use App\Entity\Value\GenealogicalDate;
use App\Entity\Value\KnownText;
use App\Entity\Value\ParentRole;
use App\Repository\FamilyTreeRepository;
use App\Repository\UserRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixtures extends Fixture
{
    private const string DEMO_PASSWORD = 'demo-password';
    private const string DEMO_USERNAME = 'demo';
    private const string DEMO_EMAIL = 'demo@example.test';
    private const string DEMO_TREE_NAME = 'Семья Орловых';

    public function __construct(
        private readonly AutomaticEventSynchronizer $events,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $users,
        private readonly FamilyTreeRepository $trees,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $createdAt = new \DateTimeImmutable('2026-08-01T00:00:00+00:00');
        $user = $this->users->findOneByLoginIdentifier(self::DEMO_EMAIL);
        if (null === $user) {
            $user = new User(
                self::DEMO_USERNAME,
                self::DEMO_EMAIL,
                '',
                'ru',
                $createdAt,
            );
            $user = new User(
                $user->getUsername(),
                $user->getEmail(),
                $this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD),
                $user->getPreferredLanguage(),
                $createdAt,
            );
            $manager->persist($user);
        }
        $existingTree = $this->trees->findOneBy([
            'owner' => $user,
            'name'  => self::DEMO_TREE_NAME,
        ]);
        if ($existingTree instanceof FamilyTree) {
            $manager->remove($existingTree);
            $manager->flush();
        }
        $tree = new FamilyTree($user, self::DEMO_TREE_NAME, $createdAt);
        $manager->persist($tree);

        // Four generations, built through the same relative and child-placement
        // rules as interactive graph mutations. No fixture coordinates are fixed.
        $builder = new FixtureTreeBuilder($manager, $tree, $this->events, $createdAt);
        $grigoriy = $builder->firstPerson($this->data('Орлов', 'Григорий', 'Павлович', Gender::MALE, $this->year(1925), $this->year(1994)));
        $agafya = $builder->addSpouse($grigoriy, $this->data('Кузнецова', 'Агафья', 'Ильинична', Gender::FEMALE, $this->year(1928), $this->year(2009)));
        $builder->marry($grigoriy, $agafya, $this->year(1949));
        $aleksandr = $builder->addChild($grigoriy, $agafya, $this->data('Орлов', 'Александр', 'Григорьевич', Gender::MALE, $this->day(1954, 2, 11), $this->year(2020)));
        $tatiana = $builder->addChild($grigoriy, $agafya, $this->data('Орлова', 'Татьяна', null, Gender::FEMALE, $this->year(1958), null));
        $larisa = $builder->addSpouse($aleksandr, $this->data('Волкова', 'Лариса', 'Фёдоровна', Gender::FEMALE, $this->month(1957, 11), null));
        $builder->marry($aleksandr, $larisa, $this->year(1978));
        $fedor = $builder->addParent($larisa, ParentRole::FATHER, $this->data('Волков', 'Фёдор', 'Никитич', Gender::MALE, $this->year(1927), $this->year(1998)));
        $vera = $builder->addParent($larisa, ParentRole::MOTHER, $this->data('Волкова', 'Вера', null, Gender::FEMALE, $this->month(1931, 5), $this->year(2015)));
        $builder->marry($fedor, $vera, $this->year(1953));
        $ivan = $builder->addChild($aleksandr, $larisa, $this->data('Орлов', 'Иван', 'Александрович', Gender::MALE, $this->day(1980, 7, 2), null));
        $olga = $builder->addChild($aleksandr, $larisa, $this->data('Орлова', 'Ольга', 'Александровна', Gender::FEMALE, $this->year(1984), null));
        $elena = $builder->addSpouse($ivan, $this->data('Савельева', 'Елена', 'Викторовна', Gender::FEMALE, $this->month(1983, 9), null));
        $builder->marry($ivan, $elena, $this->year(2003));
        $viktor = $builder->addParent($elena, ParentRole::FATHER, $this->data('Савельев', 'Виктор', 'Николаевич', Gender::MALE, $this->year(1959), null));
        $nadezhda = $builder->addParent($elena, ParentRole::MOTHER, $this->data('Крылова', 'Надежда', 'Семёновна', Gender::FEMALE, $this->day(1962, 4, 3), null));
        $builder->marry($viktor, $nadezhda, $this->year(1981));
        $nikolai = $builder->addParent($viktor, ParentRole::FATHER, $this->data('Савельев', 'Николай', 'Андреевич', Gender::MALE, $this->year(1930), $this->year(2004)));
        $sofiaSenior = $builder->addParent($viktor, ParentRole::MOTHER, $this->data('Белова', 'Софья', 'Михайловна', Gender::FEMALE, $this->day(1934, 8, 19), $this->year(2018)));
        $builder->marry($nikolai, $sofiaSenior, $this->year(1956));
        $semyon = $builder->addParent($nadezhda, ParentRole::FATHER, $this->data('Крылов', 'Семён', null, Gender::MALE, $this->year(1932), $this->year(2010)));
        $zoya = $builder->addParent($nadezhda, ParentRole::MOTHER, $this->data('Крылова', 'Зоя', 'Филипповна', Gender::FEMALE, $this->year(1936), null));
        $builder->marry($semyon, $zoya, $this->year(1958));
        $galina = $builder->addChild($viktor, $nadezhda, $this->data('Савельева', 'Галина', null, Gender::FEMALE, $this->year(1987), null));
        $pavel = $builder->addSpouse($olga, $this->data('Морозов', 'Павел', 'Сергеевич', Gender::MALE, $this->day(1981, 1, 28), null));
        $builder->marry($pavel, $olga, $this->year(2007));
        $dmitry = $builder->addSpouse($galina, $this->data(null, 'Дмитрий', 'Владимирович', Gender::MALE, $this->year(1985), null));
        $builder->marry($dmitry, $galina, $this->year(2012));
        $builder->addChild($ivan, $elena, $this->data('Орлов', 'Михаил', 'Иванович', Gender::MALE, $this->day(2005, 3, 15), null));
        $builder->addChild($ivan, $elena, $this->data('Орлова', 'Анна', 'Ивановна', Gender::FEMALE, $this->month(2008, 12), null));
        $builder->addChild($ivan, $elena, $this->data('Орлов', null, null, Gender::MALE, GenealogicalDate::unknown(), null));
        $builder->addChild($pavel, $olga, $this->data('Морозова', 'София', 'Павловна', Gender::FEMALE, $this->day(2010, 6, 7), null));
        $builder->addChild($pavel, $olga, $this->data('Морозов', 'Роман', null, Gender::MALE, $this->year(2014), null));
        $builder->addChild($dmitry, $galina, $this->data('Соколова', 'Вера', 'Дмитриевна', Gender::FEMALE, $this->year(2016), null));

        $builder->reflow();
        $manager->flush();
    }

    private function data(?string $surname, ?string $givenName, ?string $patronymic, Gender $gender, GenealogicalDate $birthDate, ?GenealogicalDate $deathDate): FixturePersonData
    {
        return new FixturePersonData($surname, $givenName, $patronymic, $gender, $birthDate, $deathDate);
    }

    private function year(int $year): GenealogicalDate
    {
        return GenealogicalDate::known(DatePrecision::YEAR, $year, null, null, false);
    }

    private function month(int $year, int $month): GenealogicalDate
    {
        return GenealogicalDate::known(DatePrecision::MONTH, $year, $month, null, false);
    }

    private function day(int $year, int $month, int $day): GenealogicalDate
    {
        return GenealogicalDate::known(DatePrecision::DAY, $year, $month, $day, false);
    }
}

final readonly class FixturePersonData
{
    public function __construct(
        public ?string $surname,
        public ?string $givenName,
        public ?string $patronymic,
        public Gender $gender,
        public GenealogicalDate $birthDate,
        public ?GenealogicalDate $deathDate,
    ) {
    }
}

/**
 * Mirrors the placement rules in frontend/src/treeGraph.ts for fixture data.
 */
final class FixtureTreeBuilder
{
    private const PERSON_CARD_WIDTH = 260;
    private const PERSON_CARD_HEIGHT = 160;
    private const HORIZONTAL_STEP = 320;
    private const VERTICAL_STEP = 240;

    /** @var list<Person> */
    private array $people = [];

    /** @var array<string, list<Person>> */
    private array $childrenByParents = [];

    /** @var array<string, list<Person>> */
    private array $parentsByChild = [];

    public function __construct(
        private readonly ObjectManager $manager,
        private readonly FamilyTree $tree,
        private readonly AutomaticEventSynchronizer $events,
        private readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public function firstPerson(FixturePersonData $data): Person
    {
        return $this->createPerson($data, new Coordinates(0, 0));
    }

    public function addParent(
        Person $child,
        ParentRole $role,
        FixturePersonData $data,
    ): Person {
        $kind = ParentRole::FATHER === $role ? 'father' : 'mother';
        $parent = $this->createPerson(
            $data,
            $this->newRelativeCoordinates($child, $kind),
        );
        $link = new ParentLink($this->tree, $parent, $child, $role);
        $this->manager->persist($link);
        $this->events->synchronizeParentLink($link, $this->createdAt);
        $this->parentsByChild[$child->getId()->toRfc4122()][] = $parent;

        return $parent;
    }

    public function addSpouse(
        Person $person,
        FixturePersonData $data,
    ): Person {
        return $this->createPerson(
            $data,
            $this->newRelativeCoordinates($person, 'spouse'),
        );
    }

    public function addChild(
        Person $father,
        Person $mother,
        FixturePersonData $data,
    ): Person {
        $key = $this->parentKey($father, $mother);
        $siblings = $this->childrenByParents[$key] ?? [];
        $totalChildren = count($siblings) + 1;
        $familyX = ($father->getX() + $mother->getX()) / 2;
        $familyY = max($father->getY(), $mother->getY());
        $childY = $familyY + self::VERTICAL_STEP;

        foreach ($siblings as $index => $sibling) {
            $coordinates = $this->childCoordinates(
                $familyX,
                $childY,
                $totalChildren,
                $index,
            );
            $sibling->moveTo(
                $coordinates->x,
                $coordinates->y,
                $this->createdAt,
            );
        }
        $child = $this->createPerson(
            $data,
            $this->childCoordinates(
                $familyX,
                $childY,
                $totalChildren,
                count($siblings),
            ),
        );
        foreach ([
            new ParentLink($this->tree, $father, $child, ParentRole::FATHER),
            new ParentLink($this->tree, $mother, $child, ParentRole::MOTHER),
        ] as $link) {
            $this->manager->persist($link);
            $this->events->synchronizeParentLink($link, $this->createdAt);
        }
        $siblings[] = $child;
        $this->childrenByParents[$key] = $siblings;
        $this->parentsByChild[$child->getId()->toRfc4122()] = [$father, $mother];

        return $child;
    }

    public function reflow(): void
    {
        $depths = [];
        $calculating = [];
        $depth = function (Person $person) use (&$depth, &$depths, &$calculating): int {
            $id = $person->getId()->toRfc4122();
            if (isset($depths[$id])) {
                return $depths[$id];
            }
            if (isset($calculating[$id])) {
                return 0;
            }
            $calculating[$id] = true;
            $parents = $this->parentsByChild[$id] ?? [];
            $parentDepths = array_map($depth, $parents);
            unset($calculating[$id]);

            return $depths[$id] = [] === $parentDepths ? 0 : max($parentDepths) + 1;
        };
        $peopleById = [];
        foreach ($this->people as $person) {
            $peopleById[$person->getId()->toRfc4122()] = $person;
        }
        /** @var array<string, array{parents: list<Person>, children: list<Person>}> $families */
        $families = [];
        foreach ($this->parentsByChild as $childId => $parents) {
            if ([] === $parents || !isset($peopleById[$childId])) {
                continue;
            }
            $parentIds = array_map(static fn (Person $person): string => $person->getId()->toRfc4122(), $parents);
            sort($parentIds);
            $key = implode(':', $parentIds);
            $families[$key] ??= ['parents' => $parents, 'children' => []];
            $families[$key]['children'][] = $peopleById[$childId];
        }
        $familyKeys = array_keys($families);
        usort($familyKeys, function (string $first, string $second) use ($depth, $families): int {
            $firstParents = $families[$first]['parents'];
            $secondParents = $families[$second]['parents'];
            if ([] === $firstParents || [] === $secondParents) {
                return 0;
            }
            $firstDepth = max(array_map($depth, $firstParents));
            $secondDepth = max(array_map($depth, $secondParents));

            return $firstDepth <=> $secondDepth ?: $first <=> $second;
        });
        $alignFamilies = function () use ($familyKeys, $families): void {
            foreach ($familyKeys as $key) {
                $parents = $families[$key]['parents'];
                $children = $families[$key]['children'];
                if ([] === $parents || [] === $children) {
                    continue;
                }
                usort($children, static fn (Person $first, Person $second): int => $first->getX() <=> $second->getX() ?: $first->getId()->toRfc4122() <=> $second->getId()->toRfc4122());
                $familyX = array_sum(array_map(static fn (Person $person): float => $person->getX(), $parents)) / count($parents);
                $familyY = max(array_map(static fn (Person $person): float => $person->getY(), $parents));
                foreach ($children as $index => $child) {
                    $coordinates = $this->childCoordinates($familyX, $familyY + self::VERTICAL_STEP, count($children), $index);
                    $child->moveTo($coordinates->x, $coordinates->y, $this->createdAt);
                }
            }
        };
        $ancestors = function (Person $person): array {
            $result = [];
            $visit = function (Person $current, int $distance) use (&$visit, &$result): void {
                $id = $current->getId()->toRfc4122();
                if (isset($result[$id]) && $result[$id]['distance'] <= $distance) {
                    return;
                }
                $result[$id] = ['person' => $current, 'distance' => $distance];
                foreach ($this->parentsByChild[$id] ?? [] as $parent) {
                    $visit($parent, $distance + 1);
                }
            };
            $visit($person, 0);

            return $result;
        };
        $alignFamilies();
        for ($attempt = 0; $attempt < count($this->people) * 2; ++$attempt) {
            $people = $this->people;
            usort($people, static fn (Person $first, Person $second): int => $first->getX() <=> $second->getX() ?: $first->getY() <=> $second->getY());
            $pair = null;
            foreach ($people as $index => $person) {
                for ($previous = $index - 1; $previous >= 0; --$previous) {
                    if ($person->getX() - $people[$previous]->getX() >= self::PERSON_CARD_WIDTH + 40) {
                        break;
                    }
                    if (abs($person->getY() - $people[$previous]->getY()) < self::PERSON_CARD_HEIGHT + 40) {
                        $pair = [$people[$previous], $person];
                        break 2;
                    }
                }
            }
            if (null === $pair) {
                break;
            }
            [$left, $right] = $pair;
            $leftAncestors = $ancestors($left);
            $roots = array_values(array_filter($ancestors($right), fn (array $candidate): bool => [] === ($this->parentsByChild[$candidate['person']->getId()->toRfc4122()] ?? [])));
            $candidates = array_values(array_filter($roots, static fn (array $candidate): bool => !isset($leftAncestors[$candidate['person']->getId()->toRfc4122()])));
            $candidates = [] !== $candidates ? $candidates : $roots;
            usort($candidates, static fn (array $first, array $second): int => $first['distance'] <=> $second['distance']);
            $candidate = $candidates[0] ?? null;
            if (null === $candidate) {
                break;
            }
            /** @var Person $root */
            $root = $candidate['person'];
            $root->moveTo(
                $root->getX() + self::HORIZONTAL_STEP * 2 ** min($candidate['distance'], 3),
                $root->getY(),
                $this->createdAt,
            );
            $alignFamilies();
        }
    }

    public function marry(
        Person $male,
        Person $female,
        GenealogicalDate $date,
    ): void {
        $marriage = new MarriagePeriod(
            $this->tree,
            $male,
            $female,
            $date,
            null,
            $this->createdAt,
        );
        $this->manager->persist($marriage);
        $this->events->synchronizeMarriage(
            $marriage,
            null,
            null,
            $this->createdAt,
        );
    }

    private function createPerson(
        FixturePersonData $data,
        Coordinates $coordinates,
    ): Person {
        $person = new Person(
            $this->tree,
            $this->text($data->surname),
            $this->text($data->givenName),
            $this->text($data->patronymic),
            $data->gender,
            $data->birthDate,
            $data->deathDate,
            $coordinates->x,
            $coordinates->y,
            $this->createdAt,
        );
        $this->manager->persist($person);
        $this->events->synchronizePersonLifecycle($person, $this->createdAt);
        $this->people[] = $person;

        return $person;
    }

    /** @param 'father'|'mother'|'spouse' $kind */
    private function newRelativeCoordinates(Person $anchor, string $kind): Coordinates
    {
        $base = match ($kind) {
            'father' => new Coordinates(
                $anchor->getX() - self::HORIZONTAL_STEP / 2,
                $anchor->getY() - self::VERTICAL_STEP,
            ),
            'mother' => new Coordinates(
                $anchor->getX() + self::HORIZONTAL_STEP / 2,
                $anchor->getY() - self::VERTICAL_STEP,
            ),
            'spouse' => new Coordinates(
                $anchor->getX() + self::HORIZONTAL_STEP,
                $anchor->getY(),
            ),
        };
        $direction = 'father' === $kind ? -1 : 1;

        for ($attempt = 0; $attempt < count($this->people) + 2; ++$attempt) {
            $ring = (int)ceil($attempt / 2);
            $sign = 0 === $attempt ? 0 : (1 === $attempt % 2 ? $direction : -$direction);
            $candidate = new Coordinates(
                $base->x + $ring * self::HORIZONTAL_STEP * $sign,
                $base->y,
            );
            if (!$this->overlapsExistingPerson($candidate)) {
                return $candidate;
            }
        }

        return new Coordinates(
            $base->x + (count($this->people) + 1) * self::HORIZONTAL_STEP * $direction,
            $base->y,
        );
    }

    private function childCoordinates(
        float $familyX,
        float $childY,
        int $totalChildren,
        int $index,
    ): Coordinates {
        return new Coordinates(
            $familyX + ($index - ($totalChildren - 1) / 2) * self::HORIZONTAL_STEP,
            $childY,
        );
    }

    private function overlapsExistingPerson(Coordinates $candidate): bool
    {
        foreach ($this->people as $person) {
            if ($this->overlaps($candidate, new Coordinates($person->getX(), $person->getY()))) {
                return true;
            }
        }

        return false;
    }

    private function overlaps(Coordinates $first, Coordinates $second): bool
    {
        return abs($first->x - $second->x) < self::PERSON_CARD_WIDTH + 40
            && abs($first->y - $second->y) < self::PERSON_CARD_HEIGHT + 40;
    }

    private function parentKey(Person $first, Person $second): string
    {
        $ids = [$first->getId()->toRfc4122(), $second->getId()->toRfc4122()];
        sort($ids);

        return implode(':', $ids);
    }

    private function text(?string $value): KnownText
    {
        return null === $value ? KnownText::unknown() : KnownText::known($value);
    }
}
