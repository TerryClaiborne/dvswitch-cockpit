<?php
declare(strict_types=1);

function dc_m17d_read_state(string $path): array {
    $out = [];
    if (!is_readable($path)) return $out;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (!str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if ($k !== '') $out[strtolower($k)] = trim($v, "\"' ");
    }
    return $out;
}

function dc_m17d_process_alive(): bool {
    return dc_process_running([
        '/var/www/html/alltune2/m17d/bin/m17d-arm64',
        '/var/www/html/alltune2/m17d/bin/m17amd64',
    ]);
}

function dc_m17d_display_callsign(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '--';

    // M17 source identity may include a one-letter suffix separated by a space
    // (for example "N6JET E").  Keep the raw identity for metadata, but show
    // the base amateur callsign in the Station/Last Heard columns.
    if (preg_match('/^([A-Z0-9]{3,10})\s+[A-Z]$/i', $raw, $m)) {
        return strtoupper($m[1]);
    }

    return $raw;
}

function dc_m17d_log_stamp(string $line, string $tzName): array {
    if (!preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2}\s+[0-9]{2}:[0-9]{2}:[0-9]{2})\s+/', $line, $m)) {
        return ['epoch' => 0, 'utc' => '', 'display' => '--'];
    }
    try {
        $local = new DateTimeImmutable($m[1], new DateTimeZone($tzName));
        $utc = $local->setTimezone(new DateTimeZone('UTC'));
        return ['epoch'=>$local->getTimestamp(),'utc'=>$utc->format('Y-m-d H:i:s'),'display'=>$local->format('H:i:s M d')];
    } catch (Throwable $e) {
        return ['epoch'=>0,'utc'=>'','display'=>'--'];
    }
}

function dc_m17d_rows_from_log(string $logPath, string $target, string $tzName): array {
    if (!is_readable($logPath)) return [];
    $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return [];
    $lines = array_slice($lines, -900);
    $rows = [];
    $openRx = null;
    $openTx = null;

    foreach ($lines as $line) {
        $stamp = dc_m17d_log_stamp($line, $tzName);
        if (($stamp['epoch'] ?? 0) <= 0) continue;

        if (preg_match('/M17 RX BEGIN\s+src=(.*?)\s+dst=(\S+)\s+reflector=(\S+)\s+module=([A-Z])/i', $line, $m)) {
            $srcRaw = trim($m[1]);
            $src = dc_m17d_display_callsign($srcRaw);
            $displayTarget = $target !== '' ? $target : ($m[3] . ' ' . $m[4]);
            $row = dc_make_row((string)$stamp['utc'], (string)$stamp['display'], 'M17', $src, $displayTarget, 'Net', 'RX');
            $row['identity_confidence'] = 'm17d_rx_src';
            $row['m17_source_raw'] = $srcRaw;
            $rows[] = $row;
            $openRx = count($rows) - 1;
            continue;
        }
        if (preg_match('/M17 RX END\s+frames=([0-9]+)\s+duration=([0-9.]+)/i', $line, $m)) {
            if ($openRx !== null && isset($rows[$openRx])) {
                $rows[$openRx]['dur'] = number_format((float)$m[2], 1, '.', '');
                $openRx = null;
            }
            continue;
        }
        if (preg_match('/M17 TX BEGIN\s+src=(.*?)\s+dst=(\S+)\s+reflector=(\S+)\s+module=([A-Z])/i', $line, $m)) {
            $srcRaw = trim($m[1]);
            $src = dc_m17d_display_callsign($srcRaw);
            $displayTarget = $target !== '' ? $target : ($m[3] . ' ' . $m[4]);
            $row = dc_make_row((string)$stamp['utc'], (string)$stamp['display'], 'M17', $src, $displayTarget, 'LNet', '--');
            $row['identity_confidence'] = 'm17d_tx_src';
            $row['m17_source_raw'] = $srcRaw;
            $rows[] = $row;
            $openTx = count($rows) - 1;
            continue;
        }
        if (preg_match('/M17 TX END\s+frames=([0-9]+)\s+duration=([0-9.]+)/i', $line, $m)) {
            if ($openTx !== null && isset($rows[$openTx])) {
                $rows[$openTx]['dur'] = number_format((float)$m[2], 1, '.', '');
                $openTx = null;
            }
            continue;
        }
        if (preg_match('/M17D STOP/i', $line)) {
            $openRx = null;
            $openTx = null;
        }
    }

    usort($rows, fn($a,$b) => strcmp((string)($b['utc'] ?? ''), (string)($a['utc'] ?? '')));
    return array_slice($rows, 0, 40);
}

function dc_adapter_m17d(string $tzName): array {
    $appDir = '/var/www/html/alltune2';
    $stateFile = $appDir . '/run/alltune2-m17d.state';
    $logFile = $appDir . '/logs/m17d.log';
    $state = dc_m17d_read_state($stateFile);
    $activeFlag = strtolower((string)($state['active'] ?? 'false')) === 'true';
    $target = trim((string)($state['target'] ?? ''));
    $processAlive = dc_m17d_process_alive();
    $rows = dc_m17d_rows_from_log($logFile, $target, $tzName);
    $lastHeard = '--'; $lastSignal = 0;
    if ($rows) {
        $lastHeard = (string)($rows[0]['callsign_display'] ?? $rows[0]['callsign'] ?? '--');
        try { $lastSignal = (new DateTime((string)($rows[0]['utc'] ?? ''), new DateTimeZone('UTC')))->getTimestamp(); } catch (Throwable $e) {}
    }
    if (!$activeFlag || !$processAlive || $target === '') {
        $idle = dc_idle_adapter('M17');
        $idle['adapter']='m17d'; $idle['provider']='M17'; $idle['network']='M17';
        $idle['rows']=$rows; $idle['last_heard']=$lastHeard; $idle['left_label']='Last Heard'; $idle['left_value']=$lastHeard; $idle['signal_epoch']=$lastSignal;
        $idle['debug_m17d_state_file']=$stateFile; $idle['debug_m17d_log_file']=$logFile; $idle['debug_m17d_process_alive']=$processAlive;
        return $idle;
    }
    if ($lastSignal <= 0) $lastSignal = time();
    return [
        'adapter'=>'m17d','provider'=>'M17','network'=>'M17','connection_state'=>'Connected',
        'path_label'=>'M17D + direct USRP','target_display'=>$target,
        'target_note'=>'(from AllTune2 M17D runtime; activity identity from M17D native log)',
        'last_heard'=>$lastHeard,'rows'=>$rows,'left_label'=>'Current Target','left_value'=>$target,'signal_epoch'=>$lastSignal,
        'debug_m17d_state_file'=>$stateFile,'debug_m17d_log_file'=>$logFile,'debug_m17d_process_alive'=>$processAlive,
    ];
}
?>
