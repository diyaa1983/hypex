<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_social_security_payroll.php');
require_once app_path('includes/hr_income_tax.php');
require_once app_path('includes/hr_oracle_ui.php');
require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/employee_picker.php');

$pdo = db();
hr_employee_ensure_schema($pdo);
$listUrl = app_url('index.php?r=hr_employees');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }
    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'post_resignation') {
            $id = (int) ($_POST['id'] ?? 0);
            $resignDate = trim((string) ($_POST['resignation_date'] ?? ''));
            if ($resignDate === '') {
                throw new RuntimeException('أدخل تاريخ الاستقالة قبل الترحيل.');
            }
            hr_employee_assert_editable($pdo, $id);
            $st = $pdo->prepare(
                'UPDATE hr_employee SET resignation_date = ?, is_active = 0, is_resigned_posted = 1 WHERE id = ?'
            );
            $st->execute([$resignDate, $id]);
            flash_set('success', 'تم ترحيل استقالة الموظف — البطاقة مغلقة للتعديل.');
            redirect($listUrl . '&id=' . $id);
        }
        if ($act === 'unpost_resignation') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('لا يوجد موظف محدد.');
            }
            $stChk = $pdo->prepare('SELECT is_resigned_posted FROM hr_employee WHERE id = ? LIMIT 1');
            $stChk->execute([$id]);
            if ((int) ($stChk->fetchColumn() ?: 0) !== 1) {
                throw new RuntimeException('الموظف غير مرحّل كمستقيل.');
            }
            $st = $pdo->prepare(
                'UPDATE hr_employee SET is_resigned_posted = 0, is_active = 1 WHERE id = ?'
            );
            $st->execute([$id]);
            flash_set('success', 'تم فك ترحيل الاستقالة — يمكن تعديل بطاقة الموظف.');
            redirect($listUrl . '&id=' . $id);
        }
        if ($act === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                hr_employee_assert_editable($pdo, $id);
            }
            $code = trim((string) ($_POST['emp_code'] ?? ''));
            $nameFirst = trim((string) ($_POST['name_first'] ?? ''));
            $nameFather = trim((string) ($_POST['name_father'] ?? ''));
            $nameGrandfather = trim((string) ($_POST['name_grandfather'] ?? ''));
            $nameFamily = trim((string) ($_POST['name_family'] ?? ''));
            $name = hr_employee_build_full_name($nameFirst, $nameFather, $nameGrandfather, $nameFamily);
            $nid = trim((string) ($_POST['national_id'] ?? ''));
            $gender = trim((string) ($_POST['gender'] ?? ''));
            if ($gender !== '' && !in_array($gender, ['male', 'female'], true)) {
                $gender = '';
            }
            $nationalityId = (int) ($_POST['nationality_id'] ?? 0);
            if ($nationalityId > 0) {
                hr_nationality_ensure_schema($pdo);
                $stNat = $pdo->prepare('SELECT id FROM hr_nationality WHERE id = ? LIMIT 1');
                $stNat->execute([$nationalityId]);
                if (!$stNat->fetchColumn()) {
                    $nationalityId = 0;
                }
            } else {
                $nationalityId = 0;
            }
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $addressAr = trim((string) ($_POST['address_ar'] ?? ''));
            $addressCity = trim((string) ($_POST['address_city'] ?? ''));
            $addressDistrict = trim((string) ($_POST['address_district'] ?? ''));
            $deptId = (int) ($_POST['department_id'] ?? 0);
            $jobTitleId = (int) ($_POST['job_title_id'] ?? 0);
            $dept = '';
            $job = '';
            if ($deptId > 0) {
                $stD = $pdo->prepare('SELECT name_ar FROM hr_department WHERE id = ? LIMIT 1');
                $stD->execute([$deptId]);
                $dept = (string) ($stD->fetchColumn() ?: '');
                if ($dept === '') { $deptId = 0; }
            }
            if ($jobTitleId > 0) {
                $stJ = $pdo->prepare('SELECT name_ar, department_id FROM hr_job_title WHERE id = ? LIMIT 1');
                $stJ->execute([$jobTitleId]);
                $jRow = $stJ->fetch(PDO::FETCH_ASSOC);
                if ($jRow) {
                    $job = (string) ($jRow['name_ar'] ?? '');
                    // إذا لم يحدد المستخدم قسماً، نأخذه من المسمى
                    if ($deptId < 1 && !empty($jRow['department_id'])) {
                        $deptId = (int) $jRow['department_id'];
                        $stD2 = $pdo->prepare('SELECT name_ar FROM hr_department WHERE id = ? LIMIT 1');
                        $stD2->execute([$deptId]);
                        $dept = (string) ($stD2->fetchColumn() ?: '');
                    }
                } else {
                    $jobTitleId = 0;
                }
            }
            $hire = trim((string) ($_POST['hire_date'] ?? ''));
            if (array_key_exists('base_salary', $_POST)) {
                $baseSalary = (float) $_POST['base_salary'];
            } elseif ($id > 0) {
                $stSal = $pdo->prepare('SELECT base_salary FROM hr_employee WHERE id = ? LIMIT 1');
                $stSal->execute([$id]);
                $baseSalary = (float) ($stSal->fetchColumn() ?: 0);
            } else {
                $baseSalary = 0.0;
            }
            $ssn = trim((string) ($_POST['social_security_no'] ?? ''));
            $subjectToSs = !empty($_POST['subject_to_social_security']) ? 1 : 0;
            $subjectToIt = !empty($_POST['subject_to_income_tax']) ? 1 : 0;
            $isMarried = !empty($_POST['is_married']) ? 1 : 0;
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $resignDate = trim((string) ($_POST['resignation_date'] ?? ''));
            $isResigned = !empty($_POST['is_resigned']);
            if ($isResigned || $resignDate !== '') {
                if ($resignDate === '') {
                    throw new RuntimeException('أدخل تاريخ الاستقالة.');
                }
                $isActive = 0;
                $resignDateVal = $resignDate;
            } else {
                $isActive = 1;
                $resignDateVal = null;
            }

            if ($nameFirst === '') {
                throw new RuntimeException('الاسم الأول مطلوب.');
            }
            if ($name === '') {
                throw new RuntimeException('اسم الموظف مطلوب.');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('صيغة البريد الإلكتروني غير صحيحة.');
            }
            if ($baseSalary < 0) {
                throw new RuntimeException('الراتب الأساسي يجب أن يكون موجباً أو صفراً.');
            }

            if ($code === '') {
                $maxNum = 0;
                try {
                    $maxNum = (int) $pdo->query(
                        "SELECT COALESCE(MAX(CAST(emp_code AS UNSIGNED)), 0) FROM hr_employee
                         WHERE emp_code REGEXP '^[0-9]+$'"
                    )->fetchColumn();
                } catch (Throwable $eMax) {
                    $maxNum = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM hr_employee')->fetchColumn();
                }
                $code = (string) ($maxNum + 1);
            } else {
                if (!ctype_digit($code)) {
                    throw new RuntimeException('الرقم الوظيفي يجب أن يحتوي أرقاماً فقط.');
                }
                $stChk = $pdo->prepare('SELECT id FROM hr_employee WHERE emp_code = ? AND id <> ? LIMIT 1');
                $stChk->execute([$code, $id]);
                if ($stChk->fetchColumn()) {
                    throw new RuntimeException('الرقم الوظيفي مستخدم لموظف آخر.');
                }
            }
            $hireDateVal = $hire !== '' ? $hire : null;

            if ($id > 0) {
                $st = $pdo->prepare(
                    'UPDATE hr_employee SET emp_code = ?, name_ar = ?, name_first = ?, name_father = ?, name_grandfather = ?, name_family = ?,
                     national_id = ?, gender = ?, is_married = ?, nationality_id = ?,
                     phone = ?, email = ?, address_ar = ?, address_city = ?, address_district = ?,
                     job_title = ?, job_title_id = ?, department = ?, department_id = ?,
                     hire_date = ?, resignation_date = ?, base_salary = ?, social_security_no = ?,
                     subject_to_social_security = ?, subject_to_income_tax = ?, notes = ?, is_active = ? WHERE id = ?'
                );
                $st->execute([
                    $code, $name, $nameFirst, $nameFather, $nameGrandfather, $nameFamily,
                    $nid !== '' ? $nid : null, $gender !== '' ? $gender : null, $isMarried,
                    $nationalityId > 0 ? $nationalityId : null,
                    $phone !== '' ? $phone : null, $email !== '' ? $email : null,
                    $addressAr !== '' ? $addressAr : null,
                    $addressCity !== '' ? $addressCity : null,
                    $addressDistrict !== '' ? $addressDistrict : null,
                    $job !== '' ? $job : null, $jobTitleId > 0 ? $jobTitleId : null,
                    $dept !== '' ? $dept : null, $deptId > 0 ? $deptId : null,
                    $hireDateVal, $resignDateVal, $baseSalary,
                    $ssn !== '' ? $ssn : null, $subjectToSs, $subjectToIt,
                    $notes !== '' ? $notes : null, $isActive, $id,
                ]);
                if ($subjectToSs === 0) {
                    hr_ss_clear_employee_unposted_payroll($pdo, $id);
                }
                if ($subjectToIt === 0) {
                    hr_income_tax_clear_employee_unposted_payroll($pdo, $id);
                }
                flash_set('success', 'تم حفظ تعديلات الموظف.');
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO hr_employee (emp_code, name_ar, name_first, name_father, name_grandfather, name_family,
                     national_id, gender, is_married, nationality_id, phone, email,
                     address_ar, address_city, address_district,
                     job_title, job_title_id, department, department_id, hire_date, resignation_date, base_salary,
                     social_security_no, subject_to_social_security, subject_to_income_tax, notes, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $code, $name, $nameFirst, $nameFather, $nameGrandfather, $nameFamily,
                    $nid !== '' ? $nid : null, $gender !== '' ? $gender : null, $isMarried,
                    $nationalityId > 0 ? $nationalityId : null,
                    $phone !== '' ? $phone : null, $email !== '' ? $email : null,
                    $addressAr !== '' ? $addressAr : null,
                    $addressCity !== '' ? $addressCity : null,
                    $addressDistrict !== '' ? $addressDistrict : null,
                    $job !== '' ? $job : null, $jobTitleId > 0 ? $jobTitleId : null,
                    $dept !== '' ? $dept : null, $deptId > 0 ? $deptId : null,
                    $hireDateVal, $resignDateVal, $baseSalary,
                    $ssn !== '' ? $ssn : null, $subjectToSs, $subjectToIt,
                    $notes !== '' ? $notes : null, $isActive,
                ]);
                $id = (int) $pdo->lastInsertId();
                flash_set('success', 'تم إضافة الموظف برقم ' . $code . '.');
            }
            redirect($listUrl . '&id=' . $id);
        }
        if ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                hr_employee_assert_editable($pdo, $id);
                hr_employee_assert_deletable($pdo, $id);
                $st = $pdo->prepare('DELETE FROM hr_employee WHERE id = ?');
                $st->execute([$id]);
                flash_set('success', 'تم حذف الموظف.');
            }
            redirect($listUrl);
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage() ?: 'تعذر إتمام العملية.');
        redirect($listUrl . (isset($id) && $id > 0 ? '&id=' . $id : ''));
    }
}

