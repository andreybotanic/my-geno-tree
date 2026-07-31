<?php

namespace App\Request\Tree;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TreeNameRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'tree_name_required')]
        public string $name,
    ) {
    }
}
