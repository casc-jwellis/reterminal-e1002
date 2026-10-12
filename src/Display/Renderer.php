<?php
declare(strict_types=1);

namespace Placard\Display;

use Placard\Paths;

/** Draws a Screen as an 800x480 indexed PNG using only the colours of its Theme. */
final class Renderer
{
    public const WIDTH = 800;
    public const HEIGHT = 480;

    private const PANEL_W = 300;
    private const PAD = 24;
    private const ROW_H = 70;

    private \GdImage $im;
    private Theme $theme;
    /** @var array<string, int> */
    private array $c = [];
    private string $regular;
    private string $bold;

    public function __construct(?string $fontDir = null)
    {
        if (!function_exists('imagettftext')) {
            throw new \RuntimeException('The PHP GD extension with FreeType support is required.');
        }
        $fontDir ??= Paths::fonts();
        $this->regular = $fontDir . '/LiberationSans-Regular.ttf';
        $this->bold    = $fontDir . '/LiberationSans-Bold.ttf';
        foreach ([$this->regular, $this->bold] as $font) {
            if (!is_file($font)) {
                throw new \RuntimeException("Font not found: $font");
            }
        }
    }

    /** @return string PNG file contents */
    public function render(Screen $s): string
    {
        $this->theme = Theme::for($s->colorMode);
        $this->im = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $t = $this->theme;
        $this->c = [
            'ink'       => $this->alloc($t->ink),
            'paper'     => $this->alloc($t->paper),
            'accent'    => $this->alloc($t->accent),
            'paperMute' => $this->alloc($t->paperMute),
            'panelMute' => $this->alloc($t->panelMute),
            'faint'     => $this->alloc($t->faint),
        ];
        imagefill($this->im, 0, 0, $this->c['paper']);

        $this->drawPanel($s);
        $this->drawSchedule($s);

        return $this->toPng();
    }

    // ---- left panel ----------------------------------------------------

    private function drawPanel(Screen $s): void
    {
        $w = self::PANEL_W;
        $pad = self::PAD;
        $inner = $w - 2 * $pad;
        imagefilledrectangle($this->im, 0, 0, $w - 1, self::HEIGHT - 1, $this->c['ink']);

        // Room name: largest size that fits in at most 3 lines
        [$size, $lines] = $this->wrapToFit($s->roomName, $this->bold, [34, 30, 26, 22], $inner, 3);
        $y = 22 + $size;
        foreach ($lines as $line) {
            $this->text($size, $pad, $y, 'paper', $this->bold, $line);
            $y += (int) round($size * 1.35);
        }

        // Status box
        $boxY = 250;
        $boxH = 90;
        $this->drawStatusBox($s, $pad, $boxY, $w - $pad, $boxY + $boxH);

        // Detail text under the box
        [$size, $lines] = $this->wrapToFit($s->detail, $this->regular, [18, 16, 14], $inner, 3);
        $y = $boxY + $boxH + 14 + $size;
        foreach ($lines as $line) {
            $this->text($size, $pad, $y, 'panelMute', $this->regular, $line);
            $y += (int) round($size * 1.4);
        }

        // Footer
        $size = $this->fitSize(13, $this->regular, $s->footer, $inner, 10);
        $this->text($size, $pad, self::HEIGHT - 22, 'panelMute', $this->regular, $s->footer);
    }

