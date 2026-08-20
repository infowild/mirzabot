#!/usr/bin/env bash

set -Eeuo pipefail

echo "==== Mirza Bot Auto Installer ===="

if [ "$(id -u)" -ne 0 ]; then
  echo "Please run the script as root (sudo su or sudo bash install.sh)."
  exit 1
fi

### 1. Collect information from user
read -p "Enter your domain (example: bot.example.com): " DOMAIN
read -p "Enter your email for SSL (Let's Encrypt): " EMAIL

read -p "Database name (default: mirzabot): " DB_NAME
DB_NAME=${DB_NAME:-mirzabot}

read -p "Database username (default: mirza_user): " DB_USER
DB_USER=${DB_USER:-mirza_user}

read -sp "Database password: " DB_PASS
echo ""

read -p "Telegram bot token (from BotFather): " BOT_TOKEN
ADMIN_API_TOKEN="$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')"
read -p "Admin Telegram ID (numeric): " ADMIN_ID
read -p "Bot username without @ (example: my_mirza_bot): " BOT_USERNAME

[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || { echo "Invalid domain."; exit 1; }
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || { echo "Invalid database name."; exit 1; }
[[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || { echo "Invalid database username."; exit 1; }

echo ""
echo "Domain:       $DOMAIN"
echo "Email:        $EMAIL"
echo "Database:     $DB_NAME / $DB_USER"
echo "Bot username: @$BOT_USERNAME"
echo "Admin ID:     $ADMIN_ID"
echo ""
read -p "Is the above information correct? (y/n): " CONFIRM
CONFIRM=${CONFIRM,,}
if [ "$CONFIRM" != "y" ]; then
  echo "Installation cancelled."
  exit 1
fi

### 2. Update system & install Apache, PHP, MySQL, etc.
echo "==> Updating system & installing Apache, PHP 8.2, MySQL, git, certbot ..."
apt update && apt upgrade -y

apt install -y apache2 mysql-server git software-properties-common curl

add-apt-repository ppa:ondrej/php -y
apt update

apt install -y \
  php8.2 libapache2-mod-php8.2 \
  php8.2-cli php8.2-common php8.2-mbstring php8.2-curl \
  php8.2-xml php8.2-zip php8.2-mysql php8.2-gd php8.2-bcmath

apt install -y certbot python3-certbot-apache

a2dismod php7.4 php8.0 php8.1 2>/dev/null || true
a2enmod php8.2 rewrite
systemctl restart apache2

echo "==> Current PHP version:"
php -v || true

### 3. Create database and user
echo "==> Creating MySQL database and user ..."
mysql -u root <<MYSQL_EOF
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
MYSQL_EOF

### 4. Clone Mirza Bot source
echo "==> Cloning Mirza Bot ..."
cd /var/www
if [ -d "mirzabot" ]; then
  echo "/var/www/mirzabot already exists. Pulling latest changes ..."
  cd /var/www/mirzabot
  git remote set-url origin https://github.com/infowild/mirzabot.git
  git fetch --prune origin main
  git merge --ff-only origin/main
else
  git clone --origin origin https://github.com/infowild/mirzabot.git mirzabot
fi

cd /var/www/mirzabot
chown -R www-data:www-data /var/www/mirzabot

### 5. Create Apache VirtualHost
echo "==> Creating Apache VirtualHost ..."
cat >/etc/apache2/sites-available/mirzabot.conf <<EOF
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot /var/www/mirzabot

    <Directory /var/www/mirzabot>
        AllowOverride All
        Require all granted
    </Directory>

    <Directory /var/www/mirzabot/cronbot>
        Require all denied
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/mirzabot_error.log
    CustomLog \${APACHE_LOG_DIR}/mirzabot_access.log combined
</VirtualHost>
EOF

a2ensite mirzabot.conf
a2dissite 000-default.conf 2>/dev/null || true
systemctl reload apache2

### 6. Write config.php
echo "==> Writing config.php ..."
CONFIG_PATH="/var/www/mirzabot/config.php"

if [ -f "$CONFIG_PATH" ] && [ ! -f "${CONFIG_PATH}.bak" ]; then
  cp "$CONFIG_PATH" "${CONFIG_PATH}.bak" || true
fi

cat >"$CONFIG_PATH" <<PHP
<?php
// ================= DATABASE =================
\$dbhost     = 'localhost';
\$dbname     = '$DB_NAME';
\$usernamedb = '$DB_USER';
\$passworddb = '$DB_PASS';

\$connect = mysqli_connect(\$dbhost, \$usernamedb, \$passworddb, \$dbname);
if (!\$connect) { error_log('Database connection failed.'); http_response_code(500); die('Service unavailable.'); }
mysqli_set_charset(\$connect, "utf8mb4");

\$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
\$dsn = "mysql:host=\$dbhost;dbname=\$dbname;charset=utf8mb4";
\$pdo = new PDO(\$dsn, \$usernamedb, \$passworddb, \$options);

// ================= TELEGRAM BOT =================
\$APIKEY      = '$BOT_TOKEN';
\$ADMIN_API_TOKEN = '$ADMIN_API_TOKEN';
\$tls_ca_bundle = getenv('MIRZABOT_CA_BUNDLE') ?: null;
\$adminnumber = '$ADMIN_ID';
\$domainhosts = '$DOMAIN';
\$usernamebot = '$BOT_USERNAME';

function configureCurlTls(\$handle): void
{
    global \$tls_ca_bundle;
    curl_setopt(\$handle, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt(\$handle, CURLOPT_SSL_VERIFYHOST, 2);
    if (is_string(\$tls_ca_bundle) && \$tls_ca_bundle !== '') {
        \$caPath = realpath(\$tls_ca_bundle);
        if (\$caPath === false || !is_file(\$caPath) || !is_readable(\$caPath)) {
            throw new RuntimeException('Configured TLS CA bundle is not readable.');
        }
        curl_setopt(\$handle, CURLOPT_CAINFO, \$caPath);
    }
}
?>
PHP

chown root:www-data "$CONFIG_PATH"
chmod 0640 "$CONFIG_PATH"

### 7. Run table.php (create DB tables)
echo "==> Running table.php ..."
cd /var/www/mirzabot
php8.2 table.php
if [ $? -ne 0 ]; then
  echo "[WARNING] table.php returned an error. Check /var/www/mirzabot/error_log"
fi

### 8. SSL via Certbot
echo "==> Obtaining SSL certificate ..."
certbot --apache -d "$DOMAIN" -m "$EMAIL" --agree-tos --redirect --non-interactive || true

### 9. Telegram Webhook
echo "==> Setting Telegram webhook ..."
WEBHOOK_URL="https://$DOMAIN/index.php"
curl -s "https://api.telegram.org/bot$BOT_TOKEN/deleteWebhook" >/dev/null 2>&1 || true

WEBHOOK_RESULT=$(curl --fail --silent --show-error --get \
  --data-urlencode "url=$WEBHOOK_URL" \
  "https://api.telegram.org/bot$BOT_TOKEN/setWebhook")
echo "Webhook response: $WEBHOOK_RESULT"

### 10. Cron jobs
echo "==> Installing cron jobs ..."

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

cat >/etc/cron.d/mirzabot <<EOF
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * www-data flock -n /run/lock/mirzabot-notifications.lock php /var/www/mirzabot/cronbot/NoticationsService.php >/dev/null 2>&1
*/5 * * * * www-data flock -n /run/lock/mirzabot-uptime-panel.lock php /var/www/mirzabot/cronbot/uptime_panel.php >/dev/null 2>&1
*/5 * * * * www-data flock -n /run/lock/mirzabot-uptime-node.lock php /var/www/mirzabot/cronbot/uptime_node.php >/dev/null 2>&1
*/10 * * * * www-data flock -n /run/lock/mirzabot-expire-agent.lock php /var/www/mirzabot/cronbot/expireagent.php >/dev/null 2>&1
*/10 * * * * www-data flock -n /run/lock/mirzabot-payment-expire.lock php /var/www/mirzabot/cronbot/payment_expire.php >/dev/null 2>&1
0 * * * * www-data flock -n /run/lock/mirzabot-statusday.lock php /var/www/mirzabot/cronbot/statusday.php >/dev/null 2>&1
0 3 * * * www-data flock -n /run/lock/mirzabot-backup.lock php /var/www/mirzabot/cronbot/backupbot.php >/dev/null 2>&1
*/15 * * * * www-data flock -n /run/lock/mirzabot-iranpay.lock php /var/www/mirzabot/cronbot/iranpay1.php >/dev/null 2>&1
*/15 * * * * www-data flock -n /run/lock/mirzabot-plisio.lock php /var/www/mirzabot/cronbot/plisio.php >/dev/null 2>&1
EOF
chmod 0644 /etc/cron.d/mirzabot

echo ""
echo "===== Installation FINISHED Successfully ====="
echo "Now go to Telegram and send /start to @$BOT_USERNAME"
echo ""
echo "Panel URL:  https://$DOMAIN"
echo "Admin API token: $ADMIN_API_TOKEN"
echo "Store this token securely; it is not the Telegram bot token."
echo "Repository: https://github.com/infowild/mirzabot"
