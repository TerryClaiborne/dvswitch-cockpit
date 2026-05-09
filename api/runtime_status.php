<?php
declare(strict_types=1);

require_once __DIR__ . '/runtime/_web_guard.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require __DIR__ . '/runtime/common.php';
require __DIR__ . '/runtime/history.php';
require __DIR__ . '/runtime/adapters/YsfAdapter.php';
require __DIR__ . '/runtime/adapters/DstarAdapter.php';
require __DIR__ . '/runtime/adapters/BmStfuAdapter.php';
require __DIR__ . '/runtime/adapters/BmStockAdapter.php';
require __DIR__ . '/runtime/adapters/TgifHblinkAdapter.php';
require __DIR__ . '/runtime/adapters/GenericAdapter.php';
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

$stfuLog = '/var/log/STFU.log';
$stfuLines = dc_linesf($stfuLog, 900);

$hblinkService = dc_service_state_first([
    'hblink.service',
    'hblink3.service',
    'tgif-hblink.service',
    'alltune2-hblink.service',
]);

$services = [
    'analog_bridge'  => dc_service_state('analog_bridge.service'),
    'mmdvm_bridge'   => dc_service_state('mmdvm_bridge.service'),
    'hblink'         => $hblinkService['state'],
    'hblink_service' => $hblinkService['name'],
    'hblink_process' => dc_process_running(['hblink', 'HBlink', 'tgif-hblink', 'alltune2-hblink']),
];

$cache = dc_load_state_cache();

$liveMode = dc_detect_live_mode($analogLines);
if ($liveMode !== '') {
    $abinfo['tlv']['ambe_mode'] = $liveMode;
}
$abinfo['_runtime'] = [
    'latest_txtg' => dc_detect_latest_txtg($analogLines),
    'tgif_hblink_target' => dc_detect_tgif_hblink_target(),
    'private_audio_link' => dc_detect_alltune2_private_audio_link(),
];

$adapters = [];
$adapters['ysf']         = dc_adapter_ysf($bridgeLines, $abinfo, $cache, $tzName);
$adapters['dstar']       = dc_adapter_dstar($bridgeLines, $abinfo, $cache, $tzName);
$adapters['bm_stfu']     = dc_adapter_bm_stfu($stfuLines, $abinfo, $cache, $tzName);
$adapters['bm_stock']    = dc_adapter_bm_stock($analogLines, $abinfo, $services, $cache, $tzName);
$adapters['tgif_hblink'] = dc_adapter_tgif_hblink($analogLines, $bridgeLines, $abinfo, $services, $tzName);
$adapters['generic']     = dc_adapter_generic($bridgeLines, $tzName, $abinfo);

foreach (['ysf','dstar','bm_stfu','bm_stock','tgif_hblink','generic'] as $name) {
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
    'tgif_hblink' => [
        'connection_state' => $adapters['tgif_hblink']['connection_state'] ?? 'Idle',
        'target_display'   => $adapters['tgif_hblink']['target_display'] ?? '--',
        'last_heard'       => $adapters['tgif_hblink']['last_heard'] ?? '--',
    ],
]);

$historyRows = array_merge(
    is_array($adapters['ysf']['rows'] ?? null) ? $adapters['ysf']['rows'] : [],
    is_array($adapters['dstar']['rows'] ?? null) ? $adapters['dstar']['rows'] : [],
    is_array($adapters['bm_stfu']['rows'] ?? null) ? $adapters['bm_stfu']['rows'] : [],
    is_array($adapters['bm_stock']['rows'] ?? null) ? $adapters['bm_stock']['rows'] : [],
    is_array($adapters['tgif_hblink']['rows'] ?? null) ? $adapters['tgif_hblink']['rows'] : [],
    is_array($adapters['generic']['rows'] ?? null) ? $adapters['generic']['rows'] : []
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

echo json_encode([
    'source_file' => basename($abFile),
    'log_source' => $activeAdapter === 'bm_stfu'
        ? $stfuLog
        : (($activeAdapter === 'tgif_hblink' || $activeAdapter === 'bm_stock') ? $analogLog : $bridgeFile),
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
    'adapter_name' => $activeAdapter,
    'debug_private_audio_link' => $abinfo['_runtime']['private_audio_link'] ?? [],
    'debug_dmr_subscriber_lookup' => array_diff_key(dc_load_dmr_subscriber_map(), ['map' => true]),
], JSON_PRETTY_PRINT);
?>