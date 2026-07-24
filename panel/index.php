<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

$totalUsers = $newToday = $blockedUsers = 0;
$totalRevenue = $todayRevenue = 0;
$activeNow = $pendingPay = $txToday = $expiredServices = 0;
$totalPanels = 0;
$weeklyRevenue = $panelsList = $recentInvoices = $recentUsers = [];

$TEST_NAME = $textbotlang['Admin']['adminphp']['db_test_service_name'] ?? 'سرویس تست';
$NOT_TEST  = 'name_product != ' . $pdo->quote($TEST_NAME);
$PAID = "Status IN ('active','end_of_time','end_of_volume','sendedwarn','send_on_hold','removeTime','removevolume') AND $NOT_TEST";
$todayUnix = strtotime('today');
$todayPayStart = date('Y/m/d') . ' 00:00:00';

try {
    $totalUsers   = db_count($pdo, "SELECT COUNT(*) FROM user");
    $newToday     = db_count($pdo, "SELECT COUNT(*) FROM user WHERE register > ?", [$todayUnix]);
    $blockedUsers = db_count($pdo, "SELECT COUNT(*) FROM user WHERE User_Status='block'");
} catch (Exception $e) {}

try {
    $totalRevenue    = (int) db_query($pdo, "SELECT COALESCE(SUM(CAST(price_product AS SIGNED)),0) FROM invoice WHERE $PAID")->fetchColumn();
    $todayRevenue    = (int) db_query($pdo, "SELECT COALESCE(SUM(CAST(price_product AS SIGNED)),0) FROM invoice WHERE CAST(time_sell AS UNSIGNED) > ? AND $PAID", [$todayUnix])->fetchColumn();
    $activeNow       = db_count($pdo, "SELECT COUNT(*) FROM invoice WHERE Status='active' AND $NOT_TEST");
    $expiredServices = db_count($pdo, "SELECT COUNT(*) FROM invoice WHERE Status IN ('end_of_time','end_of_volume') AND $NOT_TEST");
} catch (Exception $e) {}

try {
    $pendingPay = db_count($pdo, "SELECT COUNT(*) FROM Payment_report WHERE payment_Status='waiting'");
    // Payment_report.time is stored as 'Y/m/d H:i:s' string — not unix
    $txToday    = db_count($pdo, "SELECT COUNT(*) FROM Payment_report WHERE time >= ?", [$todayPayStart]);
} catch (Exception $e) {}

try { $totalPanels = db_count($pdo, "SELECT COUNT(*) FROM marzban_panel"); } catch (Exception $e) {}

try {
    for ($i = 6; $i >= 0; $i--) {
        $ds = mktime(0, 0, 0, (int)date('n'), (int)date('j') - $i, (int)date('Y'));
        $de = $ds + 86399;
        $weeklyRevenue[] = [
            'label' => jdate('j/n', $ds),
            'rev'   => (int) db_query($pdo,
                "SELECT COALESCE(SUM(price_product),0) FROM invoice WHERE time_sell BETWEEN ? AND ? AND $PAID",
                [$ds, $de]
            )->fetchColumn(),
            'today' => ($i === 0),
        ];
    }
} catch (Exception $e) {
    for ($i = 0; $i < 7; $i++) {
        $ds = mktime(0, 0, 0, (int)date('n'), (int)date('j') - (6 - $i), (int)date('Y'));
        $weeklyRevenue[] = ['label' => jdate('j/n', $ds), 'rev' => 0, 'today' => ($i === 6)];
    }
}

try { $panelsList     = db_fetchAll($pdo, "SELECT id, name_panel, url_panel, type FROM marzban_panel ORDER BY id ASC LIMIT 12"); } catch (Exception $e) {}
try { $recentInvoices = db_fetchAll($pdo, "SELECT * FROM invoice ORDER BY time_sell DESC LIMIT 8"); } catch (Exception $e) {}
try { $recentUsers    = db_fetchAll($pdo, "SELECT * FROM user ORDER BY register DESC LIMIT 8"); } catch (Exception $e) {}

