<?php
/**
 * Debug volume/time notification for one service username.
 * Usage (on server):
 *   php cronbot/debug_notif.php kaveh
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../function.php';

$username = $argv[1] ?? '';
if ($username === '') {
    fwrite(STDERR, "Usage: php cronbot/debug_notif.php <username>\n");
    exit(1);
}

$textBotLang = languagechange();
$setting = select("setting", "*");
$status_cron = json_decode($setting['cron_status'] ?? '{}', true);
if (!is_array($status_cron)) {
    $status_cron = [];
}

echo "=== Debug notif for username: {$username} ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

echo "1) cron_status.volume = " . var_export($status_cron['volume'] ?? null, true) . "\n";
echo "   cron_status.day    = " . var_export($status_cron['day'] ?? null, true) . "\n\n";

$invoice = select("invoice", "*", "username", $username, "select");
if (!$invoice) {
    echo "FAIL: invoice not found for username={$username}\n";
    exit(2);
}

echo "2) Invoice:\n";
echo "   id_invoice      = " . ($invoice['id_invoice'] ?? '') . "\n";
echo "   id_user         = " . ($invoice['id_user'] ?? '') . "\n";
echo "   Status          = " . ($invoice['Status'] ?? '') . "\n";
echo "   Service_location= " . ($invoice['Service_location'] ?? '') . "\n";
echo "   name_product    = " . ($invoice['name_product'] ?? '') . "\n";
echo "   Volume          = " . ($invoice['Volume'] ?? '') . "\n";
echo "   Service_time    = " . ($invoice['Service_time'] ?? '') . "\n";
echo "   time_cron       = " . var_export($invoice['time_cron'] ?? null, true);
if (!empty($invoice['time_cron']) && is_numeric($invoice['time_cron'])) {
    $ago = time() - (int)$invoice['time_cron'];
    echo "  (" . $ago . "s ago)";
}
echo "\n";
echo "   notifctions     = " . ($invoice['notifctions'] ?? 'null') . "\n";
echo "   bottype         = " . var_export($invoice['bottype'] ?? null, true) . "\n\n";

$check_send = json_decode($invoice['notifctions'] ?? '', true);
if (!is_array($check_send)) {
    $check_send = ['volume' => false, 'time' => false];
}
echo "3) Flags:\n";
echo "   volume already sent? " . (!empty($check_send['volume']) ? 'YES (will SKIP)' : 'NO') . "\n";
echo "   time already sent?   " . (!empty($check_send['time']) ? 'YES (will SKIP)' : 'NO') . "\n\n";

$user = select("user", "*", "id", $invoice['id_user'], "select");
if (!$user) {
    echo "FAIL: telegram user id={$invoice['id_user']} not found\n";
    exit(3);
}
echo "4) Telegram user:\n";
echo "   id          = " . ($user['id'] ?? '') . "\n";
echo "   username    = " . ($user['username'] ?? '') . "\n";
echo "   status_cron = " . var_export($user['status_cron'] ?? null, true);
if (intval($user['status_cron'] ?? 1) == 0) {
    echo "  << USER DISABLED CRON NOTIFS";
}
echo "\n";
echo "   User_Status = " . ($user['User_Status'] ?? '') . "\n\n";

$panel = select("marzban_panel", "*", "name_panel", $invoice['Service_location'], "select");
if (!$panel) {
    echo "FAIL: panel not found: " . ($invoice['Service_location'] ?? '') . "\n";
    exit(4);
}
echo "5) Panel:\n";
echo "   name   = " . ($panel['name_panel'] ?? '') . "\n";
echo "   type   = " . ($panel['type'] ?? '') . "\n";
echo "   status = " . ($panel['status'] ?? '') . "\n\n";

$ManagePanel = new ManagePanel();
$userData = $ManagePanel->DataUser($invoice['Service_location'], $username);
echo "6) Panel DataUser:\n";
if (!$userData || ($userData['status'] ?? '') === 'Unsuccessful') {
    echo "   FAIL: " . json_encode($userData, JSON_UNESCAPED_UNICODE) . "\n";
    echo "\nRoot cause likely: cannot read user from VPN panel (token/URL/username mismatch).\n";
    exit(5);
}
echo "   status       = " . ($userData['status'] ?? '') . "\n";
echo "   data_limit   = " . ($userData['data_limit'] ?? 'null') . " bytes\n";
echo "   used_traffic = " . ($userData['used_traffic'] ?? 'null') . " bytes\n";
$dataLimit = floatval($userData['data_limit'] ?? 0);
$used = floatval($userData['used_traffic'] ?? 0);
if ($dataLimit <= 0) {
    echo "   used%        = N/A (unlimited or zero limit — volume warn SKIPPED)\n";
} else {
    $pct = ($used / $dataLimit) * 100;
    $remain = max(0, $dataLimit - $used);
    echo "   used%        = " . round($pct, 2) . "%\n";
    echo "   remaining    = " . formatBytes($remain) . "\n";
    echo "   warn at >=80%? " . ($pct >= 80 ? 'YES' : 'NO') . "\n";
}
$statusOk = in_array($userData['status'] ?? '', ['active', 'Unknown', 'limited'], true);
echo "   status allowed for warn? " . ($statusOk ? 'YES' : 'NO (' . ($userData['status'] ?? '') . ')') . "\n\n";

$warningDays = intval($setting['daywarn'] ?? 2);
if ($warningDays < 1) {
    $warningDays = 2;
}
$expire = intval($userData['expire'] ?? 0);
$timeRemaining = $expire > 0 ? ($expire - time()) : 0;
$daysRemaining = $timeRemaining > 0 ? max(0, intval($timeRemaining / 86400)) : 0;
echo "6b) Time warning (daywarn={$warningDays}):\n";
echo "   expire        = " . ($expire > 0 ? date('Y-m-d H:i:s', $expire) : 'unlimited/none') . "\n";
echo "   days remaining= " . ($expire > 0 ? $daysRemaining : 'N/A') . "\n";
$timeWarnYes = $expire > 0 && $timeRemaining > 0 && $timeRemaining <= ($warningDays * 86400) && $statusOk;
echo "   warn at <={$warningDays} days? " . ($timeWarnYes ? 'YES' : 'NO') . "\n\n";

$eligible = empty($status_cron['volume']) ? false : true;
echo "7) Decision tree (volume):\n";
$blockReason = null;
if (!$eligible) {
    $blockReason = 'cron volume notifications are OFF in admin settings';
} elseif (!empty($check_send['volume'])) {
    $blockReason = 'notifctions.volume already true (already marked sent)';
} elseif (intval($user['status_cron'] ?? 1) == 0) {
    $blockReason = 'user.status_cron = 0';
} elseif ($dataLimit <= 0) {
    $blockReason = 'unlimited / zero data_limit';
} elseif (!$statusOk) {
    $blockReason = 'panel status not in active/Unknown/limited';
} elseif ($dataLimit > 0 && ($used / $dataLimit) * 100 < 80) {
    $blockReason = 'used% still under 80%';
}

if ($blockReason) {
    echo "   BLOCKED: {$blockReason}\n";
} else {
    echo "   SHOULD SEND volume warning now.\n";
}

echo "\n7c) Decision tree (time):\n";
if (empty($status_cron['day'])) {
    echo "   BLOCKED: cron day notifications are OFF\n";
} elseif (!empty($check_send['time'])) {
    echo "   BLOCKED: notifctions.time already true\n";
} elseif (intval($user['status_cron'] ?? 1) == 0) {
    echo "   BLOCKED: user.status_cron = 0\n";
} elseif ($timeWarnYes) {
    echo "   SHOULD SEND time warning now (<= {$warningDays} days left).\n";
} else {
    echo "   BLOCKED: not within daywarn window or status/expire invalid\n";
}

// SEND=1 always attempts a test Telegram message (even if cron would skip)
if (getenv('SEND') === '1') {
    echo "\n7b) Force test send (SEND=1):\n";
    $token = (!empty($invoice['bottype'])) ? $invoice['bottype'] : null;
    $msg = "🧪 تست نوتیف حجم برای سرویس <code>" . htmlspecialchars($username) . "</code>";
    $result = sendmessage($invoice['id_user'], $msg, null, 'HTML', $token);
    echo "   send result: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    if (is_array($result) && !empty($result['ok'])) {
        echo "   Telegram OK — user can receive messages.\n";
    } else {
        echo "   Telegram FAIL — user blocked bot / wrong token / chat id.\n";
    }
} else {
    echo "   Tip: run with SEND=1 to force a test Telegram message.\n";
}

echo "\n8) Cron queue eligibility:\n";
$allowedStatus = ['active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold'];
$st = $invoice['Status'] ?? '';
echo "   Status in cron list? " . (in_array($st, $allowedStatus, true) ? 'YES' : 'NO') . "\n";
$tc = $invoice['time_cron'] ?? null;
if ($tc === null || $tc === '') {
    echo "   time_cron ready? YES (NULL)\n";
} elseif (is_numeric($tc) && (time() - (int)$tc) >= 3600) {
    echo "   time_cron ready? YES (>=1h old)\n";
} else {
    $wait = 3600 - (time() - (int)$tc);
    echo "   time_cron ready? NO — wait ~{$wait}s (hourly queue filter)\n";
}

echo "\nDone.\n";
