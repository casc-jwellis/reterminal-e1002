<?php
/**
 * @var \Placard\Room $room
 * @var \Placard\Calendar\DayEvents $day
 * @var \DateTimeImmutable $now
 */
?>
<div class="row-between">
  <h1>Calendar test: <?= e($room->name) ?></h1>
  <a class="button" href="index.php?p=room&amp;room=<?= e(rawurlencode($room->slug)) ?>">Back to room</a>
</div>

<p class="muted">Calendar <code><?= e($room->calendarId) ?></code> · today is <?= e($now->format('l, F j, Y')) ?> · time zone <?= e($now->getTimezone()->getName()) ?> · fetched fresh just now</p>

<?php if ($day->error !== null): ?>
  <p class="flash flash-error" role="alert"><?= e($day->error) ?><?= $day->stale ? ' (showing older saved data)' : '' ?></p>
<?php elseif ($day->hasData()): ?>
  <p class="flash flash-ok">Connected. Found <?= count($day->events) ?> event(s) today.</p>
<?php endif; ?>

<?php if ($day->events !== []): ?>
<table class="card">
  <thead><tr><th>Event</th><th>Starts</th><th>Ends</th><th>Notes</th></tr></thead>
  <tbody>
  <?php foreach ($day->events as $event): ?>
    <tr>
      <td><?= e($event->title) ?></td>
      <td><?= e($event->allDay ? 'All day' : $event->start->format('D g:i A')) ?></td>
      <td><?= e($event->allDay ? '' : $event->end->format('D g:i A')) ?></td>
      <td><?= e(implode(', ', array_filter([$event->allDay ? 'all-day' : '', $event->busy ? '' : 'marked free (does not make the room occupied)']))) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php elseif ($day->error === null): ?>
  <p class="card">The calendar is reachable but has no events today.</p>
<?php endif; ?>