$flash = flash_get();

$row = [
    'id' => 0, 'emp_code' => '', 'name_ar' => '', 'name_first' => '', 'name_father' => '', 'name_grandfather' => '', 'name_family' => '',
    'national_id' => '', 'gender' => '', 'is_married' => 0, 'nationality_id' => 0,
    'phone' => '', 'email' => '',
    'address_ar' => '', 'address_city' => '', 'address_district' => '',
    'job_title' => '', 'job_title_id' => 0, 'department' => '', 'department_id' => 0,
    'hire_date' => '', 'resignation_date' => '', 'base_salary' => 0, 'allowances' => 0,
    'social_security_no' => '', 'subject_to_social_security' => 0, 'subject_to_income_tax' => 0,
    'bank_name' => '', 'bank_account' => '', 'notes' => '', 'is_active' => 1,
    'is_resigned_posted' => 0,
];

hr_ss_ensure_posting_rule($pdo);

$requestedId = isset($_GET['id']) && $_GET['id'] !== '' ? (int) $_GET['id'] : 0;
$searchCode = trim((string) ($_GET['emp_code'] ?? ''));

if ($searchCode !== '' && $requestedId < 1) {
    $found = hr_employee_resolve_lookup($pdo, $searchCode);
    if ($found) {
        $row = array_merge($row, $found);
    } elseif (!$flash) {
        $flash = ['type' => 'error', 'message' => 'لا يوجد موظف بالرقم: ' . $searchCode];
    }
} elseif ($requestedId > 0) {
    $st = $pdo->prepare('SELECT * FROM hr_employee WHERE id = ? LIMIT 1');
    $st->execute([$requestedId]);
    $found = $st->fetch(PDO::FETCH_ASSOC);
    if ($found) {
        $row = array_merge($row, $found);
    }
}

