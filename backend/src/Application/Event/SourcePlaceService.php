<?php

namespace App\Application\Event;

use App\Entity\EventPlace;
use Doctrine\ORM\EntityManagerInterface;

/** Updates an event source's canonical place inside the caller's transaction. */
final readonly class SourcePlaceService
{
    public function __construct(private EventPlaceDataPolicy $data, private EntityManagerInterface $em)
    {
    }

    public function update(?EventPlace $current, mixed $value, string $field): ?EventPlace
    {
        if (null === $value) {
            if (null !== $current) {
                $this->em->remove($current);
            }

            return null;
        }
        $data = $this->data->resolved($value, $field);
        $place = $current ?? EventPlace::resolved($data->name, $data->latitude, $data->longitude);
        $place->resolve($data->name, $data->latitude, $data->longitude);
        $this->em->persist($place);

        return $place;
    }
}