$pageTitle    = $textbotlang['panel']['dashboardTitle'];
$activeNav    = 'dashboard';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';

$maxW      = max(array_merge(array_column($weeklyRevenue, 'rev'), [1]));
$weekTotal = array_sum(array_column($weeklyRevenue, 'rev'));
$adminName = $_SESSION['admin_user'] ?? ($textbotlang['panel']['layoutDefaultAdminName'] ?? 'ادمین');

$hour = (int)date('H');
if ($hour < 6)       $greeting = 'شب بخیر';
elseif ($hour < 12)  $greeting = 'صبح بخیر';
elseif ($hour < 18)  $greeting = 'روز بخیر';
else                 $greeting = 'عصر بخیر';

function fmtM(int $v): string {
    if ($v >= 1_000_000) return number_format($v / 1_000_000, 1) . '<small>م&nbsp;ت</small>';
    return number_format($v) . '<small>ت</small>';
}
function fmtS(int $v): string {
    if ($v >= 1_000_000) return number_format($v / 1_000_000, 1) . 'م ت';
    if ($v >= 1_000)     return number_format((int)($v / 1_000)) . 'ک ت';
    return number_format($v) . ' ت';
}
?>

<!-- ══════════════════════════════════════════════
     Welcome bar
═══════════════════════════════════════════════ -->
<div class="welcome-bar fade-up">
  <div style="display:flex;flex-direction:column;gap:5px;min-width:0">
    <div style="font-size:1.2rem;font-weight:800;color:var(--text);letter-spacing:-.025em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
      <?= $greeting ?>، <span style="color:var(--ac)"><?= htmlspecialchars($adminName) ?></span>&nbsp;👋
    </div>
    <div style="font-size:.78rem;color:var(--mute);display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <span><?= jdate('Y/m/d') ?></span>
      <span style="opacity:.4">·</span>
      <span>میرزا بات | پنل مدیریت</span>
      <span style="opacity:.4">·</span>
      <span id="dash-clock"><?= date('H:i') ?></span>
    </div>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-shrink:0;flex-wrap:wrap">
    <span class="tag tag-ok" style="animation:pulse 3s ease-in-out infinite">سیستم فعال</span>
    <?php if ($pendingPay > 0): ?>
      <a href="payment.php" class="tag tag-no" style="cursor:pointer;text-decoration:none">
        <?= icon('card', 11) ?>&nbsp;<?= $pendingPay ?> پرداخت منتظر
      </a>
    <?php endif; ?>
  </div>
</div>
<script>
  (function() {
    var el = document.getElementById('dash-clock');
    if (!el) return;
    var fmt = new Intl.DateTimeFormat('en-US', {
      timeZone: 'Asia/Tehran',
      hour: '2-digit', minute: '2-digit', hour12: false
    });
    function tick() { el.textContent = fmt.format(new Date()); }
    tick();
    setInterval(tick, 10000);
  }());
</script>

<!-- ══════════════════════════════════════════════
     Row 1 — Primary Stats
