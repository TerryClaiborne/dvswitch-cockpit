<?php
declare(strict_types=1);

function dc_dstar_clean_reflector(string $value): string {
    $value = trim(preg_replace('/\s+/', ' ', $value));
    return $value !== '' ? $value : '--';
}


function dc_dstar_local_calls(array $abinfo): array {
    $calls = [];
    $candidates = [
        $abinfo['call'] ?? '',
        $abinfo['digital']['call'] ?? '',
        $abinfo['digital']['gw'] ?? '',
        $abinfo['info']['call'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $candidate = strtoupper(trim((string)$candidate));
        if ($candidate === '') continue;
        foreach (preg_split('/[^A-Z0-9]+/', $candidate) as $part) {
            $part = strtoupper(trim($part));
            if ($part !== '' && dc_is_callsign_like($part)) {
                $calls[$part] = true;
            }
        }
    }

    return array_keys($calls);
}

function dc_dstar_is_local_call(string $call, array $localCalls): bool {
    $call = strtoupper(trim(explode('/', $call)[0]));
    if ($call === '') return false;
    return in_array($call, $localCalls, true);
}

function dc_adapter_dstar(array $bridgeLines, array $abinfo, array $cache, string $tzName): array {
    $rows = [];
    $currentTarget = '';
    $state = 'Idle';
    $lastSignal = 0;
    $lastHeard = '--';
    $lastGatewayIdx = null;
    $lastLocalIdx = null;
    $hasDisconnect = false;
    $localCalls = dc_dstar_local_calls($abinfo);
    $pendingDstarEot = '';

    foreach ($bridgeLines as $line) {
        $stamp = dc_parse_log_dt($line, $tzName);

        if (preg_match('/D-Star,\s+Remote CMD:\s+txTg=(.+)$/i', $line, $m)) {
            $cmd = trim($m[1]);
            if ($cmd === '4000#' || strcasecmp($cmd, 'disconnect') === 0) {
                $hasDisconnect = true;
                $currentTarget = '';
            } else {
                $currentTarget = dc_dstar_clean_reflector($cmd);
                $hasDisconnect = false;
            }
            $lastSignal = max($lastSignal, (int)$stamp['epoch']);
            continue;
        }

        if (preg_match('/D-Star link status set to "([^"]+)"/i', $line, $m)) {
            $status = trim($m[1]);
            if (stripos($status, 'Not linked') !== false) {
                $hasDisconnect = true;
                $currentTarget = '';
            } elseif (preg_match('/Linked to\s+(.+)$/i', $status, $lm)) {
                $currentTarget = dc_dstar_clean_reflector($lm[1]);
                $hasDisconnect = false;
            } elseif (preg_match('/Linking to\s+(.+)$/i', $status, $lm)) {
                $target = dc_dstar_clean_reflector($lm[1]);
                if ($target !== '4000#') {
                    $currentTarget = $target;
                }
            }
            $lastSignal = max($lastSignal, (int)$stamp['epoch']);
            continue;
        }

        if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+D-Star,\s+received network header from\s+(.+?)\s+to\s+(.+?)(?:\s+via\s+(.+))?$/i', $line, $m)) {
            $rawCall = trim($m[1]);
            $call = trim(explode('/', $rawCall)[0]);
            $to = dc_dstar_clean_reflector($m[2] ?? '');
            $via = dc_dstar_clean_reflector($m[3] ?? '');
            $target = ($via !== '--') ? $via : (($currentTarget !== '') ? $currentTarget : $to);

            // MMDVM_Bridge can report the local user's D-Star audio back as a
            // network header after the local key-up. Keep that event in Local
            // Activity only so the same KC3KMV transmission does not appear as
            // duplicate Gateway Activity.
            if (dc_dstar_is_local_call($call, $localCalls)) {
                $pendingDstarEot = 'local_self';
                $lastHeard = $call;
                $lastSignal = max($lastSignal, (int)$stamp['epoch']);
                continue;
            }

            $pendingDstarEot = 'gateway';
            $rows[] = dc_make_row($stamp['utc'], $stamp['display'], 'D-Star', $call, $target, 'Net');
            $lastGatewayIdx = count($rows) - 1;
            $lastHeard = $call;
            $lastSignal = max($lastSignal, (int)$stamp['epoch']);
            continue;
        }

        if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+D-Star,\s+received network end of transmission,\s+([0-9.]+)\s+seconds,\s+([0-9.]+%)\s+packet loss,\s+BER:\s+([0-9.]+%)/i', $line, $m)) {
            if ($pendingDstarEot === 'gateway' && $lastGatewayIdx !== null && isset($rows[$lastGatewayIdx])) {
                $rows[$lastGatewayIdx]['dur'] = $m[1];
                $rows[$lastGatewayIdx]['loss'] = $m[2];
                $rows[$lastGatewayIdx]['ber'] = $m[3];
            } elseif ($pendingDstarEot === 'local_self' && $lastLocalIdx !== null && isset($rows[$lastLocalIdx])) {
                $rows[$lastLocalIdx]['dur'] = $m[1];
                $rows[$lastLocalIdx]['loss'] = $m[2];
                $rows[$lastLocalIdx]['ber'] = $m[3];
            }
            $pendingDstarEot = '';
            $lastSignal = max($lastSignal, (int)$stamp['epoch']);
            continue;
        }

        if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+D-Star,\s+Begin TX: .*metadata=([A-Za-z0-9\-\/_ ]*)/i', $line, $m)) {
            $call = trim($m[1]) !== '' ? trim($m[1]) : (string)($abinfo['call'] ?? 'Local');
            $target = $currentTarget !== '' ? $currentTarget : (($cache['dstar']['target_display'] ?? '') ?: 'D-Star');

            $rows[] = dc_make_row($stamp['utc'], $stamp['display'], 'D-Star', $call, $target, 'LNet');
            $lastLocalIdx = count($rows) - 1;
            $lastHeard = $call;
            $lastSignal = max($lastSignal, (int)$stamp['epoch']);
            continue;
        }
    }

    $tlvMode = strtoupper((string)($abinfo['tlv']['ambe_mode'] ?? ''));
    $isDstarMode = ($tlvMode === 'DSTAR');

    if ($currentTarget === '' && isset($cache['dstar']['target_display'])) {
        $currentTarget = (string)$cache['dstar']['target_display'];
    }

    $hasRecentTraffic = $lastSignal > 0 && ((time() - $lastSignal) < 240);

    if ($isDstarMode && !$hasDisconnect && ($hasRecentTraffic || $currentTarget !== '')) {
        $state = 'Connected';
    } else {
        $state = 'Idle';
    }

    usort($rows, fn($a,$b) => strcmp((string)$b['utc'], (string)$a['utc']));

    return [
        'adapter' => 'dstar',
        'provider' => 'D-Star',
        'network' => 'D-Star',
        'connection_state' => $state,
        'path_label' => $state === 'Connected' ? 'D-Star' : 'Idle',
        'target_display' => $currentTarget !== '' ? $currentTarget : '--',
        'target_note' => '(from D-Star / MMDVM_Bridge)',
        'last_heard' => $lastHeard !== '--' ? $lastHeard : (string)($cache['dstar']['last_heard'] ?? '--'),
        'rows' => array_slice($rows, 0, 40),
        'left_label' => 'Last Heard',
        'left_value' => $lastHeard !== '--' ? $lastHeard : (string)($cache['dstar']['last_heard'] ?? '--'),
        'signal_epoch' => $lastSignal,
    ];
}
