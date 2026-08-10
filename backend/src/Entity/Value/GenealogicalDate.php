<?php

namespace App\Entity\Value;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final class GenealogicalDate
{
    #[ORM\Column(enumType: KnowledgeState::class)]
    private KnowledgeState $state;

    #[ORM\Column(nullable: true, enumType: DateKind::class)]
    private ?DateKind $kind;

    #[ORM\Column(nullable: true)]
    private ?int $year;

    #[ORM\Column(nullable: true)]
    private ?int $month;

    #[ORM\Column(nullable: true)]
    private ?int $day;

    #[ORM\Column(nullable: true)]
    private ?int $endYear;

    #[ORM\Column(nullable: true)]
    private ?int $endMonth;

    #[ORM\Column(nullable: true)]
    private ?int $endDay;

    private function __construct(
        KnowledgeState $state,
        ?DateKind $kind,
        ?int $year,
        ?int $month,
        ?int $day,
        ?int $endYear,
        ?int $endMonth,
        ?int $endDay,
    ) {
        $this->state = $state;
        $this->kind = $kind;
        $this->year = $year;
        $this->month = $month;
        $this->day = $day;
        $this->endYear = $endYear;
        $this->endMonth = $endMonth;
        $this->endDay = $endDay;
    }

    public static function unknown(): self
    {
        return new self(
            KnowledgeState::UNKNOWN,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
        );
    }

    public static function known(
        DateKind $kind,
        int $year,
        ?int $month,
        ?int $day,
        ?int $endYear = null,
        ?int $endMonth = null,
        ?int $endDay = null,
    ): self {
        return new self(
            KnowledgeState::KNOWN,
            $kind,
            $year,
            $month,
            $day,
            $endYear,
            $endMonth,
            $endDay,
        );
    }

    public function isKnown(): bool
    {
        return KnowledgeState::KNOWN === $this->state;
    }

    public function getKind(): ?DateKind
    {
        return $this->kind;
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

    public function getEndYear(): ?int
    {
        return $this->endYear;
    }

    public function getEndMonth(): ?int
    {
        return $this->endMonth;
    }

    public function getEndDay(): ?int
    {
        return $this->endDay;
    }

    public function earliest(): ?\DateTimeImmutable
    {
        if (!$this->isKnown() || null === $this->kind || DateKind::APPROXIMATE === $this->kind) {
            return null;
        }

        return match ($this->kind) {
            DateKind::BEFORE                   => null,
            DateKind::AFTER                    => $this->last($this->year, $this->month, $this->day)?->modify('+1 day'),
            DateKind::EXACT, DateKind::BETWEEN => $this->first($this->year, $this->month, $this->day),
        };
    }

    public function latest(): ?\DateTimeImmutable
    {
        if (!$this->isKnown() || null === $this->kind || DateKind::APPROXIMATE === $this->kind) {
            return null;
        }

        return match ($this->kind) {
            DateKind::BEFORE  => $this->first($this->year, $this->month, $this->day)?->modify('-1 day'),
            DateKind::AFTER   => null,
            DateKind::EXACT   => $this->last($this->year, $this->month, $this->day),
            DateKind::BETWEEN => $this->last($this->endYear, $this->endMonth, $this->endDay),
        };
    }

    private function first(?int $year, ?int $month, ?int $day): ?\DateTimeImmutable
    {
        if (null === $year) {
            return null;
        }

        return new \DateTimeImmutable(sprintf(
            '%04d-%02d-%02d',
            $year,
            $month ?? 1,
            $day ?? 1,
        ));
    }

    private function last(?int $year, ?int $month, ?int $day): ?\DateTimeImmutable
    {
        $first = $this->first($year, $month, $day);
        if (null === $first) {
            return null;
        }

        if (null !== $day) {
            return $first;
        }

        return null === $month
            ? $first->setDate((int)$first->format('Y'), 12, 31)
            : $first->modify('last day of this month');
    }
}
