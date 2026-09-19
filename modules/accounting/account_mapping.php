<?php
declare(strict_types=1);

require_once app_path('includes/acc_gl.php');
require_once app_path('includes/acc_account_tree.php');
require_once app_path('includes/account_picker.php');
$listUrl = app_url('index.php?r=account_mapping');
$pdo = db();

if (!acc_gl_ensure_schema($pdo)) {
    echo '<div class="card"><p class="alert alert-error">تعذر تحميل نظام الربط. نفّذ <code>database/migrations/032_acc_gl_posting.sql</code>.</p></div>';
    return;
}

$settings = acc_gl_load_settings($pdo);
$hiddenPostingRules = acc_gl_hidden_posting_rules();
$ensureIds = [];
foreach ($settings as $meta) {
    $aid = (int) ($meta['account_id'] ?? 0);
    if ($aid > 0) {
        $ensureIds[$aid] = $aid;
    }
}
$accounts = acc_journal_accounts_picker($pdo, array_values($ensureIds));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'جلسة غير صالحة.');
        redirect($listUrl);
    }
    $posted = $_POST['account'] ?? [];
    if (!is_array($posted)) {
        $posted = [];
    }
    try {
        $st = $pdo->prepare('UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = ?');
        foreach ($settings as $code => $meta) {
            if (in_array($code, $hiddenPostingRules, true)) {
                continue;
            }
            $raw = $posted[$code] ?? '';
            $accId = $raw === '' || $raw === null ? null : (int) $raw;
            if ($accId !== null && $accId < 1) {
                $accId = null;
            }
            if ($accId !== null) {
                $chk = $pdo->prepare('SELECT id, code FROM acc_account WHERE id = ? AND is_leaf = 1 LIMIT 1');
                $chk->execute([$accId]);
                $acc = $chk->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$acc) {
                    throw new RuntimeException('الحساب المختار لـ «' . ($meta['label_ar'] ?? $code) . '» يجب أن يكون حساباً نهائياً في الشجرة.');
                }
                if (!acc_account_is_valid_posting_mapping_target($pdo, $accId)) {
                    throw new RuntimeException(
                        'الحساب المختار لـ «' . ($meta['label_ar'] ?? $code) . '» قديم (مثل 11/12) وغير معتمد. اختر حساباً من الشجرة الهرمية أو حساباً معتمداً مثل 23 رواتب مستحقة.'
                    );
                }
            }
            $st->execute([$accId, $code]);
        }
        flash_set('success', 'تم حفظ ربط الحسابات. الترحيلات الجديدة ستستخدم هذه الإعدادات.');
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        flash_set('error', 'تعذر الحفظ.');
    }
    redirect($listUrl);
}

$flash = flash_get();
$bootstrapNotice = $_SESSION['coa_bootstrap_notice'] ?? null;
unset($_SESSION['coa_bootstrap_notice']);
$cssPath = app_path('assets/css/account-mapping.css');
$cssUrl = app_url('assets/css/account-mapping.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
account_picker_enqueue_assets();
account_picker_json_script($accounts);
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> acc-map-flash"><?= esc($flash['message']) ?></div>
<?php endif; ?>
<?php if ($bootstrapNotice && is_array($bootstrapNotice)): ?>
    <div class="alert alert-success acc-map-flash"><?= esc(implode(' ', $bootstrapNotice)) ?></div>
<?php endif; ?>

<div class="dashboard-ora-toolbar acc-map-toolbar no-print">
    <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=chart_of_accounts')) ?>">شجرة الحسابات</a>
</div>

<form method="post" action="<?= esc($listUrl) ?>" class="acc-map-panel acc-map-form master-page-form" id="acc-map-form">
    <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
    <h2 class="acc-map-panel-head">ربط العمليات المحاسبية</h2>
    <div class="acc-map-panel-body">
    <div class="table-wrap">
    <table class="data-table acc-map-table">
        <thead>
        <tr>
            <th>العملية / الاستخدام</th>
            <th>الحساب في الشجرة</th>
            <th>ملاحظة</th>
        </tr>
        </thead>
        <tbody>
        <?php
        $requiredCodes = ['ar_customers', 'ap_suppliers', 'cash', 'sales_revenue'];
        $inventoryId = (int) ($settings['inventory']['account_id'] ?? 0);
        if ($inventoryId < 1) {
            $requiredCodes[] = 'purchases';
        }
        foreach ($settings as $code => $meta):
            if (in_array($code, $hiddenPostingRules, true)) {
                continue;
            }
            $selId = (int) ($meta['account_id'] ?? 0);
            $isReq = in_array($code, $requiredCodes, true);
            ?>
            <tr>
                <td>
                    <strong><?= esc($meta['label_ar']) ?></strong>
                    <?php if ($isReq): ?><span class="acc-map-req">*</span><?php endif; ?>
                    <?php if ($code === 'purchases' && $inventoryId > 0): ?>
                        <span class="muted acc-map-optional"> — اختياري (المخزون مفعّل)</span>
                    <?php endif; ?>
                    <div class="muted acc-map-code"><?= esc($code) ?></div>
                </td>
                <td>
                    <?php
                    $hid = 'acc_map_' . preg_replace('/[^a-z0-9_]/', '_', $code);
                    echo account_picker_field([
                        'id' => $hid,
                        'name' => 'account[' . $code . ']',
                        'value' => $selId,
                        'placeholder' => 'اختر حساباً…',
                        'allow_clear' => true,
                        'max_results' => 0,
                    ]);
                    ?>
                </td>
                <td class="muted acc-map-hint"><?= esc((string) ($meta['hint_ar'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div class="acc-map-actions no-print">
        <button type="submit" class="btn btn-primary">حفظ الربط</button>
    </div>
    </div>
</form>

<script>
document.addEventListener('master-toolbar', function (e) {
  if (e.detail && e.detail.action === 'save') {
    e.preventDefault();
    var f = document.getElementById('acc-map-form');
    if (f) f.requestSubmit ? f.requestSubmit() : f.submit();
  }
});
</script>
