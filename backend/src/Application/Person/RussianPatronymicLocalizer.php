<?php

namespace App\Application\Person;

use App\Entity\Value\Gender;

final class RussianPatronymicLocalizer implements LocalizedPatronymicLocalizer
{
    /**
     * @var array<string, array{male: string, female: string}>
     */
    private const array EXCEPTIONS = [
        'илья'   => ['male' => 'Ильич', 'female' => 'Ильинична'],
        'кузьма' => ['male' => 'Кузьмич', 'female' => 'Кузьминична'],
        'лев'    => ['male' => 'Львович', 'female' => 'Львовна'],
        'лука'   => ['male' => 'Лукич', 'female' => 'Лукинична'],
        'никита' => ['male' => 'Никитич', 'female' => 'Никитична'],
        'павел'  => ['male' => 'Павлович', 'female' => 'Павловна'],
        'петр'   => ['male' => 'Петрович', 'female' => 'Петровна'],
        'савва'  => ['male' => 'Саввич', 'female' => 'Саввична'],
        'фома'   => ['male' => 'Фомич', 'female' => 'Фоминична'],
        'яков'   => ['male' => 'Яковлевич', 'female' => 'Яковлевна'],
    ];

    public function supports(string $locale): bool
    {
        return 'ru' === $locale;
    }

    public function determine(string $fatherGivenName, Gender $childGender): ?string
    {
        if (Gender::UNKNOWN === $childGender) {
            return null;
        }

        $name = mb_strtolower(trim($fatherGivenName));
        if (!preg_match('/^\\p{Cyrillic}+$/u', $name)) {
            return null;
        }

        $gender = Gender::MALE === $childGender ? 'male' : 'female';
        if (isset(self::EXCEPTIONS[$name])) {
            return self::EXCEPTIONS[$name][$gender];
        }

        if (str_ends_with($name, 'й') || str_ends_with($name, 'ь')) {
            $stem = mb_substr($name, 0, -1);

            return $this->title($stem . ('male' === $gender ? 'евич' : 'евна'));
        }

        if (str_ends_with($name, 'а') || str_ends_with($name, 'я')) {
            $stem = mb_substr($name, 0, -1);

            return $this->title($stem . ('male' === $gender ? 'ич' : 'ична'));
        }

        return $this->title($name . ('male' === $gender ? 'ович' : 'овна'));
    }

    private function title(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
    }
}
