<?php
declare(strict_types=1);

function dc_adapter_bm_stock(array $analogLines, array $abinfo, array $services, array $cache, string $tzName): array {
    $rows = [];
    $currentTarget = '';
    $lastHeard = '--';
    $lastSignal = 0;
    $lastIdx = null;
    $analogMode = '';

    $liveMode = strtoupper((string)($abinfo['tlv']['ambe_mode'] ?? ''));
    $gateway = trim((string)($abinfo['digital']['gw'] ?? ''));
    $runtimeTg = trim((string)($abinfo['_runtime']['latest_txtg']['value'] ?? ''));
    $runtimeTgEpoch = (int)($abinfo['_runtime']['latest_txtg']['epoch'] ?? 0);
    $runtimeTgDisconnect = (bool)($abinfo['_runtime']['latest_txtg']['disconnect'] ?? false);

    // Only operate on the stock MMDVM_Bridge DMR lane.
    // BMTD and TGIFD also leave Analog_Bridge in DMR/TLV mode, but those direct
    // TLV paths are owned by their dedicated adapters when MMDVM_Bridge is inactive.
    if ($liveMode !== 'DMR' || (($services['mmdvm_bridge'] ?? 'inactive') !== 'active')) {
        return dc_idle_adapter('BrandMeister');
    }

    foreach ($analogLines as $line) {
        $stamp = dc_parse_log_dt($line, $tzName);
        $epoch = (int)($stamp['epoch'] ?? 0);

        if (preg_match('/Setting mode to\s+([A-Z0-9_]+)/i', $line, $modeMatch)) {
            $analogMode = strtoupper(trim($modeMatch[1]));
        } elseif (preg_match('/ambeMode\s*=\s*([A-Z0-9_]+)/i', $line, $modeMatch)) {
            $analogMode = strtoupper(trim($modeMatch[1]));
        }

        $isDmrAnalogLine = ($analogMode === '' && $liveMode === 'DMR') || $analogMode === 'DMR';

        if (!$isDmrAnalogLine) {
            continue;
        }

        if (preg_match('/EVENT:\s+\{"topic":"dvswitch\/MMDVM_Bridge\/DMR","message":"login success"\}/', $line)) {
            $lastSignal = max($lastSignal, $epoch);
            continue;
        }

        if (preg_match('/\btxTg\s*=?\s*:?\s*([0-9#]+)/i', $line, $m)) {
            $raw = trim($m[1]);
            $tg = rtrim($raw, '#');
            if (str_ends_with($raw, '#') || $tg === '0') {
                $currentTarget = $tg;
            } elseif ($tg !== '') {
                $currentTarget = $tg;
            }
            $lastSignal = max($lastSignal, $epoch);
            continue;
        }

        if (preg_match('/Begin TX:\s*src=([0-9]+)\s+rpt=([0-9]+)\s+dst=([0-9]+)\s+slot=([0-9]+)\s+cc=([0-9]+)\s+call=([A-Z0-9]*)/i', $line, $m)) {
            $src = trim($m[1]);
            $call = trim($m[6]) !== '' ? trim($m[6]) : $src;
            $dst = trim($m[3]);
            $targetNum = $currentTarget !== '' ? $currentTarget : $dst;
            if ($targetNum === '0') {
                continue;
            }

            // Plain BM stock should not claim the standby/default gateway TG.
            if ($gateway !== '' && $targetNum === $gateway) {
                continue;
            }

            if ($currentTarget === '' && preg_match('/^[0-9]+$/', $targetNum) === 1 && $targetNum !== '0') {
                $currentTarget = $targetNum;
            }

            $rows[] = dc_make_row(
                (string)($stamp['utc'] ?? ''),
                (string)($stamp['display'] ?? '--'),
                'DMR/BM',
                $call,
                'TG ' . $targetNum,
                'Net'
            );
            $lastIdx = count($rows) - 1;
            $lastHeard = $call;
            $lastSignal = max($lastSignal, $epoch);
            continue;
        }

        if (preg_match('/PTT off \(keyed for ([0-9]+) ms\)/i', $line, $m)) {
            if ($lastIdx !== null && isset($rows[$lastIdx])) {
                $rows[$lastIdx]['dur'] = number_format(((int)$m[1]) / 1000, 2, '.', '');
            }
            $lastSignal = max($lastSignal, $epoch);
        }
    }

    if ($currentTarget === '' && !$runtimeTgDisconnect && $runtimeTg !== '' && $runtimeTg !== '0') {
        $currentTarget = $runtimeTg;
    }
    if ($currentTarget === '' && isset($cache['bm_stock']['target_display'])) {
        $cachedTarget = preg_replace('/^TG\s+/', '', trim((string)$cache['bm_stock']['target_display']));
        $cachedSignal = (int)($cache['bm_stock']['signal_epoch'] ?? 0);
        if (
            preg_match('/^[0-9]+$/', $cachedTarget) === 1 &&
            $cachedTarget !== '0' &&
            ($gateway === '' || $cachedTarget !== $gateway) &&
            $cachedSignal > 0 &&
            (time() - $cachedSignal) < 180
        ) {
            $currentTarget = $cachedTarget;
        }
    }

    $hasNumericTarget = preg_match('/^[0-9]+$/', $currentTarget) === 1;
    $targetIsStandbyGateway = ($gateway !== '' && $hasNumericTarget && $currentTarget === $gateway);
    $hasRealBmTarget = $hasNumericTarget && $currentTarget !== '0' && !$targetIsStandbyGateway;
    $recentRuntimeTg = $runtimeTg !== '' && ($runtimeTgEpoch > 0) && ((time() - $runtimeTgEpoch) < 120);

    $state = 'Idle';
    if ($hasRealBmTarget) {
        $state = 'Connected';
    }

    // If the current live tg is the standby gateway tg, force Idle and clear stale BM ownership.
    if ($targetIsStandbyGateway || $runtimeTgDisconnect || ($recentRuntimeTg && $gateway !== '' && $runtimeTg === $gateway)) {
        $state = 'Idle';
    }

    usort($rows, fn($a,$b) => strcmp((string)($b['utc'] ?? ''), (string)($a['utc'] ?? '')));

    return [
        'adapter' => 'bm_stock',
        'provider' => 'BrandMeister',
        'network' => 'BrandMeister',
        'connection_state' => $state,
        'path_label' => $state === 'Connected' ? 'DMR' : 'Idle',
        'target_display' => $hasRealBmTarget ? ('TG ' . $currentTarget) : '--',
        'target_note' => '(from stock DMR / Analog_Bridge)',
        'last_heard' => $lastHeard !== '--' ? $lastHeard : (string)($cache['bm_stock']['last_heard'] ?? '--'),
        'rows' => array_slice($rows, 0, 40),
        'left_label' => 'Current TG',
        'left_value' => $hasRealBmTarget ? ('TG ' . $currentTarget) : '--',
        'signal_epoch' => $lastSignal,
    ];
}
?>
