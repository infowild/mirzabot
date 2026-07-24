<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

date_default_timezone_set('Asia/Tehran');

$TEST_NAME = $textbotlang['Admin']['adminphp']['db_test_service_name'] ?? 'سرویس تست';

// Paid service statuses (aligned with bot reports; exclude unpaid/disabled/admin-removed)
$PAID_STATUSES = "Status IN ('active','end_of_time','end_of_volume','sendedwarn','send_on_hold','removeTime','removevolume')";
$NOT_TEST = "name_product != " . $pdo->quote($TEST_NAME);

// Extra services that generate real revenue (renew / volume / time)
$OTHER_TYPES = "type IN ('extend_user','extra_user','extra_time_user')";
$OTHER_PAID  = "(status IS NULL OR status = '' OR LOWER(status) != 'unpaid')";

// Wallet top-ups that are real customer money (exclude admin adjustments)
$REAL_PAY_METHODS = "Payment_Method NOT IN ('add balance by admin','low balance by admin')";

$jMonthNames = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
                'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

function fin_fmt(int $v): string {
    if (abs($v) >= 1_000_000) return number_format($v / 1_000_000, 1) . '<small> م&nbsp;ت</small>';
    if (abs($v) >= 1_000)     return number_format($v / 1_000, 1) . '<small> ک&nbsp;ت</small>';
    return number_format($v) . '<small> ت</small>';
}
function fin_fmts(int $v): string {
    return number_format($v) . ' ت';
}
function fin_parse_ts($raw): int {
    if ($raw === null || $raw === '') return 0;
    if (is_numeric($raw)) {
        $n = (int)$raw;
        // Heuristic: unix seconds vs milliseconds
        if ($n > 1_000_000_000_000) return (int)floor($n / 1000);
        if ($n > 1_000_000) return $n;
        return 0;
    }
    $ts = strtotime(str_replace('/', '-', (string)$raw));
    return $ts !== false ? $ts : 0;
}
/** Last N Jalali month keys ending with current month (oldest → newest) */
function fin_month_keys(int $count): array {
    $jy = (int)jdate('Y');
    $jm = (int)jdate('m');
    $keys = [];
    for ($i = $count - 1; $i >= 0; $i--) {
        $m = $jm - $i;
        $y = $jy;
        while ($m < 1) { $m += 12; $y--; }
        while ($m > 12) { $m -= 12; $y++; }
        $keys[] = sprintf('%04d/%02d', $y, $m);
    }
    return $keys;
}

$now          = time();
$thisYear     = jdate('Y');
$thisMonthKey = jdate('Y/m', $now);
$jyNow        = (int)jdate('Y', $now);
$jmNow        = (int)jdate('m', $now);
$jdNow        = (int)jdate('j', $now);

$monthStartTs = jalali_day_start_ts($jyNow, $jmNow, 1);
$todayStartTs = jalali_day_start_ts($jyNow, $jmNow, $jdNow);
$daysInMonth  = jalali_days_in_month($jyNow, $jmNow);

$prevJm = $jmNow - 1;
$prevJy = $jyNow;
if ($prevJm < 1) { $prevJm = 12; $prevJy--; }
$lastMonthKey = sprintf('%04d/%02d', $prevJy, $prevJm);

$methodLabels = [
    'cart to cart'         => 'کارت به کارت',
    'Currency Rial 1'      => 'درگاه ریالی ۱',
    'Currency Rial 2'      => 'درگاه ریالی ۲',
    'Currency Rial tow'    => 'درگاه ریالی ۲',
    'Currency Rial 3'      => 'درگاه ریالی ۳',
    'aqayepardakht'        => 'آقای پرداخت',
    'zarinpal'             => 'زرین‌پال',
    'plisio'               => 'Plisio',
    'arze digital offline' => 'ارز دیجیتال',
    'Star Telegram'        => 'تلگرام استار',
    'nowpayment'           => 'NowPayment',
    'paymentnotverify'     => 'پرداخت بدون تأیید',
];

$typeLabels = [
    'extend_user'     => 'تمدید',
    'extra_user'      => 'حجم اضافه',
    'extra_time_user' => 'زمان اضافه',
];

// ── Accumulate monthly + daily buckets ───────────────────────────────────
$monthlyData = [];
foreach (fin_month_keys(24) as $k) {
    $monthlyData[$k] = ['rev' => 0, 'count' => 0, 'sales' => 0, 'extend' => 0, 'extra' => 0];
}
$dailyData = [];
$todayRev = 0; $todayCnt = 0;
$monthSales = 0; $monthExtend = 0; $monthExtra = 0;
$monthSalesCnt = 0; $monthExtendCnt = 0; $monthExtraCnt = 0;