    private function drawStatusBox(Screen $s, int $x0, int $y0, int $x1, int $y1): void
    {
        $textColor = 'ink';
        if ($s->kind === Screen::AVAILABLE) {
            imagefilledrectangle($this->im, $x0, $y0, $x1, $y1, $this->c['paper']);
        } elseif ($this->theme->hasAccentColor()) {
            imagefilledrectangle($this->im, $x0, $y0, $x1, $y1, $this->c['accent']);
            $textColor = 'paper';
        } else {
            // No colour available: a black box with a white outline, so it reads as the opposite of Available.
            for ($i = 0; $i < 3; $i++) {
                imagerectangle($this->im, $x0 + $i, $y0 + $i, $x1 - $i, $y1 - $i, $this->c['paper']);
            }
            $textColor = 'paper';
        }

        $font = $this->bold;
        $size = $this->fitSize(30, $font, $s->statusLabel, $x1 - $x0 - 24, 14);
        $label = $this->truncate($s->statusLabel, $font, $size, $x1 - $x0 - 24);
        $tx = $x0 + (int) (($x1 - $x0 - $this->width($size, $font, $label)) / 2);
        $ty = $y0 + (int) (($y1 - $y0) / 2) + (int) round($size * 0.36);
        $this->text($size, $tx, $ty, $textColor, $font, $label);
    }

    // ---- schedule ------------------------------------------------------

    private function drawSchedule(Screen $s): void
    {
        $x0 = self::PANEL_W + 30;
        $x1 = self::WIDTH - 24;
        $width = $x1 - $x0;

        $size = $this->fitSize(26, $this->bold, $s->dateLabel, $width, 16);
        $this->text($size, $x0, 56, 'ink', $this->bold, $s->dateLabel);
        imagefilledrectangle($this->im, $x0, 74, $x1, 77, $this->c['accent']);

        if ($s->rows === []) {
            $size = 22;
            $w = $this->width($size, $this->regular, $s->emptyMessage);
            $this->text($size, $x0 + (int) (($width - $w) / 2), 270, 'paperMute', $this->regular, $s->emptyMessage);
            return;
        }

        $y = 92;
        foreach ($s->rows as $row) {
            $this->drawRow($row, $x0, $y, $x1);
            $y += self::ROW_H;
        }
    }

    private function drawRow(Row $row, int $x0, int $y, int $x1): void
    {
        $bottom = $y + self::ROW_H - 8;

        if ($row->state === Row::MORE) {
            $this->text(17, $x0 + 4, $y + 30, 'paperMute', $this->bold, $row->title);
            return;
        }

        $past = $row->state === Row::PAST;
        $current = $row->state === Row::CURRENT;
        $next = $row->state === Row::NEXT;
        $hasColor = $this->theme->hasAccentColor();

        if ($current) {
            imagefilledrectangle($this->im, $x0, $y, $x1, $bottom, $hasColor ? $this->c['accent'] : $this->c['ink']);
        } else {
            imagerectangle($this->im, $x0, $y, $x1, $bottom, $past ? $this->c['faint'] : $this->c['ink']);
            if ($next && $hasColor) {
                imagefilledrectangle($this->im, $x0, $y, $x0 + 7, $bottom, $this->c['accent']);
            }
        }

        $titleColor = $current ? 'paper' : ($past ? 'paperMute' : 'ink');
        $timeColor  = $current ? 'panelMute' : 'paperMute';
        // Without mid-tones, past events are told apart by a regular (not bold) weight.
        $font = ($past && !$this->theme->hasGrey) ? $this->regular : $this->bold;
        $pad  = ($next && $hasColor) ? 20 : 12;
        $avail = $x1 - $x0 - $pad - 12;

        $size  = $this->fitSize(21, $font, $row->title, $avail, 16);
        $title = $this->truncate($row->title, $font, $size, $avail);
        $this->text($size, $x0 + $pad, $y + 27, $titleColor, $font, $title);
        $this->text(15, $x0 + $pad, $y + 50, $timeColor, $this->regular, $row->time);
    }

    // ---- text helpers --------------------------------------------------

    private function text(int $size, int $x, int $y, string $color, string $font, string $text): void
    {
        imagettftext($this->im, $size, 0, $x, $y, $this->c[$color], $font, $text);
    }

    private function width(int $size, string $font, string $text): int
    {
        $box = imagettfbbox($size, 0, $font, $text);
        return $box === false ? 0 : (int) ($box[2] - $box[0]);
    }

