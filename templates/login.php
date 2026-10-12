<?php /** @var string $csrf @var ?string $error */ ?>
<h1>Sign in</h1>
<?php if ($error !== null): ?>
  <p class="flash flash-error" role="alert"><?= e($error) ?></p>
<?php endif; ?>
<form method="post" action="index.php?p=login" class="card narrow">
  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
  <label>Password
    <input type="password" name="password" autocomplete="current-password" required autofocus>
  </label>
  <button type="submit" class="primary">Sign in</button>
</form>