$currentId = (int) $row['id'];
$empNameParts = hr_employee_name_parts_from_row($row);
$row['name_first'] = $empNameParts['first'];
$row['name_father'] = $empNameParts['father'];
$row['name_grandfather'] = $empNameParts['grandfather'];
$row['name_family'] = $empNameParts['family'];
if (trim((string) ($row['name_ar'] ?? '')) === '' && $row['name_first'] !== '') {
    $row['name_ar'] = hr_employee_build_full_name(
        $row['name_first'],
        $row['name_father'],
        $row['name_grandfather'],
        $row['name_family']
    );
}

$empNav = hr_employee_browse_nav($pdo, $currentId);
$prevId = (int) ($empNav['prev'] ?? 0);
$nextId = (int) ($empNav['next'] ?? 0);
$firstId = (int) ($empNav['first'] ?? 0);
$lastId = (int) ($empNav['last'] ?? 0);
$empPosition = (int) ($empNav['position'] ?? 0);
$totalEmployees = (int) ($empNav['total'] ?? 0);
$pickerEmployees = hr_employee_picker_list($pdo);
$browseListUrl = app_url('index.php?r=hr_employees&action=browse');
$action = (string) ($_GET['action'] ?? '');

// شاشة قائمة الموظفين (لما يضغط زر القائمة)
if ($action === 'browse') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $where = '';
    $params = [];
    if ($q !== '') {
        $where = ' WHERE (name_ar LIKE ? OR emp_code LIKE ? OR national_id LIKE ? OR phone LIKE ?
                       OR IFNULL(job_title, \'\') LIKE ? OR IFNULL(department, \'\') LIKE ?)';
        $like = '%' . $q . '%';
        $params = array_fill(0, 6, $like);
    }
    $sql = 'SELECT id, emp_code, name_ar, job_title, department, phone, is_active
            FROM hr_employee' . $where . ' ' . hr_employee_list_order_sql() . ' LIMIT 200';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $cssPathBrowse = app_path('assets/css/hr-employees.css');
    $cssUrlBrowse = app_url('assets/css/hr-employees.css')
        . (is_file($cssPathBrowse) ? '?v=' . (string) filemtime($cssPathBrowse) : '');
    $jsPathBrowse = app_path('assets/js/hr-employees.js');
    $jsUrlBrowse = app_url('assets/js/hr-employees.js')
        . (is_file($jsPathBrowse) ? '?v=' . (string) filemtime($jsPathBrowse) : '');
    ?>
    <link rel="stylesheet" href="<?= esc($cssUrlBrowse) ?>">

    <div class="hr-emp-grid-page hr-emp-ora-screen" data-list-url="<?= esc($listUrl) ?>" data-browse="1">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-emp-grid-flash">
                <?= esc($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php hr_ora_render_title_bar('قائمة الموظفين', 'hr_employees'); ?>

        <div class="hr-emp-toolbar">
            <a class="btn btn-primary btn-sm" href="<?= esc($listUrl) ?>">➕ موظف جديد</a>
            <a class="btn btn-secondary btn-sm" href="<?= esc($listUrl) ?>">↩ بطاقة الموظف</a>
        </div>

        <div class="hr-emp-picker-panel">
            <h2 class="hr-emp-picker-title">بحث في القائمة</h2>
            <form method="get" action="<?= esc(app_url('index.php')) ?>" class="hr-emp-picker-form">
                <input type="hidden" name="r" value="hr_employees">
                <input type="hidden" name="action" value="browse">
                <label class="field hr-emp-picker-field">
                    <span class="field-label">بحث</span>
                    <input class="input" type="search" name="q" value="<?= esc($q) ?>"
                           placeholder="الاسم، الرقم، الوظيفة، القسم…" autocomplete="off" autofocus>
                </label>
                <button type="submit" class="btn btn-primary btn-sm">بحث</button>
                <a class="btn btn-ghost btn-sm" href="<?= esc($browseListUrl) ?>">مسح</a>
            </form>
        </div>

        <div class="hr-emp-grid-wrap">
            <table class="hr-emp-grid-table">
                <thead>
                <tr>
                    <th>الرقم</th>
                    <th>الاسم</th>
                    <th>الوظيفة</th>
                    <th>القسم</th>
                    <th>الهاتف</th>
                    <th>الحالة</th>
                </tr>
                </thead>
                <tbody id="hr-emp-browse-body">
                <?php if (!$rows): ?>
                    <tr class="hr-emp-row hr-emp-row--empty">
                        <td colspan="6" class="muted">لا توجد سجلات.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $e):
                    $eid = (int) ($e['id'] ?? 0);
                    $isActive = (int) ($e['is_active'] ?? 1) === 1;
                ?>
                    <tr class="hr-emp-row<?= !$isActive ? ' is-inactive' : '' ?>"
                        data-id="<?= $eid ?>"
                        data-href="<?= esc($listUrl . '&id=' . $eid) ?>"
                        tabindex="0">
                        <td dir="ltr"><?= esc((string) ($e['emp_code'] ?? '—')) ?></td>
                        <td><?= esc((string) $e['name_ar']) ?></td>
                        <td><?= esc((string) ($e['job_title'] ?? '—')) ?></td>
                        <td><?= esc((string) ($e['department'] ?? '—')) ?></td>
                        <td dir="ltr"><?= esc((string) ($e['phone'] ?? '—')) ?></td>
                        <td><?= $isActive ? 'نشِط' : 'معطّل' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <script src="<?= esc($jsUrlBrowse) ?>" defer></script>
    <?php
    return;
}

