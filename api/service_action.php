<?php
declare(strict_types=1);

require_once __DIR__ . '/runtime/_web_guard.php';

dc_security_require_post();
dc_security_same_origin_required();
dc_security_require_action_header();
dc_security_rate_limit('service_action', 3, 60);

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$action = trim((string)($_POST['action'] ?? ''));
$service = trim((string)($_POST['service'] ?? ''));

$map = [
    'analog_bridge' => ['analog_bridge.service'],
    'mmdvm_bridge'  => ['mmdvm_bridge.service'],
    'both'          => ['analog_bridge.service', 'mmdvm_bridge.service'],
];

if ($action !== 'restart') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unsupported action']);
    exit;
}

if (!isset($map[$service])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unsupported service']);
    exit;
}

$services = $map[$service];
$failed = [];
$verified = [];

foreach ($services as $svc) {
    $cmd = '/usr/bin/sudo /usr/bin/systemctl restart ' . escapeshellarg($svc) . ' >/dev/null 2>&1';
    $status = 0;
    @exec($cmd, $unused, $status);
    if ($status !== 0) {
        $failed[] = $svc;
        continue;
    }

    $check = trim((string)@shell_exec('/bin/systemctl is-active ' . escapeshellarg($svc) . ' 2>/dev/null'));
    if ($check === 'active') {
        $verified[] = $svc;
    } else {
        $failed[] = $svc;
    }
}

if ($failed) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Restart failed or could not be verified.',
        'failed' => $failed,
    ]);
    exit;
}

$message = match ($service) {
    'analog_bridge' => 'Analog Bridge restart verified',
    'mmdvm_bridge'  => 'MMDVM Bridge restart verified',
    'both'          => 'Analog + MMDVM restart verified',
    default         => 'Restart verified',
};

echo json_encode([
    'ok' => true,
    'message' => $message,
    'verified' => $verified,
]);
