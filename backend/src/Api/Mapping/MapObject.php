<?php

namespace App\Api\Mapping;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\ObjectMapper\ObjectMapperAwareInterface;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\ObjectMapper\TransformCallableInterface;

/** Explicit nested output mapping keeps API metadata out of domain entities.
 * @implements TransformCallableInterface<object, object>
 */
#[Exclude]
final class MapObject implements TransformCallableInterface, ObjectMapperAwareInterface
{
    /** @param class-string $targetClass */
    public function __construct(private readonly string $targetClass, private ?ObjectMapperInterface $mapper = null)
    {
    }

    public function withObjectMapper(ObjectMapperInterface $objectMapper): static
    {
        $clone = clone $this;
        $clone->mapper = $objectMapper;

        return $clone;
    }

    public function __invoke(mixed $value, object $source, ?object $target): ?object
    {
        if (null === $value) {
            return null;
        }
        if (!is_object($value) || null === $this->mapper) {
            throw new \LogicException('Nested output mapping requires an object and the configured ObjectMapper.');
        }

        return $this->mapper->map($value, $this->targetClass);
    }
}
