<?php
declare(strict_types=1);

require __DIR__ . '/security.php';
dc_security_require_trusted_client();

$dcAuthStatus = dc_security_auth_status();

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require __DIR__ . '/runtime/common.php';
require __DIR__ . '/runtime/history.php';
require __DIR__ . '/runtime/adapters/YsfAdapter.php';
require __DIR__ . '/runtime/adapters/DstarAdapter.php';
require __DIR__ . '/runtime/adapters/BmStfuAdapter.php';
require __DIR__ . '/runtime/adapters/BmStockAdapter.php';
require __DIR__ . '/runtime/adapters/BmtdAdapter.php';
require __DIR__ . '/runtime/adapters/TgifHblinkAdapter.php';
require __DIR__ . '/runtime/adapters/TgifdAdapter.php';
require __DIR__ . '/runtime/adapters/GenericAdapter.php';
require __DIR__ . '/runtime/adapters/P25Adapter.php';
require __DIR__ . '/runtime/adapters/NxdnAdapter.php';
require __DIR__ . '/runtime/resolver.php';

$abFile = dc_abinfo_file();
if (!$abFile) {
    http_response_code(404);
    echo json_encode(['error' => 'No ABInfo file found']);
    exit;
}

$abinfo = json_decode((string)@file_get_contents($abFile), true);
if (!is_array($abinfo)) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid ABInfo JSON']);
    exit;
}

$tzName = dc_local_tz_name();
@date_default_timezone_set($tzName);

$bridgeFile = dc_recent_bridge_file('/var/log/mmdvm/MMDVM_Bridge-');
$bridgeLines = array_merge(
    dc_linesf('/var/log/mmdvm/MMDVM_Bridge-' . gmdate('Y-m-d', time() - 86400) . '.log', 300),
    dc_linesf($bridgeFile, 900)
);

