#!/usr/bin/env bash
set -euo pipefail

APP_NAME="DVSwitch Cockpit"
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEST_DIR="${DEST_DIR:-/var/www/html/dvswitch_cockpit}"
SUDOERS_FILE="${SUDOERS_FILE:-/etc/sudoers.d/dvswitch-cockpit-services}"
CACHE_DIR="${CACHE_DIR:-$DEST_DIR/data/cache}"
APACHE_CONF_FILE="${APACHE_CONF_FILE:-/etc/apache2/conf-available/dvswitch-cockpit-security.conf}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"

if [[ $EUID -ne 0 ]]; then
  echo "Please run as root: sudo ./setup_dvswitch_cockpit.sh" >&2
  exit 1
fi

echo "============================================================"
echo "$APP_NAME setup"
echo "============================================================"
echo "Source:      $SRC_DIR"
echo "Destination: $DEST_DIR"
echo

echo "[1/7] Checking basic packages..."
if command -v apt-get >/dev/null 2>&1; then
  NEED_PKGS=()
  command -v apache2 >/dev/null 2>&1 || NEED_PKGS+=(apache2)
  command -v php >/dev/null 2>&1 || NEED_PKGS+=(php)
  command -v unzip >/dev/null 2>&1 || NEED_PKGS+=(unzip)

  if [[ ${#NEED_PKGS[@]} -gt 0 ]]; then
    echo "Installing missing packages: ${NEED_PKGS[*]}"
    apt-get update
    apt-get install -y "${NEED_PKGS[@]}"
  else
    echo "Required basic packages already present."
  fi
else
  echo "apt-get not found; skipping package install check."
fi

echo
echo "[2/7] Preparing destination..."
mkdir -p "$DEST_DIR"

SRC_REAL="$(realpath "$SRC_DIR")"
DEST_REAL="$(realpath "$DEST_DIR")"

if [[ "$SRC_REAL" == "$DEST_REAL" ]]; then
  echo "Source and destination are the same directory."
  echo "Skipping file copy and only applying permissions/sudoers."
else
  if [[ -d "$DEST_DIR" && -n "$(find "$DEST_DIR" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]]; then
    BACKUP="${DEST_DIR}.backup.$(date +%Y%m%d-%H%M%S)"
    echo "Existing install found. Creating backup:"
    echo "$BACKUP"
    cp -a "$DEST_DIR" "$BACKUP"
  fi

  echo "Copying files..."
  if command -v rsync >/dev/null 2>&1; then
    rsync -a --delete \
      --exclude '.git' \
      --exclude '__pycache__' \
      --exclude '*.pyc' \
      --exclude '*.pyo' \
      --exclude '*.pyd' \
      --exclude '*.bak' \
      --exclude '*.old' \
      --exclude '*.orig' \
      --exclude '*.tmp' \
      --exclude '*.zip' \
      --exclude '*.tar' \
      --exclude '*.tar.gz' \
      --exclude '*.tgz' \
      --exclude '*.before-*' \
      --exclude '*.after-*' \
      --exclude '*.backup*' \
      --exclude 'backup*/' \
      --exclude 'backups*/' \
      --exclude 'data/cache/' \
      --exclude 'data/private/auth.json' \
      --exclude '*~' \
      "$SRC_DIR/" "$DEST_DIR/"
  else
    echo "rsync not found; using tar fallback."
    find "$DEST_DIR" -mindepth 1 -maxdepth 1 ! -name data -exec rm -rf {} +
    (cd "$SRC_DIR" && tar \
      --exclude='./.git' \
      --exclude='./__pycache__' \
      --exclude='*.pyc' \
      --exclude='*.pyo' \
      --exclude='*.pyd' \
      --exclude='*.bak' \
      --exclude='*.old' \
      --exclude='*.orig' \
      --exclude='*.tmp' \
      --exclude='*.zip' \
      --exclude='*.tar' \
      --exclude='*.tar.gz' \
      --exclude='*.tgz' \
      --exclude='*.before-*' \
      --exclude='*.after-*' \
      --exclude='*.backup*' \
      --exclude='backup*/' \
      --exclude='backups*/' \
      --exclude='./data/cache' \
      --exclude='./data/cache/*' \
      --exclude='./data/private/auth.json' \
      --exclude='*~' \
      -cf - .) | (cd "$DEST_DIR" && tar -xf -)
  fi
fi

echo
echo "[3/7] Applying ownership, permissions, and runtime cache migration..."
mkdir -p "$CACHE_DIR"

PRIVATE_DIR="$DEST_DIR/data/private"
mkdir -p "$PRIVATE_DIR"
chmod 0700 "$PRIVATE_DIR" 2>/dev/null || true

migrate_cache_file() {
  local old_path="$1"
  local new_path="$2"

  if [[ -f "$old_path" ]]; then
    echo "Migrating cache: $old_path -> $new_path"
    mkdir -p "$(dirname "$new_path")"
    if cp -f "$old_path" "$new_path"; then
      chmod 0644 "$new_path" || true
      rm -f "$old_path" || true
    else
      echo "Warning: failed to migrate $old_path; leaving old file in place." >&2
    fi
  fi
}

migrate_cache_file "/tmp/dvswitch_cockpit_dmr_subscribers.json" "$CACHE_DIR/dmr_subscribers.json"
migrate_cache_file "/tmp/dvswitch_cockpit_gateway_history.json" "$CACHE_DIR/gateway_history.json"
migrate_cache_file "/tmp/dvswitch_cockpit_runtime_state.json" "$CACHE_DIR/runtime_state.json"
migrate_cache_file "/tmp/dvcockpit_cpu_sample.json" "$CACHE_DIR/dvcockpit_cpu_sample.json"

for old_rate_file in /tmp/dvcockpit_rate_*.json; do
  [[ -e "$old_rate_file" ]] || continue
  migrate_cache_file "$old_rate_file" "$CACHE_DIR/$(basename "$old_rate_file")"
done

if [[ -d /tmp/dvswitch_cockpit_rate_limit ]]; then
  echo "Migrating cache directory: /tmp/dvswitch_cockpit_rate_limit -> $CACHE_DIR/rate_limit"
  mkdir -p "$CACHE_DIR/rate_limit"
  shopt -s nullglob
  for old_limit_file in /tmp/dvswitch_cockpit_rate_limit/*.json; do
    migrate_cache_file "$old_limit_file" "$CACHE_DIR/rate_limit/$(basename "$old_limit_file")"
  done
  shopt -u nullglob
  rmdir /tmp/dvswitch_cockpit_rate_limit 2>/dev/null || true
fi

migrate_cache_file "/var/cache/dvswitch-cockpit/subscriber_ids.lastgood.csv" "$CACHE_DIR/subscriber_ids.lastgood.csv"

rmdir /var/cache/dvswitch-cockpit 2>/dev/null || true

# runtime_state.json is Cockpit-generated display state. It is safe to rebuild
# and must not carry stale provider/connection state across updates.
# Do not clear subscriber lookup cache, gateway history, ribbon samples, or rate limits.
if [[ -f "$CACHE_DIR/runtime_state.json" ]]; then
  echo "Clearing generated runtime state cache: $CACHE_DIR/runtime_state.json"
  rm -f "$CACHE_DIR/runtime_state.json"
fi

if id "$WEB_USER" >/dev/null 2>&1; then
  chown -R "$WEB_USER:$WEB_GROUP" "$DEST_DIR" || true
  chown -R "$WEB_USER:$WEB_GROUP" "$CACHE_DIR" || true
else
  echo "Warning: web user '$WEB_USER' not found; ownership unchanged."
fi
chmod 0755 "$DEST_DIR/data" 2>/dev/null || true
chmod 0755 "$CACHE_DIR" || true
find "$CACHE_DIR" -type f -exec chmod 0644 {} + 2>/dev/null || true

if [[ ! -f "$PRIVATE_DIR/auth.json" ]] && [[ -f "$DEST_DIR/tools/bootstrap_auth.php" ]]; then
  echo "Creating login credentials (initial password printed below)..."
  php "$DEST_DIR/tools/bootstrap_auth.php" || echo "Warning: could not run bootstrap_auth.php; create $PRIVATE_DIR/auth.json manually." >&2
fi

find "$DEST_DIR" -type d -not -path "$CACHE_DIR" -not -path "$CACHE_DIR/*" -not -path "$PRIVATE_DIR" -not -path "$PRIVATE_DIR/*" -exec chmod 0755 {} +
find "$DEST_DIR" -type f -not -path "$CACHE_DIR/*" -exec chmod 0644 {} +
chmod 0755 "$DEST_DIR/setup_dvswitch_cockpit.sh" 2>/dev/null || true

echo
echo "[4/7] Installing sudoers rule..."
cat > "$SUDOERS_FILE" <<EOF_SUDOERS
# Managed by setup_dvswitch_cockpit.sh
# Allows the DVSwitch Cockpit web UI to restart DVSwitch services
# and read AllStarLink node-link state for TGIF private audio detection.
$WEB_USER ALL=(root) NOPASSWD: /usr/bin/systemctl restart analog_bridge.service, /usr/bin/systemctl restart mmdvm_bridge.service, /usr/sbin/asterisk -rx rpt nodes *
EOF_SUDOERS

chmod 0440 "$SUDOERS_FILE"
visudo -cf "$SUDOERS_FILE"

echo
echo "[5/7] Installing Apache access-control config if available..."
if [[ -d /etc/apache2/conf-available ]]; then
  cat > "$APACHE_CONF_FILE" <<EOF_APACHE
# Managed by setup_dvswitch_cockpit.sh
# Access is controlled by DVSwitch Cockpit session login (see data/private/auth.json).
<Directory "$DEST_DIR">
    Options -Indexes
    AllowOverride All
    Require all granted
    <FilesMatch "(^\.|~$|\.(bak|old|orig|tmp|swp|swo|log|zip|tar|tgz|gz|patch|b64|ini|cfg|yml|yaml|csv|sqlite|db|md|txt|sh)$)">
        Require all denied
    </FilesMatch>
    <FilesMatch "^(VERSION|tree\.txt|config\.ini|subscriber_ids\.csv|ABInfo_.*\.json)$">
        Require all denied
    </FilesMatch>
</Directory>

<DirectoryMatch "^$DEST_DIR/(\.git|\.github|docs|systemd|tools|templates|includes|api/runtime|data/cache|data/private)(/|$)">
    Require all denied
</DirectoryMatch>
EOF_APACHE
  if command -v a2enconf >/dev/null 2>&1; then
    a2enconf dvswitch-cockpit-security >/dev/null || true
  fi
else
  echo "Apache conf-available not found; .htaccess and PHP guards still apply."
fi

echo
echo "[6/7] Reloading Apache if available..."
if systemctl list-unit-files apache2.service >/dev/null 2>&1; then
  systemctl reload apache2 || systemctl restart apache2 || true
else
  echo "apache2.service not found; skipping Apache reload."
fi

echo
echo "[7/7] Setup summary"
echo "Install path: $DEST_DIR"
echo "Sudoers:     $SUDOERS_FILE"
echo "Cache dir:   $CACHE_DIR"
echo "Apache conf: $APACHE_CONF_FILE"
echo
echo "Open:"
echo "  http://<node-ip>/dvswitch_cockpit/"
echo
echo "Notes:"
echo "  - Session login protects the dashboard; initial password is printed when auth.json is created."
echo "  - Change the password with: sudo php $DEST_DIR/tools/set_cockpit_password.php 'new-password'"
echo "  - Cockpit reads runtime state only."
echo "  - It does not perform BM/TGIF/YSF connect or disconnect actions."
echo "  - Restart buttons are limited to Analog_Bridge and MMDVM_Bridge."
echo "  - DMR callsign lookup uses the existing DVSwitch subscriber database when available."
echo "  - A last-known-good subscriber fallback is kept in $CACHE_DIR when possible."
echo
echo "Setup complete."
