<?php

namespace App\Application\Person;

use App\Entity\Value\Gender;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class SurnameLocalizationService
{
    /**
     * @param iterable<LocalizedSurnameLocalizer> $localizers
     */
    public function __construct(
        #[AutowireIterator(LocalizedSurnameLocalizer::class)]
        private iterable $localizers,
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
}
