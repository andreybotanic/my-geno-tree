<?php

namespace App\DataFixtures;

use App\Application\Event\AutomaticEventSynchronizer;
use App\Entity\FamilyTree;
use App\Entity\MarriagePeriod;
use App\Entity\ParentLink;
use App\Entity\Person;
use App\Entity\User;
use App\Entity\Value\DateKind;
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
    private const string DEEP_ANCESTORS_TREE_NAME = 'Шесть поколений предков';
    private const string WIDE_TREE_NAME = 'Широкая семья Семёновых';
    private const string COMPLEX_TREE_NAME = 'Горем';

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

        $this->createOrlovTree($manager, $user, $createdAt);
        $this->createDeepAncestorsTree($manager, $user, $createdAt);
        $this->createWideTree($manager, $user, $createdAt);
        $this->createComplexTree($manager, $user, $createdAt);

        $manager->flush();
    }

    private function createOrlovTree(ObjectManager $manager, User $user, \DateTimeImmutable $createdAt): void
    {
        $builder = $this->createTreeBuilder($manager, $user, self::DEMO_TREE_NAME, $createdAt);
        if (!$builder instanceof FixtureTreeBuilder) {
            return;
        }

        // Four generations, built through the same relative and child-placement
        // rules as interactive graph mutations. No fixture coordinates are fixed.
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
    }

    private function createDeepAncestorsTree(ObjectManager $manager, User $user, \DateTimeImmutable $createdAt): void
    {
        $builder = $this->createTreeBuilder($manager, $user, self::DEEP_ANCESTORS_TREE_NAME, $createdAt);
        if (!$builder instanceof FixtureTreeBuilder) {
            return;
        }

        $generation = [$builder->firstPerson($this->data('Яснов', 'Артём', 'Ильич', Gender::MALE, $this->day(2003, 5, 17), null))];
        for ($level = 1; $level < 6; ++$level) {
            $parents = [];
            foreach ($generation as $index => $child) {
                $father = $builder->addParent($child, ParentRole::FATHER, $this->deepAncestorData($level, $index * 2, Gender::MALE));
                $mother = $builder->addParent($child, ParentRole::MOTHER, $this->deepAncestorData($level, $index * 2 + 1, Gender::FEMALE));
                $builder->marry($father, $mother, $this->year(2002 - $level * 27));
                $parents[] = $father;
                $parents[] = $mother;
            }
            $generation = $parents;
        }
    }

    private function createWideTree(ObjectManager $manager, User $user, \DateTimeImmutable $createdAt): void
    {
        $builder = $this->createTreeBuilder($manager, $user, self::WIDE_TREE_NAME, $createdAt);
        if (!$builder instanceof FixtureTreeBuilder) {
            return;
        }

        $proband = $builder->firstPerson($this->data('Семёнов', 'Арсений', 'Данилович', Gender::MALE, $this->day(1997, 9, 12), null));
        $father = $builder->addParent($proband, ParentRole::FATHER, $this->data('Семёнов', 'Данил', 'Петрович', Gender::MALE, $this->year(1972), null));
        $mother = $builder->addParent($proband, ParentRole::MOTHER, $this->data('Семёнова', 'Евгения', 'Сергеевна', Gender::FEMALE, $this->month(1974, 3), null));
        $builder->marry($father, $mother, $this->year(1994));

        $paternalGrandfather = $builder->addParent($father, ParentRole::FATHER, $this->data('Семёнов', 'Пётр', 'Андреевич', Gender::MALE, $this->year(1945), $this->year(2019)));
        $paternalGrandmother = $builder->addParent($father, ParentRole::MOTHER, $this->data('Семёнова', 'Лидия', null, Gender::FEMALE, $this->year(1948), null));
        $maternalGrandfather = $builder->addParent($mother, ParentRole::FATHER, $this->data('Котов', 'Сергей', 'Ильич', Gender::MALE, $this->month(1946, 11), $this->year(2021)));
        $maternalGrandmother = $builder->addParent($mother, ParentRole::MOTHER, $this->data('Котова', 'Валентина', 'Фёдоровна', Gender::FEMALE, $this->year(1950), null));
        $builder->marry($paternalGrandfather, $paternalGrandmother, $this->year(1968));
        $builder->marry($maternalGrandfather, $maternalGrandmother, $this->year(1970));

        foreach ([
            [$paternalGrandfather, $paternalGrandmother, 'Семёнов', 1970],
            [$maternalGrandfather, $maternalGrandmother, 'Котов', 1972],
        ] as [$grandfather, $grandmother, $surname, $year]) {
            for ($number = 1; $number <= 4; ++$number) {
                $gender = 0 === $number % 2 ? Gender::FEMALE : Gender::MALE;
                $sibling = $builder->addChild(
                    $grandfather,
                    $grandmother,
                    $this->data(
                        Gender::FEMALE === $gender ? $surname . 'а' : $surname,
                        'Родственник ' . $number,
                        null,
                        $gender,
                        $this->year($year + $number),
                        null,
                    ),
                );
                $spouseGender = Gender::FEMALE === $gender ? Gender::MALE : Gender::FEMALE;
                $spouse = $builder->addSpouse($sibling, $this->data(null, 'Супруг ' . $number, null, $spouseGender, $this->year($year + $number + 1), null));
                $builder->marry(Gender::MALE === $gender ? $sibling : $spouse, Gender::FEMALE === $gender ? $sibling : $spouse, $this->year($year + $number + 22));
                for ($childNumber = 1; $childNumber <= 4; ++$childNumber) {
                    $builder->addChild(
                        Gender::MALE === $gender ? $sibling : $spouse,
                        Gender::FEMALE === $gender ? $sibling : $spouse,
                        $this->data(null, 'Двоюродный ' . $childNumber, null, 0 === $childNumber % 2 ? Gender::FEMALE : Gender::MALE, $this->year($year + $number + 24 + $childNumber), null),
                    );
                }
            }
        }

        for ($number = 1; $number <= 8; ++$number) {
            $gender = 0 === $number % 2 ? Gender::FEMALE : Gender::MALE;
            $sibling = $builder->addChild($father, $mother, $this->data('Семёнов' . (Gender::FEMALE === $gender ? 'а' : ''), 'Сиблинг ' . $number, null, $gender, $this->year(1998 + $number), null));
            $spouse = $builder->addSpouse($sibling, $this->data(null, 'Партнёр ' . $number, null, Gender::FEMALE === $gender ? Gender::MALE : Gender::FEMALE, $this->year(1998 + $number), null));
            $builder->marry(Gender::MALE === $gender ? $sibling : $spouse, Gender::FEMALE === $gender ? $sibling : $spouse, $this->year(2020 + $number % 3));
            for ($childNumber = 1; $childNumber <= 2; ++$childNumber) {
                $builder->addChild(Gender::MALE === $gender ? $sibling : $spouse, Gender::FEMALE === $gender ? $sibling : $spouse, $this->data(null, 'Племянник ' . $number . '-' . $childNumber, null, 0 === $childNumber % 2 ? Gender::FEMALE : Gender::MALE, $this->year(2022 + $childNumber), null));
            }
        }

        $spouse = $builder->addSpouse($proband, $this->data('Громова', 'Ксения', 'Алексеевна', Gender::FEMALE, $this->day(1998, 1, 4), null));
        $builder->marry($proband, $spouse, $this->year(2019));
        $children = [];
        for ($number = 1; $number <= 7; ++$number) {
            $children[] = $builder->addChild($proband, $spouse, $this->data('Семёнов' . (0 === $number % 2 ? 'а' : ''), 'Ребёнок ' . $number, null, 0 === $number % 2 ? Gender::FEMALE : Gender::MALE, $this->year(2020 + $number % 5), null));
        }
        foreach (array_slice($children, 0, 3) as $number => $child) {
            $childSpouse = $builder->addSpouse($child, $this->data(null, 'Супруг ребёнка ' . ($number + 1), null, 0 === $number % 2 ? Gender::FEMALE : Gender::MALE, $this->year(2020 + $number), null));
            $builder->marry(Gender::MALE === $child->getGender() ? $child : $childSpouse, Gender::FEMALE === $child->getGender() ? $child : $childSpouse, $this->year(2023));
            $builder->addChild(Gender::MALE === $child->getGender() ? $child : $childSpouse, Gender::FEMALE === $child->getGender() ? $child : $childSpouse, $this->data(null, 'Правнук ' . ($number + 1), null, 0 === $number % 2 ? Gender::MALE : Gender::FEMALE, $this->year(2024), null));
        }
    }

    private function createComplexTree(ObjectManager $manager, User $user, \DateTimeImmutable $createdAt): void
    {
        $builder = $this->createTreeBuilder($manager, $user, self::COMPLEX_TREE_NAME, $createdAt);
        if (!$builder instanceof FixtureTreeBuilder) {
            return;
        }

        $marina = $builder->firstPerson($this->data('Ковалёва', 'Марина', 'Алексеевна', Gender::FEMALE, $this->day(1989, 7, 8), null));
        $alexei = $builder->addParent($marina, ParentRole::FATHER, $this->data('Ковалёв', 'Алексей', 'Павлович', Gender::MALE, $this->year(1964), null));
        $natalia = $builder->addParent($marina, ParentRole::MOTHER, $this->data('Ковалёва', 'Наталья', 'Игоревна', Gender::FEMALE, $this->year(1967), null));
        $builder->marry($alexei, $natalia, $this->year(1986));

        $pavel = $builder->addParent($alexei, ParentRole::FATHER, $this->data('Ковалёв', 'Павел', 'Степанович', Gender::MALE, $this->year(1938), $this->year(2006)));
        $zoia = $builder->addParent($alexei, ParentRole::MOTHER, $this->data('Ковалёва', 'Зоя', null, Gender::FEMALE, $this->month(1940, 6), null));
        $igor = $builder->addParent($natalia, ParentRole::FATHER, $this->data('Крылов', 'Игорь', 'Васильевич', Gender::MALE, $this->year(1941), $this->year(2014)));
        $ludmila = $builder->addParent($natalia, ParentRole::MOTHER, $this->data('Крылова', 'Людмила', 'Алексеевна', Gender::FEMALE, $this->year(1944), null));
        $builder->marry($pavel, $zoia, $this->year(1960));
        $builder->marry($igor, $ludmila, $this->year(1963));
        $builder->addChild($pavel, $zoia, $this->data('Ковалёва', 'Вера', 'Павловна', Gender::FEMALE, $this->year(1961), null));
        $builder->addChild($igor, $ludmila, $this->data('Крылов', 'Роман', 'Игоревич', Gender::MALE, $this->year(1969), null));

        $valentina = $builder->addSpouse($alexei, $this->data('Соколова', 'Валентина', 'Романовна', Gender::FEMALE, $this->year(1965), null));
        $builder->marry($alexei, $valentina, $this->year(1995));
        $builder->addChild($alexei, $valentina, $this->data('Ковалёва', 'Дарья', 'Алексеевна', Gender::FEMALE, $this->year(1997), null));
        $builder->addChild($alexei, $valentina, $this->data('Ковалёв', 'Глеб', 'Алексеевич', Gender::MALE, $this->year(2000), null));
        $igorJunior = $builder->addSpouse($natalia, $this->data('Власов', 'Игорь', 'Юрьевич', Gender::MALE, $this->year(1963), null));
        $builder->marry($igorJunior, $natalia, $this->year(1991));
        $builder->addChild($igorJunior, $natalia, $this->data('Власов', 'Виктор', 'Игоревич', Gender::MALE, $this->year(1993), null));
        $petr = $builder->addSpouse($natalia, $this->data('Лебедев', 'Пётр', null, Gender::MALE, $this->year(1962), null));
        $builder->addChild($petr, $natalia, $this->data('Лебедева', 'София', 'Петровна', Gender::FEMALE, $this->year(1996), null));
        $builder->addChild($alexei, $natalia, $this->data('Ковалёв', 'Егор', 'Алексеевич', Gender::MALE, $this->year(1991), null));

        $maksim = $builder->addSpouse($marina, $this->data('Лебедев', 'Максим', 'Владимирович', Gender::MALE, $this->year(1987), null));
        $builder->marry($maksim, $marina, $this->year(2008));
        $builder->addChild($maksim, $marina, $this->data('Лебедева', 'Алина', 'Максимовна', Gender::FEMALE, $this->year(2009), null));
        $oleg = $builder->addSpouse($marina, $this->data('Романов', 'Олег', 'Геннадьевич', Gender::MALE, $this->year(1988), null));
        $builder->marry($oleg, $marina, $this->year(2013));
        $builder->addChild($oleg, $marina, $this->data('Романова', 'Полина', 'Олеговна', Gender::FEMALE, $this->year(2015), null));
        $builder->addChild($oleg, $marina, $this->data('Романов', 'Михаил', 'Олегович', Gender::MALE, $this->year(2018), null));
        $irina = $builder->addSpouse($oleg, $this->data('Белова', 'Ирина', 'Сергеевна', Gender::FEMALE, $this->year(1990), null));
        $builder->addChild($oleg, $irina, $this->data('Романов', 'Тимофей', 'Олегович', Gender::MALE, $this->year(2012), null));
    }

    private function createTreeBuilder(ObjectManager $manager, User $user, string $name, \DateTimeImmutable $createdAt): ?FixtureTreeBuilder
    {
        if ($this->trees->findOneBy(['owner' => $user, 'name' => $name]) instanceof FamilyTree) {
            return null;
        }

        $tree = new FamilyTree($user, $name, $createdAt);
        $manager->persist($tree);

        return new FixtureTreeBuilder($manager, $tree, $this->events, $createdAt);
    }

    private function deepAncestorData(int $generation, int $index, Gender $gender): FixturePersonData
    {
        $year = 2003 - $generation * 27 - $index % 3;
        $deathDate = $generation >= 4 && 0 === $index % 3 ? $this->year($year + 72) : null;

        return $this->data(
            Gender::FEMALE === $gender ? 'Яснова' : 'Яснов',
            0 === $index % 5 ? null : (Gender::FEMALE === $gender ? 'Предок' : 'Предок') . ' ' . ($index + 1),
            0 === $index % 4 ? null : 'Артёмович',
            $gender,
            $this->year($year),
            $deathDate,
        );
    }

    private function data(?string $surname, ?string $givenName, ?string $patronymic, Gender $gender, GenealogicalDate $birthDate, ?GenealogicalDate $deathDate): FixturePersonData
    {
        return new FixturePersonData($surname, $givenName, $patronymic, $gender, $birthDate, $deathDate);
    }

    private function year(int $year): GenealogicalDate
    {
        return GenealogicalDate::known(DateKind::EXACT, $year, null, null);
    }

    private function month(int $year, int $month): GenealogicalDate
    {
        return GenealogicalDate::known(DateKind::EXACT, $year, $month, null);
    }

    private function day(int $year, int $month, int $day): GenealogicalDate
    {
        return GenealogicalDate::known(DateKind::EXACT, $year, $month, $day);
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

final class FixtureTreeBuilder
{
    public function __construct(
        private readonly ObjectManager $manager,
        private readonly FamilyTree $tree,
        private readonly AutomaticEventSynchronizer $events,
        private readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public function firstPerson(FixturePersonData $data): Person
    {
        $person = $this->createPerson($data);
        $this->tree->referencePerson($person);

        return $person;
    }

    public function addParent(
        Person $child,
        ParentRole $role,
        FixturePersonData $data,
    ): Person {
        $parent = $this->createPerson($data);
        $link = new ParentLink($this->tree, $parent, $child, $role);
        $this->manager->persist($link);
        $this->events->synchronizeParentLink($link, $this->createdAt);

        return $parent;
    }

    public function addSpouse(
        Person $person,
        FixturePersonData $data,
    ): Person {
        return $this->createPerson($data);
    }

    public function addChild(
        Person $father,
        Person $mother,
        FixturePersonData $data,
    ): Person {
        $child = $this->createPerson($data);
        foreach ([
            new ParentLink($this->tree, $father, $child, ParentRole::FATHER),
            new ParentLink($this->tree, $mother, $child, ParentRole::MOTHER),
        ] as $link) {
            $this->manager->persist($link);
            $this->events->synchronizeParentLink($link, $this->createdAt);
        }

        return $child;
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

    private function createPerson(FixturePersonData $data): Person
    {
        $person = new Person(
            $this->tree,
            $this->text($data->surname),
            $this->text($data->givenName),
            $this->text($data->patronymic),
            $data->gender,
            $data->birthDate,
            $data->deathDate,
            $this->createdAt,
        );
        $this->manager->persist($person);
        $this->events->synchronizePersonLifecycle($person, $this->createdAt);

        return $person;
    }

    private function text(?string $value): KnownText
    {
        return null === $value ? KnownText::unknown() : KnownText::known($value);
    }
}
