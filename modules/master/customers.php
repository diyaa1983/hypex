<?php
declare(strict_types=1);

$listUrl = app_url('index.php?r=customers');

require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/sales_oracle12_ui.php');

$pdo = db();
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/crm_party_delete.php');
require_once app_path('includes/oracle_customer_sync.php');
require_once app_path('includes/oracle_sync_service.php');
require_once app_path('includes/crm_region.php');
crm_sales_rep_ensure_customer_invoice_links($pdo);
crm_customer_ensure_gps_columns($pdo);
try {
    $pdo->query('SELECT use_wholesale_price FROM crm_customer LIMIT 1');
} catch (Throwable $e) {
    try {
        $pdo->exec(
            'ALTER TABLE crm_customer ADD COLUMN use_wholesale_price TINYINT(1) NOT NULL DEFAULT 0 AFTER tax_number'
        );
    } catch (Throwable $e2) {
        // ignore
    }
}
oracle_customer_schema_ensure($pdo);
oracle_customer_account_schema_ensure($pdo);
crm_region_ensure_schema($pdo);
$salesReps = crm_sales_rep_load_active($pdo);
$regions = crm_region_load_active($pdo);
$regionAddressesMap = crm_region_addresses_map($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }

    $act = (string) ($_POST['_action'] ?? '');

    try {
        if ($act === 'oracle_sync') {
            $syncResult = oracle_run_continuous_sync($pdo, ['customers', 'accounts']);
            $c = is_array($syncResult['customers'] ?? null) ? $syncResult['customers'] : [];
            $a = is_array($syncResult['accounts'] ?? null) ? $syncResult['accounts'] : [];
            $sum = 'مزامنة Oracle — +' . (int) ($c['inserted'] ?? 0)
                . ' محدّث ' . (int) ($c['updated'] ?? 0)
                . ' تخطي ' . (int) ($c['skipped'] ?? 0)
                . (isset($c['skipped_no_gl']) ? ' (بلا اسم GL: ' . (int) $c['skipped_no_gl'] . ')' : '')
                . ' | CUSTOMER: ' . (int) ($c['oracle_rows_raw'] ?? $c['oracle_rows'] ?? 0)
                . ' | GLACTMF: ' . (int) ($c['gl_rows'] ?? 0)
                . ' | حذف خارج 112: ' . (int) ($c['deleted_non_prefix'] ?? 0)
                . (isset($c['kept_with_usage']) && (int) $c['kept_with_usage'] > 0
                    ? ' | تعطيل(حركات): ' . (int) $c['kept_with_usage']
                    : '')
                . ' | ' . (int) ($syncResult['elapsed_ms'] ?? 0) . 'ms';
            // أخطاء الاتصال تظهر حتى لو حُسبت أصفار
            $errs = $syncResult['errors'] ?? [];
            if (is_array($c['errors'] ?? null)) {
                $errs = array_merge($errs, $c['errors']);
            }
            if ($errs !== []) {
                flash_set('error', $sum . ' — ' . implode(' | ', array_slice(array_map('strval', $errs), 0, 5)));
            } elseif ((int) ($c['inserted'] ?? 0) + (int) ($c['updated'] ?? 0) < 1
                && (int) ($c['oracle_rows_raw'] ?? 0) < 1) {
                flash_set('error', $sum . ' — لم تُقرأ صفوف من Oracle. راجع host/pass في oracle.local.php واتصال Oracle.');
            } else {
                flash_set('success', $sum);
            }
            redirect($listUrl);
        }
        if ($act === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name_ar'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $tax = trim((string) ($_POST['tax_number'] ?? ''));
            $addr = trim((string) ($_POST['address_ar'] ?? ''));
            $gps = crm_customer_gps_parse_input($_POST);
            $useWholesale = !empty($_POST['use_wholesale_price']) ? 1 : 0;
            $repIdsRaw = $_POST['sales_rep_ids'] ?? [];
            if (!is_array($repIdsRaw)) {
                $repIdsRaw = [];
            }
            $regionId = (int) ($_POST['region_id'] ?? 0);
            $regionAddressId = (int) ($_POST['region_address_id'] ?? 0);
            if ($regionId > 0 && !crm_region_exists_active($pdo, $regionId)) {
                throw new RuntimeException('المنطقة المحددة غير موجودة أو غير نشطة.');
            }
            if ($regionAddressId > 0) {
                if (!crm_region_address_exists_active($pdo, $regionAddressId)) {
                    throw new RuntimeException('العنوان المحدد غير موجود أو غير نشط.');
                }
                $stCheck = $pdo->prepare('SELECT region_id FROM crm_region_address WHERE id = ? LIMIT 1');
                $stCheck->execute([$regionAddressId]);
                $ownerRegion = (int) $stCheck->fetchColumn();
                if ($regionId > 0 && $ownerRegion !== $regionId) {
                    throw new RuntimeException('العنوان لا يتبع المنطقة المختارة.');
                }
                if ($regionId < 1 && $ownerRegion > 0) {
                    $regionId = $ownerRegion;
                }
            }
            $regionIdDb = $regionId > 0 ? $regionId : null;
            $regionAddressIdDb = $regionAddressId > 0 ? $regionAddressId : null;

            // عملاء Oracle: لا يُعدَّل الرقم ولا الاسم من النظام
            $oracleLocked = false;
            if ($id > 0) {
                $stOra = $pdo->prepare(
                    "SELECT code, name_ar, oracle_key FROM crm_customer WHERE id = ? LIMIT 1"
                );
                $stOra->execute([$id]);
                $oraRow = $stOra->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($oraRow && trim((string) ($oraRow['oracle_key'] ?? '')) !== '') {
                    $oracleLocked = true;
                    $name = trim((string) ($oraRow['name_ar'] ?? ''));
                }
            }

            if ($name === '') {
                throw new RuntimeException('اسم العميل مطلوب.');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('البريد الإلكتروني غير صالح.');
            }

            if ($id > 0) {
                // الاسم: من Oracle يُثبَّت؛ الرقم لا يُحدَّث من النموذج أصلاً
                try {
                    $st = $pdo->prepare(
                        'UPDATE crm_customer SET name_ar=?, phone=?, email=?, tax_number=?, address_ar=?,
                            use_wholesale_price=?,
                            region_id=?, region_address_id=?, latitude=?, longitude=?, gps_accuracy=?, gps_at=? WHERE id=?'
                    );
                    $st->execute([
                        $name,
                        $phone !== '' ? $phone : null,
                        $email !== '' ? $email : null,
                        $tax !== '' ? $tax : null,
                        $addr !== '' ? $addr : null,
                        $useWholesale,
                        $regionIdDb,
                        $regionAddressIdDb,
                        $gps['latitude'],
                        $gps['longitude'],
                        $gps['gps_accuracy'],
                        $gps['clear'] ? null : date('Y-m-d H:i:s'),
                        $id,
                    ]);
                } catch (Throwable $eCol) {
                    $st = $pdo->prepare(
                        'UPDATE crm_customer SET name_ar=?, phone=?, email=?, tax_number=?, address_ar=?,
                            region_id=?, region_address_id=?, latitude=?, longitude=?, gps_accuracy=?, gps_at=? WHERE id=?'
                    );
                    $st->execute([
                        $name,
                        $phone !== '' ? $phone : null,
                        $email !== '' ? $email : null,
                        $tax !== '' ? $tax : null,
                        $addr !== '' ? $addr : null,
                        $regionIdDb,
                        $regionAddressIdDb,
                        $gps['latitude'],
                        $gps['longitude'],
                        $gps['gps_accuracy'],
                        $gps['clear'] ? null : date('Y-m-d H:i:s'),
                        $id,
                    ]);
                }
                crm_customer_save_sales_reps($pdo, $id, $repIdsRaw);
                flash_set(
                    'success',
                    $oracleLocked
                        ? 'تم تحديث بيانات العميل (الرقم والاسم من Oracle — غير قابلين للتعديل).'
                        : 'تم تحديث بيانات العميل.'
                );
            } else {
                $code = crm_customer_generate_code($pdo);
                try {
                    $st = $pdo->prepare(
                        'INSERT INTO crm_customer (code, name_ar, phone, email, tax_number, address_ar, use_wholesale_price, region_id, region_address_id, latitude, longitude, gps_accuracy, gps_at, sales_rep_id, is_active)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)'
                    );
                    $st->execute([
                        $code,
                        $name,
                        $phone !== '' ? $phone : null,
                        $email !== '' ? $email : null,
                        $tax !== '' ? $tax : null,
                        $addr !== '' ? $addr : null,
                        $useWholesale,
                        $regionIdDb,
                        $regionAddressIdDb,
                        $gps['latitude'],
                        $gps['longitude'],
                        $gps['gps_accuracy'],
                        $gps['clear'] ? null : date('Y-m-d H:i:s'),
                        null,
                    ]);
                } catch (Throwable $eCol) {
                    $st = $pdo->prepare(
                        'INSERT INTO crm_customer (code, name_ar, phone, email, tax_number, address_ar, region_id, region_address_id, latitude, longitude, gps_accuracy, gps_at, sales_rep_id, is_active)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)'
                    );
                    $st->execute([
                        $code,
                        $name,
                        $phone !== '' ? $phone : null,
                        $email !== '' ? $email : null,
                        $tax !== '' ? $tax : null,
                        $addr !== '' ? $addr : null,
                        $regionIdDb,
                        $regionAddressIdDb,
                        $gps['latitude'],
                        $gps['longitude'],
                        $gps['gps_accuracy'],
                        $gps['clear'] ? null : date('Y-m-d H:i:s'),
                        null,
                    ]);
                }
                $newId = (int) $pdo->lastInsertId();
                crm_customer_save_sales_reps($pdo, $newId, $repIdsRaw);
                flash_set('success', 'تم إضافة العميل.');
            }
        } elseif ($act === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $st = $pdo->prepare('UPDATE crm_customer SET is_active = 1 - is_active WHERE id = ?');
            $st->execute([$id]);
            flash_set('success', 'تم تحديث حالة العميل.');
        } elseif ($act === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $chk = crm_customer_delete_check($pdo, $id);
            if (!$chk['can_delete']) {
                throw new RuntimeException($chk['message']);
            }
            $st = $pdo->prepare('DELETE FROM crm_customer WHERE id = ?');
            $st->execute([$id]);
            flash_set('success', 'تم حذف العميل من النظام.');
        } else {
            throw new RuntimeException('إجراء غير معروف.');
        }
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        flash_set('error', 'تعذر تنفيذ العملية.');
    }

    redirect($listUrl);
}

