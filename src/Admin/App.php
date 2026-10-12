<?php
declare(strict_types=1);

namespace Placard\Admin;

use Placard\Calendar\CalendarId;
use Placard\Config;
use Placard\PlacardService;
use Placard\Room;

/** The admin pages. One front controller, routed by ?p=<page>. */
final class App
{
    private readonly Config $config;
    private readonly Auth $auth;

    public function __construct(?Config $config = null, ?Auth $auth = null)
    {
        $this->config = $config ?? Config::default();
        $this->auth = $auth ?? new Auth($this->config);
    }

    public function run(): void
    {
        $this->sendSecurityHeaders();
        $this->auth->start();

        $page = (string) ($_GET['p'] ?? 'rooms');
        $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

        // Until an admin password exists, the only page is first-run setup.
        if (!$this->auth->hasPassword()) {
            if ($page !== 'setup') {
                $this->redirect('setup');
            }
            $this->setup($post);
            return;
        }
        if ($page === 'setup') {
            $this->redirect($this->auth->isLoggedIn() ? 'rooms' : 'login');
        }

        if ($page === 'login') {
            $this->login($post);
            return;
        }
        if (!$this->auth->isLoggedIn()) {
            $this->redirect('login');
        }
        if ($post && !$this->auth->verifyCsrf((string) ($_POST['csrf'] ?? ''))) {
            http_response_code(400);
            $this->render('message', ['title' => 'Request rejected', 'message' => 'Your session token was missing or expired. Go back, reload the page and try again.']);
            return;
        }

        match (true) {
            $page === 'rooms'                => $this->rooms(),
            $page === 'room'                 => $post ? $this->saveRoom() : $this->roomForm(),
            $page === 'room-delete' && $post => $this->deleteRoom(),
            $page === 'room-key' && $post    => $this->regenerateKey(),
            $page === 'test'                 => $this->testCalendar(),
            $page === 'preview'              => $this->preview(),
            $page === 'settings'             => $post ? $this->saveSettings() : $this->settingsForm(),
            $page === 'password' && $post    => $this->changePassword(),
            $page === 'logout' && $post      => $this->logout(),
            default                          => $this->notFound(),
        };
    }

    // ---- authentication ------------------------------------------------

