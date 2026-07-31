<?php

namespace App\Request\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'username_required')]
        public string $username,
        #[Assert\NotBlank(message: 'email_required')]
        public string $email,
        #[Assert\NotBlank(message: 'password_required')]
        public string $password,
        #[Assert\Choice(choices: ['en', 'ru'], message: 'language_invalid')]
        public string $language,
    ) {
    }
}
