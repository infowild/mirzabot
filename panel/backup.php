<?php
/**
 * Web panel: download DB backup + restore from MirzaBot backup zip/sql.
 * - Never overwrites server config.php
 * - Takes a safety dump before restore; auto-rolls back on import failure
 * - Re-applies current panel admin row(s) so you are not locked out
 */
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

@set_time_limit(900);
@ini_set('max_execution_time', '900');
@ini_set('memory_limit', '512M');

$CONFIRM_PHRASE = 'تایید';
$MAX_UPLOAD_BYTES = 400 * 1024 * 1024; // 400 MB soft ceiling

function backup_find_bin(string $name): string
{
    foreach (["/usr/bin/{$name}", "/usr/local/bin/{$name}", "/bin/{$name}"] as $c) {
        if (is_file($c) && is_executable($c)) {
            return $c;
        }
    }
    $which = trim((string) @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
    return ($which !== '' && is_executable($which)) ? $which : '';
}

function backup_ssl_flag(string $bin): string
{
    $ver = (string) @shell_exec(escapeshellarg($bin) . ' --version 2>&1');
    return (stripos($ver, 'mariadb') !== false) ? '--skip-ssl' : '--ssl-mode=DISABLED';
}

function backup_tmp_dir(): string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mirza_backup_' . bin2hex(random_bytes(8));
    if (!@mkdir($base, 0700, true) && !is_dir($base)) {
        throw new RuntimeException('ساخت پوشه موقت ناموفق بود.');
    }
    return $base;
}

function backup_rm_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = @scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            backup_rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/** Create mysqldump of current DB into $sqlOut. Returns true on success. */
function backup_dump_db(string $sqlOut, string &$err = ''): bool
{
    global $dbhost, $dbname, $usernamedb, $passworddb;

    $mysqldump = backup_find_bin('mysqldump');
    if ($mysqldump === '') {
        $err = 'mysqldump روی سرور پیدا نشد.';
        return false;
    }
    $host = (isset($dbhost) && $dbhost !== '' && $dbhost !== '{database_url}') ? $dbhost : 'localhost';
    $ssl = backup_ssl_flag($mysqldump);
    $log = $sqlOut . '.log';

    putenv('MYSQL_PWD=' . $passworddb);
    $cmd = sprintf(
        '%s -h %s -u %s --no-tablespaces %s %s > %s 2> %s',
        escapeshellarg($mysqldump),
        escapeshellarg($host),
        escapeshellarg($usernamedb),
        $ssl,
        escapeshellarg($dbname),
        escapeshellarg($sqlOut),
        escapeshellarg($log)
    );
    $exit = 0;
    @exec($cmd, $out, $exit);
    putenv('MYSQL_PWD=');

    $ok = ($exit === 0 && is_file($sqlOut) && filesize($sqlOut) > 64);
    if (!$ok) {
        $err = 'mysqldump ناموفق بود.';
        if (is_file($log) && filesize($log) > 0) {
            $err .= ' ' . trim((string) @file_get_contents($log));
        }
        $err = trunc($err, 240);
    }
    @unlink($log);
    return $ok;
}

/** Import a .sql file into current DB via mysql client. */
function backup_import_sql(string $sqlFile, string &$err = ''): bool
{
    global $dbhost, $dbname, $usernamedb, $passworddb;

    $mysql = backup_find_bin('mysql');
    if ($mysql === '') {
        $err = 'کلاینت mysql روی سرور پیدا نشد.';
        return false;
    }
    $host = (isset($dbhost) && $dbhost !== '' && $dbhost !== '{database_url}') ? $dbhost : 'localhost';
    $ssl = backup_ssl_flag($mysql);
    $log = $sqlFile . '.import.log';

    putenv('MYSQL_PWD=' . $passworddb);
    $cmd = sprintf(
        '%s -h %s -u %s %s %s < %s 2> %s',
        escapeshellarg($mysql),
        escapeshellarg($host),
        escapeshellarg($usernamedb),
        $ssl,
        escapeshellarg($dbname),
        escapeshellarg($sqlFile),
        escapeshellarg($log)
    );
    $exit = 0;
    @exec($cmd, $out, $exit);
    putenv('MYSQL_PWD=');

    $ok = ($exit === 0);
    if (!$ok) {
        $err = 'ورود SQL ناموفق بود.';
        if (is_file($log) && filesize($log) > 0) {
            $err .= ' ' . trim((string) @file_get_contents($log));
        }
        $err = trunc($err, 280);
    }
    @unlink($log);
    return $ok;
}

