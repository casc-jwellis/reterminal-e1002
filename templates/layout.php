<?php /** @var string $title @var string $content @var string $csrf @var bool $loggedIn @var list<array{type:string,message:string}> $flashes */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?> · Room placard</title>
<link rel="stylesheet" href="../assets/admin.css">
<script src="../assets/admin.js" defer></script>
</head>
<body>
<header class="top">
  <a class="brand" href="index.php?p=rooms">Room placard</a>
  <?php if ($loggedIn): ?>
  <nav>
    <a href="index.php?p=rooms">Rooms</a>
    <a href="index.php?p=settings">Settings</a>
    <form method="post" action="index.php?p=logout">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <button type="submit" class="link">Sign out</button>
    </form>
  </nav>
  <?php endif; ?>
</header>
<main>
  <?php foreach ($flashes as $f): ?>
    <p class="flash <?= $f['type'] === 'error' ? 'flash-error' : 'flash-ok' ?>" role="status"><?= e($f['message']) ?></p>
  <?php endforeach; ?>
  <?= $content ?>
</main>
</body>
</html>
