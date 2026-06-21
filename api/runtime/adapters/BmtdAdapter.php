<?php
declare(strict_types=1);

function dc_bmtd_read_state(string $path): array {
    $out = [];
    if (!is_readable($path)) return $out;

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (!str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if ($k !== '') $out[strtolower($k)] = trim($v, "\"' ");
    }

    return $out;
}

function dc_bmtd_pid_alive(string $pid, string $expectedNeedle): bool {
    $pid = preg_replace('/\D+/', '', $pid) ?? '';
    if ($pid === '') return false;

    $cmdline = "/proc/$pid/cmdline";
    if (!is_readable($cmdline)) return false;

    $cmd = str_replace("\0", ' ', (string)@file_get_contents($cmdline));
    return str_contains($cmd, $expectedNeedle);
}

function dc_bmtd_log_stamp(string $line, string $tzName): array {
    if (!preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2}\s+[0-9]{2}:[0-9]{2}:[0-9]{2})\s+/', $line, $m)) {
        return ['epoch' => 0, 'utc' => '', 'display' => '--'];
    }

    try {
        $local = new DateTimeImmutable($m[1], new DateTimeZone($tzName));
        $utc = $local->setTimezone(new DateTimeZone('UTC'));
        return [
            'epoch' => $local->getTimestamp(),
            'utc' => $utc->format('Y-m-d H:i:s'),
            'display' => $local->format('H:i:s M d'),
        ];
    } catch (Throwable $e) {
        return ['epoch' => 0, 'utc' => '', 'display' => '--'];
    }
}

function dc_bmtd_rows_from_log(string $logPath, string $target, string $tzName): array {
    if (!is_readable($logPath)) return [];

    $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return [];

    $lines = array_slice($lines, -900);
    $rows = [];
    $seen = [];
    $openNetRow = null;
    $openLocalRow = null;
    $openLocalEpoch = 0;

    foreach ($lines as $line) {
        $stamp = dc_bmtd_log_stamp($line, $tzName);
        $epoch = (int)($stamp['epoch'] ?? 0);
        if ($epoch <= 0) continue;

        if (preg_match('/BM RX BEGIN\s+src=([0-9]+)\s+dst=([0-9]+)\s+private=([01])/i', $line, $m)) {
            $srcId = trim($m[1]);
            $dst = trim($m[2]);
            $isPrivate = trim($m[3]) === '1';

            $displayTarget = $target !== '' ? $target : $dst;
            if ($displayTarget === '' || $displayTarget === '0') continue;

            $key = 'net-' . $epoch . '-' . $srcId . '-' . $displayTarget . '-' . ($isPrivate ? 'p' : 'g');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $row = dc_make_row(
                (string)($stamp['utc'] ?? ''),
                (string)($stamp['display'] ?? '--'),
                'DMR/BM',
                $srcId,
                'TG ' . $displayTarget,
                'Net',
                'RX'
            );

            $row['identity_confidence'] = 'bmtd_rx_src';
            $rows[] = $row;
            $openNetRow = count($rows) - 1;
            continue;
        }

        if (preg_match('/BM RX END(?:\\s+frames=([0-9]+))?/i', $line, $m)) {
            if ($openNetRow !== null && isset($rows[$openNetRow])) {
                if (!empty($m[1])) {
                    $rows[$openNetRow]['dur'] = dc_frame_count_to_seconds($m[1]);
                } else {
                    $rows[$openNetRow]['dur'] = 'RX';
                }
                $openNetRow = null;
            }
            continue;
        }

        if (preg_match('/BM TX BEGIN\s+dst=([0-9]+)\s+src=([0-9]+)/i', $line, $m)) {
            $dst = trim($m[1]);
            $srcId = trim($m[2]);

            $displayTarget = $target !== '' ? $target : $dst;
            if ($displayTarget === '' || $displayTarget === '0') continue;

            $key = 'lnet-' . $epoch . '-' . $srcId . '-' . $displayTarget;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $row = dc_make_row(
                (string)($stamp['utc'] ?? ''),
                (string)($stamp['display'] ?? '--'),
                'DMR/BM',
                $srcId,
                'TG ' . $displayTarget,
                'LNet',
                '--'
            );

            $row['identity_confidence'] = 'bmtd_tx_src';
            $rows[] = $row;
            $openLocalRow = count($rows) - 1;
            $openLocalEpoch = $epoch;
            continue;
        }

        if (preg_match('/BM TX END\s+frames=([0-9]+)/i', $line, $m)) {
            if ($openLocalRow !== null && isset($rows[$openLocalRow])) {
                $rows[$openLocalRow]['dur'] = dc_frame_count_to_seconds($m[1]);
                $openLocalRow = null;
                $openLocalEpoch = 0;
            }
            continue;
        }

        if (preg_match('/BMTD stopped cleanly|BMTD shutdown requested/i', $line)) {
            $openNetRow = null;
            $openLocalRow = null;
            $openLocalEpoch = 0;
            continue;
        }
    }

    usort($rows, fn($a, $b) => strcmp((string)($b['utc'] ?? ''), (string)($a['utc'] ?? '')));
    return array_slice($rows, 0, 40);
}

