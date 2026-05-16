<?php
declare(strict_types=1);

function dc_nxdn_digits(string $value): string {
    return preg_replace('/\D+/', '', $value) ?? '';
}

function dc_nxdn_local_station(array $abinfo): string {
    $dig = $abinfo['digital'] ?? [];
    $station = dc_display_station_call((string)($dig['call'] ?? ''), (string)($dig['gw'] ?? ''));
    if ($station === '' || $station === '--') {
        $station = trim((string)($dig['call'] ?? ''));
    }
    return $station !== '' ? strtoupper($station) : '--';
}

function dc_nxdn_gateway_id(array $abinfo): string {
    return dc_nxdn_digits((string)($abinfo['digital']['gw'] ?? ''));
}

function dc_nxdn_is_private_gateway_target(string $target, array $abinfo): bool {
    $digits = dc_nxdn_digits($target);
    $gateway = dc_nxdn_gateway_id($abinfo);
    return $digits !== '' && $gateway !== '' && $digits === $gateway;
}

function dc_nxdn_line_mode(string $line): string {
    if (preg_match('/MESSAGE packet sent to USRP client:\s+Setting mode to\s+([A-Z0-9\-]+)/i', $line, $m)) {
        return strtoupper(trim($m[1]));
    }
    if (preg_match('/\bambeMode\s*=\s*([A-Z0-9\-]+)/i', $line, $m)) {
        return strtoupper(trim($m[1]));
    }
    if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+(P25|NXDN|DMR|D-Star|YSF),/i', $line, $m)) {
        return strtoupper(str_replace('-', '', trim($m[1])));
    }
    return '';
}

function dc_nxdn_txtg_from_line(string $line, string $tzName): array {
    if (!preg_match('/\btxTg\s*=?\s*:?\s*([0-9#]+)/i', $line, $m)) {
        return ['value' => '', 'raw' => '', 'disconnect' => false, 'epoch' => 0];
    }
    $stamp = dc_parse_log_dt($line, $tzName);
    $raw = trim($m[1]);
    $value = rtrim($raw, '#');
    return [
        'value' => $value,
        'raw' => $raw,
        'disconnect' => str_ends_with($raw, '#') || $value === '0',
        'epoch' => (int)($stamp['epoch'] ?? 0),
    ];
}

function dc_nxdn_begin_tx_from_line(string $line): array {
    if (!preg_match('/\bBegin TX:\s*src=([0-9]+)\s+rpt=([0-9]+)\s+dst=([^\s]+)(.*)$/i', $line, $m)) {
        return [];
    }

    $tail = (string)$m[4];
    $call = '';
    if (preg_match('/\bcall=([^\s]+)/i', $tail, $cm)) {
        $call = trim($cm[1]);
    } elseif (preg_match('/\bmetadata=([^\s]+)/i', $tail, $cm)) {
        $call = trim($cm[1]);
    }

    return [
        'src_id' => trim($m[1]),
        'rpt_id' => trim($m[2]),
        'dst' => trim($m[3]),
        'call' => strtoupper($call),
    ];
}

function dc_nxdn_reject_station(string $station, string $srcId, string $target, array $abinfo): bool {
    $station = strtoupper(trim($station));
    $srcId = trim($srcId);
    $targetDigits = dc_nxdn_digits($target);

    if ($targetDigits === '' || $targetDigits === '0') return true;
    if (dc_nxdn_is_private_gateway_target($target, $abinfo)) return true;
    if ($station === '' || $station === '0' || $station === '--') return true;
    if (in_array($station, ['1234567', '9999', 'NXDNGATE'], true)) return true;
    if ($srcId === '0') return true;
    if ($srcId === '1234567') return true;

    return false;
}

function dc_nxdn_display_station(string $src, string $station, string $target, string $localStation): string {
    $src = strtoupper($src);
    $targetDigits = dc_nxdn_digits($target);
    $station = strtoupper(trim($station));

    if ($src === 'LNET' || $src === 'RF') return $localStation;
    if ($targetDigits === '10') return 'PARROT';
    return $station !== '' ? $station : '--';
}

