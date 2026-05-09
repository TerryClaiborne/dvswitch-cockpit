<?php
declare(strict_types=1);

function dc_linesf(string $file, int $limit = 800): array {
    if (!is_readable($file)) return [];
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return is_array($lines) ? array_slice($lines, -$limit) : [];
}

function dc_abinfo_file(): ?string {
    $files = glob('/tmp/ABInfo_*.json');
    if (!$files) return null;
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $files[0];
}

function dc_service_state(string $service): string {
    $out = @shell_exec('/bin/systemctl is-active ' . escapeshellarg($service) . ' 2>/dev/null');
    $out = trim((string)$out);
    return $out !== '' ? $out : 'unknown';
}

function dc_service_state_first(array $services): array {
    $best = ['name' => $services[0] ?? '', 'state' => 'unknown'];
    foreach ($services as $service) {
        $state = dc_service_state($service);
        if ($best['state'] === 'unknown' && $state !== 'unknown') {
            $best = ['name' => $service, 'state' => $state];
        }
        if (in_array($state, ['active', 'activating'], true)) {
            return ['name' => $service, 'state' => $state];
        }
    }
    return $best;
}

function dc_process_running(array $patterns): bool {
    foreach ($patterns as $pattern) {
        $cmd = '/usr/bin/pgrep -af ' . escapeshellarg($pattern) . ' 2>/dev/null';
        $out = trim((string)@shell_exec($cmd));
        if ($out === '') continue;

        foreach (preg_split('/\R/', $out) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // Avoid self-matches from the shell/pgrep command used by this status endpoint.
            if (stripos($line, 'pgrep -af') !== false) continue;
            if (stripos($line, 'timeout ') !== false && stripos($line, 'bash -lc') !== false) continue;
            if (stripos($line, 'php api/runtime_status.php') !== false) continue;
            return true;
        }
    }
    return false;
}

function dc_service_epoch_signature(array $services): string {
    $parts = [];
    foreach ($services as $service) {
        $out = @shell_exec('/bin/systemctl show ' . escapeshellarg($service) . ' -p ActiveEnterTimestampMonotonic --value 2>/dev/null');
        $parts[] = $service . ':' . trim((string)$out);
    }
    return implode('|', $parts);
}

function dc_recent_bridge_file(string $prefix): string {
    $today = $prefix . gmdate('Y-m-d') . '.log';
    if (is_readable($today)) return $today;
    $yesterday = $prefix . gmdate('Y-m-d', time() - 86400) . '.log';
    if (is_readable($yesterday)) return $yesterday;
    return $today;
}

function dc_local_tz_name(): string {
    $tz = @file_get_contents('/etc/timezone');
    $tz = is_string($tz) ? trim($tz) : '';
    return $tz !== '' ? $tz : 'America/New_York';
}

function dc_parse_log_dt(string $line, string $tzName): array {
    if (!preg_match('/([0-9]{4}-[0-9]{2}-[0-9]{2})\s+([0-9]{2}:[0-9]{2}:[0-9]{2})/', $line, $m)) {
        return ['utc' => '', 'display' => '--', 'epoch' => 0];
    }
    $utcText = $m[1] . ' ' . $m[2];
    try {
        $dt = new DateTime($utcText, new DateTimeZone('UTC'));
        $epoch = $dt->getTimestamp();
        $dt->setTimezone(new DateTimeZone($tzName));
        return ['utc' => $utcText, 'display' => $dt->format('H:i:s M d'), 'epoch' => $epoch];
    } catch (Throwable $e) {
        return ['utc' => '', 'display' => '--', 'epoch' => 0];
    }
}

function dc_clean_target(string $value): string {
    $value = trim($value);
    if ($value === '') return '--';
    return preg_replace('/\s+/', ' ', $value);
}

function dc_frame_count_to_seconds(string $frames): string {
    return number_format(((float)$frames) * 0.059, 1, '.', '');
}



function dc_is_callsign_like(string $value): bool {
    $value = strtoupper(trim($value));
    if ($value === '' || strlen($value) < 3 || strlen($value) > 16) return false;
    if (!preg_match('/[A-Z]/', $value) || !preg_match('/[0-9]/', $value)) return false;
    return (bool)preg_match('/^[A-Z0-9\-\/]+$/', $value);
}

function dc_qrz_url(string $callsign): string {
    $callsign = strtoupper(trim($callsign));
    return 'https://www.qrz.com/db/' . rawurlencode($callsign);
}