function dc_adapter_bmtd(string $tzName): array {
    $appDir = '/var/www/html/alltune2';
    $stateFile = $appDir . '/run/alltune2-bmtd.state';
    $pidFile = $appDir . '/run/alltune2-bmtd.pid';
    $binNeedle = $appDir . '/bmtd/bin/bmtd';
    $logFile = $appDir . '/logs/bmtd.log';

    $state = dc_bmtd_read_state($stateFile);
    $pid = trim((string)($state['pid'] ?? ''));

    if ($pid === '' && is_readable($pidFile)) {
        $pid = trim((string)@file_get_contents($pidFile));
    }

    $activeFlag = strtolower((string)($state['active'] ?? 'false')) === 'true';
    $pidAlive = dc_bmtd_pid_alive($pid, $binNeedle);
    $target = preg_replace('/\D+/', '', (string)($state['target'] ?? '')) ?? '';

    // Keep BMTD activity rows available even when BMTD is idle.
    // Connection state may go idle, but history should not disappear when switching networks.
    $rows = dc_bmtd_rows_from_log($logFile, $target, $tzName);

    $lastHeard = '--';
    $lastSignal = 0;

    if (!empty($rows)) {
        $first = $rows[0];
        $lastHeard = (string)($first['callsign_display'] ?? $first['callsign'] ?? '--');
        try {
            $lastSignal = (new DateTime((string)($first['utc'] ?? ''), new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable $e) {
            $lastSignal = 0;
        }
    }

    if (!$activeFlag || !$pidAlive || $target === '') {
        $idle = dc_idle_adapter('BrandMeister');
        $idle['adapter'] = 'bmtd';
        $idle['provider'] = 'BrandMeister';
        $idle['network'] = 'BrandMeister';
        $idle['rows'] = $rows;
        $idle['last_heard'] = $lastHeard;
        $idle['left_label'] = 'Last Heard';
        $idle['left_value'] = $lastHeard;
        $idle['signal_epoch'] = $lastSignal;
        $idle['debug_bmtd_pid'] = $pid;
        $idle['debug_bmtd_state_file'] = $stateFile;
        $idle['debug_bmtd_log_file'] = $logFile;
        return $idle;
    }

    if ($lastSignal <= 0) {
        $lastSignal = time();
    }

    $displayTarget = 'TG ' . $target;

    return [
        'adapter' => 'bmtd',
        'provider' => 'BrandMeister',
        'network' => 'BrandMeister',
        'connection_state' => 'Connected',
        'path_label' => 'BMTD + TLV',
        'target_display' => $displayTarget,
        'target_note' => '(from AllTune2 BMTD runtime; activity identity from BMTD BM/TLV log)',
        'last_heard' => $lastHeard,
        'rows' => $rows,
        'left_label' => 'Current TG',
        'left_value' => $displayTarget,
        'signal_epoch' => $lastSignal,
        'debug_bmtd_pid' => $pid,
        'debug_bmtd_state_file' => $stateFile,
        'debug_bmtd_log_file' => $logFile,
    ];
}
?>
