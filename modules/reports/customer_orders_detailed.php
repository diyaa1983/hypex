<?php
declare(strict_types=1);

/**
 * تقرير تفصيلي لطلبات الشراء — حسب فئة المادة وكل مندوب.
 * تجميع: مندوب←فئة أو فئة←مندوب + ملخص مصفوفة فئة×مندوب.
 * الشاشة الأساسية على Node: /sales/reports/customer-orders-detailed
 */
require_permission('report_customer_orders_detailed');
require_once app_path('includes/sal_customer_order.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/crm_sales_rep_schema.php');

$pdo = db();
sal_customer_order_ensure_schema($pdo);
crm_sales_rep_ensure_schema($pdo);

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}
$salesRepId = isset($_GET['sales_rep_id']) && $_GET['sales_rep_id'] !== '' ? (int) $_GET['sales_rep_id'] : 0;
$categoryId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int) $_GET['category_id'] : 0;
$status = trim((string) ($_GET['status'] ?? 'all'));
if (!in_array($status, ['all', 'draft', 'approved'], true)) {
    $status = 'all';
}
$groupBy = trim((string) ($_GET['group_by'] ?? 'rep_category'));
if ($groupBy !== 'category_rep') {
    $groupBy = 'rep_category';
}
$run = isset($_GET['run']) && (string) $_GET['run'] === '1';

$reps = $pdo->query('SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar')
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
$categories = $pdo->query('SELECT id, name_ar FROM inv_item_category WHERE is_active = 1 ORDER BY name_ar')
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];

$groups = [];
$matrix = [];
$grand = ['qty' => 0.0, 'gross' => 0.0, 'lines' => 0, 'orders' => []];
$err = '';

