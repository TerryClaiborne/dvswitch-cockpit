#!/usr/bin/env php
<?php declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require dirname(__DIR__) . '/api/security.php';

$path = dc_security_auth_config_path();
if (is_readable($path)) {
    fwrite(STDOUT, "Auth already configured: {$path}\n");
    exit(0);
}

dc_security_private_dir();

$pw = bin2hex(random_bytes(8));
$hash = password_hash($pw, PASSWORD_DEFAULT);
if ($hash === false) {
    fwrite(STDERR, "Could not hash password.\n");
    exit(1);
}

$payload = json_encode(['password_hash' => $hash], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($payload === false || @file_put_contents($path, $payload) === false) {
    fwrite(STDERR, "Could not write {$path}\n");
    exit(1);
}
@chmod($path, 0600);
dc_security_fix_auth_file_web_ownership($path);

fwrite(STDOUT, "Initial DVSwitch Cockpit password: {$pw}\n");
fwrite(STDOUT, "Stored in: {$path}\n");
fwrite(STDOUT, "Change it anytime with: sudo php " . dirname(__DIR__) . "/tools/set_cockpit_password.php\n");
exit(0);
