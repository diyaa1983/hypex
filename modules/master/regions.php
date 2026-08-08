<?php
declare(strict_types=1);

/**
 * شاشة مناطق العملاء — المنطقة + العنوان + استيراد Excel.
 */

$listUrl = app_url('index.php?r=customer_regions');

require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/crm_region.php');
require_once app_path('includes/crm_region_excel_import.php');

$pdo = db();
if (!crm_region_ensure_schema($pdo)) {
    ?>
    <div class="alert alert-error">
        تعذر إنشاء جدول المناطق. نفّذ:
        <code>database/migrations/250_crm_region.sql</code>
        و<code>254_crm_region_address.sql</code>
        ثم حدّث الصفحة.
    </div>
    <?php
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }

    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'import_excel') {
            $uploadPath = null;
            if (!empty($_FILES['excel_file']['tmp_name']) && is_uploaded_file((string) $_FILES['excel_file']['tmp_name'])) {
                $tmp = (string) $_FILES['excel_file']['tmp_name'];
                $destDir = app_path('uploads');
                if (!is_dir($destDir)) {
                    @mkdir($destDir, 0775, true);
                }
                $dest = $destDir . DIRECTORY_SEPARATOR . 'region_import_' . date('Ymd_His') . '.xlsx';
                if (!@move_uploaded_file($tmp, $dest)) {
                    throw new RuntimeException('تعذر حفظ الملف المرفوع.');
                }
                $uploadPath = $dest;
            }
            $result = crm_region_excel_import($pdo, $uploadPath, true);
            if (!$result['ok']) {
                throw new RuntimeException((string) $result['message']);
            }
            $msg = (string) $result['message'];
            if (!empty($result['warnings'])) {
                $msg .= ' — تنبيه: ' . count($result['warnings']) . ' صف لم يُربط بعميل.';
            }
            flash_set('success', $msg);
            redirect($listUrl);
        }

        if ($act === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $name = trim((string) ($_POST['name_ar'] ?? ''));
            $address = trim((string) ($_POST['address_ar'] ?? ''));
            $sort = (int) ($_POST['sort_order'] ?? 0);

            if ($name === '') {
                throw new RuntimeException('اسم المنطقة مطلوب.');
            }
            if ($code === '') {
                $n = (int) $pdo->query('SELECT IFNULL(MAX(id), 0) FROM crm_region')->fetchColumn();
                $code = 'R' . str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT);
            }
            if (!preg_match('/^[A-Za-z0-9_\-]{1,20}$/', $code)) {
                throw new RuntimeException('رمز المنطقة: حروف/أرقام فقط (حتى 20).');
            }

            if ($id > 0) {
                $chk = $pdo->prepare('SELECT id FROM crm_region WHERE code = ? AND id <> ? LIMIT 1');
                $chk->execute([$code, $id]);
            } else {
                $chk = $pdo->prepare('SELECT id FROM crm_region WHERE code = ? LIMIT 1');
                $chk->execute([$code]);
            }
            if ($chk->fetch()) {
                throw new RuntimeException('رمز المنطقة مستخدم مسبقاً.');
            }

            if ($id > 0) {
                $st = $pdo->prepare(
                    'UPDATE crm_region SET code=?, name_ar=?, address_ar=?, sort_order=? WHERE id=?'
                );
                $st->execute([$code, $name, $address !== '' ? $address : null, $sort, $id]);
                flash_set('success', 'تم تحديث المنطقة.');
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO crm_region (code, name_ar, address_ar, sort_order, is_active) VALUES (?,?,?,?,1)'
                );
                $st->execute([$code, $name, $address !== '' ? $address : null, $sort]);
                flash_set('success', 'تم إضافة المنطقة.');
            }
        } elseif ($act === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $st = $pdo->prepare('UPDATE crm_region SET is_active = 1 - is_active WHERE id = ?');
            $st->execute([$id]);
            flash_set('success', 'تم تحديث حالة المنطقة.');
        } elseif ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $cntSt = $pdo->prepare('SELECT COUNT(*) FROM crm_customer WHERE region_id = ?');
            $cntSt->execute([$id]);
            $cnt = (int) $cntSt->fetchColumn();
            if ($cnt > 0) {
                throw new RuntimeException('لا يمكن الحذف: ' . $cnt . ' عميل مرتبط بهذه المنطقة. عطّلها بدلاً من الحذف.');
            }
            $st = $pdo->prepare('DELETE FROM crm_region WHERE id = ?');
            $st->execute([$id]);
            flash_set('success', 'تم حذف المنطقة.');
        } else {
            throw new RuntimeException('إجراء غير معروف.');
        }
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        flash_set('error', 'تعذر تنفيذ العملية: ' . $e->getMessage());
    }

    redirect($listUrl);
}

