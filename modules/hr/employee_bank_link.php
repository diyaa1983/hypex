<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_employee_bank_link.php');
require_once app_path('includes/nav_helpers.php');

$pdo = db();
hr_employee_ensure_schema($pdo);
hr_salary_bank_ensure_schema($pdo);
$listUrl = app_url('index.php?r=hr_employee_bank_link');
$editorFormId = 'hr-emp-bank-editor-form';

$employees = [];
try {
    $employees = $pdo->query(
        'SELECT id, emp_code, name_ar, is_active FROM hr_employee ORDER BY is_active DESC, name_ar ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $employees = [];
}

$banks = hr_salary_bank_active_list($pdo);
$allBanksById = [];
foreach ($banks as $b) {
    $allBanksById[(int) $b['id']] = $b;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }
    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'save_one') {
            $empId = (int) ($_POST['employee_id'] ?? 0);
            $bankId = (int) ($_POST['salary_bank_id'] ?? 0);
            $bankAccount = trim((string) ($_POST['bank_account'] ?? ''));
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if ($empId < 1) {
                throw new RuntimeException('اختر الموظف.');
            }

            $stEmp = $pdo->prepare('SELECT id, emp_code FROM hr_employee WHERE id = ? LIMIT 1');
            $stEmp->execute([$empId]);
            $empRow = $stEmp->fetch(PDO::FETCH_ASSOC);
            if (!$empRow) {
                throw new RuntimeException('الموظف غير موجود.');
            }

            $bankName = null;
            if ($bankId > 0) {
                $stBank = $pdo->prepare('SELECT name_ar FROM hr_salary_bank WHERE id = ? AND is_active = 1 LIMIT 1');
                $stBank->execute([$bankId]);
                $bankName = (string) ($stBank->fetchColumn() ?: '');
                if ($bankName === '') {
                    throw new RuntimeException('البنك المختار غير موجود أو غير نشِط.');
                }
            } else {
                $bankId = 0;
            }

            $st = $pdo->prepare(
                'UPDATE hr_employee SET salary_bank_id = ?, bank_name = ?, bank_account = ?, is_active = ? WHERE id = ?'
            );
            $st->execute([
                $bankId > 0 ? $bankId : null,
                $bankName,
                $bankAccount !== '' ? $bankAccount : null,
                $isActive,
                $empId,
            ]);

            flash_set('success', 'تم حفظ ربط البنك للموظف.');
            redirect($listUrl);
        }

        if ($act === 'toggle_active') {
            $empId = (int) ($_POST['id'] ?? 0);
            $isActive = !empty($_POST['is_active']) ? 1 : 0;
            if ($empId > 0) {
                $st = $pdo->prepare('UPDATE hr_employee SET is_active = ? WHERE id = ?');
                $st->execute([$isActive, $empId]);
                flash_set('success', $isActive === 1 ? 'تم تفعيل الموظف.' : 'تم إيقاف الموظف.');
            }
            redirect($listUrl);
        }

        if ($act === 'clear_link') {
            $empId = (int) ($_POST['id'] ?? 0);
            if ($empId > 0) {
                $chk = hr_employee_bank_link_clear_check($pdo, $empId);
                if (!$chk['can_clear']) {
                    throw new RuntimeException((string) ($chk['message'] ?? 'لا يمكن إزالة الربط.'));
                }
                $st = $pdo->prepare(
                    'UPDATE hr_employee SET salary_bank_id = NULL, bank_name = NULL, bank_account = NULL WHERE id = ?'
                );
                $st->execute([$empId]);
                flash_set('success', 'تم إزالة ربط البنك عن الموظف.');
            }
            redirect($listUrl);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($listUrl);
    }
}

$flash = flash_get();

