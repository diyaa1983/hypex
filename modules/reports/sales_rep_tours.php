<?php
declare(strict_types=1);

require_permission('report_sales_rep_tours');

require_once app_path('includes/sal_rep_route.php');
require_once app_path('includes/sal_rep_visit.php');

$pdo = db();
sal_rep_route_ensure_schema($pdo);

$from = parse_date_to_iso(trim((string) ($_GET['from'] ?? ''))) ?? date('Y-m-01');
$to = parse_date_to_iso(trim((string) ($_GET['to'] ?? ''))) ?? date('Y-m-d');
$salesRepId = (int) ($_GET['sales_rep_id'] ?? 0);
$status = trim((string) ($_GET['status'] ?? ''));

$reps = [];
try {
    $reps = $pdo->query(
        "SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar LIMIT 300"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $reps = [];
}

$where = ['t.is_active = 1', 't.date_to >= ?', 't.date_from <= ?'];
$params = [$from, $to];
if ($salesRepId > 0) {
    $where[] = 't.sales_rep_id = ?';
    $params[] = $salesRepId;
}
if ($status === 'draft' || $status === 'posted') {
    $where[] = 't.status = ?';
    $params[] = $status;
}

foreach ([
    'visit_checkin_at DATETIME NULL DEFAULT NULL',
    'visit_checkout_at DATETIME NULL DEFAULT NULL',
    'checkin_method VARCHAR(20) NULL DEFAULT NULL',
    'checkout_method VARCHAR(20) NULL DEFAULT NULL',
] as $def) {
    $col = explode(' ', $def, 2)[0];
    try {
        $pdo->query('SELECT `' . $col . '` FROM sal_rep_tour_line LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE sal_rep_tour_line ADD COLUMN `' . $col . '` ' . explode(' ', $def, 2)[1]);
        } catch (Throwable $e2) {
            // ignore
        }
    }
}

$rows = [];
try {
    $sql = "SELECT t.id AS tour_id, t.date_from, t.date_to, t.status,
                   COALESCE(sr.name_ar,'') AS sales_rep_name, COALESCE(sr.code,'') AS sales_rep_code,
                   c.code AS customer_code, c.name_ar AS customer_name,
                   COALESCE(rg.name_ar,'') AS region_name,
                   COALESCE(ra.name_ar,'') AS address_name,
                   l.visit_checkin_at, l.visit_checkout_at, l.checkin_method, l.checkout_method
            FROM sal_rep_tour t
            INNER JOIN crm_sales_rep sr ON sr.id = t.sales_rep_id
            INNER JOIN sal_rep_tour_line l ON l.tour_id = t.id
            INNER JOIN crm_customer c ON c.id = l.customer_id
            LEFT JOIN crm_region rg ON rg.id = COALESCE(l.region_id, c.region_id)
            LEFT JOIN crm_region_address ra ON ra.id = COALESCE(l.region_address_id, c.region_address_id)
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.date_from DESC, t.id DESC, l.sort_order, c.name_ar
            LIMIT 1000";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $rows = [];
}

function _tour_method_label(?string $v): string
{
    $m = strtoupper(trim((string) $v));
    if ($m === '') {
        return '—';
    }
    if ($m === 'GPS') {
        return 'GPS';
    }
    if ($m === 'MANUAL') {
        return 'يدوي';
    }

    return (string) $v;
}

