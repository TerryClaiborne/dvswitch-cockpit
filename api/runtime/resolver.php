<?php
declare(strict_types=1);

function dc_adapter_is_connected(array $adapter): bool {
    return (($adapter['connection_state'] ?? 'Idle') === 'Connected');
}

function dc_newer_adapter(array $a, array $b): array {
    $aEpoch = (int)($a['signal_epoch'] ?? 0);
    $bEpoch = (int)($b['signal_epoch'] ?? 0);
    return $aEpoch >= $bEpoch ? $a : $b;
}

function dc_resolver_row_epoch(array $row): int {
    $utc = trim((string)($row['utc'] ?? ''));
    if ($utc === '') return 0;

    try {
        return (new DateTime($utc, new DateTimeZone('UTC')))->getTimestamp();
    } catch (Throwable $e) {
        return 0;
    }
}

function dc_resolver_latest_row_for_mode(array $generic, string $mode): array {
    $mode = strtoupper($mode);
    $best = [];

    foreach (($generic['rows'] ?? []) as $row) {
        if (strtoupper((string)($row['mode'] ?? '')) !== $mode) {
            continue;
        }
        if (!$best || dc_resolver_row_epoch($row) > dc_resolver_row_epoch($best)) {
            $best = $row;
        }
    }

    return $best;
}

function dc_resolver_live_target(array $abinfo): string {
    $dig = $abinfo['digital'] ?? [];

    $tg = trim((string)($dig['tg'] ?? ''));
    if ($tg !== '' && $tg !== '0') {
        return 'TG ' . $tg;
    }

    $lastTune = trim((string)($abinfo['last_tune'] ?? ''));
    if ($lastTune !== '' && $lastTune !== '0') {
        return 'TG ' . $lastTune;
    }

    $runtime = $abinfo['_runtime']['latest_txtg'] ?? [];
    $value = trim((string)($runtime['value'] ?? ''));
    $disconnect = (bool)($runtime['disconnect'] ?? false);
    if ($value !== '' && $value !== '0' && !$disconnect) {
        return 'TG ' . $value;
    }

    return '--';
}

function dc_resolver_live_mode_adapter(array $abinfo, array $generic, string $liveMode): array {
    $mode = strtoupper($liveMode);
    if (!in_array($mode, ['P25', 'NXDN'], true)) {
        return dc_idle_adapter('Idle');
    }

    $target = dc_resolver_live_target($abinfo);
    $connected = $target !== '--';
    $latest = dc_resolver_latest_row_for_mode($generic, $mode);
    $lastHeard = trim((string)($latest['callsign_display'] ?? $latest['callsign'] ?? '--'));
    if ($lastHeard === '') $lastHeard = '--';

    return [
        'adapter' => 'live_ab_' . strtolower($mode),
        'provider' => $connected ? $mode : 'Idle',
        'network' => $connected ? $mode : 'Idle',
        'connection_state' => $connected ? 'Connected' : 'Idle',
        'path_label' => $connected ? $mode : 'Idle',
        'target_display' => $connected ? $target : '--',
        'target_note' => $connected
            ? '(from live Analog Bridge mode; activity history may include other networks)'
            : '(no active network detected)',
        'last_heard' => $connected ? $lastHeard : '--',
        'rows' => [],
        'left_label' => 'Last Heard',
        'left_value' => $connected ? $lastHeard : '--',
        'signal_epoch' => $connected ? max(time(), dc_resolver_row_epoch($latest)) : 0,
    ];
}

function dc_resolve_active(array $abinfo, array $adapters, array &$stateCache = []): array {
    $liveMode = strtoupper((string)($abinfo['tlv']['ambe_mode'] ?? ''));

    $bmStfu  = $adapters['bm_stfu']      ?? dc_idle_adapter('BrandMeister');
    $bmStock = $adapters['bm_stock']     ?? dc_idle_adapter('BrandMeister');
    $bmtd    = $adapters['bmtd']         ?? dc_idle_adapter('BrandMeister');
    $ysf     = $adapters['ysf']          ?? dc_idle_adapter('YSF');
    $dstar   = $adapters['dstar']        ?? dc_idle_adapter('D-Star');
    $tgifd   = $adapters['tgifd']        ?? dc_idle_adapter('TGIF');
    $tgif    = $adapters['tgif_hblink']   ?? dc_idle_adapter('TGIF');
    $p25     = $adapters['p25']          ?? dc_idle_adapter('P25');
    $nxdn    = $adapters['nxdn']         ?? dc_idle_adapter('NXDN');
    $generic = $adapters['generic']      ?? dc_idle_adapter('Idle');

    if ($liveMode !== 'STFU') {
        unset($stateCache['bm_stfu']);
    }
    if (!str_starts_with($liveMode, 'YSF')) {
        unset($stateCache['ysf']);
    }
    if ($liveMode !== 'DSTAR') {
        unset($stateCache['dstar']);
    }
    if ($liveMode !== 'DMR') {
        unset($stateCache['bm_stock']);
    }

    // BMTD and TGIFD own direct TLV paths outside normal MMDVM_Bridge runtime,
    // so they must beat stale live Analog_Bridge mode detection.
    if (dc_adapter_is_connected($bmtd)) {
        return $bmtd;
    }

    if (dc_adapter_is_connected($tgifd)) {
        return $tgifd;
    }

    if ($liveMode === 'STFU') {
        return dc_adapter_is_connected($bmStfu) ? $bmStfu : dc_idle_adapter('Idle');
    }

    if (str_starts_with($liveMode, 'YSF')) {
        return dc_adapter_is_connected($ysf) ? $ysf : dc_idle_adapter('Idle');
    }

    if ($liveMode === 'DSTAR') {
        return dc_adapter_is_connected($dstar) ? $dstar : dc_idle_adapter('Idle');
    }

    if ($liveMode === 'DMR') {
        $tgifUp = dc_adapter_is_connected($tgif);
        $bmStfuUp = dc_adapter_is_connected($bmStfu);
        $bmStockUp = dc_adapter_is_connected($bmStock);

        if ($tgifUp) return $tgif;
        if ($bmStfuUp && $bmStockUp) return dc_newer_adapter($bmStfu, $bmStock);
        if ($bmStfuUp) return $bmStfu;
        if ($bmStockUp) return $bmStock;
        return dc_idle_adapter('Idle');
    }

    // For P25/NXDN, use the dedicated session-aware adapters. Generic history
    // is intentionally not allowed to own active P25/NXDN state.
    if ($liveMode === 'P25') {
        return dc_adapter_is_connected($p25) ? $p25 : dc_idle_adapter('Idle');
    }

    if ($liveMode === 'NXDN') {
        return dc_adapter_is_connected($nxdn) ? $nxdn : dc_idle_adapter('Idle');
    }

    if (dc_adapter_is_connected($generic)) {
        return $generic;
    }

    return dc_idle_adapter('Idle');
}

function dc_resolve_active_adapter(array $abinfo, array $adapters, array &$stateCache = []): array {
    return dc_resolve_active($abinfo, $adapters, $stateCache);
}
?>