function dc_detect_subscriber_file(): string {
    $iniCandidates = [
        '/opt/Analog_Bridge/Analog_Bridge.ini',
        '/etc/Analog_Bridge.ini',
        '/etc/dvswitch/Analog_Bridge.ini',
        '/opt/MMDVM_Bridge/Analog_Bridge.ini',
    ];

    foreach ($iniCandidates as $iniFile) {
        if (!is_readable($iniFile)) continue;
        $lines = @file($iniFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) continue;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ';') || str_starts_with($line, '#')) continue;
            if (!preg_match('/^subscriberFile\s*=\s*(.+)$/i', $line, $m)) continue;
            $path = trim(preg_replace('/\s*[;#].*$/', '', $m[1]) ?? '');
            $path = trim($path, " \t\n\r\0\x0B\"'");
            if ($path === '') continue;
            if ($path[0] !== '/') {
                $path = dirname($iniFile) . '/' . $path;
            }
            if (is_readable($path)) return $path;
        }
    }

    $candidates = [
        '/var/lib/dvswitch/subscriber_ids.csv',
        '/var/lib/dvswitch/subscriber_ids.txt',
        '/var/lib/mmdvm/subscriber_ids.csv',
        '/opt/MMDVM_Bridge/subscriber_ids.csv',
        '/opt/Analog_Bridge/subscriber_ids.csv',
    ];
    foreach ($candidates as $file) {
        if (is_readable($file)) return $file;
    }
    return '';
}

function dc_dmr_subscriber_cache_dir(): string {
    return __DIR__ . '/../../data/cache';
}

function dc_dmr_subscriber_cache_path(): string {
    return dc_dmr_subscriber_cache_dir() . '/dmr_subscribers.json';
}

function dc_dmr_subscriber_cache_max_bytes(): int {
    return 96 * 1024 * 1024;
}

function dc_dmr_subscriber_source_max_bytes(): int {
    return 64 * 1024 * 1024;
}

function dc_dmr_subscriber_lastgood_path(): string {
    return dc_dmr_subscriber_cache_dir() . '/subscriber_ids.lastgood.csv';
}

function dc_dmr_min_valid_rows(): int {
    return 1000;
}

function dc_dmr_min_valid_bytes(): int {
    return 1024 * 1024;
}

function dc_find_header_index(array $header, array $needles): int {
    foreach ($header as $idx => $field) {
        $field = strtolower(trim((string)$field));
        $field = preg_replace('/[^a-z0-9]+/', '', $field) ?? $field;
        foreach ($needles as $needle) {
            if ($field === $needle || str_contains($field, $needle)) return (int)$idx;
        }
    }
    return -1;
}

function dc_parse_dmr_subscriber_file(string $file, int $maxRows = 0): array {
    $map = [];
    if ($file === '' || !is_readable($file)) return $map;

    $fh = @fopen($file, 'r');
    if (!$fh) return $map;

    $idIndex = -1;
    $callIndex = -1;
    $lineNo = 0;
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        $lineNo++;
        if ($maxRows > 0 && $lineNo > $maxRows) break;
        if (!is_array($row) || count($row) < 2) continue;
        $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$row[0]) ?? (string)$row[0];

        if ($lineNo === 1) {
            $lower = array_map(fn($x) => strtolower(trim((string)$x)), $row);
            $looksHeader = false;
            foreach ($lower as $field) {
                if (preg_match('/^(radio[_ ]?id|dmr[_ ]?id|id|callsign|call)$/i', $field)) {
                    $looksHeader = true;
                    break;
                }
            }
            if ($looksHeader && !preg_match('/^[0-9]{4,9}$/', trim((string)$row[0]))) {
                $idIndex = dc_find_header_index($row, ['radioid','dmrid','subscriberid','id']);
                $callIndex = dc_find_header_index($row, ['callsign','call']);
                continue;
            }
        }

        $id = '';
        $call = '';
        if ($idIndex >= 0 && $callIndex >= 0 && isset($row[$idIndex], $row[$callIndex])) {
            $id = preg_replace('/\D+/', '', (string)$row[$idIndex]) ?? '';
            $call = strtoupper(trim((string)$row[$callIndex]));
        } else {
            $id = preg_replace('/\D+/', '', (string)$row[0]) ?? '';
            $call = strtoupper(trim((string)$row[1]));
            if (!preg_match('/^[0-9]{4,9}$/', $id) || !dc_is_callsign_like($call)) {
                $id = '';
                $call = '';
                foreach ($row as $field) {
                    $candidateId = preg_replace('/\D+/', '', (string)$field) ?? '';
                    if ($id === '' && preg_match('/^[0-9]{4,9}$/', $candidateId)) {
                        $id = $candidateId;
                        continue;
                    }
                    $candidateCall = strtoupper(trim((string)$field));
                    if ($call === '' && dc_is_callsign_like($candidateCall)) {
                        $call = $candidateCall;
                    }
                }
            }
        }

        if (preg_match('/^[0-9]{4,9}$/', $id) && dc_is_callsign_like($call)) {
            $map[$id] = $call;
        }
    }
    fclose($fh);
    return $map;
}

