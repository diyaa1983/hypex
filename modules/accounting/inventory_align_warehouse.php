<?php
declare(strict_types=1);

require_once app_path('includes/acc_inventory_align_warehouse.php');

if (!user_can_action('action_inventory_align_warehouse')) {
    flash_set('error', 'ليس لديك صلاحية مواءمة المخزون.');
    redirect(app_url('index.php?r=dashboard'));
}

$pdo = db();
acc_gl_ensure_schema($pdo);

$dateFrom = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? $_POST['date_from'] ?? '')))
    ?? app_default_date_from();
$asOf = parse_date_to_iso(trim((string) ($_GET['as_of'] ?? $_POST['as_of'] ?? '')))
    ?? app_default_date_to();
if ($dateFrom > $asOf) {
    [$dateFrom, $asOf] = [$asOf, $dateFrom];
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $message = 'انتهت صلاحية الجلسة.';
        $messageType = 'error';
    } elseif (($_POST['confirm'] ?? '') !== 'yes') {
        $message = 'يجب تأكيد العملية.';
        $messageType = 'error';
    } elseif (($_POST['action'] ?? '') === 'undo_reconciles') {
        $res = acc_inventory_undo_all_reconciles($pdo);
        if (!$res['ok']) {
            $message = $res['error'] ?? 'فشل إلغاء التسويات.';
            $messageType = 'error';
        } else {
            flash_set(
                'success',
                'تم إلغاء ' . (int) $res['removed'] . ' قيد تسوية مخزون. عاد رصيد المخزون في الميزانية كما قبل التسوية (يُزال المبلغ من حساب المصروفات المربوط).'
            );
            redirect(app_url(
                'index.php?r=inventory_align_warehouse&date_from='
                . rawurlencode(format_date_dmY($dateFrom))
                . '&as_of=' . rawurlencode(format_date_dmY($asOf))
            ));
        }
    } elseif (($_POST['action'] ?? '') === 'match_all') {
        $postFrom = parse_date_to_iso(trim((string) ($_POST['date_from'] ?? ''))) ?? $dateFrom;
        $postTo = parse_date_to_iso(trim((string) ($_POST['as_of'] ?? ''))) ?? $asOf;
        if ($postFrom > $postTo) {
            [$postFrom, $postTo] = [$postTo, $postFrom];
        }
        $dateFrom = $postFrom;
        $asOf = $postTo;

        $res = acc_inventory_match_all_dates($pdo, $dateFrom, $asOf);
        if (!$res['ok']) {
            $message = $res['error'] ?? 'فشلت المطابقة.';
            $messageType = 'error';
        } else {
            flash_set(
                'success',
                'تمت المطابقة: ' . (int) $res['posted'] . ' قيد تسوية على ' . (int) $res['days'] . ' يوماً.'
            );
            redirect(app_url(
                'index.php?r=report_trial_balance&view=detail&date_from='
                . rawurlencode(format_date_dmY($dateFrom))
                . '&date_to=' . rawurlencode(format_date_dmY($asOf))
            ));
        }
    }
}

$summary = acc_inventory_align_summary($pdo, $asOf);
$gap = (float) $summary['gap'];