$flash = flash_get();
$action = (string) ($_GET['action'] ?? 'list');
$excelPath = crm_region_excel_resolve_path();
$excelDefaultHint = 'C:\\xampp\\htdocs\\system\\المنطقة.xlsx';

if ($action === 'add' || $action === 'edit') {
    $row = [
        'id' => 0,
        'code' => '',
        'name_ar' => '',
        'address_ar' => '',
        'sort_order' => 0,
        'is_active' => 1,
    ];
    if ($action === 'edit') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            flash_set('error', 'منطقة غير موجودة.');
            redirect($listUrl);
        }
        $st = $pdo->prepare('SELECT * FROM crm_region WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $dbRow = $st->fetch(PDO::FETCH_ASSOC);
        if (!$dbRow) {
            flash_set('error', 'منطقة غير موجودة.');
            redirect($listUrl);
        }
        $row = array_merge($row, $dbRow);
    }

    $formTitle = $action === 'add' ? 'إضافة منطقة' : 'تعديل منطقة';
    sales_ora12_enqueue_assets();
    ?>
    <div class="dashboard-ora sales-ora12-screen customers-ora-screen">
        <?php sales_ora12_render_title_bar($formTitle); ?>
        <?php sales_ora12_workspace_open(); ?>
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash"><?= esc($flash['message']) ?></div>
        <?php endif; ?>

        <div class="sales-ora-toolbar toolbar">
            <a class="btn btn-secondary btn-sm" href="<?= esc($listUrl) ?>">↩ رجوع للقائمة</a>
        </div>

        <div class="sales-ora-panel card">
            <form method="post" action="<?= esc($listUrl) ?>" class="form-grid">
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="save">
                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">

                <div class="form-row">
                    <label class="field">
                        <span class="field-label">الرمز</span>
                        <input class="input" name="code" dir="ltr" maxlength="20"
                               value="<?= esc((string) $row['code']) ?>"
                               placeholder="يُولَّد تلقائياً إن تُرك فارغاً">
                    </label>
                    <label class="field">
                        <span class="field-label">المنطقة *</span>
                        <input class="input" name="name_ar" required maxlength="180"
                               value="<?= esc((string) $row['name_ar']) ?>"
                               placeholder="مثال: الزرقاء، عمّان، طبربور">
                    </label>
                </div>
                <div class="form-row">
                    <label class="field" style="flex:1;">
                        <span class="field-label">العنوان</span>
                        <input class="input" name="address_ar" maxlength="255"
                               value="<?= esc((string) ($row['address_ar'] ?? '')) ?>"
                               placeholder="العنوان التفصيلي حسب ملف المناطق">
                    </label>
                    <label class="field">
                        <span class="field-label">ترتيب العرض</span>
                        <input class="input" name="sort_order" type="number" dir="ltr"
                               value="<?= (int) $row['sort_order'] ?>">
                    </label>
                </div>
                <div>
                    <button class="btn btn-primary" type="submit">حفظ</button>
                </div>
            </form>
        </div>
        <?php sales_ora12_workspace_close(); ?>
    </div>
    <?php
    return;
}

