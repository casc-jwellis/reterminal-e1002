<?php
/**
 * @var \Placard\Room $room
 * @var bool $isNew
 * @var array<string, string> $errors
 * @var list<string> $zones
 * @var array $settings
 * @var ?array{query: string, pretty: string} $urls
 * @var string $csrf
 */
$err = static fn (string $field): string => isset($errors[$field]) ? '<span class="field-error">' . e($errors[$field]) . '</span>' : '';
$action = $isNew ? 'index.php?p=room' : 'index.php?p=room&room=' . rawurlencode($room->slug);
?>
<div class="row-between">
  <h1><?= $isNew ? 'Add room' : e($room->name) ?></h1>
  <?php if (!$isNew): ?>
    <a class="button" href="index.php?p=test&amp;room=<?= e(rawurlencode($room->slug)) ?>">Test calendar</a>
  <?php endif; ?>
</div>

<?php if ($errors !== []): ?>
  <p class="flash flash-error" role="alert">Please fix the highlighted fields.</p>
<?php endif; ?>

<div class="two-col">
<form method="post" action="<?= e($action) ?>" class="card">
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <?php if ($isNew): ?><input type="hidden" name="is_new" value="1"><?php endif; ?>

  <label>Room name
    <input type="text" name="name" value="<?= e($room->name) ?>" maxlength="60" required>
    <?= $err('name') ?>
  </label>

  <?php if ($isNew): ?>
  <label>Room ID
    <input type="text" name="slug" value="<?= e($room->slug) ?>" maxlength="40" pattern="[a-z0-9]([a-z0-9\-]*[a-z0-9])?" required>
    <small>Used in the image URL, for example <code>conference-b</code>. It cannot be changed later.</small>
    <?= $err('slug') ?>
  </label>
  <?php endif; ?>

  <label>Google calendar ID
    <input type="text" name="calendar_id" value="<?= e($room->calendarId) ?>" placeholder="abc123@group.calendar.google.com" autocomplete="off" spellcheck="false" required>
    <small>Google Calendar → Settings → the calendar → Integrate calendar → Calendar ID. The calendar must be public with "See all event details". Enter <code>demo</code> for sample events.</small>
    <?= $err('calendar_id') ?>
  </label>

  <label>Status
    <select name="status_mode" data-controls="custom-status">
      <?php foreach (\Placard\Room::STATUS_MODES as $value => $label): ?>
        <option value="<?= e($value) ?>" <?= $room->statusMode === $value ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <?= $err('status_mode') ?>
  </label>

  <div id="custom-status" data-show-when="custom">
    <label>Custom label
      <input type="text" name="custom_label" value="<?= e($room->customLabel) ?>" maxlength="20" placeholder="CLOSED">
      <?= $err('custom_label') ?>
    </label>
    <label>Message (optional)
      <input type="text" name="custom_message" value="<?= e($room->customMessage) ?>" maxlength="120" placeholder="Back at 1 PM">
      <?= $err('custom_message') ?>
    </label>
  </div>

  <label>Time zone
    <input type="text" name="timezone" value="<?= e($room->timezone) ?>" list="zones" placeholder="Default: <?= e($settings['default_timezone']) ?>" autocomplete="off">
    <datalist id="zones">
      <?php foreach ($zones as $zone): ?><option value="<?= e($zone) ?>"><?php endforeach; ?>
    </datalist>
    <small>Leave blank to use the site default.</small>
    <?= $err('timezone') ?>
  </label>

  <label>Display colours
    <select name="color_mode">
      <?php foreach (\Placard\Room::COLOR_MODES as $value => $label): ?>
        <option value="<?= e($value) ?>" <?= $room->colorMode === $value ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <small>Match the colour mode set on the display in SenseCraft.</small>
    <?= $err('color_mode') ?>
  </label>

  <button type="submit" class="primary"><?= $isNew ? 'Add room' : 'Save' ?></button>
</form>

<?php if (!$isNew && $urls !== null): ?>
<aside>
  <section class="card">
    <h2>Preview</h2>
    <img class="preview" width="800" height="480" alt="Current placard image" src="index.php?p=preview&amp;room=<?= e(rawurlencode($room->slug)) ?>">
    <p><button type="button" class="reload-preview">Refresh preview</button></p>
  </section>

  <section class="card">
    <h2>Image URL for the display</h2>
    <p>Paste this into SenseCraft as the image URL. Anyone with the link can see this room's schedule, so keep it private.</p>
    <div class="copy-row">
      <input type="text" readonly value="<?= e($urls['query']) ?>" aria-label="Image URL">
      <button type="button" class="copy">Copy</button>
    </div>
    <details>
      <summary>Need a URL that ends in .png?</summary>
      <p>This form needs the rewrite rule from <code>nginx.conf.example</code>.</p>
      <div class="copy-row">
        <input type="text" readonly value="<?= e($urls['pretty']) ?>" aria-label="Image URL ending in .png">
        <button type="button" class="copy">Copy</button>
      </div>
    </details>
    <form method="post" action="index.php?p=room-key" data-confirm="Generate a new access key? The old image URL will stop working until you update it on the display.">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="room" value="<?= e($room->slug) ?>">
      <button type="submit">Generate new access key</button>
    </form>
  </section>

  <section class="card danger">
    <h2>Delete room</h2>
    <form method="post" action="index.php?p=room-delete" data-confirm="Delete this room? Its image URL will stop working.">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="room" value="<?= e($room->slug) ?>">
      <button type="submit" class="danger-button">Delete <?= e($room->name) ?></button>
    </form>
  </section>
</aside>
<?php endif; ?>
</div>
