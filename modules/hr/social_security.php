<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_employee_ensure_schema($pdo);
$listUrl = app_url('index.php?r=hr_social_security');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }
    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $empId = (int) ($_POST['employee_id'] ?? 0);
            $year = (int) ($_POST['pay_year'] ?? 0);
            $month = (int) ($_POST['pay_month'] ?? 0);
            $baseAmount = (float) ($_POST['base_amount'] ?? 0);
            $empShare = (float) ($_POST['employee_share'] ?? 0);
            $erShare = (float) ($_POST['employer_share'] ?? 0);
            $payDate = trim((string) ($_POST['pay_date'] ?? ''));
            $ref = trim((string) ($_POST['reference_no'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($empId < 1) {
                throw new RuntimeException('اختر الموظف.');
            }
            if ($year < 2000 || $year > 2100) {
                throw new RuntimeException('السنة غير صحيحة.');
            }
            if ($month < 1 || $month > 12) {
                throw new RuntimeException('الشهر غير صحيح.');
            }

            $total = round($empShare + $erShare, 3);

            if ($id > 0) {
                $st = $pdo->prepare(
                    'UPDATE hr_social_security SET employee_id = ?, pay_year = ?, pay_month = ?, base_amount = ?,
                     employee_share = ?, employer_share = ?, total_share = ?, pay_date = ?, reference_no = ?, notes = ?
                     WHERE id = ?'
                );
                $st->execute([
                    $empId, $year, $month, $baseAmount, $empShare, $erShare, $total,
                    $payDate !== '' ? $payDate : null, $ref !== '' ? $ref : null, $notes !== '' ? $notes : null, $id,
                ]);
                flash_set('success', 'تم تعديل قيد الضمان.');
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO hr_social_security (employee_id, pay_year, pay_month, base_amount, employee_share,
                     employer_share, total_share, pay_date, reference_no, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $empId, $year, $month, $baseAmount, $empShare, $erShare, $total,
                    $payDate !== '' ? $payDate : null, $ref !== '' ? $ref : null, $notes !== '' ? $notes : null,
                ]);
                flash_set('success', 'تم إضافة قيد الضمان.');
            }
            redirect($listUrl);
        }
        if ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $st = $pdo->prepare('DELETE FROM hr_social_security WHERE id = ?');
                $st->execute([$id]);
                flash_set('success', 'تم الحذف.');
            }
            redirect($listUrl);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($listUrl . (isset($id) && $id > 0 ? '&action=edit&id=' . $id : '&action=add'));
    }
}

$flash = flash_get();
$action = (string) ($_GET['action'] ?? '');
$employees = hr_employee_active_list($pdo);

