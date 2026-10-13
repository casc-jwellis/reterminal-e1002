<?php
declare(strict_types=1);

// Dependency-free test runner:  php -d extension=gd tests/run.php

require __DIR__ . '/../src/bootstrap.php';

use Placard\Admin\AlreadyConfigured;
use Placard\Admin\LoginThrottle;
use Placard\Admin\Setup;
use Placard\Calendar\CalendarException;
use Placard\Calendar\CalendarId;
use Placard\Calendar\CalendarProvider;
use Placard\Calendar\DayEvents;
use Placard\Calendar\Event;
use Placard\Calendar\EventService;
use Placard\Calendar\GoogleCalendar;
use Placard\Config;
use Placard\Display\Renderer;
use Placard\Display\Row;
use Placard\Display\Screen;
use Placard\Display\ScreenBuilder;
use Placard\Display\Theme;
use Placard\Display\TimeFormat;
use Placard\Room;

$total = 0;
$failed = 0;

function same(string $name, mixed $expected, mixed $actual): void
{
    global $total, $failed;
    $total++;
    if ($expected !== $actual) {
        $failed++;
        echo "FAIL  $name\n      expected: " . var_export($expected, true) . "\n      actual:   " . var_export($actual, true) . "\n";
    }
}

function ok(string $name, bool $condition): void
{
    same($name, true, $condition);
}

function tempDir(): string
{
    $dir = sys_get_temp_dir() . '/placard-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    return $dir;
}

function removeDir(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $f) {
        is_dir($f) ? removeDir($f) : unlink($f);
    }
    foreach (glob($dir . '/.*') ?: [] as $f) {
        if (is_file($f)) {
            unlink($f);
        }
    }
    @rmdir($dir);
}

final class FakeProvider implements CalendarProvider
{
    public int $calls = 0;
    public ?string $failWith = null;
    /** @var list<Event> */
    public array $events = [];

    public function events(string $calendarId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->calls++;
        if ($this->failWith !== null) {
            throw new CalendarException($this->failWith);
        }
        return $this->events;
    }
}

$tz  = new DateTimeZone('America/Chicago');
$day = fn (string $time) => new DateTimeImmutable("2026-10-12 $time", $tz);
$ev  = fn (string $title, string $from, string $to, bool $busy = true) => new Event($title, $day($from), $day($to), false, $busy);
$room = fn (array $over = []) => Room::fromArray('b', $over + ['name' => 'Room B', 'calendar_id' => 'x@y', 'color_mode' => 'bwr']);

// ---- CalendarId -------------------------------------------------------
same('id: bare id unchanged', 'abc@group.calendar.google.com', CalendarId::normalize(' abc@group.calendar.google.com '));
same('id: from ical url', 'abc@group.calendar.google.com', CalendarId::normalize('https://calendar.google.com/calendar/ical/abc%40group.calendar.google.com/public/basic.ics'));
same('id: from embed url', 'abc@group.calendar.google.com', CalendarId::normalize('https://calendar.google.com/calendar/embed?src=abc%40group.calendar.google.com&ctz=UTC'));
same('id: from cid link', 'abc@group.calendar.google.com', CalendarId::normalize('https://calendar.google.com/calendar/u/0?cid=' . rawurlencode(base64_encode('abc@group.calendar.google.com'))));
ok('id: accepts # (holiday calendars)', CalendarId::isValid('en.usa#holiday@group.v.calendar.google.com'));
ok('id: valid email', CalendarId::isValid('someone@gmail.com'));
ok('id: rejects empty', !CalendarId::isValid(''));
ok('id: rejects spaces', !CalendarId::isValid('a b'));
ok('id: rejects control characters', !CalendarId::isValid("a\nb"));

