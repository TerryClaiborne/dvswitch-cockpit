#!/usr/bin/env bash
set -euo pipefail

APP_NAME="DVSwitch Cockpit"
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEST_DIR="${DEST_DIR:-/var/www/html/dvswitch_cockpit}"
SUDOERS_FILE="${SUDOERS_FILE:-/etc/sudoers.d/dvswitch-cockpit-services}"
CACHE_DIR="${CACHE_DIR:-$DEST_DIR/data/cache}"
PRIVATE_DIR="${PRIVATE_DIR:-$DEST_DIR/data/private}"
AUTH_CONFIG_FILE="${AUTH_CONFIG_FILE:-$PRIVATE_DIR/auth.ini}"
AUTH_CONFIG_EXAMPLE_FILE="${AUTH_CONFIG_EXAMPLE_FILE:-$PRIVATE_DIR/auth.ini.example}"
APACHE_CONF_FILE="${APACHE_CONF_FILE:-/etc/apache2/conf-available/dvswitch-cockpit-security.conf}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"
AUTH_ACTION="normal"

case "${1:-}" in
  --set-admin-password|--auth)
    AUTH_ACTION="set-password"
    shift
    ;;
  --disable-auth)
    AUTH_ACTION="disable-auth"
    shift
    ;;
  --help|-h)
    echo "Usage:"
    echo "  sudo /var/www/html/dvswitch_cockpit/setup_dvswitch_cockpit.sh"
    echo "  sudo /var/www/html/dvswitch_cockpit/setup_dvswitch_cockpit.sh --set-admin-password"
    echo "  sudo /var/www/html/dvswitch_cockpit/setup_dvswitch_cockpit.sh --disable-auth"
    echo
    echo "Normal setup/update preserves existing auth settings."
    echo "--set-admin-password changes only the DVSwitch Cockpit web login password."
    echo "--disable-auth turns login off and keeps the saved hash."
    exit 0
    ;;
  "")
    ;;
  *)
    echo "[ERROR] Unknown option: ${1}" >&2
    echo "Run: sudo /var/www/html/dvswitch_cockpit/setup_dvswitch_cockpit.sh --help" >&2
    exit 1
    ;;
esac

if [[ "$#" -gt 0 ]]; then
  echo "[ERROR] Too many arguments." >&2
  echo "Run: sudo /var/www/html/dvswitch_cockpit/setup_dvswitch_cockpit.sh --help" >&2
  exit 1
fi

if [[ $EUID -ne 0 ]]; then
  echo "Please run as root: sudo /var/www/html/dvswitch_cockpit/setup_dvswitch_cockpit.sh" >&2
  exit 1
fi

ensure_auth_config_defaults() {
  mkdir -p "$PRIVATE_DIR"

  if [[ ! -f "$AUTH_CONFIG_EXAMPLE_FILE" ]]; then
    cat > "$AUTH_CONFIG_EXAMPLE_FILE" <<'EOF_AUTH_EXAMPLE'
; DVSwitch Cockpit optional web login
; The real local file is data/private/auth.ini and must not be committed.

DVSWITCH_COCKPIT_AUTH_ENABLED=0
DVSWITCH_COCKPIT_ADMIN_USER="admin"
DVSWITCH_COCKPIT_ADMIN_PASSWORD_HASH=""
EOF_AUTH_EXAMPLE
  fi

  if [[ ! -f "$AUTH_CONFIG_FILE" ]]; then
    cp "$AUTH_CONFIG_EXAMPLE_FILE" "$AUTH_CONFIG_FILE"
  fi

  chown root:"$WEB_GROUP" "$PRIVATE_DIR" 2>/dev/null || true
  chmod 0750 "$PRIVATE_DIR" 2>/dev/null || true
  chown root:"$WEB_GROUP" "$AUTH_CONFIG_FILE" 2>/dev/null || true
  chmod 0640 "$AUTH_CONFIG_FILE" 2>/dev/null || true
  chown root:root "$AUTH_CONFIG_EXAMPLE_FILE" 2>/dev/null || true
  chmod 0644 "$AUTH_CONFIG_EXAMPLE_FILE" 2>/dev/null || true
}

