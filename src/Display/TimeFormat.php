<?php
declare(strict_types=1);

namespace Placard\Display;

final class TimeFormat
{
    public function __construct(private readonly bool $twentyFourHour = false)
    {
    }

    public static function fromSetting(string $value): self
    {
        return new self($value === '24');
    }

    public function time(\DateTimeInterface $t): string
    {
        return $this->twentyFourHour ? $t->format('H:i') : $t->format('g:i A');
    }

    /** "8:00 - 9:00 AM" / "11:30 AM - 1:00 PM" / "08:00 - 09:00". */
    public function range(\DateTimeInterface $start, \DateTimeInterface $end): string
    {
        if ($this->twentyFourHour) {
            return $start->format('H:i') . ' - ' . $end->format('H:i');
        }
        $from = $start->format('A') === $end->format('A') ? $start->format('g:i') : $start->format('g:i A');
        return $from . ' - ' . $end->format('g:i A');
    }
}