try {
    $rows = db_fetchAll($pdo,
        "SELECT time_sell, price_product FROM invoice
         WHERE $PAID_STATUSES AND $NOT_TEST AND time_sell IS NOT NULL AND time_sell != '' AND time_sell != '0'"
    );
    foreach ($rows as $row) {
        $ts  = fin_parse_ts($row['time_sell']);
        $rev = (int)$row['price_product'];
        if ($ts < 1 || $rev < 0) continue;
        $key = jdate('Y/m', $ts);
        if (!isset($monthlyData[$key])) {
            $monthlyData[$key] = ['rev' => 0, 'count' => 0, 'sales' => 0, 'extend' => 0, 'extra' => 0];
        }
        $monthlyData[$key]['rev']   += $rev;
        $monthlyData[$key]['count'] += 1;
        $monthlyData[$key]['sales'] += $rev;
        if ($ts >= $monthStartTs) {
            $d = (int)jdate('j', $ts);
            $dailyData[$d] = ($dailyData[$d] ?? 0) + $rev;
            $monthSales += $rev;
            $monthSalesCnt++;
        }
        if ($ts >= $todayStartTs) {
            $todayRev += $rev;
            $todayCnt++;
        }
    }
} catch (Exception $e) {}

try {
    $rows2 = db_fetchAll($pdo,
        "SELECT time, price, type FROM service_other
         WHERE $OTHER_TYPES AND $OTHER_PAID AND CAST(price AS SIGNED) > 0"
    );
    foreach ($rows2 as $row) {
        $ts  = fin_parse_ts($row['time']);
        $rev = (int)$row['price'];
        if ($ts < 1 || $rev <= 0) continue;
        $key = jdate('Y/m', $ts);
        if (!isset($monthlyData[$key])) {
            $monthlyData[$key] = ['rev' => 0, 'count' => 0, 'sales' => 0, 'extend' => 0, 'extra' => 0];
        }
        $monthlyData[$key]['rev']   += $rev;
        $monthlyData[$key]['count'] += 1;
        $type = $row['type'] ?? '';
        if ($type === 'extend_user') {
            $monthlyData[$key]['extend'] += $rev;
        } else {
            $monthlyData[$key]['extra'] += $rev;
        }
        if ($ts >= $monthStartTs) {
            $d = (int)jdate('j', $ts);
            $dailyData[$d] = ($dailyData[$d] ?? 0) + $rev;
            if ($type === 'extend_user') {
                $monthExtend += $rev;
                $monthExtendCnt++;
            } else {
                $monthExtra += $rev;
                $monthExtraCnt++;
            }
        }
        if ($ts >= $todayStartTs) {
            $todayRev += $rev;
            $todayCnt++;
        }
    }
} catch (Exception $e) {}

ksort($monthlyData);
ksort($dailyData);

$thisRev  = $monthlyData[$thisMonthKey]['rev']   ?? 0;
$thisCnt  = $monthlyData[$thisMonthKey]['count'] ?? 0;
$lastRev  = $monthlyData[$lastMonthKey]['rev']   ?? 0;
$lastCnt  = $monthlyData[$lastMonthKey]['count'] ?? 0;

$yearRev = 0; $yearCnt = 0;
foreach ($monthlyData as $k => $d) {
    if (substr($k, 0, 4) === $thisYear) {
        $yearRev += $d['rev'];
        $yearCnt += $d['count'];
    }
}

$allTimeRev = 0; $allTimeCnt = 0;
$allSales = 0; $allExtend = 0; $allExtra = 0;
try {
    $allSales = (int)db_query($pdo,
        "SELECT COALESCE(SUM(CAST(price_product AS SIGNED)),0) FROM invoice WHERE $PAID_STATUSES AND $NOT_TEST"
    )->fetchColumn();
    $allSalesCnt = (int)db_query($pdo,
        "SELECT COUNT(*) FROM invoice WHERE $PAID_STATUSES AND $NOT_TEST"
    )->fetchColumn();
} catch (Exception $e) { $allSalesCnt = 0; }
try {
    $allExtend = (int)db_query($pdo,
        "SELECT COALESCE(SUM(CAST(price AS SIGNED)),0) FROM service_other
         WHERE type = 'extend_user' AND $OTHER_PAID"
    )->fetchColumn();
    $allExtra = (int)db_query($pdo,
        "SELECT COALESCE(SUM(CAST(price AS SIGNED)),0) FROM service_other
         WHERE type IN ('extra_user','extra_time_user') AND $OTHER_PAID"
    )->fetchColumn();
    $allOtherCnt = (int)db_query($pdo,
        "SELECT COUNT(*) FROM service_other WHERE $OTHER_TYPES AND $OTHER_PAID AND CAST(price AS SIGNED) > 0"
    )->fetchColumn();
} catch (Exception $e) { $allOtherCnt = 0; }