if ($run) {
    $fromIso = parse_date_to_iso($from);
    $toIso = parse_date_to_iso($to);
    if ($fromIso === null || $toIso === null) {
        $err = 'تاريخ البداية والنهاية غير صالحين.';
    } elseif ($fromIso > $toIso) {
        $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
    } else {
        $from = $fromIso;
        $to = $toIso;
        $where = ['o.order_date BETWEEN ? AND ?', 'IFNULL(o.is_sent,1) = 1'];
        $params = [$from, $to];
        if ($salesRepId > 0) {
            $where[] = 'COALESCE(o.sales_rep_id, c.sales_rep_id) = ?';
            $params[] = $salesRepId;
        }
        if ($categoryId > 0) {
            $where[] = 'it.category_id = ?';
            $params[] = $categoryId;
        }
        if ($status === 'draft' || $status === 'approved') {
            $where[] = 'o.status = ?';
            $params[] = $status;
        }
        $hasDelivery = sal_customer_order_has_column($pdo, 'sal_customer_order', 'delivery_date');
        $hasQtyExtra = sal_customer_order_has_column($pdo, 'sal_customer_order_line', 'qty_extra');
        $sql = 'SELECT o.id AS order_id, o.order_no, o.order_date, o.status,'
            . ($hasDelivery ? ' o.delivery_date,' : ' NULL AS delivery_date,')
            . ' c.name_ar AS customer_name,
                   COALESCE(sr.id, 0) AS sales_rep_id,
                   COALESCE(NULLIF(TRIM(sr.name_ar), \'\'), \'— بدون مندوب —\') AS sales_rep_name,
                   COALESCE(sr.code, \'\') AS sales_rep_code,
                   COALESCE(cat.id, 0) AS category_id,
                   COALESCE(NULLIF(TRIM(cat.name_ar), \'\'), \'— بدون فئة —\') AS category_name,
                   COALESCE(NULLIF(TRIM(it.name_ar), \'\'), NULLIF(TRIM(l.item_name), \'\'), \'\') AS item_name,
                   COALESCE(NULLIF(TRIM(it.sku), \'\'), it.barcode, \'\') AS item_sku,
                   COALESCE(NULLIF(TRIM(l.unit_name), \'\'), \'قطعة\') AS unit_name,
                   COALESCE(l.qty, 0) AS qty,'
            . ($hasQtyExtra ? ' COALESCE(l.qty_extra, 0) AS qty_extra,' : ' 0 AS qty_extra,')
            . ' COALESCE(l.unit_price, 0) AS unit_price,
                   COALESCE(l.line_gross, l.line_total, 0) AS line_gross
            FROM sal_customer_order_line l
            INNER JOIN sal_customer_order o ON o.id = l.order_id
            INNER JOIN crm_customer c ON c.id = o.customer_id
            LEFT JOIN crm_sales_rep sr ON sr.id = COALESCE(o.sales_rep_id, c.sales_rep_id)
            INNER JOIN inv_item it ON it.id = l.item_id
            LEFT JOIN inv_item_category cat ON cat.id = it.category_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ' . ($groupBy === 'category_rep'
                ? 'category_name ASC, category_id ASC, sales_rep_name ASC, sales_rep_id ASC,'
                : 'sales_rep_name ASC, sales_rep_id ASC, category_name ASC, category_id ASC,') . '
                     o.order_date ASC, o.id ASC, l.line_no ASC
            LIMIT 5000';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $repMap = [];
        $catMap = [];
        $matrixMap = [];
        foreach ($rows as $r) {
            $rid = (int) ($r['sales_rep_id'] ?? 0);
            $cid = (int) ($r['category_id'] ?? 0);
            $qty = (float) ($r['qty'] ?? 0) + (float) ($r['qty_extra'] ?? 0);
            $gross = (float) ($r['line_gross'] ?? 0);
            $row = $r + ['qty_total' => $qty];
            $mk = $cid . '|' . $rid;
            if (!isset($matrixMap[$mk])) {
                $matrixMap[$mk] = [
                    'category_name' => (string) ($r['category_name'] ?? ''),
                    'sales_rep_name' => (string) ($r['sales_rep_name'] ?? ''),
                    'sales_rep_code' => (string) ($r['sales_rep_code'] ?? ''),
                    'qty' => 0.0,
                    'gross' => 0.0,
                    'lines' => 0,
                    'orders' => [],
                ];
            }
            $matrixMap[$mk]['qty'] += $qty;
            $matrixMap[$mk]['gross'] += $gross;
            $matrixMap[$mk]['lines']++;
            $oid = (int) ($r['order_id'] ?? 0);
            if ($oid > 0) {
                $matrixMap[$mk]['orders'][$oid] = true;
                $grand['orders'][$oid] = true;
            }
            $grand['qty'] += $qty;
            $grand['gross'] += $gross;
            $grand['lines']++;

            if ($groupBy === 'category_rep') {
                if (!isset($catMap[$cid])) {
                    $catMap[$cid] = [
                        'name' => (string) ($r['category_name'] ?? ''),
                        'reps' => [],
                        'qty' => 0.0,
                        'gross' => 0.0,
                        'orders' => [],
                    ];
                }
                if (!isset($catMap[$cid]['reps'][$rid])) {
                    $catMap[$cid]['reps'][$rid] = [
                        'name' => (string) ($r['sales_rep_name'] ?? ''),
                        'code' => (string) ($r['sales_rep_code'] ?? ''),
                        'rows' => [],
                        'qty' => 0.0,
                        'gross' => 0.0,
                    ];
                }
                $catMap[$cid]['reps'][$rid]['rows'][] = $row;
                $catMap[$cid]['reps'][$rid]['qty'] += $qty;
                $catMap[$cid]['reps'][$rid]['gross'] += $gross;
                $catMap[$cid]['qty'] += $qty;
                $catMap[$cid]['gross'] += $gross;
                if ($oid > 0) {
                    $catMap[$cid]['orders'][$oid] = true;
                }
            } else {
                if (!isset($repMap[$rid])) {
                    $repMap[$rid] = [
                        'name' => (string) ($r['sales_rep_name'] ?? ''),
                        'code' => (string) ($r['sales_rep_code'] ?? ''),
                        'cats' => [],
                        'qty' => 0.0,
                        'gross' => 0.0,
                        'orders' => [],
                    ];
                }
                if (!isset($repMap[$rid]['cats'][$cid])) {
                    $repMap[$rid]['cats'][$cid] = [
                        'name' => (string) ($r['category_name'] ?? ''),
                        'rows' => [],
                        'qty' => 0.0,
                        'gross' => 0.0,
                    ];
                }
                $repMap[$rid]['cats'][$cid]['rows'][] = $row;
                $repMap[$rid]['cats'][$cid]['qty'] += $qty;
                $repMap[$rid]['cats'][$cid]['gross'] += $gross;
                $repMap[$rid]['qty'] += $qty;
                $repMap[$rid]['gross'] += $gross;
                if ($oid > 0) {
                    $repMap[$rid]['orders'][$oid] = true;
                }
            }
        }
        $groups = $groupBy === 'category_rep' ? $catMap : $repMap;
        $matrix = array_values($matrixMap);
        usort($matrix, static function (array $a, array $b): int {
            return strcmp((string) $a['category_name'], (string) $b['category_name'])
                ?: strcmp((string) $a['sales_rep_name'], (string) $b['sales_rep_name']);
        });
    }
}

$statusLabel = static function (string $s): string {
    if ($s === 'approved') {
        return 'معتمد';
    }
    if ($s === 'draft') {
        return 'مسودة';
    }

    return $s !== '' ? $s : '—';
};
?>
<div class="dashboard-ora sales-ora12-screen report-sales-page">
    <h2>تقرير تفصيلي لطلبات الشراء</h2>
    <p class="muted">بنود الطلبات مفصّلة حسب كل فئة مادة وكل مندوب.</p>
    <?php if ($err !== ''): ?>
        <p class="flash flash-error"><?= esc($err) ?></p>
    <?php endif; ?>
    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="form-row" style="flex-wrap:wrap;gap:.5rem;align-items:end">
        <input type="hidden" name="r" value="report_customer_orders_detailed">
        <input type="hidden" name="run" value="1">
        <div class="field"><label>من تاريخ</label>
            <input class="input" type="date" name="from" value="<?= esc($from) ?>" required dir="ltr"></div>
        <div class="field"><label>إلى تاريخ</label>
            <input class="input" type="date" name="to" value="<?= esc($to) ?>" required dir="ltr"></div>
        <div class="field"><label>طريقة التجميع</label>
            <select class="input" name="group_by">
                <option value="rep_category" <?= $groupBy === 'rep_category' ? 'selected' : '' ?>>مندوب ← فئة المادة</option>
                <option value="category_rep" <?= $groupBy === 'category_rep' ? 'selected' : '' ?>>فئة المادة ← مندوب</option>
            </select>
        </div>
        <div class="field"><label>المندوب</label>
            <select class="input" name="sales_rep_id">
                <option value="0">— كل المندوبين —</option>
                <?php foreach ($reps as $rep): ?>
                    <option value="<?= (int) $rep['id'] ?>" <?= $salesRepId === (int) $rep['id'] ? 'selected' : '' ?>>
                        <?= esc((string) $rep['name_ar']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label>فئة المادة</label>
            <select class="input" name="category_id">
                <option value="0">— كل الفئات —</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>" <?= $categoryId === (int) $cat['id'] ? 'selected' : '' ?>>
                        <?= esc((string) $cat['name_ar']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field"><label>الحالة</label>
            <select class="input" name="status">
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>الكل</option>
                <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>مسودة</option>
                <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>معتمد</option>
            </select>
        </div>
        <button class="btn btn-primary">عرض التقرير</button>
    </form>

    <?php if ($run && $err === ''): ?>
        <p><strong><?= (int) $grand['lines'] ?></strong> بند ·
            <strong><?= count($grand['orders']) ?></strong> طلب ·
            إجمالي <strong dir="ltr"><?= esc(format_amount((float) $grand['gross'])) ?></strong>
        </p>
        <?php if ($matrix !== []): ?>
            <h3 style="margin-top:1rem;font-size:1rem">ملخص حسب فئة المادة والمندوب</h3>
            <div class="table-wrap" style="margin-bottom:1rem">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>فئة المادة</th>
                        <th>المندوب</th>
                        <th>البنود</th>
                        <th>الطلبات</th>
                        <th>الكمية</th>
                        <th>الإجمالي</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($matrix as $m): ?>
                        <tr>
                            <td><?= esc((string) $m['category_name']) ?></td>
                            <td><?= esc(($m['sales_rep_code'] ? $m['sales_rep_code'] . ' — ' : '') . $m['sales_rep_name']) ?></td>
                            <td dir="ltr"><?= (int) $m['lines'] ?></td>
                            <td dir="ltr"><?= count($m['orders']) ?></td>
                            <td dir="ltr"><?= esc(format_amount((float) $m['qty'])) ?></td>
                            <td dir="ltr"><?= esc(format_amount((float) $m['gross'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>رقم الطلب</th>
                    <th>التاريخ</th>
                    <th>تاريخ التسليم</th>
                    <th>العميل</th>
                    <th>المادة</th>
                    <th>الكمية</th>
                    <th>الوحدة</th>
                    <th>السعر</th>
                    <th>الإجمالي</th>
                    <th>الحالة</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($groups === []): ?>
                    <tr><td colspan="11" class="muted">لا توجد بنود في الفترة.</td></tr>
                <?php elseif ($groupBy === 'category_rep'): ?>
                    <?php $seq = 0; foreach ($groups as $g): ?>
                        <tr style="background:#1e3a5f;color:#fff">
                            <td colspan="11"><strong>فئة المادة: <?= esc($g['name']) ?></strong>
                                · <?= count($g['orders']) ?> طلب · إجمالي <?= esc(format_amount((float) $g['gross'])) ?></td>
                        </tr>
                        <?php foreach ($g['reps'] as $rep): ?>
                            <tr style="background:#e8eef7">
                                <td colspan="11">المندوب: <strong><?= esc(($rep['code'] ? $rep['code'] . ' — ' : '') . $rep['name']) ?></strong>
                                    · <?= count($rep['rows']) ?> بند</td>
                            </tr>
                            <?php foreach ($rep['rows'] as $r): $seq++; ?>
                                <tr>
                                    <td dir="ltr"><?= $seq ?></td>
                                    <td dir="ltr"><code><?= esc((string) $r['order_no']) ?></code></td>
                                    <td dir="ltr"><?= esc(format_date_dmY((string) $r['order_date'])) ?></td>
                                    <td dir="ltr"><?= !empty($r['delivery_date']) ? esc(format_date_dmY(substr((string) $r['delivery_date'], 0, 10))) : '—' ?></td>
                                    <td><?= esc((string) $r['customer_name']) ?></td>
                                    <td><?= esc((string) $r['item_name']) ?><?php if (!empty($r['item_sku'])): ?> <span class="muted" dir="ltr">(<?= esc((string) $r['item_sku']) ?>)</span><?php endif; ?></td>
                                    <td dir="ltr"><?= esc(format_amount((float) ($r['qty_total'] ?? $r['qty']))) ?></td>
                                    <td><?= esc((string) ($r['unit_name'] ?? '—')) ?></td>
                                    <td dir="ltr"><?= esc(format_amount((float) $r['unit_price'])) ?></td>
                                    <td dir="ltr"><?= esc(format_amount((float) $r['line_gross'])) ?></td>
                                    <td><?= esc($statusLabel((string) ($r['status'] ?? ''))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background:#f8fafc">
                                <td colspan="6"><strong>مجموع المندوب ضمن الفئة · <?= esc($rep['name']) ?></strong></td>
                                <td dir="ltr"><strong><?= esc(format_amount((float) $rep['qty'])) ?></strong></td>
                                <td colspan="2"></td>
                                <td dir="ltr"><strong><?= esc(format_amount((float) $rep['gross'])) ?></strong></td>
                                <td></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="background:#dbeafe">
                            <td colspan="6"><strong>مجموع الفئة · <?= esc($g['name']) ?></strong></td>
                            <td dir="ltr"><strong><?= esc(format_amount((float) $g['qty'])) ?></strong></td>
                            <td colspan="2"></td>
                            <td dir="ltr"><strong><?= esc(format_amount((float) $g['gross'])) ?></strong></td>
                            <td></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr style="background:#1e3a5f;color:#fff">
                        <td colspan="6"><strong>الإجمالي النهائي</strong></td>
                        <td dir="ltr"><strong><?= esc(format_amount((float) $grand['qty'])) ?></strong></td>
                        <td colspan="2"></td>
                        <td dir="ltr"><strong><?= esc(format_amount((float) $grand['gross'])) ?></strong></td>
                        <td></td>
                    </tr>
                <?php else: ?>
                    <?php $seq = 0; foreach ($groups as $g): ?>
                        <tr style="background:#1e3a5f;color:#fff">
                            <td colspan="11"><strong>المندوب: <?= esc(($g['code'] ? $g['code'] . ' — ' : '') . $g['name']) ?></strong>
                                · <?= count($g['orders']) ?> طلب · إجمالي <?= esc(format_amount((float) $g['gross'])) ?></td>
                        </tr>
                        <?php foreach ($g['cats'] as $cat): ?>
                            <tr style="background:#e8eef7">
                                <td colspan="11">فئة المادة: <strong><?= esc($cat['name']) ?></strong>
                                    · <?= count($cat['rows']) ?> بند</td>
                            </tr>
                            <?php foreach ($cat['rows'] as $r): $seq++; ?>
                                <tr>
                                    <td dir="ltr"><?= $seq ?></td>
                                    <td dir="ltr"><code><?= esc((string) $r['order_no']) ?></code></td>
                                    <td dir="ltr"><?= esc(format_date_dmY((string) $r['order_date'])) ?></td>
                                    <td dir="ltr"><?= !empty($r['delivery_date']) ? esc(format_date_dmY(substr((string) $r['delivery_date'], 0, 10))) : '—' ?></td>
                                    <td><?= esc((string) $r['customer_name']) ?></td>
                                    <td><?= esc((string) $r['item_name']) ?><?php if (!empty($r['item_sku'])): ?> <span class="muted" dir="ltr">(<?= esc((string) $r['item_sku']) ?>)</span><?php endif; ?></td>
                                    <td dir="ltr"><?= esc(format_amount((float) ($r['qty_total'] ?? $r['qty']))) ?></td>
                                    <td><?= esc((string) ($r['unit_name'] ?? '—')) ?></td>
                                    <td dir="ltr"><?= esc(format_amount((float) $r['unit_price'])) ?></td>
                                    <td dir="ltr"><?= esc(format_amount((float) $r['line_gross'])) ?></td>
                                    <td><?= esc($statusLabel((string) ($r['status'] ?? ''))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background:#f8fafc">
                                <td colspan="6"><strong>مجموع الفئة · <?= esc($cat['name']) ?></strong></td>
                                <td dir="ltr"><strong><?= esc(format_amount((float) $cat['qty'])) ?></strong></td>
                                <td colspan="2"></td>
                                <td dir="ltr"><strong><?= esc(format_amount((float) $cat['gross'])) ?></strong></td>
                                <td></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="background:#dbeafe">
                            <td colspan="6"><strong>مجموع المندوب · <?= esc($g['name']) ?></strong></td>
                            <td dir="ltr"><strong><?= esc(format_amount((float) $g['qty'])) ?></strong></td>
                            <td colspan="2"></td>
                            <td dir="ltr"><strong><?= esc(format_amount((float) $g['gross'])) ?></strong></td>
                            <td></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr style="background:#1e3a5f;color:#fff">
                        <td colspan="6"><strong>الإجمالي النهائي</strong></td>
                        <td dir="ltr"><strong><?= esc(format_amount((float) $grand['qty'])) ?></strong></td>
                        <td colspan="2"></td>
                        <td dir="ltr"><strong><?= esc(format_amount((float) $grand['gross'])) ?></strong></td>
                        <td></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