auth_ini_get() {
  local key="$1"
  local default_value="${2:-}"

  /usr/bin/php -r '
    $path = $argv[1];
    $key = $argv[2];
    $default = $argv[3];
    $cfg = is_readable($path) ? parse_ini_file($path, false, INI_SCANNER_RAW) : [];
    $value = is_array($cfg) && array_key_exists($key, $cfg) ? (string)$cfg[$key] : $default;
    $value = trim($value);
    if (strlen($value) >= 2 && $value[0] === "\"" && substr($value, -1) === "\"") {
        $value = substr($value, 1, -1);
    }
    echo $value;
  ' "$AUTH_CONFIG_FILE" "$key" "$default_value"
}

write_auth_config() {
  local enabled="$1"
  local user="$2"
  local hash="$3"

  umask 027
  cat > "$AUTH_CONFIG_FILE" <<EOF_AUTH_CONFIG
; DVSwitch Cockpit optional web login
; The real local file is data/private/auth.ini and must not be committed.

DVSWITCH_COCKPIT_AUTH_ENABLED=$enabled
DVSWITCH_COCKPIT_ADMIN_USER="$user"
DVSWITCH_COCKPIT_ADMIN_PASSWORD_HASH="$hash"
EOF_AUTH_CONFIG

  chown root:"$WEB_GROUP" "$AUTH_CONFIG_FILE" 2>/dev/null || true
  chmod 0640 "$AUTH_CONFIG_FILE" 2>/dev/null || true
}

run_auth_password_setup() {
  ensure_auth_config_defaults

  echo
  echo "DVSwitch Cockpit Web Login Password Setup"
  echo "========================================="
  echo
  echo "This changes only the DVSwitch Cockpit web login password."
  echo "The password hash is created automatically."
  echo "The plain password is not stored."
  echo

  local password_one=""
  local password_two=""
  local current_user=""

  current_user="$(auth_ini_get DVSWITCH_COCKPIT_ADMIN_USER admin)"
  [[ "$current_user" != "" ]] || current_user="admin"

  read -r -s -p "New admin password: " password_one
  echo

  if [[ -z "$password_one" ]]; then
    echo "[ERROR] Password cannot be blank for --set-admin-password. Use --disable-auth to turn login off." >&2
    exit 1
  fi

  read -r -s -p "Confirm admin password: " password_two
  echo

  if [[ "$password_one" != "$password_two" ]]; then
    echo "[ERROR] Passwords did not match. No changes were made." >&2
    exit 1
  fi

  local hash=""
  hash="$(printf '%s' "$password_one" | /usr/bin/php -r '$password = stream_get_contents(STDIN); echo password_hash($password, PASSWORD_DEFAULT);')"

  if [[ -z "$hash" ]]; then
    echo "[ERROR] Failed to create password hash. No changes were made." >&2
    exit 1
  fi

  write_auth_config 1 "$current_user" "$hash"

  unset password_one password_two

  echo
  echo "[OK] Web login enabled."
  echo "[OK] Password hash saved to data/private/auth.ini."
  echo
  echo "Next steps:"
  echo "1. Open /dvswitch_cockpit/ in your browser."
  echo "2. Click Login."
  echo "3. Enter the password you just set."
  echo
  echo "Notes:"
  echo "- The plain password was not stored."
  echo "- Running sudo /var/www/html/dvswitch_cockpit/setup_dvswitch_cockpit.sh normally will not change this password."
  echo "- To disable login later, run: sudo /var/www/html/dvswitch_cockpit/setup_dvswitch_cockpit.sh --disable-auth"
}

run_auth_disable() {
  ensure_auth_config_defaults

  local current_user=""
  local current_hash=""

  current_user="$(auth_ini_get DVSWITCH_COCKPIT_ADMIN_USER admin)"
  current_hash="$(auth_ini_get DVSWITCH_COCKPIT_ADMIN_PASSWORD_HASH "")"
  [[ "$current_user" != "" ]] || current_user="admin"

  write_auth_config 0 "$current_user" "$current_hash"

  echo
  echo "DVSwitch Cockpit Web Login Disable"
  echo "=================================="
  echo
  echo "[OK] Web login disabled."
  if [[ "$current_hash" != "" ]]; then
    echo "[OK] Existing password hash was kept."
  else
    echo "[OK] No password hash was set."
  fi
  echo
  echo "Next steps:"
  echo "1. Open /dvswitch_cockpit/ in your browser."
  echo "2. DVSwitch Cockpit should show No Login / Normal mode."
  echo
  echo "To re-enable login later:"
  echo "- Run sudo /var/www/html/dvswitch_cockpit/setup_dvswitch_cockpit.sh --set-admin-password"
}