$flash = flash_get();
$action = (string) ($_GET['action'] ?? 'list');

if ($action === 'add' || $action === 'edit') {
    $row = [
        'id' => 0,
        'code' => '',
        'name_ar' => '',
        'phone' => '',
        'email' => '',
        'tax_number' => '',
        'address_ar' => '',
        'latitude' => null,
        'longitude' => null,
        'gps_accuracy' => null,
        'gps_at' => null,
        'sales_rep_id' => '',
        'region_id' => '',
        'region_address_id' => '',
        'is_active' => 1,
    ];
    if ($action === 'edit') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            flash_set('error', 'عميل غير موجود.');
            redirect($listUrl);
        }
        $st = $pdo->prepare('SELECT * FROM crm_customer WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $dbRow = $st->fetch();
        if (!$dbRow) {
            flash_set('error', 'عميل غير موجود.');
            redirect($listUrl);
        }
        $row = array_merge($row, $dbRow);
    }
    $selectedRepIds = (int) ($row['id'] ?? 0) > 0
        ? crm_customer_sales_rep_ids_for_customer($pdo, (int) $row['id'])
        : [];

    $isOracleLinked = trim((string) ($row['oracle_key'] ?? '')) !== '';
    $formTitle = $action === 'add' ? 'إضافة عميل' : 'تعديل عميل';
    ?>
    <?php sales_ora12_enqueue_assets(); ?>

    <div class="dashboard-ora sales-ora12-screen customers-ora-screen customers-ora-form-page">
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
                    <span class="field-label">رمز العميل<?= $isOracleLinked ? ' (من Oracle)' : '' ?></span>
                    <input class="input" value="<?= esc((string) $row['code']) ?>"
                           placeholder="يُولَّد تلقائياً عند الحفظ"
                           readonly tabindex="-1" aria-readonly="true"
                           style="<?= $isOracleLinked ? 'background:#f1f5f9;cursor:not-allowed;' : '' ?>">
                </label>
                <label class="field">
                    <span class="field-label">اسم العميل *<?= $isOracleLinked ? ' (من Oracle)' : '' ?></span>
                    <?php if ($isOracleLinked): ?>
                        <input class="input" name="name_ar" value="<?= esc((string) $row['name_ar']) ?>"
                               readonly tabindex="-1" aria-readonly="true" required
                               style="background:#f1f5f9;cursor:not-allowed;"
                               title="الاسم من Oracle (GLACTMF) — يُحدَّث بالمزامنة فقط">
                    <?php else: ?>
                        <input class="input" name="name_ar" required value="<?= esc((string) $row['name_ar']) ?>">
                    <?php endif; ?>
                </label>
            </div>
            <?php if ($isOracleLinked): ?>
                <p class="muted" style="margin:0 0 0.75rem;font-size:0.88rem;">
                    هذا العميل مربوط بـ Oracle — الرمز والاسم للقراءة فقط ويُحدَّثان عبر «تحديث من Oracle».
                </p>
            <?php endif; ?>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">الهاتف</span>
                    <input class="input" name="phone" value="<?= esc((string) ($row['phone'] ?? '')) ?>">
                </label>
                <label class="field">
                    <span class="field-label">البريد</span>
                    <input class="input" name="email" type="email" value="<?= esc((string) ($row['email'] ?? '')) ?>">
                </label>
            </div>
            <div class="form-row">
                <label class="field">
                    <span class="field-label">الرقم الضريبي</span>
                    <input class="input" name="tax_number" value="<?= esc((string) ($row['tax_number'] ?? '')) ?>">
                </label>
                <label class="field">
                    <span class="field-label">المنطقة</span>
                    <select class="input" name="region_id" id="customer-region-id">
                        <option value="">— بدون منطقة —</option>
                        <?php
                        $curRegion = (int) ($row['region_id'] ?? 0);
                        foreach ($regions as $rg):
                            $rid = (int) $rg['id'];
                            ?>
                            <option value="<?= $rid ?>"<?= $curRegion === $rid ? ' selected' : '' ?>>
                                <?= esc((string) ($rg['name_ar'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$regions): ?>
                        <span class="muted" style="font-size:0.85rem;display:block;margin-top:0.25rem;">
                            لا توجد مناطق — أضف من
                            <a href="<?= esc(app_url('index.php?r=customer_regions')) ?>">مناطق العملاء</a>.
                        </span>
                    <?php endif; ?>
                </label>
                <label class="field">
                    <span class="field-label">العنوان (ضمن المنطقة)</span>
                    <select class="input" name="region_address_id" id="customer-region-address-id">
                        <option value="">— اختر المنطقة أولاً —</option>
                    </select>
                    <span id="customer-region-address-hint" class="muted" style="font-size:0.85rem;display:block;margin-top:0.25rem;"></span>
                </label>
            </div>
            <fieldset class="field customers-reps-field">
                <legend class="field-label">المندوب / مندوبو المبيعات</legend>
                <?php if (!$salesReps): ?>
                    <p class="muted">لا يوجد مندوبون نشطون —
                        <a href="<?= esc(app_url('index.php?r=sales_reps')) ?>">أضف مندوباً من شاشة المندوبين</a>.
                    </p>
                <?php else: ?>
                    <p class="muted" style="margin:0 0 0.45rem;font-size:0.85rem;">
                        اختر مندوباً — تُملأ <strong>المنطقة والعنوان</strong> تلقائياً عند توفرها على المندوب.
                    </p>
                    <div class="customers-reps-checkboxes" id="customer-reps-checkboxes">
                        <?php foreach ($salesReps as $rep): ?>
                            <label class="customers-rep-check">
                                <input type="checkbox" class="js-customer-rep" name="sales_rep_ids[]" value="<?= (int) $rep['id'] ?>"
                                    <?= in_array((int) $rep['id'], $selectedRepIds, true) ? ' checked' : '' ?>>
                                <?= esc((string) $rep['name_ar']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>
            <label class="field">
                <span class="field-label">العنوان</span>
                <textarea class="input" name="address_ar"><?= esc((string) ($row['address_ar'] ?? '')) ?></textarea>
            </label>
            <?php
            $latVal = $row['latitude'] !== null && $row['latitude'] !== '' ? (string) $row['latitude'] : '';
            $lngVal = $row['longitude'] !== null && $row['longitude'] !== '' ? (string) $row['longitude'] : '';
            $accVal = $row['gps_accuracy'] !== null && $row['gps_accuracy'] !== '' ? (string) $row['gps_accuracy'] : '';
            $hasGps = $latVal !== '' && $lngVal !== '';
            ?>
            <fieldset class="field customers-gps-field">
                <legend class="field-label">موقع العميل (GPS)</legend>
                <input type="hidden" name="latitude" id="cust-latitude" value="<?= esc($latVal) ?>">
                <input type="hidden" name="longitude" id="cust-longitude" value="<?= esc($lngVal) ?>">
                <input type="hidden" name="gps_accuracy" id="cust-gps-accuracy" value="<?= esc($accVal) ?>">
                <p id="cust-gps-status" class="muted" style="margin:0 0 0.5rem;">
                    <?= $hasGps
                        ? 'الموقع الحالي: ' . esc($latVal) . ' ، ' . esc($lngVal)
                        : 'لم يُحدَّد موقع بعد.' ?>
                </p>
                <div class="form-row" style="gap:0.5rem;flex-wrap:wrap;">
                    <button type="button" class="btn btn-secondary" id="cust-gps-pick-map">تحديد على الخريطة</button>
                    <button type="button" class="btn btn-secondary" id="cust-gps-my-loc">موقعي الآن (GPS)</button>
                    <button type="button" class="btn btn-secondary" id="cust-gps-clear" <?= $hasGps ? '' : 'disabled' ?>>مسح الموقع</button>
                </div>
            </fieldset>
            <label class="field customers-wholesale-field" style="display:flex;align-items:flex-start;gap:.5rem;margin:.75rem 0;">
                <input type="checkbox" name="use_wholesale_price" value="1" <?= !empty($row['use_wholesale_price']) ? ' checked' : '' ?> style="margin-top:.2rem">
                <span>
                    <strong>تسعير بسعر الجملة</strong>
                    <span class="muted" style="display:block;font-size:.85rem;margin-top:.15rem">
                        مفعّل: تُسحب أسعار المواد من «سعر الجملة» في بطاقة المادة عند فاتورة البيع وطلب العميل.
                        غير مفعّل: يُستخدم «سعر البيع».
                    </span>
                </span>
            </label>
            <div>
                <button class="btn btn-primary" type="submit">حفظ</button>
            </div>
        </form>
        </div>
        <script>
        (function () {
          var latEl = document.getElementById('cust-latitude');
          var lngEl = document.getElementById('cust-longitude');
          var accEl = document.getElementById('cust-gps-accuracy');
          var statusEl = document.getElementById('cust-gps-status');
          var clearBtn = document.getElementById('cust-gps-clear');
          var mapBtn = document.getElementById('cust-gps-pick-map');
          var gpsBtn = document.getElementById('cust-gps-my-loc');

          function fmt(n) {
            var x = parseFloat(n);
            if (!isFinite(x)) return '';
            return String(Math.round(x * 1e7) / 1e7);
          }
          function setGps(gps) {
            if (!gps || !isFinite(gps.latitude) || !isFinite(gps.longitude)) return;
            latEl.value = fmt(gps.latitude);
            lngEl.value = fmt(gps.longitude);
            accEl.value = gps.accuracy != null && isFinite(gps.accuracy) ? String(gps.accuracy) : '';
            statusEl.textContent = 'الموقع الحالي: ' + latEl.value + ' ، ' + lngEl.value;
            clearBtn.disabled = false;
          }
          function clearGps() {
            latEl.value = '';
            lngEl.value = '';
            accEl.value = '';
            statusEl.textContent = 'لم يُحدَّد موقع بعد.';
            clearBtn.disabled = true;
          }
          if (clearBtn) clearBtn.addEventListener('click', clearGps);

          if (mapBtn) {
            mapBtn.addEventListener('click', function () {
              if (!window.AppGeoMapPick || typeof AppGeoMapPick.pickLocationOnMap !== 'function') {
                alert('خريطة تحديد الموقع غير متاحة.');
                return;
              }
              var opts = { forPost: false, preferCurrentGps: true };
              if (latEl.value && lngEl.value) {
                opts.latitude = parseFloat(latEl.value);
                opts.longitude = parseFloat(lngEl.value);
              }
              AppGeoMapPick.pickLocationOnMap(opts).then(setGps).catch(function () {});
            });
          }

          if (gpsBtn) {
            gpsBtn.addEventListener('click', function () {
              if (!window.AppGeo || typeof AppGeo.withGpsForPost !== 'function') {
                // جرّب الخريطة مباشرة
                if (mapBtn) mapBtn.click();
                return;
              }
              gpsBtn.disabled = true;
              AppGeo.withGpsForPost('desktop', function (gps) {
                gpsBtn.disabled = false;
                if (gps === undefined) return;
                if (!gps) {
                  alert('لم يُحدَّد موقع. اسمح بالوصول للموقع أو اختر على الخريطة.');
                  return;
                }
                setGps(gps);
              });
            });
          }
        })();

        (function () {
          var regionSel = document.getElementById('customer-region-id');
          var addrSel = document.getElementById('customer-region-address-id');
          var addrHint = document.getElementById('customer-region-address-hint');
          var boxes = document.querySelectorAll('#customer-reps-checkboxes .js-customer-rep');
          var apiBase = <?= json_encode(app_url('api/sales_rep_regions.php')) ?>;
          var addrMap = <?= json_encode($regionAddressesMap, JSON_UNESCAPED_UNICODE) ?>;
          var initialAddrId = <?= (int) ($row['region_address_id'] ?? 0) ?>;

          if (!regionSel || !addrSel) return;

          function fillAddresses(regionId, preferId) {
            addrSel.innerHTML = '';
            var empty = document.createElement('option');
            empty.value = '';
            empty.textContent = regionId ? '— بدون عنوان —' : '— اختر المنطقة أولاً —';
            addrSel.appendChild(empty);
            var list = addrMap[regionId] || addrMap[String(regionId)] || [];
            list.forEach(function (a) {
              var o = document.createElement('option');
              o.value = String(a.id);
              o.textContent = a.name_ar || '';
              if (preferId && parseInt(a.id, 10) === preferId) o.selected = true;
              addrSel.appendChild(o);
            });
            if (addrHint) {
              addrHint.textContent = list.length
                ? ''
                : (regionId ? 'لا عناوين مربوطة بهذه المنطقة بعد.' : '');
            }
          }

          regionSel.addEventListener('change', function () {
            fillAddresses(parseInt(regionSel.value, 10) || 0, 0);
            var ta = document.querySelector('textarea[name="address_ar"]');
            var opt = addrSel.options[addrSel.selectedIndex];
            if (ta && opt && opt.value && !String(ta.value || '').trim()) {
              ta.value = opt.textContent || '';
            }
          });
          fillAddresses(parseInt(regionSel.value, 10) || 0, initialAddrId);

          addrSel.addEventListener('change', function () {
            var opt = addrSel.options[addrSel.selectedIndex];
            var ta = document.querySelector('textarea[name="address_ar"]');
            if (ta && opt && opt.value && !String(ta.value || '').trim()) {
              ta.value = opt.textContent || '';
            }
          });

          function applyCoverage(items) {
            if (!items || !items.length) return;
            var first = items[0];
            var rid = parseInt(first.region_id, 10) || 0;
            var aid = parseInt(first.address_id || first.id, 10) || 0;
            if (rid) {
              regionSel.value = String(rid);
              fillAddresses(rid, aid);
            }
            var ta = document.querySelector('textarea[name="address_ar"]');
            if (ta && !String(ta.value || '').trim() && first.address_ar) {
              ta.value = first.address_ar;
            }
          }

          function onRepChange() {
            var firstChecked = null;
            boxes.forEach(function (b) {
              if (!firstChecked && b.checked) firstChecked = b;
            });
            if (!firstChecked) return;
            var id = parseInt(firstChecked.value, 10) || 0;
            if (!id) return;
            fetch(apiBase + (apiBase.indexOf('?') >= 0 ? '&' : '?') + 'sales_rep_id=' + encodeURIComponent(String(id)), {
              credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).then(function (x) {
              if (x && x.ok) applyCoverage(x.regions || []);
            }).catch(function () {});
          }

          boxes.forEach(function (b) {
            b.addEventListener('change', function () {
              if (b.checked) onRepChange();
            });
          });
        })();
        </script>
        <?php sales_ora12_workspace_close(); ?>
    </div>
    <?php
    return;
}

$search = trim((string) ($_GET['q'] ?? ''));
$showInactive = ((string) ($_GET['all'] ?? '') === '1');
$regionFilterId = (int) ($_GET['region_id'] ?? 0);

$repNamesSub = '(SELECT GROUP_CONCAT(r2.name_ar ORDER BY csr2.sort_order, r2.name_ar SEPARATOR \'، \')
                FROM crm_customer_sales_rep csr2
                INNER JOIN crm_sales_rep r2 ON r2.id = csr2.sales_rep_id
                WHERE csr2.customer_id = c.id)';

$sql = "SELECT c.id, c.code, c.name_ar, c.phone, c.email, c.tax_number, c.is_active, c.created_at,
               c.latitude, c.longitude, c.oracle_key, c.region_id,
               rg.name_ar AS region_name,
               ra.name_ar AS region_address_name,
               COALESCE({$repNamesSub}, r.name_ar) AS sales_rep_name
        FROM crm_customer c
        LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id
        LEFT JOIN crm_region rg ON rg.id = c.region_id
        LEFT JOIN crm_region_address ra ON ra.id = c.region_address_id";
$params = [];
$whereParts = [];
if (!$showInactive) {
    $whereParts[] = 'c.is_active = 1';
}
if ($regionFilterId > 0) {
    $whereParts[] = 'c.region_id = ?';
    $params[] = $regionFilterId;
}
if ($search !== '') {
    $whereParts[] = '(c.name_ar LIKE ? OR c.code LIKE ? OR c.phone LIKE ? OR c.email LIKE ?'
        . ' OR c.tax_number LIKE ? OR IFNULL(r.name_ar, \'\') LIKE ?'
        . ' OR IFNULL(' . $repNamesSub . ', \'\') LIKE ?'
        . ' OR IFNULL(rg.name_ar, \'\') LIKE ?'
        . ' OR IFNULL(ra.name_ar, \'\') LIKE ?'
        . ' OR EXISTS (
              SELECT 1 FROM crm_customer_sales_rep csr3
              INNER JOIN crm_sales_rep r3 ON r3.id = csr3.sales_rep_id
              WHERE csr3.customer_id = c.id AND r3.name_ar LIKE ?
           ))';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
}
if ($whereParts !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $whereParts);
}
require_once app_path('includes/list_pagination.php');

