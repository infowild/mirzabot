<?php
// This variable added for high load panels which their response time is long and bot can't communicate with online panel!
// null for default settings
$request_exec_timeout = null;
$dbhost = '{database_url}';
$dbname = '{database_name}';
$usernamedb = '{username_db}';
$passworddb = '{password_db}';
$connect = mysqli_connect($dbhost, $usernamedb, $passworddb, $dbname);
if (!$connect) { error_log('Database connection failed.'); http_response_code(500); die('Service unavailable.'); }
mysqli_set_charset($connect, "utf8mb4");
$options = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, ];
$dsn = "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4";
try { $pdo = new PDO($dsn, $usernamedb, $passworddb, $options); } catch (\PDOException $e) { error_log("Database connection failed: " . $e->getMessage()); }
$APIKEY = '{API_KEY}';
$ADMIN_API_TOKEN = '{ADMIN_API_TOKEN}';
$tls_ca_bundle = getenv('MIRZABOT_CA_BUNDLE') ?: null;
$adminnumber = '{admin_number}';
$domainhosts = '{domain_name}';
$usernamebot = '{username_bot}';

function configureCurlTls($handle): void
{
    global $tls_ca_bundle;
    curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
    if (is_string($tls_ca_bundle) && $tls_ca_bundle !== '') {
        $caPath = realpath($tls_ca_bundle);
        if ($caPath === false || !is_file($caPath) || !is_readable($caPath)) {
            throw new RuntimeException('Configured TLS CA bundle is not readable.');
        }
        curl_setopt($handle, CURLOPT_CAINFO, $caPath);
    }
}

?>
