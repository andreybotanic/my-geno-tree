<?php

namespace App\Request\Person;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class LocalizePatronymicRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'father_given_name_required')]
        #[Assert\Length(max: 100, maxMessage: 'father_given_name_too_long')]
        public string $fatherGivenName,
        #[Assert\Choice(choices: ['male', 'female'], message: 'gender_invalid')]
        public string $childGender,
    ) {
    }
}