function backup_looks_like_sql(string $path): bool
{
    if (!is_file($path) || filesize($path) < 64) {
        return false;
    }
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return false;
    }
    $head = (string) fread($fh, 4096);
    fclose($fh);
    // Reject obvious non-SQL / PHP webshells
    if (preg_match('/<\?php/i', $head)) {
        return false;
    }
    return (bool) preg_match(
        '/(?:^|\n)\s*(?:--|\/\*|CREATE\s+|INSERT\s+|DROP\s+|ALTER\s+|SET\s+|LOCK\s+|UNLOCK\s+|REPLACE\s+|USE\s+|mysqldump|MariaDB)/i',
        $head
    );
}

/**
 * Resolve uploaded zip/sql to a local .sql path inside $workDir.
 * Never uses config.php from the archive.
 */
function backup_extract_sql(string $uploadedPath, string $origName, string $workDir, string &$err = ''): ?string
{
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === 'sql') {
        $dest = $workDir . DIRECTORY_SEPARATOR . 'restore.sql';
        if (!@copy($uploadedPath, $dest)) {
            $err = 'کپی فایل SQL ناموفق بود.';
            return null;
        }
        if (!backup_looks_like_sql($dest)) {
            $err = 'محتوای فایل شبیه بکاپ SQL معتبر نیست.';
            return null;
        }
        return $dest;
    }

    if ($ext !== 'zip') {
        $err = 'فقط فایل‌های .zip یا .sql پذیرفته می‌شوند.';
        return null;
    }

    if (!class_exists('ZipArchive')) {
        $err = 'افزونه ZipArchive روی PHP فعال نیست.';
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($uploadedPath) !== true) {
        $err = 'باز کردن فایل zip ناموفق بود.';
        return null;
    }

    $candidates = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) {
            continue;
        }
        // Zip-slip protection
        $norm = str_replace('\\', '/', $name);
        if ($norm === '' || str_contains($norm, '..') || str_starts_with($norm, '/') || str_ends_with($norm, '/')) {
            continue;
        }
        $base = basename($norm);
        // Ignore config.php and anything that is not .sql
        if (strcasecmp($base, 'config.php') === 0) {
            continue;
        }
        if (!preg_match('/\.sql$/i', $base)) {
            continue;
        }
        $stat = $zip->statIndex($i);
        $candidates[] = ['index' => $i, 'name' => $norm, 'size' => (int) ($stat['size'] ?? 0)];
    }

    if (!$candidates) {
        $zip->close();
        $err = 'داخل zip هیچ فایل .sql پیدا نشد.';
        return null;
    }

    usort($candidates, fn($a, $b) => $b['size'] <=> $a['size']);
    $pick = $candidates[0];
    $dest = $workDir . DIRECTORY_SEPARATOR . 'restore.sql';
    $stream = $zip->getStream($pick['name']);
    if ($stream === false) {
        $zip->close();
        $err = 'خواندن SQL از zip ناموفق بود.';
        return null;
    }
    $out = @fopen($dest, 'wb');
    if (!$out) {
        fclose($stream);
        $zip->close();
        $err = 'نوشتن فایل موقت ناموفق بود.';
        return null;
    }
    while (!feof($stream)) {
        $chunk = fread($stream, 1024 * 1024);
        if ($chunk === false) {
            break;
        }
        fwrite($out, $chunk);
    }
    fclose($out);
    fclose($stream);
    $zip->close();

    if (!backup_looks_like_sql($dest)) {
        $err = 'فایل SQL داخل zip معتبر به نظر نمی‌رسد.';
        return null;
    }
    return $dest;
}

/** Snapshot current admin accounts so panel login survives restore. */
function backup_snapshot_admins(PDO $pdo): array
{
    try {
        return db_fetchAll($pdo, 'SELECT id_admin, rule, username, password FROM admin');
    } catch (Exception $e) {
        return [];
    }
}

