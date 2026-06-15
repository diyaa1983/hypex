<?php
declare(strict_types=1);

require_once app_path('includes/acc_report.php');
require_once app_path('includes/acc_account_tree.php');

$pdo = db();
acc_gl_ensure_schema($pdo);

$dateFrom = parse_date_to_iso(trim((string) ($_GET['date_from'] ?? ''))) ?? app_default_date_from();
$dateTo = parse_date_to_iso(trim((string) ($_GET['date_to'] ?? ''))) ?? app_default_date_to();
$statusFilter = trim((string) ($_GET['status'] ?? 'posted'));
$detailId = (int) ($_GET['entry_id'] ?? 0);

if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$entries = acc_report_journal_entries(
    $pdo,
    $dateFrom,
    $dateTo,
    $statusFilter !== '' ? $statusFilter : null
);
$detailLines = $detailId > 0 ? acc_report_journal_lines($pdo, $detailId) : [];
$detailHeader = null;
if ($detailId > 0) {
    $st = $pdo->prepare(
        'SELECT id, entry_no, entry_date, description_ar, status, source, ref_type, ref_id
         FROM acc_journal_entry WHERE id = ? LIMIT 1'
    );
    $st->execute([$detailId]);
    $detailHeader = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$cssPath = app_path('assets/css/report-acc.css');
$cssUrl = app_url('assets/css/report-acc.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<div class="card report-acc-wrap">
    <h2 class="report-acc-title">تقرير القيود المحاسبية</h2>
    <p class="muted report-acc-sub">جميع القيود مع إمكانية فتح التفاصيل والمستند المصدر ودفتر الحساب.</p>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="form-row report-acc-filters">
        <input type="hidden" name="r" value="report_journal">
        <label class="field">
            <span class="field-label">من</span>
            <input class="input js-date-dmy" type="text" name="date_from" value="<?= esc(format_date_dmY($dateFrom)) ?>" dir="ltr">
        </label>
        <label class="field">
            <span class="field-label">إلى</span>
            <input class="input js-date-dmy" type="text" name="date_to" value="<?= esc(format_date_dmY($dateTo)) ?>" dir="ltr">
        </label>
        <label class="field">
            <span class="field-label">الحالة</span>
            <select class="input" name="status">
                <option value="">الكل</option>
                <option value="posted"<?= $statusFilter === 'posted' ? ' selected' : '' ?>>مرحّل</option>
                <option value="draft"<?= $statusFilter === 'draft' ? ' selected' : '' ?>>مسودة</option>
            </select>
        </label>
        <div class="field" style="align-self:flex-end;">
            <button type="submit" class="btn btn-primary btn-sm">عرض</button>
        </div>
    </form>

    <?php if ($detailHeader && $detailLines):
        $refType = (string) ($detailHeader['ref_type'] ?? '');
        $refId = (int) ($detailHeader['ref_id'] ?? 0);
        $refUrl = ($detailHeader['source'] ?? '') === 'auto' && $refType !== '' && $refId > 0
            ? acc_report_ref_url($refType, $refId)
            : null;
        $voucherUrl = acc_report_journal_voucher_url($detailId, (string) ($detailHeader['entry_no'] ?? ''));
        ?>
    <div class="card report-acc-detail">
        <h3>تفاصيل <?= esc((string) $detailHeader['entry_no']) ?> — <?= esc(format_date_dmY((string) $detailHeader['entry_date'])) ?></h3>
        <p class="muted"><?= esc((string) ($detailHeader['description_ar'] ?? '')) ?></p>
        <p class="report-acc-links no-print">
            <a class="btn btn-secondary btn-sm" href="<?= esc($voucherUrl) ?>">فتح القيد</a>
            <?php if ($refUrl): ?>
                <a class="btn btn-primary btn-sm" href="<?= esc($refUrl) ?>"><?= esc(acc_report_ref_type_label($refType)) ?></a>
            <?php endif; ?>
        </p>
        <table class="data-table report-acc-table">
            <thead><tr><th>حساب</th><th>مدين</th><th>دائن</th><th>بيان</th><th class="no-print"></th></tr></thead>
            <tbody>
            <?php foreach ($detailLines as $ln):
                $accId = (int) ($ln['account_id'] ?? 0);
                ?>
                <tr>
                    <td><?= esc(acc_account_format_code((string) $ln['code']) . ' — ' . (string) $ln['name_ar']) ?></td>
                    <td class="col-money"><?= (float) $ln['debit'] > 0 ? esc(format_money((float) $ln['debit'])) : '—' ?></td>
                    <td class="col-money"><?= (float) $ln['credit'] > 0 ? esc(format_money((float) $ln['credit'])) : '—' ?></td>
                    <td><?= esc((string) ($ln['memo'] ?? '')) ?></td>
                    <td class="no-print">
                        <?php if ($accId > 0): ?>
                            <a class="btn btn-secondary btn-sm" href="<?= esc(acc_report_general_ledger_url($accId, $dateFrom, $dateTo)) ?>">دفتر</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=report_journal&date_from=' . rawurlencode(format_date_dmY($dateFrom)) . '&date_to=' . rawurlencode(format_date_dmY($dateTo)) . '&status=' . rawurlencode($statusFilter))) ?>">إغلاق التفاصيل</a>
    </div>
    <?php endif; ?>

    <div class="table-wrap report-sales-print-area">
        <table class="data-table report-acc-table">
            <thead>
            <tr>
                <th>رقم القيد</th>
                <th>التاريخ</th>
                <th>البيان</th>
                <th>المصدر</th>
                <th>المرجع</th>
                <th class="col-money">مدين</th>
                <th class="col-money">دائن</th>
                <th class="no-print"></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$entries): ?>
                <tr><td colspan="8" class="muted" style="text-align:center;">لا قيود في الفترة.</td></tr>
            <?php endif; ?>
            <?php foreach ($entries as $e):
                $eid = (int) ($e['id'] ?? 0);
                $refType = (string) ($e['ref_type'] ?? '');
                $refId = (int) ($e['ref_id'] ?? 0);
                $refUrl = ($e['source'] ?? '') === 'auto' && $refType !== '' && $refId > 0
                    ? acc_report_ref_url($refType, $refId)
                    : null;
                ?>
                <tr>
                    <td><code><?= esc((string) $e['entry_no']) ?></code></td>
                    <td><?= esc(format_date_dmY((string) $e['entry_date'])) ?></td>
                    <td><?= esc((string) ($e['description_ar'] ?? '')) ?></td>
                    <td>
                        <span class="badge"><?= esc(acc_journal_status_label((string) $e['status'])) ?></span>
                        <?php if ((string) ($e['source'] ?? '') === 'auto'): ?>
                            <span class="badge" title="من ترحيل مستند">تلقائي</span>
                        <?php else: ?>
                            <span class="badge">يدوي</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($refUrl): ?>
                            <a href="<?= esc($refUrl) ?>"><?= esc(acc_report_ref_type_label($refType)) ?> #<?= $refId ?></a>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-money"><?= esc(format_money((float) $e['total_debit'])) ?></td>
                    <td class="col-money"><?= esc(format_money((float) $e['total_credit'])) ?></td>
                    <td class="no-print report-acc-links">
                        <a class="btn btn-secondary btn-sm" href="<?= esc(app_url('index.php?r=report_journal&entry_id=' . $eid . '&date_from=' . rawurlencode(format_date_dmY($dateFrom)) . '&date_to=' . rawurlencode(format_date_dmY($dateTo)) . '&status=' . rawurlencode($statusFilter))) ?>">تفاصيل</a>
                        <a class="btn btn-secondary btn-sm" href="<?= esc(acc_report_journal_voucher_url($eid, (string) $e['entry_no'])) ?>">القيد</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