═══════════════════════════════════════════════ -->
<div class="stats fade-up">

  <div class="stat">
    <div class="stat-top">
      <div class="stat-label"><?= $textbotlang['panel']['dashTotalUsers'] ?></div>
      <div class="stat-ico"><?= icon('users', 15) ?></div>
    </div>
    <div class="stat-num"><?= number_format($totalUsers) ?></div>
    <div class="stat-meta">
      <?= $newToday > 0
        ? '<span class="up">+' . $newToday . '</span>&nbsp;' . ($textbotlang['panel']['dashTodaySpan'] ?? 'امروز')
        : '<span style="color:var(--dim)">' . ($textbotlang['panel']['dashNoChange'] ?? 'بدون تغییر امروز') . '</span>' ?>
    </div>
  </div>

  <div class="stat ok">
    <div class="stat-top">
      <div class="stat-label"><?= $textbotlang['panel']['dashTotalRevenue'] ?></div>
      <div class="stat-ico"><?= icon('wallet', 15) ?></div>
    </div>
    <div class="stat-num"><?= fmtM($totalRevenue) ?></div>
    <div class="stat-meta">
      امروز:&nbsp;<strong style="color:var(--text)"><?= fmtS($todayRevenue) ?></strong>
    </div>
  </div>

  <div class="stat warn">
    <div class="stat-top">
      <div class="stat-label"><?= $textbotlang['panel']['dashActiveService'] ?></div>
      <div class="stat-ico"><?= icon('server', 15) ?></div>
    </div>
    <div class="stat-num"><?= number_format($activeNow) ?></div>
    <div class="stat-meta">
      <?= $expiredServices > 0
        ? '<span class="dn">' . number_format($expiredServices) . ' منقضی</span>'
        : '<span style="color:var(--dim)">بدون انقضا</span>' ?>
    </div>
  </div>

  <div class="stat <?= $pendingPay > 0 ? 'no' : '' ?>">
    <div class="stat-top">
      <div class="stat-label">
        <?= $pendingPay > 0
          ? ($textbotlang['panel']['dashPendingPayment'] ?? 'در انتظار تأیید')
          : ($textbotlang['panel']['dashTodayTransaction'] ?? 'تراکنش امروز') ?>
      </div>
      <div class="stat-ico"><?= icon('card', 15) ?></div>
    </div>
    <div class="stat-num" style="<?= $pendingPay > 0 ? 'color:var(--no)' : '' ?>">
      <?= number_format($pendingPay > 0 ? $pendingPay : $txToday) ?>
    </div>
    <div class="stat-meta">
      <?= $pendingPay > 0
        ? '<a href="payment.php" style="color:var(--no);font-weight:700">' . ($textbotlang['panel']['dashReviewLink'] ?? 'بررسی') . '&nbsp;→</a>'
        : '<span style="color:var(--dim)">' . ($textbotlang['panel']['dashStatusRegistered'] ?? 'ثبت‌شده') . '</span>' ?>
    </div>
  </div>

</div>

<!-- ══════════════════════════════════════════════
     Row 2 — Secondary Stats
═══════════════════════════════════════════════ -->
<div class="stats fade-up" style="margin-top:-10px">

  <div class="stat">
    <div class="stat-top">
      <div class="stat-label">جدید امروز</div>
      <div class="stat-ico"><?= icon('plus', 15) ?></div>
    </div>
    <div class="stat-num" style="color:var(--ac)"><?= number_format($newToday) ?></div>
    <div class="stat-meta"><span style="color:var(--dim)">کاربر ثبت‌نام‌کرده</span></div>
  </div>

  <div class="stat <?= $blockedUsers > 0 ? 'no' : '' ?>">
    <div class="stat-top">
      <div class="stat-label">کاربران مسدود</div>
      <div class="stat-ico"><?= icon('block', 15) ?></div>
    </div>
    <div class="stat-num" style="<?= $blockedUsers > 0 ? 'color:var(--no)' : '' ?>"><?= number_format($blockedUsers) ?></div>
    <div class="stat-meta">
      <?= $blockedUsers > 0
        ? '<a href="users.php" style="color:var(--no);font-weight:700">مشاهده&nbsp;→</a>'
        : '<span style="color:var(--ok)">همه کاربران فعال</span>' ?>
    </div>
  </div>

  <div class="stat">
    <div class="stat-top">
      <div class="stat-label">پنل‌های متصل</div>
      <div class="stat-ico"><?= icon('dashboard', 15) ?></div>
    </div>
    <div class="stat-num" style="color:var(--ac)"><?= number_format($totalPanels) ?></div>
    <div class="stat-meta"><span style="color:var(--dim)">پنل پیکربندی‌شده</span></div>
  </div>

  <div class="stat <?= $expiredServices > 0 ? 'warn' : '' ?>">
    <div class="stat-top">
      <div class="stat-label">سرویس منقضی</div>
      <div class="stat-ico"><?= icon('invoice', 15) ?></div>
    </div>
    <div class="stat-num" style="<?= $expiredServices > 0 ? 'color:var(--warn)' : '' ?>"><?= number_format($expiredServices) ?></div>
    <div class="stat-meta">
      <?= $expiredServices > 0
        ? '<a href="invoice.php" style="color:var(--warn);font-weight:700">مشاهده&nbsp;→</a>'
        : '<span style="color:var(--ok)">بدون انقضا</span>' ?>
    </div>
  </div>

