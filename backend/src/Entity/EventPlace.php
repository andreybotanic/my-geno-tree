<?php

namespace App\Entity;

use App\Repository\EventPlaceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EventPlaceRepository::class)]
class EventPlace
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\Column(length: 500)]
    private string $name;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $latitude;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $longitude;

    private function __construct(
        string $name,
        ?float $latitude,
        ?float $longitude,
    ) {
        $this->id = Uuid::v7();
        $this->name = $this->normalizeName($name);
        $this->setCoordinates($latitude, $longitude);
    }

    public static function resolved(string $name, float $latitude, float $longitude): self
    {
        return new self($name, $latitude, $longitude);
    }

    public static function imported(string $name, ?float $latitude, ?float $longitude): self
    {
        return new self($name, $latitude, $longitude);
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLatitude(): ?float
    {
        return null === $this->latitude ? null : (float)$this->latitude;
    }

    public function getLongitude(): ?float
    {
        return null === $this->longitude ? null : (float)$this->longitude;
    }

    public function isResolved(): bool
    {
        return null !== $this->latitude;
    }

    public function resolve(string $name, float $latitude, float $longitude): void
    {
        $this->name = $this->normalizeName($name);
        $this->setCoordinates($latitude, $longitude);
    }

    private function normalizeName(string $name): string
    {
        $normalized = trim($name);
        if ('' === $normalized || mb_strlen($normalized) > 500) {
            throw new \InvalidArgumentException('Place name must contain between 1 and 500 characters.');
        }

        return $normalized;
    }

    private function setCoordinates(?float $latitude, ?float $longitude): void
    {
        if ((null === $latitude) !== (null === $longitude)) {
            throw new \InvalidArgumentException('Place coordinates must be provided together.');
        }
        if (null !== $latitude && ($latitude < -90 || $latitude > 90)) {
            throw new \InvalidArgumentException('Latitude is outside WGS84 bounds.');
        }
        if (null !== $longitude && ($longitude < -180 || $longitude > 180)) {
            throw new \InvalidArgumentException('Longitude is outside WGS84 bounds.');
        }

        $this->latitude = null === $latitude ? null : number_format($latitude, 7, '.', '');
        $this->longitude = null === $longitude ? null : number_format($longitude, 7, '.', '');
    }
}
