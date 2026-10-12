<?php
declare(strict_types=1);

// Renders the demo calendar to a PNG without a web server.
// Usage: php -d extension=gd bin/render-sample.php [bwr|grey4|bw] [available|occupied] [output.png]

require __DIR__ . '/../src/bootstrap.php';

use Placard\Calendar\DemoCalendar;
use Placard\Calendar\DayEvents;
use Placard\Display\Renderer;
use Placard\Display\ScreenBuilder;
use Placard\Display\TimeFormat;
use Placard\Room;

$mode  = $argv[1] ?? 'bwr';
$state = $argv[2] ?? 'available';
if (!isset(Room::COLOR_MODES[$mode]) || !in_array($state, ['available', 'occupied'], true)) {
    exit("Usage: php -d extension=gd bin/render-sample.php [bwr|grey4|bw] [available|occupied] [output.png]\n");
}
$out = $argv[3] ?? __DIR__ . "/../samples/sample-$mode-$state.png";
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0777, true);
}

$room = new Room('sample', 'Conference Room B', 'demo', 'UTC', $mode, 'auto', '', '', '');
$now  = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTime($state === 'occupied' ? 10 : 11, 15);
$from = $now->setTime(0, 0);
$day  = new DayEvents((new DemoCalendar())->events('demo', $from, $from->modify('+1 day')), $now);

$screen = (new ScreenBuilder(new TimeFormat()))->build($room, $day, $now);
file_put_contents($out, (new Renderer())->render($screen));
echo "Wrote " . realpath($out) . "\n";