function dc_validate_dmr_subscriber_file(string $file): array {
    if ($file === '' || !is_readable($file)) {
        return ['valid' => false, 'reason' => 'missing_or_unreadable', 'size' => 0, 'sample_count' => 0];
    }

    $size = (int)(@filesize($file) ?: 0);
    if ($size < dc_dmr_min_valid_bytes()) {
        return ['valid' => false, 'reason' => 'suspiciously_small', 'size' => $size, 'sample_count' => 0];
    }
    if ($size > dc_dmr_subscriber_source_max_bytes()) {
        return ['valid' => false, 'reason' => 'suspiciously_large', 'size' => $size, 'sample_count' => 0];
    }

    $sample = dc_parse_dmr_subscriber_file($file, 5000);
    $sampleCount = count($sample);
    if ($sampleCount < dc_dmr_min_valid_rows()) {
        return ['valid' => false, 'reason' => 'too_few_valid_rows', 'size' => $size, 'sample_count' => $sampleCount];
    }

    return ['valid' => true, 'reason' => 'ok', 'size' => $size, 'sample_count' => $sampleCount];
}

function dc_load_cached_dmr_subscriber_map(bool $allowStale = false): array {
    $cacheFile = dc_dmr_subscriber_cache_path();
    if (!is_readable($cacheFile)) return [];
    $cached = json_decode((string)@file_get_contents($cacheFile), true);
    if (!is_array($cached) || !isset($cached['map']) || !is_array($cached['map'])) return [];
    $count = (int)($cached['count'] ?? count($cached['map']));
    if ($allowStale && $count >= dc_dmr_min_valid_rows()) return $cached;
    return $cached;
}

function dc_save_dmr_subscriber_cache(string $signature, string $source, array $map, array $health): void {
    if (count($map) < dc_dmr_min_valid_rows()) return;

    $dir = dc_dmr_subscriber_cache_dir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir) || !is_writable($dir)) return;

    $payload = [
        'signature' => $signature,
        'source' => $source,
        'count' => count($map),
        'health' => $health,
        'created_at' => gmdate('c'),
        'map' => $map,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT);
    if (!is_string($json)) return;
    if (strlen($json) > dc_dmr_subscriber_cache_max_bytes()) return;

    $cacheFile = dc_dmr_subscriber_cache_path();
    $tmp = $cacheFile . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return;
    }

    @chmod($tmp, 0644);
    @rename($tmp, $cacheFile);
}

function dc_save_lastgood_subscriber_file(string $file): void {
    if ($file === '' || !is_readable($file)) return;
    $dir = dc_dmr_subscriber_cache_dir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir) || !is_writable($dir)) return;

    $dest = dc_dmr_subscriber_lastgood_path();
    $tmp = $dest . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (@copy($file, $tmp) === false) {
        @unlink($tmp);
        return;
    }

    @chmod($tmp, 0644);
    @rename($tmp, $dest);
}

