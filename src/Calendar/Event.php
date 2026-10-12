<?php
declare(strict_types=1);

namespace Placard\Calendar;

final class Event
{
    public function __construct(
        public readonly string $title,
        public readonly \DateTimeImmutable $start,
        public readonly \DateTimeImmutable $end,
        public readonly bool $allDay = false,
        /** False for events marked "free" (they are shown, but do not make the room occupied). */
        public readonly bool $busy = true,
    ) {
    }

    public function toArray(): array
    {
        return [
            'title'   => $this->title,
            'start'   => $this->start->format('c'),
            'end'     => $this->end->format('c'),
            'all_day' => $this->allDay,
            'busy'    => $this->busy,
        ];
    }

    public static function fromArray(array $a, \DateTimeZone $zone): self
    {
        return new self(
            (string) $a['title'],
            (new \DateTimeImmutable((string) $a['start']))->setTimezone($zone),
            (new \DateTimeImmutable((string) $a['end']))->setTimezone($zone),
            (bool) ($a['all_day'] ?? false),
            (bool) ($a['busy'] ?? true),
        );
    }
}