$allTimeRev = $allSales + $allExtend + $allExtra;
$allTimeCnt = $allSalesCnt + $allOtherCnt;

$growth = ($lastRev > 0) ? round(($thisRev - $lastRev) / $lastRev * 100, 1) : null;

// Continuous last 12 months (fill zeros)
$last12Keys = fin_month_keys(12);
$last12 = [];
foreach ($last12Keys as $k) {
    $last12[$k] = $monthlyData[$k] ?? ['rev' => 0, 'count' => 0, 'sales' => 0, 'extend' => 0, 'extra' => 0];
}
$maxRev = max(array_merge(array_column($last12, 'rev'), [1]));
$maxDaily = max(array_merge(array_values($dailyData), [1]));

// Top products this month (new sales only) — filter in PHP for mixed time formats
$topProducts = [];
try {
    $prodAgg = [];
    $invRows = db_fetchAll($pdo,
        "SELECT name_product, time_sell, price_product FROM invoice
         WHERE $PAID_STATUSES AND $NOT_TEST"
    );
    foreach ($invRows as $r) {
        $ts = fin_parse_ts($r['time_sell']);
        if ($ts < $monthStartTs) continue;
        $name = $r['name_product'] ?? '—';
        if (!isset($prodAgg[$name])) $prodAgg[$name] = ['name_product' => $name, 'cnt' => 0, 'rev' => 0];
        $prodAgg[$name]['cnt']++;
        $prodAgg[$name]['rev'] += (int)$r['price_product'];
    }
    usort($prodAgg, fn($a, $b) => $b['rev'] <=> $a['rev']);
    $topProducts = array_slice(array_values($prodAgg), 0, 8);
} catch (Exception $e) {}

// Other services breakdown this month
$otherBreakdown = [];
try {
    $tmp = [];
    $allOtherRows = db_fetchAll($pdo,
        "SELECT type, time, price FROM service_other
         WHERE $OTHER_TYPES AND $OTHER_PAID AND CAST(price AS SIGNED) > 0"
    );
    foreach ($allOtherRows as $r) {
        $ts = fin_parse_ts($r['time']);
        if ($ts < $monthStartTs) continue;
        $t = $r['type'];
        if (!isset($tmp[$t])) $tmp[$t] = ['type' => $t, 'cnt' => 0, 'rev' => 0];
        $tmp[$t]['cnt']++;
        $tmp[$t]['rev'] += (int)$r['price'];
    }
    usort($tmp, fn($a, $b) => $b['rev'] <=> $a['rev']);
    $otherBreakdown = array_values($tmp);
} catch (Exception $e) {}

// Payment methods — this month real deposits + all-time
$byMethodMonth = [];
$methodMonthTotal = 0;
$byMethodAll = [];
$methodAllTotal = 0;
$walletMonth = 0;
try {
    $payRows = db_fetchAll($pdo,
        "SELECT Payment_Method, time, price FROM Payment_report
         WHERE payment_Status = 'paid' AND $REAL_PAY_METHODS"
    );
    $aggMonth = [];
    $aggAll = [];
    foreach ($payRows as $r) {
        $rev = (int)$r['price'];
        if ($rev <= 0) continue;
        $pm = $r['Payment_Method'] ?? '';
        $ts = fin_parse_ts($r['time']);
        if (!isset($aggAll[$pm])) $aggAll[$pm] = ['Payment_Method' => $pm, 'cnt' => 0, 'rev' => 0];
        $aggAll[$pm]['cnt']++;
        $aggAll[$pm]['rev'] += $rev;
        $methodAllTotal += $rev;
        if ($ts >= $monthStartTs) {
            if (!isset($aggMonth[$pm])) $aggMonth[$pm] = ['Payment_Method' => $pm, 'cnt' => 0, 'rev' => 0];
            $aggMonth[$pm]['cnt']++;
            $aggMonth[$pm]['rev'] += $rev;
            $methodMonthTotal += $rev;
            $walletMonth += $rev;
        }
    }
    usort($aggMonth, fn($a, $b) => $b['rev'] <=> $a['rev']);
    usort($aggAll, fn($a, $b) => $b['rev'] <=> $a['rev']);
    $byMethodMonth = array_values($aggMonth);
    $byMethodAll   = array_values($aggAll);
} catch (Exception $e) {}