function _tour_timing_row(array $r): array
{
    $r['checkin_method_label'] = _tour_method_label($r['checkin_method'] ?? null);
    $r['checkout_method_label'] = _tour_method_label($r['checkout_method'] ?? null);

    return $r;
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<style>
.report-sales-page.report-tours-page .report-sales-table-wrap {
  overflow-x: auto !important;
}
.report-sales-page.report-tours-page .report-sales-table {
  width: 100% !important;
  min-width: 1100px !important;
  table-layout: auto !important;
  border-collapse: collapse !important;
  font-size: 0.78rem;
}
.report-sales-page.report-tours-page .report-sales-table th,
.report-sales-page.report-tours-page .report-sales-table td {
  white-space: nowrap !important;
  overflow: hidden !important;
  text-overflow: ellipsis !important;
  max-width: 12rem;
  padding: 0.38rem 0.45rem !important;
  text-align: center;
  vertical-align: middle;
  line-height: 1.3;
}
.report-sales-page.report-tours-page .col-customer,
.report-sales-page.report-tours-page .col-location {
  text-align: start !important;
}
.report-sales-page.report-tours-page .col-checkin,
.report-sales-page.report-tours-page .col-checkout,
.report-sales-page.report-tours-page .col-duration,
.report-sales-page.report-tours-page .col-method,
.report-sales-page.report-tours-page .col-status {
  max-width: none !important;
  overflow: visible !important;
  text-overflow: clip !important;
}
.report-sales-page.report-tours-page .si-ts-compact {
  display: inline-block;
  white-space: nowrap !important;
  direction: ltr;
  unicode-bidi: isolate;
  font-variant-numeric: tabular-nums;
}
@media print {
  @page { size: A4 landscape; margin: 6mm 5mm; }
  .report-sales-page.report-tours-page .report-sales-header { display: none !important; }
  .report-sales-page.report-tours-page .report-sales-table {
    min-width: 0 !important;
    width: 100% !important;
    font-size: 7.5pt !important;
  }
  .report-sales-page.report-tours-page .report-sales-table th,
  .report-sales-page.report-tours-page .report-sales-table td {
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    padding: 0.12rem 0.22rem !important;
    font-size: 7.5pt !important;
    border: 1px solid #94a3b8 !important;
  }
  .report-sales-page.report-tours-page .col-checkin,
  .report-sales-page.report-tours-page .col-checkout,
  .report-sales-page.report-tours-page .col-duration,
  .report-sales-page.report-tours-page .col-method,
  .report-sales-page.report-tours-page .col-status {
    overflow: visible !important;
    text-overflow: clip !important;
  }
}
</style>
<div class="card report-sales-page report-tours-page">
    <header class="report-sales-header no-print">
        <h2>تقرير الجولات</h2>
        <p class="muted">الجولات المُنشأة مع المناطق والعناوين — أوقات الدخول/الخروج وطريقة GPS تُعبَّأ لاحقاً من الآيباد</p>
        <form method="get" class="report-filters" action="<?= esc(app_url('index.php')) ?>">
            <input type="hidden" name="r" value="report_sales_rep_tours">
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
            <label>الحالة
                <select name="status">
                    <option value="" <?= $status === '' ? 'selected' : '' ?>>— الكل —</option>
                    <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>مسودة</option>
                    <option value="posted" <?= $status === 'posted' ? 'selected' : '' ?>>مرحّلة</option>
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
                <th>الجولة</th>
                <th>المندوب</th>
                <th>الفترة</th>
                <th>الحالة</th>
                <th>العميل</th>
                <th>الموقع</th>
                <th>وقت الدخول</th>
                <th>وقت الخروج</th>
                <th>مجموع الساعات</th>
                <th>نوع الدخول/الخروج</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="11" class="muted">لا جولات في الفترة المحددة.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $i => $r): ?>
                    <?php $r = _tour_timing_row($r); ?>
                    <tr>
                        <td dir="ltr"><?= $i + 1 ?></td>
                        <td dir="ltr"><?= (int) ($r['tour_id'] ?? 0) ?></td>
                        <td>
                            <?= esc((string) ($r['sales_rep_name'] ?? '')) ?>
                        </td>
                        <td dir="ltr">
                            <?= esc(format_date_dmY((string) ($r['date_from'] ?? ''))) ?>
                            →
                            <?= esc(format_date_dmY((string) ($r['date_to'] ?? ''))) ?>
                        </td>
                        <td class="col-status"><?= (string) ($r['status'] ?? '') === 'posted' ? 'مرحّلة' : 'مسودة' ?></td>
                        <td class="col-customer"><?= sal_rep_visit_customer_inline($r) ?></td>
                        <td class="col-location"><?= sal_rep_visit_location_inline($r) ?></td>
                        <td class="col-checkin" dir="ltr"><?= sal_rep_visit_timing_checkin_cell($r) ?></td>
                        <td class="col-checkout" dir="ltr"><?= sal_rep_visit_timing_checkout_cell($r) ?></td>
                        <td class="col-duration"><?= sal_rep_visit_timing_duration_cell($r) ?></td>
                        <td class="col-method"><?= sal_rep_visit_timing_method_cell($r) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