$st = $pdo->query(
    'SELECT e.id, e.emp_code, e.name_ar, e.is_active, e.salary_bank_id, e.bank_account,
            COALESCE(b.name_ar, e.bank_name, \'\') AS bank_name
     FROM hr_employee e
     LEFT JOIN hr_salary_bank b ON b.id = e.salary_bank_id
     WHERE IFNULL(e.salary_bank_id, 0) > 0
        OR TRIM(IFNULL(e.bank_account, \'\')) <> \'\'
        OR TRIM(IFNULL(e.bank_name, \'\')) <> \'\'
     ORDER BY CAST(e.emp_code AS UNSIGNED) ASC, e.id ASC'
);
$links = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($links as &$lnk) {
    $bid = (int) ($lnk['salary_bank_id'] ?? 0);
    if ($bid > 0 && !isset($allBanksById[$bid])) {
        $stB = $pdo->prepare('SELECT id, bank_code, name_ar FROM hr_salary_bank WHERE id = ? LIMIT 1');
        $stB->execute([$bid]);
        $extra = $stB->fetch(PDO::FETCH_ASSOC);
        if ($extra) {
            $banks[] = $extra;
            $allBanksById[$bid] = $extra;
            $lnk['bank_name'] = (string) ($extra['name_ar'] ?? $lnk['bank_name']);
        }
    }
}
unset($lnk);

$cssPath = app_path('assets/css/hr-employee-bank-link.css');
$cssUrl = app_url('assets/css/hr-employee-bank-link.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssPathSalesOra = app_path('assets/css/hr-employee-bank-link-sales-ora12.css');
$cssUrlSalesOra = app_url('assets/css/hr-employee-bank-link-sales-ora12.css')
    . (is_file($cssPathSalesOra) ? '?v=' . (string) filemtime($cssPathSalesOra) : '');
$jsPath = app_path('assets/js/hr-employee-bank-link.js');
$jsUrl = app_url('assets/js/hr-employee-bank-link.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
$exitUrl = nav_exit_url('hr_employee_bank_link');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrlSalesOra) ?>">

<div class="dashboard-ora hr-emp-bank-ora12-screen hr-emp-bank-wrap hr-emp-bank-grid-page hr-emp-bank-ora-screen"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-editor-form-id="<?= esc($editorFormId) ?>">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">ربط إعدادات البنك</h1>
        <?php nav_render_screen_close('hr_employee_bank_link'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-emp-bank-grid-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="dashboard-ora-toolbar hr-emp-bank-top-bar hr-emp-bank-toolbar">
        <button type="button" class="btn btn-primary btn-sm" id="hr-emp-bank-btn-add">ربط جديد</button>
        <button type="button" class="btn btn-secondary btn-sm" id="hr-emp-bank-btn-edit" disabled>تعديل</button>
        <button type="button" class="btn btn-danger btn-sm" id="hr-emp-bank-btn-del" disabled
                title="إزالة ربط البنك عن الموظف المحدد">حذف الربط</button>
    </div>
    <p class="hr-emp-bank-toolbar-hint muted">يمكن حذف الربط فقط إن لم تكن هناك حركات مرتبطة بالموظف.</p>

    <section class="dashboard-ora-panel hr-emp-bank-editor-panel hr-emp-bank-editor" id="hr-emp-bank-editor" hidden>
        <div class="dashboard-ora-panel__body">
        <button type="button" class="btn btn-ghost btn-sm hr-emp-bank-editor-close" id="hr-emp-bank-editor-close" aria-label="إغلاق">✕</button>
        <form id="<?= esc($editorFormId) ?>" method="post" action="<?= esc($listUrl) ?>" class="hr-emp-bank-editor-form">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save_one">
            <section class="dashboard-ora-panel hr-emp-bank-section">
            <h2 class="dashboard-ora-panel__title" id="hr-emp-bank-editor-title">إضافة ربط بنك</h2>
            <div class="dashboard-ora-panel__body">
            <div class="hr-emp-bank-editor-fields">
                <div class="field hr-emp-bank-editor-field-code">
                    <span class="field-label">رقم الموظف</span>
                    <div class="hr-emp-bank-code-display" id="hr-emp-bank-editor-code-display" dir="ltr" aria-readonly="true">—</div>
                    <small class="hr-emp-bank-code-hint">رقم الموظف ثابت ولا يُعدَّل من هنا</small>
                </div>
                <label class="field">
                    <span class="field-label required">الموظف</span>
                    <select class="input" name="employee_id" id="hr-emp-bank-editor-employee" required>
                        <option value="">— اختر موظفاً —</option>
                        <?php foreach ($employees as $e): ?>
                            <option value="<?= (int) $e['id'] ?>"
                                    data-emp-code="<?= esc((string) ($e['emp_code'] ?? '')) ?>"
                                    data-active="<?= (int) ($e['is_active'] ?? 1) ?>">
                                <?= esc((string) $e['name_ar']) ?>
                                <?php if (!empty($e['emp_code'])): ?>
                                    (<?= esc((string) $e['emp_code']) ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$employees): ?>
                        <small class="hr-emp-bank-warn">
                            <a href="<?= esc(app_url('index.php?r=hr_employees')) ?>">أضف موظفاً أولاً</a>
                        </small>
                    <?php endif; ?>
                </label>
                <label class="field">
                    <span class="field-label">البنك</span>
                    <select class="input" name="salary_bank_id" id="hr-emp-bank-editor-bank">
                        <option value="">— بدون بنك —</option>
                        <?php foreach ($banks as $b): ?>
                            <option value="<?= (int) $b['id'] ?>">
                                <?= esc((string) $b['name_ar']) ?>
                                <?php if (!empty($b['bank_code'])): ?>
                                    (<?= esc((string) $b['bank_code']) ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$banks): ?>
                        <small class="hr-emp-bank-warn">
                            <a href="<?= esc(app_url('index.php?r=hr_salary_banks')) ?>">أضف بنكاً</a>
                        </small>
                    <?php endif; ?>
                </label>
                <label class="field">
                    <span class="field-label">رقم الحساب / IBAN</span>
                    <input class="input" type="text" name="bank_account" id="hr-emp-bank-editor-iban"
                           placeholder="أدخل رقم الحساب أو IBAN" dir="ltr" autocomplete="off">
                </label>
                <label class="field hr-emp-bank-editor-field-active">
                    <span class="field-label">تنشيط</span>
                    <span class="hr-emp-bank-active-check">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" id="hr-emp-bank-editor-active" value="1" checked>
                        <span>موظف نشِط</span>
                    </span>
                </label>
            </div>
            </div>
            </section>
            <div class="hr-emp-bank-editor-actions">
                <button type="submit" class="btn btn-primary btn-sm">حفظ الربط</button>
                <button type="button" class="btn btn-ghost btn-sm" id="hr-emp-bank-editor-cancel">إلغاء</button>
            </div>
        </form>
        </div>
    </section>

    <section class="dashboard-ora-panel hr-emp-bank-grid-panel">
        <h2 class="dashboard-ora-panel__title">قائمة روابط البنك</h2>
        <div class="dashboard-ora-panel__body dashboard-ora-panel__body--flush">
        <div class="dashboard-ora-table-wrap hr-emp-bank-grid-wrap">
        <table class="dashboard-ora-table hr-emp-bank-grid-table">
            <thead>
            <tr>
                <th>رقم الموظف</th>
                <th>اسم الموظف</th>
                <th>البنك</th>
                <th>رقم الحساب</th>
                <th class="hr-emp-bank-col-active">تنشيط</th>
                <th class="hr-emp-bank-col-del">حذف</th>
            </tr>
            </thead>
            <tbody id="hr-emp-bank-grid-body">
            <?php if (!$links): ?>
                <tr class="hr-emp-bank-row hr-emp-bank-row--empty">
                    <td colspan="6" class="muted">لا توجد روابط بنك مسجّلة — اضغط «ربط جديد».</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($links as $lnk):
                $eid = (int) $lnk['id'];
                $code = (string) ($lnk['emp_code'] ?? '');
                $name = (string) ($lnk['name_ar'] ?? '');
                $bankName = (string) ($lnk['bank_name'] ?? '');
                $account = (string) ($lnk['bank_account'] ?? '');
                $bankId = (int) ($lnk['salary_bank_id'] ?? 0);
                $isActive = (int) ($lnk['is_active'] ?? 1) === 1;
                $hasLink = hr_employee_has_bank_link_row($lnk);
                $clrChk = hr_employee_bank_link_clear_check($pdo, $eid);
                $linked = !$clrChk['can_clear'];
            ?>
                <tr class="hr-emp-bank-row<?= $linked ? ' is-linked' : '' ?><?= !$isActive ? ' is-inactive' : '' ?><?= !$hasLink ? ' is-no-bank' : '' ?>"
                    data-id="<?= $eid ?>"
                    data-code="<?= esc($code) ?>"
                    data-name="<?= esc($name) ?>"
                    data-bank-id="<?= $bankId ?>"
                    data-bank-name="<?= esc($bankName) ?>"
                    data-account="<?= esc($account) ?>"
                    data-active="<?= $isActive ? '1' : '0' ?>"
                    data-has-link="<?= $hasLink ? '1' : '0' ?>"
                    data-linked="<?= $linked ? '1' : '0' ?>"
                    data-linked-msg="<?= esc((string) ($clrChk['message'] ?? '')) ?>"
                    tabindex="0">
                    <td><?= esc($code !== '' ? $code : '—') ?></td>
                    <td><?= esc($name !== '' ? $name : '—') ?></td>
                    <td><?= esc($bankName !== '' ? $bankName : '—') ?></td>
                    <td dir="ltr"><?= esc($account !== '' ? $account : '—') ?></td>
                    <td class="hr-emp-bank-cell-active">
                        <form method="post" action="<?= esc($listUrl) ?>" class="hr-emp-bank-toggle-form">
                            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                            <input type="hidden" name="_action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= $eid ?>">
                            <input type="hidden" name="is_active" value="0">
                            <label class="hr-emp-bank-toggle-chk" title="<?= $isActive ? 'إيقاف الموظف' : 'تفعيل الموظف' ?>">
                                <input type="checkbox" name="is_active" value="1" class="hr-emp-bank-active-cb"
                                    <?= $isActive ? 'checked' : '' ?>>
                                <span class="sr-only">تنشيط</span>
                            </label>
                        </form>
                    </td>
                    <td class="hr-emp-bank-cell-del">
                        <?php if ($linked): ?>
                            <button type="button" class="hr-emp-bank-row-del is-locked" disabled
                                    title="<?= esc((string) ($clrChk['message'] ?? 'لا يمكن الحذف')) ?>">حذف</button>
                        <?php else: ?>
                            <button type="button" class="hr-emp-bank-row-del" title="حذف ربط البنك"
                                    data-id="<?= $eid ?>"
                                    data-name="<?= esc($name) ?>">حذف</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
    </section>

    <form method="post" action="<?= esc($listUrl) ?>" id="hr-emp-bank-delete-form" class="sr-only" aria-hidden="true">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="clear_link">
        <input type="hidden" name="id" id="hr-emp-bank-delete-id" value="0">
    </form>
    </div><!-- .dashboard-ora-workspace -->
</div>

<script src="<?= esc($jsUrl) ?>" defer></script>
