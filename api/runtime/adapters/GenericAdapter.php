<?php
declare(strict_types=1);

function dc_generic_digits(string $value): string {
    return preg_replace('/\D+/', '', $value) ?? '';
}

function dc_generic_ignore_station(string $mode, string $station, string $target): bool {
    $mode = strtoupper(trim($mode));
    $station = strtoupper(trim($station));
    $digits = dc_generic_digits($station);
    $targetDigits = dc_generic_digits($target);

    if ($targetDigits === '0') return true;
    if ($station === '' || $station === '0') return true;
    if (in_array($digits, ['0', '1234567'], true)) return true;
    if (in_array($station, ['P25GATE', 'NXDNGATE'], true)) return true;
    if ($mode === 'P25' && $digits === '10999') return true;

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

    // Stock DVSwitch shows local network/RF activity as the local node callsign.
    if ($src === 'LNET' || $src === 'RF') {
        return $localStation;
    }

    // Stock DVSwitch labels P25/NXDN talkback targets as PARROT.
    if (($mode === 'P25' || $mode === 'NXDN') && $targetDigits === '10') {
        return 'PARROT';
    }

    // Stock DVSwitch commonly labels the P25 9999 test target as MMDVM.
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
    $lastIdxByModeSrc = [];
    $localStation = dc_generic_local_station($abinfo);
    $gatewayId = trim((string)($abinfo['digital']['gw'] ?? ''));
    $localCall = strtoupper(trim((string)($abinfo['digital']['call'] ?? '')));

    foreach ($bridgeLines as $line) {
        $stamp = dc_parse_log_dt($line, $tzName);
        $epoch = (int)($stamp['epoch'] ?? 0);

        // P25/NXDN can expose real caller identity in Analog_Bridge Begin TX
        // lines. Use it for network rows instead of treating every Begin TX as
        // local activity. Ignore cleanup/default rows such as TG 0 and 1234567.
        if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+(P25|NXDN),\s+Begin TX:\s+src=([0-9]+)\s+rpt=([0-9]+)\s+dst=([^\s]+).*?(?:metadata|call)=([^\s]*)/i', $line, $m)) {
            $mode = strtoupper(trim($m[1]));
            $srcId = trim($m[2]);
            $dst = trim($m[4]);
            $rawCall = strtoupper(trim($m[5]));
            $targetDigits = dc_generic_digits($dst);

            if ($targetDigits === '' || $targetDigits === '0') {
                continue;
            }

            if (dc_generic_ignore_station($mode, $rawCall !== '' ? $rawCall : $srcId, $dst)) {
                continue;
            }

            $placeholderCaller = in_array($srcId, ['0', '1234567'], true)
                && ($rawCall === '' || $rawCall === '0' || $rawCall === $srcId);

            $systemCaller = (
                ($mode === 'P25' && $srcId === '10999') ||
                in_array($rawCall, ['P25GATE', 'NXDNGATE'], true)
            );

            if ($placeholderCaller || $systemCaller) {
                continue;
            }

            $targetClean = dc_clean_target('TG ' . $dst);
            $isLocal = (
                ($gatewayId !== '' && $srcId === $gatewayId) ||
                ($localCall !== '' && $rawCall === $localCall)
            );

            $src = $isLocal ? 'LNet' : 'Net';
            $rawStation = $rawCall !== '' ? $rawCall : $srcId;
            $station = $isLocal
                ? $localStation
                : dc_generic_display_station($mode, $src, $rawStation, $targetClean, $localStation);

            if ($station === '' || $station === '--' || $station === '0') {
                continue;
            }

            $rows[] = dc_make_row($stamp['utc'], $stamp['display'], $mode, $station, $targetClean, $src);
            $lastIdxByModeSrc[$mode . '|' . $src] = count($rows) - 1;

            $provider = $mode;
            $network = $mode;
            $path = $mode;
            $target = $targetClean;
            $lastHeard = $station;
            $lastSignal = max($lastSignal, $epoch);
            continue;
        }

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

        if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+(P25),\s+received (RF|network) .* from ([^ ]+) to (TG )?(.+)$/i', $line, $m)) {
            $src = strtoupper($m[2]) === 'NETWORK' ? 'Net' : 'LNet';
            $targetClean = dc_clean_target(($m[4] ?? '') . $m[5]);
            if (dc_generic_digits($targetClean) === '0') {
                continue;
            }

            $rawStation = trim($m[3]);
            if ($rawStation === '0' || $rawStation === '' || dc_generic_ignore_station('P25', $rawStation, $targetClean)) {
                continue;
            }

            $station = dc_generic_display_station('P25', $src, $rawStation, $targetClean, $localStation);

            $rows[] = dc_make_row($stamp['utc'], $stamp['display'], 'P25', $station, $targetClean, $src);
            $lastIdxByModeSrc['P25|' . $src] = count($rows) - 1;

            $provider = 'P25';
            $network = 'P25';
            $path = 'P25';
            $target = $targetClean;
            $lastHeard = $station;
            $lastSignal = max($lastSignal, $epoch);
            continue;
        }

        if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+(NXDN),\s+received (RF|network) .* from ([^ ]+) to (TG )?(.+)$/i', $line, $m)) {
            $src = strtoupper($m[2]) === 'NETWORK' ? 'Net' : 'LNet';
            $targetClean = dc_clean_target(($m[4] ?? '') . $m[5]);
            if (dc_generic_digits($targetClean) === '0') {
                continue;
            }

            $rawStation = trim($m[3]);
            if ($rawStation === '0' || $rawStation === '' || dc_generic_ignore_station('NXDN', $rawStation, $targetClean)) {
                continue;
            }

            $station = dc_generic_display_station('NXDN', $src, $rawStation, $targetClean, $localStation);

            $rows[] = dc_make_row($stamp['utc'], $stamp['display'], 'NXDN', $station, $targetClean, $src);
            $lastIdxByModeSrc['NXDN|' . $src] = count($rows) - 1;

            $provider = 'NXDN';
            $network = 'NXDN';
            $path = 'NXDN';
            $target = $targetClean;
            $lastHeard = $station;
            $lastSignal = max($lastSignal, $epoch);
            continue;
        }

        if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+P25,\s+network end of transmission,\s+([0-9.]+)\s+seconds,\s+([0-9.]+%)\s+packet loss/i', $line, $m)) {
            $idx = $lastIdxByModeSrc['P25|Net'] ?? null;
            if ($idx !== null && isset($rows[$idx])) {
                $rows[$idx]['dur'] = $m[1];
                $rows[$idx]['loss'] = $m[2];
            }
            $lastSignal = max($lastSignal, $epoch);
            continue;
        }

        if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+P25,\s+RF end of transmission,\s+([0-9.]+)\s+seconds,\s+(?:BER:\s+)?([0-9.]+%)(?:\s+packet loss)?/i', $line, $m)) {
            $idx = $lastIdxByModeSrc['P25|LNet'] ?? null;
            if ($idx !== null && isset($rows[$idx])) {
                $rows[$idx]['dur'] = $m[1];
                $rows[$idx]['ber'] = $m[2];
            }
            $lastSignal = max($lastSignal, $epoch);
            continue;
        }

        if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+P25,\s+TX state = OFF/i', $line)) {
            $idx = $lastIdxByModeSrc['P25|LNet'] ?? null;
            if ($idx !== null && isset($rows[$idx]) && !dc_merge_value_is_useful($rows[$idx]['dur'] ?? '')) {
                $rows[$idx]['dur'] = '--';
            }
            $lastSignal = max($lastSignal, $epoch);
            continue;
        }

        if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+NXDN,\s+received (network|RF) end of transmission,\s+([0-9.]+)\s+seconds(?:,\s+BER:\s+([0-9.]+%))?/i', $line, $m)) {
            $src = strtoupper($m[1]) === 'NETWORK' ? 'Net' : 'LNet';
            $idx = $lastIdxByModeSrc['NXDN|' . $src] ?? null;
            if ($idx !== null && isset($rows[$idx])) {
                $rows[$idx]['dur'] = $m[2];
                if (!empty($m[3])) {
                    $rows[$idx]['ber'] = $m[3];
                }
            }
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