function dc_load_dmr_subscriber_map(): array {
    static $loaded = null;
    if (is_array($loaded)) return $loaded;

    $file = dc_detect_subscriber_file();
    $cacheFile = dc_dmr_subscriber_cache_path();
    $lastGood = dc_dmr_subscriber_lastgood_path();
    $health = dc_validate_dmr_subscriber_file($file);

    if (($health['valid'] ?? false) === true) {
        $sig = $file . '|' . (string)(@filemtime($file) ?: 0) . '|' . (string)(@filesize($file) ?: 0);
        if (is_readable($cacheFile)) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && ($cached['signature'] ?? '') === $sig && isset($cached['map']) && is_array($cached['map']) && (int)($cached['count'] ?? count($cached['map'])) >= dc_dmr_min_valid_rows()) {
                $loaded = ['source' => $file, 'map' => $cached['map'], 'health' => $health + ['cache' => 'fresh']];
                return $loaded;
            }
        }

        $map = dc_parse_dmr_subscriber_file($file);
        if (count($map) >= dc_dmr_min_valid_rows()) {
            dc_save_dmr_subscriber_cache($sig, $file, $map, $health);
            dc_save_lastgood_subscriber_file($file);
            $loaded = ['source' => $file, 'map' => $map, 'health' => $health + ['cache' => 'rebuilt']];
            return $loaded;
        }
        $health = ['valid' => false, 'reason' => 'parse_count_too_low', 'size' => (int)(@filesize($file) ?: 0), 'sample_count' => count($map)];
    }

    // If DVSwitch temporarily leaves subscriber_ids.csv tiny/corrupt during an
    // update, keep using the last known good Cockpit cache instead of poisoning
    // callsign lookup with a bad rebuild.
    $cached = dc_load_cached_dmr_subscriber_map(true);
    if (isset($cached['map']) && is_array($cached['map'])) {
        $loaded = ['source' => (string)($cached['source'] ?? 'cache'), 'map' => $cached['map'], 'health' => $health + ['cache' => 'stale_used']];
        return $loaded;
    }

    if (is_readable($lastGood)) {
        $lastGoodHealth = dc_validate_dmr_subscriber_file($lastGood);
        if (($lastGoodHealth['valid'] ?? false) === true) {
            $map = dc_parse_dmr_subscriber_file($lastGood);
            if (count($map) >= dc_dmr_min_valid_rows()) {
                $sig = $lastGood . '|' . (string)(@filemtime($lastGood) ?: 0) . '|' . (string)(@filesize($lastGood) ?: 0);
                dc_save_dmr_subscriber_cache($sig, $lastGood, $map, $lastGoodHealth + ['fallback' => true]);
                $loaded = ['source' => $lastGood, 'map' => $map, 'health' => $health + ['cache' => 'lastgood_used']];
                return $loaded;
            }
        }
    }

    $loaded = ['source' => $file, 'map' => [], 'health' => $health + ['cache' => 'none']];
    return $loaded;
}

function dc_display_station_call(string $rawCall, string $gateway = ''): string {
    $rawCall = trim($rawCall);
    $gateway = preg_replace('/\D+/', '', trim($gateway)) ?? '';
    if ($rawCall === '' || $rawCall === '--') {
        if ($gateway !== '') {
            $lookup = dc_lookup_dmr_callsign($gateway);
            if ($lookup !== '') return $lookup;
            return $gateway;
        }
        return '--';
    }
    if (preg_match('/^[0-9]{4,9}$/', $rawCall)) {
        $lookup = dc_lookup_dmr_callsign($rawCall);
        if ($lookup !== '') return $lookup;
        if ($gateway !== '' && $rawCall === $gateway) {
            $gatewayLookup = dc_lookup_dmr_callsign($gateway);
            if ($gatewayLookup !== '') return $gatewayLookup;
        }
    }
    return $rawCall;
}

function dc_lookup_dmr_callsign(string $id): string {
    $id = preg_replace('/\D+/', '', $id) ?? '';
    if (!preg_match('/^[0-9]{4,9}$/', $id)) return '';
    $payload = dc_load_dmr_subscriber_map();
    $map = $payload['map'] ?? [];
    return is_array($map) ? (string)($map[$id] ?? '') : '';
}

function dc_enrich_row_identity(array $row): array {
    $raw = trim((string)($row['callsign'] ?? ''));
    if ($raw === '') return $row;

    $display = $raw;
    $dmrId = '';
    $qrzUrl = '';
    $lookupSource = '';

    if (preg_match('/^[0-9]{4,9}$/', $raw)) {
        $call = dc_lookup_dmr_callsign($raw);
        if ($call !== '') {
            $display = $call;
            $dmrId = $raw;
            $qrzUrl = dc_qrz_url($call);
            $lookupSource = 'subscriber_ids';
        }
    } elseif (dc_is_callsign_like($raw)) {
        $display = strtoupper($raw);
        $qrzUrl = dc_qrz_url($display);
        $lookupSource = 'callsign';
    }

    $row['callsign_display'] = $display;
    if ($dmrId !== '') $row['dmr_id'] = $dmrId;
    if ($qrzUrl !== '') $row['qrz_url'] = $qrzUrl;
    if ($lookupSource !== '') $row['callsign_lookup'] = $lookupSource;
    return $row;
}

function dc_make_row(string $utc, string $time, string $mode, string $callsign, string $target, string $src, string $dur='--', string $loss='--', string $ber='--'): array {
    return dc_enrich_row_identity(compact('utc','time','mode','callsign','target','src','dur','loss','ber'));
}

