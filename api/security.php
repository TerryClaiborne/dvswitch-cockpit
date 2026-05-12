<?php
declare(strict_types=1);

const DC_SECURITY_SESSION_NAME = 'DVS_COCKPIT';

function dc_security_is_cli(): bool {
    return PHP_SAPI === 'cli';
}

function dc_security_header_value(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$key] ?? ''));
}

function dc_security_cache_dir(): string {
    $dir = dirname(__DIR__) . '/data/cache';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function dc_security_private_dir(): string {
    $dir = dirname(__DIR__) . '/data/private';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir;
}

function dc_security_auth_config_path(): string {
    return dc_security_private_dir() . '/auth.ini';
}

function dc_security_parse_bool(mixed $value): bool {
    $text = strtolower(trim((string)$value));
    return in_array($text, ['1', 'true', 'yes', 'on'], true);
}

function dc_security_auth_config(): array {
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $defaults = [
        'DVSWITCH_COCKPIT_AUTH_ENABLED' => '0',
        'DVSWITCH_COCKPIT_ADMIN_USER' => 'admin',
        'DVSWITCH_COCKPIT_ADMIN_PASSWORD_HASH' => '',
    ];

    $path = dc_security_auth_config_path();

    if (!is_readable($path)) {
        $config = $defaults;
        return $config;
    }

    $parsed = @parse_ini_file($path, false, INI_SCANNER_RAW);
    if (!is_array($parsed)) {
        $config = $defaults;
        return $config;
    }

    $config = array_merge($defaults, $parsed);
    return $config;
}

function dc_security_auth_enabled(): bool {
    $config = dc_security_auth_config();
    return dc_security_parse_bool($config['DVSWITCH_COCKPIT_AUTH_ENABLED'] ?? '0');
}

function dc_security_admin_user(): string {
    $config = dc_security_auth_config();
    $user = trim((string)($config['DVSWITCH_COCKPIT_ADMIN_USER'] ?? 'admin'));
    return $user !== '' ? $user : 'admin';
}

function dc_security_admin_password_hash(): string {
    $config = dc_security_auth_config();
    return trim((string)($config['DVSWITCH_COCKPIT_ADMIN_PASSWORD_HASH'] ?? ''));
}

function dc_security_https_active(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }

    $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($forwardedProto !== '') {
        foreach (explode(',', $forwardedProto) as $proto) {
            if (trim($proto) === 'https') {
                return true;
            }
        }
    }

    $forwardedSsl = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
    return $forwardedSsl === 'on';
}

function dc_security_cookie_path(): string {
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
    $appRoot = realpath(dirname(__DIR__));

    if ($docRoot && $appRoot && str_starts_with($appRoot, $docRoot)) {
        $suffix = substr($appRoot, strlen($docRoot));
        $suffix = str_replace('\\', '/', $suffix);
        $cached = ($suffix === '' || $suffix === '/') ? '/' : rtrim($suffix, '/') . '/';
        return $cached;
    }

    $cached = '/';
    return $cached;
}