$departments = hr_department_active_list($pdo);
$nationalities = hr_nationality_active_list($pdo);
$jobTitles = hr_job_title_active_list($pdo);

$cssPath = app_path('assets/css/hr-employees.css');
$cssUrl = app_url('assets/css/hr-employees.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssPathSalesOra = app_path('assets/css/hr-employees-sales-ora12.css');
$cssUrlSalesOra = app_url('assets/css/hr-employees-sales-ora12.css')
    . (is_file($cssPathSalesOra) ? '?v=' . (string) filemtime($cssPathSalesOra) : '');
$cssInvMetaPath = app_path('assets/css/sales-invoice.css');
$cssInvMetaUrl = app_url('assets/css/sales-invoice.css')
    . (is_file($cssInvMetaPath) ? '?v=' . (string) filemtime($cssInvMetaPath) : '');
$jsPath = app_path('assets/js/hr-employees.js');
$jsUrl = app_url('assets/js/hr-employees.js') . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');

$prevUrlAttr = $prevId > 0 ? $listUrl . '&id=' . $prevId : '';
$nextUrlAttr = $nextId > 0 ? $listUrl . '&id=' . $nextId : '';
$firstUrlAttr = $firstId > 0 ? $listUrl . '&id=' . $firstId : '';
$lastUrlAttr = $lastId > 0 ? $listUrl . '&id=' . $lastId : '';
$pickerCurrentCode = $currentId > 0 ? trim((string) ($row['emp_code'] ?? '')) : '';
$pickerEmployeesJson = hr_employees_picker_json($pickerEmployees);
$printBaseUrl = app_url('index.php?r=hr_employee_print');
$empLocked = $currentId > 0 && hr_employee_is_resignation_posted($row);
$empResignDate = (string) ($row['resignation_date'] ?? '');
$empIsResigned = $empResignDate !== '' || hr_employee_is_resignation_posted($row);
$empDeleteCheck = $currentId > 0 ? hr_employee_delete_check($pdo, $currentId) : ['can_delete' => true, 'message' => ''];
$exitUrl = nav_exit_url('hr_employees');
?>
<?php employee_picker_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssInvMetaUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssUrlSalesOra) ?>">
<?php employee_picker_json_script($pickerEmployees, 'hr-employees-picker-json'); ?>

