<?php
declare(strict_types=1);

namespace Placard\Admin;

use Placard\Calendar\CalendarId;
use Placard\Config;
use Placard\Paths;
use Placard\Room;

/** First-run setup: server checks, then admin password, settings and an optional first room. */
final class Setup
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * What the server needs. A failed "required" check blocks setup; others are warnings.
     *
     * @return list<array{label: string, ok: bool, required: bool, detail: string}>
     */
    public static function requirements(): array
    {
        try {
            Paths::ensure(Paths::data());
            $writable = is_writable(Paths::data());
        } catch (\RuntimeException) {
            $writable = false;
        }
        $fonts = is_file(Paths::fonts() . '/LiberationSans-Regular.ttf') && is_file(Paths::fonts() . '/LiberationSans-Bold.ttf');
        $httpClient = function_exists('curl_init') || (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL) && extension_loaded('openssl'));

        return [
            ['label' => 'PHP 8.1 or newer',            'ok' => PHP_VERSION_ID >= 80100,        'required' => true,  'detail' => 'Running PHP ' . PHP_VERSION],
            ['label' => 'GD image extension with FreeType', 'ok' => function_exists('imagettftext'), 'required' => true,  'detail' => 'Needed to draw the placard image. Enable extension=gd in php.ini.'],
            ['label' => 'mbstring extension',          'ok' => function_exists('mb_strlen'),    'required' => true,  'detail' => 'Enable extension=mbstring in php.ini.'],
            ['label' => 'Fonts present',               'ok' => $fonts,                          'required' => true,  'detail' => 'The fonts/ directory is missing LiberationSans-Regular.ttf or LiberationSans-Bold.ttf.'],
            ['label' => 'Data directory is writable',  'ok' => $writable,                       'required' => true,  'detail' => Paths::data() . ' must be writable by the web server user.'],
            ['label' => 'Can make HTTPS requests',     'ok' => $httpClient,                     'required' => false, 'detail' => 'Needed to read Google Calendar. Enable the curl extension, or allow_url_fopen with openssl. The demo calendar works without it.'],
            ['label' => 'Page served over HTTPS',      'ok' => Auth::isHttps(),                 'required' => false, 'detail' => 'The admin password will be sent unencrypted. Use HTTPS for anything other than local testing.'],
        ];
    }

    /** @param list<array{required: bool, ok: bool}> $requirements */
    public static function passes(array $requirements): bool
    {
        foreach ($requirements as $r) {
            if ($r['required'] && !$r['ok']) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>} cleaned values, errors by field
     */
    public static function validate(array $post): array
    {
        $errors = [];
        $get = static fn (string $k): string => trim((string) ($post[$k] ?? ''));

        $password = (string) ($post['password'] ?? '');
        if (mb_strlen($password) < 10) {
            $errors['password'] = 'Use at least 10 characters.';
        } elseif ($password !== (string) ($post['confirm_password'] ?? '')) {
            $errors['confirm_password'] = 'The passwords do not match.';
        }

        $zone = $get('default_timezone');
        if (!in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            $errors['default_timezone'] = 'Choose a valid time zone, for example America/Chicago.';
        }

        $key = $get('google_api_key');
        if ($key !== '' && preg_match('/^[A-Za-z0-9_\-]{20,100}$/', $key) !== 1) {
            $errors['google_api_key'] = 'That does not look like a Google API key. You can also leave this blank and add it later.';
        }

        $roomName = $get('room_name');
        $calendar = CalendarId::normalize($get('room_calendar_id'));
        $color    = $get('room_color_mode');
        if ($roomName !== '' || $calendar !== '') {
            if ($roomName === '' || mb_strlen($roomName) > 60) {
                $errors['room_name'] = 'Enter a room name (up to 60 characters).';
            }
            if (!CalendarId::isValid($calendar)) {
                $errors['room_calendar_id'] = 'Enter a Google calendar ID (or "' . CalendarId::DEMO . '" for sample events).';
            }
            if (!isset(Room::COLOR_MODES[$color])) {
                $errors['room_color_mode'] = 'Choose a colour mode.';
            }
        }

        return [[
            'password'         => $password,
            'default_timezone' => $zone,
            'google_api_key'   => $key,
            'room_name'        => $roomName,
            'room_calendar_id' => $calendar,
            'room_color_mode'  => isset(Room::COLOR_MODES[$color]) ? $color : 'bwr',
        ], $errors];
    }

    /**
     * Writes the first configuration. Refuses if an admin password already exists, so a
     * second visitor (or a double submit) cannot overwrite it.
     *
     * @param array<string, string> $values from validate()
     * @return ?string the new room's ID, if one was created
     * @throws AlreadyConfigured
     */
    public function apply(array $values): ?string
    {
        $slug = $values['room_name'] !== '' ? Room::slugFromName($values['room_name']) : null;
        $hash = password_hash($values['password'], PASSWORD_DEFAULT);

        $this->config->update(static function (array $cfg) use ($values, $hash, $slug): array {
            if ($cfg['admin']['password_hash'] !== '') {
                throw new AlreadyConfigured('An admin password is already set.');
            }
            $cfg['admin']['password_hash'] = $hash;
            $cfg['settings']['default_timezone'] = $values['default_timezone'];
            $cfg['settings']['google_api_key'] = $values['google_api_key'];
            if ($slug !== null) {
                $cfg['rooms'][$slug] = Room::fromArray($slug, [
                    'name'        => $values['room_name'],
                    'calendar_id' => $values['room_calendar_id'],
                    'color_mode'  => $values['room_color_mode'],
                    'access_key'  => Room::newAccessKey(),
                ])->toArray();
            }
            return $cfg;
        });

        return $slug;
    }
}
