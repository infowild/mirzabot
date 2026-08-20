#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="/var/www/mirzabot"
PURGE=false
[[ "${1:-}" == "--purge" ]] && PURGE=true
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root."; exit 1; }

BOT_TOKEN=""
DB_NAME=""
DB_USER=""
DOMAIN=""

read_config_value() {
  php -r '
$tokens = token_get_all(file_get_contents($argv[1]));
$wanted = "$" . $argv[2];
$count = count($tokens);
for ($i = 0; $i < $count; $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE || $tokens[$i][1] !== $wanted) {
        continue;
    }
    for ($j = $i + 1; $j < $count; $j++) {
        if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if ($tokens[$j] !== "=") {
            exit;
        }
        for ($j++; $j < $count; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal = $tokens[$j][1];
                $value = substr($literal, 1, -1);
                echo $literal[0] === chr(34) ? stripcslashes($value) : str_replace(["\\\\", "\\" . chr(39)], ["\\", chr(39)], $value);
            }
            exit;
        }
    }
}
' "$APP_DIR/config.php" "$1" 2>/dev/null || true
}

if [[ -f "$APP_DIR/config.php" ]]; then
  BOT_TOKEN="$(read_config_value APIKEY)"
  DB_NAME="$(read_config_value dbname)"
  DB_USER="$(read_config_value usernamedb)"
  DOMAIN="$(read_config_value domain)"
fi

rm -f /etc/cron.d/mirzabot

# Remove only legacy MirzaBot entries and preserve unrelated crontab jobs.
if command -v crontab >/dev/null 2>&1; then
  for cron_user in root www-data; do
    legacy_cron="$(mktemp)"
    if crontab -u "$cron_user" -l >"$legacy_cron" 2>/dev/null; then
      sed '\#/var/www/mirzabot/cronbot/#d' "$legacy_cron" | crontab -u "$cron_user" -
    fi
    rm -f "$legacy_cron"
  done
fi

rm -f /etc/apache2/sites-available/mirzabot.conf /etc/apache2/sites-enabled/mirzabot.conf
rm -f /etc/apache2/sites-available/mirzabot-le-ssl.conf /etc/apache2/sites-enabled/mirzabot-le-ssl.conf
rm -f /etc/nginx/sites-available/mirzabot /etc/nginx/sites-enabled/mirzabot
command -v systemctl >/dev/null && systemctl reload apache2 2>/dev/null || true
command -v systemctl >/dev/null && systemctl reload nginx 2>/dev/null || true

if [[ -n "$BOT_TOKEN" ]]; then
  curl --fail --silent --show-error "https://api.telegram.org/bot${BOT_TOKEN}/deleteWebhook" >/dev/null || true
fi

if [[ "$PURGE" == true ]]; then
  echo "WARNING: --purge permanently deletes all MirzaBot files, its database/user, logs, backups, and TLS certificate."
  read -r -p "Type PURGE to continue: " CONFIRM
  [[ "$CONFIRM" == "PURGE" ]] || { echo "Purge cancelled."; exit 1; }
  [[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || { echo "Unsafe database name; aborting."; exit 1; }
  [[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || { echo "Unsafe database user; aborting."; exit 1; }
  mysql -u root -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; DROP USER IF EXISTS '$DB_USER'@'localhost'; FLUSH PRIVILEGES;"

  if [[ -n "$DOMAIN" && "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] && command -v certbot >/dev/null 2>&1; then
    certbot delete --cert-name "$DOMAIN" --non-interactive >/dev/null 2>&1 || true
  fi

  find /run/lock -maxdepth 1 -type f -name 'mirzabot-*.lock' -delete 2>/dev/null || true
  rm -f /var/log/apache2/mirzabot_access.log /var/log/apache2/mirzabot_access.log.*
  rm -f /var/log/apache2/mirzabot_error.log /var/log/apache2/mirzabot_error.log.*
  for backup_dir in /var/backups/mirzabot-*; do
    [[ -d "$backup_dir" ]] || continue
    [[ "$backup_dir" == /var/backups/mirzabot-* ]] || { echo "Unsafe backup path; aborting."; exit 1; }
    rm -rf --one-file-system "$backup_dir"
  done
else
  BACKUP_DIR="/var/backups/mirzabot-$(date +%Y%m%d-%H%M%S)"
  mkdir -p "$BACKUP_DIR"
  for item in backups backup storage/backups; do
    [[ -e "$APP_DIR/$item" ]] && cp -a "$APP_DIR/$item" "$BACKUP_DIR/" || true
  done
  echo "Database preserved. Existing backup directories copied to $BACKUP_DIR when present."
fi

if [[ -d "$APP_DIR" && "$APP_DIR" == "/var/www/mirzabot" ]]; then
  rm -rf --one-file-system "$APP_DIR"
fi
echo "MirzaBot uninstalled."