function dc_nxdn_current_target(array $analogLines, array $abinfo, string $tzName): array {
    $currentMode = strtoupper((string)($abinfo['tlv']['ambe_mode'] ?? ''));
    $target = '';
    $epoch = 0;
    $recentDisconnect = false;
    $recentDisconnectEpoch = 0;

    foreach ($analogLines as $line) {
        $mode = dc_nxdn_line_mode($line);
        if ($mode !== '') {
            $currentMode = $mode;
            if ($currentMode !== 'NXDN') {
                $target = '';
            }
        }

        $tx = dc_nxdn_txtg_from_line($line, $tzName);
        if ($tx['raw'] !== '') {
            if ($currentMode !== 'NXDN') {
                continue;
            }
            if ((bool)$tx['disconnect']) {
                $target = '';
                $epoch = (int)$tx['epoch'];
                $recentDisconnect = true;
                $recentDisconnectEpoch = (int)$tx['epoch'];
                continue;
            }
            if (!dc_nxdn_is_private_gateway_target((string)$tx['value'], $abinfo)) {
                $target = (string)$tx['value'];
                $epoch = (int)$tx['epoch'];
                $recentDisconnect = false;
            }
        }
    }

    $runtime = $abinfo['_runtime']['latest_txtg'] ?? [];
    $runtimeValue = trim((string)($runtime['value'] ?? ''));
    $runtimeEpoch = (int)($runtime['epoch'] ?? 0);
    $runtimeDisconnect = (bool)($runtime['disconnect'] ?? false);
    if ($runtimeDisconnect && $runtimeEpoch > 0 && (time() - $runtimeEpoch) < 120) {
        return ['value' => '', 'epoch' => $runtimeEpoch, 'disconnect' => true];
    }

    if ($target === '' && $runtimeValue !== '' && $runtimeValue !== '0' && !$runtimeDisconnect && !dc_nxdn_is_private_gateway_target($runtimeValue, $abinfo)) {
        $target = $runtimeValue;
        $epoch = $runtimeEpoch;
    }

    if ($target === '' && !$recentDisconnect) {
        foreach ([(string)($abinfo['digital']['tg'] ?? ''), (string)($abinfo['last_tune'] ?? '')] as $candidate) {
            $candidate = dc_nxdn_digits($candidate);
            if ($candidate !== '' && $candidate !== '0' && !dc_nxdn_is_private_gateway_target($candidate, $abinfo)) {
                $target = $candidate;
                break;
            }
        }
    }

    return ['value' => $target, 'epoch' => $epoch ?: $recentDisconnectEpoch, 'disconnect' => false];
}

function dc_nxdn_bridge_start_from_line(string $line): array {
    if (!preg_match('/^\w:\s+[0-9:\-\. ]+\s+NXDN,\s+received\s+(network|RF)\s+transmission\s+from\s+(.+?)\s+to\s+(TG\s+)?(.+)$/i', $line, $m)) {
        return [];
    }

    $srcKind = strtoupper(trim($m[1]));
    $rawStation = strtoupper(trim($m[2]));
    $target = dc_clean_target(trim(($m[3] ?? '') . $m[4]));

    return [
        'src' => $srcKind === 'NETWORK' ? 'Net' : 'LNet',
        'station' => $rawStation,
        'target' => $target,
    ];
}

function dc_nxdn_bridge_eot_from_line(string $line): array {
    if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+NXDN,\s+(?:received\s+)?network end of transmission,\s+([0-9.]+)\s+seconds(?:,\s+([0-9.]+%)\s+packet loss)?(?:,\s+BER:\s+([0-9.]+%))?/i', $line, $m)) {
        return [
            'src' => 'Net',
            'dur' => $m[1],
            'loss' => !empty($m[2]) ? $m[2] : '--',
            'ber' => !empty($m[3]) ? $m[3] : '--',
        ];
    }

    if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+NXDN,\s+(?:received\s+)?RF end of transmission,\s+([0-9.]+)\s+seconds(?:,\s+BER:\s+([0-9.]+%))?(?:,\s+([0-9.]+%)\s+packet loss)?/i', $line, $m)) {
        return [
            'src' => 'LNet',
            'dur' => $m[1],
            'loss' => !empty($m[3]) ? $m[3] : '--',
            'ber' => !empty($m[2]) ? $m[2] : '--',
        ];
    }

    return [];
}