</div>

<!-- ══════════════════════════════════════════════
     7-day Revenue Chart
═══════════════════════════════════════════════ -->
<div class="card fade-up" style="margin-bottom:20px">
  <div class="card-head">
    <div>
      <div class="card-title" style="display:flex;align-items:center;gap:8px">
        <?= icon('chart', 16) ?>&nbsp;روند درآمد ۷ روز اخیر
      </div>
      <div class="card-subtitle">
        مجموع هفتگی:&nbsp;
        <strong style="color:var(--text)">
          <?= $weekTotal >= 1_000_000
            ? number_format($weekTotal / 1_000_000, 1) . ' میلیون تومان'
            : number_format($weekTotal) . ' تومان' ?>
        </strong>
      </div>
    </div>
    <span class="tag tag-info">هفته جاری</span>
  </div>
  <div class="card-body" style="padding-top:18px;padding-bottom:10px">
    <!-- Bar chart -->
    <div style="display:flex;align-items:flex-end;gap:8px;height:90px">
      <?php foreach ($weeklyRevenue as $day):
        $pct  = $maxW > 0 ? ($day['rev'] / $maxW * 100) : 0;
        $barH = max(5, (int)round($pct));
        $isT  = $day['today'];
      ?>
      <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;gap:0">
        <?php if ($day['rev'] > 0): ?>
          <div style="font-size:.57rem;color:<?= $isT ? 'var(--ac)' : 'var(--mute)' ?>;margin-bottom:4px;white-space:nowrap;font-weight:<?= $isT ? '700' : '400' ?>;letter-spacing:-.01em">
            <?= $day['rev'] >= 1_000_000
              ? number_format($day['rev'] / 1_000_000, 1) . 'م'
              : number_format((int)($day['rev'] / 1_000)) . 'ک' ?>
          </div>
        <?php else: ?>
          <div style="margin-bottom:4px;height:13px"></div>
        <?php endif; ?>
        <div style="
          width:100%;
          border-radius:6px 6px 0 0;
          height:<?= $barH ?>%;
          min-height:5px;
          background:<?= $isT ? 'var(--ac)' : 'var(--sf3)' ?>;
          box-shadow:<?= $isT ? '0 0 16px var(--acg)' : 'none' ?>;
          transition:height .45s cubic-bezier(.4,0,.2,1);
          position:relative;
          overflow:hidden
        ">
          <?php if ($isT): ?>
            <div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(255,255,255,.18) 0%,transparent 60%);border-radius:inherit"></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <!-- Day labels -->
    <div style="display:flex;gap:8px;margin-top:8px;border-top:1px solid var(--bd);padding-top:8px">
      <?php foreach ($weeklyRevenue as $day): $isT = $day['today']; ?>
        <div style="
          flex:1;
          text-align:center;
          font-size:.62rem;
          font-weight:<?= $isT ? '700' : '400' ?>;
          color:<?= $isT ? 'var(--ac)' : 'var(--dim)' ?>;
          letter-spacing:-.01em
        ">
          <?= $isT ? 'امروز' : $day['label'] ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════
     Panel Monitoring
