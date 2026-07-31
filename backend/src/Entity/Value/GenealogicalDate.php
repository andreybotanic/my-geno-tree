<?php

namespace App\Entity\Value;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final class GenealogicalDate
{
    #[ORM\Column(enumType: KnowledgeState::class)]
    private KnowledgeState $state;

    #[ORM\Column(nullable: true, enumType: DatePrecision::class)]
    private ?DatePrecision $precision;

    #[ORM\Column(nullable: true)]
    private ?int $year;

    #[ORM\Column(nullable: true)]
    private ?int $month;

    #[ORM\Column(nullable: true)]
    private ?int $day;

    #[ORM\Column]
    private bool $approximate;

    private function __construct(
        KnowledgeState $state,
        ?DatePrecision $precision,
        ?int $year,
        ?int $month,
        ?int $day,
        bool $approximate,
    ) {
        $this->state = $state;
        $this->precision = $precision;
        $this->year = $year;
        $this->month = $month;
        $this->day = $day;
        $this->approximate = $approximate;
    }

    public static function unknown(): self
    {
        return new self(
            KnowledgeState::UNKNOWN,
            null,
            null,
            null,
            null,
            false,
        );
    }

    public static function known(
        DatePrecision $precision,
        int $year,
        ?int $month,
        ?int $day,
        bool $approximate,
    ): self {
        return new self(
            KnowledgeState::KNOWN,
            $precision,
            $year,
            $month,
            $day,
            $approximate,
        );
    }

    public function isKnown(): bool
    {
        return KnowledgeState::KNOWN === $this->state;
    }

    public function getPrecision(): ?DatePrecision
    {
        return $this->precision;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function getMonth(): ?int
    {
        return $this->month;
    }

    public function getDay(): ?int
    {
        return $this->day;
    }

    public function isApproximate(): bool
    {
        return $this->approximate;
    }

    public function earliest(): ?DateTimeImmutable
    {
        if (!$this->isComparable()) {
            return null;
        }

        return match ($this->precision) {
            DatePrecision::YEAR => new DateTimeImmutable(
                sprintf('%04d-01-01', $this->year),
            ),
            DatePrecision::MONTH => new DateTimeImmutable(
                sprintf('%04d-%02d-01', $this->year, $this->month),
            ),
            DatePrecision::DAY => new DateTimeImmutable(sprintf(
                '%04d-%02d-%02d',
                $this->year,
                $this->month,
                $this->day,
            )),
            null => null,
        };
    }

    public function latest(): ?DateTimeImmutable
    {
        $earliest = $this->earliest();
        if (null === $earliest) {
            return null;
        }

        return match ($this->precision) {
            DatePrecision::YEAR => $earliest->setDate(
                (int) $earliest->format('Y'),
                12,
                31,
            ),
            DatePrecision::MONTH => $earliest->modify('last day of this month'),
            DatePrecision::DAY => $earliest,
            null => null,
        };
    }

    private function isComparable(): bool
    {
        return $this->isKnown() && !$this->approximate;
    }
}
