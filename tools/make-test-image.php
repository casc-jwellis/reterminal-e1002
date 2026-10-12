<?php
// Generates an 800x480 4-level greyscale PNG with dummy placard data.
// Usage: php -d extension=gd tools/make-test-image.php [output.png]

const W = 800;
const H = 480;

$out = $argv[1] ?? __DIR__ . '/../samples/test-placard.png';
@mkdir(dirname($out), 0777, true);

$fontDirs = ['C:/Windows/Fonts', '/usr/share/fonts/truetype/dejavu'];
$fontFiles = ['reg' => ['arial.ttf', 'DejaVuSans.ttf'], 'bold' => ['arialbd.ttf', 'DejaVuSans-Bold.ttf']];
$fonts = [];
foreach ($fontFiles as $k => $names) {
    foreach ($fontDirs as $d) {
        foreach ($names as $n) {
            if (!isset($fonts[$k]) && is_file("$d/$n")) $fonts[$k] = "$d/$n";
        }
    }
    if (!isset($fonts[$k])) exit("No $k font found\n");
}

// Dummy data
$room    = 'Conference Room B';
$now     = mktime(11, 15);
$status  = 'AVAILABLE';
$until   = 'Free until 1:00 PM';
$events  = [
    ['08:00', '09:00', 'Team Standup'],
    ['09:30', '11:00', 'Advising Session - Smith'],
    ['13:00', '14:30', 'Curriculum Committee'],
    ['15:00', '16:00', 'Vendor Demo: Lab Equipment'],
    ['16:30', '17:30', 'Student Org Meeting'],
];
$today = date('l, F j', $now);

// Draw in truecolor (antialiased), quantize to 4 greys at the end.
$im = imagecreatetruecolor(W, H);
$c = [
    'black' => imagecolorallocate($im, 0, 0, 0),
    'dark'  => imagecolorallocate($im, 85, 85, 85),
    'light' => imagecolorallocate($im, 170, 170, 170),
    'white' => imagecolorallocate($im, 255, 255, 255),
];
imagefill($im, 0, 0, $c['white']);

function txt($im, $size, $x, $y, $col, $font, $s) {
    imagettftext($im, $size, 0, $x, $y, $col, $font, $s);
}
function textWidth($size, $font, $s) {
    $b = imagettfbbox($size, 0, $font, $s);
    return $b[2] - $b[0];
}
// Shrinks font size until text fits maxW
function fit($size, $font, $s, $maxW) {
    while ($size > 10 && textWidth($size, $font, $s) > $maxW) $size--;
    return $size;
}

// ---- Left panel: room name + status ----
$panelW = 300;
imagefilledrectangle($im, 0, 0, $panelW - 1, H - 1, $c['black']);

// Wrap room name onto up to 2 lines
$words = explode(' ', $room);
$lines = [''];
foreach ($words as $w) {
    $try = trim(end($lines) . ' ' . $w);
    if (textWidth(34, $fonts['bold'], $try) <= $panelW - 48 || end($lines) === '') $lines[key($lines)] = $try;
    else $lines[] = $w;
}
$y = 80;
foreach ($lines as $l) {
    txt($im, 34, 24, $y, $c['white'], $fonts['bold'], $l);
    $y += 46;
}

// Status block
$boxY = 250;
imagefilledrectangle($im, 24, $boxY, $panelW - 24, $boxY + 90, $c['white']);
$sz = fit(30, $fonts['bold'], $status, $panelW - 48 - 16);
$tw = textWidth($sz, $fonts['bold'], $status);
txt($im, $sz, 24 + (($panelW - 48 - $tw) >> 1), $boxY + 58, $c['black'], $fonts['bold'], $status);
txt($im, 17, 24, $boxY + 130, $c['light'], $fonts['reg'], $until);

// Clock/updated footer
txt($im, 13, 24, H - 24, $c['dark'], $fonts['reg'], 'Updated ' . date('g:i A', $now));

// ---- Right panel: today's schedule ----
$x0 = $panelW + 30;
$x1 = W - 24;
txt($im, 26, $x0, 56, $c['black'], $fonts['bold'], $today);
imagefilledrectangle($im, $x0, 74, $x1, 77, $c['black']);

$rowH = 70;
$y = 92;
foreach ($events as [$s, $e, $title]) {
    $st = strtotime(date('Y-m-d', $now) . " $s");
    $en = strtotime(date('Y-m-d', $now) . " $e");
    $past = $en <= $now;
    $cur  = $st <= $now && $now < $en;

    if ($cur) imagefilledrectangle($im, $x0, $y, $x1, $y + $rowH - 8, $c['black']);
    else      imagerectangle($im, $x0, $y, $x1, $y + $rowH - 8, $past ? $c['light'] : $c['black']);

    $fg  = $cur ? $c['white'] : ($past ? $c['dark'] : $c['black']);
    $sub = $cur ? $c['light'] : $c['dark'];

    $range = date('g:i', $st) . ' - ' . date('g:i A', $en);
    $tSize = fit(21, $fonts['bold'], $title, $x1 - $x0 - 24);
    txt($im, $tSize, $x0 + 12, $y + 27, $fg, $fonts['bold'], $title);
    txt($im, 15, $x0 + 12, $y + 50, $sub, $fonts['reg'], $range);
    $y += $rowH;
}

// ---- Quantize to 4 grey levels (0, 85, 170, 255) in a palette PNG ----
$pal = imagecreate(W, H);
$idx = [];
foreach ([0, 85, 170, 255] as $i => $g) $idx[$i] = imagecolorallocate($pal, $g, $g, $g);
for ($py = 0; $py < H; $py++) {
    for ($px = 0; $px < W; $px++) {
        $rgb = imagecolorat($im, $px, $py);
        $g = ($rgb >> 16) & 0xFF;                      // already grey: R == G == B
        imagesetpixel($pal, $px, $py, $idx[(int)round($g / 85)]);
    }
}
imagepng($pal, $out, 9);
echo "Wrote " . realpath($out) . "\n";
