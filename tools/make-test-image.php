<?php
// Generates an 800x480 test placard PNG with dummy data.
// Usage: php -d extension=gd tools/make-test-image.php [mode] [state] [output.png]
//   mode:  grey (4-level greyscale, default) | bwr (black/white/red)
//   state: available (default) | occupied

const W = 800;
const H = 480;

$mode  = $argv[1] ?? 'grey';
$state = $argv[2] ?? 'available';
if (!in_array($mode, ['grey', 'bwr'], true) || !in_array($state, ['available', 'occupied'], true)) {
    exit("Usage: php -d extension=gd tools/make-test-image.php [grey|bwr] [available|occupied] [output.png]\n");
}
$suffix = $mode === 'grey' ? '' : "-$mode-$state";
$out = $argv[3] ?? __DIR__ . "/../samples/test-placard$suffix.png";
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
$room   = 'Conference Room B';
$events = [
    ['08:00', '09:00', 'Team Standup'],
    ['09:30', '11:00', 'Advising Session - Smith'],
    ['13:00', '14:30', 'Curriculum Committee'],
    ['15:00', '16:00', 'Vendor Demo: Lab Equipment'],
    ['16:30', '17:30', 'Student Org Meeting'],
];
if ($state === 'occupied') {
    $now    = mktime(10, 15);
    $status = 'OCCUPIED';
    $until  = 'In use until 11:00 AM';
} else {
    $now    = mktime(11, 15);
    $status = 'AVAILABLE';
    $until  = 'Free until 1:00 PM';
}
$today = date('l, F j', $now);

// Draw in truecolor (antialiased), quantize to the device palette at the end.
$im = imagecreatetruecolor(W, H);
$c = [
    'black' => imagecolorallocate($im, 0, 0, 0),
    'white' => imagecolorallocate($im, 255, 255, 255),
    'red'   => imagecolorallocate($im, 255, 0, 0),
    'dark'  => imagecolorallocate($im, 85, 85, 85),
    'light' => imagecolorallocate($im, 170, 170, 170),
];
if ($mode === 'bwr') {
    // No greys available: secondary text uses black/white, accents use red.
    $c['dark']  = $c['black'];
    $c['light'] = $c['white'];
}
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

// Status block: occupied is red on bwr (white on black on greyscale); available is white.
$boxY = 250;
$occupied = $state === 'occupied';
$boxFill  = $occupied && $mode === 'bwr' ? $c['red'] : $c['white'];
$boxText  = $occupied && $mode === 'bwr' ? $c['white'] : $c['black'];
imagefilledrectangle($im, 24, $boxY, $panelW - 24, $boxY + 90, $boxFill);
$sz = fit(30, $fonts['bold'], $status, $panelW - 48 - 16);
$tw = textWidth($sz, $fonts['bold'], $status);
txt($im, $sz, 24 + (($panelW - 48 - $tw) >> 1), $boxY + 58, $boxText, $fonts['bold'], $status);
txt($im, 17, 24, $boxY + 130, $c['light'], $fonts['reg'], $until);

// Updated footer
txt($im, 13, 24, H - 24, $mode === 'bwr' ? $c['white'] : $c['dark'], $fonts['reg'], 'Updated ' . date('g:i A', $now));

// ---- Right panel: today's schedule ----
$x0 = $panelW + 30;
$x1 = W - 24;
txt($im, 26, $x0, 56, $c['black'], $fonts['bold'], $today);
imagefilledrectangle($im, $x0, 74, $x1, 77, $mode === 'bwr' ? $c['red'] : $c['black']);

$rowH = 70;
$y = 92;
$nextMarked = false;
foreach ($events as [$s, $e, $title]) {
    $st = strtotime(date('Y-m-d', $now) . " $s");
    $en = strtotime(date('Y-m-d', $now) . " $e");
    $past = $en <= $now;
    $cur  = $st <= $now && $now < $en;
    $next = !$past && !$cur && !$nextMarked;
    if ($next) $nextMarked = true;
    $bottom = $y + $rowH - 8;

    if ($cur) {
        imagefilledrectangle($im, $x0, $y, $x1, $bottom, $mode === 'bwr' ? $c['red'] : $c['black']);
    } else {
        imagerectangle($im, $x0, $y, $x1, $bottom, $past ? $c['light'] : $c['black']);
        if ($past && $mode === 'bwr') imagerectangle($im, $x0, $y, $x1, $bottom, $c['black']);
        if ($next && $mode === 'bwr') imagefilledrectangle($im, $x0, $y, $x0 + 7, $bottom, $c['red']);
    }

    $fg  = $cur ? $c['white'] : ($past ? $c['dark'] : $c['black']);
    $sub = $cur ? $c['light'] : $c['dark'];
    // Without greys, past events are distinguished by a regular (not bold) weight.
    $titleFont = ($past && $mode === 'bwr') ? $fonts['reg'] : $fonts['bold'];
    $pad = $next && $mode === 'bwr' ? 20 : 12;

    $range = date('g:i', $st) . ' - ' . date('g:i A', $en);
    $tSize = fit(21, $titleFont, $title, $x1 - $x0 - 24);
    txt($im, $tSize, $x0 + $pad, $y + 27, $fg, $titleFont, $title);
    txt($im, 15, $x0 + $pad, $y + 50, $sub, $fonts['reg'], $range);
    $y += $rowH;
}

// ---- Quantize to the device palette in an indexed PNG ----
$levels = $mode === 'bwr'
    ? [[0, 0, 0], [255, 255, 255], [255, 0, 0]]
    : [[0, 0, 0], [85, 85, 85], [170, 170, 170], [255, 255, 255]];
$pal = imagecreate(W, H);
$idx = [];
foreach ($levels as $i => [$r, $g, $b]) $idx[$i] = imagecolorallocate($pal, $r, $g, $b);
for ($py = 0; $py < H; $py++) {
    for ($px = 0; $px < W; $px++) {
        $rgb = imagecolorat($im, $px, $py);
        $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
        $best = 0; $bestD = PHP_INT_MAX;
        foreach ($levels as $i => [$lr, $lg, $lb]) {
            $d = ($r - $lr) ** 2 + ($g - $lg) ** 2 + ($b - $lb) ** 2;
            if ($d < $bestD) { $bestD = $d; $best = $i; }
        }
        imagesetpixel($pal, $px, $py, $idx[$best]);
    }
}
imagepng($pal, $out, 9);
echo "Wrote " . realpath($out) . "\n";
