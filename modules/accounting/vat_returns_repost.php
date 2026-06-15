<?php
declare(strict_types=1);

require_once app_path('includes/acc_return_vat_repost.php');

if (!user_is_system_admin()) {
    flash_set('error', 'هذه العملية للمسؤول فقط.');
    redirect(app_url('index.php?r=dashboard'));
}

$pdo = db();
acc_gl_ensure_schema($pdo);

$message = '';
$messageType = '';
$applyResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $message = 'انتهت صلاحية الجلسة.';
        $messageType = 'error';
    } elseif (($_POST['confirm'] ?? '') !== 'yes') {
        $message = 'يجب تأكيد العملية.';
        $messageType = 'error';
    } else {
        acc_gl_ensure_schema($pdo);
        $applyResult = acc_return_vat_repost_all($pdo);
        if ($applyResult['fixed'] > 0 && $applyResult['errors'] === []) {
            flash_set(
                'success',
                'تم تحديث ' . $applyResult['fixed'] . ' قيد مردود. راجع «صافي الضريبة المستحقة على المبيعات والمشتريات».'
            );
            redirect(app_url('index.php?r=vat_returns_repost'));
        }
        if ($applyResult['fixed'] > 0 && $applyResult['errors'] !== []) {
            $message = 'تم ' . $applyResult['fixed'] . ' بنجاح. أخطاء متبقية: ' . implode('؛ ', $applyResult['errors']);
            $messageType = 'error';
        } elseif ($applyResult['fixed'] === 0 && $applyResult['errors'] === []) {
            $message = 'لا مردودات تحتاج تحديث.';
            $messageType = 'success';
        } elseif ($applyResult['errors'] !== []) {
            $message = 'أخطاء: ' . implode('؛ ', $applyResult['errors']);
            $messageType = 'error';
        }
    }
}

$scan = acc_return_vat_repost_scan($pdo);
$allRows = array_merge($scan['sale'], $scan['purchase']);
?>
<div class="card" style="max-width:52rem;">
    <h2 style="margin:0 0 0.75rem;font-size:1.1rem;">تحديث قيود ضريبة المردودات (آمن)</h2>

    <p class="muted" style="font-size:0.9rem;line-height:1.55;margin:0 0 1rem;">
        يُعاد بناء <strong>القيد المحاسبي فقط</strong> لمردودات البيع/الشراء القديمة لفصل الضريبة على
        حسابي 2003 و 1001004. لا يُلمس المستودع، ولا ذمم العملاء/الموردين التشغيلية، ولا فواتير البيع/الشراء.
    </p>

    <?php if ($message !== ''): ?>
        <p class="alert alert-<?= $messageType === 'error' ? 'error' : 'success' ?>" style="margin-bottom:1rem;">
            <?= esc($message) ?>
        </p>
    <?php endif; ?>

    <?php if ($applyResult !== null && $applyResult['fixed'] > 0): ?>
        <p class="alert alert-success">تم تحديث <?= (int) $applyResult['fixed'] ?> مردود.</p>
    <?php endif; ?>

    <p style="margin:0 0 0.75rem;">
        <strong>يحتاج تحديث:</strong> <?= (int) $scan['to_fix_count'] ?> مردود
        &nbsp;|&nbsp;
        <strong>الإجمالي المرحّل محاسبياً:</strong> <?= count($allRows) ?>
    </p>

    <?php if ($scan['to_fix_count'] > 0): ?>
        <table class="data-table" style="margin-bottom:1rem;font-size:0.88rem;">
            <thead>
            <tr>
                <th>النوع</th>
                <th>الرقم</th>
                <th>التاريخ</th>
                <th>ضريبة</th>
                <th>الحالة</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($allRows as $row): ?>
                <tr>
                    <td><?= esc((string) $row['type_label']) ?></td>
                    <td><code><?= esc((string) $row['return_no']) ?></code></td>
                    <td dir="ltr"><?= esc(format_date_dmY((string) $row['return_date'])) ?></td>
                    <td><?= esc(format_money((float) $row['tax_amount'])) ?></td>
                    <td><?= !empty($row['needs_repost'])
                        ? '<span style="color:#b42318">يحتاج تحديث</span>'
                        : esc((string) $row['reason']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <form method="post" action="<?= esc(app_url('index.php?r=vat_returns_repost')) ?>"
              onsubmit="return confirm('سيتم استبدال قيود المردودات المحددة فقط. هل أنت متأكد؟');">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="confirm" value="yes">
            <label style="display:flex;align-items:flex-start;gap:0.5rem;margin-bottom:0.75rem;font-size:0.9rem;">
                <input type="checkbox" required style="margin-top:0.2rem">
                <span>أفهم أن العملية تعدّل القيود المحاسبية للمردودات فقط (<?= (int) $scan['to_fix_count'] ?> مردود)،
                    وأنني راجعت القائمة أعلاه.</span>
            </label>
            <button type="submit" class="btn btn-primary">تنفيذ التحديث الآمن</button>
        </form>
    <?php else: ?>
        <p class="alert alert-success">كل المردودات المرحّلة محدّثة — لا حاجة لتنفيذ.</p>
        <a class="btn btn-secondary" href="<?= esc(app_url('index.php?r=report_vat_net_payable')) ?>">صافي الضريبة المستحقة على المبيعات والمشتريات</a>
    <?php endif; ?>

    <p class="muted" style="margin-top:1rem;font-size:0.82rem;">
        نصيحة: خذ نسخة احتياطية لقاعدة البيانات قبل التنفيذ. بعد التنفيذ افتح
        <a href="<?= esc(app_url('index.php?r=report_vat_net_payable')) ?>">صافي الضريبة المستحقة على المبيعات والمشتريات</a>
        للفترة الضريبية.
    </p>
</div>
