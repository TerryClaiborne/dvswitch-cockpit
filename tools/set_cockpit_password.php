#!/usr/bin/env php
<?php declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require dirname(__DIR__) . '/api/security.php';

dc_security_private_dir();

$pw = '';
if (isset($argv[1]) && is_string($argv[1]) && $argv[1] !== '') {
    $pw = $argv[1];
} else {
    fwrite(STDERR, "Usage: sudo php tools/set_cockpit_password.php 'new-password'\n");
    exit(1);
}

if (strlen($pw) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$hash = password_hash($pw, PASSWORD_DEFAULT);
if ($hash === false) {
    fwrite(STDERR, "Could not hash password.\n");
    exit(1);
}

$path = dc_security_auth_config_path();
$payload = json_encode(['password_hash' => $hash], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($payload === false || @file_put_contents($path, $payload) === false) {
    fwrite(STDERR, "Could not write {$path}\n");
    exit(1);
}
@chmod($path, 0600);
dc_security_fix_auth_file_web_ownership($path);

fwrite(STDOUT, "Password updated: {$path}\n");
exit(0);
