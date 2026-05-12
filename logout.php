<?php
declare(strict_types=1);

require __DIR__ . '/api/security.php';

dc_security_require_trusted_client();

if (dc_security_auth_enabled()) {
    dc_security_logout();
}

header('Location: index.php');
exit;