function backup_restore_admins(PDO $pdo, array $admins): void
{
    if (!$admins) {
        return;
    }
    foreach ($admins as $a) {
        $id = (string) ($a['id_admin'] ?? '');
        $user = (string) ($a['username'] ?? '');
        if ($id === '' && $user === '') {
            continue;
        }
        try {
            // Prefer match by username (panel session key)
            if ($user !== '') {
                $exists = db_fetch($pdo, 'SELECT id_admin FROM admin WHERE username = ? LIMIT 1', [$user]);
                if ($exists) {
                    db_query(
                        $pdo,
                        'UPDATE admin SET password = ?, rule = COALESCE(NULLIF(?, ""), rule), id_admin = COALESCE(NULLIF(?, ""), id_admin) WHERE username = ?',
                        [
                            (string) ($a['password'] ?? ''),
                            (string) ($a['rule'] ?? ''),
                            $id,
                            $user,
                        ]
                    );
                    continue;
                }
            }
            if ($id !== '') {
                $existsId = db_fetch($pdo, 'SELECT id_admin FROM admin WHERE id_admin = ? LIMIT 1', [$id]);
                if ($existsId) {
                    db_query(
                        $pdo,
                        'UPDATE admin SET username = ?, password = ?, rule = ? WHERE id_admin = ?',
                        [
                            $user !== '' ? $user : ($existsId['username'] ?? 'admin'),
                            (string) ($a['password'] ?? ''),
                            (string) ($a['rule'] ?? 'administrator'),
                            $id,
                        ]
                    );
                    continue;
                }
            }
            db_query(
                $pdo,
                'INSERT INTO admin (id_admin, rule, username, password) VALUES (?, ?, ?, ?)',
                [
                    $id !== '' ? $id : ('panel_' . bin2hex(random_bytes(4))),
                    (string) ($a['rule'] ?? 'administrator'),
                    $user !== '' ? $user : 'admin',
                    (string) ($a['password'] ?? ''),
                ]
            );
        } catch (Exception $e) {
            error_log('backup.php restore admin failed: ' . $e->getMessage());
        }
    }
}

function backup_reconnect_pdo(): PDO
{
    global $dbhost, $dbname, $usernamedb, $passworddb, $options, $pdo;
    $host = (isset($dbhost) && $dbhost !== '' && $dbhost !== '{database_url}') ? $dbhost : 'localhost';
    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    $opts = $options ?? [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pdo = new PDO($dsn, $usernamedb, $passworddb, $opts);
    return $pdo;
}

function backup_php_upload_limit(): int
{
    $parse = static function (string $v): int {
        $v = trim($v);
        if ($v === '') {
            return 0;
        }
        $u = strtolower(substr($v, -1));
        $n = (float) $v;
        return (int) match ($u) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => (float) $v,
        };
    };
    $candidates = [
        $parse((string) ini_get('upload_max_filesize')),
        $parse((string) ini_get('post_max_size')),
    ];
    $candidates = array_filter($candidates, fn($x) => $x > 0);
    return $candidates ? (int) min($candidates) : 0;
}

