<?php
declare(strict_types=1);

namespace Placard;

final class Room
{
    public const COLOR_MODES = [
        'bwr'   => 'Black / white / red',
        'grey4' => '4-level greyscale',
        'bw'    => 'Black / white',
    ];

    public const STATUS_MODES = [
        'auto'      => 'Automatic (from the calendar)',
        'available' => 'Always show Available',
        'occupied'  => 'Always show Occupied',
        'custom'    => 'Custom label (e.g. Closed)',
    ];

    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $calendarId,
        public readonly string $timezone,
        public readonly string $colorMode,
        public readonly string $statusMode,
        public readonly string $customLabel,
        public readonly string $customMessage,
        public readonly string $accessKey,
    ) {
    }

    public static function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/', $slug) === 1;
    }

    /** "Conference Room B" becomes "conference-room-b". */
    public static function slugFromName(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $slug = rtrim(substr($slug, 0, 40), '-');
        return $slug !== '' ? $slug : 'room';
    }

    public static function newAccessKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function fromArray(string $slug, array $d): self
    {
        $colorMode  = (string) ($d['color_mode'] ?? 'bwr');
        $statusMode = (string) ($d['status_mode'] ?? 'auto');

        return new self(
            $slug,
            (string) ($d['name'] ?? $slug),
            (string) ($d['calendar_id'] ?? ''),
            (string) ($d['timezone'] ?? ''),
            isset(self::COLOR_MODES[$colorMode]) ? $colorMode : 'bwr',
            isset(self::STATUS_MODES[$statusMode]) ? $statusMode : 'auto',
            (string) ($d['custom_label'] ?? ''),
            (string) ($d['custom_message'] ?? ''),
            (string) ($d['access_key'] ?? ''),
        );
    }

    /** Stored form (the slug is the key in the config, so it is not included). */
    public function toArray(): array
    {
        return [
            'name'           => $this->name,
            'calendar_id'    => $this->calendarId,
            'timezone'       => $this->timezone,
            'color_mode'     => $this->colorMode,
            'status_mode'    => $this->statusMode,
            'custom_label'   => $this->customLabel,
            'custom_message' => $this->customMessage,
            'access_key'     => $this->accessKey,
        ];
    }

    public function with(array $changes): self
    {
        return self::fromArray($this->slug, array_merge($this->toArray(), $changes));
    }

    /** The room's time zone, falling back to the site default, then UTC. */
    public function zone(array $settings): \DateTimeZone
    {
        foreach ([$this->timezone, $settings['default_timezone'] ?? '', 'UTC'] as $name) {
            if ($name !== '' && in_array($name, \DateTimeZone::listIdentifiers(), true)) {
                return new \DateTimeZone($name);
            }
        }
        return new \DateTimeZone('UTC');
    }
}