if [[ "$AUTH_ACTION" == "set-password" ]]; then
  run_auth_password_setup
  exit 0
fi

if [[ "$AUTH_ACTION" == "disable-auth" ]]; then
  run_auth_disable
  exit 0
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
      --exclude 'data/private/auth.ini' \
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
      --exclude='./data/private/auth.ini' \
      --exclude='*~' \
      -cf - .) | (cd "$DEST_DIR" && tar -xf -)
  fi
fi

echo
echo "[3/7] Applying ownership, permissions, and runtime cache migration..."
mkdir -p "$CACHE_DIR" "$PRIVATE_DIR"

# Create disabled-by-default auth config if missing.
# Normal setup/update must preserve this file once it exists.
if [[ ! -f "$AUTH_CONFIG_EXAMPLE_FILE" ]]; then
  cat > "$AUTH_CONFIG_EXAMPLE_FILE" <<'EOF_AUTH_EXAMPLE'
; DVSwitch Cockpit optional web login
; The real local file is data/private/auth.ini and must not be committed.

DVSWITCH_COCKPIT_AUTH_ENABLED=0
DVSWITCH_COCKPIT_ADMIN_USER="admin"
DVSWITCH_COCKPIT_ADMIN_PASSWORD_HASH=""
EOF_AUTH_EXAMPLE
fi

if [[ ! -f "$AUTH_CONFIG_FILE" ]]; then
  cp "$AUTH_CONFIG_EXAMPLE_FILE" "$AUTH_CONFIG_FILE"
fi


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

find "$DEST_DIR" -type d -not -path "$CACHE_DIR" -not -path "$CACHE_DIR/*" -exec chmod 0755 {} +
find "$DEST_DIR" -type f -not -path "$CACHE_DIR/*" -exec chmod 0644 {} +
chmod 0755 "$DEST_DIR/setup_dvswitch_cockpit.sh" 2>/dev/null || true

# Private auth config is readable by the web group but not browser-served.
if [[ -d "$PRIVATE_DIR" ]]; then
  chown root:"$WEB_GROUP" "$PRIVATE_DIR" 2>/dev/null || true
  chmod 0750 "$PRIVATE_DIR" 2>/dev/null || true
fi

if [[ -f "$AUTH_CONFIG_FILE" ]]; then
  chown root:"$WEB_GROUP" "$AUTH_CONFIG_FILE" 2>/dev/null || true
  chmod 0640 "$AUTH_CONFIG_FILE" 2>/dev/null || true
fi

if [[ -f "$AUTH_CONFIG_EXAMPLE_FILE" ]]; then
  chown root:root "$AUTH_CONFIG_EXAMPLE_FILE" 2>/dev/null || true
  chmod 0644 "$AUTH_CONFIG_EXAMPLE_FILE" 2>/dev/null || true
fi


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
# DVSwitch Cockpit is intended for local/trusted network access only.
<Directory "$DEST_DIR">
    Options -Indexes
    AllowOverride All
    Require ip 127.0.0.1 ::1 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 169.254.0.0/16 100.64.0.0/10 fc00::/7 fe80::/10
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
echo "Auth config: $AUTH_CONFIG_FILE"
echo "Apache conf: $APACHE_CONF_FILE"
echo
echo "Open:"
echo "  http://<node-ip>/dvswitch_cockpit/"
echo
echo "Notes:"
echo "  - Cockpit reads runtime state only."
echo "  - It does not perform BM/TGIF/YSF connect or disconnect actions."
echo "  - Restart buttons are limited to Analog_Bridge and MMDVM_Bridge."
echo "  - DMR callsign lookup uses the existing DVSwitch subscriber database when available."
echo "  - Optional web login config is created disabled by default."
echo "  - Normal setup/update preserves existing web login settings."
echo "  - To set/change the web login password, run: sudo /var/www/html/dvswitch_cockpit/setup_dvswitch_cockpit.sh --set-admin-password"
echo "  - To disable web login and keep the saved hash, run: sudo /var/www/html/dvswitch_cockpit/setup_dvswitch_cockpit.sh --disable-auth"
echo "  - A last-known-good subscriber fallback is kept in $CACHE_DIR when possible."
echo
echo "Setup complete."