// ── POST actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $action = $_POST['action'] ?? '';

    if ($action === 'download_backup') {
        $work = null;
        try {
            $work = backup_tmp_dir();
            $sqlFile = $work . DIRECTORY_SEPARATOR . 'backup_' . date('Y-m-d_His') . '.sql';
            $err = '';
            if (!backup_dump_db($sqlFile, $err)) {
                flash('error', 'دانلود بکاپ ناموفق: ' . $err);
                header('Location: backup.php');
                exit;
            }
            $zipPath = $work . DIRECTORY_SEPARATOR . 'backup_' . date('Y-m-d_His') . '.zip';
            $downloadName = 'mirzabot_backup_' . date('Y-m-d_His') . '.zip';
            $sendPath = $sqlFile;
            $sendMime = 'application/sql';
            $sendName = 'mirzabot_backup_' . date('Y-m-d_His') . '.sql';

            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
                    $zip->addFile($sqlFile, basename($sqlFile));
                    $zip->close();
                    if (is_file($zipPath) && filesize($zipPath) > 0) {
                        $sendPath = $zipPath;
                        $sendMime = 'application/zip';
                        $sendName = $downloadName;
                    }
                }
            }

            // Stream then cleanup
            header('Content-Type: ' . $sendMime);
            header('Content-Disposition: attachment; filename="' . $sendName . '"');
            header('Content-Length: ' . (string) filesize($sendPath));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            readfile($sendPath);
            if ($work) {
                backup_rm_tree($work);
            }
            exit;
        } catch (Throwable $e) {
            if ($work) {
                backup_rm_tree($work);
            }
            flash('error', 'خطا در ساخت بکاپ: ' . trunc($e->getMessage(), 160));
            header('Location: backup.php');
            exit;
        }
    }

    if ($action === 'restore_backup') {
        $work = null;
        $safetySql = null;
        try {
            $confirm = trim((string) ($_POST['confirm_phrase'] ?? ''));
            if ($confirm !== $CONFIRM_PHRASE) {
                flash('error', 'عبارت تأیید نادرست است. دقیقاً بنویسید: ' . $CONFIRM_PHRASE);
                header('Location: backup.php');
                exit;
            }
            if (empty($_POST['accept_risk'])) {
                flash('error', 'برای ادامه باید هشدار را تأیید کنید.');
                header('Location: backup.php');
                exit;
            }
            if (!isset($_FILES['backup_file']) || !is_array($_FILES['backup_file'])) {
                flash('error', 'فایلی آپلود نشده است.');
                header('Location: backup.php');
                exit;
            }

            $file = $_FILES['backup_file'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $code = (int) ($file['error'] ?? -1);
                $msg = match ($code) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم فایل از حد مجاز PHP بیشتر است.',
                    UPLOAD_ERR_PARTIAL => 'آپلود ناقص بود؛ دوباره تلاش کنید.',
                    UPLOAD_ERR_NO_FILE => 'فایلی انتخاب نشده است.',
                    default => 'خطای آپلود (کد ' . $code . ').',
                };
                flash('error', $msg);
                header('Location: backup.php');
                exit;
            }

            $origName = (string) ($file['name'] ?? 'backup');
            $tmpName = (string) ($file['tmp_name'] ?? '');
            $size = (int) ($file['size'] ?? 0);
            $phpLimit = backup_php_upload_limit();
            $hardLimit = $MAX_UPLOAD_BYTES;
            if ($phpLimit > 0) {
                $hardLimit = min($hardLimit, $phpLimit);
            }
            if ($size <= 0 || $size > $hardLimit) {
                flash('error', 'حجم فایل نامعتبر یا بیش از حد مجاز است (حداکثر حدود ' . round($hardLimit / 1048576) . ' مگابایت).');
                header('Location: backup.php');
                exit;
            }
            if (!is_uploaded_file($tmpName)) {
                flash('error', 'فایل آپلود معتبر نیست.');
                header('Location: backup.php');
                exit;
            }

            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['zip', 'sql'], true)) {
                flash('error', 'فقط .zip یا .sql مجاز است.');
                header('Location: backup.php');
                exit;
            }

            // Need mysql + mysqldump before touching DB
            if (backup_find_bin('mysql') === '') {
                flash('error', 'کلاینت mysql روی سرور نیست؛ بازیابی ممکن نیست.');
                header('Location: backup.php');
                exit;
            }
            if (backup_find_bin('mysqldump') === '') {
                flash('error', 'mysqldump روی سرور نیست؛ بکاپ ایمنی قبل از بازیابی ساخته نمی‌شود.');
                header('Location: backup.php');
                exit;
            }

            $work = backup_tmp_dir();
            $extractErr = '';
            $sqlPath = backup_extract_sql($tmpName, $origName, $work, $extractErr);
            if ($sqlPath === null) {
                flash('error', $extractErr ?: 'استخراج بکاپ ناموفق بود.');
                backup_rm_tree($work);
                header('Location: backup.php');
                exit;
            }

            // Snapshot panel admins (current login)
            $adminSnap = backup_snapshot_admins($pdo);

            // Safety dump of CURRENT database
            $safetySql = $work . DIRECTORY_SEPARATOR . 'safety_before_restore.sql';
            $dumpErr = '';
            if (!backup_dump_db($safetySql, $dumpErr)) {
                flash('error', 'بکاپ ایمنی قبل از بازیابی ساخته نشد: ' . $dumpErr);
                backup_rm_tree($work);
                header('Location: backup.php');
                exit;
            }

            // Import uploaded dump
            $importErr = '';
            if (!backup_import_sql($sqlPath, $importErr)) {
                // Auto-rollback
                $rbErr = '';
                $rolled = backup_import_sql($safetySql, $rbErr);
                backup_rm_tree($work);
                if ($rolled) {
                    flash('error', 'بازیابی ناموفق بود و دیتابیس به حالت قبل برگشت. جزئیات: ' . $importErr);
                } else {
                    flash('error', 'بازیابی ناموفق بود و برگشت خودکار هم شکست خورد. فوری از بکاپ کانال تلگرام دستی restore کنید. خطا: ' . $importErr . ' | rollback: ' . $rbErr);
                }
                header('Location: backup.php');
                exit;
            }

            // Reconnect + re-apply current panel admins
            try {
                $pdo = backup_reconnect_pdo();
                backup_restore_admins($pdo, $adminSnap);
            } catch (Throwable $e) {
                error_log('backup.php post-restore admin/reconnect: ' . $e->getMessage());
            }

            error_log(sprintf(
                '[panel backup] restore OK by=%s ip=%s file=%s size=%d',
                $_SESSION['admin_user'] ?? '?',
                $_SERVER['REMOTE_ADDR'] ?? '?',
                $origName,
                $size
            ));

            backup_rm_tree($work);
            flash('success', 'بازیابی با موفقیت انجام شد. تنظیمات فعلی ورود پنل حفظ شده است. در صورت نیاز وب‌هوک/کرون را بررسی کنید.');
            header('Location: backup.php');
            exit;
        } catch (Throwable $e) {
            if ($work && $safetySql && is_file($safetySql)) {
                $rbErr = '';
                backup_import_sql($safetySql, $rbErr);
            }
            if ($work) {
                backup_rm_tree($work);
            }
            flash('error', 'خطای غیرمنتظره در بازیابی: ' . trunc($e->getMessage(), 180));
            header('Location: backup.php');
            exit;
        }
    }

    flash('error', 'درخواست نامعتبر.');
    header('Location: backup.php');
    exit;
}

