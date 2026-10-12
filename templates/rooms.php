<?php /** @var array<string, \Placard\Room> $rooms */ ?>
<div class="row-between">
  <h1>Rooms</h1>
  <a class="button primary" href="index.php?p=room">Add room</a>
</div>

<?php if ($rooms === []): ?>
  <p class="card">No rooms yet. Add one to get an image URL for the display. Use the calendar ID <code>demo</code> to try it with sample events.</p>
<?php endif; ?>

<div class="grid">
<?php foreach ($rooms as $room): ?>
  <section class="card room-card">
    <img class="thumb" loading="lazy" width="400" height="240" alt="Current placard for <?= e($room->name) ?>"
         src="index.php?p=preview&amp;room=<?= e(rawurlencode($room->slug)) ?>">
    <h2><?= e($room->name) ?></h2>
    <p class="muted"><?= e($room->calendarId) ?></p>
    <p>
      <a class="button" href="index.php?p=room&amp;room=<?= e(rawurlencode($room->slug)) ?>">Edit</a>
      <a class="button" href="index.php?p=test&amp;room=<?= e(rawurlencode($room->slug)) ?>">Test calendar</a>
    </p>
  </section>
<?php endforeach; ?>
</div>
