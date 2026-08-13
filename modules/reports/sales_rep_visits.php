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

$uniqueRepIds = [];
foreach ($rows as $r) {
    $rid = (int) ($r['sales_rep_id'] ?? 0);
    if ($rid > 0) {
        $uniqueRepIds[$rid] = true;
    }
}
$groupByRep = $salesRepId < 1 && count($uniqueRepIds) > 1;
$colCount = $groupByRep ? 12 : 13;

$grouped = [];
if ($groupByRep) {
    foreach ($rows as $r) {
        $rid = (int) ($r['sales_rep_id'] ?? 0);
        $key = $rid > 0 ? (string) $rid : '0';
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'name' => (string) ($r['sales_rep_name'] ?? 'مندوب'),
                'rows' => [],
            ];
        }
        $grouped[$key]['rows'][] = $r;
    }
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<style>
.report-sales-page.report-visits-page .report-sales-table-wrap {
  overflow-x: auto;
}
.report-sales-page.report-visits-page .report-sales-table {
  width: max-content;
  min-width: 100%;
  table-layout: auto;
  font-size: 0.78rem;
}
.report-sales-page.report-visits-page .report-sales-table th,
.report-sales-page.report-visits-page .report-sales-table td {
  white-space: nowrap;
  padding: 0.35rem 0.45rem;
  text-align: center;
  vertical-align: middle;
  line-height: 1.25;
}
.report-sales-page.report-visits-page .report-visits-group td {
  background: #e2e8f0;
  text-align: start !important;
  font-weight: 700;
}
@media print {
  @page { size: A4 landscape; margin: 6mm 5mm; }
  .report-sales-page.report-visits-page .report-sales-header { display: none !important; }
  .report-sales-page.report-visits-page .report-sales-table {
    width: 100% !important;
    font-size: 7.5pt !important;
  }
  .report-sales-page.report-visits-page .report-sales-table th,
  .report-sales-page.report-visits-page .report-sales-table td {
    white-space: nowrap !important;
    padding: 0.15rem 0.25rem !important;
    font-size: 7.5pt !important;
    border: 1px solid #94a3b8 !important;
  }
}
</style>
<div class="card report-sales-page report-visits-page">
    <header class="report-sales-header no-print">
        <h2>تقرير زيارات العملاء</h2>
        <p class="muted">تسجيلات دخول/خروج المندوب عند العميل — الوقت والنوع (GPS أو Manual) ومدة الزيارة</p>
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
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>النوع
                <select name="method">
                    <option value="" <?= $method === '' ? 'selected' : '' ?>>— الكل —</option>
                    <option value="GPS" <?= $method === 'GPS' ? 'selected' : '' ?>>GPS</option>
                    <option value="MANUAL" <?= $method === 'MANUAL' ? 'selected' : '' ?>>Manual</option>
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
                <?php if (!$groupByRep): ?><th>المندوب</th><?php endif; ?>
                <th>العميل</th>
                <th>رقم العميل</th>
                <th>المنطقة</th>
                <th>العنوان</th>
                <th>دخول</th>
                <th>نوع</th>
                <th>خروج</th>
                <th>نوع</th>
                <th>مدة الزيارة</th>
                <th>الحالة</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="<?= (int) $colCount ?>" class="muted">لا تسجيلات زيارة في الفترة المحددة.</td></tr>
            <?php elseif ($groupByRep): ?>
                <?php $seq = 0; ?>
                <?php foreach ($grouped as $g): ?>
                    <tr class="report-visits-group">
                        <td colspan="<?= (int) $colCount ?>">
                            المندوب: <?= esc((string) $g['name']) ?>
                            <span class="muted">(<?= count($g['rows']) ?> زيارة)</span>
                        </td>
                    </tr>
                    <?php foreach ($g['rows'] as $r): ?>
                        <?php $seq++; ?>
                        <tr>
                            <td dir="ltr"><?= $seq ?></td>
                            <td><?= esc(sal_rep_visit_date_with_weekday((string) ($r['route_date'] ?? ''))) ?></td>
                            <td><?= esc((string) ($r['customer_name'] ?? '')) ?></td>
                            <td dir="ltr"><?= esc((string) ($r['customer_code'] ?? '')) ?></td>
                            <td><?= esc((string) (($r['region_name'] ?? '') !== '' ? $r['region_name'] : '—')) ?></td>
                            <td><?= esc((string) (($r['address_name'] ?? '') !== '' ? $r['address_name'] : '—')) ?></td>
                            <td dir="ltr"><?= esc(sal_rep_visit_fmt_ts($r['visit_checkin_at'] ?? null)) ?></td>
                            <td><?= esc((string) ($r['checkin_method_label'] ?? '—')) ?></td>
                            <td dir="ltr"><?= esc(sal_rep_visit_fmt_ts($r['visit_checkout_at'] ?? null)) ?></td>
                            <td><?= esc((string) ($r['checkout_method_label'] ?? '—')) ?></td>
                            <td dir="ltr"><?= esc((string) ($r['duration_label'] ?? '—')) ?></td>
                            <td><?= esc((string) ($r['status_label'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td dir="ltr"><?= $i + 1 ?></td>
                        <td><?= esc(sal_rep_visit_date_with_weekday((string) ($r['route_date'] ?? ''))) ?></td>
                        <td><?= esc((string) ($r['sales_rep_name'] ?? '')) ?></td>
                        <td><?= esc((string) ($r['customer_name'] ?? '')) ?></td>
                        <td dir="ltr"><?= esc((string) ($r['customer_code'] ?? '')) ?></td>
                        <td><?= esc((string) (($r['region_name'] ?? '') !== '' ? $r['region_name'] : '—')) ?></td>
                        <td><?= esc((string) (($r['address_name'] ?? '') !== '' ? $r['address_name'] : '—')) ?></td>
                        <td dir="ltr"><?= esc(sal_rep_visit_fmt_ts($r['visit_checkin_at'] ?? null)) ?></td>
                        <td><?= esc((string) ($r['checkin_method_label'] ?? '—')) ?></td>
                        <td dir="ltr"><?= esc(sal_rep_visit_fmt_ts($r['visit_checkout_at'] ?? null)) ?></td>
                        <td><?= esc((string) ($r['checkout_method_label'] ?? '—')) ?></td>
                        <td dir="ltr"><?= esc((string) ($r['duration_label'] ?? '—')) ?></td>
                        <td><?= esc((string) ($r['status_label'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
