<?php

namespace App\Application\Person;

use App\Entity\Value\Gender;

final class SurnameLocalizer
{
    public function localize(
        string $surname,
        Gender $sourceGender,
        Gender $targetGender,
        string $locale,
    ): string {
        if ($sourceGender === $targetGender || 'ru' !== $locale) {
            return $surname;
        }

        return $this->russianGenderForm($surname, $sourceGender, $targetGender);
    }

    private function russianGenderForm(
        string $surname,
        Gender $sourceGender,
        Gender $targetGender,
    ): string {
        $parts = [];
        foreach (explode('-', $surname) as $part) {
            $parts[] = Gender::FEMALE === $targetGender
                ? $this->toRussianFemaleForm($part)
                : $this->toRussianMaleForm($part);
        }

        return implode('-', $parts);
    }

    private function toRussianFemaleForm(string $surname): string
    {
        return $this->replaceEnding($surname, [
            'ский' => 'ская',
            'цкий' => 'цкая',
            'ой'   => 'ая',
            'ый'   => 'ая',
            'ий'   => 'яя',
            'ов'   => 'ова',
            'ев'   => 'ева',
            'ёв'   => 'ёва',
            'ин'   => 'ина',
            'ын'   => 'ына',
        ]);
    }

    private function toRussianMaleForm(string $surname): string
    {
        return $this->replaceEnding($surname, [
            'ская' => 'ский',
            'цкая' => 'цкий',
            'яя'   => 'ий',
            'ова'  => 'ов',
            'ева'  => 'ев',
            'ёва'  => 'ёв',
            'ина'  => 'ин',
            'ына'  => 'ын',
            'ая'   => 'ый',
        ]);
    }

    /**
     * @param array<string, string> $replacements
     */
    private function replaceEnding(string $surname, array $replacements): string
    {
        $lowercase = mb_strtolower($surname);
        foreach ($replacements as $ending => $replacement) {
            if (!str_ends_with($lowercase, $ending)) {
                continue;
            }

            $stem = mb_substr($surname, 0, -mb_strlen($ending));

            return $stem . $this->preserveEndingCase($ending, $replacement, $surname);
        }

        return $surname;
    }

    private function preserveEndingCase(
        string $sourceEnding,
        string $replacement,
        string $surname,
    ): string {
        $source = mb_substr($surname, -mb_strlen($sourceEnding));

        return mb_strtoupper($source) === $source
            ? mb_strtoupper($replacement)
            : $replacement;
    }
}