// ---- Google parsing ---------------------------------------------------
$json = ['items' => [
    ['summary' => 'Late',      'start' => ['dateTime' => '2026-10-12T15:00:00-05:00'], 'end' => ['dateTime' => '2026-10-12T16:00:00-05:00']],
    ['summary' => "  Early\n  meeting ", 'start' => ['dateTime' => '2026-10-12T14:00:00Z'], 'end' => ['dateTime' => '2026-10-12T15:30:00Z']],
    ['summary' => 'Holiday',   'start' => ['date' => '2026-10-12'], 'end' => ['date' => '2026-10-13']],
    ['summary' => 'Gone',      'status' => 'cancelled', 'start' => ['dateTime' => '2026-10-12T10:00:00Z'], 'end' => ['dateTime' => '2026-10-12T11:00:00Z']],
    ['summary' => 'Optional',  'transparency' => 'transparent', 'start' => ['dateTime' => '2026-10-12T17:00:00Z'], 'end' => ['dateTime' => '2026-10-12T18:00:00Z']],
    ['start' => ['dateTime' => '2026-10-12T20:00:00Z'], 'end' => ['dateTime' => '2026-10-12T21:00:00Z']],
    ['summary' => 'Broken',    'start' => ['dateTime' => 'not a date'], 'end' => ['dateTime' => 'nope']],
    ['summary' => 'No end',    'start' => ['dateTime' => '2026-10-12T20:00:00Z']],
]];
$parsed = GoogleCalendar::parse($json, $tz);
same('google: skips cancelled/broken/incomplete', ['Holiday', 'Early meeting', 'Optional', 'Late', 'Busy'], array_map(fn ($e) => $e->title, $parsed));
same('google: converts UTC to room zone', '2026-10-12 09:00', $parsed[1]->start->format('Y-m-d H:i'));
same('google: all-day flag and midnight start', [true, '2026-10-12 00:00', '2026-10-13 00:00'], [$parsed[0]->allDay, $parsed[0]->start->format('Y-m-d H:i'), $parsed[0]->end->format('Y-m-d H:i')]);
same('google: transparent is not busy', false, $parsed[2]->busy);
same('google: missing summary becomes Busy', 'Busy', $parsed[4]->title);
ok('google: error 400 key', str_contains(GoogleCalendar::describeError(400, ['error' => ['message' => 'API key not valid', 'errors' => [['reason' => 'badRequest']]]]), 'API key'));
ok('google: error 404', str_contains(GoogleCalendar::describeError(404, []), 'not found'));
ok('google: error 403 public', str_contains(GoogleCalendar::describeError(403, ['error' => ['errors' => [['reason' => 'forbidden']]]]), 'public'));
$ipBlocked = GoogleCalendar::describeError(403, ['error' => ['message' => 'Requests from IP address 203.0.113.9 are blocked.', 'details' => [['reason' => 'API_KEY_IP_ADDRESS_BLOCKED']]]]);
ok('google: error 403 ip restriction', str_contains($ipBlocked, 'IP address') && str_contains($ipBlocked, '203.0.113.9'));
ok('google: error 403 api restriction', str_contains(GoogleCalendar::describeError(403, ['error' => ['message' => 'Requests to this API are blocked.', 'details' => [['reason' => 'API_KEY_SERVICE_BLOCKED']]]]), 'API restrictions'));
ok('google: error 403 shows googles message', str_contains(GoogleCalendar::describeError(403, ['error' => ['message' => 'Some new reason', 'errors' => [['reason' => 'weird']]]]), 'Some new reason'));
ok('google: error 403 api disabled', str_contains(GoogleCalendar::describeError(403, ['error' => ['message' => 'Google Calendar API has not been used in project 1', 'errors' => [['reason' => 'accessNotConfigured']]]]), 'not enabled'));

// ---- Config -----------------------------------------------------------
$dir = tempDir();
$config = new Config("$dir/config.json");
same('config: defaults when missing', [], $config->load()['rooms']);
$config->update(function (array $c) {
    $c['rooms']['b'] = Room::fromArray('b', ['name' => 'Room B', 'calendar_id' => 'demo', 'access_key' => 'k'])->toArray();
    return $c;
});
same('config: room round trip', ['Room B', 'demo', 'bwr', 'auto'], [$config->room('b')->name, $config->room('b')->calendarId, $config->room('b')->colorMode, $config->room('b')->statusMode]);
same('config: unknown room', null, $config->room('nope'));
file_put_contents("$dir/config.json", '{not json');
$threw = false;
try {
    $config->load();
} catch (RuntimeException) {
    $threw = true;
}
ok('config: corrupt file throws instead of resetting', $threw);
same('room: invalid modes fall back', ['bwr', 'auto'], [Room::fromArray('x', ['color_mode' => 'rainbow', 'status_mode' => 'zzz'])->colorMode, Room::fromArray('x', ['status_mode' => 'zzz'])->statusMode]);
ok('room: slug rules', Room::isValidSlug('conf-b2') && !Room::isValidSlug('-x') && !Room::isValidSlug('Conf') && !Room::isValidSlug('a b') && !Room::isValidSlug(''));
same('room: zone fallback', 'America/Chicago', $room(['timezone' => ''])->zone(['default_timezone' => 'America/Chicago'])->getName());
same('room: zone override', 'Europe/Paris', $room(['timezone' => 'Europe/Paris'])->zone(['default_timezone' => 'America/Chicago'])->getName());
same('room: invalid zone falls back to UTC', 'UTC', $room(['timezone' => 'Mars/Base'])->zone(['default_timezone' => 'nope'])->getName());
removeDir($dir);

