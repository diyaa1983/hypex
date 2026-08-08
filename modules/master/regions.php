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
                flash_set('success', 'تم إضافة المنطقة. أضف عناوينها من القائمة اليسرى.');
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
?>
<style>
.crm-ra-layout{display:grid;grid-template-columns:minmax(260px,1fr) minmax(300px,1.15fr);gap:1rem;align-items:start}
@media (max-width:900px){.crm-ra-layout{grid-template-columns:1fr}}
.crm-ra-list{list-style:none;margin:0;padding:0;max-height:28rem;overflow:auto}
.crm-ra-list li{border-bottom:1px solid rgba(0,0,0,.06)}
.crm-ra-list a{display:flex;justify-content:space-between;gap:.5rem;padding:.55rem .65rem;text-decoration:none;color:inherit}
.crm-ra-list a:hover{background:rgba(13,110,110,.06)}
.crm-ra-list a.is-active{background:rgba(13,110,110,.12);font-weight:600}
.crm-ra-list .cnt{font-size:.8rem;opacity:.7;direction:ltr}
.crm-ra-muted{font-size:.88rem;opacity:.8;margin:0 0 .65rem}
.crm-ra-form-inline{display:flex;flex-wrap:wrap;gap:.5rem;align-items:end;margin-bottom:.75rem}
.crm-ra-form-inline .field{margin:0;flex:1;min-width:9rem}
.crm-ra-table{width:100%;border-collapse:collapse;font-size:.92rem}
.crm-ra-table th,.crm-ra-table td{padding:.45rem .5rem;border-bottom:1px solid rgba(0,0,0,.07);text-align:right}
.crm-ra-table th{font-weight:600;background:rgba(0,0,0,.03)}
</style>
<div class="dashboard-ora sales-ora12-screen customers-ora-screen">
    <?php sales_ora12_render_title_bar('مناطق العملاء'); ?>
    <?php sales_ora12_workspace_open(); ?>
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="sales-ora-toolbar toolbar" style="flex-wrap:wrap;gap:0.5rem;">
        <a class="btn btn-ghost btn-sm" href="<?= esc(app_url('index.php?r=customers')) ?>">العملاء</a>
        <a class="btn btn-ghost btn-sm" href="<?= esc(app_url('index.php?r=sales_reps')) ?>">المندوبين</a>
    </div>

    <div class="sales-ora-panel card" style="margin-bottom:1rem;">
        <h3 style="margin:0 0 0.5rem;font-size:1rem;">استيراد من Excel</h3>
        <p class="crm-ra-muted">
            الملف الافتراضي: <code dir="ltr"><?= esc($excelDefaultHint) ?></code>
            <?php if ($excelPath): ?>
                — موجود: <code dir="ltr"><?= esc($excelPath) ?></code>
            <?php else: ?>
                — <strong>غير موجود</strong>؛ ارفع الملف أدناه.
            <?php endif; ?>
            <br>
            الأعمدة: <strong>رقم العميل</strong> · <strong>اسم العميل</strong> · <strong>العنوان</strong> · <strong>المنطقة</strong> · <strong>اسم المندوب</strong>
            — يُنشئ المناطق/العناوين ويربط كل مندوب بالعميل حسب رقم العميل.
        </p>
        <form method="post" action="<?= esc($listUrl) ?>" enctype="multipart/form-data" class="form-row" style="flex-wrap:wrap;gap:0.5rem;align-items:end;">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="import_excel">
            <label class="field">
                <span class="field-label">رفع ملف</span>
                <input class="input" type="file" name="excel_file" accept=".xlsx">
            </label>
            <button class="btn btn-primary" type="submit" data-confirm="استيراد المناطق والعناوين وربط المندوبين بالعملاء؟">
                استيراد الآن
            </button>
        </form>
    </div>

    <div class="crm-ra-layout">
        <!-- قائمة المناطق -->
        <div class="sales-ora-panel card">
            <h3 style="margin:0 0 0.4rem;font-size:1rem;">قائمة المناطق</h3>
            <p class="crm-ra-muted">مثال: عمان الغربية، شمال عمان، وسط عمان</p>

            <form method="post" action="<?= esc($listUrl) ?>" class="crm-ra-form-inline">
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="_action" value="save_region">
                <input type="hidden" name="id" value="<?= (int) ($editRegionRow['id'] ?? 0) ?>">
                <label class="field">
                    <span class="field-label">اسم المنطقة *</span>
                    <input class="input" name="name_ar" required maxlength="180"
                           value="<?= esc((string) ($editRegionRow['name_ar'] ?? '')) ?>"
                           placeholder="شمال عمان">
                </label>
                <label class="field" style="flex:0 0 6rem;">
                    <span class="field-label">الرمز</span>
                    <input class="input" name="code" dir="ltr" maxlength="20"
                           value="<?= esc((string) ($editRegionRow['code'] ?? '')) ?>" placeholder="تلقائي">
                </label>
                <label class="field" style="flex:0 0 5rem;">
                    <span class="field-label">ترتيب</span>
                    <input class="input" name="sort_order" type="number" dir="ltr"
                           value="<?= (int) ($editRegionRow['sort_order'] ?? 0) ?>">
                </label>
                <button class="btn btn-primary btn-sm" type="submit">
                    <?= $editRegionRow ? 'تحديث' : 'إضافة منطقة' ?>
                </button>
                <?php if ($editRegionRow): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= esc($listUrl . '&region_id=' . $selectedRegionId) ?>">إلغاء</a>
                <?php endif; ?>
            </form>

            <ul class="crm-ra-list">
                <?php if (!$regions): ?>
                    <li class="muted" style="padding:.65rem;">لا توجد مناطق بعد.</li>
                <?php endif; ?>
                <?php foreach ($regions as $rg):
                    $rid = (int) $rg['id'];
                    $active = $rid === $selectedRegionId;
                    $href = $listUrl . '&region_id=' . $rid;
                    ?>
                    <li>
                        <a href="<?= esc($href) ?>" class="<?= $active ? 'is-active' : '' ?>">
                            <span>
                                <?= esc((string) $rg['name_ar']) ?>
                                <?php if (!(int) $rg['is_active']): ?>
                                    <span class="badge badge-off" style="font-size:.7rem;">موقوف</span>
                                <?php endif; ?>
                            </span>
                            <span class="cnt"><?= (int) ($rg['address_count'] ?? 0) ?> عنوان</span>
                        </a>
                        <?php if ($active): ?>
                            <div class="row-actions" style="padding:0 .5rem .5rem;display:flex;gap:.35rem;flex-wrap:wrap;">
                                <a class="btn btn-secondary btn-sm"
                                   href="<?= esc($listUrl . '&region_id=' . $rid . '&edit_region=' . $rid) ?>">تعديل الاسم</a>
                                <form method="post" action="<?= esc($listUrl) ?>" data-confirm="تغيير حالة المنطقة؟" style="display:inline;">
                                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                    <input type="hidden" name="_action" value="toggle_region">
                                    <input type="hidden" name="id" value="<?= $rid ?>">
                                    <button class="btn btn-danger btn-sm" type="submit"><?= (int) $rg['is_active'] ? 'تعطيل' : 'تفعيل' ?></button>
                                </form>
                                <?php if ((int) ($rg['customer_count'] ?? 0) < 1): ?>
                                    <form method="post" action="<?= esc($listUrl) ?>" data-confirm="حذف المنطقة وكل عناوينها؟" style="display:inline;">
                                        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="_action" value="delete_region">
                                        <input type="hidden" name="id" value="<?= $rid ?>">
                                        <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- عناوين المنطقة -->
        <div class="sales-ora-panel card">
            <h3 style="margin:0 0 0.4rem;font-size:1rem;">
                عناوين المنطقة
                <?php if ($selectedRegion): ?>
                    — <span style="color:#0d6e6e;"><?= esc((string) $selectedRegion['name_ar']) ?></span>
                <?php endif; ?>
            </h3>

            <?php if (!$selectedRegion): ?>
                <p class="crm-ra-muted">اختر منطقة من القائمة أو أضف منطقة جديدة ثم اربط بها العناوين (الرابية، الشميساني…).</p>
            <?php else: ?>
                <p class="crm-ra-muted">كل عنوان مرتبط فقط بالمنطقة المحددة. المندوب يختار منطقة ثم يرى هذه العناوين.</p>

                <form method="post" action="<?= esc($listUrl) ?>" class="crm-ra-form-inline">
                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                    <input type="hidden" name="_action" value="save_address">
                    <input type="hidden" name="region_id" value="<?= $selectedRegionId ?>">
                    <input type="hidden" name="id" value="<?= (int) ($editAddressRow['id'] ?? 0) ?>">
                    <label class="field">
                        <span class="field-label">اسم العنوان *</span>
                        <input class="input" name="name_ar" required maxlength="180"
                               value="<?= esc((string) ($editAddressRow['name_ar'] ?? '')) ?>"
                               placeholder="الرابية، شفا بدران…">
                    </label>
                    <label class="field" style="flex:0 0 5rem;">
                        <span class="field-label">ترتيب</span>
                        <input class="input" name="sort_order" type="number" dir="ltr"
                               value="<?= (int) ($editAddressRow['sort_order'] ?? 0) ?>">
                    </label>
                    <button class="btn btn-primary btn-sm" type="submit">
                        <?= $editAddressRow ? 'تحديث العنوان' : 'ربط عنوان' ?>
                    </button>
                    <?php if ($editAddressRow): ?>
                        <a class="btn btn-ghost btn-sm" href="<?= esc($listUrl . '&region_id=' . $selectedRegionId) ?>">إلغاء</a>
                    <?php endif; ?>
                </form>

                <table class="crm-ra-table">
                    <thead>
                    <tr>
                        <th>العنوان</th>
                        <th>عملاء</th>
                        <th>حالة</th>
                        <th>إجراء</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$addresses): ?>
                        <tr><td colspan="4" class="muted">لا عناوين بعد — أضف من النموذج أعلاه أو استورد Excel.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($addresses as $a): ?>
                        <tr>
                            <td><?= esc((string) $a['name_ar']) ?></td>
                            <td dir="ltr"><?= (int) ($a['customer_count'] ?? 0) ?></td>
                            <td>
                                <?php if ((int) $a['is_active']): ?>
                                    <span class="badge badge-ok">نشط</span>
                                <?php else: ?>
                                    <span class="badge badge-off">موقوف</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="row-actions" style="display:flex;gap:.3rem;flex-wrap:wrap;">
                                    <a class="btn btn-secondary btn-sm"
                                       href="<?= esc($listUrl . '&region_id=' . $selectedRegionId . '&edit_address=' . (int) $a['id']) ?>">تعديل</a>
                                    <form method="post" action="<?= esc($listUrl) ?>" data-confirm="تغيير حالة العنوان؟" style="display:inline;">
                                        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                        <input type="hidden" name="_action" value="toggle_address">
                                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                        <input type="hidden" name="region_id" value="<?= $selectedRegionId ?>">
                                        <button class="btn btn-danger btn-sm" type="submit"><?= (int) $a['is_active'] ? 'تعطيل' : 'تفعيل' ?></button>
                                    </form>
                                    <?php if ((int) ($a['customer_count'] ?? 0) < 1): ?>
                                        <form method="post" action="<?= esc($listUrl) ?>" data-confirm="حذف هذا العنوان؟" style="display:inline;">
                                            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                            <input type="hidden" name="_action" value="delete_address">
                                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                            <input type="hidden" name="region_id" value="<?= $selectedRegionId ?>">
                                            <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php sales_ora12_workspace_close(); ?>
</div>
