<?php
declare(strict_types=1);

$listUrl = app_url('index.php?r=sales_reps');

require_once app_path('includes/nav_helpers.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/crm_region.php');

$pdo = db();
crm_sales_rep_ensure_mobile_custody_schema($pdo);
crm_region_ensure_schema($pdo);
crm_sales_rep_region_ensure_schema($pdo);
if (!crm_sales_rep_ensure_schema($pdo)) {
    ?>
    <div class="alert alert-error">
        تعذر إنشاء جدول مندوبي المبيعات. نفّذ من phpMyAdmin:
        <code>database/migrations/009_crm_sales_rep.sql</code>
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
        if ($act === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $code = trim((string) ($_POST['code'] ?? ''));
            $name = trim((string) ($_POST['name_ar'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $address = trim((string) ($_POST['address_ar'] ?? ''));
            $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
            $autoCreateWarehouse = isset($_POST['auto_create_warehouse']);
            $regionIdsRaw = $_POST['region_ids'] ?? [];
            if (!is_array($regionIdsRaw)) {
                $regionIdsRaw = [];
            }
            $addressIdsRaw = $_POST['region_address_ids'] ?? [];
            if (!is_array($addressIdsRaw)) {
                $addressIdsRaw = [];
            }

            if ($name === '') {
                throw new RuntimeException('اسم المندوب مطلوب.');
            }

            if ($code === '') {
                $n = (int) $pdo->query('SELECT IFNULL(MAX(id), 0) FROM crm_sales_rep')->fetchColumn();
                $code = 'REP-' . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
            }

            if ($id > 0) {
                $chk = $pdo->prepare('SELECT id FROM crm_sales_rep WHERE code = ? AND id <> ? LIMIT 1');
                $chk->execute([$code, $id]);
            } else {
                $chk = $pdo->prepare('SELECT id FROM crm_sales_rep WHERE code = ? LIMIT 1');
                $chk->execute([$code]);
            }
            if ($chk->fetch()) {
                throw new RuntimeException('رمز المندوب مستخدم مسبقًا.');
            }

            if ($warehouseId > 0) {
                $whChk = $pdo->prepare('SELECT id FROM inv_warehouse WHERE id = ? AND is_active = 1 LIMIT 1');
                $whChk->execute([$warehouseId]);
                if (!$whChk->fetch()) {
                    throw new RuntimeException('مستودع العهدة المحدد غير موجود أو غير نشط.');
                }
            } else {
                $warehouseId = null;
            }

            $hasWhCol = crm_sales_rep_has_warehouse_link($pdo);

            if ($id > 0) {
                if ($hasWhCol) {
                    $st = $pdo->prepare(
                        'UPDATE crm_sales_rep SET code=?, name_ar=?, phone=?, address_ar=?, warehouse_id=? WHERE id=?'
                    );
                    $st->execute([
                        $code,
                        $name,
                        $phone !== '' ? $phone : null,
                        $address !== '' ? $address : null,
                        $warehouseId,
                        $id,
                    ]);
                } else {
                    $st = $pdo->prepare(
                        'UPDATE crm_sales_rep SET code=?, name_ar=?, phone=?, address_ar=? WHERE id=?'
                    );
                    $st->execute([
                        $code,
                        $name,
                        $phone !== '' ? $phone : null,
                        $address !== '' ? $address : null,
                        $id,
                    ]);
                }
                if ($autoCreateWarehouse && $warehouseId === null) {
                    crm_sales_rep_ensure_custody_warehouse($pdo, $id);
                }
                if ($addressIdsRaw !== []) {
                    crm_sales_rep_save_region_addresses($pdo, $id, $addressIdsRaw);
                } else {
                    crm_sales_rep_save_region_addresses($pdo, $id, []);
                    if ($regionIdsRaw !== []) {
                        crm_sales_rep_save_regions($pdo, $id, $regionIdsRaw);
                    }
                }
                flash_set('success', 'تم تحديث بيانات المندوب.');
            } else {
                if ($hasWhCol) {
                    $st = $pdo->prepare(
                        'INSERT INTO crm_sales_rep (code, name_ar, phone, address_ar, warehouse_id, is_active) VALUES (?,?,?,?,?,1)'
                    );
                    $st->execute([
                        $code,
                        $name,
                        $phone !== '' ? $phone : null,
                        $address !== '' ? $address : null,
                        $warehouseId,
                    ]);
                } else {
                    $st = $pdo->prepare(
                        'INSERT INTO crm_sales_rep (code, name_ar, phone, address_ar, is_active) VALUES (?,?,?,?,1)'
                    );
                    $st->execute([
                        $code,
                        $name,
                        $phone !== '' ? $phone : null,
                        $address !== '' ? $address : null,
                    ]);
                }
                $newId = (int) $pdo->lastInsertId();
                if ($autoCreateWarehouse && $warehouseId === null && $newId > 0) {
                    crm_sales_rep_ensure_custody_warehouse($pdo, $newId);
                }
                if ($newId > 0) {
                    if ($addressIdsRaw !== []) {
                        crm_sales_rep_save_region_addresses($pdo, $newId, $addressIdsRaw);
                    } elseif ($regionIdsRaw !== []) {
                        crm_sales_rep_save_regions($pdo, $newId, $regionIdsRaw);
                    }
                }
                flash_set('success', 'تم إضافة المندوب.');
            }
        } elseif ($act === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('معرّف غير صالح.');
            }
            $st = $pdo->prepare('UPDATE crm_sales_rep SET is_active = 1 - is_active WHERE id = ?');
            $st->execute([$id]);
            flash_set('success', 'تم تحديث حالة المندوب.');
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
        'address_ar' => '',
        'warehouse_id' => null,
        'is_active' => 1,
    ];
    if ($action === 'edit') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            flash_set('error', 'مندوب غير موجود.');
            redirect($listUrl);
        }
        $st = $pdo->prepare('SELECT * FROM crm_sales_rep WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $dbRow = $st->fetch();
        if (!$dbRow) {
            flash_set('error', 'مندوب غير موجود.');
            redirect($listUrl);
        }
        $row = array_merge($row, $dbRow);
    }

    $warehouses = $pdo->query(
        'SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $regions = crm_region_load_active($pdo);
    $addressesMap = crm_region_addresses_map($pdo);
    $selectedAddressIds = (int) ($row['id'] ?? 0) > 0
        ? crm_sales_rep_region_address_ids($pdo, (int) $row['id'])
        : [];
    $selectedCoverage = (int) ($row['id'] ?? 0) > 0
        ? crm_sales_rep_coverage_detail($pdo, (int) $row['id'])
        : [];

    $formTitle = $action === 'add' ? 'إضافة مندوب' : 'تعديل مندوب';
    ?>
    <?php sales_ora12_enqueue_assets(); ?>
    <style>
    .rep-coverage-box{border:1px solid rgba(0,0,0,.08);border-radius:8px;padding:.75rem;background:rgba(0,0,0,.02)}
    .rep-coverage-add{display:flex;flex-wrap:wrap;gap:.5rem;align-items:end;margin-bottom:.65rem}
    .rep-coverage-add .field{margin:0;flex:1;min-width:10rem}
    .rep-coverage-addrs{display:flex;flex-wrap:wrap;gap:.35rem .75rem;margin:.35rem 0 .5rem;max-height:9rem;overflow:auto}
    .rep-coverage-addrs label{display:inline-flex;align-items:center;gap:.3rem;font-size:.9rem}
    .rep-coverage-list{width:100%;border-collapse:collapse;font-size:.9rem}
    .rep-coverage-list th,.rep-coverage-list td{padding:.4rem .5rem;border-bottom:1px solid rgba(0,0,0,.07);text-align:right}
    .rep-coverage-list th{background:rgba(0,0,0,.03)}
    .rep-cov-empty{color:#888;font-size:.9rem;margin:.4rem 0}
    </style>
    <div class="dashboard-ora sales-ora12-screen sales-reps-ora-screen sales-reps-ora-form-page">
        <?php sales_ora12_render_title_bar($formTitle); ?>
        <?php sales_ora12_workspace_open(); ?>
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash"><?= esc($flash['message']) ?></div>
        <?php endif; ?>

        <div class="sales-ora-toolbar toolbar">
            <a class="btn btn-secondary btn-sm" href="<?= esc($listUrl) ?>">↩ رجوع للقائمة</a>
        </div>

        <div class="sales-ora-panel card">
        <form method="post" action="<?= esc($listUrl) ?>" class="form-grid" id="sales-rep-form">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
            <div id="rep-coverage-hiddens">
                <?php foreach ($selectedAddressIds as $aid): ?>
                    <input type="hidden" name="region_address_ids[]" value="<?= (int) $aid ?>" data-aid="<?= (int) $aid ?>">
                <?php endforeach; ?>
            </div>

            <label class="field">
                <span class="field-label">اسم المندوب *</span>
                <input class="input" name="name_ar" required value="<?= esc((string) $row['name_ar']) ?>">
            </label>

            <label class="field">
                <span class="field-label">رقم التلفون</span>
                <input class="input" name="phone" type="tel" value="<?= esc((string) ($row['phone'] ?? '')) ?>" autocomplete="tel">
            </label>

            <label class="field">
                <span class="field-label">العنوان الشخصي (اختياري)</span>
                <textarea class="input" name="address_ar" rows="2"><?= esc((string) ($row['address_ar'] ?? '')) ?></textarea>
            </label>

            <fieldset class="field customers-reps-field">
                <legend class="field-label">تغطية المناطق والعناوين</legend>
                <?php if (!$regions): ?>
                    <p class="muted">
                        لا توجد مناطق —
                        <a href="<?= esc(app_url('index.php?r=customer_regions')) ?>">عرّف المناطق والعناوين أولاً</a>.
                    </p>
                <?php else: ?>
                    <div class="rep-coverage-box">
                        <p class="muted" style="margin:0 0 .5rem;font-size:.85rem;">
                            اختر <strong>المنطقة</strong> فتظهر عناوينها، ثم أضف العناوين المختارة. يمكن تكرار العملية لمنطقة أخرى.
                        </p>
                        <div class="rep-coverage-add">
                            <label class="field">
                                <span class="field-label">المنطقة</span>
                                <select class="input" id="rep-pick-region">
                                    <option value="">— اختر منطقة —</option>
                                    <?php foreach ($regions as $rg): ?>
                                        <option value="<?= (int) $rg['id'] ?>"><?= esc((string) $rg['name_ar']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <div id="rep-pick-addrs" class="rep-coverage-addrs"></div>
                        <button type="button" class="btn btn-secondary btn-sm" id="rep-add-coverage">➕ إضافة العناوين المحددة للتغطية</button>

                        <h4 style="margin:1rem 0 .4rem;font-size:.95rem;">التغطية الحالية</h4>
                        <table class="rep-coverage-list" id="rep-coverage-table">
                            <thead>
                            <tr><th>المنطقة</th><th>العنوان</th><th></th></tr>
                            </thead>
                            <tbody>
                            <?php if (!$selectedCoverage): ?>
                                <tr class="rep-cov-empty-row"><td colspan="3" class="rep-cov-empty">لم تُضف مناطق/عناوين بعد.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($selectedCoverage as $cv):
                                if ((int) ($cv['address_id'] ?? 0) < 1) {
                                    continue;
                                }
                                ?>
                                <tr data-aid="<?= (int) $cv['address_id'] ?>">
                                    <td><?= esc((string) ($cv['region_name'] ?? $cv['name_ar'] ?? '')) ?></td>
                                    <td><?= esc((string) ($cv['address_ar'] ?? '')) ?></td>
                                    <td><button type="button" class="btn btn-danger btn-sm js-rep-cov-remove">إزالة</button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </fieldset>

            <?php if (crm_sales_rep_has_warehouse_link($pdo)): ?>
            <label class="field">
                <span class="field-label">مستودع العهدة</span>
                <select class="input" name="warehouse_id">
                    <option value="">— اختيار لاحقًا / تلقائي —</option>
                    <?php
                    $currentWhId = (int) ($row['warehouse_id'] ?? 0);
                    foreach ($warehouses as $wh):
                        $wid = (int) $wh['id'];
                        ?>
                        <option value="<?= $wid ?>"<?= $currentWhId === $wid ? ' selected' : '' ?>>
                            <?= esc((string) $wh['name_ar']) ?> (<?= esc((string) $wh['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="field-hint muted">مستودع يحمل فيه المندوب المواد من المستودع الرئيسي.</span>
            </label>
            <label class="field users-admin-check-inline" style="display:flex;align-items:center;gap:.5rem;">
                <input type="checkbox" name="auto_create_warehouse" value="1"
                    <?= $currentWhId < 1 ? 'checked' : '' ?>>
                <span>إنشاء مستودع عهدة تلقائيًا (VAN-رمز المندوب) إن لم يُحدَّد مستودع</span>
            </label>
            <?php endif; ?>

            <input type="hidden" name="code" value="<?= esc((string) $row['code']) ?>">

            <div>
                <button class="btn btn-primary" type="submit">حفظ</button>
            </div>
        </form>
        </div>
        <?php if ($regions): ?>
        <script>
        (function () {
          var map = <?= json_encode($addressesMap, JSON_UNESCAPED_UNICODE) ?>;
          var regionNames = {};
          <?php foreach ($regions as $rg): ?>
          regionNames[<?= (int) $rg['id'] ?>] = <?= json_encode((string) $rg['name_ar'], JSON_UNESCAPED_UNICODE) ?>;
          <?php endforeach; ?>

          var regionSel = document.getElementById('rep-pick-region');
          var addrsBox = document.getElementById('rep-pick-addrs');
          var addBtn = document.getElementById('rep-add-coverage');
          var tbody = document.querySelector('#rep-coverage-table tbody');
          var hiddens = document.getElementById('rep-coverage-hiddens');

          function hasAid(aid) {
            return !!hiddens.querySelector('input[data-aid="' + aid + '"]');
          }

          function renderAddrs() {
            addrsBox.innerHTML = '';
            var rid = parseInt(regionSel.value, 10) || 0;
            if (!rid || !map[rid] || !map[rid].length) {
              addrsBox.innerHTML = '<span class="muted" style="font-size:.85rem;">لا عناوين مربوطة بهذه المنطقة — عرّفها من شاشة المناطق.</span>';
              return;
            }
            map[rid].forEach(function (a) {
              var id = parseInt(a.id, 10);
              var lab = document.createElement('label');
              var cb = document.createElement('input');
              cb.type = 'checkbox';
              cb.value = String(id);
              cb.disabled = hasAid(id);
              lab.appendChild(cb);
              lab.appendChild(document.createTextNode(' ' + (a.name_ar || '')));
              addrsBox.appendChild(lab);
            });
          }

          function removeEmptyRow() {
            var er = tbody.querySelector('.rep-cov-empty-row');
            if (er) er.remove();
          }

          function ensureEmpty() {
            if (!tbody.querySelector('tr[data-aid]')) {
              tbody.innerHTML = '<tr class="rep-cov-empty-row"><td colspan="3" class="rep-cov-empty">لم تُضف مناطق/عناوين بعد.</td></tr>';
            }
          }

          function addCoverage(aid, regionId, addrName) {
            aid = parseInt(aid, 10);
            if (!aid || hasAid(aid)) return;
            removeEmptyRow();
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'region_address_ids[]';
            inp.value = String(aid);
            inp.setAttribute('data-aid', String(aid));
            hiddens.appendChild(inp);

            var tr = document.createElement('tr');
            tr.setAttribute('data-aid', String(aid));
            tr.innerHTML = '<td></td><td></td><td><button type="button" class="btn btn-danger btn-sm js-rep-cov-remove">إزالة</button></td>';
            tr.cells[0].textContent = regionNames[regionId] || '';
            tr.cells[1].textContent = addrName || '';
            tbody.appendChild(tr);
          }

          function removeCoverage(aid) {
            var h = hiddens.querySelector('input[data-aid="' + aid + '"]');
            if (h) h.remove();
            var tr = tbody.querySelector('tr[data-aid="' + aid + '"]');
            if (tr) tr.remove();
            ensureEmpty();
            renderAddrs();
          }

          if (regionSel) regionSel.addEventListener('change', renderAddrs);
          if (addBtn) {
            addBtn.addEventListener('click', function () {
              var rid = parseInt(regionSel.value, 10) || 0;
              if (!rid) { alert('اختر المنطقة أولاً'); return; }
              var checks = addrsBox.querySelectorAll('input[type="checkbox"]:checked:not(:disabled)');
              if (!checks.length) { alert('اختر عنواناً واحداً على الأقل'); return; }
              checks.forEach(function (cb) {
                var lab = cb.parentNode;
                var name = lab ? lab.textContent.replace(/^\s+/, '').trim() : '';
                addCoverage(cb.value, rid, name);
              });
              renderAddrs();
            });
          }
          if (tbody) {
            tbody.addEventListener('click', function (e) {
              var btn = e.target.closest('.js-rep-cov-remove');
              if (!btn) return;
              var tr = btn.closest('tr[data-aid]');
              if (!tr) return;
              removeCoverage(tr.getAttribute('data-aid'));
            });
          }
          renderAddrs();
        })();
        </script>
        <?php endif; ?>
        <?php sales_ora12_workspace_close(); ?>
    </div>
    <?php
    return;
}

$search = trim((string) ($_GET['q'] ?? ''));

$regionNamesSub = '(SELECT GROUP_CONCAT(DISTINCT CONCAT(rg.name_ar, \' — \', a.name_ar) ORDER BY rg.name_ar, a.name_ar SEPARATOR \'، \')
                   FROM crm_sales_rep_region_address sra
                   INNER JOIN crm_region_address a ON a.id = sra.region_address_id
                   INNER JOIN crm_region rg ON rg.id = a.region_id
                   WHERE sra.sales_rep_id = r.id)';

$sql = "SELECT r.id, r.code, r.name_ar, r.phone, r.address_ar, r.is_active, r.created_at,
        w.name_ar AS warehouse_name_ar, w.code AS warehouse_code,
        {$regionNamesSub} AS region_names
        FROM crm_sales_rep r
        LEFT JOIN inv_warehouse w ON w.id = r.warehouse_id";
$params = [];
if ($search !== '') {
    $sql .= " WHERE (r.name_ar LIKE ? OR r.code LIKE ? OR r.phone LIKE ? OR r.address_ar LIKE ?
              OR IFNULL({$regionNamesSub}, '') LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_fill(0, 5, $like);
}
require_once app_path('includes/list_pagination.php');

$countSql = 'SELECT COUNT(*) FROM crm_sales_rep r';
if ($search !== '') {
    $countSql .= " WHERE (r.name_ar LIKE ? OR r.code LIKE ? OR r.phone LIKE ? OR r.address_ar LIKE ?
                   OR IFNULL({$regionNamesSub}, '') LIKE ?)";
}
$stCount = $pdo->prepare($countSql);
$stCount->execute($params);
$listTotal = (int) $stCount->fetchColumn();
$pager = list_pager_with_total(list_pager_from_request($pdo), $listTotal);
$listPagerUrl = list_pager_base_url('sales_reps', $search !== '' ? ['q' => $search] : []);

$sql .= ' ORDER BY r.name_ar ASC, r.id DESC' . list_pager_sql_limit($pager);

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll() ?: [];
$addUrl = app_url('index.php?r=sales_reps&action=add');
?>
<?php sales_ora12_enqueue_assets(); ?>

<div class="dashboard-ora sales-ora12-screen sales-reps-ora-screen sales-reps-ora-list-page">
    <?php sales_ora12_render_title_bar('المندوبين'); ?>
    <?php sales_ora12_workspace_open(); ?>
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> sales-ora-flash"><?= esc($flash['message']) ?></div>
    <?php endif; ?>

    <div class="sales-ora-toolbar toolbar">
        <a class="btn btn-primary btn-sm" href="<?= esc($addUrl) ?>">➕ إضافة مندوب</a>
    </div>

    <div class="sales-ora-panel card">
        <form method="get" action="<?= esc(app_url('index.php')) ?>" class="sales-ora-search-form form-row">
            <input type="hidden" name="r" value="sales_reps">
            <label class="field sales-reps-ora-search-field">
                <span class="field-label">بحث عن المندوب</span>
                <input class="input" type="search" name="q" value="<?= esc($search) ?>"
                       placeholder="الاسم، الرمز، التلفون، المنطقة…" autocomplete="off" spellcheck="false">
            </label>
            <div class="sales-reps-ora-search-actions">
                <button type="submit" class="btn btn-secondary btn-sm">بحث</button>
                <?php if ($search !== ''): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= esc($listUrl) ?>">مسح</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="sales-ora-panel card">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>#</th>
                <th>الرمز</th>
                <th>اسم المندوب</th>
                <th>المناطق</th>
                <th>التلفون</th>
                <th>مستودع العهدة</th>
                <th>الحالة</th>
                <th>إجراءات</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="8" class="muted">
                        <?= $search !== '' ? 'لا يوجد مندوب مطابق لبحثك.' : 'لا يوجد مندوبون بعد.' ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php foreach ($rows as $rep): ?>
                <tr>
                    <td><?= (int) $rep['id'] ?></td>
                    <td><code><?= esc((string) $rep['code']) ?></code></td>
                    <td><?= esc((string) $rep['name_ar']) ?></td>
                    <td><?= esc(trim((string) ($rep['region_names'] ?? '')) !== '' ? (string) $rep['region_names'] : '—') ?></td>
                    <td><?= esc((string) ($rep['phone'] ?? '—')) ?></td>
                    <td>
                        <?php if (!empty($rep['warehouse_name_ar'])): ?>
                            <?= esc((string) $rep['warehouse_name_ar']) ?>
                            <span class="muted">(<?= esc((string) ($rep['warehouse_code'] ?? '')) ?>)</span>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int) $rep['is_active']): ?>
                            <span class="badge badge-ok">نشط</span>
                        <?php else: ?>
                            <span class="badge badge-off">موقوف</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=sales_reps&action=edit&id=' . (int) $rep['id'])) ?>">تعديل</a>
                            <form method="post" action="<?= esc($listUrl) ?>" data-confirm="تغيير حالة المندوب؟">
                                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                                <input type="hidden" name="_action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $rep['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit"><?= (int) $rep['is_active'] ? 'تعطيل' : 'تفعيل' ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php list_pager_render($pager, $listPagerUrl); ?>
    </div>
    <?php sales_ora12_workspace_close(); ?>
</div>
