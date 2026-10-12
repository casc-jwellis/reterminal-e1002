<?php
declare(strict_types=1);

namespace Placard\Display;

/** One line of the schedule list. */
final class Row
{
    public const PAST = 'past';
    public const CURRENT = 'current';
    public const NEXT = 'next';
    public const UPCOMING = 'upcoming';
    public const MORE = 'more';

    public function __construct(
        public readonly string $title,
        public readonly string $time,
        public readonly string $state,
    ) {
    }
}
