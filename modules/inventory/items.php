<?php
declare(strict_types=1);

$listUrl = app_url('index.php?r=items');
$pdo = db();
require_once app_path('includes/inv_item_barcode.php');
require_once app_path('includes/inv_item_schema.php');
require_once app_path('includes/inv_stock.php');
require_once app_path('includes/inv_item_units.php');
require_once app_path('includes/oracle_sync_service.php');

$extendedSchemaOk = inv_item_ensure_extended_schema($pdo);
$barcodeSchemaOk = inv_item_ensure_barcode_schema($pdo);
$expirySchemaOk = inv_item_ensure_expiry_schema($pdo);
$itemUnitsOk = inv_item_units_ensure_schema($pdo);
inv_item_ensure_card_columns($pdo);
oracle_item_schema_ensure($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }

    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'oracle_sync') {
            $syncResult = oracle_run_continuous_sync($pdo, ['item_groups', 'items']);
            $g = is_array($syncResult['item_groups'] ?? null) ? $syncResult['item_groups'] : [];
            $i = is_array($syncResult['items'] ?? null) ? $syncResult['items'] : [];
            $sum = 'مزامنة Oracle — مجموعات: +' . (int) ($g['inserted'] ?? 0)
                . ' ~' . (int) ($g['updated'] ?? 0)
                . ' | مواد: +' . (int) ($i['inserted'] ?? 0)
                . ' ~' . (int) ($i['updated'] ?? 0)
                . ' | ' . (int) ($syncResult['elapsed_ms'] ?? 0) . 'ms';
            if (!empty($syncResult['errors'])) {
                flash_set('error', $sum . ' — ' . implode(' | ', array_slice($syncResult['errors'], 0, 5)));
            } else {
                flash_set('success', $sum);
            }
            redirect($listUrl);
        }
        if ($act === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $sku = trim((string) ($_POST['sku'] ?? ''));
            $barcodeInput = trim((string) ($_POST['barcode'] ?? ''));
            $name = trim((string) ($_POST['name_ar'] ?? ''));
            $nameEn = trim((string) ($_POST['name_en'] ?? ''));
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            $unitId = (int) ($_POST['unit_id'] ?? 0);
            $warehouseId = (int) ($_POST['default_warehouse_id'] ?? 0);
            $taxRateId = (int) ($_POST['tax_rate_id'] ?? 0);
            $unit = $extendedSchemaOk && $unitId > 0
                ? inv_item_unit_name_by_id($pdo, $unitId)
                : (trim((string) ($_POST['unit_name'] ?? '')) ?: 'قطعة');
            $cost = (string) ($_POST['default_cost'] ?? '0');
            $sale = (string) ($_POST['default_sale'] ?? '0');
            $wholesale = (string) ($_POST['default_wholesale'] ?? '0');
            $track = 1;
            $isEdit = $id > 0;
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $openingQty = max(0, (float) str_replace(',', '.', (string) ($_POST['opening_qty'] ?? '0')));
            $pricesLocked = $isEdit && inv_item_has_any_movements($pdo, $id);
            $hasNameEn = inv_item_column_exists($pdo, 'name_en');
            $hasWholesale = inv_item_column_exists($pdo, 'default_wholesale');
            $hasTax = inv_item_column_exists($pdo, 'tax_rate_id');
            require_once app_path('includes/company_settings.php');

            if ($name === '') {
                throw new RuntimeException('اسم المادة بالعربي مطلوب.');
            }

            if ($extendedSchemaOk && $unitId < 1) {
                throw new RuntimeException('اختر وحدة القياس.');
            }

            $autoSku = $id < 1 && $sku === '';
            if ($autoSku) {
                $sku = inv_item_pending_sku();
            }

            if ($isEdit) {
                $stPrices = $pdo->prepare(
                    'SELECT default_cost, default_sale'
                    . ($hasWholesale ? ', default_wholesale' : '')
                    . ', is_active FROM inv_item WHERE id = ? LIMIT 1'
                );
                $stPrices->execute([$id]);
                $priceRow = $stPrices->fetch(PDO::FETCH_ASSOC);
                if (!$priceRow) {
                    throw new RuntimeException('المادة غير موجودة.');
                }
                if ($pricesLocked) {
                    $costF = (float) ($priceRow['default_cost'] ?? 0);
                    $saleF = (float) ($priceRow['default_sale'] ?? 0);
                    $wholesaleF = $hasWholesale ? (float) ($priceRow['default_wholesale'] ?? 0) : 0.0;
                } else {
                    $costF = company_round_unit_price((float) str_replace(',', '.', $cost), $pdo);
                    $saleF = company_round_unit_price((float) str_replace(',', '.', $sale), $pdo);
                    $wholesaleF = company_round_unit_price((float) str_replace(',', '.', $wholesale), $pdo);
                    if ($costF < 0 || $saleF < 0 || $wholesaleF < 0) {
                        throw new RuntimeException('الأسعار يجب أن تكون أكبر أو تساوي صفرًا.');
                    }
                }
            } else {
                $costF = company_round_unit_price((float) str_replace(',', '.', $cost), $pdo);
                $saleF = company_round_unit_price((float) str_replace(',', '.', $sale), $pdo);
                $wholesaleF = company_round_unit_price((float) str_replace(',', '.', $wholesale), $pdo);
                if ($costF < 0 || $saleF < 0 || $wholesaleF < 0) {
                    throw new RuntimeException('الأسعار يجب أن تكون أكبر أو تساوي صفرًا.');
                }
            }

            if (!$autoSku && inv_item_sku_exists($pdo, $sku, $id)) {
                throw new RuntimeException('رقم المادة مستخدم مسبقًا.');
            }

            if ($barcodeSchemaOk) {
                try {
                    $barcode = inv_item_resolve_barcode($pdo, $barcodeInput, $id);
                } catch (RuntimeException $e) {
                    throw $e;
                }
            } else {
                $barcode = null;
            }

            $catVal = $extendedSchemaOk && $categoryId > 0 ? $categoryId : null;
            $whVal = $extendedSchemaOk && $warehouseId > 0 ? $warehouseId : null;
            $taxVal = $hasTax && $taxRateId > 0 ? $taxRateId : null;

            $normalizedIssue = null;
            if ($itemUnitsOk && $extendedSchemaOk && $unitId > 0 && !$pricesLocked) {
                $issueUnitIds = $_POST['issue_unit_id'] ?? ($_POST['pack_unit_id'] ?? []);
                $issueFactors = $_POST['issue_factor'] ?? ($_POST['pack_factor'] ?? []);
                if (!is_array($issueUnitIds)) {
                    $issueUnitIds = [$issueUnitIds];
                    $issueFactors = is_array($issueFactors) ? $issueFactors : [$issueFactors];
                }
                $postIssueUid = 0;
                $postIssueFactor = 0.0;
                foreach ($issueUnitIds as $ix => $rawUid) {
                    $uid = (int) $rawUid;
                    if ($uid < 1) {
                        continue;
                    }
                    $postIssueUid = $uid;
                    $postIssueFactor = (float) str_replace(',', '.', (string) ($issueFactors[$ix] ?? '0'));
                    break;
                }
                $norm = inv_item_units_normalize_selection($pdo, $unitId, $postIssueUid, $postIssueFactor);
                $unitId = (int) $norm['base_unit_id'];
                $normalizedIssue = $norm['issue'];
                $unit = inv_item_unit_name_by_id($pdo, $unitId) ?: $unit;
            }
            $unitVal = $extendedSchemaOk && $unitId > 0 ? $unitId : null;

            $expiryDate = null;
            $notifyExpiry = 0;
            if ($expirySchemaOk) {
                [$expiryDate, $notifyExpiry] = inv_item_parse_expiry_input($_POST);
            }

            if ($id > 0) {
                // dynamic update
                $sets = ['sku=?', 'name_ar=?', 'unit_name=?', 'default_cost=?', 'default_sale=?', 'track_inventory=?', 'is_active=?'];
                $params = [$sku, $name, $unit, $costF, $saleF, $track, (int) $isActive];
                if ($barcodeSchemaOk) {
                    $sets[] = 'barcode=?';
                    $params[] = $barcode;
                }
                if ($hasNameEn) {
                    $sets[] = 'name_en=?';
                    $params[] = $nameEn !== '' ? $nameEn : null;
                }
                if ($extendedSchemaOk) {
                    $sets[] = 'category_id=?';
                    $params[] = $catVal;
                    if (!$pricesLocked) {
                        $sets[] = 'unit_id=?';
                        $params[] = $unitVal;
                    }
                    $sets[] = 'default_warehouse_id=?';
                    $params[] = $whVal;
                }
                if ($hasWholesale) {
                    $sets[] = 'default_wholesale=?';
                    $params[] = $wholesaleF;
                }
                if ($hasTax) {
                    $sets[] = 'tax_rate_id=?';
                    $params[] = $taxVal;
                }
                $params[] = $id;
                $pdo->prepare('UPDATE inv_item SET ' . implode(', ', $sets) . ' WHERE id=?')->execute($params);
                flash_set(
                    'success',
                    $pricesLocked
                        ? 'تم تحديث المادة (الأسعار مقفلة بعد الحركات).'
                        : 'تم تحديث المادة.'
                );
            } else {
                $cols = ['sku', 'name_ar', 'unit_name', 'default_cost', 'default_sale', 'track_inventory', 'is_active'];
                $vals = [$sku, $name, $unit, $costF, $saleF, $track, 1];
                if ($barcodeSchemaOk) {
                    $cols[] = 'barcode';
                    $vals[] = $barcode;
                }
                if ($hasNameEn) {
                    $cols[] = 'name_en';
                    $vals[] = $nameEn !== '' ? $nameEn : null;
                }
                if ($extendedSchemaOk) {
                    $cols[] = 'category_id';
                    $vals[] = $catVal;
                    $cols[] = 'unit_id';
                    $vals[] = $unitVal;
                    $cols[] = 'default_warehouse_id';
                    $vals[] = $whVal;
                }
                if ($hasWholesale) {
                    $cols[] = 'default_wholesale';
                    $vals[] = $wholesaleF;
                }
                if ($hasTax) {
                    $cols[] = 'tax_rate_id';
                    $vals[] = $taxVal;
                }
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $pdo->prepare('INSERT INTO inv_item (' . implode(',', $cols) . ') VALUES (' . $ph . ')')->execute($vals);
                $savedId = (int) $pdo->lastInsertId();
                if ($autoSku && $savedId > 0) {
                    $finalSku = inv_item_allocate_sku($pdo, $savedId);
                    $pdo->prepare('UPDATE inv_item SET sku = ? WHERE id = ?')->execute([$finalSku, $savedId]);
                }
                $msg = 'تم إضافة المادة.';
                if (
                    $openingQty > 0
                    && $savedId > 0
                    && $whVal !== null
                    && (int) $whVal > 0
                    && inv_stock_move_has_table($pdo)
                ) {
                    $rcpt = inv_stock_receipt(
                        $pdo,
                        date('Y-m-d'),
                        (int) $whVal,
                        $savedId,
                        $openingQty,
                        'item_opening',
                        $savedId,
                        'رصيد افتتاحي — ' . $name
                    );
                    if ($rcpt['ok']) {
                        $msg .= ' وتم تسجيل رصيد افتتاحي: ' . $openingQty . '.';
                    }
                }
                flash_set('success', $msg);
            }

            if ($expirySchemaOk) {
                $savedId = $id > 0 ? $id : (int) ($savedId ?? $pdo->lastInsertId());
                if ($savedId > 0) {
                    $stExp = $pdo->prepare('UPDATE inv_item SET expiry_date = ?, notify_on_expiry = ? WHERE id = ?');
                    $stExp->execute([$expiryDate, $notifyExpiry, $savedId]);
                }
            }

            $savedId = $id > 0 ? $id : (int) ($savedId ?? 0);
            if ($savedId < 1) {
                $savedId = (int) $pdo->lastInsertId();
            }
            if ($itemUnitsOk && $extendedSchemaOk && $savedId > 0 && $unitId > 0 && !$pricesLocked) {
                $extraUnits = $normalizedIssue ? [$normalizedIssue] : [];
                inv_item_units_save($pdo, $savedId, $unitId, $extraUnits);
            }
        } elseif ($act === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $st = $pdo->prepare('SELECT is_active, name_ar FROM inv_item WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) {
                throw new RuntimeException('المادة غير موجودة.');
            }
            $st = $pdo->prepare('UPDATE inv_item SET is_active = 1 - is_active WHERE id = ?');
            $st->execute([$id]);
            $nowActive = (int) $cur['is_active'] === 0;
            flash_set(
                'success',
                $nowActive
                    ? 'تم تفعيل المادة. ستظهر في القوائم المنسدلة.'
                    : 'تم تعطيل المادة. لن تظهر في القوائم المنسدلة (فواتير البيع/الشراء).'
            );
        } elseif ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $chk = inv_item_delete_check($pdo, $id);
            if (!$chk['can_delete']) {
                throw new RuntimeException($chk['message']);
            }
            $st = $pdo->prepare('DELETE FROM inv_item WHERE id = ?');
            $st->execute([$id]);
            flash_set('success', 'تم حذف المادة من النظام.');
        } else {
            throw new RuntimeException('إجراء غير معروف.');
        }
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        $msg = 'تعذر تنفيذ العملية.';
        if (strpos($e->getMessage(), 'Unknown column') !== false && strpos($e->getMessage(), 'barcode') !== false) {
            $msg = 'نفّذ ملف الترحيل: database/migrations/003_inv_item_barcode.sql';
        }
        flash_set('error', $msg);
    }

    redirect($listUrl);
}