// ---- EventService (cache + stale fallback) ----------------------------
$dir = tempDir();
$fake = new FakeProvider();
$fake->events = [$ev('Standup', '09:00', '10:00')];
$service = new EventService(['cache_ttl' => 60], $fake, $dir);
$r = $room();

$first = $service->forToday($r, $day('08:00'));
same('events: first call fetches', [1, 1, false, null], [$fake->calls, count($first->events), $first->stale, $first->error]);
$service->forToday($r, $day('08:00:30'));
same('events: second call within TTL is cached', 1, $fake->calls);
$fresh = $service->forToday($r, $day('08:00:30'), true);
same('events: bypass cache refetches', 2, $fake->calls);
$fake->failWith = 'Google API quota exceeded.';
$stale = $service->forToday($r, $day('08:05'));
same('events: failure uses stale data', [3, true, 1, 'Google API quota exceeded.'], [$fake->calls, $stale->stale, count($stale->events), $stale->error]);
$service->forToday($r, $day('08:05:10'));
same('events: retry is delayed after a failure', 3, $fake->calls);
$service->forToday($r, $day('08:06'));
same('events: retries after the delay', 4, $fake->calls);
$fake->failWith = null;
$recovered = $service->forToday($r, $day('08:10'));
same('events: recovers', [false, null], [$recovered->stale, $recovered->error]);

$other = new FakeProvider();
$other->failWith = 'Calendar not found.';
$none = (new EventService([], $other, $dir))->forToday($room(['calendar_id' => 'other@y']), $day('08:00'));
same('events: failure without cache', [false, false, 'Calendar not found.'], [$none->hasData(), $none->stale, $none->error]);
$demo = (new EventService([], null, $dir))->forToday($room(['calendar_id' => 'demo']), $day('08:00'));
same('events: demo calendar', 5, count($demo->events));
$blank = (new EventService([], null, $dir))->forToday($room(['calendar_id' => '']), $day('08:00'));
ok('events: blank calendar id is an error', !$blank->hasData() && $blank->error !== null);
removeDir($dir);

// ---- ScreenBuilder ----------------------------------------------------
$builder = new ScreenBuilder(new TimeFormat());
$build = fn (Room $r, array $events, string $at, ?DayEvents $d = null) => $builder->build($r, $d ?? new DayEvents($events, $day($at)), $day($at));

$standard = [$ev('A', '09:00', '10:00'), $ev('B', '10:00', '11:00'), $ev('C', '13:00', '14:00')];
$s = $build($room(), $standard, '08:00');
same('status: free before first event', [Screen::AVAILABLE, 'AVAILABLE', 'Free until 9:00 AM'], [$s->kind, $s->statusLabel, $s->detail]);
$s = $build($room(), $standard, '09:30');
same('status: back-to-back events extend occupancy', [Screen::OCCUPIED, 'In use until 11:00 AM'], [$s->kind, $s->detail]);
$s = $build($room(), $standard, '11:00');
same('status: free at the exact end time', [Screen::AVAILABLE, 'Free until 1:00 PM'], [$s->kind, $s->detail]);
$s = $build($room(), $standard, '15:00');
same('status: free for the rest of the day', 'Free for the rest of the day', $s->detail);
$s = $build($room(), [$ev('Free block', '09:00', '10:00', false)], '09:30');
same('status: events marked free do not occupy', Screen::AVAILABLE, $s->kind);
$allDay = new Event('Closed for maintenance', $day('00:00'), $day('00:00')->modify('+1 day'), true);
$s = $build($room(), [$allDay], '09:30');
same('status: all-day event occupies the whole day', [Screen::OCCUPIED, 'In use for the rest of the day', 'All day'], [$s->kind, $s->detail, $s->rows[0]->time]);

$s = $build($room(['status_mode' => 'custom', 'custom_label' => 'CLOSED', 'custom_message' => 'Back at 1']), $standard, '09:30');
same('status: custom override', [Screen::OTHER, 'CLOSED', 'Back at 1'], [$s->kind, $s->statusLabel, $s->detail]);
$s = $build($room(['status_mode' => 'available']), $standard, '09:30');
same('status: forced available', [Screen::AVAILABLE, 'AVAILABLE'], [$s->kind, $s->statusLabel]);
$s = $build($room(['status_mode' => 'occupied']), $standard, '08:00');
same('status: forced occupied', [Screen::OCCUPIED, 'OCCUPIED'], [$s->kind, $s->statusLabel]);
$s = $build($room(), [], '08:00', new DayEvents([], null, false, 'boom'));
same('status: no data', [Screen::OTHER, 'UNKNOWN', 'Calendar unavailable'], [$s->kind, $s->statusLabel, $s->emptyMessage]);
$s = $build($room(), [], '08:00');
same('status: no events', [Screen::AVAILABLE, 'No events today', []], [$s->kind, $s->emptyMessage, $s->rows]);
$s = $build($room(), $standard, '08:30', new DayEvents($standard, $day('08:00'), true, 'x'));
same('footer: stale data is flagged', 'Offline - data from 8:00 AM', $s->footer);

