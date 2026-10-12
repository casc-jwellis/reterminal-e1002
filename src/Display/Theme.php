<?php
declare(strict_types=1);

namespace Placard\Display;

/**
 * The exact colours a display mode can show. Every pixel in the output PNG is one of
 * $levels; the named roles say which of those colours to use for what.
 */
final class Theme
{
    /**
     * @param list<array{int, int, int}> $levels the device palette
     * @param array{int, int, int} $ink     text and lines on white
     * @param array{int, int, int} $paper   background; also text on black
     * @param array{int, int, int} $accent  highlight colour (equals $ink when there is no colour)
     * @param array{int, int, int} $paperMute secondary text on white
     * @param array{int, int, int} $panelMute secondary text on black
     * @param array{int, int, int} $faint   borders of past events
     */
    private function __construct(
        public readonly array $levels,
        public readonly array $ink,
        public readonly array $paper,
        public readonly array $accent,
        public readonly array $paperMute,
        public readonly array $panelMute,
        public readonly array $faint,
        /** True when there are mid-tones available, so past events can be shown in grey. */
        public readonly bool $hasGrey,
    ) {
    }

    public static function for(string $mode): self
    {
        $black = [0, 0, 0];
        $white = [255, 255, 255];

        return match ($mode) {
            'grey4' => new self([$black, [85, 85, 85], [170, 170, 170], $white], $black, $white, $black, [85, 85, 85], [170, 170, 170], [170, 170, 170], true),
            'bw'    => new self([$black, $white], $black, $white, $black, $black, $white, $black, false),
            default => new self([$black, $white, [255, 0, 0]], $black, $white, [255, 0, 0], $black, $white, $black, false),
        };
    }

    /** Whether the accent is a real colour rather than black. */
    public function hasAccentColor(): bool
    {
        return $this->accent !== $this->ink;
    }
}
