<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
$textbotlang = languagechange();
require_once __DIR__ . '/../botapi.php';

// Use __DIR__ (directory of this file) so paths are correct regardless
// of the cron process working directory (which is often /root or /).
$destination = __DIR__;
$sourcefir   = dirname(__DIR__);
$logFile     = $destination . '/backup.log';

function blog(string $msg): void
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

blog('=== Backup started ===');

$setting = select("setting", "*");

$reportbackupRow = select("topicid", "idreport", "report", "backupfile", "select");
$reportbackup    = is_array($reportbackupRow) ? ($reportbackupRow['idreport'] ?? '0') : '0';

$channelReport = $setting['Channel_Report'] ?? '';
blog("Channel_Report={$channelReport} | thread_id={$reportbackup}");

if (empty($channelReport) || $channelReport === '0') {
    blog('ERROR: Channel_Report is empty or 0 — cannot send backups. Configure it in bot settings.');
    exit;
}

// ─── Sub-bot file backups ───────────────────────────────────────────────────
$botlist = select("botsaz", "*", null, null, "fetchAll");
if ($botlist) {
    foreach ($botlist as $bot) {
        $folderName = $bot['id_user'] . $bot['username'];
        $zipFile   = $destination . '/bot_' . $bot['id_user'] . '_backup.zip';
        $dataDir   = $sourcefir . '/vpnbot/' . $folderName . '/data';
        $prodJson  = $sourcefir . '/vpnbot/' . $folderName . '/product.json';
        $prodName  = $sourcefir . '/vpnbot/' . $folderName . '/product_name.json';

        $targets = '';
        if (is_dir($dataDir))        $targets .= ' ' . escapeshellarg($dataDir);
        if (file_exists($prodJson))  $targets .= ' ' . escapeshellarg($prodJson);
        if (file_exists($prodName))  $targets .= ' ' . escapeshellarg($prodName);

        if (!empty($targets)) {
            $zipCmd = 'zip -r ' . escapeshellarg($zipFile) . $targets . ' >> ' . escapeshellarg($logFile) . ' 2>&1';
            shell_exec($zipCmd);
        }

        if (file_exists($zipFile) && filesize($zipFile) > 0) {
            blog("Sending bot backup: {$bot['username']} ({$bot['id_user']})");
            $res = telegram('sendDocument', [
                'chat_id'           => $channelReport,
                'message_thread_id' => $reportbackup,
                'document'          => new CURLFile($zipFile),
                'caption'           => "@{$bot['username']} | {$bot['id_user']}",
            ], null, 120);
            blog("sendDocument result: " . json_encode($res['ok'] ?? null) . (isset($res['description']) ? ' — ' . $res['description'] : ''));
            unlink($zipFile);
        } else {
            blog("Bot backup zip missing or empty for {$bot['username']} — skipping send");
        }
    }
}

// ─── Database + config backup ───────────────────────────────────────────────
$dbhost  = (isset($dbhost) && $dbhost !== '' && $dbhost !== '{database_url}') ? $dbhost : 'localhost';
$today   = date('Y-m-d');
$sqlFile = $destination . '/backup_' . $today . '.sql';
$zipFile = $destination . '/backup_' . $today . '.zip';

// Resolve mysqldump binary — use full path so cron's restricted PATH is not an issue
$mysqldump = '';
foreach (['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/bin/mysqldump'] as $candidate) {
    if (file_exists($candidate) && is_executable($candidate)) {
        $mysqldump = $candidate;
        break;
    }
}
if ($mysqldump === '') {
    $mysqldump = trim((string) shell_exec('which mysqldump 2>&1'));
}
if ($mysqldump === '') {
    blog('ERROR: mysqldump not found on this server.');
    telegram('sendmessage', [
        'chat_id'           => $channelReport,
        'message_thread_id' => $reportbackup,
        'text'              => '⚠️ mysqldump not found on this server.',
    ]);
    exit;
}
blog("Using mysqldump: {$mysqldump}");

// Detect MariaDB vs MySQL and pick the correct SSL silence flag
$dumpVersion = (string) shell_exec($mysqldump . ' --version 2>&1');
$sslFlag = (stripos($dumpVersion, 'mariadb') !== false) ? '--skip-ssl' : '--ssl-mode=DISABLED';
blog("mysqldump version: " . trim($dumpVersion) . " | sslFlag={$sslFlag}");

// Use MYSQL_PWD env var — password never appears in the process list or ps output
putenv('MYSQL_PWD=' . $passworddb);
$command = sprintf(
    '%s -h %s -u %s --no-tablespaces %s %s > %s 2>> %s',
    escapeshellarg($mysqldump),
    escapeshellarg($dbhost),
    escapeshellarg($usernamedb),
    $sslFlag,
    escapeshellarg($dbname),
    escapeshellarg($sqlFile),
    escapeshellarg($logFile)
);

$dumpOutput = [];
$dumpExit   = 0;
exec($command, $dumpOutput, $dumpExit);
putenv('MYSQL_PWD=');  // clear immediately after use

$sqlOk = ($dumpExit === 0 && file_exists($sqlFile) && filesize($sqlFile) > 0);
blog("mysqldump exit={$dumpExit} | sqlFile exists=" . (file_exists($sqlFile) ? 'yes' : 'no') . " | size=" . (file_exists($sqlFile) ? filesize($sqlFile) : 0));

if (!$sqlOk) {
    blog('ERROR: mysqldump failed — sending error notification');
    telegram('sendmessage', [
        'chat_id'           => $channelReport,
        'message_thread_id' => $reportbackup,
        'text'              => $textbotlang['keyboard']['backupError'] ?? '⚠️ Backup failed: mysqldump error.',
    ]);
} else {
    // Pack SQL dump + config.php into one zip (config has DB creds needed for restore)
    $configFile = $sourcefir . '/config.php';
    $zipTargets = escapeshellarg($sqlFile);
    if (file_exists($configFile)) {
        $zipTargets .= ' ' . escapeshellarg($configFile);
    }
    // -j: strip directory paths so files sit at zip root
    $zipCmd = 'zip -j ' . escapeshellarg($zipFile) . ' ' . $zipTargets . ' >> ' . escapeshellarg($logFile) . ' 2>&1';
    shell_exec($zipCmd);

    // Prefer the zip; fall back to raw SQL if zip failed
    $sendFile = (file_exists($zipFile) && filesize($zipFile) > 0) ? $zipFile : $sqlFile;
    blog("Sending DB backup: " . basename($sendFile) . " (" . filesize($sendFile) . " bytes)");

    $res = telegram('sendDocument', [
        'chat_id'           => $channelReport,
        'message_thread_id' => $reportbackup,
        'document'          => new CURLFile($sendFile),
        'caption'           => $textbotlang['hardcoded']['backupDatabaseCaption'] ?? '🗄 Database Backup',
    ], null, 120);
    blog("sendDocument result: " . json_encode($res['ok'] ?? null) . (isset($res['description']) ? ' — ' . $res['description'] : ''));

    if (file_exists($sqlFile)) unlink($sqlFile);
    if (file_exists($zipFile)) unlink($zipFile);
}

blog('=== Backup finished ===');
