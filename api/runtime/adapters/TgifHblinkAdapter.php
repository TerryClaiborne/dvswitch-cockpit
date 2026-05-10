<?php
declare(strict_types=1);

function dc_tgif_digits(string $value): string {
    return preg_replace('/\D+/', '', $value) ?? '';
}

function dc_tgif_clean_station(string $value): string {
    $value = strtoupper(trim($value));
    if ($value === '' || $value === 'NONE' || $value === 'UNKNOWN') return '';
    return $value;
}

function dc_tgif_station_from_mmdvm(string $raw, string $target): array {
    $raw = dc_tgif_clean_station($raw);
    $digits = dc_tgif_digits($raw);
    $targetDigits = dc_tgif_digits($target);

    if ($raw === '') {
        return ['TGIF RX', 'network_rx_only'];
    }

    if ($targetDigits !== '' && $digits !== '' && $digits === $targetDigits) {
        return ['TGIF Parrot', 'tgif_parrot'];
    }

    if (dc_is_callsign_like($raw)) {
        return [$raw, 'mmdvm_network_header'];
    }

    if ($digits !== '') {
        return [$digits, 'mmdvm_numeric_id'];
    }

    return ['TGIF RX', 'network_rx_only'];
}

function dc_tgif_active_mmdvm_path(): array {
    $path = '/opt/MMDVM_Bridge/MMDVM_Bridge.ini';
    $out = ['mode' => 'unknown', 'address' => '', 'port' => ''];

    if (!is_readable($path)) return $out;

    $section = '';
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim(preg_replace('/[;#].*$/', '', (string)$line));
        if ($line === '') continue;

        if (preg_match('/^\[(.+)\]$/', $line, $m)) {
            $section = strtolower(trim($m[1]));
            continue;
        }

        if ($section !== 'dmr network') continue;

        if (preg_match('/^Address\s*=\s*(.+)$/i', $line, $m)) {
            $out['address'] = strtolower(trim($m[1]));
        } elseif (preg_match('/^Port\s*=\s*(.+)$/i', $line, $m)) {
            $out['port'] = trim($m[1]);
        }
    }

    if (in_array($out['address'], ['127.0.0.1', 'localhost'], true) && $out['port'] === '62033') {
        $out['mode'] = 'hblink';
    } elseif (str_contains($out['address'], 'tgif.network')) {
        $out['mode'] = 'direct_tgif';
    }

    return $out;
}

function dc_tgif_find_session_start(array $analogLines, string $tzName): int {
    $lastDmrStart = 0;
    $lastMode = '';

    foreach ($analogLines as $line) {
        $stamp = dc_parse_log_dt($line, $tzName);
        $epoch = (int)($stamp['epoch'] ?? 0);

        if (preg_match('/MESSAGE packet sent to USRP client:\s+Setting mode to\s+([A-Z0-9_-]+)/i', $line, $m)) {
            $lastMode = strtoupper(trim($m[1]));
            if ($lastMode === 'DMR') {
                $lastDmrStart = max($lastDmrStart, $epoch);
            }
            continue;
        }

        if (preg_match('/\bambeMode\s*=\s*([A-Z0-9_-]+)/i', $line, $m)) {
            $lastMode = strtoupper(trim($m[1]));
            if ($lastMode === 'DMR') {
                $lastDmrStart = max($lastDmrStart, $epoch);
            }
            continue;
        }

        if (preg_match('/EVENT:\s+\{"topic":"dvswitch\/MMDVM_Bridge\/DMR","message":"login success"\}/', $line)) {
            $lastDmrStart = max($lastDmrStart, $epoch);
            continue;
        }
    }

    return $lastDmrStart;
}


