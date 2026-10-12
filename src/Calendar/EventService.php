<?php
declare(strict_types=1);

namespace Placard\Calendar;

use Placard\Paths;
use Placard\Room;

/**
 * Loads a room's events for the current day, caching them on disk. If the calendar
 * cannot be reached, the last good data is used (up to MAX_STALE seconds old).
 */
final class EventService
{
    private const MAX_STALE = 6 * 3600;
    private const RETRY_DELAY = 30;

    private readonly string $cacheDir;

    public function __construct(
        private readonly array $settings,
        private readonly ?CalendarProvider $provider = null,
        ?string $cacheDir = null,
    ) {
        $this->cacheDir = $cacheDir ?? Paths::cache();
    }

    public function forToday(Room $room, \DateTimeImmutable $now, bool $bypassCache = false): DayEvents
    {
        $zone = $now->getTimezone();
        $from = $now->setTime(0, 0);
        $to   = $from->modify('+1 day');

        if ($room->calendarId === CalendarId::DEMO) {
            return new DayEvents((new DemoCalendar())->events($room->calendarId, $from, $to), $now);
        }
        if ($room->calendarId === '') {
            return new DayEvents([], null, false, 'No calendar ID is set for this room.');
        }

        $ttl  = max(30, min(3600, (int) ($this->settings['cache_ttl'] ?? 120)));
        $file = $this->cacheDir . '/events-' . sha1($room->calendarId . '|' . $zone->getName() . '|' . $from->format('Y-m-d')) . '.json';
        $cached = $this->readCache($file, $zone);

        if (!$bypassCache && $cached !== null) {
            $age = $now->getTimestamp() - $cached['fetched_at'];
            if ($age < $ttl) {
                return new DayEvents($cached['events'], (new \DateTimeImmutable('@' . $cached['fetched_at']))->setTimezone($zone));
            }
            if ($cached['retry_after'] > $now->getTimestamp()) {
                return $this->staleResult($cached, $zone, $cached['error']);
            }
        }

        try {
            $provider = $this->provider ?? new GoogleCalendar((string) ($this->settings['google_api_key'] ?? ''));
            $events = $provider->events($room->calendarId, $from, $to);
        } catch (CalendarException $e) {
            if ($cached !== null && $now->getTimestamp() - $cached['fetched_at'] < self::MAX_STALE) {
                $this->writeCache($file, $cached['fetched_at'], $cached['events'], $now->getTimestamp() + self::RETRY_DELAY, $e->getMessage());
                return $this->staleResult($cached, $zone, $e->getMessage());
            }
            return new DayEvents([], null, false, $e->getMessage());
        }

        $this->writeCache($file, $now->getTimestamp(), $events, 0, '');
        $this->pruneCache();
        return new DayEvents($events, $now);
    }

    private function staleResult(array $cached, \DateTimeZone $zone, string $error): DayEvents
    {
        return new DayEvents(
            $cached['events'],
            (new \DateTimeImmutable('@' . $cached['fetched_at']))->setTimezone($zone),
            true,
            $error,
        );
    }

    /** @return ?array{fetched_at: int, retry_after: int, error: string, events: list<Event>} */
    private function readCache(string $file, \DateTimeZone $zone): ?array
    {
        $json = is_file($file) ? @file_get_contents($file) : false;
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data) || !isset($data['fetched_at'], $data['events']) || !is_array($data['events'])) {
            return null;
        }
        try {
            return [
                'fetched_at'  => (int) $data['fetched_at'],
                'retry_after' => (int) ($data['retry_after'] ?? 0),
                'error'       => (string) ($data['error'] ?? ''),
                'events'      => array_map(static fn (array $e) => Event::fromArray($e, $zone), $data['events']),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<Event> $events */
    private function writeCache(string $file, int $fetchedAt, array $events, int $retryAfter, string $error): void
    {
        Paths::writeAtomic($file, json_encode([
            'fetched_at'  => $fetchedAt,
            'retry_after' => $retryAfter,
            'error'       => $error,
            'events'      => array_map(static fn (Event $e) => $e->toArray(), $events),
        ], JSON_THROW_ON_ERROR));
    }

    /** Occasionally remove cache files for past days. */
    private function pruneCache(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }
        foreach (glob($this->cacheDir . '/events-*.json') ?: [] as $f) {
            if (filemtime($f) < time() - 2 * 86400) {
                @unlink($f);
            }
        }
    }
}
