<?php
declare(strict_types=1);

function dc_cockpit_cache_dir(): string {
    return __DIR__ . '/../../data/cache';
}

function dc_history_path(): string { return dc_cockpit_cache_dir() . '/gateway_history.json'; }
function dc_state_cache_path(): string { return dc_cockpit_cache_dir() . '/runtime_state.json'; }

function dc_cache_ensure_dir(): void {
    $dir = dc_cockpit_cache_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

function dc_cache_write_json(string $path, array $payload, int $maxBytes = 262144): void {
    dc_cache_ensure_dir();

    $json = json_encode($payload, JSON_PRETTY_PRINT);
    if (!is_string($json)) return;

    if ($maxBytes > 0 && strlen($json) > $maxBytes) {
        return;
    }

    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return;
    }

    @chmod($tmp, 0644);
    @rename($tmp, $path);
}

function dc_load_history(): array {
    $file = dc_history_path();
    if (!is_readable($file)) return ['signature' => '', 'rows' => []];
    $json = json_decode((string)@file_get_contents($file), true);
    if (!is_array($json)) return ['signature' => '', 'rows' => []];
    if (!isset($json['rows']) || !is_array($json['rows'])) $json['rows'] = [];
    if (!isset($json['signature'])) $json['signature'] = '';
    return $json;
}
function dc_save_history(array $payload): void {
    if (!isset($payload['rows']) || !is_array($payload['rows'])) {
        $payload['rows'] = [];
    }

    usort($payload['rows'], fn($a, $b) => strcmp((string)($b['utc'] ?? ''), (string)($a['utc'] ?? '')));
    $payload['rows'] = array_slice($payload['rows'], 0, 240);

    // Keep history bounded by both row count and file size. If unusually long
    // rows would exceed the byte cap, shrink further rather than leaving stale
    // or ever-growing history behind.
    while (count($payload['rows']) > 20) {
        $json = json_encode($payload, JSON_PRETTY_PRINT);
        if (is_string($json) && strlen($json) <= 262144) {
            break;
        }
        $payload['rows'] = array_slice($payload['rows'], 0, max(20, count($payload['rows']) - 20));
    }

    dc_cache_write_json(dc_history_path(), $payload, 262144);
}
function dc_merge_value_is_useful($value): bool {
    $value = trim((string)$value);
    return $value !== '' && $value !== '--' && $value !== '-';
}

function dc_merge_prefer_useful_row(array $preferred, array $fallback): array {
    // Keep the newest/current row shape, but do not lose useful duration,
    // quality, or lookup fields when another adapter pass returns the same
    // row with blanks. This prevents mode switches from turning Dur(s) back
    // into -- for already-known activity rows.
    foreach (['dur', 'loss', 'ber', 'callsign_display', 'dmr_id', 'qrz_url', 'callsign_lookup'] as $field) {
        if (!dc_merge_value_is_useful($preferred[$field] ?? '') && dc_merge_value_is_useful($fallback[$field] ?? '')) {
            $preferred[$field] = $fallback[$field];
        }
    }
    return $preferred;
}

function dc_history_row_is_local(array $row): bool {
    return strtoupper((string)($row['src'] ?? '')) === 'LNET';
}