$phpLimit = backup_php_upload_limit();
$effectiveLimit = $MAX_UPLOAD_BYTES;
if ($phpLimit > 0) {
    $effectiveLimit = min($effectiveLimit, $phpLimit);
}
$hasMysql = backup_find_bin('mysql') !== '';
$hasDump = backup_find_bin('mysqldump') !== '';
$hasZip = class_exists('ZipArchive');

$pageTitle = 'بکاپ و بازیابی';
$activeNav = 'backup';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<div class="welcome-bar fade-up" style="margin-bottom:16px">
  <div>
    <div style="font-size:1.1rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:8px">
      <?= icon('package', 16) ?>&nbsp;بکاپ و بازیابی
    </div>
    <div style="font-size:.75rem;color:var(--mute);margin-top:3px">
      دانلود بکاپ فعلی دیتابیس یا بازیابی از فایل zip/sql ربات
    </div>
  </div>
</div>

<div class="fin-note fade-up" style="font-size:.72rem;color:var(--mute);background:var(--sf2);border:1px solid var(--bd);border-radius:10px;padding:10px 14px;margin-bottom:16px;line-height:1.7">
  فایل بکاپ ربات معمولاً zip شامل <strong>.sql</strong> و گاهی <strong>config.php</strong> است.
  این صفحه فقط SQL را بازیابی می‌کند و <strong>هرگز config.php سرور را عوض نمی‌کند</strong>
  (توکن، دامنه و پسورد دیتابیس فعلی حفظ می‌شود).
  قبل از بازیابی یک بکاپ ایمنی گرفته می‌شود؛ اگر import خراب شود، برگشت خودکار انجام می‌شود.
  حساب ورود همین پنل بعد از بازیابی حفظ می‌شود.
</div>

<div class="fin-grid two fade-up" style="display:grid;gap:16px;grid-template-columns:repeat(2,1fr);margin-bottom:16px">
  <div class="fin-stat" style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:16px 18px">
    <div style="font-size:.72rem;color:var(--mute);font-weight:600;margin-bottom:6px">ابزار سرور</div>
    <div style="display:flex;flex-wrap:wrap;gap:6px">
      <span class="tag <?= $hasDump ? 'tag-ok' : 'tag-no' ?>">mysqldump <?= $hasDump ? '✓' : '✗' ?></span>
      <span class="tag <?= $hasMysql ? 'tag-ok' : 'tag-no' ?>">mysql <?= $hasMysql ? '✓' : '✗' ?></span>
      <span class="tag <?= $hasZip ? 'tag-ok' : 'tag-warn' ?>">ZipArchive <?= $hasZip ? '✓' : '✗' ?></span>
    </div>
  </div>
  <div class="fin-stat" style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:16px 18px">
    <div style="font-size:.72rem;color:var(--mute);font-weight:600;margin-bottom:6px">حداکثر حجم آپلود</div>
    <div style="font-size:1.2rem;font-weight:800"><?= round($effectiveLimit / 1048576) ?> <small style="font-size:.75rem;color:var(--mute)">مگابایت</small></div>
    <div style="font-size:.72rem;color:var(--dim);margin-top:4px">بر اساس محدودیت PHP سرور</div>
  </div>
