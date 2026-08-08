<?php
declare(strict_types=1);

/**
 * شاشة مناطق العملاء — قائمة مناطق + عناوين مربوطة + استيراد Excel.
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
        تعذر إنشاء جداول المناطق. نفّذ:
        <code>database/migrations/250_crm_region.sql</code>
        و<code>255_crm_region_address_link.sql</code>
        ثم حدّث الصفحة.
    </div>
    <?php
    return;
}

$selectedRegionId = (int) ($_GET['region_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }

    $act = (string) ($_POST['_action'] ?? '');
    $redirRegion = (int) ($_POST['region_id'] ?? 0);

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
                $msg .= ' — تنبيه: ' . count($result['warnings']) . ' صف لم يُربط بعميل (الرقم غير موجود).';
            }
            flash_set('success', $msg);
            redirect($listUrl);
        }

        if ($act === 'save_region') {
            $id = (int) ($_POST['id'] ?? 0);
            $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $name = trim((string) ($_POST['name_ar'] ?? ''));
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

            $dupName = $pdo->prepare(
                $id > 0
                    ? 'SELECT id FROM crm_region WHERE name_ar = ? AND id <> ? LIMIT 1'
                    : 'SELECT id FROM crm_region WHERE name_ar = ? LIMIT 1'
            );
            if ($id > 0) {
                $dupName->execute([$name, $id]);
            } else {
                $dupName->execute([$name]);
            }
            if ($dupName->fetch()) {
                throw new RuntimeException('اسم المنطقة مستخدم مسبقاً.');
            }

            if ($id > 0) {
                $st = $pdo->prepare('UPDATE crm_region SET code=?, name_ar=?, sort_order=? WHERE id=?');
                $st->execute([$code, $name, $sort, $id]);
                $redirRegion = $id;
                flash_set('success', 'تم تحديث اسم المنطقة.');
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO crm_region (code, name_ar, sort_order, is_active) VALUES (?,?,?,1)'
                );
                $st->execute([$code, $name, $sort]);
                $redirRegion = (int) $pdo->lastInsertId();
                flash_set('success', 'تم إضافة المنطقة. أضف عناوينها أدناه.');
            }
        } elseif ($act === 'save_address') {
            $id = (int) ($_POST['id'] ?? 0);
            $regionId = (int) ($_POST['region_id'] ?? 0);
            $name = trim((string) ($_POST['name_ar'] ?? ''));
            $sort = (int) ($_POST['sort_order'] ?? 0);
            if ($regionId < 1) {
                throw new RuntimeException('اختر المنطقة أولاً.');
            }
            if ($name === '') {
                throw new RuntimeException('اسم العنوان مطلوب.');
            }
            if (!crm_region_id_exists($pdo, $regionId)) {
                throw new RuntimeException('المنطقة غير موجودة.');
            }

            if ($id > 0) {
                $chk = $pdo->prepare(
                    'SELECT id FROM crm_region_address WHERE region_id = ? AND name_ar = ? AND id <> ? LIMIT 1'
                );
                $chk->execute([$regionId, $name, $id]);
            } else {
                $chk = $pdo->prepare(
                    'SELECT id FROM crm_region_address WHERE region_id = ? AND name_ar = ? LIMIT 1'
                );
                $chk->execute([$regionId, $name]);
            }
            if ($chk->fetch()) {
                throw new RuntimeException('هذا العنوان مربوط بالمنطقة مسبقاً.');
            }

            if ($id > 0) {
                $own = $pdo->prepare('SELECT region_id FROM crm_region_address WHERE id = ? LIMIT 1');
                $own->execute([$id]);
                if ((int) $own->fetchColumn() !== $regionId) {
                    throw new RuntimeException('العنوان لا يخص هذه المنطقة.');
                }
                $pdo->prepare('UPDATE crm_region_address SET name_ar=?, sort_order=? WHERE id=?')
                    ->execute([$name, $sort, $id]);
                flash_set('success', 'تم تحديث العنوان.');
            } else {
                $pdo->prepare(
                    'INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active) VALUES (?,?,?,1)'
                )->execute([$regionId, $name, $sort]);
                flash_set('success', 'تم ربط العنوان بالمنطقة.');
            }
            $redirRegion = $regionId;
        } elseif ($act === 'toggle_region') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $pdo->prepare('UPDATE crm_region SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            $redirRegion = $id;
            flash_set('success', 'تم تحديث حالة المنطقة.');
        } elseif ($act === 'toggle_address') {
            $id = (int) ($_POST['id'] ?? 0);
            $regionId = (int) ($_POST['region_id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $pdo->prepare('UPDATE crm_region_address SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            $redirRegion = $regionId;
            flash_set('success', 'تم تحديث حالة العنوان.');
        } elseif ($act === 'delete_region') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $stCnt = $pdo->prepare('SELECT COUNT(*) FROM crm_customer WHERE region_id = ?');
            $stCnt->execute([$id]);
            $cnt = (int) $stCnt->fetchColumn();
            if ($cnt > 0) {
                throw new RuntimeException('لا يمكن الحذف: ' . $cnt . ' عميل مرتبط. عطّل المنطقة بدلاً من الحذف.');
            }
            $pdo->prepare('DELETE FROM crm_region WHERE id = ?')->execute([$id]);
            $redirRegion = 0;
            flash_set('success', 'تم حذف المنطقة وعناوينها.');
        } elseif ($act === 'delete_address') {
            $id = (int) ($_POST['id'] ?? 0);
            $regionId = (int) ($_POST['region_id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $stCnt = $pdo->prepare('SELECT COUNT(*) FROM crm_customer WHERE region_address_id = ?');
            $stCnt->execute([$id]);
            $cnt = (int) $stCnt->fetchColumn();
            if ($cnt > 0) {
                throw new RuntimeException('لا يمكن الحذف: ' . $cnt . ' عميل مرتبط بهذا العنوان. عطّله بدلاً من الحذف.');
            }
            $pdo->prepare('DELETE FROM crm_region_address WHERE id = ?')->execute([$id]);
            $redirRegion = $regionId;
            flash_set('success', 'تم فك ربط العنوان.');
        } else {
            throw new RuntimeException('إجراء غير معروف.');
        }
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        flash_set('error', 'تعذر تنفيذ العملية: ' . $e->getMessage());
    }

    $go = $listUrl;
    if ($redirRegion > 0) {
        $go .= (str_contains($listUrl, '?') ? '&' : '?') . 'region_id=' . $redirRegion;
    }
    redirect($go);
}

$flash = flash_get();
$excelPath = crm_region_excel_resolve_path();
$excelDefaultHint = 'C:\\xampp\\htdocs\\system\\المنطقة.xlsx';
$regions = crm_region_load_all($pdo);

if ($selectedRegionId < 1 && $regions) {
    $selectedRegionId = (int) ($regions[0]['id'] ?? 0);
}

$selectedRegion = null;
foreach ($regions as $rg) {
    if ((int) $rg['id'] === $selectedRegionId) {
        $selectedRegion = $rg;
        break;
    }
}
$addresses = $selectedRegionId > 0 ? crm_region_address_load($pdo, $selectedRegionId, false) : [];

$editRegionId = (int) ($_GET['edit_region'] ?? 0);
$editAddressId = (int) ($_GET['edit_address'] ?? 0);
$editRegionRow = null;
$editAddressRow = null;
if ($editRegionId > 0) {
    foreach ($regions as $rg) {
        if ((int) $rg['id'] === $editRegionId) {
            $editRegionRow = $rg;
            break;
        }
    }
}
if ($editAddressId > 0) {
    foreach ($addresses as $a) {
        if ((int) $a['id'] === $editAddressId) {
            $editAddressRow = $a;
            break;
        }
    }
}

sales_ora12_enqueue_assets();
$csrf = csrf_token();
$selName = $selectedRegion ? (string) $selectedRegion['name_ar'] : '';
?>
<style>
.rg-simple{max-width:720px;margin:0 auto}
.rg-simple .rg-bar{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-bottom:1rem}
.rg-simple .rg-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:1rem 1.1rem;margin-bottom:1rem}
.rg-simple h3{margin:0 0 .75rem;font-size:1rem;font-weight:600}
.rg-simple .rg-row{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
.rg-simple .rg-row .input,.rg-simple .rg-row select{flex:1;min-width:10rem}
.rg-simple .rg-hint{margin:.35rem 0 0;font-size:.85rem;color:#6b7280}
.rg-simple table{width:100%;border-collapse:collapse;margin-top:.75rem}
.rg-simple th,.rg-simple td{padding:.5rem .4rem;border-bottom:1px solid #f0f0f0;text-align:right;font-size:.93rem}
.rg-simple th{color:#6b7280;font-weight:600;font-size:.8rem}
.rg-simple tr.rg-off td{opacity:.55}
.rg-simple .rg-actions{display:flex;gap:.3rem;flex-wrap:wrap;justify-content:flex-start}
.rg-simple .rg-actions form{display:inline;margin:0}
.rg-simple details.rg-import{margin-bottom:1rem}
.rg-simple details.rg-import>summary{cursor:pointer;font-weight:600;padding:.55rem .75rem;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;list-style:none}
.rg-simple details.rg-import[open]>summary{border-radius:8px 8px 0 0;border-bottom:none}
.rg-simple details.rg-import .rg-import-body{border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;padding:.85rem 1rem;background:#fff}
.rg-simple .rg-empty{padding:.85rem 0;color:#9ca3af;text-align:center}
.rg-simple .rg-name-form{display:inline;margin:0}
.rg-simple .rg-name-form input.input{min-width:8rem;padding:.25rem .4rem;font-size:.9rem}
</style>
<div class="dashboard-ora sales-ora12-screen customers-ora-screen">
    <?php sales_ora12_render_title_bar('مناطق العملاء'); ?>
    <?php sales_ora12_workspace_open(); ?>

    <div class="rg-simple">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash"><?= esc($flash['message']) ?></div>
        <?php endif; ?>

        <div class="rg-bar">
            <a class="btn btn-ghost btn-sm" href="<?= esc(app_url('index.php?r=customers')) ?>">العملاء</a>
            <a class="btn btn-ghost btn-sm" href="<?= esc(app_url('index.php?r=sales_reps')) ?>">المندوبين</a>
        </div>

        <details class="rg-import">
            <summary>استيراد من Excel</summary>
            <div class="rg-import-body">
                <p class="rg-hint" style="margin-top:0">
                    ارفع ملف المناطق (رقم العميل · المنطقة · العنوان · المندوب).
                    <?php if ($excelPath): ?>
                        ملف جاهز على القرص: <code dir="ltr"><?= esc(basename($excelPath)) ?></code>
                    <?php endif; ?>
                </p>
                <form method="post" action="<?= esc($listUrl) ?>" enctype="multipart/form-data" class="rg-row">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                    <input type="hidden" name="_action" value="import_excel">
                    <input class="input" type="file" name="excel_file" accept=".xlsx">
                    <button class="btn btn-primary btn-sm" type="submit" data-confirm="استيراد الملف وربطه بالعملاء؟">استيراد</button>
                </form>
            </div>
        </details>

        <!-- 1) المنطقة -->
        <div class="rg-card">
            <h3>1) المنطقة</h3>
            <form method="get" action="<?= esc(app_url('index.php')) ?>" class="rg-row" id="rg-select-form">
                <input type="hidden" name="r" value="customer_regions">
                <select class="input" name="region_id" onchange="this.form.submit()" aria-label="اختر المنطقة">
                    <?php if (!$regions): ?>
                        <option value="">لا توجد مناطق</option>
                    <?php endif; ?>
                    <?php foreach ($regions as $rg):
                        $rid = (int) $rg['id'];
                        $label = (string) $rg['name_ar'];
                        if (!(int) $rg['is_active']) {
                            $label .= ' (موقوف)';
                        }
                        $label .= ' — ' . (int) ($rg['address_count'] ?? 0) . ' عنوان';
                        ?>
                        <option value="<?= $rid ?>"<?= $rid === $selectedRegionId ? ' selected' : '' ?>>
                            <?= esc($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <form method="post" action="<?= esc($listUrl) ?>" class="rg-row" style="margin-top:.65rem">
                <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                <input type="hidden" name="_action" value="save_region">
                <input type="hidden" name="id" value="<?= (int) ($editRegionRow['id'] ?? 0) ?>">
                <input type="hidden" name="code" value="<?= esc((string) ($editRegionRow['code'] ?? '')) ?>">
                <input type="hidden" name="sort_order" value="<?= (int) ($editRegionRow['sort_order'] ?? 0) ?>">
                <input class="input" name="name_ar" required maxlength="180"
                       value="<?= esc((string) ($editRegionRow['name_ar'] ?? '')) ?>"
                       placeholder="<?= $editRegionRow ? 'تعديل اسم المنطقة' : 'منطقة جديدة (مثل: عمان الغربية)' ?>">
                <button class="btn btn-primary btn-sm" type="submit">
                    <?= $editRegionRow ? 'حفظ الاسم' : 'إضافة' ?>
                </button>
                <?php if ($editRegionRow): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= esc($listUrl . '&region_id=' . $selectedRegionId) ?>">إلغاء</a>
                <?php endif; ?>
            </form>

            <?php if ($selectedRegion): ?>
                <div class="rg-row" style="margin-top:.65rem">
                    <a class="btn btn-secondary btn-sm"
                       href="<?= esc($listUrl . '&region_id=' . $selectedRegionId . '&edit_region=' . $selectedRegionId) ?>">تعديل الاسم</a>
                    <form method="post" action="<?= esc($listUrl) ?>" data-confirm="تغيير حالة المنطقة؟">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                        <input type="hidden" name="_action" value="toggle_region">
                        <input type="hidden" name="id" value="<?= $selectedRegionId ?>">
                        <button class="btn btn-ghost btn-sm" type="submit"><?= (int) $selectedRegion['is_active'] ? 'تعطيل المنطقة' : 'تفعيل المنطقة' ?></button>
                    </form>
                    <?php if ((int) ($selectedRegion['customer_count'] ?? 0) < 1): ?>
                        <form method="post" action="<?= esc($listUrl) ?>" data-confirm="حذف المنطقة وكل عناوينها؟">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                            <input type="hidden" name="_action" value="delete_region">
                            <input type="hidden" name="id" value="<?= $selectedRegionId ?>">
                            <button class="btn btn-ghost btn-sm" type="submit" style="color:#b91c1c">حذف المنطقة</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- 2) العناوين -->
        <div class="rg-card">
            <h3>
                2) العناوين
                <?php if ($selName !== ''): ?>
                    <span style="font-weight:500;color:#0f766e">— <?= esc($selName) ?></span>
                <?php endif; ?>
            </h3>

            <?php if (!$selectedRegion): ?>
                <p class="rg-empty">أضف منطقة أولاً ثم أضف عناوينها.</p>
            <?php else: ?>
                <form method="post" action="<?= esc($listUrl) ?>" class="rg-row">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                    <input type="hidden" name="_action" value="save_address">
                    <input type="hidden" name="region_id" value="<?= $selectedRegionId ?>">
                    <input type="hidden" name="id" value="<?= (int) ($editAddressRow['id'] ?? 0) ?>">
                    <input type="hidden" name="sort_order" value="<?= (int) ($editAddressRow['sort_order'] ?? 0) ?>">
                    <input class="input" name="name_ar" required maxlength="180"
                           value="<?= esc((string) ($editAddressRow['name_ar'] ?? '')) ?>"
                           placeholder="<?= $editAddressRow ? 'تعديل العنوان' : 'عنوان جديد (مثل: الرابية)' ?>"
                           <?= $editAddressRow ? 'autofocus' : '' ?>>
                    <button class="btn btn-primary btn-sm" type="submit">
                        <?= $editAddressRow ? 'حفظ' : 'إضافة عنوان' ?>
                    </button>
                    <?php if ($editAddressRow): ?>
                        <a class="btn btn-ghost btn-sm" href="<?= esc($listUrl . '&region_id=' . $selectedRegionId) ?>">إلغاء</a>
                    <?php endif; ?>
                </form>

                <?php if (!$addresses): ?>
                    <p class="rg-empty">لا عناوين بعد لهذه المنطقة.</p>
                <?php else: ?>
                    <table>
                        <thead>
                        <tr>
                            <th>العنوان</th>
                            <th style="width:4rem">عملاء</th>
                            <th style="width:9rem"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($addresses as $a):
                            $aid = (int) $a['id'];
                            $on = (int) $a['is_active'];
                            ?>
                            <tr class="<?= $on ? '' : 'rg-off' ?>">
                                <td>
                                    <?= esc((string) $a['name_ar']) ?>
                                    <?php if (!$on): ?><span class="badge badge-off" style="font-size:.7rem;margin-inline-start:.35rem">موقوف</span><?php endif; ?>
                                </td>
                                <td dir="ltr"><?= (int) ($a['customer_count'] ?? 0) ?></td>
                                <td>
                                    <div class="rg-actions">
                                        <a class="btn btn-ghost btn-sm"
                                           href="<?= esc($listUrl . '&region_id=' . $selectedRegionId . '&edit_address=' . $aid) ?>">تعديل</a>
                                        <form method="post" action="<?= esc($listUrl) ?>" data-confirm="تغيير حالة العنوان؟">
                                            <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                                            <input type="hidden" name="_action" value="toggle_address">
                                            <input type="hidden" name="id" value="<?= $aid ?>">
                                            <input type="hidden" name="region_id" value="<?= $selectedRegionId ?>">
                                            <button class="btn btn-ghost btn-sm" type="submit"><?= $on ? 'تعطيل' : 'تفعيل' ?></button>
                                        </form>
                                        <?php if ((int) ($a['customer_count'] ?? 0) < 1): ?>
                                            <form method="post" action="<?= esc($listUrl) ?>" data-confirm="حذف العنوان؟">
                                                <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                                                <input type="hidden" name="_action" value="delete_address">
                                                <input type="hidden" name="id" value="<?= $aid ?>">
                                                <input type="hidden" name="region_id" value="<?= $selectedRegionId ?>">
                                                <button class="btn btn-ghost btn-sm" type="submit" style="color:#b91c1c">حذف</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php sales_ora12_workspace_close(); ?>
</div>
