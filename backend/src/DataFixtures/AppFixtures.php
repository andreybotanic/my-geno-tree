<?php

namespace App\DataFixtures;

use App\Application\Event\AutomaticEventSynchronizer;
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
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixtures extends Fixture
{
    private const DEMO_PASSWORD = 'demo-password';

    public function __construct(
        private readonly AutomaticEventSynchronizer $events,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $createdAt = new \DateTimeImmutable('2026-08-01T00:00:00+00:00');
        $user = new User('demo', 'demo@example.test', '', 'ru', $createdAt);
        $user = new User(
            $user->getUsername(),
            $user->getEmail(),
            $this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD),
            $user->getPreferredLanguage(),
            $createdAt,
        );
        $tree = new FamilyTree($user, 'Семья Орловых', $createdAt);
        $manager->persist($user);
        $manager->persist($tree);

        // Четыре поколения: прародители, бабушки и дедушки, родители и дети.
        $grigoriy = $this->person($manager, $tree, 'Орлов', 'Григорий', 'Павлович', Gender::MALE, $this->year(1925), $this->year(1994), -1280, -720, $createdAt);
        $agafya = $this->person($manager, $tree, 'Кузнецова', 'Агафья', 'Ильинична', Gender::FEMALE, $this->year(1928), $this->year(2009), -960, -720, $createdAt);
        $fedor = $this->person($manager, $tree, 'Волков', 'Фёдор', 'Никитич', Gender::MALE, $this->year(1927), $this->year(1998), -640, -720, $createdAt);
        $vera = $this->person($manager, $tree, 'Волкова', 'Вера', null, Gender::FEMALE, $this->month(1931, 5), $this->year(2015), -320, -720, $createdAt);
        $nikolai = $this->person($manager, $tree, 'Савельев', 'Николай', 'Андреевич', Gender::MALE, $this->year(1930), $this->year(2004), 320, -720, $createdAt);
        $sofiaSenior = $this->person($manager, $tree, 'Белова', 'Софья', 'Михайловна', Gender::FEMALE, $this->day(1934, 8, 19), $this->year(2018), 640, -720, $createdAt);
        $semyon = $this->person($manager, $tree, 'Крылов', 'Семён', null, Gender::MALE, $this->year(1932), $this->year(2010), 960, -720, $createdAt);
        $zoya = $this->person($manager, $tree, 'Крылова', 'Зоя', 'Филипповна', Gender::FEMALE, $this->year(1936), null, 1280, -720, $createdAt);

        $aleksandr = $this->person($manager, $tree, 'Орлов', 'Александр', 'Григорьевич', Gender::MALE, $this->day(1954, 2, 11), $this->year(2020), -1280, -480, $createdAt);
        $tatiana = $this->person($manager, $tree, 'Орлова', 'Татьяна', null, Gender::FEMALE, $this->year(1958), null, -960, -480, $createdAt);
        $larisa = $this->person($manager, $tree, 'Волкова', 'Лариса', 'Фёдоровна', Gender::FEMALE, $this->month(1957, 11), null, -480, -480, $createdAt);
        $viktor = $this->person($manager, $tree, 'Савельев', 'Виктор', 'Николаевич', Gender::MALE, $this->year(1959), null, 480, -480, $createdAt);
        $nadezhda = $this->person($manager, $tree, 'Крылова', 'Надежда', 'Семёновна', Gender::FEMALE, $this->day(1962, 4, 3), null, 1120, -480, $createdAt);

        $ivan = $this->person($manager, $tree, 'Орлов', 'Иван', 'Александрович', Gender::MALE, $this->day(1980, 7, 2), null, -1040, -240, $createdAt);
        $olga = $this->person($manager, $tree, 'Орлова', 'Ольга', 'Александровна', Gender::FEMALE, $this->year(1984), null, -720, -240, $createdAt);
        $elena = $this->person($manager, $tree, 'Савельева', 'Елена', 'Викторовна', Gender::FEMALE, $this->month(1983, 9), null, 640, -240, $createdAt);
        $galina = $this->person($manager, $tree, 'Савельева', 'Галина', null, Gender::FEMALE, $this->year(1987), null, 960, -240, $createdAt);
        $pavel = $this->person($manager, $tree, 'Морозов', 'Павел', 'Сергеевич', Gender::MALE, $this->day(1981, 1, 28), null, -400, -240, $createdAt);
        $dmitry = $this->person($manager, $tree, null, 'Дмитрий', 'Владимирович', Gender::MALE, $this->year(1985), null, 1440, -240, $createdAt);

        $mikhail = $this->person($manager, $tree, 'Орлов', 'Михаил', 'Иванович', Gender::MALE, $this->day(2005, 3, 15), null, -520, 0, $createdAt);
        $anna = $this->person($manager, $tree, 'Орлова', 'Анна', 'Ивановна', Gender::FEMALE, $this->month(2008, 12), null, -200, 0, $createdAt);
        $petr = $this->person($manager, $tree, 'Орлов', null, null, Gender::MALE, GenealogicalDate::unknown(), null, 120, 0, $createdAt);
        $sofia = $this->person($manager, $tree, 'Морозова', 'София', 'Павловна', Gender::FEMALE, $this->day(2010, 6, 7), null, -720, 0, $createdAt);
        $roman = $this->person($manager, $tree, 'Морозов', 'Роман', null, Gender::MALE, $this->year(2014), null, -400, 0, $createdAt);
        $veraJunior = $this->person($manager, $tree, 'Соколова', 'Вера', 'Дмитриевна', Gender::FEMALE, $this->year(2016), null, 1120, 0, $createdAt);

        $this->marry($manager, $tree, $grigoriy, $agafya, $this->year(1949), $createdAt);
        $this->marry($manager, $tree, $fedor, $vera, $this->year(1953), $createdAt);
        $this->marry($manager, $tree, $nikolai, $sofiaSenior, $this->year(1956), $createdAt);
        $this->marry($manager, $tree, $semyon, $zoya, $this->year(1958), $createdAt);
        $this->marry($manager, $tree, $aleksandr, $larisa, $this->year(1978), $createdAt);
        $this->marry($manager, $tree, $viktor, $nadezhda, $this->year(1981), $createdAt);
        $this->marry($manager, $tree, $ivan, $elena, $this->year(2003), $createdAt);
        $this->marry($manager, $tree, $pavel, $olga, $this->year(2007), $createdAt);
        $this->marry($manager, $tree, $dmitry, $galina, $this->year(2012), $createdAt);

        $this->parents($manager, $tree, $grigoriy, $agafya, $aleksandr, $createdAt);
        $this->parents($manager, $tree, $grigoriy, $agafya, $tatiana, $createdAt);
        $this->parents($manager, $tree, $fedor, $vera, $larisa, $createdAt);
        $this->parents($manager, $tree, $nikolai, $sofiaSenior, $viktor, $createdAt);
        $this->parents($manager, $tree, $semyon, $zoya, $nadezhda, $createdAt);
        $this->parents($manager, $tree, $aleksandr, $larisa, $ivan, $createdAt);
        $this->parents($manager, $tree, $aleksandr, $larisa, $olga, $createdAt);
        $this->parents($manager, $tree, $viktor, $nadezhda, $elena, $createdAt);
        $this->parents($manager, $tree, $viktor, $nadezhda, $galina, $createdAt);
        $this->parents($manager, $tree, $ivan, $elena, $mikhail, $createdAt);
        $this->parents($manager, $tree, $ivan, $elena, $anna, $createdAt);
        $this->parents($manager, $tree, $ivan, $elena, $petr, $createdAt);
        $this->parents($manager, $tree, $pavel, $olga, $sofia, $createdAt);
        $this->parents($manager, $tree, $pavel, $olga, $roman, $createdAt);
        $this->parents($manager, $tree, $dmitry, $galina, $veraJunior, $createdAt);

        $manager->flush();
    }

    private function person(ObjectManager $manager, FamilyTree $tree, ?string $surname, ?string $givenName, ?string $patronymic, Gender $gender, GenealogicalDate $birthDate, ?GenealogicalDate $deathDate, float $x, float $y, \DateTimeImmutable $createdAt): Person
    {
        $person = new Person($tree, $this->text($surname), $this->text($givenName), $this->text($patronymic), $gender, $birthDate, $deathDate, $x, $y, $createdAt);
        $manager->persist($person);
        $this->events->synchronizePersonLifecycle($person, $createdAt);

        return $person;
    }

    private function marry(ObjectManager $manager, FamilyTree $tree, Person $male, Person $female, GenealogicalDate $date, \DateTimeImmutable $createdAt): void
    {
        $marriage = new MarriagePeriod($tree, $male, $female, $date, null, $createdAt);
        $manager->persist($marriage);
        $this->events->synchronizeMarriage($marriage, null, null, $createdAt);
    }

    private function parents(ObjectManager $manager, FamilyTree $tree, Person $father, Person $mother, Person $child, \DateTimeImmutable $createdAt): void
    {
        foreach ([
            new ParentLink($tree, $father, $child, ParentRole::FATHER),
            new ParentLink($tree, $mother, $child, ParentRole::MOTHER),
        ] as $link) {
            $manager->persist($link);
            $this->events->synchronizeParentLink($link, $createdAt);
        }
    }

    private function text(?string $value): KnownText
    {
        return null === $value ? KnownText::unknown() : KnownText::known($value);
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
