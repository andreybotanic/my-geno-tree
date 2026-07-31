<?php

namespace App\Request\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class LoginRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'identifier_required')]
        public string $identifier,
        #[Assert\NotBlank(message: 'password_required')]
        public string $password,
    ) {
    }
}
