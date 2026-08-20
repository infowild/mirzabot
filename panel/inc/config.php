<?php

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../function.php';

date_default_timezone_set('Asia/Tehran');

// Panel UI language strings (loaded from lang/fa.php via languagechange())
$textbotlang = languagechange();

function is_jalali_leap(int $jy): bool {
    return in_array($jy % 33, [1, 5, 9, 13, 17, 22, 26, 30], true);
}

function gregorian_to_jalali(int $gy, int $gm, int $gd): array {
    $g    = $gy - 1600;
    $gdn  = 365 * $g + (int)(($g + 3) / 4) - (int)(($g + 99) / 100) + (int)(($g + 399) / 400);
    $gml  = [0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    for ($i = 1; $i < $gm; $i++) $gdn += $gml[$i];
    if ($gm > 2 && (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0)) $gdn++;
    $gdn += $gd - 1;
    $jdn  = $gdn - 79;
    $jnp  = (int)($jdn / 12053); $jdn %= 12053;
    $jy   = 979 + 33 * $jnp + 4 * (int)($jdn / 1461); $jdn %= 1461;
    if ($jdn >= 366) { $jy += (int)(($jdn - 1) / 365); $jdn = ($jdn - 1) % 365; }
    $jdim = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, is_jalali_leap($jy) ? 30 : 29];
    for ($i = 0; $i < 11 && $jdn >= $jdim[$i]; $i++) $jdn -= $jdim[$i];
    return [$jy, $i + 1, $jdn + 1];
}

function jdate(string $format = 'Y/m/d', ?int $ts = null): string {
    if ($ts === null) $ts = time();
    [$jy, $jm, $jd] = gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    $out = '';
    for ($i = 0; $i < strlen($format); $i++) {
        $out .= match($format[$i]) {
            'Y' => str_pad((string)$jy, 4, '0', STR_PAD_LEFT),
            'y' => substr((string)$jy, -2),
            'm' => str_pad((string)$jm, 2, '0', STR_PAD_LEFT),
            'n' => (string)$jm,
            'd' => str_pad((string)$jd, 2, '0', STR_PAD_LEFT),
            'j' => (string)$jd,
            default => $format[$i],
        };
    }
    return $out;
}

function jalali_days_in_month(int $jy, int $jm): int {
    if ($jm <= 6) return 31;
    if ($jm <= 11) return 30;
    return is_jalali_leap($jy) ? 30 : 29;
}

