<?php
declare(strict_types=1);

namespace Placard\Display;

use Placard\Calendar\DayEvents;
use Placard\Calendar\Event;
use Placard\Room;

/** Turns a room, today's events and the current time into a Screen. */
final class ScreenBuilder
{
    public const MAX_ROWS = 5;

    public function __construct(private readonly TimeFormat $time)
    {
    }

    public function build(Room $room, DayEvents $day, \DateTimeImmutable $now): Screen
    {
        $dayStart = $now->setTime(0, 0);
        $dayEnd   = $dayStart->modify('+1 day');
        $events   = $day->events;

        [$kind, $label, $detail] = $this->status($room, $day, $now, $dayEnd);

        $emptyMessage = '';
        if (!$day->hasData()) {
            $emptyMessage = 'Calendar unavailable';
        } elseif ($events === []) {
            $emptyMessage = 'No events today';
        }

        $footer = $day->stale && $day->fetchedAt !== null
            ? 'Offline - data from ' . $this->time->time($day->fetchedAt)
            : 'Updated ' . $this->time->time($now);

        return new Screen(
            $room->name,
            $kind,
            $label,
            $detail,
            $now->format('l, F j'),
            $this->rows($events, $now, $dayStart, $dayEnd),
            $emptyMessage,
            $footer,
            $room->colorMode,
        );
    }

    /** @return array{0: string, 1: string, 2: string} kind, label, detail */
    private function status(Room $room, DayEvents $day, \DateTimeImmutable $now, \DateTimeImmutable $dayEnd): array
    {
        switch ($room->statusMode) {
            case 'available':
                return [Screen::AVAILABLE, 'AVAILABLE', ''];
            case 'occupied':
                return [Screen::OCCUPIED, 'OCCUPIED', ''];
            case 'custom':
                $label = trim($room->customLabel) !== '' ? trim($room->customLabel) : 'UNAVAILABLE';
                return [Screen::OTHER, $label, trim($room->customMessage)];
        }

        if (!$day->hasData()) {
            return [Screen::OTHER, 'UNKNOWN', 'Calendar unavailable'];
        }

        $busy = array_values(array_filter($day->events, static fn (Event $e) => $e->busy));

        $current = array_filter($busy, static fn (Event $e) => $e->start <= $now && $now < $e->end);
        if ($current !== []) {
            // Extend through events that overlap or run back to back with this one.
            $until = max(array_map(static fn (Event $e) => $e->end, $current));
            foreach ($busy as $e) {
                if ($e->start <= $until && $e->end > $until) {
                    $until = $e->end;
                }
            }
            $detail = $until >= $dayEnd
                ? 'In use for the rest of the day'
                : 'In use until ' . $this->time->time($until);
            return [Screen::OCCUPIED, 'OCCUPIED', $detail];
        }

        foreach ($busy as $e) {
            if ($e->start > $now) {
                return [Screen::AVAILABLE, 'AVAILABLE', 'Free until ' . $this->time->time($e->start)];
            }
        }
        return [Screen::AVAILABLE, 'AVAILABLE', 'Free for the rest of the day'];
    }

    /**
     * Picks what fits: upcoming events first, topped up with the most recent past ones.
     *
     * @param list<Event> $events
     * @return list<Row>
     */
    private function rows(array $events, \DateTimeImmutable $now, \DateTimeImmutable $dayStart, \DateTimeImmutable $dayEnd): array
    {
        $past = $remaining = [];
        foreach ($events as $e) {
            if ($e->end <= $now) {
                $past[] = $e;
            } else {
                $remaining[] = $e;
            }
        }

        $more = 0;
        if (count($remaining) > self::MAX_ROWS) {
            $more = count($remaining) - (self::MAX_ROWS - 1);
            $remaining = array_slice($remaining, 0, self::MAX_ROWS - 1);
        }
        $pastSlots = self::MAX_ROWS - count($remaining) - ($more > 0 ? 1 : 0);
        $pastShown = $pastSlots > 0 ? array_slice($past, -$pastSlots) : [];

        $rows = [];
        foreach ($pastShown as $e) {
            $rows[] = new Row($e->title, $this->label($e, $dayStart, $dayEnd), Row::PAST);
        }
        $nextMarked = false;
        foreach ($remaining as $e) {
            if ($e->start <= $now) {
                $state = Row::CURRENT;
            } elseif (!$nextMarked) {
                $state = Row::NEXT;
                $nextMarked = true;
            } else {
                $state = Row::UPCOMING;
            }
            $rows[] = new Row($e->title, $this->label($e, $dayStart, $dayEnd), $state);
        }
        if ($more > 0) {
            $rows[] = new Row("+$more more", '', Row::MORE);
        }
        return $rows;
    }

    private function label(Event $e, \DateTimeImmutable $dayStart, \DateTimeImmutable $dayEnd): string
    {
        $startsBefore = $e->start <= $dayStart;
        $endsAfter    = $e->end >= $dayEnd;

        return match (true) {
            $e->allDay, $startsBefore && $endsAfter => 'All day',
            $startsBefore                           => 'Until ' . $this->time->time($e->end),
            $endsAfter                              => 'From ' . $this->time->time($e->start),
            default                                 => $this->time->range($e->start, $e->end),
        };
    }
}
