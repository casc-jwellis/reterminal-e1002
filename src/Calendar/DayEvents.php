<?php
declare(strict_types=1);

namespace Placard\Calendar;

/** Today's events for a room, plus how fresh they are. */
final class DayEvents
{
    /**
     * @param list<Event> $events
     * @param ?\DateTimeImmutable $fetchedAt when the data was read from the calendar; null if there is no data
     * @param bool $stale true when the data is old because the latest fetch failed
     * @param ?string $error why the latest fetch failed
     */
    public function __construct(
        public readonly array $events,
        public readonly ?\DateTimeImmutable $fetchedAt,
        public readonly bool $stale = false,
        public readonly ?string $error = null,
    ) {
    }

    public function hasData(): bool
    {
        return $this->fetchedAt !== null;
    }
}
