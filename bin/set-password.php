<?php
declare(strict_types=1);

// Sets (or resets) the admin password. Run on the server:  php bin/set-password.php
// The web admin has no first-run setup page on purpose: nobody can claim it by getting there first.

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require __DIR__ . '/../src/bootstrap.php';

function prompt(string $label): string
{
    echo $label;
    $hide = DIRECTORY_SEPARATOR === '/' && function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($hide) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($hide) {
        shell_exec('stty echo');
        echo "\n";
    }
    return rtrim($line === false ? '' : $line, "\r\n");
}

$password = $argv[1] ?? prompt('New admin password (min 10 characters): ');
if (mb_strlen($password) < 10) {
    exit("Password must be at least 10 characters.\n");
}
if (!isset($argv[1]) && $password !== prompt('Repeat password: ')) {
    exit("Passwords do not match.\n");
}

$hash = password_hash($password, PASSWORD_DEFAULT);
Placard\Config::default()->update(static function (array $cfg) use ($hash): array {
    $cfg['admin']['password_hash'] = $hash;
    return $cfg;
});

echo "Admin password saved to " . Placard\Paths::configFile() . "\n";