function dc_history_dedupe_local_rows(array $rows): array {
    $seen = [];
    $out = [];
    foreach ($rows as $row) {
        if (dc_history_row_is_local($row)) {
            $key = implode('|', [
                (string)($row['utc'] ?? ''),
                (string)($row['mode'] ?? ''),
                (string)($row['callsign'] ?? ''),
                (string)($row['src'] ?? ''),
                (string)($row['dur'] ?? ''),
            ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
        }
        $out[] = $row;
    }
    return $out;
}

function dc_merge_history(array $existing, array $current): array {
    $map = [];
    foreach (array_merge($current, $existing) as $row) {
        $key = implode('|', [
            (string)($row['utc'] ?? ''),
            (string)($row['mode'] ?? ''),
            (string)($row['callsign'] ?? ''),
            (string)($row['target'] ?? ''),
            (string)($row['src'] ?? ''),
        ]);

        if (!isset($map[$key])) {
            $map[$key] = $row;
        } else {
            $map[$key] = dc_merge_prefer_useful_row($map[$key], $row);
        }
    }
    $rows = array_values($map);
    usort($rows, fn($a,$b) => strcmp((string)($b['utc'] ?? ''), (string)($a['utc'] ?? '')));
    $rows = dc_history_dedupe_local_rows($rows);
    return array_slice($rows, 0, 120);
}
function dc_history_duplicate_key(array $row): string {
    $station = (string)($row['callsign_display'] ?? ($row['callsign'] ?? ''));
    return implode('|', [
        (string)($row['utc'] ?? ''),
        trim($station),
        (string)($row['target'] ?? ''),
        (string)($row['src'] ?? ''),
    ]);
}

function dc_history_row_epoch(array $row): int {
    $utc = trim((string)($row['utc'] ?? ''));
    if ($utc === '') return 0;

    try {
        return (new DateTime($utc, new DateTimeZone('UTC')))->getTimestamp();
    } catch (Throwable $e) {
        return 0;
    }
}

function dc_history_station_target_key(array $row): string {
    $station = strtoupper(trim((string)($row['callsign_display'] ?? $row['callsign'] ?? '')));
    $target = trim((string)($row['target'] ?? ''));
    $src = strtoupper(trim((string)($row['src'] ?? '')));

    return $station . '|' . $target . '|' . $src;
}

function dc_history_remove_cross_mode_duplicates(array $rows): array {
    $digitalKeys = [];
    $tgifNearby = [];

    foreach ($rows as $row) {
        $mode = (string)($row['mode'] ?? '');
        if ($mode === 'NXDN' || $mode === 'P25' || $mode === 'DMR/TGIF') {
            $digitalKeys[dc_history_duplicate_key($row)] = true;
        }

        if ($mode === 'DMR/TGIF') {
            $key = dc_history_station_target_key($row);
            if ($key !== '||') {
                $tgifNearby[$key][] = dc_history_row_epoch($row);
            }
        }
    }

    if (!$digitalKeys && !$tgifNearby) {
        return $rows;
    }

    $out = [];
    foreach ($rows as $row) {
        $mode = (string)($row['mode'] ?? '');
        if ($mode === 'DMR/BM' && isset($digitalKeys[dc_history_duplicate_key($row)])) {
            continue;
        }

        // TGIFD traffic can appear briefly as stock DMR/BM after mode handoff
        // because Analog_Bridge is still in DMR mode. If a BM row has the same
        // station/target/source as a nearby real DMR/TGIF row, drop the fake BM
        // copy while preserving real BM rows such as the current BM talkgroup.
        if ($mode === 'DMR/BM') {
            $key = dc_history_station_target_key($row);
            $epoch = dc_history_row_epoch($row);

            if ($epoch > 0 && isset($tgifNearby[$key])) {
                foreach ($tgifNearby[$key] as $tgifEpoch) {
                    if ($tgifEpoch > 0 && abs($epoch - $tgifEpoch) <= 180) {
                        continue 2;
                    }
                }
            }
        }

        $out[] = $row;
    }

    return $out;
}

function dc_history_target_digits(string $target): string {
    return preg_replace('/\D+/', '', $target) ?? '';
}

function dc_history_display_station(array $row): string {
    return trim((string)($row['callsign_display'] ?? $row['callsign'] ?? ''));
}

function dc_history_parrot_display_key(array $row): string {
    $mode = strtoupper((string)($row['mode'] ?? ''));
    $src = strtoupper((string)($row['src'] ?? ''));
    $target = (string)($row['target'] ?? '');
    $targetDigits = dc_history_target_digits($target);

    // P25/NXDN talkback/parrot rows can appear multiple ways in history:
    // PARROT, 10999, 9999, or the local callsign. Display one stock-style row.
    if ($src === 'NET' && ($mode === 'P25' || $mode === 'NXDN') && $targetDigits === '10') {
        return implode('|', [
            (string)($row['utc'] ?? ''),
            $mode,
            'PARROT_TG10',
            $target,
            $src,
            (string)($row['dur'] ?? ''),
        ]);
    }

    // P25 TG 9999 is commonly shown by stock DVSwitch as MMDVM.
    if ($src === 'NET' && $mode === 'P25' && $targetDigits === '9999') {
        return implode('|', [
            (string)($row['utc'] ?? ''),
            $mode,
            'MMDVM_TG9999',
            $target,
            $src,
            (string)($row['dur'] ?? ''),
        ]);
    }

    return implode('|', [
        (string)($row['utc'] ?? ''),
        $mode,
        dc_history_display_station($row),
        $target,
        $src,
        (string)($row['dur'] ?? ''),
    ]);
}

function dc_history_display_row_score(array $row): int {
    $mode = strtoupper((string)($row['mode'] ?? ''));
    $src = strtoupper((string)($row['src'] ?? ''));
    $targetDigits = dc_history_target_digits((string)($row['target'] ?? ''));
    $station = strtoupper(dc_history_display_station($row));

    $score = 0;

    if ($src === 'LNET') {
        $score += 20;
    }

    if ($src === 'NET' && ($mode === 'P25' || $mode === 'NXDN') && $targetDigits === '10') {
        if ($station === 'PARROT') {
            $score += 100;
        } elseif (preg_match('/^\d+$/', $station)) {
            $score += 10;
        }
    }

    if ($src === 'NET' && $mode === 'P25' && $targetDigits === '9999') {
        if ($station === 'MMDVM') {
            $score += 100;
        } elseif (preg_match('/^\d+$/', $station)) {
            $score += 10;
        }
    }

    if (dc_merge_value_is_useful($row['dur'] ?? '')) {
        $score += 5;
    }
    if (dc_merge_value_is_useful($row['loss'] ?? '') || dc_merge_value_is_useful($row['ber'] ?? '')) {
        $score += 3;
    }

    return $score;
}

function dc_history_display_rows(array $rows, int $limit = 16): array {
    $rows = dc_history_remove_cross_mode_duplicates($rows);

    $deduped = [];
    foreach ($rows as $row) {
        $key = dc_history_parrot_display_key($row);

        if (!isset($deduped[$key])) {
            $deduped[$key] = $row;
            continue;
        }

        $old = $deduped[$key];

        if (dc_history_display_row_score($row) > dc_history_display_row_score($old)) {
            $deduped[$key] = dc_merge_prefer_useful_row($row, $old);
        } else {
            $deduped[$key] = dc_merge_prefer_useful_row($old, $row);
        }
    }

    $deduped = array_values($deduped);
    usort($deduped, fn($a, $b) => strcmp((string)($b['utc'] ?? ''), (string)($a['utc'] ?? '')));

    return array_slice($deduped, 0, $limit);
}

function dc_load_state_cache(): array {
    $f = dc_state_cache_path();
    if (!is_readable($f)) return [];
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : [];
}
function dc_save_state_cache(array $state): void {
    dc_cache_write_json(dc_state_cache_path(), $state, 65536);
}
?>