$many = [];
foreach (range(8, 19) as $h) {
    $many[] = $ev("E$h", sprintf('%02d:00', $h), sprintf('%02d:30', $h));
}
$s = $build($room(), $many, '12:10');
same('rows: never more than the maximum', true, count($s->rows) <= ScreenBuilder::MAX_ROWS);
$lastRow = $s->rows[count($s->rows) - 1];
same('rows: overflow is summarised', [Row::MORE, '+4 more'], [$lastRow->state, $lastRow->title]);
same('rows: current event comes first when there is overflow', [Row::CURRENT, 'E12'], [$s->rows[0]->state, $s->rows[0]->title]);
$s = $build($room(), $standard, '10:30');
same('rows: past events fill free space', [Row::PAST, Row::CURRENT, Row::NEXT], array_map(fn ($r) => $r->state, $s->rows));
$s = $build($room(), $standard, '12:00');
same('rows: past, then next', [Row::PAST, Row::PAST, Row::NEXT], array_map(fn ($r) => $r->state, $s->rows));
$s = $build($room(), $standard, '09:30');
same('rows: current, upcoming', [Row::CURRENT, Row::NEXT, Row::UPCOMING], array_map(fn ($r) => $r->state, $s->rows));
$midnightCrossing = [new Event('Overnight', $day('00:00')->modify('-3 hours'), $day('02:00'))];
same('rows: event that started yesterday', 'Until 2:00 AM', $build($room(), $midnightCrossing, '01:00')->rows[0]->time);

same('time: same meridiem', '8:00 - 9:00 AM', (new TimeFormat())->range($day('08:00'), $day('09:00')));
same('time: different meridiem', '11:30 AM - 1:00 PM', (new TimeFormat())->range($day('11:30'), $day('13:00')));
same('time: 24-hour', '08:00 - 13:00', (new TimeFormat(true))->range($day('08:00'), $day('13:00')));

// ---- Renderer ---------------------------------------------------------
if (!function_exists('imagettftext')) {
    echo "SKIP  renderer tests (GD with FreeType not loaded; run with: php -d extension=gd tests/run.php)\n";
} else {
    $renderer = new Renderer();
    $longEvents = [
        $ev('An extremely long meeting title that certainly will not fit on one line of the placard', '09:00', '10:00'),
        $ev('Supercalifragilisticexpialidocious-and-unbreakable-single-word-title', '10:00', '11:00'),
        $ev('B', '11:00', '12:00'), $ev('C', '12:00', '13:00'), $ev('D', '13:00', '14:00'), $ev('E', '14:00', '15:00'),
    ];
    foreach (['bwr', 'grey4', 'bw'] as $mode) {
        foreach ([[$standard, '09:30', 'Room B'], [$longEvents, '09:30', 'Doctor Wellington Hartley Memorial Science Laboratory Annex 204'], [[], '09:30', 'X']] as [$events, $at, $name]) {
            $screen = $builder->build($room(['color_mode' => $mode, 'name' => $name, 'status_mode' => 'custom', 'custom_label' => 'TEMPORARILY UNAVAILABLE', 'custom_message' => 'A very long message that has to wrap across several lines on the panel']), new DayEvents($events, $day($at)), $day($at));
            $png = $renderer->render($screen);
            $img = imagecreatefromstring($png);
            $allowed = array_map(fn ($c) => implode(',', $c), Theme::for($mode)->levels);
            $seen = [];
            for ($y = 0; $y < Renderer::HEIGHT; $y += 3) {
                for ($x = 0; $x < Renderer::WIDTH; $x += 3) {
                    $c = imagecolorsforindex($img, imagecolorat($img, $x, $y));
                    $seen[$c['red'] . ',' . $c['green'] . ',' . $c['blue']] = true;
                }
            }
            same("render $mode: size", [800, 480], [imagesx($img), imagesy($img)]);
            same("render $mode: only palette colours", [], array_values(array_diff(array_keys($seen), $allowed)));
            ok("render $mode: uses at least black and white", count($seen) >= 2);
        }
    }
    $pngOk = $renderer->render($build($room(), $standard, '09:30'));
    ok('render: PNG signature', str_starts_with($pngOk, "\x89PNG\r\n\x1a\n"));
}

