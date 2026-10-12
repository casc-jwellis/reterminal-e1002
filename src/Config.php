<?php
declare(strict_types=1);

namespace Placard;

/**
 * JSON-file configuration: admin password hash, site settings and rooms.
 * Writes are serialised with a lock and replaced atomically.
 */
final class Config
{
    public const DEFAULTS = [
        'version'  => 1,
        'admin'    => ['password_hash' => ''],
        'settings' => [
            'google_api_key'   => '',
            'default_timezone' => 'UTC',
            'time_format'      => '12',
            'cache_ttl'        => 120,
            'public_base_url'  => '',
        ],
        'rooms'    => [],
    ];

    public function __construct(private readonly string $file)
    {
    }

    public static function default(): self
    {
        return new self(Paths::configFile());
    }

    public function load(): array
    {
        if (!is_file($this->file)) {
            return self::DEFAULTS;
        }
        $json = file_get_contents($this->file);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data)) {
            // Never silently fall back to defaults: that would discard the admin password.
            throw new \RuntimeException('Config file is unreadable or not valid JSON: ' . $this->file);
        }
        return [
            'version'  => 1,
            'admin'    => array_merge(self::DEFAULTS['admin'], (array) ($data['admin'] ?? [])),
            'settings' => array_merge(self::DEFAULTS['settings'], (array) ($data['settings'] ?? [])),
            'rooms'    => (array) ($data['rooms'] ?? []),
        ];
    }

    /**
     * Read-modify-write under an exclusive lock.
     * @param callable(array): array $change receives the current config, returns the new one
     */
    public function update(callable $change): array
    {
        Paths::ensure(dirname($this->file));
        $lock = fopen($this->file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock config file.');
        }
        try {
            $new = $change($this->load());
            Paths::writeAtomic($this->file, json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
            return $new;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, Room> */
    public function rooms(?array $config = null): array
    {
        $rooms = [];
        foreach (($config ?? $this->load())['rooms'] as $slug => $data) {
            $rooms[(string) $slug] = Room::fromArray((string) $slug, (array) $data);
        }
        return $rooms;
    }

    public function room(string $slug): ?Room
    {
        return $this->rooms()[$slug] ?? null;
    }
}