function dc_tgif_activity_stamp_from_ts($ts, string $tzName): array {
    try {
        $epoch = (int)floor((float)$ts);
        $utcDt = (new DateTimeImmutable('@' . $epoch))->setTimezone(new DateTimeZone('UTC'));
        $localDt = $utcDt->setTimezone(new DateTimeZone($tzName));
        return [
            'epoch' => $epoch,
            'utc' => $utcDt->format('Y-m-d H:i:s'),
            'display' => $localDt->format('H:i:s M d'),
        ];
    } catch (Throwable $e) {
        return ['epoch' => 0, 'utc' => '', 'display' => '--'];
    }
}

function dc_tgif_activity_station(array $event, string $target): array {
    $alias = trim((string)($event['src_alias'] ?? ''));
    $srcId = trim((string)($event['src_id'] ?? ''));
    $targetDigits = dc_tgif_digits($target);
    $srcDigits = dc_tgif_digits($srcId !== '' ? $srcId : $alias);

    if ($targetDigits !== '' && $srcDigits !== '' && $targetDigits === $srcDigits) {
        return ['TGIF Parrot', 'alltune2_tgif_activity_parrot'];
    }

    if ($alias !== '' && !ctype_digit($alias) && strtoupper($alias) !== 'NONE') {
        return [$alias, 'alltune2_tgif_activity_feed'];
    }

    if ($srcId !== '') {
        return [$srcId, 'alltune2_tgif_activity_feed_id'];
    }

    return ['TGIF RX', 'alltune2_tgif_activity_unknown'];
}

function dc_tgif_activity_feed_rows(string $path, string $target, string $tzName, int $sessionStart): array {
    if (!is_readable($path)) {
        return [];
    }

    $mtime = @filemtime($path);
    if (!$mtime || (time() - $mtime) > 900) {
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || count($lines) === 0) {
        return [];
    }

    $lines = array_slice($lines, -240);
    $starts = [];
    $ends = [];

    $targetDigits = dc_tgif_digits($target);

    foreach ($lines as $line) {
        $event = json_decode($line, true);
        if (!is_array($event)) {
            continue;
        }

        $eventName = strtolower((string)($event['event'] ?? ''));
        $streamId = (string)($event['stream_id'] ?? '');
        if ($streamId === '' || ($eventName !== 'start' && $eventName !== 'end')) {
            continue;
        }

        $eventTarget = dc_tgif_digits((string)($event['dst_tg'] ?? ''));
        if ($targetDigits !== '' && $eventTarget !== '' && $eventTarget !== $targetDigits) {
            continue;
        }

        $stamp = dc_tgif_activity_stamp_from_ts($event['ts'] ?? 0, $tzName);
        if ($stamp['epoch'] <= 0) {
            continue;
        }

        if ($sessionStart > 0 && $stamp['epoch'] < $sessionStart) {
            continue;
        }

        $event['_stamp'] = $stamp;

        if ($eventName === 'start') {
            $starts[$streamId] = $event;
        } else {
            $ends[$streamId] = $event;
        }
    }

    $rows = [];

    foreach ($starts as $streamId => $start) {
        $end = $ends[$streamId] ?? null;
        $stamp = $start['_stamp'];
        $eventTarget = dc_tgif_digits((string)($start['dst_tg'] ?? $target));
        if ($eventTarget === '') {
            $eventTarget = $targetDigits;
        }

        [$station, $confidence] = dc_tgif_activity_station($start, $eventTarget);
        $dur = is_array($end) && isset($end['duration'])
            ? (string)$end['duration']
            : 'RX';

        $row = dc_make_row(
            (string)$stamp['utc'],
            (string)$stamp['display'],
            'DMR/TGIF',
            $station,
            'TG ' . $eventTarget,
            'Net',
            $dur
        );

        $row['identity_confidence'] = $confidence;
        $row['tgif_stream_id'] = $streamId;
        $row['_feed_epoch'] = $stamp['epoch'];

        if ($station === 'TGIF RX' || $station === 'TGIF Parrot') {
            $row['callsign_display'] = $station;
            unset($row['dmr_id'], $row['qrz_url'], $row['callsign_lookup']);
        }

        $rows[] = $row;
    }

    usort($rows, fn($a, $b) => (int)($b['_feed_epoch'] ?? 0) <=> (int)($a['_feed_epoch'] ?? 0));

    return array_slice($rows, 0, 40);
}


