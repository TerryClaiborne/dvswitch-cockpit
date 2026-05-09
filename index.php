<?php declare(strict_types=1);
require __DIR__ . '/api/security.php';
dc_security_apply_headers();
dc_security_session_start();
$canRestartServices = dc_security_is_authenticated();

if (isset($_GET['dvc_ribbon_ajax']) && $_GET['dvc_ribbon_ajax'] === '1') {
    require __DIR__ . '/includes/system_ribbon.php';
    exit;
}

$dvcVersion = trim((string)@file_get_contents(__DIR__ . '/VERSION'));
if ($dvcVersion === '') { $dvcVersion = '0.0.0'; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DVSwitch Cockpit</title>
  <link rel="stylesheet" href="static/app.css">
</head>
<body data-can-restart="<?= $canRestartServices ? '1' : '0' ?>">
  <header class="top-header">
    <div class="title-wrap title-wrap-with-auth">
      <div class="auth-links">
        <?php if ($canRestartServices) { ?>
          <a href="logout.php">Log out</a>
        <?php } else { ?>
          <a href="login.php">Sign in</a><span class="auth-guest-hint"> for restarts</span>
        <?php } ?>
      </div>
      <h1 class="app-title">DVSwitch Cockpit <a id="update-bolt" class="update-bolt" href="https://github.com/TerryClaiborne/dvswitch-cockpit" target="_blank" rel="noopener noreferrer" data-current-version="<?= htmlspecialchars($dvcVersion, ENT_QUOTES) ?>" title="" aria-label="DVSwitch Cockpit update available">⚡</a></h1>
      <p>Modern dashboard for AllStarLink 3 / DVSwitch</p>
    </div>
  </header>

  <?php require __DIR__ . '/includes/system_ribbon.php'; ?>

  <main class="app-shell">
    <section class="hero-panel panel">
      <h2>Node Status</h2>
      <div class="hero-card">
        <div class="hero-title" id="hero-title">Loading runtime status...</div>
        <div class="hero-grid">
          <div class="hero-labels">
            <div>Network</div>
            <div>Target</div>
            <div>Last Heard</div>
            <div>Path</div>
          </div>
          <div class="hero-values">
            <div id="hero-network">--</div>
            <div id="hero-target">--</div>
            <div id="hero-last-heard">--</div>
            <div class="rx-row"><span class="rx-dot"></span><span id="hero-path">--</span></div>
          </div>
          <div class="hero-meters">
            <div class="meter-row"><div class="meter purple"><span id="meter-rx-gain" style="width:0%"></span></div><small id="meter-rx-text">RX Gain --</small></div>
            <div class="meter-row"><div class="meter green"><span id="meter-tx-gain" style="width:0%"></span></div><small id="meter-tx-text">TX Gain --</small></div>
            <div class="meter-row"><div class="meter green"><span id="meter-usrp" style="width:0%"></span></div><small id="meter-usrp-text">USRP --</small></div>
            <div class="meter-row"><div class="meter gold"><span id="meter-dv3000" style="width:0%"></span></div><small id="meter-dv3000-text">Vocoder --</small></div>
          </div>
        </div>
      </div>
    </section>

    <section class="dashboard-grid dashboard-grid-single" style="grid-template-columns:250px minmax(0,1fr) 230px; align-items:stretch;">
      <aside class="panel left-panel">
        <div class="panel-head">
          <h3>Node Status</h3>
          <div class="panel-icons">≡</div>
        </div>

        <div class="node-summary card-dark">
          <div class="node-line-big">
            <strong id="node-rpt">--</strong>
            <span id="node-call">--</span>
            <span id="node-status-text">Idle</span>
          </div>
          <div class="mode-pill-row">
            <span class="mode-pill active" id="node-provider">--</span>
            <div class="mini-meter"><span id="node-mini-meter" style="width:0%"></span></div>
            <span class="mini-dot"></span>
            <span class="mini-text" id="node-tscc">TS -- / CC --</span>
          </div>
        </div>

        <div class="status-stack">
          <div class="service-pill card-dark"><span>Gateway <strong id="left-gw">--</strong></span><span class="live-dot"></span></div>
          <div class="service-pill card-dark"><span>Target</span><span class="ok-text" id="left-target">--</span></div>
          <div class="service-pill card-dark"><span id="left-status-label">Last Tune</span><span class="ok-text" id="left-last-tune">--</span></div>
          <div class="service-pill card-dark"><span>Mute</span><span class="ok-text" id="left-mute">--</span></div>
          <div class="service-pill card-dark"><span>Source File</span><span class="stop-text" id="left-source">--</span></div>
        </div>

        <?php if (!$canRestartServices) { ?>
        <p class="service-login-hint" id="service-login-hint">Service restarts require <a href="login.php">sign in</a>.</p>
        <?php } ?>
        <div class="control-buttons vertical">
          <button class="action-btn" type="button" data-service-action="restart" data-service-name="analog_bridge"<?= $canRestartServices ? '' : ' disabled' ?>>Restart Analog Bridge</button>
          <button class="action-btn" type="button" data-service-action="restart" data-service-name="mmdvm_bridge"<?= $canRestartServices ? '' : ' disabled' ?>>Restart MMDVM Bridge</button>
          <button class="action-btn" type="button" data-service-action="restart" data-service-name="both"<?= $canRestartServices ? '' : ' disabled' ?>>Restart Analog + MMDVM</button>
        </div>
        <div class="action-status<?= $canRestartServices ? '' : ' guest-readonly' ?>" id="action-status"><?= $canRestartServices ? 'Service actions ready.' : 'View only — sign in to restart services.' ?></div>
      </aside>

      <section class="center-column center-column-single" style="display:flex; flex-direction:column; gap:14px;">
        <section class="panel center-top-panel" style="height:330px; display:flex; flex-direction:column;">
          <div class="section-title">Gateway Activity</div>
          <div class="table-wrap card-dark table-wrap-tall" style="flex:1 1 auto; min-height:250px; max-height:250px;">
            <table class="activity-table" id="gateway-table">
              <thead>
                <tr><th>Time</th><th>Mode</th><th>Station</th><th>Target</th><th>Src</th><th>Dur(s)</th><th>Quality</th></tr>
              </thead>
              <tbody><tr><td colspan="7">Loading gateway activity...</td></tr></tbody>
            </table>
          </div>
          <div class="gateway-footer-bar footer-bar">
            <span>AB Version: <strong id="footer-ab-version">--</strong></span>
            <span>Mute: <strong id="footer-mute">--</strong></span>
            <span>Last Heard: <strong id="footer-last-tune">--</strong></span>
            <span>USRP RX: <strong id="footer-usrp-rx">--</strong></span>
            <span>USRP TX: <strong id="footer-usrp-tx">--</strong></span>
          </div>
        </section>

        <section class="panel center-top-panel" style="height:205px; display:flex; flex-direction:column;">
          <div class="section-title">Local Activity</div>
          <div class="table-wrap card-dark table-wrap-tall" style="flex:1 1 auto; min-height:145px; max-height:145px;">
            <table class="activity-table" id="local-table">
              <thead>
                <tr><th>Time</th><th>Mode</th><th>Station</th><th>Target</th><th>Src</th><th>Dur(s)</th><th>Quality</th></tr>
              </thead>
              <tbody><tr><td colspan="7">Loading local activity...</td></tr></tbody>
            </table>
          </div>
        </section>
      </section>

      <aside class="panel right-panel">
        <div class="panel-head"><h3>Recent Activity</h3><div class="panel-icons">≡</div></div>
        <div class="recent-summary card-dark"><p id="summary-line-1">Loading runtime summary</p><p id="summary-line-2">(detecting provider)</p></div>
        <div class="network-status-grid">
          <div class="network-box card-dark net-bm"><span>BrandMeister</span><strong id="mode-bm">--</strong></div>
          <div class="network-box card-dark net-tgif"><span>TGIF/HBLink</span><strong id="mode-tgif">--</strong></div>
          <div class="network-box card-dark net-ysf"><span>YSF</span><strong id="mode-ysf">--</strong></div>
          <div class="network-box card-dark net-dstar"><span>D-Star</span><strong id="mode-dstar">--</strong></div>
          <div class="network-box card-dark net-p25"><span>P25</span><strong id="mode-p25">--</strong></div>
          <div class="network-box card-dark net-nxdn"><span>NXDN</span><strong id="mode-nxdn">--</strong></div>
        </div>
        <div class="recent-log card-dark" id="recent-log"><div>Loading recent events...</div></div>
      </aside>
    </section>
  </main>


  <script src="static/app.js"></script>
</body>
</html>