═══════════════════════════════════════════════ -->
<?php if (!empty($panelsList)): ?>
<div class="card fade-up" style="margin-bottom:20px">
  <div class="card-head">
    <div>
      <div class="card-title" style="display:flex;align-items:center;gap:8px">
        <?= icon('server', 15) ?>&nbsp;پنل‌های متصل
      </div>
      <div class="card-subtitle"><?= count($panelsList) ?> پنل پیکربندی‌شده</div>
    </div>
    <a href="settings.php" class="btn btn-ghost btn-sm">
      <?= icon('settings', 13) ?>&nbsp;مدیریت
    </a>
  </div>
  <div class="tbl-wrap">
    <table class="tbl-md">
      <thead>
        <tr>
          <th>#</th>
          <th>نام پنل</th>
          <th>نوع</th>
          <th>آدرس</th>
          <th>وضعیت</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $typeMap = [
            'x-ui_single' => ['3X-UI',  'tag-info'],
            'marzban'     => ['Marzban','tag-ok'],
            'Manualsale'  => ['دستی',   'tag-plain'],
        ];
        foreach ($panelsList as $p):
            $hasUrl = !empty($p['url_panel']) && $p['url_panel'] !== 'none' && $p['url_panel'] !== '';
            [$typeLabel, $typeClass] = $typeMap[$p['type'] ?? ''] ?? [htmlspecialchars($p['type'] ?? '—'), 'tag-plain'];
        ?>
        <tr>
          <td class="cm cf" style="width:40px"><?= (int)$p['id'] ?></td>
          <td class="cs"><?= htmlspecialchars(trunc($p['name_panel'] ?? '—', 26)) ?></td>
          <td><span class="tag <?= $typeClass ?>"><?= $typeLabel ?></span></td>
          <td class="url-cell cm cf">
            <?= $hasUrl
              ? htmlspecialchars(trunc($p['url_panel'], 34))
              : '<span style="color:var(--dim)">—</span>' ?>
          </td>
          <td>
            <span class="tag <?= $hasUrl ? 'tag-ok' : 'tag-warn' ?>">
              <?= icon($hasUrl ? 'check' : 'block', 10) ?>&nbsp;<?= $hasUrl ? 'فعال' : 'ناقص' ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════
     Recent Orders + Recent Users
