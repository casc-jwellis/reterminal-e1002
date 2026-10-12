<?php
/**
 * @var array $settings
 * @var bool $hasKey
 * @var string $keyHint
 * @var list<string> $zones
 * @var array<string, string> $errors
 * @var string $csrf
 */
$err = static fn (string $field): string => isset($errors[$field]) ? '<span class="field-error">' . e($errors[$field]) . '</span>' : '';
?>
<h1>Settings</h1>

<div class="two-col">
<form method="post" action="index.php?p=settings" class="card">
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <h2>Calendar connection</h2>

  <label>Google Calendar API key
    <input type="password" name="google_api_key" value="" autocomplete="off" spellcheck="false"
           placeholder="<?= $hasKey ? e($keyHint) . ' (saved; leave blank to keep)' : 'Paste your API key' ?>">
    <small>Create one in Google Cloud Console: enable the "Google Calendar API", then Credentials → Create credentials → API key. Restrict it to the Calendar API.</small>
    <?= $err('google_api_key') ?>
  </label>
  <?php if ($hasKey): ?>
    <label class="check"><input type="checkbox" name="clear_api_key" value="1"> Remove the saved API key</label>
  <?php endif; ?>

  <label>Cache calendar data for (seconds)
    <input type="number" name="cache_ttl" value="<?= e($settings['cache_ttl']) ?>" min="30" max="3600" required>
    <small>How long fetched events are reused before asking Google again.</small>
    <?= $err('cache_ttl') ?>
  </label>

  <h2>Display</h2>
  <label>Default time zone
    <input type="text" name="default_timezone" value="<?= e($settings['default_timezone']) ?>" list="zones" required autocomplete="off">
    <datalist id="zones">
      <?php foreach ($zones as $zone): ?><option value="<?= e($zone) ?>"><?php endforeach; ?>
    </datalist>
    <?= $err('default_timezone') ?>
  </label>

  <label>Time format
    <select name="time_format">
      <option value="12" <?= $settings['time_format'] === '12' ? 'selected' : '' ?>>12-hour (1:30 PM)</option>
      <option value="24" <?= $settings['time_format'] === '24' ? 'selected' : '' ?>>24-hour (13:30)</option>
    </select>
    <?= $err('time_format') ?>
  </label>

  <label>Public base URL (optional)
    <input type="url" name="public_base_url" value="<?= e($settings['public_base_url']) ?>" placeholder="https://placard.example.edu">
    <small>Only needed if the address shown in the image URLs is wrong, for example behind a proxy.</small>
    <?= $err('public_base_url') ?>
  </label>

  <button type="submit" class="primary">Save settings</button>
</form>

<form method="post" action="index.php?p=password" class="card">
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <h2>Change admin password</h2>
  <label>Current password
    <input type="password" name="current_password" autocomplete="current-password" required>
  </label>
  <label>New password
    <input type="password" name="new_password" autocomplete="new-password" minlength="10" required>
    <small>At least 10 characters.</small>
  </label>
  <label>Confirm new password
    <input type="password" name="confirm_password" autocomplete="new-password" minlength="10" required>
  </label>
  <button type="submit" class="primary">Change password</button>
</form>
</div>