function jalali_to_gregorian(int $jy, int $jm, int $jd): array {
    $gy = ($jy <= 979) ? 621 : 1600;
    $jy -= ($jy <= 979) ? 0 : 979;
    $days = (365 * $jy) + (((int)($jy / 33)) * 8) + ((int)((($jy % 33) + 3) / 4))
          + 78 + $jd + (($jm < 7) ? (($jm - 1) * 31) : ((($jm - 7) * 30) + 186));
    $gy += 400 * ((int)($days / 146097));
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * ((int)(--$days / 36524));
        $days %= 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $gy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $sal_a = [0, 31, ((($gy % 4 === 0) && ($gy % 100 !== 0)) || ($gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    for ($gm = 1; $gm <= 12 && $gd > $sal_a[$gm]; $gm++) {
        $gd -= $sal_a[$gm];
    }
    return [$gy, $gm, $gd];
}

/** Unix timestamp at 00:00:00 Asia/Tehran for Jalali Y/m/d */
function jalali_day_start_ts(int $jy, int $jm, int $jd = 1): int {
    [$gy, $gm, $gd] = jalali_to_gregorian($jy, $jm, $jd);
    return mktime(0, 0, 0, $gm, $gd, $gy);
}

function db_query(PDO $pdo, string $sql, array $params = []): PDOStatement
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_fetch(PDO $pdo, string $sql, array $params = []): ?array
{
    return db_query($pdo, $sql, $params)->fetch() ?: null;
}

function db_fetchAll(PDO $pdo, string $sql, array $params = []): array
{
    return db_query($pdo, $sql, $params)->fetchAll();
}

function db_count(PDO $pdo, string $sql, array $params = []): int
{
    return (int) db_query($pdo, $sql, $params)->fetchColumn();
}
function require_auth(): void
{
    if (session_status() === PHP_SESSION_NONE)
        session_start();
    global $pdo;
    if (empty($_SESSION['admin_user'])) {
        header('Location: login.php');
        exit;
    }
    try {
        $admin = db_fetch($pdo, "SELECT id_admin FROM admin WHERE username = ?", [$_SESSION['admin_user']]);
        if (!$admin) {
            session_destroy();
            header('Location: login.php');
            exit;
        }
    } catch (Exception $e) {
        session_destroy();
        header('Location: login.php');
        exit;
    }
}

function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE)
        session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check_post(): void
{
    global $textbotlang;
    $token = $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(403);
        die($textbotlang['panel']['configInvalidRequest']);
    }
}

function csrf_check_get(): void
{
    global $textbotlang;
    $token = $_GET['_csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(403);
        die($textbotlang['panel']['configInvalidRequest']);
    }
}

function flash(string $key, string $msg): void
{
    if (session_status() === PHP_SESSION_NONE)
        session_start();
    $_SESSION["flash_{$key}"] = $msg;
}

function get_flash(string $key): ?string
{
    if (session_status() === PHP_SESSION_NONE)
        session_start();
    $msg = $_SESSION["flash_{$key}"] ?? null;
    unset($_SESSION["flash_{$key}"]);
    return $msg;
}

function trunc(string $str, int $max = 30): string
{
    return mb_strlen($str, 'UTF-8') > $max
        ? mb_substr($str, 0, $max, 'UTF-8') . '…'
        : $str;
}

/** Parse panel timestamps: unix seconds/ms or 'Y/m/d H:i:s' strings. */
function parse_panel_ts($raw): int
{
    if ($raw === null || $raw === '' || $raw === false) {
        return 0;
    }
    if (is_numeric($raw)) {
        $n = (int) $raw;
        if ($n > 1_000_000_000_000) {
            return (int) floor($n / 1000);
        }
        if ($n > 1_000_000) {
            return $n;
        }
        return 0;
    }
    $parsed = strtotime(str_replace('/', '-', trim((string) $raw)));
    return $parsed !== false ? $parsed : 0;
}

function safe_date($ts, string $fmt = 'Y/m/d'): string
{
    $n = parse_panel_ts($ts);
    if ($n < 1) {
        if ($ts === null || $ts === '' || $ts === false) {
            return '—';
        }
        if (!is_numeric($ts)) {
            return htmlspecialchars((string) $ts);
        }
        return '—';
    }
    return date($fmt, $n);
}
function check_login_rate(string $ip): bool
{
    $file = sys_get_temp_dir() . '/panel_login_' . md5($ip);
    $data = @json_decode(@file_get_contents($file) ?: '{}', true) ?: [];
    $now = time();
    $data = array_filter($data, fn($t) => ($now - $t) < 900);
    if (count($data) >= 10)
        return false;
    $data[] = $now;
    @file_put_contents($file, json_encode(array_values($data)), LOCK_EX);
    return true;
}

function clear_login_rate(string $ip): void
{
    @unlink(sys_get_temp_dir() . '/panel_login_' . md5($ip));
}

function user_role_label(string $agent): string
{
    global $textbotlang;
    return match ($agent) {
        'n' => $textbotlang['panel']['configRoleN'],
        'n2' => $textbotlang['panel']['configRoleN2'],
        'all' => $textbotlang['panel']['configRoleAll'],
        default => $textbotlang['panel']['configRoleDefault'],
    };
}

function user_role_tag(string $agent): string
{
    return match ($agent) {
        'f' => 'tag-info',
        'n' => 'tag-info',
        'n2' => 'tag-warn',
        'all' => 'tag-ok',
        default => 'tag-plain',
    };
}