$analogLog = '/var/log/dvswitch/Analog_Bridge.log';
if (!is_readable($analogLog)) {
    $cand = glob('/var/log/dvswitch/Analog_Bridge*.log');
    if ($cand) {
        usort($cand, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $analogLog = $cand[0];
    }
}
$analogLines = dc_linesf($analogLog, 700);

$stfuLog = '/var/www/html/alltune2/logs/STFU.log';
$stfuLines = dc_linesf($stfuLog, 900);
$bmtdLog = '/var/www/html/alltune2/logs/bmtd.log';
$tgifdLog = '/var/www/html/alltune2/logs/tgifd.log';

$services = [
    'analog_bridge'  => dc_service_state('analog_bridge.service'),
    'mmdvm_bridge'   => dc_service_state('mmdvm_bridge.service'),
];

$cache = dc_load_state_cache();

$liveMode = dc_detect_live_mode($analogLines);
if ($liveMode !== '') {
    $abinfo['tlv']['ambe_mode'] = $liveMode;
}
$abinfo['_runtime'] = [
    'latest_txtg' => dc_detect_latest_txtg($analogLines),
    'private_audio_link' => dc_detect_alltune2_private_audio_link(),
];

$adapters = [];
$adapters['ysf']         = dc_adapter_ysf($bridgeLines, $abinfo, $cache, $tzName);
$adapters['dstar']       = dc_adapter_dstar($bridgeLines, $abinfo, $cache, $tzName);
$adapters['bm_stfu']     = dc_adapter_bm_stfu($stfuLines, $abinfo, $cache, $tzName);
$adapters['bm_stock']    = dc_adapter_bm_stock($analogLines, $abinfo, $services, $cache, $tzName);
$adapters['bmtd']        = dc_adapter_bmtd($tzName);
$adapters['tgifd']        = dc_adapter_tgifd($tzName);
$adapters['p25']         = dc_adapter_p25($analogLines, $bridgeLines, $abinfo, $cache, $tzName);
$adapters['nxdn']        = dc_adapter_nxdn($analogLines, $bridgeLines, $abinfo, $cache, $tzName);
$adapters['generic']     = dc_adapter_generic($bridgeLines, $tzName, $abinfo);

foreach (['ysf','dstar','bm_stfu','bm_stock','bmtd','tgifd','p25','nxdn','generic'] as $name) {
    if (!isset($adapters[$name]) || !is_array($adapters[$name])) {
        $adapters[$name] = dc_idle_adapter('Idle');
    }
    $adapters[$name] += [
        'adapter' => $name,
        'provider' => 'Idle',
        'network' => 'Idle',
        'connection_state' => 'Idle',
        'path_label' => 'Idle',
        'target_display' => '--',
        'target_note' => '(no active network detected)',
        'last_heard' => '--',
        'rows' => [],
        'left_label' => 'Last Heard',
        'left_value' => '--',
        'signal_epoch' => 0,
    ];
}

$active = dc_resolve_active($abinfo, $adapters, $cache);
if (!is_array($active)) {
    $active = dc_idle_adapter('Idle');
}

dc_save_state_cache([
    'ysf' => [
        'connection_state' => $adapters['ysf']['connection_state'] ?? 'Idle',
        'target_display'   => $adapters['ysf']['target_display'] ?? '--',
        'last_heard'       => $adapters['ysf']['last_heard'] ?? '--',
    ],
    'dstar' => [
        'connection_state' => $adapters['dstar']['connection_state'] ?? 'Idle',
        'target_display'   => $adapters['dstar']['target_display'] ?? '--',
        'last_heard'       => $adapters['dstar']['last_heard'] ?? '--',
    ],
    'bm_stfu' => [
        'connection_state' => $adapters['bm_stfu']['connection_state'] ?? 'Idle',
        'target_display'   => $adapters['bm_stfu']['target_display'] ?? '--',
        'last_heard'       => $adapters['bm_stfu']['last_heard'] ?? '--',
    ],
    'bm_stock' => [
        'connection_state' => $adapters['bm_stock']['connection_state'] ?? 'Idle',
        'target_display'   => $adapters['bm_stock']['target_display'] ?? '--',
        'last_heard'       => $adapters['bm_stock']['last_heard'] ?? '--',
    ],
    'bmtd' => [
        'connection_state' => $adapters['bmtd']['connection_state'] ?? 'Idle',
        'target_display'   => $adapters['bmtd']['target_display'] ?? '--',
        'last_heard'       => $adapters['bmtd']['last_heard'] ?? '--',
    ],
    'tgifd' => [
        'connection_state' => $adapters['tgifd']['connection_state'] ?? 'Idle',
        'target_display'   => $adapters['tgifd']['target_display'] ?? '--',
        'last_heard'       => $adapters['tgifd']['last_heard'] ?? '--',
    ],
    'p25' => [
        'connection_state' => $adapters['p25']['connection_state'] ?? 'Idle',
        'target_display'   => $adapters['p25']['target_display'] ?? '--',
        'last_heard'       => $adapters['p25']['last_heard'] ?? '--',
    ],
    'nxdn' => [
        'connection_state' => $adapters['nxdn']['connection_state'] ?? 'Idle',
        'target_display'   => $adapters['nxdn']['target_display'] ?? '--',
        'last_heard'       => $adapters['nxdn']['last_heard'] ?? '--',
    ],
]);

$mmdvmActiveForHistory = (($services['mmdvm_bridge'] ?? 'inactive') === 'active');

$genericRowsForHistory = is_array($adapters['generic']['rows'] ?? null) ? $adapters['generic']['rows'] : [];
if (!$mmdvmActiveForHistory) {
    $genericRowsForHistory = array_values(array_filter($genericRowsForHistory, function($r) {
        return (string)($r['mode'] ?? '') !== 'DMR/BM'
            && (string)($r['mode'] ?? '') !== 'DMR/TGIF';
    }));
}

$historyRows = array_merge(
    is_array($adapters['ysf']['rows'] ?? null) ? $adapters['ysf']['rows'] : [],
    is_array($adapters['dstar']['rows'] ?? null) ? $adapters['dstar']['rows'] : [],
    is_array($adapters['bm_stfu']['rows'] ?? null) ? $adapters['bm_stfu']['rows'] : [],
    $mmdvmActiveForHistory && is_array($adapters['bm_stock']['rows'] ?? null) ? $adapters['bm_stock']['rows'] : [],
    is_array($adapters['bmtd']['rows'] ?? null) ? $adapters['bmtd']['rows'] : [],
    is_array($adapters['tgifd']['rows'] ?? null) ? $adapters['tgifd']['rows'] : [],
    is_array($adapters['p25']['rows'] ?? null) ? $adapters['p25']['rows'] : [],
    is_array($adapters['nxdn']['rows'] ?? null) ? $adapters['nxdn']['rows'] : [],
    $genericRowsForHistory
);

// Keep history persistent across mode switches. Do not reset on service-state signature drift.
$history = dc_load_history();
$history['signature'] = 'persistent';
$history['rows'] = array_map('dc_enrich_row_identity', dc_merge_history($history['rows'] ?? [], $historyRows));
dc_save_history($history);

[$vocoderLabel, $vocoderStatus] = dc_vocoder_details($abinfo, $analogLines);

$recentEvents = [];
foreach (array_slice(array_reverse($analogLines), 0, 80) as $line) {
    if (stripos($line, 'DV3000 not found') !== false && stripos($vocoderStatus, 'OP25') !== false) {
        continue;
    }
    $recentEvents[] = $line;
}
$recentEvents = array_slice(array_reverse($recentEvents), 0, 18);

$ab   = $abinfo['ab'] ?? [];
$dig  = $abinfo['digital'] ?? [];
$dv   = $abinfo['dv3000'] ?? [];
$usrp = $abinfo['usrp'] ?? [];
$tlv  = $abinfo['tlv'] ?? [];

$activeAdapter = (string)($active['adapter'] ?? 'idle');

$allHistoryRows = is_array($history['rows'] ?? null) ? $history['rows'] : [];
$gatewayActivityRows = array_values(array_filter($allHistoryRows, function($r) {
    return strtoupper((string)($r['src'] ?? '')) === 'NET';
}));
$localActivityRows = array_values(array_filter($allHistoryRows, function($r) {
    $src = strtoupper((string)($r['src'] ?? ''));
    return $src === 'LNET' || $src === 'RF';
}));

if (!function_exists('dc_runtime_keep_dmr_row_for_display')) {
    function dc_runtime_keep_dmr_row_for_display(array $r, bool $mmdvmActive): bool {
        $mode = (string)($r['mode'] ?? '');
        if ($mode !== 'DMR/BM' && $mode !== 'DMR/TGIF') {
            return true;
        }

        if ($mmdvmActive) {
            return true;
        }

        // When MMDVM_Bridge is inactive, only BMTD/TGIFD-owned DMR rows are valid.
        // Stock/generic DMR rows can be stale Analog_Bridge/MMDVM leftovers after reboot.
        $confidence = (string)($r['identity_confidence'] ?? '');
        return str_starts_with($confidence, 'bmtd_')
            || str_starts_with($confidence, 'tgifd_');
    }
}

$mmdvmActiveForDisplay = (($services['mmdvm_bridge'] ?? 'inactive') === 'active');
$gatewayActivityRows = array_values(array_filter($gatewayActivityRows, fn($r) => dc_runtime_keep_dmr_row_for_display($r, $mmdvmActiveForDisplay)));
$localActivityRows = array_values(array_filter($localActivityRows, fn($r) => dc_runtime_keep_dmr_row_for_display($r, $mmdvmActiveForDisplay)));

if (!function_exists('dc_runtime_tgif_row_score')) {
    function dc_runtime_tgif_row_score(array $r, string $activeTarget): int {
        $score = 0;
        $target = (string)($r['target'] ?? '');
        $station = strtoupper(trim((string)($r['callsign_display'] ?? $r['callsign'] ?? '')));

        if ($activeTarget !== '' && $target === $activeTarget) {
            $score += 10;
        }
        if ($station !== '' && $station !== 'TGIF RX') {
            $score += 3;
        }
        if ($station === 'TGIF PARROT') {
            $score += 2;
        }
        return $score;
    }
}

if (!function_exists('dc_runtime_dedupe_tgif_rows')) {
    function dc_runtime_dedupe_tgif_rows(array $rows, string $activeTarget): array {
        $out = [];
        $seen = [];

        foreach ($rows as $r) {
            $mode = (string)($r['mode'] ?? '');
            if ($mode !== 'DMR/TGIF') {
                $out[] = $r;
                continue;
            }

            $time = (string)($r['utc'] ?? $r['time'] ?? '');
            $src  = strtoupper((string)($r['src'] ?? ''));
            $dur  = (string)($r['dur'] ?? '');

            // TGIF can be parsed repeatedly from shared Analog_Bridge history.
            // Collapse the same event if it shows up with stale StartRef targets.
            $key = 'tgif|' . $time . '|' . $src . '|' . $dur;

            if (!isset($seen[$key])) {
                $seen[$key] = count($out);
                $out[] = $r;
                continue;
            }

            $idx = $seen[$key];
            $old = $out[$idx];

            if (dc_runtime_tgif_row_score($r, $activeTarget) >= dc_runtime_tgif_row_score($old, $activeTarget)) {
                $out[$idx] = $r;
            }
        }

        return $out;
    }
}

$gatewayActivityRows = dc_runtime_dedupe_tgif_rows($gatewayActivityRows, (string)($active['target_display'] ?? ''));

if (!function_exists('dc_runtime_row_identity_key')) {
    function dc_runtime_row_identity_key(array $r): string {
        return implode('|', [
            (string)($r['utc'] ?? $r['time'] ?? ''),
            (string)($r['mode'] ?? ''),
            (string)($r['callsign'] ?? ''),
            (string)($r['target'] ?? ''),
            (string)($r['src'] ?? ''),
            (string)($r['dur'] ?? ''),
        ]);
    }
}

if (!function_exists('dc_runtime_balanced_gateway_rows')) {
    function dc_runtime_balanced_gateway_rows(array $rows, int $limit = 16, int $perDigitalNetwork = 4): array {
        if ($limit <= 0) return [];

        $bmRows = [];
        $tgifRows = [];

        foreach ($rows as $r) {
            $mode = strtoupper((string)($r['mode'] ?? ''));
            if ($mode === 'DMR/BM') {
                $bmRows[] = $r;
            } elseif ($mode === 'DMR/TGIF') {
                $tgifRows[] = $r;
            }
        }

        // If both BMTD and TGIFD have history, reserve a few rows for each so
        // one active network cannot push the other completely out of the table.
        if (empty($bmRows) || empty($tgifRows)) {
            return array_slice($rows, 0, $limit);
        }

        $out = [];
        $seen = [];

        $add = function(array $r) use (&$out, &$seen, $limit): void {
            if (count($out) >= $limit) return;
            $key = dc_runtime_row_identity_key($r);
            if (isset($seen[$key])) return;
            $seen[$key] = true;
            $out[] = $r;
        };

        foreach (array_slice($bmRows, 0, $perDigitalNetwork) as $r) {
            $add($r);
        }

        foreach (array_slice($tgifRows, 0, $perDigitalNetwork) as $r) {
            $add($r);
        }

        foreach ($rows as $r) {
            $add($r);
        }

        usort($out, function($a, $b) {
            return strcmp((string)($b['utc'] ?? $b['time'] ?? ''), (string)($a['utc'] ?? $a['time'] ?? ''));
        });

        return array_slice($out, 0, $limit);
    }
}

$gatewayActivityRows = dc_runtime_balanced_gateway_rows($gatewayActivityRows, 16, 4);

echo json_encode([
    'source_file' => basename($abFile),
    'log_source' => $activeAdapter === 'bm_stfu'
        ? $stfuLog
        : ($activeAdapter === 'bmtd'
            ? $bmtdLog
            : ($activeAdapter === 'tgifd'
                ? $tgifdLog
                : (($activeAdapter === 'bm_stock' || $activeAdapter === 'p25' || $activeAdapter === 'nxdn') ? $analogLog : $bridgeFile))),
    'ab_version' => $ab['version'] ?? '--',
    'call' => dc_display_station_call((string)($dig['call'] ?? ''), (string)($dig['gw'] ?? '')),
    'gw' => $dig['gw'] ?? '--',
    'rpt' => $dig['rpt'] ?? '--',
    'ts' => $dig['ts'] ?? '--',
    'cc' => $dig['cc'] ?? '--',
    'last_tune' => $active['left_value'] ?? '--',
    'left_status_label' => $active['left_label'] ?? 'Last Heard',
    'mute' => $abinfo['mute'] ?? '--',
    'tlv_mode' => $tlv['ambe_mode'] ?? '--',
    'dv3000_port' => $dv['port'] ?? '--',
    'usrp_rx_port' => $usrp['rx_port'] ?? '--',
    'usrp_tx_port' => $usrp['tx_port'] ?? '--',
    'usrp_rx_gain' => $usrp['to_pcm']['gain'] ?? '0',
    'usrp_tx_gain' => $usrp['to_ambe']['gain'] ?? '0',
    'provider' => $active['provider'] ?? 'Idle',
    'network' => $active['network'] ?? 'Idle',
    'path_label' => $active['path_label'] ?? 'Idle',
    'target_display' => $active['target_display'] ?? '--',
    'target_note' => $active['target_note'] ?? '(no active network detected)',
    'connection_state' => $active['connection_state'] ?? 'Idle',
    'last_heard' => $active['last_heard'] ?? '--',
    'services' => $services,
    'gateway_activity' => dc_history_display_rows($gatewayActivityRows, 16),
    'local_activity' => dc_history_display_rows($localActivityRows, 12),
    'recent_events' => $recentEvents,
    'vocoder_label' => $vocoderLabel,
    'vocoder_status' => $vocoderStatus,
    'display_timezone' => $tzName,
    'service_control_verified' => false,
    'can_restart_services' => (bool)($dcAuthStatus['can_restart_services'] ?? false),
    'auth' => $dcAuthStatus,
    'adapter_name' => $activeAdapter,
    'debug_private_audio_link' => $abinfo['_runtime']['private_audio_link'] ?? [],
    'debug_dmr_subscriber_lookup' => array_diff_key(dc_load_dmr_subscriber_map(), ['map' => true]),
], JSON_PRETTY_PRINT);
?>