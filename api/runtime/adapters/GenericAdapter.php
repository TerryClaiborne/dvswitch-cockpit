<?php
declare(strict_types=1);

function dc_generic_digits(string $value): string {
    return preg_replace('/\D+/', '', $value) ?? '';
}

function dc_generic_ignore_station(string $mode, string $station, string $target): bool {
    $mode = strtoupper(trim($mode));
    $station = strtoupper(trim($station));
    $targetDigits = dc_generic_digits($target);

    if ($targetDigits === '0') return true;
    if ($station === '' || $station === '0') return true;

    // Only reject whole placeholder values. Do not reject real alphanumeric
    // callsigns such as G0RDH, KB0LSM, W9TRO, W2CHI, or 2W0HFU just because
    // they contain digits.
    if (in_array($station, ['1234567', '9999', 'P25GATE', 'NXDNGATE'], true)) return true;
    if ($mode === 'P25' && $station === '10999') return true;

    return false;
}

function dc_generic_local_station(array $abinfo): string {
    $dig = $abinfo['digital'] ?? [];

    $station = dc_display_station_call(
        (string)($dig['call'] ?? ''),
        (string)($dig['gw'] ?? '')
    );

    if ($station === '' || $station === '--') {
        $station = trim((string)($dig['call'] ?? ''));
    }

    return $station !== '' ? $station : '--';
}

function dc_generic_display_station(string $mode, string $src, string $rawStation, string $target, string $localStation): string {
    $mode = strtoupper($mode);
    $src = strtoupper($src);
    $rawStation = trim($rawStation);
    $targetDigits = dc_generic_digits($target);

    if ($src === 'LNET' || $src === 'RF') {
        return $localStation;
    }

    if (($mode === 'P25' || $mode === 'NXDN') && $targetDigits === '10') {
        return 'PARROT';
    }

    if ($mode === 'P25' && $targetDigits === '9999') {
        return 'MMDVM';
    }

    return $rawStation !== '' ? $rawStation : '--';
}

function dc_adapter_generic(array $bridgeLines, string $tzName, array $abinfo = []): array {
    $rows = [];
    $provider = 'Idle';
    $network = 'Idle';
    $path = 'Idle';
    $target = '--';
    $lastHeard = '--';
    $lastSignal = 0;
    $localStation = dc_generic_local_station($abinfo);

    foreach ($bridgeLines as $line) {
        $stamp = dc_parse_log_dt($line, $tzName);
        $epoch = (int)($stamp['epoch'] ?? 0);

        // P25 and NXDN are intentionally not owned by GenericAdapter anymore.
        // They have separate session-aware adapters that can read Analog_Bridge
        // Begin TX lines without filtering real callsigns that contain digits.

        if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+(D-Star),\s+received (RF|network) .* from ([^ ]+) to ([^ ]+)/i', $line, $m)) {
            $src = strtoupper($m[2]) === 'NETWORK' ? 'Net' : 'LNet';
            $targetClean = dc_clean_target($m[4]);
            $station = dc_generic_display_station('D-Star', $src, trim($m[3]), $targetClean, $localStation);

            $rows[] = dc_make_row($stamp['utc'], $stamp['display'], 'D-Star', $station, $targetClean, $src);
            $provider = 'D-Star';
            $network = 'D-Star';
            $path = 'D-Star';
            $target = $targetClean;
            $lastHeard = $station;
            $lastSignal = max($lastSignal, $epoch);
            continue;
        }
    }

    usort($rows, fn($a, $b) => strcmp((string)($b['utc'] ?? ''), (string)($a['utc'] ?? '')));

    return [
        'adapter' => 'generic',
        'provider' => $provider,
        'network' => $network,
        'connection_state' => dc_is_recent_epoch((int)$lastSignal, 180) ? 'Connected' : 'Idle',
        'path_label' => dc_is_recent_epoch((int)$lastSignal, 180) ? $path : 'Idle',
        'target_display' => $target,
        'target_note' => '(from stock bridge logs)',
        'last_heard' => $lastHeard,
        'rows' => array_slice($rows, 0, 60),
        'left_label' => 'Last Heard',
        'left_value' => $lastHeard,
        'signal_epoch' => $lastSignal,
    ];
}
?>
