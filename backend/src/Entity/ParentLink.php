<?php

namespace App\Entity;

use App\Entity\Value\ParentRole;
use App\Repository\ParentLinkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ParentLinkRepository::class)]
#[ORM\UniqueConstraint(
    name: 'uniq_parent_link_child_role',
    columns: ['child_id', 'role'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_parent_link_pair',
    columns: ['parent_id', 'child_id'],
)]
#[ORM\Index(columns: ['tree_id'], name: 'idx_parent_link_tree')]
class ParentLink
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FamilyTree $tree;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Person $parent;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Person $child;

    #[ORM\Column(enumType: ParentRole::class)]
    private ParentRole $role;

    public function __construct(
        FamilyTree $tree,
        Person $parent,
        Person $child,
        ParentRole $role,
    ) {
        $this->id = Uuid::v7();
        $this->tree = $tree;
        $this->parent = $parent;
        $this->child = $child;
        $this->role = $role;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTree(): FamilyTree
    {
        return $this->tree;
    }

    public function getParent(): Person
    {
        return $this->parent;
    }

    public function getChild(): Person
    {
        return $this->child;
    }

    public function getRole(): ParentRole
    {
        return $this->role;
    }
}