<div class="dashboard-ora hr-emp-ora12-screen hr-emp-wrap hr-emp-grid-page hr-emp-ora-screen"
     data-list-url="<?= esc($listUrl) ?>"
     data-exit-url="<?= esc($exitUrl) ?>"
     data-browse-url="<?= esc($browseListUrl) ?>"
     data-prev-url="<?= esc($prevUrlAttr) ?>"
     data-next-url="<?= esc($nextUrlAttr) ?>"
     data-first-url="<?= esc($firstUrlAttr) ?>"
     data-last-url="<?= esc($lastUrlAttr) ?>"
     data-current-id="<?= (int) $currentId ?>"
     data-current-code="<?= esc($pickerCurrentCode) ?>"
     data-emp-position="<?= (int) $empPosition ?>"
     data-emp-total="<?= (int) $totalEmployees ?>"
     data-picker-employees="<?= esc($pickerEmployeesJson ?: '[]') ?>"
     data-resignation-posted="<?= $empLocked ? '1' : '0' ?>"
     data-print-base-url="<?= esc($printBaseUrl) ?>"
     data-can-delete="<?= !empty($empDeleteCheck['can_delete']) ? '1' : '0' ?>"
     data-delete-block-reason="<?= esc((string) ($empDeleteCheck['message'] ?? '')) ?>">

    <header class="dashboard-ora-screen-title" role="banner">
        <h1 class="dashboard-ora-screen-title__text">بيانات الموظف الاساسية</h1>
        <?php nav_render_screen_close('hr_employees'); ?>
    </header>

    <div class="dashboard-ora-workspace">
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> hr-emp-grid-flash">
            <?= esc($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="dashboard-ora-toolbar hr-emp-top-bar hr-emp-toolbar">
        <a class="btn btn-primary btn-sm" href="<?= esc($listUrl) ?>">موظف جديد</a>
        <a class="btn btn-secondary btn-sm" href="<?= esc($browseListUrl) ?>">القائمة</a>
        <div class="sales-inv-meta-row hr-emp-meta-row hr-emp-meta-row--inline">
            <div class="sales-inv-meta-item hr-emp-meta-item hr-emp-meta-no">
                <label for="hr-emp-picker-code">الرقم</label>
                <div class="sales-inv-no-nav hr-emp-no-nav" role="group" aria-label="تنقّل بين الموظفين">
                    <button type="button" class="sales-inv-no-arrow hr-emp-no-arrow" id="hr-emp-nav-prev"
                            title="السابق" aria-label="السابق"<?= $prevUrlAttr === '' ? ' disabled' : '' ?>>‹</button>
                    <input type="text" class="input input-compact sales-inv-no-input hr-emp-picker-code" id="hr-emp-picker-code"
                           value="<?= esc($pickerCurrentCode) ?>"
                           dir="ltr" inputmode="numeric" autocomplete="off"
                           placeholder="رقم" title="أدخل الرقم الوظيفي ثم Enter">
                    <button type="button" class="sales-inv-no-arrow hr-emp-no-arrow" id="hr-emp-nav-next"
                            title="التالي" aria-label="التالي"<?= $nextUrlAttr === '' ? ' disabled' : '' ?>>›</button>
                </div>
            </div>
            <?= employee_picker_field([
                'id' => 'hr-emp-picker-id',
                'label' => 'الاسم',
                'compact' => true,
                'wrapper_class' => 'sales-inv-meta-item hr-emp-meta-item hr-emp-meta-name hr-emp-picker-slot',
                'json_id' => 'hr-employees-picker-json',
                'manual_bind' => true,
                'value' => $currentId,
                'placeholder' => 'اضغط لاختيار الموظف',
                'allow_new' => true,
                'new_label' => '— موظف جديد —',
            ]) ?>
            <div class="sales-inv-meta-item hr-emp-meta-item hr-emp-meta-pos">
                <label for="hr-emp-picker-pos">السجل</label>
                <input type="text" class="input input-compact hr-emp-pos-display" id="hr-emp-picker-pos" readonly dir="ltr"
                       value="<?php if ($totalEmployees > 0 && $currentId > 0): ?><?= (int) $empPosition ?> / <?= (int) $totalEmployees ?><?php elseif ($totalEmployees > 0): ?>— / <?= (int) $totalEmployees ?><?php else: ?>—<?php endif; ?>">
            </div>
        </div>
    </div>

    <section class="dashboard-ora-panel hr-emp-editor-panel<?= $empLocked ? ' is-resignation-posted' : '' ?>">
        <div class="dashboard-ora-panel__body">
        <?php if ($empLocked): ?>
            <p class="hr-emp-locked-note muted">بطاقة موظف مستقيل مرحّلة — للعرض فقط. استخدم <strong>فك الترحيل</strong> من شريط الأدوات للتعديل.</p>
        <?php endif; ?>

        <form method="post" action="<?= esc($listUrl) ?>" id="hr-emp-form" class="hr-emp-editor-form">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
            <input type="hidden" name="emp_code" value="<?= esc((string) ($row['emp_code'] ?? '')) ?>">

            <fieldset class="hr-emp-fieldset"<?= $empLocked ? ' disabled' : '' ?>>

            <section class="dashboard-ora-panel hr-emp-section hr-emp-ora-stack-wrap">
                <h2 class="dashboard-ora-panel__title">البيانات الشخصية</h2>
                <div class="dashboard-ora-panel__body">
                <div class="hr-emp-ora-stack" id="hr-emp-personal-stack">
                    <div class="hr-emp-ora-stack-tabs" role="tablist" aria-label="بيانات الموظف الشخصية">
                        <button type="button" class="hr-emp-ora-stack-tab is-front" role="tab"
                                id="hr-emp-tab-identity" data-stack-tab="identity"
                                aria-controls="hr-emp-pane-identity" aria-selected="true" tabindex="0">
                            الهوية
                        </button>
                        <button type="button" class="hr-emp-ora-stack-tab is-behind" role="tab"
                                id="hr-emp-tab-contact" data-stack-tab="contact"
                                aria-controls="hr-emp-pane-contact" aria-selected="false" tabindex="-1">
                            معلومات الاتصال
                        </button>
                        <button type="button" class="hr-emp-ora-stack-tab is-behind" role="tab"
                                id="hr-emp-tab-address" data-stack-tab="address"
                                aria-controls="hr-emp-pane-address" aria-selected="false" tabindex="-1">
                            عنوان الموظف
                        </button>
                    </div>
                    <div class="hr-emp-ora-stack-body">
                        <div class="hr-emp-ora-stack-pane is-active" id="hr-emp-pane-identity"
                             data-stack-pane="identity" role="tabpanel" aria-labelledby="hr-emp-tab-identity">
                            <div class="hr-emp-grid hr-emp-grid--identity-head">
                                <div class="hr-emp-name-parts">
                                    <label class="field hr-emp-field">
                                        <span class="field-label required">الاسم</span>
                                        <input class="input" name="name_first" required
                                               value="<?= esc((string) $row['name_first']) ?>" autocomplete="off">
                                    </label>
                                    <label class="field hr-emp-field">
                                        <span class="field-label">اسم الأب</span>
                                        <input class="input" name="name_father"
                                               value="<?= esc((string) $row['name_father']) ?>" autocomplete="off">
                                    </label>
                                    <label class="field hr-emp-field">
                                        <span class="field-label">اسم الجد</span>
                                        <input class="input" name="name_grandfather"
                                               value="<?= esc((string) $row['name_grandfather']) ?>" autocomplete="off">
                                    </label>
                                    <label class="field hr-emp-field">
                                        <span class="field-label">اسم العائلة</span>
                                        <input class="input" name="name_family"
                                               value="<?= esc((string) $row['name_family']) ?>" autocomplete="off">
                                    </label>
                                </div>
                                <label class="field hr-emp-field">
                                    <span class="field-label">الجنس</span>
                                    <div class="hr-emp-ora-lov">
                                        <select class="input hr-emp-ora-lov-field" name="gender">
                                            <option value="">— غير محدد —</option>
                                            <option value="male" <?= (string) ($row['gender'] ?? '') === 'male' ? 'selected' : '' ?>>ذكر</option>
                                            <option value="female" <?= (string) ($row['gender'] ?? '') === 'female' ? 'selected' : '' ?>>أنثى</option>
                                        </select>
                                        <button type="button" class="hr-emp-ora-lov-btn" tabindex="-1" aria-label="اختيار الجنس" title="اختيار الجنس"></button>
                                    </div>
                                </label>
                                <label class="field hr-emp-field">
                                    <span class="field-label">الجنسية</span>
                                    <div class="hr-emp-ora-lov">
                                        <select class="input hr-emp-ora-lov-field" name="nationality_id" id="hr-emp-nat-sel">
                                            <option value="">— اختر الجنسية —</option>
                                            <?php foreach ($nationalities as $nat): ?>
                                                <option value="<?= (int) $nat['id'] ?>"
                                                    <?= (int) ($row['nationality_id'] ?? 0) === (int) $nat['id'] ? 'selected' : '' ?>>
                                                    <?= esc((string) $nat['name_ar']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="hr-emp-ora-lov-btn" tabindex="-1" aria-label="اختيار الجنسية" title="اختيار الجنسية"></button>
                                    </div>
                                    <?php if (!$nationalities): ?>
                                        <small class="hr-emp-field-warn">
                                            <a href="<?= esc(app_url('index.php?r=hr_nationalities')) ?>">أضف جنسية</a>
                                        </small>
                                    <?php endif; ?>
                                </label>
                                <div class="field hr-emp-field hr-emp-marital-field">
                                    <span class="field-label">الحالة الاجتماعية</span>
                                    <input type="hidden" name="is_married" id="hr-emp-is-married-val"
                                           value="<?= (int) ($row['is_married'] ?? 0) === 1 ? '1' : '0' ?>">
                                    <div class="hr-emp-marital-options" role="group" aria-label="الحالة الاجتماعية">
                                        <label class="hr-emp-toggle hr-emp-marital-toggle">
                                            <input type="checkbox" id="hr-emp-marital-single"
                                                <?= (int) ($row['is_married'] ?? 0) !== 1 ? 'checked' : '' ?>>
                                            <span>أعزب</span>
                                        </label>
                                        <label class="hr-emp-toggle hr-emp-marital-toggle">
                                            <input type="checkbox" id="hr-emp-marital-married"
                                                <?= (int) ($row['is_married'] ?? 0) === 1 ? 'checked' : '' ?>>
                                            <span>متزوج</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="hr-emp-grid hr-emp-grid--identity">
                                <label class="field hr-emp-field">
                                    <span class="field-label">الرقم الوطني</span>
                                    <input class="input" name="national_id" value="<?= esc((string) ($row['national_id'] ?? '')) ?>" dir="ltr" autocomplete="off">
                                </label>
                            </div>
                        </div>
                        <div class="hr-emp-ora-stack-pane" id="hr-emp-pane-contact"
                             data-stack-pane="contact" role="tabpanel" aria-labelledby="hr-emp-tab-contact" hidden>
                            <div class="hr-emp-grid hr-emp-grid--contact">
                                <label class="field hr-emp-field">
                                    <span class="field-label">الهاتف</span>
                                    <input class="input" name="phone" value="<?= esc((string) ($row['phone'] ?? '')) ?>" dir="ltr" inputmode="tel" autocomplete="off">
                                </label>
                                <label class="field hr-emp-field">
                                    <span class="field-label">البريد الإلكتروني</span>
                                    <input class="input" name="email" type="email" value="<?= esc((string) ($row['email'] ?? '')) ?>" dir="ltr" autocomplete="off">
                                </label>
                            </div>
                        </div>
                        <div class="hr-emp-ora-stack-pane" id="hr-emp-pane-address"
                             data-stack-pane="address" role="tabpanel" aria-labelledby="hr-emp-tab-address" hidden>
                            <div class="hr-emp-grid hr-emp-grid--address">
                                <label class="field hr-emp-field hr-emp-field--address-full">
                                    <span class="field-label">العنوان التفصيلي</span>
                                    <textarea class="input" name="address_ar" rows="3"
                                              placeholder="الشارع، المبنى، الطابق…"><?= esc((string) ($row['address_ar'] ?? '')) ?></textarea>
                                </label>
                                <label class="field hr-emp-field">
                                    <span class="field-label">المدينة</span>
                                    <input class="input" name="address_city" value="<?= esc((string) ($row['address_city'] ?? '')) ?>" autocomplete="off">
                                </label>
                                <label class="field hr-emp-field">
                                    <span class="field-label">الحي / المنطقة</span>
                                    <input class="input" name="address_district" value="<?= esc((string) ($row['address_district'] ?? '')) ?>" autocomplete="off">
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                </div>
            </section>

            <section class="dashboard-ora-panel hr-emp-section">
                <h2 class="dashboard-ora-panel__title">البيانات الوظيفية</h2>
                <div class="dashboard-ora-panel__body">
                <div class="hr-emp-grid">
                    <label class="field hr-emp-field">
                        <span class="field-label">القسم</span>
                        <div class="hr-emp-ora-lov">
                            <select class="input hr-emp-ora-lov-field" name="department_id" id="hr-emp-dept-sel">
                                <option value="">— اختر القسم —</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= (int) $d['id'] ?>" <?= (int) $row['department_id'] === (int) $d['id'] ? 'selected' : '' ?>>
                                        <?= esc((string) $d['name_ar']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="hr-emp-ora-lov-btn" tabindex="-1" aria-label="اختيار القسم" title="اختيار القسم"></button>
                        </div>
                        <?php if (!$departments): ?>
                            <small class="hr-emp-field-warn">
                                <a href="<?= esc(app_url('index.php?r=hr_departments')) ?>">أضف قسماً</a>
                            </small>
                        <?php endif; ?>
                    </label>
                    <label class="field hr-emp-field">
                        <span class="field-label">المسمى الوظيفي</span>
                        <div class="hr-emp-ora-lov">
                            <select class="input hr-emp-ora-lov-field" name="job_title_id" id="hr-emp-jt-sel"
                                    data-job-titles='<?= esc(json_encode(array_map(static function ($jt) {
                                        return [
                                            'id' => (int) $jt['id'],
                                            'name' => (string) $jt['name_ar'],
                                            'dept_id' => (int) ($jt['department_id'] ?? 0),
                                            'dept_name' => (string) ($jt['department_name'] ?? ''),
                                        ];
                                    }, $jobTitles), JSON_UNESCAPED_UNICODE)) ?>'>
                                <option value="">— اختر المسمى —</option>
                                <?php foreach ($jobTitles as $jt): ?>
                                    <option value="<?= (int) $jt['id'] ?>"
                                            data-dept-id="<?= (int) ($jt['department_id'] ?? 0) ?>"
                                            <?= (int) $row['job_title_id'] === (int) $jt['id'] ? 'selected' : '' ?>>
                                        <?= esc((string) $jt['name_ar']) ?>
                                        <?php if (!empty($jt['department_name'])): ?>
                                            — <?= esc((string) $jt['department_name']) ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="hr-emp-ora-lov-btn" tabindex="-1" aria-label="اختيار المسمى" title="اختيار المسمى"></button>
                        </div>
                        <?php if (!$jobTitles): ?>
                            <small class="hr-emp-field-warn">
                                <a href="<?= esc(app_url('index.php?r=hr_job_titles')) ?>">أضف مسمى</a>
                            </small>
                        <?php endif; ?>
                    </label>
                    <label class="field hr-emp-field">
                        <span class="field-label">تاريخ التعيين</span>
                        <input class="input" name="hire_date" type="date" value="<?= esc((string) ($row['hire_date'] ?? '')) ?>">
                    </label>
                </div>
                </div>
            </section>

            <section class="dashboard-ora-panel hr-emp-section hr-emp-block--ss">
                <h2 class="dashboard-ora-panel__title">الاستقالة والضمان وضريبة الدخل</h2>
                <div class="dashboard-ora-panel__body">
                <div class="hr-emp-grid hr-emp-grid--emp-status">
                    <div class="field hr-emp-field hr-emp-status-item">
                        <span class="field-label">الاستقالة</span>
                        <div class="hr-emp-status-control">
                            <label class="hr-emp-toggle hr-emp-toggle--resign">
                                <input type="checkbox" name="is_resigned" value="1" id="hr-emp-is-resigned"
                                    <?= $empIsResigned ? 'checked' : '' ?>>
                                <span>موظف مستقيل</span>
                            </label>
                        </div>
                    </div>
                    <label class="field hr-emp-field hr-emp-status-item">
                        <span class="field-label">تاريخ الاستقالة</span>
                        <input class="input hr-emp-status-control" type="date" name="resignation_date" id="hr-emp-resignation-date"
                               value="<?= esc($empResignDate) ?>">
                    </label>
                    <div class="field hr-emp-field hr-emp-status-item">
                        <span class="field-label">الضمان الاجتماعي</span>
                        <div class="hr-emp-status-control">
                            <input type="hidden" name="subject_to_social_security" value="0">
                            <label class="hr-emp-toggle hr-emp-ss-toggle">
                                <input type="checkbox" name="subject_to_social_security" value="1"
                                    id="hr-emp-subject-ss"
                                    <?= (int) ($row['subject_to_social_security'] ?? 0) === 1 ? 'checked' : '' ?>>
                                <span>خاضع للضمان</span>
                            </label>
                        </div>
                        <?php if (!hr_ss_active_rate($pdo)): ?>
                            <small class="hr-emp-field-warn hr-emp-status-warn">
                                <a href="<?= esc(app_url('index.php?r=hr_social_security_rates')) ?>">أضف نسبة ضمان</a>
                            </small>
                        <?php endif; ?>
                    </div>
                    <label class="field hr-emp-field hr-emp-status-item">
                        <span class="field-label">رقم الضمان</span>
                        <input class="input hr-emp-status-control" name="social_security_no"
                               value="<?= esc((string) ($row['social_security_no'] ?? '')) ?>" dir="ltr">
                    </label>
                    <div class="field hr-emp-field hr-emp-status-item">
                        <span class="field-label">ضريبة الدخل</span>
                        <div class="hr-emp-status-control">
                            <input type="hidden" name="subject_to_income_tax" value="0">
                            <label class="hr-emp-toggle hr-emp-it-toggle">
                                <input type="checkbox" name="subject_to_income_tax" value="1"
                                    id="hr-emp-subject-it"
                                    <?= (int) ($row['subject_to_income_tax'] ?? 0) === 1 ? 'checked' : '' ?>>
                                <span>خاضع للضريبة</span>
                            </label>
                        </div>
                    </div>
                </div>
                <?php if ($currentId > 0 && !$empLocked): ?>
                    <small class="hr-emp-field-meta muted hr-emp-status-hint">
                        بعد إدخال تاريخ الاستقالة اضغط <strong>ترحيل</strong> من شريط الأدوات لإغلاق البطاقة.
                        — ضريبة الدخل تُحسب حسب
                        <a href="<?= esc(app_url('index.php?r=hr_income_tax_settings')) ?>">إعدادات ضريبة الدخل</a>.
                    </small>
                <?php elseif (!$currentId): ?>
                    <small class="hr-emp-field-meta muted hr-emp-status-hint">
                        ضريبة الدخل تُحسب حسب
                        <a href="<?= esc(app_url('index.php?r=hr_income_tax_settings')) ?>">إعدادات ضريبة الدخل</a>
                        والحالة الاجتماعية.
                    </small>
                <?php endif; ?>
                </div>
            </section>

            <section class="dashboard-ora-panel hr-emp-section">
                <h2 class="dashboard-ora-panel__title">ملاحظات</h2>
                <div class="dashboard-ora-panel__body">
                <div class="hr-emp-grid hr-emp-grid--1">
                    <label class="field hr-emp-field">
                        <span class="field-label">ملاحظات إضافية</span>
                        <textarea class="input" name="notes" rows="3"><?= esc((string) ($row['notes'] ?? '')) ?></textarea>
                    </label>
                </div>
                </div>
            </section>
            <?php
            $empSetupLinks = [];
            if (!$nationalities) {
                $empSetupLinks[] = '<a href="' . esc(app_url('index.php?r=hr_nationalities')) . '">جنسيات</a>';
            }
            if (!$departments) {
                $empSetupLinks[] = '<a href="' . esc(app_url('index.php?r=hr_departments')) . '">أقسام</a>';
            }
            if (!$jobTitles) {
                $empSetupLinks[] = '<a href="' . esc(app_url('index.php?r=hr_job_titles')) . '">مسميات</a>';
            }
            if (!hr_ss_active_rate($pdo)) {
                $empSetupLinks[] = '<a href="' . esc(app_url('index.php?r=hr_social_security_rates')) . '">نسب ضمان</a>';
            }
            if ($empSetupLinks !== []): ?>
                <p class="hr-emp-form-warn muted">إعداد: <?= implode(' · ', $empSetupLinks) ?></p>
            <?php endif; ?>

            </fieldset>
        </form>

        <?php if ($currentId > 0): ?>
            <form method="post" action="<?= esc($listUrl) ?>" id="hr-emp-delete-form" style="display:none;">
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
            </form>
            <form method="post" action="<?= esc($listUrl) ?>" id="hr-emp-post-resign-form" style="display:none;">
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="post_resignation">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                <input type="hidden" name="resignation_date" id="hr-emp-post-resignation-date" value="<?= esc($empResignDate) ?>">
            </form>
            <form method="post" action="<?= esc($listUrl) ?>" id="hr-emp-unpost-resign-form" style="display:none;">
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="unpost_resignation">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
            </form>
        <?php endif; ?>
        </div>
    </section>
    </div>
</div>

<script src="<?= esc($jsUrl) ?>" defer></script>
