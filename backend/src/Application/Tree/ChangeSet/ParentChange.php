<?php

namespace App\Application\Tree\ChangeSet;

use App\Entity\Value\ParentRole;

final readonly class ParentChange
{
    public function __construct(public string $parent, public string $child, public ParentRole $role)
    {
    }
}