$rows = crm_region_load_all($pdo);
$addUrl = app_url('index.php?r=customer_regions&action=add');
sales_ora12_enqueue_assets();
?>
<div class="dashboard-ora sales-ora12-screen customers-ora-screen">
    <?php sales_ora12_render_title_bar('مناطق العملاء'); ?>
    <?php sales_ora12_workspace_open(); ?>
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="sales-ora-toolbar toolbar" style="flex-wrap:wrap;gap:0.5rem;">
        <a class="btn btn-primary btn-sm" href="<?= esc($addUrl) ?>">➕ إضافة منطقة</a>
        <a class="btn btn-ghost btn-sm" href="<?= esc(app_url('index.php?r=customers')) ?>">العملاء</a>
        <a class="btn btn-ghost btn-sm" href="<?= esc(app_url('index.php?r=sales_reps')) ?>">المندوبين</a>
    </div>

    <div class="sales-ora-panel card" style="margin-bottom:1rem;">
        <h3 style="margin:0 0 0.5rem;font-size:1rem;">استيراد من Excel</h3>
        <p class="muted" style="margin:0 0 0.65rem;font-size:0.9rem;">
            الملف الافتراضي:
            <code dir="ltr"><?= esc($excelDefaultHint) ?></code>
            <?php if ($excelPath): ?>
                <br>ملف موجود الآن: <code dir="ltr"><?= esc($excelPath) ?></code>
            <?php else: ?>
                <br><strong>الملف غير موجود حالياً على هذا الجهاز.</strong> انسخ <code>المنطقة.xlsx</code> إلى المسار أعلاه أو ارفعه هنا.
            <?php endif; ?>
            <br>الأعمدة المتوقعة: <strong>المنطقة</strong> · <strong>العنوان</strong> · <strong>المندوب</strong> · (رمز العميل) · (اسم العميل)
        </p>
        <form method="post" action="<?= esc($listUrl) ?>" enctype="multipart/form-data" class="form-row" style="flex-wrap:wrap;gap:0.5rem;align-items:end;">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="import_excel">
            <label class="field">
                <span class="field-label">رفع ملف (اختياري)</span>
                <input class="input" type="file" name="excel_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
            </label>
            <button class="btn btn-primary" type="submit" data-confirm="استيراد المندوبين والمناطق وربط العملاء من Excel؟">
                استيراد الآن
            </button>
        </form>
    </div>

    <p class="muted" style="margin:0.5rem 0 1rem;">
        كل سجل = <strong>منطقة + عنوان</strong>. عند اختيار المندوب في شاشة العميل تُسحَب المنطقة والعنوان المرتبطان به.
    </p>

    <div class="sales-ora-panel card" style="overflow:auto;">
        <table class="data-table">
            <thead>
            <tr>
                <th>#</th>
                <th>الرمز</th>
                <th>المنطقة</th>
                <th>العنوان</th>
                <th>الترتيب</th>
                <th>العملاء</th>
                <th>المندوبون</th>
                <th>الحالة</th>
                <th>إجراءات</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="9" class="muted">لا توجد مناطق بعد. استورد Excel أو أضف يدوياً.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td><code><?= esc((string) $r['code']) ?></code></td>
                    <td><?= esc((string) $r['name_ar']) ?></td>
                    <td><?= esc(trim((string) ($r['address_ar'] ?? '')) !== '' ? (string) $r['address_ar'] : '—') ?></td>
                    <td dir="ltr"><?= (int) $r['sort_order'] ?></td>
                    <td dir="ltr"><?= (int) ($r['customer_count'] ?? 0) ?></td>
                    <td dir="ltr"><?= (int) ($r['rep_count'] ?? 0) ?></td>
                    <td>
                        <?php if ((int) $r['is_active']): ?>
                            <span class="badge badge-ok">نشط</span>
                        <?php else: ?>
                            <span class="badge badge-off">موقوف</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-secondary btn-sm"
                               href="<?= esc(app_url('index.php?r=customer_regions&action=edit&id=' . (int) $r['id'])) ?>">تعديل</a>
                            <form method="post" action="<?= esc($listUrl) ?>" data-confirm="تغيير حالة المنطقة؟">
                                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                <input type="hidden" name="_action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit">
                                    <?= (int) $r['is_active'] ? 'تعطيل' : 'تفعيل' ?>
                                </button>
                            </form>
                            <?php if ((int) ($r['customer_count'] ?? 0) < 1): ?>
                                <form method="post" action="<?= esc($listUrl) ?>" data-confirm="حذف المنطقة نهائياً؟">
                                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php sales_ora12_workspace_close(); ?>
</div>