    private function login(bool $post): void
    {
        if ($this->auth->isLoggedIn()) {
            $this->redirect('rooms');
        }
        $error = null;
        if ($post) {
            if (!$this->auth->verifyCsrf((string) ($_POST['csrf'] ?? ''))) {
                $error = 'Your session expired. Please try again.';
            } else {
                $error = $this->auth->attempt((string) ($_POST['password'] ?? ''), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
                if ($error === null) {
                    $this->redirect('rooms');
                }
            }
        }
        $this->render('login', ['title' => 'Sign in', 'error' => $error]);
    }

    private function setup(bool $post): void
    {
        $requirements = Setup::requirements();
        $canRun = Setup::passes($requirements);
        $values = [
            'default_timezone' => date_default_timezone_get(),
            'google_api_key'   => '',
            'room_name'        => '',
            'room_calendar_id' => '',
            'room_color_mode'  => 'bwr',
        ];
        $errors = [];

        if ($post && $canRun) {
            if (!$this->auth->verifyCsrf((string) ($_POST['csrf'] ?? ''))) {
                $errors['form'] = 'Your session expired. Please try again.';
            } else {
                [$values, $errors] = Setup::validate($_POST);
                if ($errors === []) {
                    try {
                        $slug = (new Setup($this->config))->apply($values);
                    } catch (AlreadyConfigured) {
                        $this->redirect('login');
                    }
                    $this->auth->signIn();
                    $this->flash('Setup complete. You are signed in.');
                    if ($slug !== null) {
                        $this->flash('Copy the image URL below into SenseCraft HMI.');
                        $this->redirect('room', ['room' => $slug]);
                    }
                    $this->redirect('rooms');
                }
            }
        }

        $this->render('setup', [
            'title'        => 'Setup',
            'requirements' => $requirements,
            'canRun'       => $canRun,
            'values'       => $values,
            'errors'       => $errors,
            'zones'        => \DateTimeZone::listIdentifiers(),
        ]);
    }

    private function logout(): void
    {
        $this->auth->logout();
        header('Location: index.php?p=login');
        exit;
    }

    // ---- rooms ---------------------------------------------------------

    private function rooms(): void
    {
        $this->render('rooms', ['title' => 'Rooms', 'rooms' => $this->config->rooms()]);
    }

    private function roomForm(): void
    {
        $slug = (string) ($_GET['room'] ?? '');
        if ($slug === '') {
            $this->renderRoomForm(Room::fromArray('', ['access_key' => Room::newAccessKey()]), true);
            return;
        }
        $room = $this->config->room($slug);
        if ($room === null) {
            $this->notFound();
            return;
        }
        $this->renderRoomForm($room, false);
    }

    private function renderRoomForm(Room $room, bool $isNew, array $errors = []): void
    {
        $this->render('room', [
            'title'    => $isNew ? 'Add room' : $room->name,
            'room'     => $room,
            'isNew'    => $isNew,
            'errors'   => $errors,
            'zones'    => \DateTimeZone::listIdentifiers(),
            'settings' => $this->config->load()['settings'],
            'urls'     => $isNew ? null : $this->placardUrls($room),
        ]);
    }

    private function saveRoom(): void
    {
        $isNew = (string) ($_POST['is_new'] ?? '') === '1';
        $slug  = $isNew ? strtolower(trim((string) ($_POST['slug'] ?? ''))) : (string) ($_GET['room'] ?? '');
        $existing = $isNew ? null : $this->config->room($slug);
        if (!$isNew && $existing === null) {
            $this->notFound();
            return;
        }

        $errors = [];
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) {
            $errors['name'] = 'Enter a room name (up to 60 characters).';
        }
        if ($isNew) {
            if (!Room::isValidSlug($slug)) {
                $errors['slug'] = 'Use 1-40 lowercase letters, digits and hyphens (no leading or trailing hyphen).';
            } elseif ($this->config->room($slug) !== null) {
                $errors['slug'] = 'A room with this ID already exists.';
            }
        }
        $calendarId = CalendarId::normalize((string) ($_POST['calendar_id'] ?? ''));
        if (!CalendarId::isValid($calendarId)) {
            $errors['calendar_id'] = 'Enter a Google calendar ID (or "' . CalendarId::DEMO . '" for sample events).';
        }
        $timezone = trim((string) ($_POST['timezone'] ?? ''));
        if ($timezone !== '' && !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Unknown time zone.';
        }
        $colorMode = (string) ($_POST['color_mode'] ?? '');
        if (!isset(Room::COLOR_MODES[$colorMode])) {
            $errors['color_mode'] = 'Choose a colour mode.';
        }
        $statusMode = (string) ($_POST['status_mode'] ?? '');
        if (!isset(Room::STATUS_MODES[$statusMode])) {
            $errors['status_mode'] = 'Choose a status.';
        }
        $label = trim((string) ($_POST['custom_label'] ?? ''));
        $message = trim((string) ($_POST['custom_message'] ?? ''));
        if (mb_strlen($label) > 20) {
            $errors['custom_label'] = 'Keep the label to 20 characters or fewer.';
        }
        if (mb_strlen($message) > 120) {
            $errors['custom_message'] = 'Keep the message to 120 characters or fewer.';
        }
        if ($statusMode === 'custom' && $label === '') {
            $errors['custom_label'] = 'Enter the label to show (for example CLOSED).';
        }

        $room = Room::fromArray($slug, [
            'name'           => $name,
            'calendar_id'    => $calendarId,
            'timezone'       => $timezone,
            'color_mode'     => $colorMode,
            'status_mode'    => $statusMode,
            'custom_label'   => $label,
            'custom_message' => $message,
            'access_key'     => $existing?->accessKey ?? Room::newAccessKey(),
        ]);

        if ($errors !== []) {
            $this->renderRoomForm($room, $isNew, $errors);
            return;
        }

        $this->config->update(static function (array $cfg) use ($room, $slug): array {
            $cfg['rooms'][$slug] = $room->toArray();
            return $cfg;
        });
        $this->flash('Room saved.');
        $this->redirect('room', ['room' => $slug]);
    }

    private function deleteRoom(): void
    {
        $slug = (string) ($_POST['room'] ?? '');
        $this->config->update(static function (array $cfg) use ($slug): array {
            unset($cfg['rooms'][$slug]);
            return $cfg;
        });
        $this->flash('Room deleted.');
        $this->redirect('rooms');
    }

    private function regenerateKey(): void
    {
        $slug = (string) ($_POST['room'] ?? '');
        if ($this->config->room($slug) === null) {
            $this->notFound();
            return;
        }
        $key = Room::newAccessKey();
        $this->config->update(static function (array $cfg) use ($slug, $key): array {
            $cfg['rooms'][$slug]['access_key'] = $key;
            return $cfg;
        });
        $this->flash('New access key generated. Update the image URL on the display.');
        $this->redirect('room', ['room' => $slug]);
    }

    private function testCalendar(): void
    {
        $room = $this->config->room((string) ($_GET['room'] ?? ''));
        if ($room === null) {
            $this->notFound();
            return;
        }
        $service = new PlacardService($this->config);
        $now = $service->now($room);
        $day = $service->todaysEvents($room, true, $now);

        $this->render('test', [
            'title' => 'Calendar test: ' . $room->name,
            'room'  => $room,
            'day'   => $day,
            'now'   => $now,
        ]);
    }

    /** Renders the room's image for the logged-in admin (no access key needed). */
    private function preview(): void
    {
        $room = $this->config->room((string) ($_GET['room'] ?? ''));
        if ($room === null) {
            $this->notFound();
            return;
        }
        try {
            $png = (new PlacardService($this->config))->png($room);
        } catch (\Throwable $e) {
            error_log('admin preview: ' . $e);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Could not render the preview.\n";
            return;
        }
        header('Content-Type: image/png');
        header('Cache-Control: no-store');
        echo $png;
    }

    // ---- settings ------------------------------------------------------

