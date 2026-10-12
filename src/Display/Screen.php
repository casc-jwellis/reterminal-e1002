<?php
declare(strict_types=1);

namespace Placard\Display;

/** Everything the renderer needs to draw one placard image. */
final class Screen
{
    public const AVAILABLE = 'available';
    public const OCCUPIED = 'occupied';
    /** Neither: a custom label, or the calendar is unavailable. Drawn like OCCUPIED. */
    public const OTHER = 'other';

    /**
     * @param list<Row> $rows
     */
    public function __construct(
        public readonly string $roomName,
        public readonly string $kind,
        public readonly string $statusLabel,
        public readonly string $detail,
        public readonly string $dateLabel,
        public readonly array $rows,
        /** Shown in place of the list when there are no rows. */
        public readonly string $emptyMessage,
        public readonly string $footer,
        public readonly string $colorMode,
    ) {
    }
}