function dc_nxdn_make_bridge_rows(array $bridgeLines, array $abinfo, string $tzName, string $localStation): array {
    $rows = [];
    $active = [
        'Net' => ['idx' => null],
        'LNet' => ['idx' => null],
    ];

    foreach ($bridgeLines as $line) {
        $stamp = dc_parse_log_dt($line, $tzName);
        $epoch = (int)($stamp['epoch'] ?? 0);

        // Local NXDN/parrot key-up is logged by MMDVM_Bridge as a Begin TX line
        // followed by an RF end-of-transmission. Create the Local Activity row
        // here so the RF EOT updates this same row instead of only showing the
        // returned parrot audio in Gateway Activity.
        $begin = dc_nxdn_begin_tx_from_line($line);
        if ($begin && preg_match('/\bNXDN,\s+Begin TX:/i', $line)) {
            $rawStation = $begin['call'] !== '' ? $begin['call'] : $begin['src_id'];
            $targetClean = dc_clean_target('TG ' . $begin['dst']);
            $gatewayId = dc_nxdn_gateway_id($abinfo);
            $localCall = strtoupper(trim((string)($abinfo['digital']['call'] ?? '')));
            $isLocal = (
                ($gatewayId !== '' && $begin['src_id'] === $gatewayId) ||
                ($localCall !== '' && strtoupper($begin['call']) === $localCall)
            );

            if ($isLocal) {
                // Replace the active local slot so a later RF EOT cannot attach
                // quality/duration to an older local row.
                $active['LNet'] = ['idx' => null];

                if (!dc_nxdn_reject_station($rawStation, $begin['src_id'], $begin['dst'], $abinfo)) {
                    $station = $localStation !== '' && $localStation !== '--' ? $localStation : strtoupper((string)$rawStation);
                    if ($station !== '' && $station !== '--' && $station !== '0') {
                        $rows[] = dc_make_row($stamp['utc'], $stamp['display'], 'NXDN', $station, $targetClean, 'LNet');
                        $active['LNet'] = ['idx' => count($rows) - 1, 'epoch' => $epoch, 'station' => $station, 'target' => $targetClean];
                    }
                }
                continue;
            }
        }

        $start = dc_nxdn_bridge_start_from_line($line);
        if ($start) {
            $src = (string)$start['src'];
            $stationRaw = strtoupper((string)$start['station']);
            $targetClean = dc_clean_target((string)$start['target']);

            // Always replace the active slot. If this is a placeholder caller,
            // the following EOT belongs to the placeholder and must not be merged
            // into the previous real station.
            $active[$src] = ['idx' => null];

            if (dc_nxdn_reject_station($stationRaw, $stationRaw, $targetClean, $abinfo)) {
                continue;
            }

            $station = dc_nxdn_display_station($src, $stationRaw, $targetClean, $localStation);
            if ($station === '' || $station === '--' || $station === '0') {
                continue;
            }

            $rows[] = dc_make_row($stamp['utc'], $stamp['display'], 'NXDN', $station, $targetClean, $src);
            $active[$src] = ['idx' => count($rows) - 1, 'epoch' => $epoch, 'station' => $station, 'target' => $targetClean];
            continue;
        }

        $eot = dc_nxdn_bridge_eot_from_line($line);
        if ($eot) {
            $src = (string)$eot['src'];
            $idx = $active[$src]['idx'] ?? null;
            if (is_int($idx) && isset($rows[$idx])) {
                $rows[$idx]['dur'] = (string)$eot['dur'];
                if ($eot['loss'] !== '--') $rows[$idx]['loss'] = (string)$eot['loss'];
                if ($eot['ber'] !== '--') $rows[$idx]['ber'] = (string)$eot['ber'];
            }
            $active[$src] = ['idx' => null];
            continue;
        }
    }

    return $rows;
}

function dc_nxdn_row_epoch(array $row): int {
    try {
        return (new DateTime((string)($row['utc'] ?? ''), new DateTimeZone('UTC')))->getTimestamp();
    } catch (Throwable $e) {
        return 0;
    }
}

function dc_nxdn_has_near_duplicate(array $rows, string $station, string $target, string $src, int $epoch, int $windowSeconds = 3): bool {
    $station = strtoupper(trim($station));
    $target = dc_clean_target($target);
    $src = strtoupper(trim($src));

    foreach ($rows as $row) {
        $rowStation = strtoupper(trim((string)($row['callsign_display'] ?? $row['callsign'] ?? '')));
        $rowTarget = dc_clean_target((string)($row['target'] ?? ''));
        $rowSrc = strtoupper(trim((string)($row['src'] ?? '')));
        $rowEpoch = dc_nxdn_row_epoch($row);
        if ($rowStation === $station && $rowTarget === $target && $rowSrc === $src && abs($rowEpoch - $epoch) <= $windowSeconds) {
            return true;
        }
    }

    return false;
}