$cssPath = app_path('assets/css/hr-social-security.css');
$cssUrl = app_url('assets/css/hr-social-security.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssPathSalesOra = app_path('assets/css/hr-social-security-sales-ora12.css');
$cssUrlSalesOra = app_url('assets/css/hr-social-security-sales-ora12.css')
    . (is_file($cssPathSalesOra) ? '?v=' . (string) filemtime($cssPathSalesOra) : '');
$exitUrl = nav_exit_url('hr_social_security');

if ($action === 'add' || $action === 'edit') {
    $row = [
        'id' => 0, 'employee_id' => 0, 'pay_year' => (int) date('Y'), 'pay_month' => (int) date('n'),
        'base_amount' => 0, 'employee_share' => 0, 'employer_share' => 0, 'total_share' => 0,
        'pay_date' => '', 'reference_no' => '', 'notes' => '',
    ];
    if ($action === 'edit') {
        $id = (int) ($_GET['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM hr_social_security WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $dbRow = $st->fetch(PDO::FETCH_ASSOC);
        if (!$dbRow) {
            flash_set('error', 'القيد غير موجود.');
            redirect($listUrl);
        }
        $row = array_merge($row, $dbRow);
    }
    $formTitle = $action === 'add' ? 'إضافة اشتراك ضمان' : 'تعديل اشتراك ضمان';
    ?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrlSalesOra) ?>">

<div class="dashboard-ora hr-ss-ora12-screen hr-ss-wrap hr-ss-form-page hr-ss-ora-screen"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text"><?= esc($formTitle) ?></h1>
        <?php nav_render_screen_close('hr_social_security'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-ss-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <section class="dashboard-ora-panel hr-ss-form-panel">
        <h2 class="dashboard-ora-panel__title">بيانات الاشتراك</h2>
        <div class="dashboard-ora-panel__body">
        <form method="post" action="<?= esc($listUrl) ?>" class="hr-ss-form">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">

            <div class="form-row">
                <label class="field">
                    <span class="field-label">الموظف *</span>
                    <select class="input" name="employee_id" required>
                        <option value="">— اختر الموظف —</option>
                        <?php foreach ($employees as $e): ?>
                            <option value="<?= (int) $e['id'] ?>" <?= (int) $row['employee_id'] === (int) $e['id'] ? 'selected' : '' ?>>
                                <?= esc((string) $e['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span class="field-label">السنة</span>
                    <input class="input" name="pay_year" type="number" min="2000" max="2100" value="<?= esc((string) $row['pay_year']) ?>" required>
                </label>
                <label class="field">
                    <span class="field-label">الشهر</span>
                    <select class="input" name="pay_month" required>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= (int) $row['pay_month'] === $m ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
            </div>

            <div class="form-row">
                <label class="field">
                    <span class="field-label">قيمة الاشتراك (الراتب المعتمد)</span>
                    <input class="input" name="base_amount" type="number" step="0.001" value="<?= esc((string) $row['base_amount']) ?>">
                </label>
                <label class="field">
                    <span class="field-label">حصة الموظف</span>
                    <input class="input" name="employee_share" type="number" step="0.001" value="<?= esc((string) $row['employee_share']) ?>">
                </label>
                <label class="field">
                    <span class="field-label">حصة صاحب العمل</span>
                    <input class="input" name="employer_share" type="number" step="0.001" value="<?= esc((string) $row['employer_share']) ?>">
                </label>
            </div>

            <div class="form-row">
                <label class="field">
                    <span class="field-label">تاريخ الدفع</span>
                    <input class="input" name="pay_date" type="date" value="<?= esc((string) ($row['pay_date'] ?? '')) ?>">
                </label>
                <label class="field">
                    <span class="field-label">رقم سند الدفع / المرجع</span>
                    <input class="input" name="reference_no" value="<?= esc((string) ($row['reference_no'] ?? '')) ?>">
                </label>
            </div>

            <label class="field field--wide">
                <span class="field-label">ملاحظات</span>
                <textarea class="input" name="notes" rows="3"><?= esc((string) ($row['notes'] ?? '')) ?></textarea>
            </label>

            <div class="hr-ss-form-actions">
                <button class="btn btn-primary" type="submit">حفظ</button>
                <a class="btn btn-secondary hr-ss-back-link" href="<?= esc($listUrl) ?>">رجوع للقائمة</a>
            </div>
        </form>
        </div>
    </section>
    </div><!-- .dashboard-ora-workspace -->
</div>
    <?php
    return;
}

$filterYear = (int) ($_GET['year'] ?? date('Y'));
$filterMonth = isset($_GET['month']) && $_GET['month'] !== '' ? (int) $_GET['month'] : 0;
$filterEmp = isset($_GET['employee_id']) && $_GET['employee_id'] !== '' ? (int) $_GET['employee_id'] : 0;

$where = ' WHERE 1=1';
$params = [];
if ($filterYear > 0) { $where .= ' AND s.pay_year = ?'; $params[] = $filterYear; }
if ($filterMonth > 0) { $where .= ' AND s.pay_month = ?'; $params[] = $filterMonth; }
if ($filterEmp > 0) { $where .= ' AND s.employee_id = ?'; $params[] = $filterEmp; }

$sql = 'SELECT s.id, s.pay_year, s.pay_month, s.base_amount, s.employee_share, s.employer_share,
               s.total_share, s.pay_date, s.reference_no, e.name_ar AS emp_name, e.social_security_no
        FROM hr_social_security s
        JOIN hr_employee e ON e.id = s.employee_id'
        . $where
        . ' ORDER BY s.pay_year DESC, s.pay_month DESC, e.name_ar ASC';

try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $rows = [];
}

$totals = ['base' => 0, 'emp' => 0, 'er' => 0, 'total' => 0];
foreach ($rows as $r) {
    $totals['base'] += (float) $r['base_amount'];
    $totals['emp'] += (float) $r['employee_share'];
    $totals['er'] += (float) $r['employer_share'];
    $totals['total'] += (float) $r['total_share'];
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrlSalesOra) ?>">

<div class="dashboard-ora hr-ss-ora12-screen hr-ss-wrap hr-ss-list-page hr-ss-ora-screen"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">قيود الضمان الاجتماعي</h1>
        <?php nav_render_screen_close('hr_social_security'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-ss-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="dashboard-ora-toolbar hr-ss-top-bar hr-ss-toolbar">
        <a class="btn btn-primary btn-sm" href="<?= esc(app_url('index.php?r=hr_social_security&action=add')) ?>">اشتراك جديد</a>
    </div>

    <section class="dashboard-ora-panel hr-ss-filter-panel">
        <h2 class="dashboard-ora-panel__title">تصفية السجلات</h2>
        <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="hr-ss-filter-form">
            <input type="hidden" name="r" value="hr_social_security">
            <label class="field hr-ss-filter-year">
                <span class="field-label">السنة</span>
                <input class="input" type="number" name="year" value="<?= esc((string) $filterYear) ?>" min="2000" max="2100">
            </label>
            <label class="field hr-ss-filter-month">
                <span class="field-label">الشهر</span>
                <select class="input" name="month">
                    <option value="">الكل</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $filterMonth === $m ? 'selected' : '' ?>><?= $m ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <label class="field hr-ss-filter-emp">
                <span class="field-label">الموظف</span>
                <select class="input" name="employee_id">
                    <option value="">— الكل —</option>
                    <?php foreach ($employees as $e): ?>
                        <option value="<?= (int) $e['id'] ?>" <?= $filterEmp === (int) $e['id'] ? 'selected' : '' ?>>
                            <?= esc((string) $e['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="btn btn-secondary btn-sm">عرض</button>
            <a class="btn btn-ghost btn-sm" href="<?= esc($listUrl) ?>">مسح</a>
        </form>
        </div>
    </section>

    <section class="dashboard-ora-panel hr-ss-grid-panel">
        <h2 class="dashboard-ora-panel__title">سجلات الضمان الاجتماعي</h2>
        <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
        <div class="dashboard-ora-table-wrap hr-ss-grid-wrap">
            <table class="dashboard-ora-table hr-ss-grid-table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>السنة/الشهر</th>
                    <th>الموظف</th>
                    <th>رقم الضمان</th>
                    <th>الأساس</th>
                    <th>حصة الموظف</th>
                    <th>حصة صاحب العمل</th>
                    <th>الإجمالي</th>
                    <th>تاريخ الدفع</th>
                    <th>المرجع</th>
                    <th>إجراءات</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="11" class="muted" style="text-align:center;">لا توجد سجلات.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= (int) $r['id'] ?></td>
                        <td dir="ltr"><?= (int) $r['pay_year'] ?>/<?= str_pad((string) $r['pay_month'], 2, '0', STR_PAD_LEFT) ?></td>
                        <td><?= esc((string) $r['emp_name']) ?></td>
                        <td dir="ltr"><code><?= esc((string) ($r['social_security_no'] ?? '—')) ?></code></td>
                        <td class="num"><?= esc(number_format((float) $r['base_amount'], 2)) ?></td>
                        <td class="num"><?= esc(number_format((float) $r['employee_share'], 2)) ?></td>
                        <td class="num"><?= esc(number_format((float) $r['employer_share'], 2)) ?></td>
                        <td class="num"><?= esc(number_format((float) $r['total_share'], 2)) ?></td>
                        <td><?= esc((string) ($r['pay_date'] ?? '—')) ?></td>
                        <td><?= esc((string) ($r['reference_no'] ?? '—')) ?></td>
                        <td class="hr-ss-actions">
                            <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=hr_social_security&action=edit&id=' . (int) $r['id'])) ?>">تعديل</a>
                            <form method="post" action="<?= esc($listUrl) ?>" onsubmit="return confirm('حذف القيد؟');">
                                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                <input type="hidden" name="_action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if ($rows): ?>
                <tfoot>
                    <tr>
                        <td colspan="4" class="num">الإجمالي</td>
                        <td class="num"><?= esc(number_format($totals['base'], 2)) ?></td>
                        <td class="num"><?= esc(number_format($totals['emp'], 2)) ?></td>
                        <td class="num"><?= esc(number_format($totals['er'], 2)) ?></td>
                        <td class="num"><?= esc(number_format($totals['total'], 2)) ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
        </div>
    </section>
    </div><!-- .dashboard-ora-workspace -->
</div>
