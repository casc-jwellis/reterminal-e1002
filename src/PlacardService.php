<?php
declare(strict_types=1);

namespace Placard;

use Placard\Calendar\DayEvents;
use Placard\Calendar\EventService;
use Placard\Display\Renderer;
use Placard\Display\ScreenBuilder;
use Placard\Display\TimeFormat;

/** Ties calendar, screen layout and rendering together for one room. */
final class PlacardService
{
    private readonly array $settings;

    public function __construct(Config $config, private readonly ?EventService $events = null)
    {
        $this->settings = $config->load()['settings'];
    }

    public function settings(): array
    {
        return $this->settings;
    }

    public function now(Room $room): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $room->zone($this->settings));
    }

    public function todaysEvents(Room $room, bool $bypassCache = false, ?\DateTimeImmutable $now = null): DayEvents
    {
        $service = $this->events ?? new EventService($this->settings);
        return $service->forToday($room, $now ?? $this->now($room), $bypassCache);
    }

    /** @return string PNG file contents */
    public function png(Room $room, bool $bypassCache = false, ?\DateTimeImmutable $now = null): string
    {
        $now ??= $this->now($room);
        $day = $this->todaysEvents($room, $bypassCache, $now);
        $builder = new ScreenBuilder(TimeFormat::fromSetting((string) $this->settings['time_format']));
        return (new Renderer())->render($builder->build($room, $day, $now));
    }
}