    /** Largest size (down to $min) at which $text fits in $maxW. */
    private function fitSize(int $size, string $font, string $text, int $maxW, int $min): int
    {
        while ($size > $min && $this->width($size, $font, $text) > $maxW) {
            $size--;
        }
        return $size;
    }

    private function truncate(string $text, string $font, int $size, int $maxW): string
    {
        if ($this->width($size, $font, $text) <= $maxW) {
            return $text;
        }
        while ($text !== '' && $this->width($size, $font, $text . '…') > $maxW) {
            $text = mb_substr($text, 0, -1);
        }
        return rtrim($text) . '…';
    }

    /**
     * Word-wraps $text, trying each size in turn until it fits in $maxLines lines.
     * Falls back to the smallest size with the last line truncated.
     *
     * @param list<int> $sizes
     * @return array{int, list<string>}
     */
    private function wrapToFit(string $text, string $font, array $sizes, int $maxW, int $maxLines): array
    {
        $text = trim($text);
        if ($text === '') {
            return [end($sizes), []];
        }
        foreach ($sizes as $size) {
            $lines = $this->wrap($text, $font, $size, $maxW);
            $fits = count($lines) <= $maxLines;
            foreach ($lines as $line) {
                $fits = $fits && $this->width($size, $font, $line) <= $maxW;
            }
            if ($fits) {
                return [$size, $lines];
            }
        }
        $size  = end($sizes);
        $lines = array_slice($this->wrap($text, $font, $size, $maxW), 0, $maxLines);
        $last  = count($lines) - 1;
        $lines[$last] = $this->truncate($lines[$last] . ' …', $font, $size, $maxW);
        return [$size, array_map(fn (string $l) => $this->truncate($l, $font, $size, $maxW), $lines)];
    }

    /** @return list<string> */
    private function wrap(string $text, string $font, int $size, int $maxW): array
    {
        $lines = [''];
        foreach (preg_split('/\s+/', $text) ?: [] as $word) {
            $i = count($lines) - 1;
            $try = $lines[$i] === '' ? $word : $lines[$i] . ' ' . $word;
            if ($lines[$i] === '' || $this->width($size, $font, $try) <= $maxW) {
                $lines[$i] = $try;
            } else {
                $lines[] = $word;
            }
        }
        return $lines;
    }

    // ---- colour handling -----------------------------------------------

    /** @param array{int, int, int} $rgb */
    private function alloc(array $rgb): int
    {
        return (int) imagecolorallocate($this->im, $rgb[0], $rgb[1], $rgb[2]);
    }

    /** Snaps the antialiased drawing to the device palette and encodes it as PNG. */
    private function toPng(): string
    {
        $levels = $this->theme->levels;
        $pal = imagecreate(self::WIDTH, self::HEIGHT);
        $index = [];
        foreach ($levels as $i => [$r, $g, $b]) {
            $index[$i] = imagecolorallocate($pal, $r, $g, $b);
        }

        $nearest = [];
        for ($y = 0; $y < self::HEIGHT; $y++) {
            for ($x = 0; $x < self::WIDTH; $x++) {
                $rgb = imagecolorat($this->im, $x, $y);
                $i = $nearest[$rgb] ??= $this->nearestLevel($rgb, $levels);
                imagesetpixel($pal, $x, $y, $index[$i]);
            }
        }

        ob_start();
        imagepng($pal, null, 9);
        return (string) ob_get_clean();
    }

    /** @param list<array{int, int, int}> $levels */
    private function nearestLevel(int $rgb, array $levels): int
    {
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $best = 0;
        $bestDistance = PHP_INT_MAX;
        foreach ($levels as $i => [$lr, $lg, $lb]) {
            $d = ($r - $lr) ** 2 + ($g - $lg) ** 2 + ($b - $lb) ** 2;
            if ($d < $bestDistance) {
                $bestDistance = $d;
                $best = $i;
            }
        }
        return $best;
    }
}