</div>

<style>
@media(max-width:700px){.fin-grid.two{grid-template-columns:1fr!important}}
</style>

<div class="two-col fade-up">
  <!-- Download -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">دانلود بکاپ فعلی</div>
        <div class="card-subtitle">خروجی mysqldump همین دیتابیس زنده</div>
      </div>
    </div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:12px">
      <p style="font-size:.8rem;color:var(--mute);line-height:1.7;margin:0">
        قبل از هر بازیابی، یک نسخه از وضعیت فعلی بگیرید و جای امن نگه دارید.
      </p>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="download_backup">
        <button type="submit" class="btn btn-primary" <?= $hasDump ? '' : 'disabled' ?> style="justify-content:center;width:100%">
          <?= icon('invoice', 14) ?>&nbsp;دانلود بکاپ دیتابیس
        </button>
      </form>
      <?php if (!$hasDump): ?>
        <div style="font-size:.75rem;color:var(--no)">mysqldump روی این سرور در دسترس نیست.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Restore -->
  <div class="card" style="border-color:color-mix(in srgb,var(--no) 28%,var(--bd))">
    <div class="card-head">
      <div>
        <div class="card-title">بازیابی از فایل</div>
        <div class="card-subtitle">آپلود zip بکاپ ربات یا فایل .sql</div>
      </div>
    </div>
    <form method="POST" enctype="multipart/form-data" class="card-body" style="display:flex;flex-direction:column;gap:14px"
          onsubmit="return confirmRestore(this)">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="restore_backup">

      <div class="field">
        <label>فایل بکاپ (.zip یا .sql)</label>
        <input type="file" name="backup_file" class="input" accept=".zip,.sql,application/zip,application/sql,text/plain" required
               <?= ($hasMysql && $hasDump) ? '' : 'disabled' ?>>
      </div>

      <div class="field">
        <label>عبارت تأیید (بنویسید: <b dir="rtl"><?= htmlspecialchars($CONFIRM_PHRASE) ?></b>)</label>
        <input type="text" name="confirm_phrase" class="input" autocomplete="off" placeholder="<?= htmlspecialchars($CONFIRM_PHRASE) ?>" required
               <?= ($hasMysql && $hasDump) ? '' : 'disabled' ?>>
      </div>

      <label style="display:flex;gap:8px;align-items:flex-start;font-size:.78rem;color:var(--mute);line-height:1.6;cursor:pointer">
        <input type="checkbox" name="accept_risk" value="1" style="margin-top:3px" required
               <?= ($hasMysql && $hasDump) ? '' : 'disabled' ?>>
        <span>می‌دانم این کار داده‌های فعلی دیتابیس را با بکاپ جایگزین می‌کند (به‌جز حفظ ورود پنل و فایل config.php سرور).</span>
      </label>

      <button type="submit" class="btn btn-no" <?= ($hasMysql && $hasDump) ? '' : 'disabled' ?> style="justify-content:center;width:100%">
        <?= icon('check', 14) ?>&nbsp;شروع بازیابی
      </button>

      <?php if (!$hasMysql || !$hasDump): ?>
        <div style="font-size:.75rem;color:var(--no)">برای بازیابی امن، هم mysql و هم mysqldump لازم است.</div>
      <?php endif; ?>
    </form>
  </div>
</div>

<script>
function confirmRestore(form) {
  var phrase = (form.confirm_phrase.value || '').trim();
  if (phrase !== <?= json_encode($CONFIRM_PHRASE, JSON_UNESCAPED_UNICODE) ?>) {
    alert('عبارت تأیید را درست وارد کنید.');
    return false;
  }
  return window.confirm('آیا از بازیابی دیتابیس مطمئن هستید؟ این عملیات برگشت‌پذیر نیست مگر آنکه بکاپ ایمنی داخلی موفق باشد.');
}
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