═══════════════════════════════════════════════ -->
<div class="two-col">

  <!-- Recent Orders -->
  <div class="card fade-up d1">
    <div class="card-head">
      <div>
        <div class="card-title" style="display:flex;align-items:center;gap:7px">
          <?= icon('invoice', 15) ?>&nbsp;<?= $textbotlang['panel']['dashRecentOrders'] ?>
        </div>
        <div class="card-subtitle"><?= count($recentInvoices) ?>&nbsp;<?= $textbotlang['panel']['dashRecentItem'] ?></div>
      </div>
      <a href="invoice.php" class="btn-link" style="font-size:.78rem"><?= $textbotlang['panel']['dashViewAll'] ?>&nbsp;→</a>
    </div>
    <div class="tbl-wrap">
      <table class="tbl-sm">
        <thead>
          <tr>
            <th><?= $textbotlang['panel']['dashColUser'] ?></th>
            <th><?= $textbotlang['panel']['dashColProduct'] ?></th>
            <th><?= $textbotlang['panel']['dashColAmount'] ?></th>
            <th><?= $textbotlang['panel']['dashColStatus'] ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentInvoices)): ?>
            <tr><td colspan="4">
              <div class="empty" style="padding:28px 20px">
                <?= icon('invoice', 36) ?>
                <p style="margin-top:10px"><?= $textbotlang['panel']['dashNoOrdersYet'] ?></p>
              </div>
            </td></tr>
          <?php else:
            $statusMap = [
                'active'        => ['tag-ok',   $textbotlang['panel']['dashStatusActive']],
                'end_of_time'   => ['tag-warn',  $textbotlang['panel']['dashStatusExpired']],
                'end_of_volume' => ['tag-no',    $textbotlang['panel']['dashStatusVolumeFinished']],
                'sendedwarn'    => ['tag-warn',  $textbotlang['panel']['dashStatusWarning']],
                'send_on_hold'  => ['tag-plain', $textbotlang['panel']['dashStatusWaiting']],
            ];
            foreach ($recentInvoices as $inv):
                [$tagClass, $label] = $statusMap[$inv['Status'] ?? ''] ?? ['tag-plain', $inv['Status'] ?? '—'];
          ?>
            <tr>
              <td class="cm cf"><?= htmlspecialchars($inv['id_user'] ?? '—') ?></td>
              <td class="cs" style="max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <?= htmlspecialchars(trunc($inv['name_product'] ?? '—', 22)) ?>
              </td>
              <td class="cn" style="white-space:nowrap">
                <?= number_format((int)($inv['price_product'] ?? 0)) ?>&nbsp;<span class="cf"><?= $textbotlang['panel']['dashTomanShort'] ?></span>
              </td>
              <td><span class="tag <?= $tagClass ?>"><?= $label ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Recent Users -->
  <div class="card fade-up d2">
    <div class="card-head">
      <div>
        <div class="card-title" style="display:flex;align-items:center;gap:7px">
          <?= icon('users', 15) ?>&nbsp;<?= $textbotlang['panel']['dashRecentUsers'] ?>
        </div>
        <div class="card-subtitle"><?= count($recentUsers) ?>&nbsp;<?= $textbotlang['panel']['dashRecentItem2'] ?></div>
      </div>
      <a href="users.php" class="btn-link" style="font-size:.78rem"><?= $textbotlang['panel']['dashViewAll2'] ?>&nbsp;→</a>
    </div>
    <div class="tbl-wrap">
      <table class="tbl-sm">
        <thead>
          <tr>
            <th><?= $textbotlang['panel']['dashColId'] ?></th>
            <th><?= $textbotlang['panel']['dashColName'] ?></th>
            <th><?= $textbotlang['panel']['dashColBalance'] ?></th>
            <th><?= $textbotlang['panel']['dashColGroup'] ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentUsers)): ?>
            <tr><td colspan="4">
              <div class="empty" style="padding:28px 20px">
                <?= icon('users', 36) ?>
                <p style="margin-top:10px"><?= $textbotlang['panel']['dashNoUsersYet'] ?></p>
              </div>
            </td></tr>
          <?php else:
            foreach ($recentUsers as $u):
                $agent     = $u['agent'] ?? 'f';
                $isBlocked = ($u['User_Status'] ?? '') === 'block';
                $name      = ($u['namecustom'] ?? '') === 'none' ? '' : ($u['namecustom'] ?? '');
                $uname     = ($u['username']   ?? '') === 'none' ? '' : ($u['username']   ?? '');
          ?>
            <tr>
              <td class="cm cf"><?= htmlspecialchars($u['id']) ?></td>
              <td>
                <?php if ($name): ?>
                  <span class="cs"><?= htmlspecialchars(trunc($name, 14)) ?></span>
                <?php elseif ($uname): ?>
                  <span class="cm" style="color:var(--ac)">@<?= htmlspecialchars(trunc($uname, 13)) ?></span>
                <?php else: ?>
                  <span class="cf">—</span>
                <?php endif; ?>
              </td>
              <td class="cn" style="white-space:nowrap">
                <?= number_format((int)($u['Balance'] ?? 0)) ?>&nbsp;<span class="cf"><?= $textbotlang['panel']['dashTomanShort2'] ?></span>
              </td>
              <td>
                <?php if ($isBlocked): ?>
                  <span class="tag tag-no" style="font-size:.63rem"><?= $textbotlang['panel']['dashLabelBlocked'] ?></span>
                <?php else: ?>
                  <span class="tag <?= user_role_tag($agent) ?>" style="font-size:.63rem"><?= user_role_label($agent) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
