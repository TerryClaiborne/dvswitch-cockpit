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
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function dc_security_auth_config_path(): string {
    return dc_security_private_dir() . '/auth.json';
}

function dc_security_cookie_path(): string {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $doc = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    $root = realpath(dirname(__DIR__));
    if ($doc && $root && str_starts_with($root, $doc)) {
        $suffix = substr($root, strlen($doc));
        $suffix = str_replace('\\', '/', $suffix);
        $cached = ($suffix === '' || $suffix === '/') ? '/' : rtrim($suffix, '/') . '/';
        return $cached;
    }
    $cached = '/';
    return $cached;
}

function dc_security_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
    session_name(DC_SECURITY_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => dc_security_cookie_path(),
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function dc_security_password_hash_from_config(): ?string {
    $path = dc_security_auth_config_path();
    if (!is_readable($path)) {
        return null;
    }
    $raw = json_decode((string) @file_get_contents($path), true);
    if (!is_array($raw)) {
        return null;
    }
    $h = $raw['password_hash'] ?? null;
    return is_string($h) && $h !== '' ? $h : null;
}

function dc_security_is_authenticated(): bool {
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
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
}

function dc_security_csrf_token(): string {
    dc_security_session_start();
    if (empty($_SESSION['dvc_csrf']) || !is_string($_SESSION['dvc_csrf'])) {
        $_SESSION['dvc_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['dvc_csrf'];
}

function dc_security_csrf_verify(?string $token): bool {
    dc_security_session_start();
    $expect = $_SESSION['dvc_csrf'] ?? '';
    return is_string($expect) && $expect !== '' && is_string($token) && hash_equals($expect, $token);
}

function dc_security_verify_password(string $plain): bool {
    $hash = dc_security_password_hash_from_config();
    if ($hash === null || $plain === '') {
        return false;
    }
    return password_verify($plain, $hash);
}

function dc_security_wants_json_response(): bool {
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['SCRIPT_FILENAME'] ?? ''));
    return str_contains($accept, 'application/json')
        || str_contains($uri, '/api/')
        || str_contains($script, '/api/');
}

function dc_security_login_page_path(): string {
    $p = rtrim(dc_security_cookie_path(), '/');
    return ($p === '') ? '/login.php' : $p . '/login.php';
}

function dc_security_app_index_path(): string {
    $p = rtrim(dc_security_cookie_path(), '/');
    return ($p === '') ? '/index.php' : $p . '/index.php';
}

/** @param ?string $returnRaw rawurlencoded path from query string */
function dc_security_safe_return_target(?string $returnRaw): string {
    $default = dc_security_app_index_path();
    if (!is_string($returnRaw) || $returnRaw === '') {
        return $default;
    }
    $path = rawurldecode($returnRaw);
    if (!str_starts_with($path, '/') || str_contains($path, "\0") || preg_match('/[\r\n]/', $path)) {
        return $default;
    }
    if (str_starts_with($path, '//')) {
        return $default;
    }
    $base = rtrim(dc_security_cookie_path(), '/');
    if ($base !== '' && $base !== '/' && $path !== $base && !str_starts_with($path, $base . '/')) {
        return $default;
    }
    return $path;
}

function dc_security_require_authenticated(): void {
    dc_security_apply_headers();
    if (dc_security_is_cli()) {
        return;
    }
    if (dc_security_password_hash_from_config() === null) {
        dc_security_deny('DVSwitch Cockpit login is not configured. Run the installer or tools/bootstrap_auth.php.');
    }
    dc_security_session_start();
    if (dc_security_is_authenticated()) {
        return;
    }
    $loginPage = dc_security_login_page_path();
    if (dc_security_wants_json_response()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Authentication required', 'login' => $loginPage]);
        exit;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $qsPos = strpos($uri, '?');
    $path = $qsPos === false ? $uri : substr($uri, 0, $qsPos);
    $ret = rawurlencode($path);
    header('Location: ' . $loginPage . '?return=' . $ret);
    exit;
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
