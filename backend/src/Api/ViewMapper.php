<?php

namespace App\Api;

use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/** Maps declared output DTOs and preserves the API's normalized array contracts. */
final readonly class ViewMapper
{
    public function __construct(private ObjectMapperInterface $mapper, private NormalizerInterface $normalizer)
    {
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|T $target
     *
     * @return array<string, mixed>
     */
    public function one(object $source, string|object $target): array
    {
        $data = $this->normalizer->normalize(
            $this->mapper->map($source, $target),
            'json',
            [AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true],
        );
        if (!is_array($data)) {
            throw new \LogicException('An output DTO must normalize to an object.');
        }

        /* @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param iterable<object> $sources
     * @param class-string     $target
     *
     * @return list<array<string, mixed>>
     */
    public function many(iterable $sources, string $target): array
    {
        $views = [];
        foreach ($sources as $source) {
            $views[] = $this->one($source, $target);
        }

        return $views;
    }
}