$countSql = 'SELECT COUNT(*) FROM crm_customer c
             LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id
             LEFT JOIN crm_region rg ON rg.id = c.region_id
             LEFT JOIN crm_region_address ra ON ra.id = c.region_address_id';
if ($whereParts !== []) {
    $countSql .= ' WHERE ' . implode(' AND ', $whereParts);
}
$stCount = $pdo->prepare($countSql);
$stCount->execute($params);
$listTotal = (int) $stCount->fetchColumn();

$listPagerQuery = [];
if ($search !== '') {
    $listPagerQuery['q'] = $search;
}
if ($showInactive) {
    $listPagerQuery['all'] = '1';
}
if ($regionFilterId > 0) {
    $listPagerQuery['region_id'] = (string) $regionFilterId;
}
$pager = list_pager_with_total(list_pager_from_request($pdo), $listTotal);
$listPagerUrl = list_pager_base_url('customers', $listPagerQuery);

$sql .= ' ORDER BY c.id DESC' . list_pager_sql_limit($pager);

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll() ?: [];
$customerUsageCounts = crm_customer_usage_counts($pdo);
$addUrl = app_url('index.php?r=customers&action=add');
require_once app_path('includes/oracle_pdo.php');
$oracleMapReady = oracle_customers_saved_mapping() !== null || oracle_is_enabled();
$oracleLinkedCount = 0;
try {
    $oracleLinkedCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM crm_customer
         WHERE oracle_key IS NOT NULL AND oracle_key <> ''
           AND code LIKE '112%'"
    )->fetchColumn();
} catch (Throwable $e) {
    $oracleLinkedCount = 0;
}

