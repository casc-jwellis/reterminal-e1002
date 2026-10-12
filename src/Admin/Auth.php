<?php
declare(strict_types=1);

namespace Placard\Admin;

use Placard\Config;
use Placard\Paths;

/** Session-based admin login with CSRF tokens and brute-force throttling. */
final class Auth
{
    private const IDLE_TIMEOUT = 7200;

    public function __construct(
        private readonly Config $config,
        private readonly LoginThrottle $throttle = new LoginThrottle(),
    ) {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        // Distro PHP packages disable session GC and clean only the default save path from cron; ours needs it on.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.gc_maxlifetime', (string) (self::IDLE_TIMEOUT + 600));
        try {
            session_save_path(Paths::sessions());
        } catch (\RuntimeException) {
            // Data directory not writable: use PHP's default so the setup page can still explain the problem.
        }
        session_name('placard_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => self::cookiePath(),
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();

        if (isset($_SESSION['last_seen']) && time() - (int) $_SESSION['last_seen'] > self::IDLE_TIMEOUT) {
            $this->logout();
            session_start();
        }
        $_SESSION['last_seen'] = time();
    }

    public static function isHttps(): bool
    {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    private static function cookiePath(): string
    {
        $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'))), '/');
        return $dir === '' ? '/' : $dir;
    }

    public function hasPassword(): bool
    {
        return $this->config->load()['admin']['password_hash'] !== '';
    }

    public function isLoggedIn(): bool
    {
        return ($_SESSION['admin'] ?? false) === true;
    }

    /** @return ?string an error message, or null when the login succeeded */
    public function attempt(string $password, string $ip): ?string
    {
        $hash = (string) $this->config->load()['admin']['password_hash'];
        if ($hash === '') {
            return 'Setup has not been completed yet.';
        }

        $wait = $this->throttle->remaining($ip);
        if ($wait > 0) {
            return 'Too many failed attempts. Try again in ' . (int) ceil($wait / 60) . ' minute(s).';
        }

        if (!password_verify($password, $hash)) {
            $this->throttle->fail($ip);
            return 'Incorrect password.';
        }

        $this->throttle->clear($ip);
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->setPassword($password);
        }
        $this->signIn();
        return null;
    }

    /** Marks the current session as signed in (with a fresh session ID and CSRF token). */
    public function signIn(): void
    {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public function verifyPassword(string $password): bool
    {
        $hash = (string) $this->config->load()['admin']['password_hash'];
        return $hash !== '' && password_verify($password, $hash);
    }

    public function setPassword(string $password): void
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->config->update(static function (array $cfg) use ($hash): array {
            $cfg['admin']['password_hash'] = $hash;
            return $cfg;
        });
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public function verifyCsrf(string $token): bool
    {
        return $token !== '' && hash_equals($this->csrfToken(), $token);
    }
}
