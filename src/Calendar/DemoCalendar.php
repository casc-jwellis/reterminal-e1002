<?php
declare(strict_types=1);

namespace Placard\Calendar;

/** Sample events for trying the placard without a Google calendar. */
final class DemoCalendar implements CalendarProvider
{
    private const EVENTS = [
        ['08:00', '09:00', 'Team Standup'],
        ['09:30', '11:00', 'Advising Session - Smith'],
        ['13:00', '14:30', 'Curriculum Committee'],
        ['15:00', '16:00', 'Vendor Demo: Lab Equipment'],
        ['16:30', '17:30', 'Student Org Meeting'],
    ];

    public function events(string $calendarId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $events = [];
        foreach (self::EVENTS as [$start, $end, $title]) {
            [$sh, $sm] = array_map('intval', explode(':', $start));
            [$eh, $em] = array_map('intval', explode(':', $end));
            $events[] = new Event($title, $from->setTime($sh, $sm), $from->setTime($eh, $em));
        }
        return $events;
    }
}
