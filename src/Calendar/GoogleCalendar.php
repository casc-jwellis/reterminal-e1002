<?php
declare(strict_types=1);

namespace Placard\Calendar;

use Placard\Http;

/** Reads a public calendar through the Google Calendar API v3 using an API key. */
final class GoogleCalendar implements CalendarProvider
{
    private const ENDPOINT = 'https://www.googleapis.com/calendar/v3/calendars/%s/events';

    public function __construct(private readonly string $apiKey)
    {
    }

    public function events(string $calendarId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($this->apiKey === '') {
            throw new CalendarException('No Google API key is configured (Settings page).');
        }

        $url = sprintf(self::ENDPOINT, rawurlencode($calendarId)) . '?' . http_build_query([
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
            'timeMin'      => $from->format('c'),
            'timeMax'      => $to->format('c'),
            'timeZone'     => $from->getTimezone()->getName(),
            'maxResults'   => 100,
            'fields'       => 'items(summary,status,transparency,start,end)',
        ]);

        try {
            // The key goes in a header so it never appears in URLs or access logs.
            [$status, $body] = Http::get($url, ['X-Goog-Api-Key: ' . $this->apiKey]);
        } catch (\RuntimeException $e) {
            throw new CalendarException($e->getMessage(), 0, $e);
        }

        $json = json_decode($body, true);
        if ($status !== 200) {
            throw new CalendarException(self::describeError($status, is_array($json) ? $json : []));
        }
        if (!is_array($json)) {
            throw new CalendarException('Google returned an unreadable response.');
        }

        return self::parse($json, $from->getTimezone());
    }

    /**
     * Converts an events.list response into events.
     *
     * @return list<Event>
     */
    public static function parse(array $json, \DateTimeZone $zone): array
    {
        $events = [];
        foreach ((array) ($json['items'] ?? []) as $item) {
            if (!is_array($item) || ($item['status'] ?? '') === 'cancelled') {
                continue;
            }
            $start = $item['start'] ?? null;
            $end   = $item['end'] ?? null;
            if (!is_array($start) || !is_array($end)) {
                continue;
            }

            try {
                if (isset($start['dateTime'], $end['dateTime'])) {
                    $s = (new \DateTimeImmutable($start['dateTime']))->setTimezone($zone);
                    $e = (new \DateTimeImmutable($end['dateTime']))->setTimezone($zone);
                    $allDay = false;
                } elseif (isset($start['date'], $end['date'])) {
                    // All-day events carry dates only; the end date is exclusive.
                    $s = new \DateTimeImmutable($start['date'] . ' 00:00:00', $zone);
                    $e = new \DateTimeImmutable($end['date'] . ' 00:00:00', $zone);
                    $allDay = true;
                } else {
                    continue;
                }
            } catch (\Exception) {
                continue;
            }

            $events[] = new Event(
                self::cleanTitle((string) ($item['summary'] ?? '')),
                $s,
                $e,
                $allDay,
                ($item['transparency'] ?? 'opaque') !== 'transparent',
            );
        }

        usort($events, static fn (Event $a, Event $b) => [$a->start, $a->end] <=> [$b->start, $b->end]);
        return $events;
    }

    private static function cleanTitle(string $title): string
    {
        $title = trim(preg_replace('/[\x00-\x1f\x7f\s]+/u', ' ', $title) ?? '');
        return $title !== '' ? $title : 'Busy';
    }

    /** A readable explanation for a non-200 API response. */
    public static function describeError(int $status, array $json): string
    {
        $reason  = (string) ($json['error']['errors'][0]['reason'] ?? '');
        $message = (string) ($json['error']['message'] ?? '');

        return match (true) {
            $reason === 'keyInvalid', $status === 400 && str_contains($message, 'API key')
                => 'Google rejected the API key. Check it on the Settings page.',
            $reason === 'accessNotConfigured', str_contains($message, 'has not been used')
                => 'The Google Calendar API is not enabled for this API key\'s project.',
            $status === 404, $reason === 'notFound'
                => 'Calendar not found. Check the calendar ID.',
            $status === 403 && in_array($reason, ['rateLimitExceeded', 'quotaExceeded', 'dailyLimitExceeded', 'userRateLimitExceeded'], true)
                => 'Google API quota exceeded. Try again later.',
            $status === 403
                => 'Access denied. Make sure the calendar is public with "See all event details", and that the API key is allowed to use the Calendar API.',
            default
                => "Google API error (HTTP $status)" . ($message !== '' ? ': ' . $message : '.'),
        };
    }
}
