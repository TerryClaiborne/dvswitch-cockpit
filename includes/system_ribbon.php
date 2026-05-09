<?php
declare(strict_types=1);

function dvc_apply_system_timezone(): void
{
    $tz = trim((string) @file_get_contents('/etc/timezone'));
    if ($tz !== '' && @timezone_open($tz) !== false) {
        date_default_timezone_set($tz);
    }
}

dvc_apply_system_timezone();

function dvc_exec(string $cmd): string
{
    $out = function_exists('shell_exec') ? @shell_exec($cmd) : '';
    if (is_string($out) && trim($out) !== '') return trim($out);
    $lines = [];
    @exec($cmd, $lines);
    return trim(implode("\n", $lines));
}

function dvc_read(string $path): ?string
{
    if (!is_readable($path)) return null;
    $txt = @file_get_contents($path);
    return $txt === false ? null : trim($txt);
}

function dvc_human_bytes(float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    $dec = $bytes >= 100 ? 0 : ($bytes >= 10 ? 1 : 2);
    return number_format($bytes, $dec) . ' ' . $units[$i];
}

function dvc_human_rate(float $bytesPerSec): string
{
    $bits = max(0.0, $bytesPerSec) * 8.0;
    $units = ['b/s', 'Kb/s', 'Mb/s', 'Gb/s'];
    $i = 0;
    while ($bits >= 1000 && $i < count($units) - 1) {
        $bits /= 1000;
        $i++;
    }
    $dec = $bits >= 100 ? 0 : ($bits >= 10 ? 1 : 2);
    return number_format($bits, $dec) . ' ' . $units[$i];
}

function dvc_cache_dir(): string
{
    $dir = dirname(__DIR__) . '/data/cache';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function dvc_cache_path(string $name): string
{
    return dvc_cache_dir() . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name);
}

function dvc_human_uptime(int $seconds): string
{
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $mins = intdiv($seconds % 3600, 60);
    if ($days > 0) return sprintf('%dd %02dh', $days, $hours);
    if ($hours > 0) return sprintf('%dh %02dm', $hours, $mins);
    return sprintf('%d min', max(0, $mins));
}

