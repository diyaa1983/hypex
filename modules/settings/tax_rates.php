<?php
declare(strict_types=1);

require_permission('tax_rates_settings');

require_once app_path('includes/sal_invoice_schema.php');

$pdo = db();
sal_invoice_ensure_schema($pdo);

$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $msg = 'انتهت صلاحية الجلسة، أعد المحاولة.';
        $msgType = 'error';
    } else {
        $action = (string) ($_POST['_action'] ?? '');

        if ($action === 'tax_rate_add') {
            $name = trim((string) ($_POST['name_ar'] ?? ''));
            $rate = (float) str_replace(',', '.', (string) ($_POST['rate_percent'] ?? '0'));
            $sort = (int) ($_POST['sort_order'] ?? 10);
            if ($name === '') {
                $msg = 'اسم المعدّل مطلوب.';
                $msgType = 'error';
            } elseif ($rate < 0 || $rate > 100) {
                $msg = 'نسبة الضريبة يجب أن تكون بين 0 و 100.';
                $msgType = 'error';
            } else {
                try {
                    $pdo->prepare(
                        'INSERT INTO sys_tax_rate (name_ar, rate_percent, sort_order, is_active)
                         VALUES (?,?,?,1)'
                    )->execute([$name, round($rate, 3), $sort]);
                    $msg = 'تمت إضافة معدّل الضريبة.';
                    $msgType = 'success';
                } catch (Throwable $e) {
                    $msg = 'تعذر الإضافة. قد يكون الاسم مكررًا.';
                    $msgType = 'error';
                }
            }
        } elseif ($action === 'tax_rates_save_all') {
            $rows = $_POST['rates'] ?? [];
            if (!is_array($rows) || $rows === []) {
                $msg = 'لا توجد بيانات للحفظ.';
                $msgType = 'error';
            } else {
                $errors = [];
                $updates = [];
                foreach ($rows as $idStr => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $id = (int) $idStr;
                    if ($id < 1) {
                        continue;
                    }
                    $name = trim((string) ($row['name_ar'] ?? ''));
                    $rate = (float) str_replace(',', '.', (string) ($row['rate_percent'] ?? '0'));
                    $sort = (int) ($row['sort_order'] ?? 0);
                    $active = isset($row['is_active']) ? 1 : 0;
                    if ($name === '') {
                        $errors[] = 'اسم المعدّل مطلوب.';
                        continue;
                    }
                    if ($rate < 0 || $rate > 100) {
                        $errors[] = 'نسبة الضريبة يجب أن تكون بين 0 و 100 («' . $name . '»).';
                        continue;
                    }
                    $updates[$id] = [
                        'name' => $name,
                        'rate' => round($rate, 3),
                        'sort' => $sort,
                        'active' => $active,
                    ];
                }
                if ($errors !== []) {
                    $msg = implode(' ', $errors);
                    $msgType = 'error';
                } elseif ($updates === []) {
                    $msg = 'لا توجد بيانات للحفظ.';
                    $msgType = 'error';
                } else {
                    $activeAfter = 0;
                    foreach ($updates as $u) {
                        if ($u['active'] === 1) {
                            $activeAfter++;
                        }
                    }
                    if ($activeAfter < 1) {
                        $msg = 'يجب أن يبقى معدّل ضريبة واحد على الأقل نشطًا.';
                        $msgType = 'error';
                    } else {
                        try {
                            $pdo->beginTransaction();
                            $st = $pdo->prepare(
                                'UPDATE sys_tax_rate SET name_ar = ?, rate_percent = ?, sort_order = ?, is_active = ? WHERE id = ?'
                            );
                            foreach ($updates as $id => $u) {
                                $st->execute([$u['name'], $u['rate'], $u['sort'], $u['active'], $id]);
                            }
                            $pdo->commit();
                            $msg = 'تم حفظ التعديلات.';
                            $msgType = 'success';
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $msg = 'تعذر الحفظ. قد يكون الاسم مكررًا.';
                            $msgType = 'error';
                        }
                    }
                }
            }
        } elseif ($action === 'tax_rate_delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                $msg = 'معرّف غير صالح.';
                $msgType = 'error';
            } else {
                $total = (int) $pdo->query('SELECT COUNT(*) FROM sys_tax_rate')->fetchColumn();
                if ($total <= 1) {
                    $msg = 'لا يمكن حذف آخر معدّل ضريبة.';
                    $msgType = 'error';
                } else {
                    try {
                        $pdo->prepare('DELETE FROM sys_tax_rate WHERE id = ?')->execute([$id]);
                        $msg = 'تم حذف المعدّل.';
                        $msgType = 'success';
                    } catch (Throwable $e) {
                        $msg = 'تعذر الحذف.';
                        $msgType = 'error';
                    }
                }
            }
        }
    }
}

