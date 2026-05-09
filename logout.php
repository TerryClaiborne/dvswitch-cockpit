<?php declare(strict_types=1);

require __DIR__ . '/api/security.php';

dc_security_apply_headers();
dc_security_logout();

header('Location: ' . dc_security_login_page_path());
exit;
