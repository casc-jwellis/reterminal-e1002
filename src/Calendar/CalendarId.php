<?php
declare(strict_types=1);

namespace Placard\Calendar;

final class CalendarId
{
    /** The calendar ID that selects the built-in sample events instead of Google. */
    public const DEMO = 'demo';

    /**
     * Accepts a bare calendar ID, or pulls it out of a pasted iCal / embed / share link.
     */
    public static function normalize(string $input): string
    {
        $input = trim($input);

        if (preg_match('~calendar\.google\.com/calendar/ical/([^/]+)/~i', $input, $m)) {
            return rawurldecode($m[1]);
        }
        if (preg_match('~calendar\.google\.com/.*[?&]src=([^&#]+)~i', $input, $m)) {
            return rawurldecode($m[1]);
        }
        if (preg_match('~calendar\.google\.com/.*[?&]cid=([^&#]+)~i', $input, $m)) {
            $decoded = base64_decode(rawurldecode($m[1]), true);
            if ($decoded !== false && self::isValid($decoded)) {
                return $decoded;
            }
        }
        return $input;
    }

    public static function isValid(string $id): bool
    {
        // Real IDs can contain characters such as "#" (holiday calendars); they are URL-encoded when used.
        return $id !== '' && strlen($id) <= 255 && preg_match('~^[^\s\x00-\x1f\x7f]+$~', $id) === 1;
    }
}
