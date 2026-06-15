<?php
declare(strict_types=1);

require_once app_path('includes/acc_account_tree.php');
require_once app_path('includes/acc_coa_bootstrap.php');

$listUrl = app_url('index.php?r=chart_of_accounts');
$pdo = db();

if (!acc_account_ensure_schema($pdo)) {
    echo '<div class="card"><p class="alert alert-error">تعذر تحميل دليل الحسابات. نفّذ <code>database/migrations/026_acc_journal_tables.sql</code> ثم حدّث الصفحة.</p></div>';
    return;
}

$checksFund = acc_coa_ensure_checks_fund_account($pdo);
if (!empty($checksFund['created']) && !empty($checksFund['message'])) {
    flash_set('success', (string) $checksFund['message']);
}

$typeLabels = acc_account_type_labels();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }

    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'save') {
            $parentRaw = $_POST['parent_id'] ?? null;
            $parentId = $parentRaw === '' || $parentRaw === null ? null : (int) $parentRaw;
            acc_account_save($pdo, [
                'id' => (int) ($_POST['id'] ?? 0),
                'parent_id' => $parentId,
                'name_ar' => (string) ($_POST['name_ar'] ?? ''),
                'account_type' => (string) ($_POST['account_type'] ?? ''),
                'is_leaf' => !empty($_POST['is_leaf']),
                'is_active' => !empty($_POST['is_active']),
            ]);
            flash_set('success', (int) ($_POST['id'] ?? 0) > 0 ? 'تم تحديث الحساب.' : 'تم إضافة الحساب.');
        } elseif ($act === 'delete') {
            acc_account_delete($pdo, (int) ($_POST['id'] ?? 0));
            flash_set('success', 'تم حذف الحساب.');
        } else {
            throw new RuntimeException('إجراء غير معروف.');
        }
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        flash_set('error', 'تعذر تنفيذ العملية.');
    }

    // إجبار تحميل جديد بعد أي تعديل لتفادي بقاء حالة عرض قديمة في المتصفح/الجلسة.
    $_SESSION['coa_tree_in_session'] = false;
    redirect($listUrl . '&refresh=' . rawurlencode((string) time()));
}

$flash = flash_get();
$action = (string) ($_GET['action'] ?? 'list');

// ملاحظة: لا ننفّذ صيانة الشجرة تلقائياً عند كل فتح للصفحة،
// لأن هذا قد يحذف/يعطّل حسابات مضافة يدوياً تحمل أسماء شائعة (مثل "البنك").
// الصيانة تُشغَّل من شاشة ربط الحسابات أو أثناء bootstrap فقط.

