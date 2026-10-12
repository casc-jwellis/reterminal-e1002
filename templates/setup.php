<?php
/**
 * @var list<array{label: string, ok: bool, required: bool, detail: string}> $requirements
 * @var bool $canRun
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var list<string> $zones
 * @var string $csrf
 */
$err = static fn (string $field): string => isset($errors[$field]) ? '<span class="field-error">' . e($errors[$field]) . '</span>' : '';
?>
<h1>Welcome: let's set up the room placard</h1>
<p class="muted">This page appears only until an admin password is created. Finish it now so nobody else can.</p>

<section class="card">
  <h2>Server check</h2>
  <ul class="checks">
  <?php foreach ($requirements as $r): ?>
    <li class="<?= $r['ok'] ? 'check-ok' : ($r['required'] ? 'check-fail' : 'check-warn') ?>">
      <strong><?= $r['ok'] ? 'OK' : ($r['required'] ? 'Problem' : 'Warning') ?>:</strong>
      <?= e($r['label']) ?>
      <?php if (!$r['ok'] || $r['label'] === 'PHP 8.1 or newer'): ?><small><?= e($r['detail']) ?></small><?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php if (!$canRun): ?>
    <p class="flash flash-error">Fix the problems above, then reload this page.</p>
  <?php endif; ?>
</section>

<?php if ($canRun): ?>
<?php if (isset($errors['form'])): ?>
  <p class="flash flash-error" role="alert"><?= e($errors['form']) ?></p>
<?php elseif ($errors !== []): ?>
  <p class="flash flash-error" role="alert">Please fix the highlighted fields.</p>
<?php endif; ?>

<form method="post" action="index.php?p=setup" class="card">
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

  <h2>Admin password</h2>
  <label>Password
    <input type="password" name="password" autocomplete="new-password" minlength="10" required autofocus>
    <small>At least 10 characters. You will use it to sign in to this admin area.</small>
    <?= $err('password') ?>
  </label>
  <label>Confirm password
    <input type="password" name="confirm_password" autocomplete="new-password" minlength="10" required>
    <?= $err('confirm_password') ?>
  </label>

  <h2>Site settings</h2>
  <label>Time zone
    <input type="text" name="default_timezone" value="<?= e($values['default_timezone']) ?>" list="zones" required autocomplete="off" data-detect-timezone>
    <datalist id="zones">
      <?php foreach ($zones as $zone): ?><option value="<?= e($zone) ?>"><?php endforeach; ?>
    </datalist>
    <small>Event times are shown in this zone, for example <code>America/Chicago</code>.</small>
    <?= $err('default_timezone') ?>
  </label>
  <label>Google Calendar API key (optional)
    <input type="password" name="google_api_key" value="" autocomplete="off" spellcheck="false">
    <small>Create one in Google Cloud Console: enable the "Google Calendar API", then Credentials → Create credentials → API key. You can add it later under Settings.</small>
    <?= $err('google_api_key') ?>
  </label>

  <h2>First room (optional)</h2>
  <label>Room name
    <input type="text" name="room_name" value="<?= e($values['room_name']) ?>" maxlength="60" placeholder="Conference Room B">
    <?= $err('room_name') ?>
  </label>
  <label>Google calendar ID
    <input type="text" name="room_calendar_id" value="<?= e($values['room_calendar_id']) ?>" placeholder="abc123@group.calendar.google.com" autocomplete="off" spellcheck="false">
    <small>The calendar must be public with "See all event details". Enter <code>demo</code> to try sample events first.</small>
    <?= $err('room_calendar_id') ?>
  </label>
  <label>Display colours
    <select name="room_color_mode">
      <?php foreach (\Placard\Room::COLOR_MODES as $value => $label): ?>
        <option value="<?= e($value) ?>" <?= $values['room_color_mode'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <small>Match the colour mode set on the display in SenseCraft.</small>
    <?= $err('room_color_mode') ?>
  </label>

  <button type="submit" class="primary">Finish setup</button>
</form>
<?php endif; ?>