$tableMonths = [];
foreach (array_reverse(fin_month_keys(24)) as $k) {
    $tableMonths[$k] = $monthlyData[$k] ?? ['rev' => 0, 'count' => 0, 'sales' => 0, 'extend' => 0, 'extra' => 0];
}

$pageTitle    = 'گزارش مالی';
$activeNav    = 'finance';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<style>
.fin-grid{display:grid;gap:16px;grid-template-columns:repeat(4,1fr)}
.fin-grid.two{grid-template-columns:repeat(2,1fr)}
.fin-grid.three{grid-template-columns:repeat(3,1fr)}
@media(max-width:900px){.fin-grid,.fin-grid.three{grid-template-columns:repeat(2,1fr)}}
@media(max-width:480px){.fin-grid,.fin-grid.two,.fin-grid.three{grid-template-columns:1fr}}
.fin-stat{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px 20px;display:flex;flex-direction:column;gap:6px;transition:box-shadow .2s}
.fin-stat:hover{box-shadow:0 4px 24px rgba(0,0,0,.18)}
.fin-stat-label{font-size:.72rem;color:var(--mute);font-weight:600;letter-spacing:.03em}
.fin-stat-val{font-size:1.55rem;font-weight:800;color:var(--text);letter-spacing:-.04em;line-height:1.1}
.fin-stat-val small{font-size:.8rem;font-weight:500;color:var(--mute)}
.fin-stat-meta{font-size:.73rem;color:var(--dim);margin-top:2px}
.up{color:var(--ok);font-weight:700}
.dn2{color:var(--no);font-weight:700}
.month-bar-wrap{display:flex;align-items:flex-end;gap:6px;height:100px;padding-bottom:2px}
.month-bar{flex:1;border-radius:5px 5px 0 0;min-width:0;position:relative;transition:height .4s cubic-bezier(.4,0,.2,1);cursor:default}
.month-bar:hover .month-bar-tip{opacity:1;transform:translateY(-4px)}
.month-bar-tip{position:absolute;top:-42px;left:50%;transform:translateX(-50%);background:var(--sf3);border:1px solid var(--bd);border-radius:7px;padding:3px 7px;font-size:.62rem;color:var(--text);white-space:nowrap;opacity:0;transition:opacity .15s,transform .15s;pointer-events:none;z-index:10}
.month-label-row{display:flex;gap:6px;margin-top:8px;border-top:1px solid var(--bd);padding-top:8px}
.month-label{flex:1;text-align:center;font-size:.58rem;color:var(--dim);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.month-label.cur{color:var(--ac);font-weight:700}
.day-bar-wrap{display:flex;align-items:flex-end;gap:3px;height:60px}
.day-bar{flex:1;min-width:2px;border-radius:3px 3px 0 0;background:var(--ac);opacity:.7;position:relative;transition:opacity .2s,height .35s}
.day-bar:hover{opacity:1}
.tbl-finance td:first-child{font-weight:700;color:var(--text)}
.tbl-finance .cur-month td{background:color-mix(in srgb,var(--ac) 7%,transparent)}
.method-bar{height:6px;border-radius:3px;background:var(--ac);margin-top:4px;transition:width .5s}
.pct-label{font-size:.65rem;color:var(--mute)}
.fin-note{font-size:.72rem;color:var(--mute);background:var(--sf2);border:1px solid var(--bd);border-radius:10px;padding:10px 14px;margin-bottom:16px;line-height:1.7}
.source-chip{display:inline-flex;align-items:center;gap:4px;font-size:.68rem;padding:2px 8px;border-radius:999px;background:var(--sf3);color:var(--mute);border:1px solid var(--bd)}
</style>

<!-- Page title -->
<div class="welcome-bar fade-up" style="margin-bottom:16px">
  <div>
    <div style="font-size:1.1rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:8px">
      <?= icon('trend', 16) ?>&nbsp;گزارش مالی جامع
    </div>
    <div style="font-size:.75rem;color:var(--mute);margin-top:3px">
      سال شمسی <?= $thisYear ?> &nbsp;·&nbsp; <?= jdate('Y/m/d') ?>
      &nbsp;·&nbsp; فروش + تمدید + حجم/زمان اضافه
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <span class="tag tag-info">درآمد واقعی</span>
    <?php if ($growth !== null): ?>
      <span class="tag <?= $growth >= 0 ? 'tag-ok' : 'tag-no' ?>">
        <?= $growth >= 0 ? '↑' : '↓' ?> <?= abs($growth) ?>٪ نسبت به ماه قبل
      </span>
    <?php endif; ?>
  </div>
</div>

<div class="fin-note fade-up">
  درآمد = <strong>فروش سرویس جدید</strong> + <strong>تمدید</strong> + <strong>حجم/زمان اضافه</strong>.
  سرویس تست و تعدیل موجودی توسط ادمین در این گزارش لحاظ نمی‌شود.
  بخش «شارژ کیف پول» جداگانه است (واریز واقعی کاربران) و با فروش یکی نیست.
</div>

<!-- ══ Row 1: Summary Stats ══════════════════════════════════════════════ -->
<div class="fin-grid fade-up" style="margin-bottom:16px">

  <div class="fin-stat" style="border-color:color-mix(in srgb,var(--ac) 35%,var(--bd))">
    <div class="fin-stat-label"><?= icon('chart', 12) ?>&nbsp;ماه جاری</div>
    <div class="fin-stat-val" title="<?= fin_fmts($thisRev) ?>"><?= fin_fmt($thisRev) ?></div>
    <div class="fin-stat-meta">
      <?= number_format($thisCnt) ?> تراکنش
      <?php if ($thisCnt > 0): ?>
        &nbsp;·&nbsp; میانگین <?= fin_fmts((int)($thisRev / $thisCnt)) ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="fin-stat">
    <div class="fin-stat-label"><?= icon('chart', 12) ?>&nbsp;امروز</div>
    <div class="fin-stat-val" title="<?= fin_fmts($todayRev) ?>"><?= fin_fmt($todayRev) ?></div>
    <div class="fin-stat-meta"><?= number_format($todayCnt) ?> تراکنش امروز</div>
  </div>

  <div class="fin-stat">
    <div class="fin-stat-label"><?= icon('chart', 12) ?>&nbsp;ماه گذشته</div>
    <div class="fin-stat-val" title="<?= fin_fmts($lastRev) ?>"><?= fin_fmt($lastRev) ?></div>
    <div class="fin-stat-meta">
      <?= number_format($lastCnt) ?> تراکنش
      <?php if ($growth !== null): ?>
        &nbsp;·&nbsp; <span class="<?= $growth >= 0 ? 'up' : 'dn2' ?>"><?= $growth >= 0 ? '+' : '' ?><?= $growth ?>٪</span>
      <?php endif; ?>
    </div>
  </div>

  <div class="fin-stat" style="border-color:color-mix(in srgb,var(--ok) 30%,var(--bd))">
    <div class="fin-stat-label"><?= icon('wallet', 12) ?>&nbsp;سال <?= $thisYear ?></div>
    <div class="fin-stat-val" title="<?= fin_fmts($yearRev) ?>"><?= fin_fmt($yearRev) ?></div>
    <div class="fin-stat-meta">
      <?= number_format($yearCnt) ?> تراکنش
      &nbsp;·&nbsp; میانگین ماهانه <?= fin_fmts($jmNow > 0 ? (int)($yearRev / $jmNow) : 0) ?>
    </div>
  </div>

</div>

<!-- ══ Row 1b: Source breakdown this month ═══════════════════════════════ -->
<div class="fin-grid three fade-up" style="margin-bottom:16px">
  <div class="fin-stat">
    <div class="fin-stat-label">فروش جدید (ماه)</div>
    <div class="fin-stat-val" title="<?= fin_fmts($monthSales) ?>"><?= fin_fmt($monthSales) ?></div>
    <div class="fin-stat-meta"><?= number_format($monthSalesCnt) ?> فاکتور</div>
  </div>
  <div class="fin-stat">
    <div class="fin-stat-label">تمدید (ماه)</div>
    <div class="fin-stat-val" title="<?= fin_fmts($monthExtend) ?>"><?= fin_fmt($monthExtend) ?></div>
    <div class="fin-stat-meta"><?= number_format($monthExtendCnt) ?> تمدید</div>
  </div>
  <div class="fin-stat">
    <div class="fin-stat-label">حجم/زمان اضافه (ماه)</div>
    <div class="fin-stat-val" title="<?= fin_fmts($monthExtra) ?>"><?= fin_fmt($monthExtra) ?></div>
    <div class="fin-stat-meta"><?= number_format($monthExtraCnt) ?> خرید</div>
  </div>
</div>

<div class="fin-grid two fade-up" style="margin-bottom:16px">
  <div class="fin-stat">
    <div class="fin-stat-label"><?= icon('invoice', 12) ?>&nbsp;کل درآمد ثبت‌شده</div>
    <div class="fin-stat-val" title="<?= fin_fmts($allTimeRev) ?>"><?= fin_fmt($allTimeRev) ?></div>
    <div class="fin-stat-meta">
      <?= number_format($allTimeCnt) ?> تراکنش
      &nbsp;·&nbsp;
      <span class="source-chip">فروش <?= fin_fmts($allSales) ?></span>
      <span class="source-chip">تمدید <?= fin_fmts($allExtend) ?></span>
      <span class="source-chip">اضافه <?= fin_fmts($allExtra) ?></span>
    </div>
  </div>
  <div class="fin-stat">
    <div class="fin-stat-label"><?= icon('card', 12) ?>&nbsp;شارژ کیف پول (ماه جاری)</div>
    <div class="fin-stat-val" title="<?= fin_fmts($walletMonth) ?>"><?= fin_fmt($walletMonth) ?></div>
    <div class="fin-stat-meta">واریز واقعی کاربران — بدون تعدیل ادمین</div>
  </div>
</div>

<!-- ══ Row 2: 12-Month Chart + Daily Chart ══════════════════════════════ -->
<div class="fin-grid two fade-up" style="margin-bottom:16px">

  <div class="card" style="min-width:0">
    <div class="card-head">
      <div>
        <div class="card-title"><?= icon('chart', 15) ?>&nbsp;روند درآمد ۱۲ ماه اخیر</div>
        <div class="card-subtitle">مجموع: <?= fin_fmts(array_sum(array_column($last12, 'rev'))) ?></div>
      </div>
      <span class="tag tag-info">ماهانه</span>
    </div>
    <div class="card-body" style="padding-top:16px">
      <div class="month-bar-wrap">
        <?php foreach ($last12 as $key => $d):
          $pct   = $maxRev > 0 ? ($d['rev'] / $maxRev * 100) : 0;
          $barH  = max(4, (int)round($pct));
          $isCur = ($key === $thisMonthKey);
          [$jy, $jm] = explode('/', $key);
        ?>
        <div class="month-bar" style="
          height:<?= $barH ?>%;
          background:<?= $isCur ? 'var(--ac)' : 'var(--sf3)' ?>;
          box-shadow:<?= $isCur ? '0 0 14px var(--acg)' : 'none' ?>;
        ">
          <div class="month-bar-tip">
            <?= $jMonthNames[(int)$jm] ?> <?= $jy ?><br>
            <strong><?= fin_fmts($d['rev']) ?></strong><br>
            <?= $d['count'] ?> تراکنش
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="month-label-row">
        <?php foreach ($last12 as $key => $d):
          [,$jm] = explode('/', $key);
          $isCur = ($key === $thisMonthKey);
        ?>
          <div class="month-label <?= $isCur ? 'cur' : '' ?>"><?= $jMonthNames[(int)$jm] ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card" style="min-width:0">
    <div class="card-head">
      <div>
        <div class="card-title"><?= icon('chart', 15) ?>&nbsp;درآمد روزانه — <?= $jMonthNames[$jmNow] ?></div>
        <div class="card-subtitle">
          مجموع ماه: <?= fin_fmts($thisRev) ?>
          <?php if ($thisCnt > 0): ?>
            &nbsp;·&nbsp; <?= number_format($thisCnt) ?> تراکنش
          <?php endif; ?>
        </div>
      </div>
      <span class="tag tag-ok">ماه جاری</span>
    </div>
    <div class="card-body" style="padding-top:16px">
      <?php if (empty($dailyData)): ?>
        <div style="text-align:center;color:var(--dim);padding:20px 0;font-size:.8rem">هنوز درآمدی در این ماه ثبت نشده</div>
      <?php else: ?>
        <div class="day-bar-wrap">
          <?php for ($d = 1; $d <= $daysInMonth; $d++):
            $rev  = $dailyData[$d] ?? 0;
            $pct  = $maxDaily > 0 ? ($rev / $maxDaily * 100) : 0;
            $barH = $rev > 0 ? max(5, (int)round($pct)) : 1;
          ?>
            <div class="day-bar" style="
              height:<?= $barH ?>%;
              opacity:<?= $d == $jdNow ? '1' : ($rev > 0 ? '.75' : '.15') ?>;
              box-shadow:<?= $d == $jdNow ? '0 0 8px var(--acg)' : 'none' ?>;
            " title="روز <?= $d ?>: <?= fin_fmts($rev) ?>"></div>
          <?php endfor; ?>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:6px;font-size:.62rem;color:var(--dim)">
          <span>۱ <?= $jMonthNames[$jmNow] ?></span>
          <span>امروز: <?= fin_fmts($dailyData[$jdNow] ?? 0) ?></span>
          <span><?= $daysInMonth ?> <?= $jMonthNames[$jmNow] ?></span>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- ══ Row 3: Top Products + Other services + Payment Methods ═══════════ -->
<div class="fin-grid two fade-up" style="margin-bottom:16px">

  <div class="card" style="min-width:0">
    <div class="card-head">
      <div>
        <div class="card-title"><?= icon('package', 15) ?>&nbsp;پرفروش‌ترین محصولات ماه</div>
        <div class="card-subtitle">فقط فروش سرویس جدید</div>
      </div>
    </div>
    <?php if (empty($topProducts)): ?>
      <div class="card-body" style="color:var(--dim);font-size:.8rem;text-align:center;padding:20px">فروشی ثبت نشده</div>
    <?php else: ?>
    <div class="tbl-wrap">
      <table class="tbl-md">
        <thead>
          <tr>
            <th>#</th>
            <th>نام محصول</th>
            <th style="text-align:left">تعداد</th>
            <th style="text-align:left">درآمد</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($topProducts as $i => $p): ?>
          <tr>
            <td style="color:var(--mute);font-size:.72rem"><?= $i + 1 ?></td>
            <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?= htmlspecialchars($p['name_product'] ?? '—') ?>
            </td>
            <td style="text-align:left"><span class="tag tag-plain"><?= number_format((int)$p['cnt']) ?></span></td>
            <td style="text-align:left;font-weight:700;color:var(--ac)"><?= fin_fmts((int)$p['rev']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($otherBreakdown)): ?>
    <div class="card-body" style="border-top:1px solid var(--bd);padding-top:14px">
      <div style="font-size:.78rem;font-weight:700;margin-bottom:10px;color:var(--text)">خدمات جانبی این ماه</div>
      <?php foreach ($otherBreakdown as $ob):
        $label = $typeLabels[$ob['type']] ?? htmlspecialchars($ob['type']);
      ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <span style="font-size:.78rem"><?= $label ?> <span class="pct-label">(<?= number_format($ob['cnt']) ?>)</span></span>
          <span style="font-weight:700;color:var(--ac);font-size:.78rem"><?= fin_fmts((int)$ob['rev']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="card" style="min-width:0">
    <div class="card-head">
      <div>
        <div class="card-title"><?= icon('card', 15) ?>&nbsp;روش‌های شارژ کیف پول</div>
        <div class="card-subtitle">
          ماه جاری: <?= fin_fmts($methodMonthTotal) ?>
          &nbsp;·&nbsp; کل: <?= fin_fmts($methodAllTotal) ?>
        </div>
      </div>
    </div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:12px">
      <?php if (empty($byMethodMonth) && empty($byMethodAll)): ?>
        <div style="color:var(--dim);font-size:.8rem;text-align:center;padding:10px">داده‌ای موجود نیست</div>
      <?php else: ?>
        <div style="font-size:.72rem;font-weight:700;color:var(--mute)">ماه جاری</div>
        <?php if (empty($byMethodMonth)): ?>
          <div style="color:var(--dim);font-size:.78rem">واریزی در این ماه نبوده</div>
        <?php else: ?>
          <?php foreach ($byMethodMonth as $m):
            $rev = (int)$m['rev'];
            $pct = $methodMonthTotal > 0 ? round($rev / $methodMonthTotal * 100, 1) : 0;
            $pm  = $m['Payment_Method'] ?? '';
            $label = $methodLabels[$pm] ?? htmlspecialchars($pm ?: '—');
          ?>
          <div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px">
              <span style="font-size:.78rem;color:var(--text);font-weight:600"><?= $label ?></span>
              <div style="text-align:left">
                <span style="font-size:.78rem;font-weight:700;color:var(--ac)"><?= fin_fmts($rev) ?></span>
                <span class="pct-label">&nbsp;(<?= $pct ?>٪)</span>
              </div>
            </div>
            <div style="background:var(--sf3);border-radius:3px;height:6px;overflow:hidden">
              <div class="method-bar" style="width:<?= $pct ?>%"></div>
            </div>
            <div class="pct-label" style="margin-top:2px"><?= number_format((int)$m['cnt']) ?> تراکنش</div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($byMethodAll)): ?>
        <div style="font-size:.72rem;font-weight:700;color:var(--mute);margin-top:8px;border-top:1px solid var(--bd);padding-top:10px">کل دوره‌ها</div>
          <?php foreach (array_slice($byMethodAll, 0, 6) as $m):
            $rev = (int)$m['rev'];
            $pct = $methodAllTotal > 0 ? round($rev / $methodAllTotal * 100, 1) : 0;
            $pm  = $m['Payment_Method'] ?? '';
            $label = $methodLabels[$pm] ?? htmlspecialchars($pm ?: '—');
          ?>
          <div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px">
              <span style="font-size:.78rem;color:var(--text);font-weight:600"><?= $label ?></span>
              <div style="text-align:left">
                <span style="font-size:.78rem;font-weight:700;color:var(--ac)"><?= fin_fmts($rev) ?></span>
                <span class="pct-label">&nbsp;(<?= $pct ?>٪)</span>
              </div>
            </div>
            <div style="background:var(--sf3);border-radius:3px;height:6px;overflow:hidden">
              <div class="method-bar" style="width:<?= $pct ?>%;opacity:.65"></div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- ══ Row 4: Monthly Breakdown Table ═══════════════════════════════════ -->
<div class="card fade-up" style="margin-bottom:20px">
  <div class="card-head">
    <div>
      <div class="card-title"><?= icon('invoice', 15) ?>&nbsp;جزئیات درآمد ماهانه</div>
      <div class="card-subtitle">۲۴ ماه اخیر — فروش / تمدید / اضافه</div>
    </div>
    <span class="tag tag-plain">تقویم شمسی</span>
  </div>
  <div class="tbl-wrap">
    <table class="tbl-md tbl-finance">
      <thead>
        <tr>
          <th>ماه</th>
          <th style="text-align:left">کل درآمد</th>
          <th style="text-align:left">فروش</th>
          <th style="text-align:left">تمدید</th>
          <th style="text-align:left">اضافه</th>
          <th style="text-align:left">تعداد</th>
          <th style="text-align:left">رشد</th>
          <th style="text-align:left">سهم امسال</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $monthKeys = array_keys($tableMonths);
        foreach ($monthKeys as $idx => $key):
            $d       = $tableMonths[$key];
            $rev     = (int)$d['rev'];
            $cnt     = (int)$d['count'];
            [$jy, $jm] = explode('/', $key);

            $nextKey = $monthKeys[$idx + 1] ?? null;
            $nextRev = $nextKey ? (int)($tableMonths[$nextKey]['rev'] ?? 0) : null;
            $grw     = ($nextRev !== null && $nextRev > 0)
                       ? round(($rev - $nextRev) / $nextRev * 100, 1)
                       : null;

            $share = ($yearRev > 0 && substr($key, 0, 4) === $thisYear)
                     ? round($rev / $yearRev * 100, 1)
                     : null;

            $isCur  = ($key === $thisMonthKey);
            $isLast = ($key === $lastMonthKey);
        ?>
        <tr class="<?= $isCur ? 'cur-month' : '' ?>">
          <td>
            <div style="display:flex;align-items:center;gap:6px">
              <?php if ($isCur): ?>
                <span class="tag tag-ok" style="font-size:.58rem;padding:1px 5px">جاری</span>
              <?php elseif ($isLast): ?>
                <span class="tag tag-plain" style="font-size:.58rem;padding:1px 5px">قبلی</span>
              <?php endif; ?>
              <span><?= $jMonthNames[(int)$jm] ?> <?= $jy ?></span>
            </div>
          </td>
          <td style="text-align:left;font-weight:700;color:var(--ac)"><?= fin_fmts($rev) ?></td>
          <td style="text-align:left;color:var(--mute)"><?= fin_fmts((int)$d['sales']) ?></td>
          <td style="text-align:left;color:var(--mute)"><?= fin_fmts((int)$d['extend']) ?></td>
          <td style="text-align:left;color:var(--mute)"><?= fin_fmts((int)$d['extra']) ?></td>
          <td style="text-align:left"><?= number_format($cnt) ?></td>
          <td style="text-align:left">
            <?php if ($grw !== null): ?>
              <span class="<?= $grw >= 0 ? 'up' : 'dn2' ?>"><?= $grw >= 0 ? '+' : '' ?><?= $grw ?>٪</span>
            <?php else: ?>
              <span style="color:var(--dim)">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:left">
            <?php if ($share !== null): ?>
              <div style="display:flex;align-items:center;gap:6px">
                <div style="flex:1;height:4px;background:var(--sf3);border-radius:2px;min-width:50px">
                  <div style="width:<?= min(100, $share) ?>%;height:100%;background:var(--ac);border-radius:2px"></div>
                </div>
                <span style="font-size:.7rem;color:var(--mute)"><?= $share ?>٪</span>
              </div>
            <?php else: ?>
              <span style="color:var(--dim);font-size:.7rem">سال دیگر</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:var(--sf2);font-weight:700">
          <td>جمع سال <?= $thisYear ?></td>
          <td style="text-align:left;color:var(--ac)"><?= fin_fmts($yearRev) ?></td>
          <td colspan="3"></td>
          <td style="text-align:left"><?= number_format($yearCnt) ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
