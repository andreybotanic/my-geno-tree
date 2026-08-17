<?php

namespace App\Request\Person;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class LocalizeSurnameRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'surname_required')]
        #[Assert\Length(max: 100, maxMessage: 'surname_too_long')]
        public string $surname,
        #[Assert\Choice(choices: ['male', 'female'], message: 'gender_invalid')]
        public string $sourceGender,
        #[Assert\Choice(choices: ['male', 'female'], message: 'gender_invalid')]
        public string $targetGender,
    ) {
    }
}
