<?php
declare(strict_types=1);

require_permission('report_sales_rep_tours');

require_once app_path('includes/sal_rep_route.php');

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

// تأكد من أعمدة الزيارة
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

function _tour_report_fmt_ts($v): string
{
    $s = trim((string) $v);
    if ($s === '') {
        return '—';
    }
    $iso = substr($s, 0, 10);
    $t = strlen($s) >= 16 ? substr($s, 11, 8) : '';

    return format_date_dmY($iso) . ($t !== '' ? ' ' . $t : '');
}

function _tour_report_method($v): string
{
    $m = strtoupper(trim((string) $v));

    return $m === '' ? '—' : ($m === 'GPS' ? 'GPS' : (string) $v);
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<div class="card report-sales-page">
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
        </form>
    </header>

    <div class="report-sales-table-wrap">
        <table class="report-sales-table" dir="rtl">
            <thead>
            <tr>
                <th>#</th>
                <th>رقم الجولة</th>
                <th>المندوب</th>
                <th>تاريخ البداية</th>
                <th>تاريخ النهاية</th>
                <th>الحالة</th>
                <th>العميل</th>
                <th>الرمز</th>
                <th>المنطقة</th>
                <th>العنوان</th>
                <th>وقت الدخول</th>
                <th>وقت الخروج</th>
                <th>طريقة الدخول</th>
                <th>طريقة الخروج</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="14" class="muted">لا جولات في الفترة المحددة.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td dir="ltr"><?= $i + 1 ?></td>
                        <td dir="ltr"><?= (int) ($r['tour_id'] ?? 0) ?></td>
                        <td>
                            <?= esc((string) ($r['sales_rep_name'] ?? '')) ?>
                            <?php if (!empty($r['sales_rep_code'])): ?>
                                <span class="muted" dir="ltr">(<?= esc((string) $r['sales_rep_code']) ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td dir="ltr"><?= esc(format_date_dmY((string) ($r['date_from'] ?? ''))) ?></td>
                        <td dir="ltr"><?= esc(format_date_dmY((string) ($r['date_to'] ?? ''))) ?></td>
                        <td><?= (string) ($r['status'] ?? '') === 'posted' ? 'مرحّلة' : 'مسودة' ?></td>
                        <td><?= esc((string) ($r['customer_name'] ?? '')) ?></td>
                        <td dir="ltr"><?= esc((string) ($r['customer_code'] ?? '')) ?></td>
                        <td><?= esc((string) (($r['region_name'] ?? '') !== '' ? $r['region_name'] : '—')) ?></td>
                        <td><?= esc((string) (($r['address_name'] ?? '') !== '' ? $r['address_name'] : '—')) ?></td>
                        <td dir="ltr" class="muted"><?= esc(_tour_report_fmt_ts($r['visit_checkin_at'] ?? null)) ?></td>
                        <td dir="ltr" class="muted"><?= esc(_tour_report_fmt_ts($r['visit_checkout_at'] ?? null)) ?></td>
                        <td class="muted"><?= esc(_tour_report_method($r['checkin_method'] ?? null)) ?></td>
                        <td class="muted"><?= esc(_tour_report_method($r['checkout_method'] ?? null)) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
