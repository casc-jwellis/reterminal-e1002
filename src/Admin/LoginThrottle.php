<?php
declare(strict_types=1);

namespace Placard\Admin;

use Placard\Paths;

/** Locks out an IP address for a while after too many wrong passwords. */
final class LoginThrottle
{
    private const MAX_FAILURES = 5;
    private const WINDOW = 900;
    private const LOCKOUT = 900;

    private readonly string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?? Paths::data() . '/login-throttle.json';
    }

    /** Seconds the address must still wait, or 0 if it may try. */
    public function remaining(string $ip): int
    {
        return $this->withData(function (array &$data, int $now) use ($ip): int {
            return max(0, (int) ($data[$this->key($ip)]['until'] ?? 0) - $now);
        });
    }

    public function fail(string $ip): void
    {
        $this->withData(function (array &$data, int $now) use ($ip): void {
            $k = $this->key($ip);
            $entry = $data[$k] ?? ['count' => 0, 'first' => $now, 'until' => 0];
            if ($entry['first'] < $now - self::WINDOW) {
                $entry = ['count' => 0, 'first' => $now, 'until' => 0];
            }
            $entry['count']++;
            if ($entry['count'] >= self::MAX_FAILURES) {
                $entry['until'] = $now + self::LOCKOUT;
                $entry['count'] = 0;
                $entry['first'] = $now;
            }
            $data[$k] = $entry;
        });
    }

    public function clear(string $ip): void
    {
        $this->withData(function (array &$data) use ($ip): void {
            unset($data[$this->key($ip)]);
        });
    }

    private function key(string $ip): string
    {
        return sha1($ip);
    }

    /**
     * @template T
     * @param callable(array, int): T $fn
     * @return T
     */
    private function withData(callable $fn): mixed
    {
        Paths::ensure(dirname($this->file));
        $fh = fopen($this->file, 'c+');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            throw new \RuntimeException('Cannot open login throttle file.');
        }
        try {
            $data = json_decode((string) stream_get_contents($fh), true);
            $data = is_array($data) ? $data : [];
            $now = time();
            foreach ($data as $k => $entry) {
                if (($entry['until'] ?? 0) < $now && ($entry['first'] ?? 0) < $now - self::WINDOW) {
                    unset($data[$k]);
                }
            }
            $result = $fn($data, $now);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($data));
            return $result;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
