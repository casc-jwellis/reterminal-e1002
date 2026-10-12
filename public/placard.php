<?php
declare(strict_types=1);

// The image URL for the display:  /placard.php?room=<slug>&key=<access key>

require __DIR__ . '/../src/bootstrap.php';

use Placard\Config;
use Placard\PlacardService;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message . "\n";
    exit;
}

$slug = (string) ($_GET['room'] ?? '');
$key  = (string) ($_GET['key'] ?? '');

try {
    $config = Config::default();
    $room = $config->room($slug);
    // The same response for an unknown room and a wrong key, so room names cannot be probed.
    if ($room === null || $room->accessKey === '' || !hash_equals($room->accessKey, $key)) {
        fail(404, 'Not found');
    }

    $png = (new PlacardService($config))->png($room);
} catch (Throwable $e) {
    error_log('placard.php: ' . $e);
    fail(500, 'Could not generate the image');
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
echo $png;