// ---- First-run setup --------------------------------------------------
same('slug from name', ['conference-room-b', 'room', 'a-b'], [Room::slugFromName('Conference Room B'), Room::slugFromName('日本語'), Room::slugFromName('  A & B!  ')]);
ok('slug from long name is valid', Room::isValidSlug(Room::slugFromName(str_repeat('word ', 30))));

$good = ['password' => 'correct-horse-battery', 'confirm_password' => 'correct-horse-battery', 'default_timezone' => 'America/Chicago',
         'google_api_key' => '', 'room_name' => 'Conference Room B', 'room_calendar_id' => 'demo', 'room_color_mode' => 'bwr'];
[$values, $errors] = Setup::validate($good);
same('setup: valid input', [], $errors);
same('setup: short password', ['password'], array_keys(Setup::validate(['password' => 'short', 'confirm_password' => 'short'] + $good)[1]));
same('setup: mismatched passwords', ['confirm_password'], array_keys(Setup::validate(['confirm_password' => 'different-password'] + $good)[1]));
same('setup: bad time zone', ['default_timezone'], array_keys(Setup::validate(['default_timezone' => 'Mars/Base'] + $good)[1]));
same('setup: bad api key', ['google_api_key'], array_keys(Setup::validate(['google_api_key' => 'nope nope'] + $good)[1]));
same('setup: room is optional', [], Setup::validate(['room_name' => '', 'room_calendar_id' => ''] + $good)[1]);
same('setup: room needs both name and calendar', ['room_calendar_id'], array_keys(Setup::validate(['room_calendar_id' => ''] + $good)[1]));
same('setup: calendar url is normalised', 'abc@group.calendar.google.com', Setup::validate(['room_calendar_id' => 'https://calendar.google.com/calendar/embed?src=abc%40group.calendar.google.com'] + $good)[0]['room_calendar_id']);

$dir = tempDir();
$config = new Config("$dir/config.json");
$slug = (new Setup($config))->apply($values);
$saved = $config->load();
same('setup: creates the room', 'conference-room-b', $slug);
ok('setup: password is stored hashed', $saved['admin']['password_hash'] !== '' && password_verify('correct-horse-battery', $saved['admin']['password_hash']) && !str_contains((string) file_get_contents("$dir/config.json"), 'correct-horse-battery'));
same('setup: stores settings and room', ['America/Chicago', 'Conference Room B', 'demo', 32], [$saved['settings']['default_timezone'], $config->room('conference-room-b')->name, $config->room('conference-room-b')->calendarId, strlen($config->room('conference-room-b')->accessKey)]);
$threw = false;
try {
    (new Setup($config))->apply($values);
} catch (AlreadyConfigured) {
    $threw = true;
}
ok('setup: refuses to run twice', $threw);
removeDir($dir);

$dir = tempDir();
$config = new Config("$dir/config.json");
same('setup: no room when none given', null, (new Setup($config))->apply(Setup::validate(['room_name' => '', 'room_calendar_id' => ''] + $good)[0]));
same('setup: no rooms stored', [], $config->load()['rooms']);
removeDir($dir);

$requirements = Setup::requirements();
ok('setup: requirements are reported', count($requirements) >= 5);
ok('setup: passes() blocks on a failed required check', !Setup::passes([['required' => true, 'ok' => false]]) && Setup::passes([['required' => false, 'ok' => false], ['required' => true, 'ok' => true]]));

// ---- Login throttle ---------------------------------------------------
$dir = tempDir();
$throttle = new LoginThrottle("$dir/throttle.json");
same('throttle: starts open', 0, $throttle->remaining('1.2.3.4'));
for ($i = 0; $i < 4; $i++) {
    $throttle->fail('1.2.3.4');
}
same('throttle: still open after 4 failures', 0, $throttle->remaining('1.2.3.4'));
$throttle->fail('1.2.3.4');
ok('throttle: locked after 5 failures', $throttle->remaining('1.2.3.4') > 0);
same('throttle: other addresses unaffected', 0, $throttle->remaining('5.6.7.8'));
$throttle->clear('1.2.3.4');
same('throttle: clear unlocks', 0, $throttle->remaining('1.2.3.4'));
removeDir($dir);

echo "\n" . ($total - $failed) . " / $total passed\n";
exit($failed === 0 ? 0 : 1);
