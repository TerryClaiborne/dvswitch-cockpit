<?php declare(strict_types=1);

require __DIR__ . '/api/security.php';

dc_security_apply_headers();

if (dc_security_password_hash_from_config() === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Setup required</title>'
        . '<p>DVSwitch Cockpit login is not configured. On the node, run '
        . '<code>sudo ./setup_dvswitch_cockpit.sh</code> or '
        . '<code>sudo php ' . htmlspecialchars(__DIR__, ENT_QUOTES) . '/tools/bootstrap_auth.php</code>.</p>';
    exit;
}

dc_security_session_start();

$returnGet = isset($_GET['return']) ? (string) $_GET['return'] : '';
$target = dc_security_safe_return_target($returnGet);

if (dc_security_is_authenticated()) {
    header('Location: ' . $target);
    exit;
}

$error = '';
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
    dc_security_rate_limit('login', 25, 300);
    $pwd = isset($_POST['password']) ? (string) $_POST['password'] : '';
    $csrf = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
    $postedReturn = isset($_POST['return']) ? (string) $_POST['return'] : '';
    $afterLogin = dc_security_safe_return_target($postedReturn !== '' ? $postedReturn : $returnGet);

    if (!dc_security_csrf_verify($csrf)) {
        $error = 'Session expired. Refresh and try again.';
    } elseif (!dc_security_verify_password($pwd)) {
        $error = 'Incorrect password.';
    } else {
        dc_security_login_success();
        header('Location: ' . $afterLogin);
        exit;
    }
}

$csrf = dc_security_csrf_token();
$dvcVersion = trim((string) @file_get_contents(__DIR__ . '/VERSION'));
if ($dvcVersion === '') {
    $dvcVersion = '0.0.0';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in · DVSwitch Cockpit</title>
  <link rel="stylesheet" href="static/app.css">
</head>
<body class="login-body">
  <main class="login-shell">
    <div class="login-card panel">
      <h1 class="login-title">DVSwitch Cockpit</h1>
      <p class="login-sub">Sign in to continue</p>
      <?php if ($error !== '') { ?>
        <p class="login-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
      <?php } ?>
      <form method="post" action="login.php" autocomplete="current-password" class="login-form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="return" value="<?= htmlspecialchars($returnGet, ENT_QUOTES, 'UTF-8') ?>">
        <label class="login-label" for="password">Password</label>
        <input class="login-input" type="password" name="password" id="password" required autofocus>
        <button type="submit" class="login-submit action-btn">Sign in</button>
      </form>
      <p class="login-foot">v<?= htmlspecialchars($dvcVersion, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
  </main>
</body>
</html>