$reconcileCount = 0;
try {
    $st = $pdo->query(
        "SELECT COUNT(*) FROM acc_journal_entry
         WHERE ref_type = 'inventory_reconcile' AND status = 'posted'"
    );
    $reconcileCount = (int) ($st->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $reconcileCount = 0;
}
?>
<div class="card" style="max-width:52rem;">
    <h2 style="margin:0 0 0.75rem;font-size:1.15rem;">مطابقة المخزون مع المستودع</h2>

    <?php if ($message !== ''): ?>
        <p class="alert alert-<?= $messageType === 'error' ? 'error' : 'success' ?>" style="margin-bottom:1rem;">
            <?= esc($message) ?>
        </p>
    <?php endif; ?>

    <?php if ($reconcileCount > 0): ?>
        <div style="padding:1rem;border:1px solid #f5c2c2;border-radius:8px;background:#fff5f5;margin-bottom:1rem;">
            <p style="margin:0 0 0.75rem;font-size:0.92rem;line-height:1.55;">
                يوجد <strong><?= (int) $reconcileCount ?></strong> قيد تسوية مخزون (مثل المبلغ على حساب 5120 بتاريخ 30-05).
                لإرجاع المخزون في الميزانية <strong>كما كان قبل التسوية</strong> وإزالة ذلك المبلغ من المصروفات:
            </p>
            <form method="post" action="<?= esc(app_url('index.php?r=inventory_align_warehouse')) ?>"
                  onsubmit="return confirm('إلغاء كل قيود تسوية المخزون. المتابعة؟');">
                <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
                <input type="hidden" name="confirm" value="yes">
                <input type="hidden" name="action" value="undo_reconciles">
                <input type="hidden" name="date_from" value="<?= esc(format_date_dmY($dateFrom)) ?>">
                <input type="hidden" name="as_of" value="<?= esc(format_date_dmY($asOf)) ?>">
                <button type="submit" class="btn btn-primary">
                    إلغاء تسويات المخزون (إرجاع الرصيد السابق)
                </button>
            </form>
        </div>
    <?php else: ?>
        <p class="alert alert-success" style="margin-bottom:1rem;">
            لا توجد قيود تسوية مخزون مرحّلة — رصيد المخزون غير مُعدَّل بتسوية.
        </p>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="form-row no-print" style="margin-bottom:1rem;align-items:flex-end;flex-wrap:wrap;gap:0.5rem;">
        <input type="hidden" name="r" value="inventory_align_warehouse">
        <label class="field">
            <span class="field-label">من تاريخ</span>
            <input class="input js-date-dmy" type="text" name="date_from" value="<?= esc(format_date_dmY($dateFrom)) ?>" dir="ltr" autocomplete="off">
        </label>
        <label class="field">
            <span class="field-label">إلى تاريخ</span>
            <input class="input js-date-dmy" type="text" name="as_of" value="<?= esc(format_date_dmY($asOf)) ?>" dir="ltr" autocomplete="off">
        </label>
        <button type="submit" class="btn btn-secondary btn-sm">عرض الفرق (للمراجعة فقط)</button>
    </form>

    <table class="data-table" style="max-width:36rem;margin-bottom:1rem;">
        <tbody>
        <tr>
            <td>رصيد المخزون في الدفتر</td>
            <td class="col-money" style="text-align:end"><strong><?= esc(format_money((float) $summary['gl_balance'])) ?></strong></td>
        </tr>
        <tr>
            <td>قيمة المستودعات (مرجع)</td>
            <td class="col-money" style="text-align:end"><strong><?= esc(format_money((float) $summary['warehouse_value'])) ?></strong></td>
        </tr>
        <tr>
            <td>الفرق</td>
            <td class="col-money" style="text-align:end"><?= esc(format_money($gap)) ?></td>
        </tr>
        </tbody>
    </table>

    <details class="no-print" style="margin-top:0.5rem;">
        <summary class="muted" style="cursor:pointer;font-size:0.88rem;">مطابقة تلقائية (اختياري — لاحقاً)</summary>
        <p class="muted" style="margin:0.5rem 0;font-size:0.85rem;">
            يُنشئ قيود تسوية على حساب <code>misc_expense</code> (مثل 5120). استخدمه فقط عند الرغبة بمطابقة الدفتر مع المستودع.
        </p>
        <?php if ($summary['cogs_enabled'] && $summary['misc_mapped']): ?>
        <form method="post" action="<?= esc(app_url('index.php?r=inventory_align_warehouse')) ?>"
              onsubmit="return confirm('تسوية المخزون لكل أيام الفترة. المتابعة؟');">
            <input type="hidden" name="_csrf" value="<?= esc(csrf_token()) ?>">
            <input type="hidden" name="confirm" value="yes">
            <input type="hidden" name="action" value="match_all">
            <input type="hidden" name="date_from" value="<?= esc(format_date_dmY($dateFrom)) ?>">
            <input type="hidden" name="as_of" value="<?= esc(format_date_dmY($asOf)) ?>">
            <button type="submit" class="btn btn-secondary btn-sm">تطابق المخزون (كل التواريخ)</button>
        </form>
        <?php endif; ?>
    </details>

    <p style="margin-top:1rem;">
        <a class="btn btn-secondary" href="<?= esc(app_url('index.php?r=report_balance_sheet')) ?>">الميزانية العمومية</a>
        <a class="btn btn-secondary" href="<?= esc(app_url('index.php?r=report_trial_balance&view=detail')) ?>">ميزان المراجعة</a>
    </p>
</div>