function dc_security_session_start(): void {
    if (dc_security_is_cli()) {
        return;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(DC_SECURITY_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => dc_security_cookie_path(),
        'secure' => dc_security_https_active(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    @session_start();
}

function dc_security_is_authenticated(): bool {
    if (!dc_security_auth_enabled()) {
        return true;
    }

    dc_security_session_start();
    return !empty($_SESSION['dvc_auth_ok']) && $_SESSION['dvc_auth_ok'] === true;
}

function dc_security_login_success(): void {
    dc_security_session_start();
    session_regenerate_id(true);
    $_SESSION['dvc_auth_ok'] = true;
    $_SESSION['dvc_auth_at'] = time();
}

function dc_security_logout(): void {
    dc_security_session_start();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    session_destroy();
}

function dc_security_csrf_token(): string {
    dc_security_session_start();

    if (empty($_SESSION['dvc_csrf_token']) || !is_string($_SESSION['dvc_csrf_token'])) {
        $_SESSION['dvc_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['dvc_csrf_token'];
}

function dc_security_request_csrf_token(): string {
    $header = dc_security_header_value('X-DVSwitch-Cockpit-CSRF');
    if ($header !== '') {
        return $header;
    }

    return trim((string)($_POST['csrf_token'] ?? ''));
}

function dc_security_require_csrf(): void {
    if (dc_security_is_cli()) {
        return;
    }

    dc_security_session_start();

    $expected = (string)($_SESSION['dvc_csrf_token'] ?? '');
    $actual = dc_security_request_csrf_token();

    if ($expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
        dc_security_deny('CSRF validation failed.');
    }
}

function dc_security_auth_status(): array {
    $enabled = dc_security_auth_enabled();

    return [
        'enabled' => $enabled,
        'logged_in' => !$enabled || dc_security_is_authenticated(),
        'can_restart_services' => !$enabled || dc_security_is_authenticated(),
        'admin_user' => dc_security_admin_user(),
        'csrf_token' => $enabled ? dc_security_csrf_token() : '',
    ];
}

function dc_security_require_authenticated(): void {
    if (!dc_security_auth_enabled()) {
        return;
    }

    if (!dc_security_is_authenticated()) {
        dc_security_apply_headers();
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'Authentication required',
            'auth_required' => true,
        ]);
        exit;
    }
}

function dc_security_remote_addr(): string {
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

function dc_security_ipv4_in_cidr(string $ip, string $cidr): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
    [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
    $bits = (int)$bits;
    $ipLong = ip2long($ip);
    $netLong = ip2long($network);
    if ($ipLong === false || $netLong === false || $bits < 0 || $bits > 32) return false;
    $mask = $bits === 0 ? 0 : ((-1 << (32 - $bits)) & 0xFFFFFFFF);
    return (($ipLong & $mask) === ($netLong & $mask));
}

function dc_security_ipv6_in_cidr(string $ip, string $cidr): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return false;
    [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, '128');
    $bits = (int)$bits;
    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton($network);
    if ($ipBin === false || $netBin === false || $bits < 0 || $bits > 128) return false;
    $bytes = intdiv($bits, 8);
    $rem = $bits % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) return false;
    if ($rem === 0) return true;
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return ((ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask));
}

function dc_security_is_trusted_ip(string $ip): bool {
    if ($ip === '') return false;
    $trusted = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        '100.64.0.0/10',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];
    foreach ($trusted as $cidr) {
        if (str_contains($cidr, ':')) {
            if (dc_security_ipv6_in_cidr($ip, $cidr)) return true;
        } else {
            if (dc_security_ipv4_in_cidr($ip, $cidr)) return true;
        }
    }
    return false;
}

function dc_security_forwarded_chain_is_trusted(): bool {
    $headers = [
        dc_security_header_value('X-Forwarded-For'),
        dc_security_header_value('X-Real-IP'),
        dc_security_header_value('CF-Connecting-IP'),
    ];
    foreach ($headers as $header) {
        if ($header === '') continue;
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') continue;
            if (!filter_var($candidate, FILTER_VALIDATE_IP)) return false;
            if (!dc_security_is_trusted_ip($candidate)) return false;
        }
    }
    return true;
}

function dc_security_apply_headers(): void {
    if (headers_sent()) return;
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self' https://raw.githubusercontent.com; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}

function dc_security_deny(string $message = 'Forbidden'): never {
    dc_security_apply_headers();
    http_response_code(403);
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['SCRIPT_FILENAME'] ?? ''));
    $isJson = str_contains($accept, 'application/json') || str_contains($uri, '/api/') || str_contains($script, '/api/');
    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $message]);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message . "\n";
    }
    exit;
}

function dc_security_require_trusted_client(): void {
    dc_security_apply_headers();
    if (dc_security_is_cli()) return;
    $remote = dc_security_remote_addr();
    if (!dc_security_is_trusted_ip($remote)) {
        dc_security_deny('DVSwitch Cockpit is restricted to local/trusted networks.');
    }
    if (!dc_security_forwarded_chain_is_trusted()) {
        dc_security_deny('Untrusted forwarded client address.');
    }
}

function dc_security_same_origin_required(): void {
    if (dc_security_is_cli()) return;
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') dc_security_deny('Missing host header.');

    $origin = strtolower((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $referer = strtolower((string)($_SERVER['HTTP_REFERER'] ?? ''));

    $allowedHttp = 'http://' . $host;
    $allowedHttps = 'https://' . $host;

    if ($origin !== '') {
        if ($origin !== $allowedHttp && $origin !== $allowedHttps) {
            dc_security_deny('Cross-origin request denied.');
        }
        return;
    }

    if ($referer !== '') {
        if (!str_starts_with($referer, $allowedHttp . '/') && !str_starts_with($referer, $allowedHttps . '/')) {
            dc_security_deny('Cross-origin request denied.');
        }
        return;
    }

    dc_security_deny('Same-origin proof required.');
}

function dc_security_require_post(): void {
    if (dc_security_is_cli()) return;
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'POST required']);
        exit;
    }
}

function dc_security_require_action_header(): void {
    if (dc_security_is_cli()) return;
    if (dc_security_header_value('X-DVSwitch-Cockpit') !== 'service-action') {
        dc_security_deny('Missing service action header.');
    }
}

function dc_security_rate_limit(string $bucket, int $limit, int $windowSeconds): void {
    if (dc_security_is_cli()) return;
    $remote = preg_replace('/[^A-Za-z0-9_.:-]/', '_', dc_security_remote_addr()) ?: 'unknown';
    $dir = dc_security_cache_dir() . '/rate_limit';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $bucket) . '_' . $remote . '.json';
    $now = time();
    $hits = [];
    if (is_readable($file)) {
        $json = json_decode((string)@file_get_contents($file), true);
        if (is_array($json)) $hits = array_values(array_filter($json, fn($t) => is_int($t) && $t > $now - $windowSeconds));
    }
    if (count($hits) >= $limit) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Too many service actions. Try again shortly.']);
        exit;
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
}