function dc_nxdn_fallback_analog_rows(array $analogLines, array $existingRows, array $abinfo, string $tzName, string $localStation): array {
    $rows = [];
    $currentMode = strtoupper((string)($abinfo['tlv']['ambe_mode'] ?? ''));
    $gatewayId = dc_nxdn_gateway_id($abinfo);
    $localCall = strtoupper(trim((string)($abinfo['digital']['call'] ?? '')));

    foreach ($analogLines as $line) {
        $lineMode = dc_nxdn_line_mode($line);
        if ($lineMode !== '') {
            $currentMode = $lineMode;
        }

        $begin = dc_nxdn_begin_tx_from_line($line);
        if (!$begin) continue;
        if ($lineMode !== '' && $lineMode !== 'NXDN') continue;
        if ($currentMode !== 'NXDN' && stripos($line, ' NXDN,') === false) continue;

        $stamp = dc_parse_log_dt($line, $tzName);
        $epoch = (int)($stamp['epoch'] ?? 0);
        $rawStation = $begin['call'] !== '' ? $begin['call'] : $begin['src_id'];
        if (dc_nxdn_reject_station($rawStation, $begin['src_id'], $begin['dst'], $abinfo)) continue;

        $isLocal = (
            ($gatewayId !== '' && $begin['src_id'] === $gatewayId) ||
            ($localCall !== '' && strtoupper($begin['call']) === $localCall)
        );
        $src = $isLocal ? 'LNet' : 'Net';
        $targetClean = dc_clean_target('TG ' . $begin['dst']);
        $station = $isLocal ? $localStation : dc_nxdn_display_station($src, $rawStation, $targetClean, $localStation);
        if ($station === '' || $station === '--' || $station === '0') continue;

        if (dc_nxdn_has_near_duplicate(array_merge($existingRows, $rows), $station, $targetClean, $src, $epoch)) {
            continue;
        }

        $rows[] = dc_make_row($stamp['utc'], $stamp['display'], 'NXDN', $station, $targetClean, $src);
    }

    return $rows;
}

function dc_adapter_nxdn(array $analogLines, array $bridgeLines, array $abinfo, array $cache, string $tzName): array {
    $localStation = dc_nxdn_local_station($abinfo);

    // Primary NXDN activity source: MMDVM_Bridge start/EOT pairs. This mirrors
    // the corrected P25 behavior: show the station at received-transmission time,
    // then update that same row when the matching end-of-transmission line arrives.
    $rows = dc_nxdn_make_bridge_rows($bridgeLines, $abinfo, $tzName, $localStation);

    // Analog_Bridge Begin TX is fallback/enrichment only. It must not own EOT
    // duration/quality, because placeholder transmissions such as 1234567/9999
    // would otherwise get merged into the previous real callsign.
    $rows = array_merge($rows, dc_nxdn_fallback_analog_rows($analogLines, $rows, $abinfo, $tzName, $localStation));

    usort($rows, fn($a, $b) => strcmp((string)($b['utc'] ?? ''), (string)($a['utc'] ?? '')));

    $lastSignal = 0;
    foreach ($rows as $row) {
        $lastSignal = max($lastSignal, dc_nxdn_row_epoch($row));
    }

    $liveMode = strtoupper((string)($abinfo['tlv']['ambe_mode'] ?? ''));
    $targetInfo = dc_nxdn_current_target($analogLines, $abinfo, $tzName);
    $targetValue = trim((string)($targetInfo['value'] ?? ''));
    $connected = ($liveMode === 'NXDN' && $targetValue !== '' && $targetValue !== '0');
    $targetDisplay = $connected ? ('TG ' . $targetValue) : '--';

    $lastHeard = '--';
    foreach ($rows as $row) {
        if ($connected && (string)($row['target'] ?? '') !== $targetDisplay) {
            continue;
        }
        $candidate = trim((string)($row['callsign_display'] ?? $row['callsign'] ?? ''));
        if ($candidate !== '' && $candidate !== '--') {
            $lastHeard = $candidate;
            break;
        }
    }

    $signal = $connected ? max(time(), $lastSignal, (int)($targetInfo['epoch'] ?? 0)) : $lastSignal;

    return [
        'adapter' => 'nxdn',
        'provider' => $connected ? 'NXDN' : 'Idle',
        'network' => $connected ? 'NXDN' : 'Idle',
        'connection_state' => $connected ? 'Connected' : 'Idle',
        'path_label' => $connected ? 'NXDN' : 'Idle',
        'target_display' => $targetDisplay,
        'target_note' => $connected ? '(from live Analog Bridge NXDN session)' : '(no active NXDN session detected)',
        'last_heard' => $connected ? $lastHeard : '--',
        'rows' => array_slice($rows, 0, 60),
        'left_label' => 'Last Heard',
        'left_value' => $connected ? $lastHeard : '--',
        'signal_epoch' => $signal,
    ];
}
?>