$allRegions = crm_region_load_all($pdo);
$cssPath = app_path('assets/css/regions-ssms.css');
$cssUrl = app_url('assets/css/regions-ssms.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$cssCustPath = app_path('assets/css/customers-ssms.css');
$cssCustUrl = app_url('assets/css/customers-ssms.css') . (is_file($cssCustPath) ? '?v=' . (string) filemtime($cssCustPath) : '');
$csrf = csrf_token();
$regionsUrl = app_url('index.php?r=customer_regions');
$repsUrl = app_url('index.php?r=sales_reps');
?>
<?php sales_ora12_enqueue_assets(); ?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($cssCustUrl) ?>">

<div class="dashboard-ora sales-ora12-screen rg-ssms cu-ssms" data-exit-guard="custom">
    <header class="dashboard-ora-screen-title no-print" role="banner">
        <h1 class="dashboard-ora-screen-title__text">العملاء</h1>
        <span class="dashboard-ora-screen-title__meta"><?= (int) $listTotal ?> صف</span>
        <?php nav_render_screen_close($GLOBALS['activeRoute'] ?? 'customers'); ?>
    </header>

    <div class="dashboard-ora-workspace rg-ssms-workspace">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> rg-ssms-flash"><?= esc($flash['message']) ?></div>
        <?php endif; ?>

        <div class="rg-ssms-toolbar" role="toolbar">
            <a class="rg-tb rg-tb--primary" href="<?= esc($addUrl) ?>"><span class="rg-tb-ico">＋</span> عميل</a>
            <span class="rg-tb-sep"></span>
            <a class="rg-tb" href="<?= esc($regionsUrl) ?>">المناطق</a>
            <a class="rg-tb" href="<?= esc($repsUrl) ?>">المندوبون</a>
            <span class="rg-tb-sep"></span>
            <?php if ($oracleMapReady || oracle_is_enabled()): ?>
                <form method="post" class="cu-ssms-inline-form" data-confirm="تحديث العملاء وحساباتهم من Oracle الآن؟">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                    <input type="hidden" name="_action" value="oracle_sync">
                    <button type="submit" class="rg-tb"><span class="rg-tb-ico">↻</span> Oracle Sync</button>
                </form>
            <?php else: ?>
                <a class="rg-tb" href="<?= esc(app_url('index.php?r=oracle_customers_sync')) ?>">إعداد Oracle</a>
            <?php endif; ?>
            <span class="rg-tb-sep"></span>
            <?php if ($showInactive): ?>
                <a class="rg-tb" href="<?= esc(app_url('index.php?r=customers' . ($search !== '' ? '&q=' . rawurlencode($search) : '') . ($regionFilterId > 0 ? '&region_id=' . $regionFilterId : ''))) ?>">نشط فقط</a>
            <?php else: ?>
                <a class="rg-tb" href="<?= esc(app_url('index.php?r=customers&all=1' . ($search !== '' ? '&q=' . rawurlencode($search) : '') . ($regionFilterId > 0 ? '&region_id=' . $regionFilterId : ''))) ?>">الكل</a>
            <?php endif; ?>
            <span class="rg-tb-grow"></span>
            <span class="rg-tb-hint" dir="ltr">dbo.crm_customer · Oracle <?= (int) $oracleLinkedCount ?></span>
        </div>

        <div class="rg-ssms-split cu-ssms-split">
            <aside class="rg-ssms-explorer" aria-label="مستكشف العملاء">
                <div class="rg-ssms-pane-title">
                    <span class="rg-ssms-folder">🗀</span> Object Explorer
                </div>
                <div class="rg-ssms-tree-head">
                    <span class="rg-ssms-server">■ CRM → Customers</span>
                </div>
                <ul class="rg-ssms-tree">
                    <li class="<?= $regionFilterId < 1 ? 'is-selected' : '' ?>">
                        <a class="rg-ssms-node" href="<?= esc(app_url('index.php?r=customers' . ($search !== '' ? '&q=' . rawurlencode($search) : '') . ($showInactive ? '&all=1' : ''))) ?>">
                            <span class="rg-ssms-icon">▣</span>
                            <span class="rg-ssms-node-name">All Customers</span>
                            <span class="rg-ssms-badge" dir="ltr"><?= $regionFilterId < 1 ? (int) $listTotal : '…' ?></span>
                        </a>
                    </li>
                    <li class="cu-ssms-tree-group">
                        <span class="cu-ssms-group-label">Folders / Regions</span>
                    </li>
                    <?php foreach ($allRegions as $rg):
                        $rid = (int) $rg['id'];
                        $active = $rid === $regionFilterId;
                        $href = app_url(
                            'index.php?r=customers&region_id=' . $rid
                            . ($search !== '' ? '&q=' . rawurlencode($search) : '')
                            . ($showInactive ? '&all=1' : '')
                        );
                        ?>
                        <li class="<?= $active ? 'is-selected' : '' ?><?= (int) ($rg['is_active'] ?? 1) ? '' : ' is-off' ?>">
                            <a class="rg-ssms-node" href="<?= esc($href) ?>">
                                <span class="rg-ssms-icon">▤</span>
                                <span class="rg-ssms-node-name"><?= esc((string) $rg['name_ar']) ?></span>
                                <span class="rg-ssms-badge" dir="ltr"><?= (int) ($rg['customer_count'] ?? 0) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </aside>

            <main class="rg-ssms-results">
                <div class="rg-ssms-pane-title">
                    <span class="rg-ssms-folder">▦</span>
                    Results
                    <?php if ($regionFilterId > 0): ?>
                        <?php
                        $rfName = '';
                        foreach ($allRegions as $rg) {
                            if ((int) $rg['id'] === $regionFilterId) {
                                $rfName = (string) $rg['name_ar'];
                                break;
                            }
                        }
                        ?>
                        <span class="rg-ssms-muted">— <?= esc($rfName !== '' ? $rfName : ('region_id=' . $regionFilterId)) ?></span>
                    <?php endif; ?>
                </div>

                <div class="rg-ssms-grid-bar">
                    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="rg-ssms-grid-add cu-ssms-search">
                        <input type="hidden" name="r" value="customers">
                        <?php if ($regionFilterId > 0): ?>
                            <input type="hidden" name="region_id" value="<?= $regionFilterId ?>">
                        <?php endif; ?>
                        <?php if ($showInactive): ?>
                            <input type="hidden" name="all" value="1">
                        <?php endif; ?>
                        <span class="rg-ssms-muted">Filter:</span>
                        <input class="rg-ssms-input rg-ssms-input--wide" type="search" name="q" value="<?= esc($search) ?>"
                               placeholder="Name / Code / Phone / Region / Rep" autocomplete="off" spellcheck="false">
                        <button class="rg-tb rg-tb--primary" type="submit">Execute</button>
                        <?php if ($search !== ''): ?>
                            <a class="rg-tb" href="<?= esc(app_url('index.php?r=customers' . ($regionFilterId > 0 ? '&region_id=' . $regionFilterId : '') . ($showInactive ? '&all=1' : ''))) ?>">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="rg-ssms-grid-wrap">
                    <table class="rg-ssms-grid cu-ssms-grid">
                        <thead>
                        <tr>
                            <th class="col-sel">#</th>
                            <th class="col-id">ID</th>
                            <th>Code</th>
                            <th class="col-name">Name</th>
                            <th>Region</th>
                            <th>Address</th>
                            <th>Phone</th>
                            <th>Sales Rep</th>
                            <th>Oracle</th>
                            <th class="col-status">Status</th>
                            <th class="col-act">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$rows): ?>
                            <tr class="rg-ssms-empty-row">
                                <td colspan="11">
                                    <?= $search !== '' ? 'No rows matching filter.' : 'No rows — (0 row(s) returned)' ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php
                        $rowNum = 0;
                        foreach ($rows as $c):
                            $rowNum++;
                            $custId = (int) $c['id'];
                            $usageCount = (int) ($customerUsageCounts[$custId] ?? 0);
                            $blockedDelete = $usageCount > 0;
                            $custName = (string) $c['name_ar'];
                            $deleteConfirm = 'حذف العميل «' . $custName . '» نهائياً من النظام؟';
                            $blockTitle = 'تعذر الحذف: مرتبط بـ ' . $usageCount . ' حركة';
                            $okey = trim((string) ($c['oracle_key'] ?? ''));
                            $on = (int) $c['is_active'];
                            $regionLabel = trim((string) ($c['region_name'] ?? ''));
                            $addrLabel = trim((string) ($c['region_address_name'] ?? ''));
                            ?>
                            <tr class="<?= $on ? '' : 'is-off' ?>">
                                <td class="col-sel"><?= $rowNum ?></td>
                                <td class="col-id" dir="ltr"><?= $custId ?></td>
                                <td dir="ltr" class="cu-ssms-code">
                                    <a href="<?= esc(app_url('index.php?r=customers&action=edit&id=' . $custId)) ?>"><?= esc((string) $c['code']) ?></a>
                                </td>
                                <td class="col-name"><?= esc((string) $c['name_ar']) ?></td>
                                <td><?= esc($regionLabel !== '' ? $regionLabel : '—') ?></td>
                                <td><?= esc($addrLabel !== '' ? $addrLabel : '—') ?></td>
                                <td dir="ltr"><?= esc((string) ($c['phone'] ?? '') !== '' ? (string) $c['phone'] : '—') ?></td>
                                <td><?= esc((string) ($c['sales_rep_name'] ?? '') !== '' ? (string) $c['sales_rep_name'] : '—') ?></td>
                                <td class="cu-ssms-ora" title="<?= esc($okey) ?>"><?= $okey !== '' ? '✓' : '—' ?></td>
                                <td class="col-status">
                                    <span class="rg-status <?= $on ? 'on' : 'off' ?>"><?= $on ? 'Active' : 'Off' ?></span>
                                </td>
                                <td class="col-act">
                                    <a href="<?= esc(app_url('index.php?r=customers&action=edit&id=' . $custId)) ?>">Edit</a>
                                    <?php if ($okey !== '' || str_starts_with((string) $c['code'], '112')):
                                        $stmtAcc = $okey !== '' ? $okey : (string) $c['code'];
                                        $stmtUrl = app_url(
                                            'index.php?r=report_oracle_customer_statement'
                                            . '&customer_id=' . $custId
                                            . '&account=' . rawurlencode($stmtAcc)
                                            . '&from=01-01-2020'
                                        );
                                        ?>
                                        <a href="<?= esc($stmtUrl) ?>" title="كشف Oracle">Oracle</a>
                                    <?php endif; ?>
                                    <form method="post" action="<?= esc($listUrl) ?>" data-confirm="تغيير حالة العميل؟">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                                        <input type="hidden" name="_action" value="toggle">
                                        <input type="hidden" name="id" value="<?= $custId ?>">
                                        <button type="submit"><?= $on ? 'Disable' : 'Enable' ?></button>
                                    </form>
                                    <?php if ($blockedDelete): ?>
                                        <button type="button" disabled title="<?= esc($blockTitle) ?>">Delete</button>
                                    <?php else: ?>
                                        <form method="post" action="<?= esc($listUrl) ?>" data-confirm="<?= esc($deleteConfirm) ?>">
                                            <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                                            <input type="hidden" name="_action" value="delete">
                                            <input type="hidden" name="id" value="<?= $custId ?>">
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
                    <span><?= count($rows) ?> row(s) displayed · <?= (int) $listTotal ?> total</span>
                    <span dir="ltr">SELECT * FROM crm_customer<?= $regionFilterId > 0 ? ' WHERE region_id=' . $regionFilterId : '' ?></span>
                    <span>Query executed successfully</span>
                </div>
                <div class="cu-ssms-pager">
                    <?php list_pager_render($pager, $listPagerUrl); ?>
                </div>
            </main>
        </div>
    </div>
</div>
