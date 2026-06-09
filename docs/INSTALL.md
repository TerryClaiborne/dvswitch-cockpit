# Install DVSwitch Cockpit

DVSwitch Cockpit is a PHP/Apache dashboard for ASL3 / DVSwitch systems.

It reads live runtime files and logs. It does **not** perform network connect/disconnect actions.

## Standard install

From the project directory:

```bash
sudo ./setup_dvswitch_cockpit.sh
```

Then open:

```text
http://<node-ip>/dvswitch_cockpit/
```

The installer:

- copies the web files into `/var/www/html/dvswitch_cockpit`
- writes `/etc/sudoers.d/dvswitch-cockpit-services`
- allows the web UI to restart `analog_bridge.service` and `mmdvm_bridge.service`
- allows a read-only Asterisk `rpt nodes` check for systems that use a private DVSwitch audio node

## Stock ASL3 / DVSwitch systems

A stock install should still show:

- Analog Bridge status from `/tmp/ABInfo_*.json`
- stock DMR/BrandMeister activity from `Analog_Bridge.log`
- YSF/D-Star/P25/NXDN activity from MMDVM Bridge logs when available
- restart buttons for Analog Bridge and MMDVM Bridge

## Optional integrations

The cockpit can also detect:

- BM/STFU when STFU is installed and running
- TGIF / AllTune2 TGIFD when matching runtime evidence is available
- AllTune2 private-node state when `/var/www/html/alltune2/config.ini` exists and Asterisk `rpt nodes` can be read

Those integrations are optional and are not required for a normal ASL3 / DVSwitch install.

## Runtime/cache location

The installer creates and maintains:

```text
/var/www/html/dvswitch_cockpit/data/cache/
```

This directory stores generated Cockpit cache/state files. The installer migrates older Cockpit cache files from `/tmp/dvswitch_cockpit_*.json` and `/var/cache/dvswitch-cockpit/` when present, then removes only those exact old Cockpit-created files after a successful copy.
