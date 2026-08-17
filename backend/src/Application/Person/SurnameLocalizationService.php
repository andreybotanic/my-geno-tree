<?php

namespace App\Application\Person;

use App\Entity\Value\Gender;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class SurnameLocalizationService
{
    /**
     * @param iterable<LocalizedSurnameLocalizer>    $localizers
     * @param iterable<LocalizedPatronymicLocalizer> $patronymicLocalizers
     */
    public function __construct(
        #[AutowireIterator(LocalizedSurnameLocalizer::class)]
        private iterable $localizers,
        #[AutowireIterator(LocalizedPatronymicLocalizer::class)]
        private iterable $patronymicLocalizers,
    ) {
    }

    public function localize(
        string $surname,
        Gender $sourceGender,
        Gender $targetGender,
        string $locale,
    ): string {
        foreach ($this->localizers as $localizer) {
            if ($localizer->supports($locale)) {
                return $localizer->localize($surname, $sourceGender, $targetGender);
            }
        }

        return $surname;
    }

    public function determinePatronymic(
        string $fatherGivenName,
        Gender $childGender,
        string $locale,
    ): ?string {
        foreach ($this->patronymicLocalizers as $localizer) {
            if ($localizer->supports($locale)) {
                return $localizer->determine($fatherGivenName, $childGender);
            }
        }

        return null;
    }
}