function dc_vocoder_details(array $abinfo, array $analogLines): array {
    $dv = $abinfo['dv3000'] ?? [];
    $useSerial = strtolower((string)($dv['use_serial'] ?? 'false')) === 'true';
    $useEmu = strtolower((string)($abinfo['use_emulator'] ?? 'false')) === 'true';
    $op25 = false;
    foreach (array_reverse($analogLines) as $line) {
        if (stripos($line, 'Using software OP25 IMBE/AMBE vocoder') !== false) { $op25 = true; break; }
    }
    if ($op25) return ['Vocoder', 'Software OP25'];
    if ($useSerial) return ['Vocoder', 'USB Vocoder'];
    if ($useEmu) return ['Vocoder', 'Emulator'];
    return ['Vocoder', 'Auto'];
}

function dc_detect_live_mode(array $analogLines): string {
    for ($i = count($analogLines) - 1; $i >= 0; $i--) {
        $line = $analogLines[$i];
        if (preg_match('/MESSAGE packet sent to USRP client:\s+Setting mode to\s+([A-Z0-9\-]+)/i', $line, $m)) {
            return strtoupper(trim($m[1]));
        }
        if (preg_match('/ambeMode\s*=\s*([A-Z0-9\-]+)/i', $line, $m)) {
            return strtoupper(trim($m[1]));
        }
    }
    return '';
}

function dc_detect_latest_txtg(array $analogLines): array {
    for ($i = count($analogLines) - 1; $i >= 0; $i--) {
        $line = $analogLines[$i];
        if (preg_match('/([0-9]{4}-[0-9]{2}-[0-9]{2})\s+([0-9]{2}:[0-9]{2}:[0-9]{2}).*\btxTg\s*=?\s*:?\s*([0-9#]+)/i', $line, $m)) {
            $utcText = $m[1] . ' ' . $m[2];
            $epoch = 0;
            try {
                $dt = new DateTime($utcText, new DateTimeZone('UTC'));
                $epoch = $dt->getTimestamp();
            } catch (Throwable $e) {}
            $raw = trim($m[3]);
            return [
                'value' => rtrim($raw, '#'),
                'raw' => $raw,
                'disconnect' => str_ends_with($raw, '#') || rtrim($raw, '#') === '0',
                'epoch' => $epoch,
            ];
        }
    }
    return ['value' => '', 'raw' => '', 'disconnect' => false, 'epoch' => 0];
}


function dc_detect_tgif_hblink_target(): array {
    $candidates = [
        // AllTune2/TGIF helper paths. Optional; absent on stock systems.
        '/var/www/html/alltune2/tgif-hblink/hblink.cfg',
        '/var/www/html/alltune2/tgif-hblink/rules.py',
        '/var/www/html/alltune2/run/tgif_state.json',
        '/var/www/html/alltune2/run/state.json',

        // Common HBLink/HBlink3 locations used by non-AllTune2 installs. Optional.
        '/etc/hblink/hblink.cfg',
        '/etc/hblink3/hblink.cfg',
        '/opt/HBlink3/hblink.cfg',
        '/opt/hblink3/hblink.cfg',
        '/opt/hblink/hblink.cfg',

        // Simple state-marker files for custom controllers. Optional.
        '/tmp/alltune2_tgif_target',
        '/tmp/tgif_hblink_target',
    ];

    foreach ($candidates as $file) {
        if (!is_readable($file)) continue;
        $text = @file_get_contents($file);
        if (!is_string($text) || $text === '') continue;

        if (str_ends_with($file, '.json')) {
            $json = json_decode($text, true);
            if (is_array($json)) {
                foreach (['target','tg','talkgroup','current_tg','currentTG','tgif_tg','tgifTarget'] as $key) {
                    if (isset($json[$key]) && preg_match('/^([0-9]+)$/', (string)$json[$key], $m)) {
                        return ['value' => $m[1], 'source' => $file, 'epoch' => @filemtime($file) ?: 0];
                    }
                }
            }
        }

        // AllTune2's TGIF/HBLink helper writes this dynamically:
        // OPTIONS: StartRef=<TG>;RelinkTime=60
        if (preg_match('/^\s*OPTIONS\s*:\s*.*?\bStartRef\s*=\s*([0-9]+)\b/im', $text, $m)) {
            return ['value' => $m[1], 'source' => $file . ':OPTIONS', 'epoch' => @filemtime($file) ?: 0];
        }

        if (preg_match('/\bStartRef\s*=\s*([0-9]+)\b/i', $text, $m)) {
            return ['value' => $m[1], 'source' => $file . ':StartRef', 'epoch' => @filemtime($file) ?: 0];
        }

        if (preg_match('/\b(?:TGIF_TARGET|TGIF_TG|CURRENT_TG|target_tg)\b\s*[=:]\s*["\']?([0-9]+)\b/i', $text, $m)) {
            return ['value' => $m[1], 'source' => $file, 'epoch' => @filemtime($file) ?: 0];
        }

        $trim = trim($text);
        if (preg_match('/^[0-9]+$/', $trim)) {
            return ['value' => $trim, 'source' => $file, 'epoch' => @filemtime($file) ?: 0];
        }
    }

    return ['value' => '', 'source' => '', 'epoch' => 0];
}


