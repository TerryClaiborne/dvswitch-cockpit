<?php
declare(strict_types=1);

function dc_adapter_bm_stfu(array $stfuLines, array $abinfo, array $cache, string $tzName): array {
    $tlvMode = strtoupper((string)($abinfo['tlv']['ambe_mode'] ?? ''));
    $gateway = trim((string)($abinfo['digital']['gw'] ?? ''));
    $localCall = trim((string)($abinfo['digital']['call'] ?? ''));
    $abTg = preg_replace('/[^0-9]/', '', trim((string)($abinfo['digital']['tg'] ?? ''))) ?? '';
    $lastTune = preg_replace('/[^0-9]/', '', trim((string)($abinfo['last_tune'] ?? ''))) ?? '';

    // If live mode is not STFU, this adapter must not own the session.
    if ($tlvMode !== 'STFU') {
        return dc_idle_adapter('BrandMeister');
    }

    $rows = [];
    $currentTarget = '';
    $lastHeard = '--';
    $lastSignal = 0;
    $openIdx = null;
    $targetCandidates = [];

    $rememberTarget = function (string $target, string $source = '', bool $allowGatewayTarget = false) use (&$targetCandidates, $gateway): void {
        $target = preg_replace('/[^0-9]/', '', trim($target)) ?? '';
        if ($target === '' || $target === '0') return;

        // In STFU private-call traffic frames the local gateway/user ID can appear
        // as dst. That dst is not proof of the BM talkgroup. However, the same
        // number can also be a valid commanded BM target when it comes from a
        // trusted selection source such as ABInfo digital.tg, last_tune, or
        // Remote CMD: txTg=<number>#. Do not throw that away.
        if (!$allowGatewayTarget && $gateway !== '' && $target === $gateway) return;

        $targetCandidates[] = ['target' => $target, 'source' => $source];
    };

    $selectedTargetNow = function () use (&$currentTarget, &$targetCandidates): string {
        if ($currentTarget !== '') return $currentTarget;
        if (!empty($targetCandidates)) {
            return (string)($targetCandidates[count($targetCandidates) - 1]['target'] ?? '');
        }
        return '';
    };

    $localStation = function () use ($gateway, $localCall): string {
        // Prefer station callsign for explicit local TX rows. Falling back to the
        // gateway DMR ID is still useful because subscriber lookup may resolve it.
        if ($localCall !== '') return $localCall;
        if ($gateway !== '') return $gateway;
        return 'Local';
    };

    $rememberTarget($abTg, 'ABInfo digital.tg', true);
    $rememberTarget($lastTune, 'ABInfo last_tune', true);

    // Track event ordering instead of just "did a disconnect exist somewhere in the tail?"
    $lastConnectEpoch = 0;
    $lastDisconnectEpoch = 0;
    $sawLogin = false;

    foreach ($stfuLines as $line) {
        $stamp = dc_parse_log_dt($line, $tzName);
        $epoch = (int)($stamp['epoch'] ?? 0);

        if (preg_match('/DMR,\s+Remote CMD:\s+txTg=([0-9]+)#?/i', $line, $m)) {
            $cmdTarget = trim($m[1]);

            // STFU remote commands commonly end with '#'.  That trailing '#'
            // is the command terminator, not a disconnect marker.  A real
            // disconnect is txTg=0/0# or a later STFU exit signal.
            if ($cmdTarget === '0') {
                $currentTarget = '';
                $lastDisconnectEpoch = max($lastDisconnectEpoch, $epoch);
            } else {
                $rememberTarget($cmdTarget, 'STFU Remote CMD', true);
                $currentTarget = $cmdTarget;
                $lastConnectEpoch = max($lastConnectEpoch, $epoch);
            }

            $lastSignal = max($lastSignal, $epoch);
            continue;
        }

        if (preg_match('/Successful connection to BM server|EVENT:\s+\{"topic":"dvswitch\/STFU\/DMR","message":"login success"\}/i', $line)) {
            $sawLogin = true;
            $lastConnectEpoch = max($lastConnectEpoch, $epoch);
            $lastSignal = max($lastSignal, $epoch);
            continue;
        }

        if (preg_match('/DMR,\s+ODMR Begin Tx:\s+src\s*=\s*([0-9]+),\s*dst\s*=\s*([0-9]+)\s+\((GROUP|PRIVATE)\)/i', $line, $m)) {
            $src = trim($m[1]);
            $dst = trim($m[2]);
            // If STFU sends PRIVATE traffic to the local gateway ID, the dst is
            // the private gateway/user ID, not the displayed BM talkgroup. Prefer
            // the selected/current target; if none is known yet, use src only when
            // it is not the local gateway.
            $rowTargetNum = $currentTarget !== '' ? $currentTarget : (($gateway !== '' && $dst === $gateway && $src !== $gateway) ? $src : $dst);
            $rememberTarget($rowTargetNum, 'STFU traffic');
            $target = 'TG ' . $rowTargetNum;

            // STFU can log some bridged BM traffic as src=<selected TG>,
            // dst=<selected TG>. That is not proof of local TX, so do not
            // invent a local callsign/LNet. Also do not guess from Talker Alias. Show the
            // source value STFU actually reported so the row remains traceable.
            $isAmbiguousTgSource = ($src !== '' && $rowTargetNum !== '' && $src === $rowTargetNum);
            if ($isAmbiguousTgSource) {
                $rows[] = dc_make_row(
                    (string)($stamp['utc'] ?? ''),
                    (string)($stamp['display'] ?? '--'),
                    'DMR/BM',
                    $src,
                    $target,
                    'Net'
                );
                $openIdx = count($rows) - 1;
                $lastHeard = $src;
                $lastSignal = max($lastSignal, $epoch);
                $lastConnectEpoch = max($lastConnectEpoch, $epoch);
                continue;
            }

            $rowStation = $src;
            $rowSource = 'Net';

            $rows[] = dc_make_row(
                (string)($stamp['utc'] ?? ''),
                (string)($stamp['display'] ?? '--'),
                'DMR/BM',
                $rowStation,
                $target,
                $rowSource
            );
            $openIdx = count($rows) - 1;
            $lastHeard = $rowStation;
            $lastSignal = max($lastSignal, $epoch);
            // Real traffic means the live STFU session is up.
            $lastConnectEpoch = max($lastConnectEpoch, $epoch);
            continue;
        }

        if (preg_match('/DMR,\s+ODMR End Tx:DMR frame count was\s+([0-9]+)\s+frames/i', $line, $m)) {
            if ($openIdx !== null && isset($rows[$openIdx])) {
                $rows[$openIdx]['dur'] = dc_frame_count_to_seconds($m[1]);
                $openIdx = null;
            }
            // Do not invent a Local Activity row from an orphan end/frame-count
            // line. Without a matching begin line, STFU does not provide enough
            // evidence to classify the event as local.
            $lastSignal = max($lastSignal, $epoch);
            continue;
        }

        if (preg_match('/DMR,\s+TX state\s*=\s*OFF,\s*DMR frame count was\s+([0-9]+)\s+frames/i', $line, $m)) {
            // Newer/alternate STFU builds log local TX completion this way
            // instead of the older ODMR End Tx wording. Without this pattern,
            // local key-ups on TGs such as the configured local ID show no Dur(s) even though
            // STFU logged the frame count.
            $selected = $selectedTargetNow();
            if ($openIdx !== null && isset($rows[$openIdx])) {
                $rows[$openIdx]['dur'] = dc_frame_count_to_seconds($m[1]);
                $openIdx = null;
            } elseif ($selected !== '') {
                $rows[] = dc_make_row(
                    (string)($stamp['utc'] ?? ''),
                    (string)($stamp['display'] ?? '--'),
                    'DMR/BM',
                    $localStation(),
                    'TG ' . $selected,
                    'LNet',
                    dc_frame_count_to_seconds($m[1])
                );
                $lastHeard = $localStation();
                $lastConnectEpoch = max($lastConnectEpoch, $epoch);
            }
            $lastSignal = max($lastSignal, $epoch);
            continue;
        }
        if (preg_match('/Signal 15 received, exiting STFU/i', $line)) {
            $lastDisconnectEpoch = max($lastDisconnectEpoch, $epoch);
            $lastSignal = max($lastSignal, $epoch);
            continue;
        }
    }

    $bestTarget = '';
    if (!empty($targetCandidates)) {
        $bestTarget = (string)($targetCandidates[count($targetCandidates) - 1]['target'] ?? '');
    }

    if ($bestTarget !== '') {
        $currentTarget = $bestTarget;
    } elseif ($currentTarget !== '' && $gateway !== '' && $currentTarget === $gateway) {
        $currentTarget = '';
    }

    // Final state rule:
    // Connected only if the most recent meaningful STFU event is a connect/login/traffic event.
    // Any newer disconnect wins. Old disconnects no longer poison later connects.
    $state = 'Idle';
    if ($lastConnectEpoch > 0 && $lastConnectEpoch >= $lastDisconnectEpoch) {
        $state = 'Connected';
    } elseif ($sawLogin && $lastDisconnectEpoch === 0) {
        $state = 'Connected';
    }

    usort($rows, fn($a, $b) => strcmp((string)($b['utc'] ?? ''), (string)($a['utc'] ?? '')));

    return [
        'adapter' => 'bm_stfu',
        'provider' => 'BrandMeister',
        'network' => 'BrandMeister',
        'connection_state' => $state,
        'path_label' => $state === 'Connected' ? 'STFU' : 'Idle',
        'target_display' => $currentTarget !== '' ? ('TG ' . $currentTarget) : '--',
        'target_note' => '(from STFU.log)',
        'last_heard' => $lastHeard !== '--' ? $lastHeard : (string)($cache['bm_stfu']['last_heard'] ?? '--'),
        'rows' => array_slice($rows, 0, 40),
        'left_label' => 'Current TG',
        'left_value' => $currentTarget !== '' ? ('TG ' . $currentTarget) : '--',
        'signal_epoch' => max($lastSignal, $lastConnectEpoch),
    ];
}
?>