$flash = flash_get();
$action = (string) ($_GET['action'] ?? 'list');

if ($action === 'add' || $action === 'edit') {
    $row = [
        'id' => 0,
        'sku' => '',
        'barcode' => '',
        'name_ar' => '',
        'name_en' => '',
        'category_id' => 0,
        'unit_id' => 0,
        'default_warehouse_id' => 0,
        'tax_rate_id' => 0,
        'unit_name' => 'قطعة',
        'default_cost' => 0,
        'default_sale' => 0,
        'default_wholesale' => 0,
        'track_inventory' => 1,
        'expiry_date' => '',
        'notify_on_expiry' => 0,
        'is_active' => 1,
    ];
    if ($action === 'edit') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            flash_set('error', 'مادة غير موجودة.');
            redirect($listUrl);
        }
        $st = $pdo->prepare('SELECT * FROM inv_item WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $dbRow = $st->fetch();
        if (!$dbRow) {
            flash_set('error', 'مادة غير موجودة.');
            redirect($listUrl);
        }
        $row = array_merge($row, $dbRow);
    }

    if ($barcodeSchemaOk && $action === 'add' && trim((string) ($row['barcode'] ?? '')) === '') {
        try {
            $row['barcode'] = inv_item_generate_barcode($pdo);
        } catch (Throwable $e) {
            $row['barcode'] = '';
        }
    }

    $categories = $extendedSchemaOk ? inv_item_load_categories($pdo) : [];
    $units = $extendedSchemaOk ? inv_item_load_units($pdo) : [];
    $warehouses = inv_item_load_warehouses($pdo);
    $taxRates = inv_item_load_tax_rates($pdo);
    $pricesLocked = $action === 'edit' && (int) ($row['id'] ?? 0) > 0 && inv_item_has_any_movements($pdo, (int) $row['id']);
    $hasNameEn = inv_item_column_exists($pdo, 'name_en');
    $hasWholesale = inv_item_column_exists($pdo, 'default_wholesale');
    $hasTax = inv_item_column_exists($pdo, 'tax_rate_id');

    if ($extendedSchemaOk && $action === 'add' && (int) ($row['unit_id'] ?? 0) < 1 && $units !== []) {
        $row['unit_id'] = (int) $units[0]['id'];
    }

    $formTitle = $action === 'add' ? 'تعريف مادة' : 'تعديل مادة';
    $nextListSeq = $action === 'add' ? inv_item_count_list($pdo) + 1 : 0;
    $itemStockTotal = 0.0;
    $itemStockWh = 0.0;
    $itemWhId = (int) ($row['default_warehouse_id'] ?? 0);
    if ($action === 'edit' && (int) ($row['id'] ?? 0) > 0) {
        $editItemId = (int) $row['id'];
        $itemStockTotal = inv_item_stock_qty_total($pdo, $editItemId);
        if ($itemWhId > 0) {
            $itemStockWh = inv_stock_qty_on_hand($pdo, $itemWhId, $editItemId);
        }
    }
    require_once app_path('includes/nav_helpers.php');
    $ledgerBack = nav_item_stock_ledger_back_link();
    $itemsDefCssPath = app_path('assets/css/items-def.css');
    $itemsDefCssUrl = app_url('assets/css/items-def.css') . (is_file($itemsDefCssPath) ? '?v=' . (string) filemtime($itemsDefCssPath) : '');
    ?>
    <link rel="stylesheet" href="<?= esc($itemsDefCssUrl) ?>">
    <div class="items-def-screen">
    <div class="toolbar">
        <h2 style="margin:0;font-size:1.05rem;"><?= esc($formTitle) ?></h2>
        <button class="btn btn-primary btn-sm" type="submit" form="item-def-form">حفظ</button>
        <?php if ($ledgerBack !== null): ?>
            <a class="btn btn-secondary btn-sm" href="<?= esc($ledgerBack['url']) ?>">← <?= esc($ledgerBack['label']) ?></a>
        <?php else: ?>
            <a class="btn btn-secondary btn-sm" href="<?= esc($listUrl) ?>">رجوع للقائمة</a>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (!$extendedSchemaOk): ?>
        <div class="alert alert-error">لتفعيل الفئة والوحدة والمستودع: نفّذ <code>database/migrations/004_item_category_unit_warehouse.sql</code> ثم حدّث الصفحة.</div>
    <?php endif; ?>

    <div class="card">
        <form method="post" action="<?= esc($listUrl) ?>" id="item-def-form" class="form-grid item-def-form master-page-form" style="max-width:820px;" data-app-busy-msg="جاري حفظ المادة...">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">

            <label class="field">
                <span class="field-label">رقم المادة</span>
                <input class="input" name="sku" value="<?= esc((string) $row['sku']) ?>" dir="ltr" maxlength="64"
                       placeholder="<?= $action === 'add' ? 'تلقائي إن تُرك فارغاً' : '' ?>"
                       title="رقم داخلي — لا يظهر في الفواتير">
                <span class="muted" style="font-size:0.78rem;">داخلي فقط · المعرّف الظاهر هو الباركود</span>
            </label>

            <?php if ($barcodeSchemaOk): ?>
            <label class="field">
                <span class="field-label">باركود المادة *</span>
                <input class="input" name="barcode" value="<?= esc((string) ($row['barcode'] ?? '')) ?>" maxlength="14" inputmode="numeric" pattern="[0-9]*" autocomplete="off" title="يظهر في الفواتير والتقارير" required>
                <span class="muted" style="font-size:0.78rem;">أرقام فقط (حتى 14 رقمًا) — فارغ عند الحفظ = تلقائي 6 أرقام · يظهر في الفواتير والتقارير</span>
            </label>
            <?php endif; ?>

            <label class="field">
                <span class="field-label">اسم المادة بالعربي *</span>
                <input class="input" name="name_ar" required value="<?= esc((string) $row['name_ar']) ?>">
                <?php if ($action === 'add' && $nextListSeq > 0): ?>
                    <span class="muted" style="font-size:0.78rem;">التسلسل في القائمة: <?= (int) $nextListSeq ?></span>
                <?php endif; ?>
            </label>

            <?php if ($hasNameEn): ?>
            <label class="field">
                <span class="field-label">اسم المادة بالإنجليزي</span>
                <input class="input" name="name_en" value="<?= esc((string) ($row['name_en'] ?? '')) ?>" dir="ltr">
            </label>
            <?php endif; ?>

            <?php if ($extendedSchemaOk): ?>
            <label class="field">
                <span class="field-label">فئة المادة</span>
                <select class="input" name="category_id">
                    <option value="">— بدون فئة —</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>" <?= (int) ($row['category_id'] ?? 0) === (int) $cat['id'] ? 'selected' : '' ?>>
                            <?= esc((string) $cat['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="muted" style="font-size:0.78rem;"><a href="<?= esc(app_url('index.php?r=item_categories')) ?>">إدارة الفئات</a></span>
            </label>

            <label class="field">
                <span class="field-label">الوحدة الأساسية *</span>
                <select class="input" name="unit_id" id="item-base-unit-id" required <?= $pricesLocked ? 'disabled' : '' ?>>
                    <option value="">— اختر الوحدة —</option>
                    <?php foreach ($units as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (int) ($row['unit_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>>
                            <?= esc((string) $u['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($pricesLocked): ?>
                    <input type="hidden" name="unit_id" value="<?= (int) ($row['unit_id'] ?? 0) ?>">
                <?php endif; ?>
                <span class="muted" style="font-size:0.78rem;">مثل قطعة — العدد في الحقل المجاور يكون 1</span>
            </label>
            <?php
            $itemIssueUnit = null;
            $editItemId = (int) ($row['id'] ?? 0);
            if ($itemUnitsOk && $editItemId > 0) {
                $itemIssueUnit = inv_item_units_single_issue($pdo, $editItemId);
            }
            $issueFactorDisp = $itemIssueUnit
                ? (rtrim(rtrim(number_format((float) $itemIssueUnit['factor'], 6, '.', ''), '0'), '.') ?: '1')
                : '';
            ?>
            <?php if ($itemUnitsOk): ?>
            <div class="card" style="padding:0.75rem 1rem;margin:0.5rem 0 1rem;background:#f8fafc;" id="item-issue-units-box">
                <strong style="display:block;margin-bottom:0.5rem;">وحدات الصرف والتعبئة</strong>
                <p class="muted" style="font-size:0.8rem;margin:0 0 0.75rem;line-height:1.55;">
                    الوحدة الأساسية (قطعة) عددها 1. أضف وحدة أخرى دون تكرار (مثال: <b>كرتون</b> والعدد 24).
                    تُلتزم في فواتير المبيعات/المشتريات وطلبات الشراء.
                    <?php if ($pricesLocked): ?><b>مقفلة بعد حركات المادة.</b><?php endif; ?>
                </p>
                <div class="form-row" style="align-items:flex-end;gap:0.75rem;flex-wrap:wrap;margin:0;">
                    <label class="field" style="flex:1.2;min-width:180px;margin:0;">
                        <span class="field-label">وحدة إضافية (كرتونة…)</span>
                        <select class="input js-issue-unit-sel" name="issue_unit_id[]" id="item-issue-unit-id" <?= $pricesLocked ? 'disabled' : '' ?>>
                            <option value="">— لا يوجد —</option>
                            <?php foreach ($units as $u): ?>
                                <option value="<?= (int) $u['id'] ?>" <?= $itemIssueUnit && (int) $itemIssueUnit['unit_id'] === (int) $u['id'] ? 'selected' : '' ?>><?= esc((string) $u['name_ar']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field" style="flex:1;min-width:160px;margin:0;">
                        <span class="field-label">العدد في الوحدة</span>
                        <input class="input" type="number" name="issue_factor[]" id="item-issue-factor" min="0" step="1" value="<?= esc($issueFactorDisp) ?>" placeholder="مثال: 24" dir="ltr" <?= $pricesLocked ? 'readonly' : '' ?>>
                    </label>
                    <?php if (!$pricesLocked): ?>
                    <div class="field" style="flex:0 0 auto;margin:0;">
                        <span class="field-label" style="visibility:hidden;">حذف</span>
                        <button type="button" class="btn btn-secondary btn-sm" id="js-clear-issue-unit">حذف / بدون صرف</button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!$pricesLocked): ?>
            <script>
            (function () {
              var issueSel = document.getElementById('item-issue-unit-id');
              var factorInp = document.getElementById('item-issue-factor');
              var clearBtn = document.getElementById('js-clear-issue-unit');
              if (clearBtn) clearBtn.addEventListener('click', function () {
                if (issueSel) issueSel.value = '';
                if (factorInp) factorInp.value = '';
              });
            })();
            </script>
            <?php endif; ?>
            <?php endif; ?>

            <?php else: ?>
            <label class="field">
                <span class="field-label">الوحدة</span>
                <input class="input" name="unit_name" value="<?= esc((string) ($row['unit_name'] ?? 'قطعة')) ?>">
            </label>
            <?php endif; ?>

            <?php
            $isItemEdit = (int) ($row['id'] ?? 0) > 0;
            $priceAdjustUrl = app_url('index.php?r=item_sale_price_adjust&item_id=' . (int) ($row['id'] ?? 0));
            $priceRo = $pricesLocked ? 'readonly' : '';
            require_once app_path('includes/company_settings.php');
            $unitPriceDp = company_invoice_unit_price_decimal_places($pdo);
            $unitPriceStep = company_invoice_unit_price_decimal_step($pdo);
            $amountDp = company_decimal_places($pdo);
            ?>
            <?php if ($pricesLocked): ?>
                <div class="alert" style="background:#fef3c7;border:1px solid #f59e0b;color:#92400e;padding:.65rem .9rem;border-radius:8px;margin-bottom:.75rem;font-size:.88rem;">
                    الأسعار مقفلة بعد حركات على المادة. عدّل سعر البيع من
                    <a href="<?= esc($priceAdjustUrl) ?>">شاشة تعديل أسعار البيع</a>
                    (الكلفة والجملة عبر الشاشات الخاصة لاحقاً).
                </div>
            <?php endif; ?>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">سعر الكلفة</span>
                    <input class="input" name="default_cost" type="number" step="<?= esc($unitPriceStep) ?>" min="0"
                           value="<?= esc(format_amount((float) $row['default_cost'], $unitPriceDp, false)) ?>" dir="ltr" <?= $priceRo ?>>
                </label>
                <label class="field">
                    <span class="field-label">سعر البيع</span>
                    <input class="input" name="default_sale" type="number" step="<?= esc($unitPriceStep) ?>" min="0"
                           value="<?= esc(format_amount((float) $row['default_sale'], $unitPriceDp, false)) ?>" dir="ltr" <?= $priceRo ?>>
                </label>
                <?php if ($hasWholesale): ?>
                <label class="field">
                    <span class="field-label">سعر الجملة</span>
                    <input class="input" name="default_wholesale" type="number" step="<?= esc($unitPriceStep) ?>" min="0"
                           value="<?= esc(format_amount((float) ($row['default_wholesale'] ?? 0), $unitPriceDp, false)) ?>" dir="ltr" <?= $priceRo ?>>
                </label>
                <?php endif; ?>
            </div>
            <?php if ($isItemEdit): ?>
                <div class="form-row item-stock-qty-readonly">
                    <label class="field">
                        <span class="field-label">الكمية المتوفرة (كل المستودعات)</span>
                        <input class="input" type="text" readonly dir="ltr" value="<?= esc(format_amount($itemStockTotal)) ?>">
                    </label>
                    <?php if ($itemWhId > 0): ?>
                        <label class="field">
                            <span class="field-label">الكمية في المستودع الافتراضي</span>
                            <input class="input" type="text" readonly dir="ltr" value="<?= esc(format_amount($itemStockWh)) ?>">
                        </label>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($extendedSchemaOk): ?>
            <label class="field">
                <span class="field-label">المستودع</span>
                <select class="input" name="default_warehouse_id">
                    <option value="">— بدون مستودع —</option>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= (int) $wh['id'] ?>" <?= (int) ($row['default_warehouse_id'] ?? 0) === (int) $wh['id'] ? 'selected' : '' ?>>
                            <?= esc((string) $wh['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="muted" style="font-size:0.78rem;"><a href="<?= esc(app_url('index.php?r=warehouses')) ?>">إدارة المستودعات</a></span>
            </label>
            <?php if ($hasTax): ?>
            <label class="field">
                <span class="field-label">ضريبة المادة</span>
                <select class="input" name="tax_rate_id">
                    <option value="">— بدون ضريبة مخصصة —</option>
                    <?php foreach ($taxRates as $tr): ?>
                        <option value="<?= (int) $tr['id'] ?>" <?= (int) ($row['tax_rate_id'] ?? 0) === (int) $tr['id'] ? 'selected' : '' ?>>
                            <?= esc((string) $tr['name_ar']) ?> (<?= esc(format_amount((float) $tr['rate_percent'], min(3, $amountDp), false)) ?>%)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <?php if ((int) ($row['id'] ?? 0) < 1): ?>
            <label class="field">
                <span class="field-label">رصيد افتتاحي (اختياري)</span>
                <input class="input" name="opening_qty" type="number" step="any" min="0" value="" placeholder="0">
            </label>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($expirySchemaOk): ?>
            <div class="form-row item-expiry-row" style="align-items:flex-end;gap:1rem;">
                <label class="field" style="flex:1 1 220px;">
                    <span class="field-label">تاريخ انتهاء المادة</span>
                    <input class="input" type="date" name="expiry_date" value="<?= esc(inv_item_format_expiry_for_input(isset($row['expiry_date']) ? (string) $row['expiry_date'] : null)) ?>">
                </label>
                <label class="perm-item" style="flex:1 1 auto;margin:0;padding:0.65rem 0;">
                    <input type="checkbox" name="notify_on_expiry" value="1" <?= !empty($row['notify_on_expiry']) ? 'checked' : '' ?>>
                    <span>تفعيل الإشعار عند انتهاء المادة</span>
                </label>
            </div>
            <?php endif; ?>

            <label class="perm-item" style="margin:0.5rem 0 1rem;">
                <input type="checkbox" name="is_active" value="1" <?= !empty($row['is_active']) || (int) ($row['id'] ?? 0) < 1 ? 'checked' : '' ?>>
                <span>المادة نشطة (ألغِ التفعيل لإيقاف المادة)</span>
            </label>

            <div>
                <button class="btn btn-primary" type="submit" id="item-def-form-submit">حفظ</button>
            </div>
        </form>
    </div>
    </div>
    <script>
    (function () {
      var form = document.getElementById('item-def-form');
      if (!form) return;
      document.addEventListener('master-toolbar', function (e) {
        if (!e.detail || e.detail.action !== 'save') return;
        e.preventDefault();
        e.stopImmediatePropagation();
        if (typeof form.reportValidity === 'function' && !form.reportValidity()) return;
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          var btn = document.getElementById('item-def-form-submit');
          if (btn) btn.click();
          else form.submit();
        }
      });
    })();
    </script>
    <?php
    return;
}

require_once app_path('includes/list_pagination.php');
$search = trim((string) ($_GET['q'] ?? ''));
$listTotal = inv_item_count_list($pdo, $search);
$pager = list_pager_with_total(list_pager_from_request($pdo), $listTotal);
$listPagerUrl = list_pager_base_url('items', $search !== '' ? ['q' => $search] : []);
$rows = inv_item_fetch_list($pdo, $pager['limit'], $pager['offset'], $search);
$itemIdsOnPage = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows);
$stockQtyMap = inv_item_stock_qty_map($pdo, $itemIdsOnPage);
$hasStockTable = inv_stock_move_has_table($pdo);
$itemMoveCounts = inv_item_stock_move_counts($pdo);
$itemDocCounts = inv_item_doc_line_counts($pdo);
$showBarcodeCol = inv_item_has_barcode_column($pdo);
$issueUnitMap = $itemUnitsOk ? inv_item_units_issue_map($pdo, $itemIdsOnPage) : [];
$listColspan = 8;
if ($showBarcodeCol) {
    $listColspan++;
}
if ($extendedSchemaOk) {
    $listColspan += 2;
} else {
    $listColspan++;
}
if ($itemUnitsOk) {
    $listColspan += 2;
}
if ($expirySchemaOk) {
    $listColspan++;
}
?>
<?php if (!$barcodeSchemaOk): ?>
<div class="alert alert-error">تعذر إنشاء عمود Barcode تلقائيًا. نفّذ ملف <code>database/migrations/003_inv_item_barcode.sql</code> من phpMyAdmin ثم حدّث الصفحة.</div>
<?php endif; ?>
<div class="toolbar">
    <a class="btn btn-primary btn-sm" href="<?= esc(app_url('index.php?r=items&action=add')) ?>">+ إضافة مادة</a>
    <?php if (oracle_is_enabled()): ?>
        <form method="post" style="display:inline;" data-confirm="تحديث المجموعات والمواد من Oracle الآن؟">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="oracle_sync">
            <button type="submit" class="btn btn-secondary btn-sm">🔄 تحديث من Oracle</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= esc($flash['message']) ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:0.75rem;padding:0.75rem 1rem;">
    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="form-row" style="align-items:flex-end;gap:0.75rem;flex-wrap:wrap;margin:0;">
        <input type="hidden" name="r" value="items">
        <label class="field" style="flex:1;min-width:220px;margin:0;">
            <span class="field-label">بحث عن المادة</span>
            <input class="input" type="search" name="q" value="<?= esc($search) ?>"
                   placeholder="الاسم، Barcode، SKU، الفئة، الوحدة…" autocomplete="off" spellcheck="false">
        </label>
        <button type="submit" class="btn btn-secondary btn-sm">بحث</button>
        <?php if ($search !== ''): ?>
            <a class="btn btn-ghost btn-sm" href="<?= esc($listUrl) ?>">مسح</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>#</th>
                <?php if ($showBarcodeCol): ?><th>Barcode</th><?php endif; ?>
                <th>الاسم</th>
                <?php if ($extendedSchemaOk): ?><th>الفئة</th><?php endif; ?>
                <th title="الوحدة الأساسية للمخزون">القطعة / الأساسية</th>
                <?php if ($itemUnitsOk): ?>
                    <th title="وحدة الصرف الأكبر من الأساسية">وحدة الصرف</th>
                    <th title="عدد القطع داخل وحدة الصرف">التعبئة</th>
                <?php endif; ?>
                <th>تكلفة</th>
                <th>بيع</th>
                <th class="col-qty" title="مجموع الرصيد في كل المستودعات">كمية متوفرة</th>
                <?php if ($extendedSchemaOk): ?><th>المستودع</th><?php else: ?><th>SKU</th><?php endif; ?>
                <?php if ($expirySchemaOk): ?><th>انتهاء</th><?php endif; ?>
                <th>الحالة</th>
                <th>إجراءات</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="<?= $listColspan ?>" class="muted" style="text-align:center;">
                        <?= $search !== '' ? 'لا توجد مادة مطابقة لبحثك.' : 'لا توجد مواد بعد.' ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php
            $rowSeq = (int) ($pager['offset'] ?? 0);
            foreach ($rows as $it):
                $rowSeq++;
                $itemId = (int) $it['id'];
                $moveCount = (int) ($itemMoveCounts[$itemId] ?? 0);
                $docCount = (int) ($itemDocCounts[$itemId] ?? 0);
                $blockedDelete = $moveCount > 0 || $docCount > 0;
                $blockTitle = 'تعذر الحذف: ';
                if ($moveCount > 0) {
                    $blockTitle .= $moveCount . ' حركة مخزنية';
                }
                if ($docCount > 0) {
                    $blockTitle .= ($moveCount > 0 ? ' و' : '') . $docCount . ' سطر فواتير/مردودات';
                }
                $itemName = (string) $it['name_ar'];
                $deleteConfirm = 'حذف المادة «' . $itemName . '» نهائياً من النظام؟';
                ?>
                <tr>
                    <td><?= $rowSeq ?></td>
                    <?php if ($showBarcodeCol): ?><td><code><?= esc((string) ($it['barcode'] ?? '')) ?></code></td><?php endif; ?>
                    <td><?= esc((string) $it['name_ar']) ?></td>
                    <?php if ($extendedSchemaOk): ?>
                        <td><?= esc((string) ($it['category_name'] ?? '—')) ?></td>
                    <?php endif; ?>
                    <td><?= esc((string) ($it['unit_name'] ?: 'قطعة')) ?></td>
                    <?php if ($itemUnitsOk): ?>
                        <?php
                        $issueInfo = $issueUnitMap[$itemId] ?? null;
                        $issueName = $issueInfo ? trim((string) ($issueInfo['name'] ?? '')) : '';
                        $issueFactor = $issueInfo ? (float) ($issueInfo['factor'] ?? 0) : 0.0;
                        $issueFactorDisp = $issueFactor > 1
                            ? (rtrim(rtrim(number_format($issueFactor, 6, '.', ''), '0'), '.') ?: '')
                            : '';
                        ?>
                        <td><?php if ($issueName !== ''): ?><?= esc($issueName) ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                        <td dir="ltr"><?php if ($issueFactorDisp !== ''): ?><?= esc($issueFactorDisp) ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                    <?php endif; ?>
                    <td><?= esc(format_amount((float) $it['default_cost'], null, true)) ?></td>
                    <td><?= esc(format_amount((float) $it['default_sale'], company_invoice_unit_price_decimal_places($pdo), true)) ?></td>
                    <td class="col-qty" dir="ltr">
                        <?php if ($hasStockTable): ?>
                            <?= esc(format_amount((float) ($stockQtyMap[$itemId] ?? 0.0))) ?>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($extendedSchemaOk): ?>
                        <td><?= esc((string) ($it['warehouse_name'] ?? '—')) ?></td>
                    <?php else: ?>
                        <td><code><?= esc((string) $it['sku']) ?></code></td>
                    <?php endif; ?>
                    <?php if ($expirySchemaOk): ?>
                        <td>
                            <?php
                            $exp = isset($it['expiry_date']) && $it['expiry_date'] !== '' && $it['expiry_date'] !== null
                                ? substr((string) $it['expiry_date'], 0, 10)
                                : '';
                            if ($exp === '') {
                                echo '<span class="muted">—</span>';
                            } else {
                                $expired = $exp < date('Y-m-d');
                                echo esc($exp);
                                if (!empty($it['notify_on_expiry'])) {
                                    echo ' <span class="badge ' . ($expired ? 'badge-off' : 'badge-ok') . '" title="إشعار مفعّل">' . ($expired ? 'منتهية' : 'إشعار') . '</span>';
                                }
                            }
                            ?>
                        </td>
                    <?php endif; ?>
                    <td>
                        <?php if ((int) $it['is_active']): ?>
                            <span class="badge badge-ok">نشط</span>
                        <?php else: ?>
                            <span class="badge badge-off">موقوف</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=items&action=edit&id=' . $itemId)) ?>">تعديل</a>
                            <form method="post" action="<?= esc($listUrl) ?>" data-confirm="<?= (int) $it['is_active'] ? 'تعطيل المادة؟ لن تظهر في القوائم المنسدلة.' : 'تفعيل المادة؟ ستظهر في القوائم المنسدلة.' ?>">
                                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                <input type="hidden" name="_action" value="toggle">
                                <input type="hidden" name="id" value="<?= $itemId ?>">
                                <button class="btn btn-secondary btn-sm" type="submit"><?= (int) $it['is_active'] ? 'تعطيل' : 'تفعيل' ?></button>
                            </form>
                            <?php if ($blockedDelete): ?>
                                <button class="btn btn-secondary btn-sm" type="button" disabled title="<?= esc($blockTitle) ?>">حذف</button>
                            <?php else: ?>
                                <form method="post" action="<?= esc($listUrl) ?>" data-confirm="<?= esc($deleteConfirm) ?>">
                                    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="id" value="<?= $itemId ?>">
                                    <button class="btn btn-secondary btn-sm" type="submit">حذف</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php list_pager_render($pager, $listPagerUrl); ?>
</div>
<script>
(function () {
  var saveBtn = document.querySelector('#master-toolbar [data-master-action="save"]');
  if (saveBtn) {
    saveBtn.hidden = true;
    saveBtn.disabled = true;
    saveBtn.classList.add('is-inactive');
  }
})();
</script>
