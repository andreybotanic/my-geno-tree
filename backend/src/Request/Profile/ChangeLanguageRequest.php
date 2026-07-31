<?php

namespace App\Request\Profile;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChangeLanguageRequest
{
    public function __construct(
        #[Assert\Choice(choices: ['en', 'ru'], message: 'language_invalid')]
        public string $language,
    ) {
    }
}