function dvc_cpu_percent(): string
{
    $line = dvc_read('/proc/stat');
    if ($line === null) return 'N/A';
    $first = strtok($line, "\n");
    if (!is_string($first) || strpos($first, 'cpu ') !== 0) return 'N/A';
    $parts = preg_split('/\s+/', trim($first));
    if (!is_array($parts) || count($parts) < 8) return 'N/A';
    $nums = array_map('floatval', array_slice($parts, 1));
    $idle = ($nums[3] ?? 0.0) + ($nums[4] ?? 0.0);
    $total = array_sum($nums);
    $cache = dvc_cache_path('dvcockpit_cpu_sample.json');
    $old = is_readable($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
    @file_put_contents($cache, json_encode(['idle' => $idle, 'total' => $total, 'time' => microtime(true)]));
    if (!is_array($old)) return '0%';
    $dt = $total - (float) ($old['total'] ?? 0);
    $di = $idle - (float) ($old['idle'] ?? 0);
    if ($dt <= 0) return '0%';
    $use = (1.0 - ($di / $dt)) * 100.0;
    return number_format(max(0.0, min(100.0, $use)), 0) . '%';
}

function dvc_default_iface(): string
{
    $route = @file('/proc/net/route', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($route)) {
        foreach ($route as $i => $line) {
            if ($i === 0) continue;
            $parts = preg_split('/\s+/', trim($line));
            if (is_array($parts) && count($parts) >= 2 && ($parts[1] ?? '') === '00000000' && ($parts[0] ?? '') !== 'lo') {
                return (string) $parts[0];
            }
        }
    }
    return 'eth0';
}

function dvc_service_state(array $services): string
{
    foreach ($services as $service) {
        $state = trim(dvc_exec('/bin/systemctl is-active ' . escapeshellarg($service) . ' 2>/dev/null'));
        if ($state === 'active') return 'ON';
    }
    return 'OFF';
}

function dvc_process_state(string $pattern): string
{
    $found = trim(dvc_exec('/usr/bin/pgrep -af ' . escapeshellarg($pattern) . ' 2>/dev/null'));
    if ($found === '') return 'OFF';

    foreach (preg_split('/\\R/', $found) as $line) {
        $line = trim((string) $line);
        if ($line === '') continue;

        // pgrep -af can match the shell command running this check, especially
        // when the regex appears in the command line. Do not let that make
        // optional HBLink/STFU indicators look ON for stock systems.
        if (stripos($line, 'pgrep -af') !== false) continue;
        if (stripos($line, 'timeout ') !== false && stripos($line, 'bash -lc') !== false) continue;
        if (stripos($line, 'system_ribbon.php') !== false) continue;

        return 'ON';
    }

    return 'OFF';
}


function dvc_collect(): array
{
    $host = gethostname() ?: 'unknown';
    $ip = 'n/a';
    $ips = dvc_exec('hostname -I 2>/dev/null');
    if ($ips !== '') {
        foreach (preg_split('/\s+/', $ips) as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($candidate, '127.') !== 0) {
                $ip = $candidate;
                break;
            }
        }
    }

    $time = date('m-d-Y h:i A');
    $cpu = dvc_cpu_percent();
    $load = 'N/A';
    $loadRaw = dvc_read('/proc/loadavg');
    if ($loadRaw !== null) {
        $parts = preg_split('/\s+/', $loadRaw);
        if (is_array($parts) && isset($parts[0])) $load = $parts[0];
    }

    $ram = 'N/A';
    $mem = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($mem)) {
        $vals = [];
        foreach ($mem as $line) {
            if (preg_match('/^([^:]+):\s+(\d+)/', $line, $m)) $vals[$m[1]] = (float) $m[2] * 1024.0;
        }
        $total = $vals['MemTotal'] ?? 0.0;
        if ($total > 0) {
            $avail = $vals['MemAvailable'] ?? (($vals['MemFree'] ?? 0) + ($vals['Cached'] ?? 0) + ($vals['Buffers'] ?? 0));
            $usedPct = (1.0 - (max(0.0, $avail) / $total)) * 100.0;
            $ram = number_format(max(0.0, min(100.0, $usedPct)), 0) . '%';
        }
    }

    $disk = 'N/A';
    $totalDisk = @disk_total_space('/');
    $freeDisk = @disk_free_space('/');
    if (is_numeric($totalDisk) && is_numeric($freeDisk) && (float) $totalDisk > 0) {
        $usedPct = (1.0 - ((float) $freeDisk / (float) $totalDisk)) * 100.0;
        $disk = number_format(max(0.0, min(100.0, $usedPct)), 0) . '%';
    }

    $temp = 'N/A';
    $best = null;
    foreach (glob('/sys/class/thermal/thermal_zone*/temp') ?: [] as $path) {
        $raw = dvc_read($path);
        if ($raw !== null && is_numeric($raw)) {
            $c = ((float) $raw >= 1000) ? ((float) $raw / 1000.0) : (float) $raw;
            if ($c >= 10 && $c <= 120 && ($best === null || $c > $best)) $best = $c;
        }
    }
    if ($best !== null) $temp = number_format(($best * 9 / 5) + 32, 1) . '°F';

    $uptime = 'N/A';
    $up = dvc_read('/proc/uptime');
    if ($up !== null) {
        $parts = preg_split('/\s+/', $up);
        if (is_array($parts) && isset($parts[0]) && is_numeric($parts[0])) $uptime = dvc_human_uptime((int) floor((float) $parts[0]));
    }

    $iface = dvc_default_iface();
    $rx = 'N/A';
    $tx = 'N/A';
    $rxNow = dvc_read("/sys/class/net/$iface/statistics/rx_bytes");
    $txNow = dvc_read("/sys/class/net/$iface/statistics/tx_bytes");
    if ($rxNow !== null && $txNow !== null && is_numeric($rxNow) && is_numeric($txNow)) {
        $cache = dvc_cache_path('dvcockpit_rate_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $iface) . '.json');
        $old = is_readable($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
        $now = microtime(true);
        $rxRate = 0.0;
        $txRate = 0.0;
        if (is_array($old)) {
            $dt = $now - (float) ($old['time'] ?? 0);
            if ($dt > 0.1) {
                $rxRate = ((float) $rxNow - (float) ($old['rx'] ?? 0)) / $dt;
                $txRate = ((float) $txNow - (float) ($old['tx'] ?? 0)) / $dt;
            }
        }
        @file_put_contents($cache, json_encode(['time' => $now, 'rx' => (float) $rxNow, 'tx' => (float) $txNow]));
        $rx = dvc_human_rate(max(0.0, $rxRate));
        $tx = dvc_human_rate(max(0.0, $txRate));
    }

    $ab = dvc_service_state(['analog_bridge.service']);
    $mb = dvc_service_state(['mmdvm_bridge.service']);
    $hblink = dvc_service_state(['alltune2-hblink.service', 'hblink.service', 'hblink3.service']);
    if ($hblink === 'OFF') $hblink = dvc_process_state('hblink|HBlink|tgif-hblink');
    $stfu = dvc_process_state('stfu|STFU|bm-stfu');

    return [
        'hostname' => $host,
        'ip' => $ip,
        'time' => $time,
        'cpu' => $cpu,
        'load' => $load,
        'ram' => $ram,
        'disk' => $disk,
        'rx' => $rx,
        'tx' => $tx,
        'temp' => $temp,
        'uptime' => $uptime,
        'iface' => $iface,
        'ab' => $ab,
        'mb' => $mb,
        'hblink' => $hblink,
        'stfu' => $stfu,
    ];
}

if (isset($_GET['dvc_ribbon_ajax']) && $_GET['dvc_ribbon_ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(dvc_collect(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$initial = dvc_collect();
$self = htmlspecialchars(basename(__FILE__), ENT_QUOTES, 'UTF-8');
$id = 'dvc_ribbon_' . substr(md5((string) __FILE__), 0, 8);
function dvc_chip(array $initial, string $label, string $key, bool $hot = false): string
{
    $value = htmlspecialchars((string) ($initial[$key] ?? 'N/A'), ENT_QUOTES, 'UTF-8');
    $hotClass = $hot ? ' dvc-hot' : '';
    return '<span class="dvc-ribbon-pill"><span class="dvc-l">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span><span class="dvc-v' . $hotClass . '" data-k="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">' . $value . '</span></span>';
}
?>
<div id="<?= $id ?>" class="dvc-ribbon" data-endpoint="index.php?dvc_ribbon_ajax=1">
<style>
#<?= $id ?>.dvc-ribbon{display:block;width:100%;max-width:1220px;margin:2px auto 6px;padding:4px 6px;border:1px solid rgba(140,112,210,.22);border-radius:10px;background:linear-gradient(180deg,rgba(20,11,33,.94),rgba(14,8,23,.96));box-shadow:inset 0 0 0 1px rgba(255,255,255,.02);font-family:Arial,Helvetica,sans-serif}
#<?= $id ?> .dvc-ribbon-row{display:flex;width:100%;gap:4px;align-items:center;justify-content:center;flex-wrap:wrap;white-space:normal;overflow-x:visible;margin:0 auto}
#<?= $id ?> .dvc-ribbon-pill{display:inline-flex;align-items:center;gap:4px;min-height:20px;padding:0 6px;border:1px solid rgba(110,84,173,.24);border-radius:7px;background:linear-gradient(180deg,rgba(35,18,58,.92),rgba(23,12,36,.96));color:#f0e9ff;font-size:9px;flex:0 0 auto}
#<?= $id ?> .dvc-l{color:#c9a9ff;font-weight:700} #<?= $id ?> .dvc-v{color:#f7f4ff;font-weight:600} #<?= $id ?> .dvc-hot{color:#77ef7d}
@media (max-width:1040px){ #<?= $id ?>.dvc-ribbon{width:100%;max-width:100%;margin:4px 0} #<?= $id ?> .dvc-ribbon-row{display:flex;width:100%;justify-content:center} }
</style>
<div class="dvc-ribbon-row">
<?= dvc_chip($initial, 'Node', 'hostname') ?>
<?= dvc_chip($initial, 'IP', 'ip') ?>
<?= dvc_chip($initial, 'Time', 'time') ?>
<?= dvc_chip($initial, 'CPU', 'cpu') ?>
<?= dvc_chip($initial, 'Load', 'load') ?>
<?= dvc_chip($initial, 'RAM', 'ram') ?>
<?= dvc_chip($initial, 'Disk', 'disk') ?>
<?= dvc_chip($initial, 'Down', 'rx', true) ?>
<?= dvc_chip($initial, 'Up', 'tx', true) ?>
<?= dvc_chip($initial, 'Temp', 'temp') ?>
<?= dvc_chip($initial, 'Uptime', 'uptime') ?>
<?= dvc_chip($initial, 'Iface', 'iface') ?>
<?= dvc_chip($initial, 'AB', 'ab', true) ?>
<?= dvc_chip($initial, 'MB', 'mb', true) ?>
<?= dvc_chip($initial, 'HBL', 'hblink', true) ?>
<?= dvc_chip($initial, 'STFU', 'stfu', true) ?>
</div>
</div>
