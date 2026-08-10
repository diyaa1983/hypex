<?php
declare(strict_types=1);

require_permission('report_item_price_adjustments');

require_once app_path('includes/inv_price_adj_schema.php');
require_once app_path('includes/inv_item_display.php');
require_once app_path('includes/inv_item_sale_price_adj.php');

$pdo = db();
inv_price_adj_ensure_schema($pdo);
inv_price_adj_ensure_wholesale_columns($pdo);

$from = parse_date_to_iso(trim((string) ($_GET['from'] ?? ''))) ?? date('Y-m-01');
$to = parse_date_to_iso(trim((string) ($_GET['to'] ?? ''))) ?? date('Y-m-d');
$q = trim((string) ($_GET['q'] ?? ''));

$hasWh = false;
try {
    $pdo->query('SELECT old_wholesale FROM inv_item_sale_price_adj LIMIT 1');
    $hasWh = true;
} catch (Throwable $e) {
    $hasWh = false;
}

$itemNoSql = inv_item_sql_material_number($pdo, 'i');
$whCols = $hasWh ? ', l.old_wholesale, l.new_wholesale' : ', 0 AS old_wholesale, 0 AS new_wholesale';
$params = [$from, $to];
$where = "l.status = 'posted' AND DATE(COALESCE(l.posted_at, d.posted_at, l.created_at)) BETWEEN ? AND ?";
if ($q !== '') {
    $where .= " AND (IFNULL(d.adj_no,'') LIKE ? OR IFNULL(i.name_ar,'') LIKE ? OR IFNULL(i.barcode,'') LIKE ? OR IFNULL(i.sku,'') LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$rows = [];
try {
    $sql = "SELECT l.id, l.old_sale_price, l.new_sale_price{$whCols},
                   l.posted_at, l.created_at,
                   d.adj_no, d.adj_date, d.posted_at AS doc_posted_at,
                   {$itemNoSql} AS item_code, i.name_ar AS item_name,
                   COALESCE(p.full_name_ar, c.full_name_ar, '') AS employee_name
            FROM inv_item_sale_price_adj l
            LEFT JOIN inv_price_adj_doc d ON d.id = l.doc_id
            INNER JOIN inv_item i ON i.id = l.item_id
            LEFT JOIN sys_user p ON p.id = d.posted_by
            LEFT JOIN sys_user c ON c.id = COALESCE(d.created_by, l.created_by)
            WHERE {$where}
            ORDER BY COALESCE(l.posted_at, d.posted_at, l.created_at) DESC, l.id DESC
            LIMIT 1000";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $rows = [];
}

$selfUrl = app_url('index.php?r=report_item_price_adjustments');
$adjUrl = app_url('index.php?r=item_sale_price_adjust');
$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<div class="card report-sales-page">
    <header class="report-sales-header no-print">
        <h2>تقرير الأسعار المعدّلة</h2>
        <p class="muted">تعديلات البيع والجملة المرحّلة — السعر القديم والجديد والتاريخ والموظف</p>
        <form method="get" class="report-filters" action="<?= esc(app_url('index.php')) ?>">
            <input type="hidden" name="r" value="report_item_price_adjustments">
            <label>من
                <input type="date" name="from" value="<?= esc($from) ?>" dir="ltr">
            </label>
            <label>إلى
                <input type="date" name="to" value="<?= esc($to) ?>" dir="ltr">
            </label>
            <label>بحث
                <input type="search" name="q" value="<?= esc($q) ?>" placeholder="حركة / مادة / باركود">
            </label>
            <button type="submit" class="btn btn-primary">عرض</button>
            <a class="btn" href="<?= esc($adjUrl) ?>">شاشة التعديل</a>
        </form>
    </header>

    <div class="report-sales-table-wrap">
        <table class="report-sales-table" dir="rtl">
            <thead>
            <tr>
                <th>#</th>
                <th>رقم الحركة</th>
                <th>التاريخ والساعة</th>
                <th>الباركود</th>
                <th>المادة</th>
                <th>بيع قديم</th>
                <th>بيع جديد</th>
                <th>جملة قديم</th>
                <th>جملة جديد</th>
                <th>الموظف</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="10" class="muted">لا تعديلات مرحّلة في الفترة.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $i => $r): ?>
                    <?php
                    $ts = (string) ($r['posted_at'] ?? $r['doc_posted_at'] ?? $r['created_at'] ?? '');
                    $tsShow = $ts !== ''
                        ? format_date_dmY(substr($ts, 0, 10)) . ' ' . substr($ts, 11, 8)
                        : '—';
                    ?>
                    <tr>
                        <td dir="ltr"><?= $i + 1 ?></td>
                        <td dir="ltr"><?= esc((string) ($r['adj_no'] ?? '—')) ?></td>
                        <td dir="ltr"><?= esc($tsShow) ?></td>
                        <td dir="ltr"><?= esc((string) ($r['item_code'] ?? '')) ?></td>
                        <td><?= esc((string) ($r['item_name'] ?? '')) ?></td>
                        <td dir="ltr"><?= esc(inv_item_sale_price_adj_format_price((float) ($r['old_sale_price'] ?? 0), $pdo)) ?></td>
                        <td dir="ltr"><?= esc(inv_item_sale_price_adj_format_price((float) ($r['new_sale_price'] ?? 0), $pdo)) ?></td>
                        <td dir="ltr"><?= esc(inv_item_sale_price_adj_format_price((float) ($r['old_wholesale'] ?? 0), $pdo)) ?></td>
                        <td dir="ltr"><?= esc(inv_item_sale_price_adj_format_price((float) ($r['new_wholesale'] ?? 0), $pdo)) ?></td>
                        <td><?= esc((string) ($r['employee_name'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
