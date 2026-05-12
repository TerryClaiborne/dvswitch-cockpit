<?php
declare(strict_types=1);

require __DIR__ . '/api/security.php';

dc_security_require_trusted_client();
dc_security_apply_headers();

if (!dc_security_auth_enabled()) {
    header('Location: index.php');
    exit;
}

if (dc_security_is_authenticated()) {
    header('Location: index.php');
    exit;
}

$error = '';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
    dc_security_require_csrf();

    $password = (string)($_POST['password'] ?? '');
    $hash = dc_security_admin_password_hash();

    if ($hash === '') {
        $error = 'Web login password is not set. Run setup_dvswitch_cockpit.sh --set-admin-password.';
    } elseif (password_verify($password, $hash)) {
        dc_security_login_success();
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid password.';
    }
}

$csrfToken = dc_security_csrf_token();

function dvc_e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DVSwitch Cockpit Login</title>
  <link rel="stylesheet" href="static/app.css">
</head>
<body class="cockpit-login-page">
  <main class="cockpit-login-shell">
    <section class="cockpit-login-card panel">
      <h1>DVSwitch Cockpit</h1>
      <p class="cockpit-login-subtitle">Sign in to restart DVSwitch services.</p>

      <?php if ($error !== ''): ?>
        <div class="cockpit-login-error"><?= dvc_e($error) ?></div>
      <?php endif; ?>

      <form method="post" action="login.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= dvc_e($csrfToken) ?>">

        <label for="password">Admin password</label>
        <input
          id="password"
          name="password"
          type="password"
          autocomplete="current-password"
          autofocus
          required
        >

        <button type="submit">Sign In</button>
      </form>

      <p class="cockpit-login-note">
        Status remains viewable without signing in. Service restart buttons require login.
      </p>

      <p class="cockpit-login-back">
        <a href="index.php">Back to dashboard</a>
      </p>
    </section>
  </main>
</body>
</html>