if ($action === 'add' || $action === 'edit') {
    $row = [
        'id' => 0,
        'code' => '',
        'name_ar' => '',
        'parent_id' => null,
        'account_type' => 'asset',
        'is_leaf' => 1,
        'is_active' => 1,
    ];
    $parentRow = null;
    $parentId = (int) ($_GET['parent_id'] ?? 0);

    if ($action === 'edit') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            flash_set('error', 'حساب غير موجود.');
            redirect($listUrl);
        }
        $dbRow = acc_account_get($pdo, $id);
        if (!$dbRow) {
            flash_set('error', 'حساب غير موجود.');
            redirect($listUrl);
        }
        $row = array_merge($row, $dbRow);
        if ($row['parent_id'] !== null) {
            $parentRow = acc_account_get($pdo, (int) $row['parent_id']);
        }
    } elseif ($parentId > 0) {
        $parentRow = acc_account_get($pdo, $parentId);
        if (!$parentRow) {
            flash_set('error', 'الحساب الأب غير موجود.');
            redirect($listUrl);
        }
        $row['parent_id'] = $parentId;
        $row['account_type'] = (string) $parentRow['account_type'];
        $row['is_leaf'] = 1;
    }

    $nextCodePreview = '';
    if ($action === 'add') {
        $nextCodePreview = acc_account_next_code(
            $pdo,
            $parentRow ? (int) $parentRow['id'] : null
        );
    }

    $stKids = null;
    if ((int) $row['id'] > 0) {
        $stKids = $pdo->prepare('SELECT id FROM acc_account WHERE parent_id = ? LIMIT 1');
        $stKids->execute([(int) $row['id']]);
        $hasKids = (bool) $stKids->fetch();
    } else {
        $hasKids = false;
    }

    $formTitle = $action === 'add'
        ? ($parentRow ? 'إضافة حساب فرعي' : 'إضافة حساب رئيسي')
        : 'تعديل حساب';
    $cssPath = app_path('assets/css/chart-of-accounts.css');
    $cssUrl = app_url('assets/css/chart-of-accounts.css')
        . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
    ?>
    <link rel="stylesheet" href="<?= esc($cssUrl) ?>">
    <div class="toolbar coa-toolbar">
        <h2 class="coa-page-title"><?= esc($formTitle) ?></h2>
        <a class="btn btn-secondary btn-sm" href="<?= esc($listUrl) ?>">رجوع للشجرة</a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="card coa-form-card">
        <?php if ($parentRow): ?>
            <p class="coa-parent-hint">
                تحت الحساب: <strong><?= esc(acc_account_format_code((string) $parentRow['code'])) ?></strong>
                — <?= esc((string) $parentRow['name_ar']) ?>
            </p>
        <?php endif; ?>

        <form method="post" action="<?= esc($listUrl) ?>" class="form-grid coa-form">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
            <?php if ($action === 'add' && $parentRow): ?>
                <input type="hidden" name="parent_id" value="<?= (int) $parentRow['id'] ?>">
            <?php endif; ?>

            <div class="form-row">
                <label class="field">
                    <span class="field-label">رقم الحساب</span>
                    <input class="input" type="text" readonly tabindex="-1"
                           value="<?= esc($action === 'edit' ? acc_account_format_code((string) $row['code']) : acc_account_format_code($nextCodePreview)) ?>"
                           style="background:#f1f5f9;cursor:not-allowed;">
                    <span class="muted" style="font-size:0.8rem;">يُولَّد تلقائياً ولا يُعدَّل</span>
                </label>
                <label class="field">
                    <span class="field-label">اسم الحساب *</span>
                    <input class="input" name="name_ar" required autofocus value="<?= esc((string) $row['name_ar']) ?>">
                </label>
            </div>

            <?php if ($action === 'add' && !$parentRow): ?>
                <label class="field">
                    <span class="field-label">نوع الحساب *</span>
                    <select class="input" name="account_type" required>
                        <?php foreach ($typeLabels as $code => $label): ?>
                            <option value="<?= esc($code) ?>"<?= (string) $row['account_type'] === $code ? ' selected' : '' ?>>
                                <?= esc($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php else: ?>
                <input type="hidden" name="account_type" value="<?= esc((string) $row['account_type']) ?>">
                <p class="muted coa-type-readonly">نوع الحساب: <strong><?= esc(acc_account_type_label((string) $row['account_type'])) ?></strong></p>
            <?php endif; ?>

            <div class="form-row coa-checks">
                <label class="field coa-check">
                    <input type="checkbox" name="is_leaf" value="1"<?= (int) $row['is_leaf'] === 1 ? ' checked' : '' ?><?= $hasKids ? ' disabled' : '' ?>>
                    <span>حساب نهائي (يُستخدم في القيود والسندات)</span>
                </label>
                <label class="field coa-check">
                    <input type="checkbox" name="is_active" value="1"<?= (int) $row['is_active'] === 1 ? ' checked' : '' ?>>
                    <span>نشط</span>
                </label>
            </div>
            <?php if ($hasKids): ?>
                <p class="muted" style="font-size:0.8rem;">لا يمكن جعل الحساب «نهائي» لأنه يحتوي حسابات فرعية.</p>
            <?php endif; ?>

            <div>
                <button class="btn btn-primary" type="submit">حفظ</button>
            </div>
        </form>
    </div>
    <?php
    return;
}

$rows = acc_account_load_all($pdo, false);
try {
    require_once app_path('includes/hr_payroll_gl.php');
    hr_payroll_gl_ensure_payroll_liability_group($pdo);
    $rows = acc_account_load_all($pdo, false);
} catch (Throwable $e) {
    // ignored — عرض الشجرة كما هي
}
$tree = acc_account_build_tree($rows);
$clientMap = acc_account_client_map_with_delete_flags($pdo, acc_account_client_map($rows));
$forceFreshTree = isset($_GET['refresh']) && (string) $_GET['refresh'] !== '';
$restoreCoaTree = !$forceFreshTree && !empty($_SESSION['coa_tree_in_session']);
$_SESSION['coa_tree_in_session'] = !$forceFreshTree;
$cssPath = app_path('assets/css/chart-of-accounts.css');
$cssUrl = app_url('assets/css/chart-of-accounts.css')
    . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$jsPath = app_path('assets/js/chart-of-accounts.js');
$jsUrl = app_url('assets/js/chart-of-accounts.js')
    . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<script src="<?= esc($jsUrl) ?>" defer></script>

<div class="toolbar coa-toolbar">
    <h2 class="coa-page-title">شجرة الحسابات</h2>
    <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=report_chart_of_accounts&run=1')) ?>">طباعة الشجرة</a>
    <a class="btn btn-primary btn-sm" href="<?= esc($listUrl . '&action=add') ?>">+ حساب رئيسي</a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= esc($flash['message']) ?></div>
<?php endif; ?>

<div class="card coa-card coa-card--split">
    <?php acc_account_render_split_view($tree, $clientMap, $listUrl, $restoreCoaTree); ?>
</div>
