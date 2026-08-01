<?php
declare(strict_types=1);

require_once app_path('includes/sal_rep_route.php');
require_once app_path('includes/sales_oracle12_ui.php');
require_once app_path('includes/crm_sales_rep_schema.php');
require_once app_path('includes/customer_picker.php');

$pdo = db();
sal_rep_route_ensure_schema($pdo);
crm_sales_rep_ensure_schema($pdo);
crm_customer_ensure_gps_columns($pdo);

$listUrl = app_url('index.php?r=sales_rep_route');
$salesReps = crm_sales_rep_load_active($pdo);
$customers = $pdo->query(
    'SELECT id, code, name_ar, latitude, longitude FROM crm_customer WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة، أعد المحاولة.');
        redirect($listUrl);
    }
    $act = (string) ($_POST['_action'] ?? 'save');
    try {
        if ($act === 'delete') {
            sal_rep_route_delete($pdo, (int) ($_POST['id'] ?? 0));
            flash_set('success', 'تم حذف خط السير.');
        } else {
            $repId = (int) ($_POST['sales_rep_id'] ?? 0);
            $routeDate = parse_date_to_iso(trim((string) ($_POST['route_date'] ?? ''))) ?? '';
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $custRaw = $_POST['customer_ids'] ?? [];
            if (!is_array($custRaw)) {
                $custRaw = [];
            }
            $id = sal_rep_route_save(
                $pdo,
                $repId,
                $routeDate,
                $custRaw,
                $notes !== '' ? $notes : null,
                (int) (current_user()['id'] ?? 0),
                (int) ($_POST['id'] ?? 0) ?: null
            );
            flash_set('success', 'تم حفظ خط السير.');
            redirect(app_url('index.php?r=sales_rep_route&id=' . $id));
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect($listUrl);
}

$flash = flash_get();
$filterRep = isset($_GET['sales_rep_id']) && $_GET['sales_rep_id'] !== '' ? (int) $_GET['sales_rep_id'] : 0;
$editId = (int) ($_GET['id'] ?? 0);
$edit = $editId > 0 ? sal_rep_route_fetch($pdo, $editId) : null;
$routeDateDefault = $edit ? (string) $edit['route_date'] : date('Y-m-d');
$selectedRep = $edit ? (int) $edit['sales_rep_id'] : $filterRep;
$selectedCustIds = [];
if ($edit) {
    foreach ($edit['lines'] as $ln) {
        $selectedCustIds[(int) $ln['customer_id']] = true;
    }
}
$rows = sal_rep_route_list($pdo, $filterRep > 0 ? $filterRep : null, null, null, 80);

sales_ora12_enqueue_assets();
customer_picker_enqueue_assets();
?>
<div class="dashboard-ora sales-ora12-screen">
<?php sales_ora12_render_title_bar('خط سير المندوب', '', 'sales_rep_route'); ?>
<?php sales_ora12_workspace_open(); ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>"><?= esc($flash['message']) ?></div>
<?php endif; ?>

<div class="sales-ora-panel card">
    <h2><?= $edit ? 'تعديل خط السير' : 'تعيين خط سير جديد' ?></h2>
    <p class="muted" style="margin-top:0;">
        اختر المندوب وتاريخ الخط والعملاء المطلوب زيارتهم.
        لن يتمكن المندوب من عمل طلب شراء أو فاتورة لعميل إلا إذا كان مدرجاً هنا وكان ضمن 200 متر من موقع العميل.
    </p>
    <form method="post" action="<?= esc($listUrl) ?>" class="form-grid" id="rep-route-form">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : 0 ?>">
        <div class="form-row">
            <label class="field">
                <span class="field-label">المندوب *</span>
                <select class="input" name="sales_rep_id" required>
                    <option value="">— اختر —</option>
                    <?php foreach ($salesReps as $rep): ?>
                        <option value="<?= (int) $rep['id'] ?>" <?= $selectedRep === (int) $rep['id'] ? 'selected' : '' ?>>
                            <?= esc((string) $rep['name_ar']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">تاريخ خط السير *</span>
                <input class="input" type="date" name="route_date" required
                       value="<?= esc($routeDateDefault) ?>">
            </label>
        </div>
        <label class="field">
            <span class="field-label">ملاحظات</span>
            <input class="input" name="notes" maxlength="500" value="<?= esc((string) ($edit['notes'] ?? '')) ?>">
        </label>
        <fieldset class="field">
            <legend class="field-label">عملاء الزيارة *</legend>
            <div style="margin-bottom:0.5rem;">
                <button type="button" class="btn btn-secondary btn-sm" id="rep-route-check-all">تحديد الكل</button>
                <button type="button" class="btn btn-secondary btn-sm" id="rep-route-uncheck-all">إلغاء التحديد</button>
            </div>
            <div class="table-wrap" style="max-height:320px;overflow:auto;">
                <table class="data-table" id="rep-route-cust-table">
                    <thead>
                    <tr>
                        <th></th>
                        <th>الرمز</th>
                        <th>العميل</th>
                        <th>الموقع</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($customers as $c):
                        $cid = (int) $c['id'];
                        $hasLoc = $c['latitude'] !== null && $c['longitude'] !== null
                            && $c['latitude'] !== '' && $c['longitude'] !== '';
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="rep-route-cust" name="customer_ids[]"
                                       value="<?= $cid ?>" <?= isset($selectedCustIds[$cid]) ? 'checked' : '' ?>>
                            </td>
                            <td><code><?= esc((string) $c['code']) ?></code></td>
                            <td><?= esc((string) $c['name_ar']) ?></td>
                            <td><?= $hasLoc ? '📍 محدد' : '<span class="muted">بدون موقع</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$customers): ?>
                        <tr><td colspan="4" class="muted">لا يوجد عملاء.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </fieldset>
        <div class="form-row" style="gap:0.5rem;">
            <button class="btn btn-primary" type="submit">حفظ خط السير</button>
            <?php if ($edit): ?>
                <a class="btn btn-secondary" href="<?= esc($listUrl) ?>">جديد</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="sales-ora-panel card">
    <h2>خطوط السير المحفوظة</h2>
    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="form-row" style="gap:0.5rem;align-items:end;">
        <input type="hidden" name="r" value="sales_rep_route">
        <label class="field">
            <span class="field-label">تصفية بالمندوب</span>
            <select class="input" name="sales_rep_id" onchange="this.form.submit()">
                <option value="">جميع المندوبين</option>
                <?php foreach ($salesReps as $rep): ?>
                    <option value="<?= (int) $rep['id'] ?>" <?= $filterRep === (int) $rep['id'] ? 'selected' : '' ?>>
                        <?= esc((string) $rep['name_ar']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>التاريخ</th>
                <th>المندوب</th>
                <th>عدد العملاء</th>
                <th>ملاحظات</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= esc(format_date_dmY((string) $r['route_date'])) ?></td>
                    <td><?= esc((string) $r['sales_rep_name']) ?></td>
                    <td><?= (int) $r['customer_count'] ?></td>
                    <td><?= esc((string) ($r['notes'] ?: '—')) ?></td>
                    <td>
                        <a class="btn btn-sm" href="<?= esc(app_url('index.php?r=sales_rep_route&id=' . (int) $r['id'])) ?>">تعديل</a>
                        <form method="post" action="<?= esc($listUrl) ?>" style="display:inline;"
                              onsubmit="return confirm('حذف خط السير؟');">
                            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                            <input type="hidden" name="_action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button class="btn btn-secondary btn-sm" type="submit">حذف</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="5" class="muted">لا توجد خطوط سير بعد.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
  var allBtn = document.getElementById('rep-route-check-all');
  var noneBtn = document.getElementById('rep-route-uncheck-all');
  function setAll(on) {
    document.querySelectorAll('.rep-route-cust').forEach(function (el) { el.checked = !!on; });
  }
  if (allBtn) allBtn.onclick = function () { setAll(true); };
  if (noneBtn) noneBtn.onclick = function () { setAll(false); };
  var form = document.getElementById('rep-route-form');
  if (form) {
    form.addEventListener('submit', function (e) {
      if (!form.querySelector('.rep-route-cust:checked')) {
        e.preventDefault();
        alert('حدّد عميلاً واحداً على الأقل.');
      }
    });
  }
})();
</script>
<?php sales_ora12_workspace_close(); ?>
</div>
