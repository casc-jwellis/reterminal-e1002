# Room placard for the reTerminal E1002

A small PHP app that turns a public Google Calendar into a room placard for a Seeed reTerminal E1002 e-ink display running the stock SenseCraft HMI firmware.

```
Public Google Calendar ──API key──> PHP server ──PNG image──> SenseCraft HMI ──> E1002
                                        ▲
                                   admin page (password)
```

The display cannot render web pages, so the server draws the placard (room name, availability, today's schedule) as an 800×480 PNG and the display fetches it from a URL on a refresh interval. No Composer packages or JavaScript libraries are used.

## Requirements

- PHP 8.1 or newer (written for 8.5; developed and tested on 8.1) with the **gd** (with FreeType), **mbstring**, **json** and **session** extensions. **curl** is used when present, otherwise `allow_url_fopen`.
- nginx with PHP-FPM (see [nginx.conf.example](nginx.conf.example)).
- A Google Cloud API key for the Calendar API, and a calendar shared publicly with **See all event details**.

## Set up

1. **Google API key.** In Google Cloud Console, create a project, enable the *Google Calendar API*, then *Credentials → Create credentials → API key*. Restrict the key to the Calendar API (and to your server's IP address if it has a fixed one).
2. **Calendar.** In Google Calendar open the calendar's settings, enable *Make available to public → See all event details*, and copy the *Calendar ID* from *Integrate calendar*.
3. **Install.** Copy this repository to the server and point nginx at the `public/` directory. Make `data/` writable by the PHP-FPM user (or set `PLACARD_DATA_DIR`, see the nginx example). It holds the config (password hash, API key), cache and sessions and must not be web-accessible; with `public/` as the web root it is not.
4. **Run setup in the browser.** Open `/admin/`. While no admin password exists, you are taken to a setup page that checks the server (GD/FreeType, mbstring, writable `data/`, and so on), then lets you choose the admin password and time zone, enter the API key (optional) and add your first room. When you finish you are signed in and shown the room's image URL. **Do this right after deploying:** until it is done, the first visitor to `/admin/` can claim the admin account. Once a password exists, the setup page is closed for good.
5. **Configure.** Add more rooms or change settings any time from the admin pages. Use *Test calendar* on a room to confirm the Google connection. The calendar ID `demo` shows sample events without Google.
6. **Display.** In SenseCraft HMI set the display colour mode to match the room's *Display colours*, add an image from URL using the *Image URL for the display* shown on the room page, and choose a refresh interval. Frequent refreshes wear e-ink panels; every 5 to 15 minutes is typical for a placard.

## How it behaves

- **Status** comes from the calendar: a current event makes the room *Occupied* until the end of any back-to-back events, otherwise *Available until* the next event. Events marked "free" in Google Calendar and cancelled events do not occupy the room. All-day events do. A room can instead be forced Available, Occupied, or given a custom label such as *CLOSED* with a message.
- **Caching.** Events are cached on disk (default 2 minutes) so display refreshes do not each call Google.
- **Outages.** If Google cannot be reached, the last good data (up to 6 hours old) is drawn with an "Offline - data from …" note. With no data at all the placard shows *UNKNOWN*.
- **Several rooms.** Add as many rooms as you like; each has its own calendar, time zone, colour mode and image URL.
- **Image URLs** contain a per-room random access key. Anyone with the link can see that room's schedule. Use *Generate new access key* if it leaks. A URL ending in `.png` (`/placard/<room>/<key>.png`) is available through the rewrite in the nginx example.

## Forgotten password

Change it from *Settings* while signed in. If you are locked out, either run `php bin/set-password.php` on the server, or delete `data/config.json` to run the browser setup again (this also removes your rooms and API key).

## Security notes

Admin sign-in uses `password_hash`, per-session CSRF tokens, a strict cookie (`HttpOnly`, `SameSite=Strict`, `Secure` over HTTPS), idle timeout, and a lockout after 5 wrong passwords from an address. The Google API key is sent in a request header, never in a URL, and is never shown again after saving. Serve the admin over HTTPS.

## Development

```
php -d extension=gd tests/run.php                       # run the tests
php -d extension=gd -S localhost:8080 -t public bin/dev-router.php   # local server (set PLACARD_DATA_DIR to keep test data elsewhere)
php -d extension=gd bin/render-sample.php bwr occupied  # render a sample PNG from the demo calendar
```

Layout of the code: `src/Calendar` (Google client, cache, demo data), `src/Display` (screen model and PNG renderer), `src/Admin` (login and pages), `templates/` (admin HTML), `public/` (the only web-accessible files). Fonts in `fonts/` are Liberation Sans, licensed under the SIL Open Font License.