$rates = $pdo->query(
    'SELECT id, name_ar, rate_percent, sort_order, is_active FROM sys_tax_rate ORDER BY sort_order ASC, id ASC'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$generalUrl = app_url('index.php?r=settings');
$taxRatesFormId = 'tax-rates-form';
$taxRatesJsUrl = app_url('assets/js/tax-rates.js');
?>
<div class="card" style="max-width:960px;">
    <p class="muted" style="margin:0 0 1rem;">
        معدّلات الضريبة تظهر في فواتير المبيعات والمشتريات ضمن قائمة منسدلة لكل بند.
        <strong>النسبة الافتراضية</strong> عند إضافة سطر جديد تُضبط من
        <a href="<?= esc($generalUrl) ?>">الإعدادات العامة</a> (قائمة «النسبة الافتراضية للضريبة»).
    </p>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>"><?= esc($msg) ?></div>
    <?php endif; ?>

    <h3 style="margin:0 0 0.75rem;font-size:1.05rem;">إضافة معدّل جديد</h3>
    <form method="post" class="form-row" style="align-items:flex-end;gap:0.75rem;flex-wrap:wrap;margin-bottom:1.5rem;">
        <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
        <input type="hidden" name="_action" value="tax_rate_add">
        <label class="field" style="min-width:200px;margin:0;">
            <span class="field-label">الاسم (عربي)</span>
            <input class="input" name="name_ar" required maxlength="100" placeholder="مثال: ضريبة مخفّضة">
        </label>
        <label class="field" style="width:8rem;margin:0;">
            <span class="field-label">النسبة %</span>
            <input class="input" name="rate_percent" type="number" step="0.001" min="0" max="100" value="0" required>
        </label>
        <label class="field" style="width:7rem;margin:0;">
            <span class="field-label">ترتيب</span>
            <input class="input" name="sort_order" type="number" value="10">
        </label>
        <button type="submit" class="btn btn-primary">إضافة</button>
    </form>

    <h3 style="margin:0 0 0.75rem;font-size:1.05rem;">المعدّلات الحالية</h3>
    <?php if ($rates !== []): ?>
        <form id="<?= esc($taxRatesFormId) ?>" method="post" class="sr-only" aria-hidden="true">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="_action" value="tax_rates_save_all">
            <button type="submit" id="tax-rates-submit">حفظ</button>
        </form>
        <p class="muted" style="margin:0 0 0.75rem;font-size:0.9rem;">عدّل الحقول ثم اضغط <strong>حفظ</strong> في الشريط العلوي لحفظ كل التعديلات دفعة واحدة.</p>
    <?php endif; ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>الاسم</th>
                <th>النسبة %</th>
                <th>ترتيب العرض</th>
                <th>نشط</th>
                <th style="width:6rem;">إجراءات</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rates as $r):
                $rid = (int) $r['id'];
                ?>
                <tr>
                    <td>
                        <input class="input" form="<?= esc($taxRatesFormId) ?>" name="rates[<?= $rid ?>][name_ar]"
                               value="<?= esc((string) $r['name_ar']) ?>" required maxlength="100">
                    </td>
                    <td>
                        <input class="input" form="<?= esc($taxRatesFormId) ?>" name="rates[<?= $rid ?>][rate_percent]"
                               type="number" step="0.001" min="0" max="100"
                               value="<?= esc((string) $r['rate_percent']) ?>" required style="width:6rem;">
                    </td>
                    <td>
                        <input class="input" form="<?= esc($taxRatesFormId) ?>" name="rates[<?= $rid ?>][sort_order]"
                               type="number" value="<?= (int) $r['sort_order'] ?>" style="width:5rem;">
                    </td>
                    <td>
                        <label style="display:flex;align-items:center;gap:0.35rem;margin:0;">
                            <input type="checkbox" form="<?= esc($taxRatesFormId) ?>" name="rates[<?= $rid ?>][is_active]"
                                   value="1" <?= (int) $r['is_active'] === 1 ? 'checked' : '' ?>>
                            <span class="muted" style="font-size:0.85rem;">نشط</span>
                        </label>
                    </td>
                    <td>
                        <form method="post" style="margin:0;" onsubmit="return confirm('حذف هذا المعدّل؟');">
                            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                            <input type="hidden" name="_action" value="tax_rate_delete">
                            <input type="hidden" name="id" value="<?= $rid ?>">
                            <button type="submit" class="btn btn-danger btn-sm">حذف</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rates === []): ?>
                <tr><td colspan="5" class="muted" style="text-align:center;">لا توجد معدّلات. أضف واحدًا أعلاه.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="<?= esc($taxRatesJsUrl) ?>" defer></script>