    private function settingsForm(array $errors = [], ?array $values = null): void
    {
        $settings = $values ?? $this->config->load()['settings'];
        $this->render('settings', [
            'title'    => 'Settings',
            'settings' => $settings,
            'hasKey'   => $this->config->load()['settings']['google_api_key'] !== '',
            'keyHint'  => $this->keyHint((string) $this->config->load()['settings']['google_api_key']),
            'zones'    => \DateTimeZone::listIdentifiers(),
            'errors'   => $errors,
        ]);
    }

    private function saveSettings(): void
    {
        $current = $this->config->load()['settings'];
        $errors = [];

        $values = $current;
        $newKey = trim((string) ($_POST['google_api_key'] ?? ''));
        if (isset($_POST['clear_api_key'])) {
            $values['google_api_key'] = '';
        } elseif ($newKey !== '') {
            if (preg_match('/^[A-Za-z0-9_\-]{20,100}$/', $newKey) !== 1) {
                $errors['google_api_key'] = 'That does not look like a Google API key.';
            }
            $values['google_api_key'] = $newKey;
        }

        $zone = trim((string) ($_POST['default_timezone'] ?? ''));
        if (!in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            $errors['default_timezone'] = 'Choose a valid time zone.';
        }
        $values['default_timezone'] = $zone;

        $format = (string) ($_POST['time_format'] ?? '');
        if (!in_array($format, ['12', '24'], true)) {
            $errors['time_format'] = 'Choose 12-hour or 24-hour.';
        }
        $values['time_format'] = $format;

        $ttl = filter_var($_POST['cache_ttl'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 30, 'max_range' => 3600]]);
        if ($ttl === false) {
            $errors['cache_ttl'] = 'Enter a number of seconds between 30 and 3600.';
        }
        $values['cache_ttl'] = $ttl === false ? $current['cache_ttl'] : $ttl;

        $base = rtrim(trim((string) ($_POST['public_base_url'] ?? '')), '/');
        if ($base !== '' && (filter_var($base, FILTER_VALIDATE_URL) === false || !in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true))) {
            $errors['public_base_url'] = 'Enter a full http(s) URL, or leave blank.';
        }
        $values['public_base_url'] = $base;

        if ($errors !== []) {
            // Do not echo a rejected key back into the page.
            $values['google_api_key'] = $current['google_api_key'];
            $this->settingsForm($errors, $values);
            return;
        }

        $this->config->update(static function (array $cfg) use ($values): array {
            $cfg['settings'] = array_merge($cfg['settings'], $values);
            return $cfg;
        });
        $this->flash('Settings saved.');
        $this->redirect('settings');
    }

    private function changePassword(): void
    {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if (!$this->auth->verifyPassword($current)) {
            $this->flash('Current password is incorrect.', 'error');
        } elseif (mb_strlen($new) < 10) {
            $this->flash('The new password must be at least 10 characters.', 'error');
        } elseif ($new !== $confirm) {
            $this->flash('The new passwords do not match.', 'error');
        } else {
            $this->auth->setPassword($new);
            session_regenerate_id(true);
            $this->flash('Password changed.');
        }
        $this->redirect('settings');
    }

    // ---- helpers -------------------------------------------------------

    /** The URLs a display can use to fetch this room's image. */
    private function placardUrls(Room $room): array
    {
        $settings = $this->config->load()['settings'];
        $base = (string) $settings['public_base_url'];
        if ($base === '') {
            $scheme = Auth::isHttps() ? 'https' : 'http';
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $path = rtrim(str_replace('\\', '/', dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php')))), '/');
            $base = "$scheme://$host$path";
        }
        return [
            'query'  => $base . '/placard.php?' . http_build_query(['room' => $room->slug, 'key' => $room->accessKey]),
            'pretty' => $base . '/placard/' . $room->slug . '/' . $room->accessKey . '.png',
        ];
    }

    private function keyHint(string $key): string
    {
        return $key === '' ? '' : str_repeat('•', 8) . substr($key, -4);
    }

    private function flash(string $message, string $type = 'ok'): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    private function redirect(string $page, array $params = []): never
    {
        header('Location: index.php?' . http_build_query(['p' => $page] + $params));
        exit;
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->render('message', ['title' => 'Not found', 'message' => 'That page does not exist.']);
    }

    private function sendSecurityHeaders(): void
    {
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'self'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
    }

    /** Renders templates/<name>.php inside templates/layout.php. */
    private function render(string $template, array $vars = []): void
    {
        $vars += [
            'csrf'     => $this->auth->csrfToken(),
            'loggedIn' => $this->auth->isLoggedIn(),
            'flashes'  => $_SESSION['flash'] ?? [],
        ];
        unset($_SESSION['flash']);

        $dir = dirname(__DIR__, 2) . '/templates';
        $content = (static function (string $file, array $vars): string {
            extract($vars, EXTR_SKIP);
            ob_start();
            require $file;
            return (string) ob_get_clean();
        })("$dir/$template.php", $vars);

        (static function (string $file, array $vars, string $content): void {
            extract($vars, EXTR_SKIP);
            require $file;
        })("$dir/layout.php", $vars, $content);
    }
}
