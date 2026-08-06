<?php

namespace App\Application\TreeExchange;

final class GedcomNode
{
    /** @var list<self> */
    public array $children = [];

    public function __construct(
        public readonly int $level,
        public readonly ?string $xref,
        public readonly string $tag,
        public readonly string $value,
    ) {
    }

    public function child(string $tag): ?self
    {
        foreach ($this->children as $child) {
            if ($tag === $child->tag) {
                return $child;
            }
        }

        return null;
    }

    /** @return list<self> */
    public function children(string $tag): array
    {
        return array_values(array_filter(
            $this->children,
            static fn (self $child): bool => $tag === $child->tag,
        ));
    }
}
