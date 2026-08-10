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
$selCode = $selectedRegion ? (string) ($selectedRegion['code'] ?? '') : '';
$cssPath = app_path('assets/css/regions-ssms.css');
$cssUrl = app_url('assets/css/regions-ssms.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$customersUrl = app_url('index.php?r=customers');
$repsUrl = app_url('index.php?r=sales_reps');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<div class="dashboard-ora sales-ora12-screen rg-ssms" data-exit-guard="custom">
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <h1 class="dashboard-ora-screen-title__text">تعريف المناطق</h1>
        <span class="dashboard-ora-screen-title__meta"><?= count($regions) ?> منطقة</span>
        <?php nav_render_screen_close($GLOBALS['activeRoute'] ?? 'customer_regions'); ?>
    </header>

    <div class="dashboard-ora-workspace rg-ssms-workspace">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> rg-ssms-flash"><?= esc($flash['message']) ?></div>
        <?php endif; ?>

        <!-- شريط أدوات SSMS -->
        <div class="rg-ssms-toolbar" role="toolbar">
            <a class="rg-tb" href="<?= esc($listUrl . ($selectedRegionId ? '&region_id=' . $selectedRegionId : '') . '#rg-new-region') ?>" title="منطقة جديدة">
                <span class="rg-tb-ico">＋</span> منطقة
            </a>
            <?php if ($selectedRegionId > 0): ?>
                <a class="rg-tb" href="<?= esc($listUrl . '&region_id=' . $selectedRegionId . '#rg-new-addr') ?>" title="عنوان جديد">
                    <span class="rg-tb-ico">＋</span> عنوان
                </a>
            <?php endif; ?>
            <span class="rg-tb-sep"></span>
            <a class="rg-tb" href="<?= esc($customersUrl) ?>">العملاء</a>
            <a class="rg-tb" href="<?= esc($repsUrl) ?>">المندوبون</a>
            <span class="rg-tb-sep"></span>
            <button type="button" class="rg-tb" id="rg-import-toggle" title="استيراد Excel">
                <span class="rg-tb-ico">▤</span> استيراد
            </button>
            <span class="rg-tb-grow"></span>
            <span class="rg-tb-hint" dir="ltr">dbo.crm_region · crm_region_address</span>
        </div>

        <div class="rg-ssms-import" id="rg-import-box" hidden>
            <form method="post" action="<?= esc($listUrl) ?>" enctype="multipart/form-data" class="rg-ssms-import-form">
                <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                <input type="hidden" name="_action" value="import_excel">
                <label class="rg-ssms-lbl">ملف Excel
                    <input class="rg-ssms-input" type="file" name="excel_file" accept=".xlsx">
                </label>
                <button class="rg-tb rg-tb--primary" type="submit" data-confirm="استيراد المناطق والعناوين وربط العملاء؟">تنفيذ الاستيراد</button>
                <span class="rg-ssms-muted">رقم العميل · المنطقة · العنوان · المندوب</span>
            </form>
        </div>

        <div class="rg-ssms-split">
            <!-- Object Explorer -->
            <aside class="rg-ssms-explorer" aria-label="مستكشف المناطق">
                <div class="rg-ssms-pane-title">
                    <span class="rg-ssms-folder">🗀</span> Object Explorer
                    <span class="rg-ssms-count"><?= count($regions) ?></span>
                </div>
                <div class="rg-ssms-tree-head">
                    <span class="rg-ssms-server">■ CRM → Regions</span>
                </div>
                <ul class="rg-ssms-tree">
                    <?php if (!$regions): ?>
                        <li class="rg-ssms-empty-node">لا توجد مناطق</li>
                    <?php endif; ?>
                    <?php foreach ($regions as $rg):
                        $rid = (int) $rg['id'];
                        $active = $rid === $selectedRegionId;
                        $nAddr = (int) ($rg['address_count'] ?? 0);
                        $isOn = (int) ($rg['is_active'] ?? 1);
                        ?>
                        <li class="<?= $active ? 'is-selected' : '' ?><?= $isOn ? '' : ' is-off' ?>">
                            <a href="<?= esc($listUrl . '&region_id=' . $rid) ?>" class="rg-ssms-node">
                                <span class="rg-ssms-icon">▣</span>
                                <span class="rg-ssms-node-name"><?= esc((string) $rg['name_ar']) ?></span>
                                <span class="rg-ssms-badge" dir="ltr"><?= $nAddr ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div class="rg-ssms-pane-title rg-ssms-pane-title--sub" id="rg-new-region">New Region</div>
                <form method="post" action="<?= esc($listUrl) ?>" class="rg-ssms-mini-form">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                    <input type="hidden" name="_action" value="save_region">
                    <input type="hidden" name="id" value="<?= (int) ($editRegionRow['id'] ?? 0) ?>">
                    <input type="hidden" name="code" value="<?= esc((string) ($editRegionRow['code'] ?? '')) ?>">
                    <input type="hidden" name="sort_order" value="<?= (int) ($editRegionRow['sort_order'] ?? 0) ?>">
                    <label class="rg-ssms-lbl">Name
                        <input class="rg-ssms-input" name="name_ar" required maxlength="180"
                               value="<?= esc((string) ($editRegionRow['name_ar'] ?? '')) ?>"
                               placeholder="عمان الغربية">
                    </label>
                    <div class="rg-ssms-mini-actions">
                        <button class="rg-tb rg-tb--primary" type="submit">
                            <?= $editRegionRow ? 'Update' : 'Add' ?>
                        </button>
                        <?php if ($editRegionRow): ?>
                            <a class="rg-tb" href="<?= esc($listUrl . '&region_id=' . $selectedRegionId) ?>">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </aside>

            <!-- Results / Properties -->
            <main class="rg-ssms-results">
                <div class="rg-ssms-pane-title">
                    <?php if ($selectedRegion): ?>
                        <span class="rg-ssms-folder">▦</span>
                        Results — <?= esc($selName) ?>
                        <span class="rg-ssms-muted" dir="ltr">(<?= esc($selCode) ?>)</span>
                    <?php else: ?>
                        <span class="rg-ssms-folder">▦</span> Results
                    <?php endif; ?>
                </div>

                <?php if (!$selectedRegion): ?>
                    <div class="rg-ssms-placeholder">
                        <p>Select a region from Object Explorer</p>
                        <p class="rg-ssms-muted">اختر منطقة من الشجرة لعرض العناوين المرتبطة</p>
                    </div>
                <?php else: ?>
                    <!-- Object properties strip -->
                    <div class="rg-ssms-props">
                        <div class="rg-ssms-prop"><span>Name</span><b><?= esc($selName) ?></b></div>
                        <div class="rg-ssms-prop"><span>Code</span><b dir="ltr"><?= esc($selCode) ?></b></div>
                        <div class="rg-ssms-prop"><span>Addresses</span><b dir="ltr"><?= count($addresses) ?></b></div>
                        <div class="rg-ssms-prop"><span>Customers</span><b dir="ltr"><?= (int) ($selectedRegion['customer_count'] ?? 0) ?></b></div>
                        <div class="rg-ssms-prop"><span>Status</span>
                            <b class="<?= (int) $selectedRegion['is_active'] ? 'is-on' : 'is-off' ?>">
                                <?= (int) $selectedRegion['is_active'] ? 'Active' : 'Disabled' ?>
                            </b>
                        </div>
                        <div class="rg-ssms-prop-actions">
                            <a class="rg-tb" href="<?= esc($listUrl . '&region_id=' . $selectedRegionId . '&edit_region=' . $selectedRegionId) ?>">Edit</a>
                            <form method="post" action="<?= esc($listUrl) ?>" data-confirm="Toggle region status?">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                                <input type="hidden" name="_action" value="toggle_region">
                                <input type="hidden" name="id" value="<?= $selectedRegionId ?>">
                                <button class="rg-tb" type="submit"><?= (int) $selectedRegion['is_active'] ? 'Disable' : 'Enable' ?></button>
                            </form>
                            <?php if ((int) ($selectedRegion['customer_count'] ?? 0) < 1): ?>
                                <form method="post" action="<?= esc($listUrl) ?>" data-confirm="Delete region and all addresses?">
                                    <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                                    <input type="hidden" name="_action" value="delete_region">
                                    <input type="hidden" name="id" value="<?= $selectedRegionId ?>">
                                    <button class="rg-tb rg-tb--danger" type="submit">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Grid toolbar -->
                    <div class="rg-ssms-grid-bar" id="rg-new-addr">
                        <form method="post" action="<?= esc($listUrl) ?>" class="rg-ssms-grid-add">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                            <input type="hidden" name="_action" value="save_address">
                            <input type="hidden" name="region_id" value="<?= $selectedRegionId ?>">
                            <input type="hidden" name="id" value="<?= (int) ($editAddressRow['id'] ?? 0) ?>">
                            <input type="hidden" name="sort_order" value="<?= (int) ($editAddressRow['sort_order'] ?? 0) ?>">
                            <span class="rg-ssms-muted"><?= $editAddressRow ? 'Edit row:' : 'New row:' ?></span>
                            <input class="rg-ssms-input rg-ssms-input--wide" name="name_ar" required maxlength="180"
                                   value="<?= esc((string) ($editAddressRow['name_ar'] ?? '')) ?>"
                                   placeholder="اسم العنوان (الرابية…)"
                                   <?= $editAddressRow ? 'autofocus' : '' ?>>
                            <button class="rg-tb rg-tb--primary" type="submit"><?= $editAddressRow ? 'Update' : 'Insert' ?></button>
                            <?php if ($editAddressRow): ?>
                                <a class="rg-tb" href="<?= esc($listUrl . '&region_id=' . $selectedRegionId) ?>">Cancel</a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- Data grid -->
                    <div class="rg-ssms-grid-wrap">
                        <table class="rg-ssms-grid">
                            <thead>
                            <tr>
                                <th class="col-sel"></th>
                                <th class="col-id">ID</th>
                                <th class="col-name">Address Name</th>
                                <th class="col-num">Customers</th>
                                <th class="col-status">Status</th>
                                <th class="col-act">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$addresses): ?>
                                <tr class="rg-ssms-empty-row">
                                    <td colspan="6">No rows — (0 row(s) affected)</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($addresses as $i => $a):
                                $aid = (int) $a['id'];
                                $on = (int) $a['is_active'];
                                $editing = $editAddressId === $aid;
                                ?>
                                <tr class="<?= $on ? '' : 'is-off' ?><?= $editing ? ' is-edit' : '' ?>">
                                    <td class="col-sel"><?= $i + 1 ?></td>
                                    <td class="col-id" dir="ltr"><?= $aid ?></td>
                                    <td class="col-name"><?= esc((string) $a['name_ar']) ?></td>
                                    <td class="col-num" dir="ltr"><?= (int) ($a['customer_count'] ?? 0) ?></td>
                                    <td class="col-status">
                                        <span class="rg-status <?= $on ? 'on' : 'off' ?>"><?= $on ? 'Active' : 'Off' ?></span>
                                    </td>
                                    <td class="col-act">
                                        <a href="<?= esc($listUrl . '&region_id=' . $selectedRegionId . '&edit_address=' . $aid) ?>">Edit</a>
                                        <form method="post" action="<?= esc($listUrl) ?>" data-confirm="Toggle status?">
                                            <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                                            <input type="hidden" name="_action" value="toggle_address">
                                            <input type="hidden" name="id" value="<?= $aid ?>">
                                            <input type="hidden" name="region_id" value="<?= $selectedRegionId ?>">
                                            <button type="submit"><?= $on ? 'Disable' : 'Enable' ?></button>
                                        </form>
                                        <?php if ((int) ($a['customer_count'] ?? 0) < 1): ?>
                                            <form method="post" action="<?= esc($listUrl) ?>" data-confirm="Delete address?">
                                                <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                                                <input type="hidden" name="_action" value="delete_address">
                                                <input type="hidden" name="id" value="<?= $aid ?>">
                                                <input type="hidden" name="region_id" value="<?= $selectedRegionId ?>">
                                                <button type="submit" class="danger">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="rg-ssms-status-bar">
                        <span><?= count($addresses) ?> row(s)</span>
                        <span dir="ltr">region_id = <?= $selectedRegionId ?></span>
                        <span>Query executed successfully</span>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
</div>
<script>
(function () {
  var btn = document.getElementById('rg-import-toggle');
  var box = document.getElementById('rg-import-box');
  if (btn && box) {
    btn.addEventListener('click', function () {
      box.hidden = !box.hidden;
    });
  }
})();
</script>
