<?php
declare(strict_types=1);

namespace Placard\Calendar;

interface CalendarProvider
{
    /**
     * Events overlapping [$from, $to), sorted by start time. Times are in $from's time zone.
     *
     * @return list<Event>
     * @throws CalendarException
     */
    public function events(string $calendarId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