function dc_detect_alltune2_private_audio_link(): array {
    $configCandidates = [
        '/var/www/html/alltune2/config.ini',
        '/var/www/html/alltune/config.ini',
    ];

    $configFile = '';
    $mynode = '';
    $dvnode = '';

    foreach ($configCandidates as $file) {
        if (!is_readable($file)) continue;
        $ini = @parse_ini_file($file, false, INI_SCANNER_RAW);
        if (!is_array($ini)) continue;

        $candidateMynode = trim((string)($ini['MYNODE'] ?? $ini['mynode'] ?? ''));
        $candidateDvnode = trim((string)($ini['DVSWITCH_NODE'] ?? $ini['dvswitch_node'] ?? ''));
        $candidateMynode = preg_replace('/[^0-9]/', '', $candidateMynode) ?? '';
        $candidateDvnode = preg_replace('/[^0-9]/', '', $candidateDvnode) ?? '';

        if ($candidateMynode !== '' && $candidateDvnode !== '') {
            $configFile = $file;
            $mynode = $candidateMynode;
            $dvnode = $candidateDvnode;
            break;
        }
    }

    if ($mynode === '' || $dvnode === '') {
        return [
            'known' => false,
            'linked' => false,
            'status' => 'unknown',
            'reason' => 'AllTune2 MYNODE/DVSWITCH_NODE not found',
            'config_file' => $configFile,
            'mynode' => $mynode,
            'dvswitch_node' => $dvnode,
            'raw' => '',
        ];
    }

    $rptCmd = 'rpt nodes ' . $mynode;
    $commands = [];
    if (is_executable('/usr/bin/sudo') && is_executable('/usr/sbin/asterisk')) {
        $commands[] = '/usr/bin/timeout 2s /usr/bin/sudo -n /usr/sbin/asterisk -rx ' . escapeshellarg($rptCmd) . ' 2>/dev/null';
    }
    if (is_executable('/usr/sbin/asterisk')) {
        $commands[] = '/usr/bin/timeout 2s /usr/sbin/asterisk -rx ' . escapeshellarg($rptCmd) . ' 2>/dev/null';
    }

    $raw = '';
    $source = '';
    foreach ($commands as $cmd) {
        $out = trim((string)@shell_exec($cmd));
        if ($out !== '') {
            $raw = $out;
            $source = str_contains($cmd, 'sudo -n') ? 'sudo asterisk rpt nodes' : 'asterisk rpt nodes';
            break;
        }
    }

    if ($raw === '') {
        return [
            'known' => false,
            'linked' => false,
            'status' => 'unknown',
            'reason' => 'Unable to read Asterisk rpt nodes output',
            'config_file' => $configFile,
            'mynode' => $mynode,
            'dvswitch_node' => $dvnode,
            'raw' => '',
        ];
    }

    $pattern = '/(^|[^0-9])(?:[A-Z]?\s*)?' . preg_quote($dvnode, '/') . '([^0-9]|$)/i';
    $linked = (bool)preg_match($pattern, $raw);

    return [
        'known' => true,
        'linked' => $linked,
        'status' => $linked ? 'linked' : 'not_linked',
        'reason' => $linked ? 'DVSwitch private node is linked' : 'DVSwitch private node is not linked',
        'config_file' => $configFile,
        'mynode' => $mynode,
        'dvswitch_node' => $dvnode,
        'source' => $source,
        'raw' => $raw,
    ];
}

function dc_is_recent_epoch(int $epoch, int $seconds): bool {
    return $epoch > 0 && (time() - $epoch) < $seconds;
}

function dc_idle_adapter(string $name='Idle'): array {
    return [
        'adapter' => 'idle',
        'provider' => $name,
        'network' => $name,
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
