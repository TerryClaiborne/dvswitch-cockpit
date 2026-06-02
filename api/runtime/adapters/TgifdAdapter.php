<?php
declare(strict_types=1);

function dc_tgifd_read_state(string $path): array {
    $out = [];
    if (!is_readable($path)) return $out;

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (!str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if ($k !== '') $out[$k] = trim($v, "\"' ");
    }

    return $out;
}

function dc_tgifd_ini_value(string $path, string $section, string $key): string {
    if (!is_readable($path)) return '';

    $current = '';
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim(preg_replace('/[;#].*$/', '', (string)$line));
        if ($line === '') continue;

        if (preg_match('/^\[(.+)\]$/', $line, $m)) {
            $current = strtolower(trim($m[1]));
            continue;
        }

        if ($current !== strtolower($section)) continue;

        if (preg_match('/^' . preg_quote($key, '/') . '\s*=\s*(.+)$/i', $line, $m)) {
            return trim((string)$m[1], "\"' ");
        }
    }

    return '';
}

function dc_tgifd_pid_alive(string $pid, string $expectedNeedle): bool {
    $pid = preg_replace('/\D+/', '', $pid) ?? '';
    if ($pid === '') return false;

    $cmdline = "/proc/$pid/cmdline";
    if (!is_readable($cmdline)) return false;

    $cmd = str_replace("\0", ' ', (string)@file_get_contents($cmdline));
    return str_contains($cmd, $expectedNeedle);
}

function dc_tgifd_log_stamp(string $line, string $tzName): array {
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

function dc_tgifd_rows_from_log(string $logPath, string $target, string $tzName): array {
    if (!is_readable($logPath)) return [];

    $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return [];

    $lines = array_slice($lines, -700);
    $rows = [];
    $seen = [];
    $openNetRow = null;
    $openLocalRow = null;
    $openLocalEpoch = 0;

    foreach ($lines as $line) {
        $stamp = dc_tgifd_log_stamp($line, $tzName);
        if ((int)$stamp['epoch'] <= 0) continue;

        if (preg_match('/TLV BEGIN_TX sent src=([0-9]+)\s+rpt=([0-9]+)\s+dst=([0-9]+)\s+slot=([0-9]+)\s+flags=([0-9]+)/', $line, $m)) {
            $srcId = $m[1];
            $dstTg = $m[3];

            if ($target !== '' && $dstTg !== $target) continue;

            $key = 'net-' . $stamp['epoch'] . '-' . $srcId . '-' . $dstTg;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $row = dc_make_row(
                (string)$stamp['utc'],
                (string)$stamp['display'],
                'DMR/TGIF',
                $srcId,
                'TG ' . $dstTg,
                'Net',
                'RX'
            );

            $row['identity_confidence'] = 'tgifd_header_src';
            $rows[] = $row;
            $openNetRow = count($rows) - 1;
            continue;
        }

        if (preg_match('/bridged inbound DMRD end tx(?: timeout| stream-change)? duration=([0-9]+(?:\\.[0-9]+)?)/', $line, $m)) {
            if ($openNetRow !== null && isset($rows[$openNetRow])) {
                $rows[$openNetRow]['dur'] = $m[1];
                $openNetRow = null;
            }
            continue;
        }

        if (preg_match('/TX fields src=([0-9]+)\s+dst=([0-9]+)\s+peer=([0-9]+)\s+slot2=(true|false)\s+private=(true|false)/', $line, $m)) {
            $srcId = $m[1];
            $dstTg = $m[2];

            if ($target !== '' && $dstTg !== $target) continue;

            $key = 'lnet-' . $stamp['epoch'] . '-' . $srcId . '-' . $dstTg;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $row = dc_make_row(
                (string)$stamp['utc'],
                (string)$stamp['display'],
                'DMR/TGIF',
                $srcId,
                'TG ' . $dstTg,
                'LNet',
                '--'
            );

            $row['identity_confidence'] = 'tgifd_local_tlv_src';
            $rows[] = $row;
            $openLocalRow = count($rows) - 1;
            $openLocalEpoch = (int)$stamp['epoch'];
            continue;
        }
        if (preg_match('/TX end bridged to DMRD terminator/', $line)) {
            if ($openLocalRow !== null && isset($rows[$openLocalRow]) && $openLocalEpoch > 0) {
                $dur = max(0, (int)$stamp['epoch'] - $openLocalEpoch);
                $rows[$openLocalRow]['dur'] = number_format($dur, 2, '.', '');
                $openLocalRow = null;
                $openLocalEpoch = 0;
            }
            continue;
        }

    }

    usort($rows, fn($a, $b) => strcmp((string)($b['utc'] ?? ''), (string)($a['utc'] ?? '')));
    return array_slice($rows, 0, 40);
}

function dc_adapter_tgifd(string $tzName): array {
    $appDir = '/var/www/html/alltune2';
    $stateFile = $appDir . '/run/alltune2-tgifd.state';
    $pidFile = $appDir . '/run/alltune2-tgifd.pid';
    $cfgFile = $appDir . '/tgif/config/tgifd.ini';
    $binNeedle = $appDir . '/tgif/bin/tgifd';
    $logFile = $appDir . '/logs/tgifd-helper.log';

    $state = dc_tgifd_read_state($stateFile);
    $pid = trim((string)($state['pid'] ?? ''));

    if ($pid === '' && is_readable($pidFile)) {
        $pid = trim((string)@file_get_contents($pidFile));
    }

    $activeFlag = strtolower((string)($state['active'] ?? 'false')) === 'true';
    $pidAlive = dc_tgifd_pid_alive($pid, $binNeedle);

    $target = preg_replace('/\D+/', '', (string)($state['target'] ?? '')) ?? '';
    if ($target === '') {
        $target = preg_replace('/\D+/', '', dc_tgifd_ini_value($cfgFile, 'tgif', 'startup_tg')) ?? '';
    }

    if (!$activeFlag || !$pidAlive || $target === '') {
        return dc_idle_adapter('TGIF');
    }

    $rows = dc_tgifd_rows_from_log($logFile, $target, $tzName);

    $lastHeard = '--';
    $lastSignal = time();

    if (!empty($rows)) {
        $first = $rows[0];
        $lastHeard = (string)($first['callsign_display'] ?? $first['callsign'] ?? '--');
        try {
            $lastSignal = (new DateTime((string)($first['utc'] ?? ''), new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable $e) {
            $lastSignal = time();
        }
    }

    $displayTarget = 'TG ' . $target;

    return [
        'adapter' => 'tgifd',
        'provider' => 'TGIFD',
        'network' => 'TGIF',
        'connection_state' => 'Connected',
        'path_label' => 'TGIFD + TLV',
        'target_display' => $displayTarget,
        'target_note' => '(from AllTune2 TGIFD runtime; activity identity from TGIFD DMRD header source)',
        'last_heard' => $lastHeard,
        'rows' => $rows,
        'left_label' => 'Current TG',
        'left_value' => $displayTarget,
        'signal_epoch' => $lastSignal,
        'debug_tgifd_pid' => $pid,
        'debug_tgifd_state_file' => $stateFile,
        'debug_tgifd_log_file' => $logFile,
    ];
}
?>
