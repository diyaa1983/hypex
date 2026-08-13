<?php
declare(strict_types=1);

require_permission('report_sales_rep_visits');

require_once app_path('includes/sal_rep_visit.php');

$pdo = db();
sal_rep_visit_ensure_schema($pdo);

$from = parse_date_to_iso(trim((string) ($_GET['from'] ?? ''))) ?? date('Y-m-01');
$to = parse_date_to_iso(trim((string) ($_GET['to'] ?? ''))) ?? date('Y-m-d');
$salesRepId = (int) ($_GET['sales_rep_id'] ?? 0);
$method = strtoupper(trim((string) ($_GET['method'] ?? '')));
$status = trim((string) ($_GET['status'] ?? ''));

$reps = [];
try {
    $reps = $pdo->query(
        "SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar LIMIT 300"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $reps = [];
}

$rows = sal_rep_visit_report_rows($pdo, [
    'from' => $from,
    'to' => $to,
    'sales_rep_id' => $salesRepId,
    'method' => $method,
    'status' => $status,
    'limit' => 1500,
]);

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');

function _visit_dist($v): string
{
    if ($v === null || $v === '') {
        return '—';
    }

    return number_format((float) $v, 0) . ' م';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<div class="card report-sales-page">
    <header class="report-sales-header no-print">
        <h2>تقرير زيارات العملاء</h2>
        <p class="muted">تسجيلات دخول/خروج المندوب عند العميل — الوقت والنوع (GPS أو يدوي) والمسافة ومدة الزيارة</p>
        <form method="get" class="report-filters" action="<?= esc(app_url('index.php')) ?>">
            <input type="hidden" name="r" value="report_sales_rep_visits">
            <label>من
                <input type="date" name="from" value="<?= esc($from) ?>" dir="ltr">
            </label>
            <label>إلى
                <input type="date" name="to" value="<?= esc($to) ?>" dir="ltr">
            </label>
            <label>المندوب
                <select name="sales_rep_id">
                    <option value="0">— الكل —</option>
                    <?php foreach ($reps as $rep): ?>
                        <option value="<?= (int) $rep['id'] ?>" <?= $salesRepId === (int) $rep['id'] ? 'selected' : '' ?>>
                            <?= esc((string) ($rep['name_ar'] ?? '')) ?>
                            <?php if (!empty($rep['code'])): ?>(<?= esc((string) $rep['code']) ?>)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>النوع
                <select name="method">
                    <option value="" <?= $method === '' ? 'selected' : '' ?>>— الكل —</option>
                    <option value="GPS" <?= $method === 'GPS' ? 'selected' : '' ?>>GPS</option>
                    <option value="MANUAL" <?= $method === 'MANUAL' ? 'selected' : '' ?>>يدوي</option>
                </select>
            </label>
            <label>الحالة
                <select name="status">
                    <option value="" <?= $status === '' ? 'selected' : '' ?>>— الكل —</option>
                    <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>داخل الزيارة</option>
                    <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>مكتملة</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>بانتظار موافقة</option>
                </select>
            </label>
            <button type="submit" class="btn btn-primary">عرض</button>
            <button type="button" class="btn btn-secondary no-print" onclick="window.print()">🖨 طباعة</button>
        </form>
    </header>

    <div class="report-sales-table-wrap">
        <table class="report-sales-table" dir="rtl">
            <thead>
            <tr>
                <th>#</th>
                <th>التاريخ</th>
                <th>المندوب</th>
                <th>العميل</th>
                <th>الرمز</th>
                <th>المنطقة</th>
                <th>العنوان</th>
                <th>وقت الدخول</th>
                <th>نوع الدخول</th>
                <th>مسافة الدخول</th>
                <th>وقت الخروج</th>
                <th>نوع الخروج</th>
                <th>مسافة الخروج</th>
                <th>المدة</th>
                <th>الحالة</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="15" class="muted">لا تسجيلات زيارة في الفترة المحددة.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td dir="ltr"><?= $i + 1 ?></td>
                        <td dir="ltr"><?= esc(format_date_dmY((string) ($r['route_date'] ?? ''))) ?></td>
                        <td>
                            <?= esc((string) ($r['sales_rep_name'] ?? '')) ?>
                            <?php if (!empty($r['sales_rep_code'])): ?>
                                <span class="muted" dir="ltr">(<?= esc((string) $r['sales_rep_code']) ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td><?= esc((string) ($r['customer_name'] ?? '')) ?></td>
                        <td dir="ltr"><?= esc((string) ($r['customer_code'] ?? '')) ?></td>
                        <td><?= esc((string) (($r['region_name'] ?? '') !== '' ? $r['region_name'] : '—')) ?></td>
                        <td><?= esc((string) (($r['address_name'] ?? '') !== '' ? $r['address_name'] : '—')) ?></td>
                        <td dir="ltr"><?= esc(sal_rep_visit_fmt_ts($r['visit_checkin_at'] ?? null)) ?></td>
                        <td><?= esc((string) ($r['checkin_method_label'] ?? '—')) ?></td>
                        <td dir="ltr"><?= esc(_visit_dist($r['checkin_distance_m'] ?? null)) ?></td>
                        <td dir="ltr"><?= esc(sal_rep_visit_fmt_ts($r['visit_checkout_at'] ?? null)) ?></td>
                        <td><?= esc((string) ($r['checkout_method_label'] ?? '—')) ?></td>
                        <td dir="ltr"><?= esc(_visit_dist($r['checkout_distance_m'] ?? null)) ?></td>
                        <td dir="ltr"><?= esc((string) ($r['duration_label'] ?? '—')) ?></td>
                        <td><?= esc((string) ($r['status_label'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