function dc_adapter_tgif_hblink(array $analogLines, array $bridgeLines, array $abinfo, array $services, string $tzName): array {
    $rows = [];
    $lastHeard = '--';
    $lastSignal = 0;
    $lastConnectSignal = 0;
    $lastDisconnectSignal = 0;

    $liveMode = strtoupper((string)($abinfo['tlv']['ambe_mode'] ?? ''));
    $gateway = trim((string)($abinfo['digital']['gw'] ?? ''));
    $localCall = trim((string)($abinfo['digital']['call'] ?? ''));

    $privateLink = is_array($abinfo['_runtime']['private_audio_link'] ?? null)
        ? $abinfo['_runtime']['private_audio_link']
        : [];
    $privateLinkKnown = !empty($privateLink['known']) && trim((string)($privateLink['dvswitch_node'] ?? '')) !== '';
    $privateLinkActive = !empty($privateLink['linked']);
    $privateLinkNode = trim((string)($privateLink['dvswitch_node'] ?? ''));

    $configuredTarget = trim((string)($abinfo['_runtime']['tgif_hblink_target']['value'] ?? ''));
    $configuredSource = trim((string)($abinfo['_runtime']['tgif_hblink_target']['source'] ?? ''));

    $activeMmdvmPath = dc_tgif_active_mmdvm_path();
    $useHblinkConfig = ($activeMmdvmPath['mode'] === 'hblink');

    $hblinkActive = in_array(($services['hblink'] ?? ''), ['active', 'activating'], true)
        || !empty($services['hblink_process']);

    $target = '';
    $targetSource = '';

    if ($useHblinkConfig && $configuredTarget !== '' && $configuredTarget !== '0' && ($gateway === '' || $configuredTarget !== $gateway)) {
        $target = $configuredTarget;
        $targetSource = 'hblink_config';
    }

    $latestTxTg = (string)($abinfo['_runtime']['latest_txtg']['value'] ?? '');
    $latestTxTgEpoch = (int)($abinfo['_runtime']['latest_txtg']['epoch'] ?? 0);
    $latestTxTgDisconnect = (bool)($abinfo['_runtime']['latest_txtg']['disconnect'] ?? false);

    if ($latestTxTgDisconnect && $latestTxTgEpoch > 0) {
        $lastDisconnectSignal = max($lastDisconnectSignal, $latestTxTgEpoch);
    }

    if ($target === '' && !$latestTxTgDisconnect && $latestTxTg !== '' && $latestTxTg !== '0' && ($gateway === '' || $latestTxTg !== $gateway)) {
        $target = $latestTxTg;
        $targetSource = 'latest_txtg';
        $lastConnectSignal = max($lastConnectSignal, $latestTxTgEpoch);
    }

    $hasTarget = $target !== '' && $target !== '0' && ($gateway === '' || $target !== $gateway);
    $disconnectWins = $lastDisconnectSignal > 0 && $lastDisconnectSignal >= $lastConnectSignal;

    $state = 'Idle';
    if ($liveMode === 'DMR' && $hasTarget && !$disconnectWins) {
        if ($privateLinkKnown) {
            if ($privateLinkActive) {
                $state = 'Connected';
            }
        } elseif ($hblinkActive || $useHblinkConfig) {
            $state = 'Connected';
        }
    }

    if ($state !== 'Connected') {
        return dc_idle_adapter('TGIF');
    }

    $sessionStart = dc_tgif_find_session_start($analogLines, $tzName);

    $localStation = function () use ($gateway, $localCall): string {
        if ($localCall !== '') return $localCall;
        if ($gateway !== '') return $gateway;
        return 'Local';
    };

    // Local activity comes from Analog_Bridge PTT timing, but only for the
    // current DMR/TGIF session. Older Analog_Bridge rows must not be rewritten
    // to the current StartRef.
    $modeContext = ($liveMode === 'DMR') ? 'DMR' : '';
    $pttOnEpoch = 0;

    foreach ($analogLines as $line) {
        $stamp = dc_parse_log_dt($line, $tzName);
        $epoch = (int)($stamp['epoch'] ?? 0);

        if ($sessionStart > 0 && $epoch > 0 && $epoch < $sessionStart) {
            continue;
        }

        if (preg_match('/MESSAGE packet sent to USRP client:\s+Setting mode to\s+([A-Z0-9_-]+)/i', $line, $m)) {
            $modeContext = strtoupper(trim($m[1]));
            continue;
        }

        if (preg_match('/\bambeMode\s*=\s*([A-Z0-9_-]+)/i', $line, $m)) {
            $modeContext = strtoupper(trim($m[1]));
            continue;
        }

        if (preg_match('/EVENT:\s+\{"topic":"dvswitch\/MMDVM_Bridge\/DMR","message":"login success"\}/', $line)) {
            $lastConnectSignal = max($lastConnectSignal, $epoch);
            $lastSignal = max($lastSignal, $epoch);
            continue;
        }

        if (preg_match('/\btxTg\s*=?\s*:?\s*([0-9#]+)/i', $line, $m)) {
            if ($modeContext !== '' && $modeContext !== 'DMR') {
                continue;
            }

            $raw = trim($m[1]);
            $tg = rtrim($raw, '#');

            if (str_ends_with($raw, '#') || $tg === '' || $tg === '0') {
                $lastDisconnectSignal = max($lastDisconnectSignal, $epoch);
                continue;
            }

            $lastConnectSignal = max($lastConnectSignal, $epoch);
            $lastSignal = max($lastSignal, $epoch);
            continue;
        }

        if (preg_match('/PTT on/i', $line)) {
            if ($modeContext === 'DMR') {
                $pttOnEpoch = $epoch;
            }
            continue;
        }

        if (preg_match('/PTT off \(keyed for ([0-9]+) ms\)/i', $line, $m)) {
            if ($modeContext !== 'DMR') {
                continue;
            }

            $dur = number_format(((int)$m[1]) / 1000, 2, '.', '');

            $rows[] = dc_make_row(
                (string)($stamp['utc'] ?? ''),
                (string)($stamp['display'] ?? '--'),
                'DMR/TGIF',
                $localStation(),
                'TG ' . $target,
                'LNet',
                $dur
            );

            $lastSignal = max($lastSignal, $epoch);
            $pttOnEpoch = 0;
            continue;
        }
    }

    $activityFeedRows = dc_tgif_activity_feed_rows('/var/www/html/alltune2/logs/tgif-activity.jsonl', $target, $tzName, $sessionStart);

    if (!empty($activityFeedRows)) {
        foreach ($activityFeedRows as $activityRow) {
            $rows[] = $activityRow;
            $feedEpoch = (int)($activityRow['_feed_epoch'] ?? 0);
            if ($feedEpoch >= $lastSignal) {
                $lastSignal = $feedEpoch;
                $lastHeard = (string)($activityRow['callsign_display'] ?? $activityRow['callsign'] ?? 'TGIF RX');
            }
        }
    } else {
        // Net activity fallback comes from MMDVM_Bridge DMR network headers, not from
        // Analog_Bridge Begin TX caller fields. However, on some TGIF/HBLink paths
        // even MMDVM_Bridge can expose a repeated upstream identity for many
        // different speakers. Track repeated identity and mask it rather than
        // falsely claiming every speaker is the same station.
        $lastNetIdx = null;
        $lastNetEpoch = 0;
        $tgifIdentityCounts = [];

        foreach ($bridgeLines as $line) {
        $stamp = dc_parse_log_dt($line, $tzName);
        $epoch = (int)($stamp['epoch'] ?? 0);

        if ($sessionStart > 0 && $epoch > 0 && $epoch < $sessionStart) {
            continue;
        }

        if (preg_match('/DMR Slot\s+[0-9]+,\s+received network (?:voice header|late entry) from\s+(.+?)\s+to TG\s+(.+)$/i', $line, $m)) {
            $rawStation = trim($m[1]);
            [$station, $confidence] = dc_tgif_station_from_mmdvm($rawStation, $target);

            $identityKey = strtoupper($rawStation);
            if ($identityKey !== '') {
                $tgifIdentityCounts[$identityKey] = ($tgifIdentityCounts[$identityKey] ?? 0) + 1;
            }

            // If the same non-parrot TGIF identity repeats over and over in one
            // session, the upstream path is likely exposing a stuck/representative
            // caller, not the true speaker. Do not lie by showing that callsign
            // for every transmission.
            if (
                $station !== 'TGIF RX' &&
                $station !== 'TGIF Parrot' &&
                $identityKey !== '' &&
                ($tgifIdentityCounts[$identityKey] ?? 0) >= 4
            ) {
                $station = 'TGIF RX';
                $confidence = 'masked_repeated_tgif_identity';
            }

            $row = dc_make_row(
                (string)($stamp['utc'] ?? ''),
                (string)($stamp['display'] ?? '--'),
                'DMR/TGIF',
                $station,
                'TG ' . $target,
                'Net',
                'RX'
            );
            $row['identity_confidence'] = $confidence;
            if ($station === 'TGIF RX' || $station === 'TGIF Parrot') {
                $row['callsign_display'] = $station;
                unset($row['dmr_id'], $row['qrz_url'], $row['callsign_lookup']);
            }

            $rows[] = $row;
            $lastNetIdx = count($rows) - 1;
            $lastNetEpoch = $epoch;
            $lastHeard = $station;
            $lastSignal = max($lastSignal, $epoch);
            continue;
        }

        if (preg_match('/DMR Slot\s+[0-9]+,\s+received network end of voice transmission,\s+([0-9.]+)\s+seconds,\s+([0-9.]+%)\s+packet loss,\s+BER:\s+([0-9.]+%)/i', $line, $m)) {
            if ($lastNetIdx !== null && isset($rows[$lastNetIdx]) && $lastNetEpoch > 0 && ($epoch - $lastNetEpoch) <= 20) {
                $rows[$lastNetIdx]['dur'] = $m[1];
                $rows[$lastNetIdx]['loss'] = $m[2];
                $rows[$lastNetIdx]['ber'] = $m[3];
                $lastSignal = max($lastSignal, $epoch);
            }
            continue;
        }
    }

    }

    usort($rows, fn($a,$b) => strcmp((string)($b['utc'] ?? ''), (string)($a['utc'] ?? '')));

    $displayTarget = 'TG ' . $target;

    $note = '(from TGIF/HBLink current session; Net caller from MMDVM_Bridge, Local activity from Analog_Bridge PTT)';
    if ($privateLinkKnown && $privateLinkActive && $targetSource === 'hblink_config') {
        $note = '(from TGIF/HBLink StartRef in hblink.cfg; private audio node linked; Net caller from MMDVM_Bridge)';
    } elseif ($privateLinkKnown && !$privateLinkActive) {
        $note = '(TGIF idle; private DVSwitch audio node ' . $privateLinkNode . ' is not linked)';
    }

    return [
        'adapter' => 'tgif_hblink',
        'provider' => 'TGIF/HBLink',
        'network' => 'TGIF',
        'connection_state' => 'Connected',
        'path_label' => 'HBLink + DMR',
        'target_display' => $displayTarget,
        'target_note' => $note,
        'last_heard' => $lastHeard,
        'rows' => array_slice($rows, 0, 40),
        'left_label' => 'Current TG',
        'left_value' => $displayTarget,
        'signal_epoch' => $lastSignal,
    ];
}
