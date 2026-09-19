<?php
declare(strict_types=1);

require_once app_path('includes/crm_party_statement.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/customer_picker.php');
require_once app_path('includes/supplier_picker.php');
require_once app_path('includes/crm_sales_rep_schema.php');

$pdo = db();
crm_ledger_ensure_schema($pdo);
crm_supplier_ledger_ensure_schema($pdo);
crm_sales_rep_ensure_schema($pdo);

$activeRoute = (string) ($GLOBALS['activeRoute'] ?? 'report_party_statement');

$customers = $pdo->query(
    'SELECT id, code, name_ar FROM crm_customer WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$suppliers = $pdo->query(
    'SELECT id, code, name_ar FROM crm_supplier WHERE is_active = 1 ORDER BY name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$partyType = trim((string) ($_GET['party_type'] ?? ''));
if ($partyType !== 'supplier' && $partyType !== 'customer') {
    if ($activeRoute === 'report_supplier_statement') {
        $partyType = 'supplier';
    } else {
        $partyType = 'customer';
    }
}

$partyId = (int) ($_GET['party_id'] ?? 0);
if ($partyId < 1 && $partyType === 'customer') {
    $partyId = (int) ($_GET['customer_id'] ?? 0);
}
if ($partyId < 1 && $partyType === 'supplier') {
    $partyId = (int) ($_GET['supplier_id'] ?? 0);
}

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
if ($from === '') {
    $from = app_default_date_from();
}
if ($to === '') {
    $to = app_default_date_to();
}

$routeKey = 'report_party_statement';
$partyTypeLabel = $partyType === 'supplier' ? 'مورد' : 'عميل';
$reportTitle = 'كشف حساب ' . $partyTypeLabel;

$rows = [];
$partyName = '';
$partyCode = '';
$salesRepNames = '';
$openingBalance = 0.0;
$openingDebit = 0.0;
$openingCredit = 0.0;
$totalDebit = 0.0;
$totalCredit = 0.0;
$closingBalance = 0.0;
$showResult = false;
$err = '';
$submitted = isset($_GET['party_id']) || isset($_GET['customer_id']) || isset($_GET['supplier_id']);

if ($submitted) {
    if ($partyId < 1) {
        $err = $partyType === 'supplier' ? 'اختر المورد.' : 'اختر العميل.';
    } else {
        $fromIso = parse_date_to_iso($from);
        $toIso = parse_date_to_iso($to);
        if ($fromIso === null || $toIso === null) {
            $err = 'تاريخ البداية والنهاية غير صالحين (يوم-شهر-سنة).';
        } elseif ($fromIso > $toIso) {
            $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
        } else {
            $from = $fromIso;
            $to = $toIso;

            if ($partyType === 'supplier') {
                $st = $pdo->prepare('SELECT name_ar, code FROM crm_supplier WHERE id = ? LIMIT 1');
            } else {
                $st = $pdo->prepare('SELECT name_ar, code FROM crm_customer WHERE id = ? LIMIT 1');
            }
            $st->execute([$partyId]);
            $party = $st->fetch(PDO::FETCH_ASSOC);
            if (!$party) {
                $err = $partyType === 'supplier' ? 'المورد غير موجود.' : 'العميل غير موجود.';
            } else {
                $showResult = true;
                $partyTypeLabel = $partyType === 'supplier' ? 'مورد' : 'عميل';
                $reportTitle = 'كشف حساب ' . $partyTypeLabel;
                $partyName = (string) ($party['name_ar'] ?? '');
                $partyCode = (string) ($party['code'] ?? '');
                if ($partyType === 'customer') {
                    $salesRepNames = crm_customer_sales_rep_names($pdo, $partyId);
                }
                $built = crm_party_statement_build($pdo, $partyType, $partyId, $from, $to);
                $rows = $built['rows'];
                $openingBalance = $built['opening_balance'];
                $openingDebit = $built['opening_debit'];
                $openingCredit = $built['opening_credit'];
                $totalDebit = $built['total_debit'];
                $totalCredit = $built['total_credit'];
                $closingBalance = $built['closing_balance'];
            }
        }
    }
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$partyJsPath = app_path('assets/js/report-party-statement.js');
$partyJsUrl = app_url('assets/js/report-party-statement.js') . (is_file($partyJsPath) ? '?v=' . (string) filemtime($partyJsPath) : '');
$invCssPath = app_path('assets/css/sales-invoice.css');
$invCssUrl = app_url('assets/css/sales-invoice.css') . (is_file($invCssPath) ? '?v=' . (string) filemtime($invCssPath) : '');
$exportJsPath = app_path('assets/js/report-sales-export.js');
$exportJsUrl = app_url('assets/js/report-sales-export.js') . (is_file($exportJsPath) ? '?v=' . (string) filemtime($exportJsPath) : '');

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="' . esc($routeKey) . '"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($partyName) . '"';
    $pageDataAttrs .= ' data-from-dmy="' . esc(format_date_dmY($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc(format_date_dmY($to)) . '"';
}

$balanceLabel = static function (float $bal) use ($partyType): string {
    return crm_party_statement_balance_label($partyType, $bal);
};

$formRoute = $activeRoute;
if (!in_array($formRoute, ['report_party_statement', 'report_customer_statement', 'report_supplier_statement'], true)) {
    $formRoute = 'report_party_statement';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($invCssUrl) ?>">
<?php
customer_picker_enqueue_assets();
supplier_picker_enqueue_assets();
customer_picker_json_script($customers, 'party-stmt-customers-json');
supplier_picker_json_script($suppliers, 'party-stmt-suppliers-json');
?>

<div class="card report-sales-page party-stmt-page"<?= $pageDataAttrs ?>>

    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print" id="party-stmt-form">
        <input type="hidden" name="r" value="<?= esc($formRoute) ?>">
        <input type="hidden" name="party_type" id="party_type_hidden" value="<?= esc($partyType) ?>">
        <input type="hidden" name="party_id" id="party_id_field" value="<?= $partyId > 0 ? (int) $partyId : '' ?>">

        <div class="form-row">
            <label class="field">
                <span class="field-label">من تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="from" value="<?= esc(format_date_dmY($from)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
            </label>
            <label class="field">
                <span class="field-label">إلى تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="to" value="<?= esc(format_date_dmY($to)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
            </label>
        </div>

        <div class="form-row" style="margin-top:0.5rem;align-items:flex-end;">
            <fieldset class="field party-stmt-type-field" style="border:none;padding:0;margin:0;">
                <span class="field-label">نوع الطرف *</span>
                <div class="party-stmt-type-radios" style="display:flex;gap:1rem;flex-wrap:wrap;margin-top:0.35rem;">
                    <label style="display:flex;align-items:center;gap:0.35rem;cursor:pointer;">
                        <input type="radio" name="party_type_radio" value="customer" <?= $partyType === 'customer' ? 'checked' : '' ?>>
                        عميل
                    </label>
                    <label style="display:flex;align-items:center;gap:0.35rem;cursor:pointer;">
                        <input type="radio" name="party_type_radio" value="supplier" <?= $partyType === 'supplier' ? 'checked' : '' ?>>
                        مورد
                    </label>
                </div>
            </fieldset>
        </div>

        <div class="form-row" style="margin-top:0.5rem;">
            <?= customer_picker_field([
                'id' => 'party_stmt_cust_hidden',
                'name' => null,
                'value' => $partyType === 'customer' && $partyId > 0 ? $partyId : 0,
                'label' => 'العميل *',
                'wrapper_class' => 'field party-stmt-pick-customer',
                'wrapper_style' => 'flex:1 1 16rem' . ($partyType === 'supplier' ? ';display:none' : ''),
                'json_id' => 'party-stmt-customers-json',
            ]) ?>
            <?= supplier_picker_field([
                'id' => 'party_stmt_supp_hidden',
                'name' => null,
                'value' => $partyType === 'supplier' && $partyId > 0 ? $partyId : 0,
                'label' => 'المورد *',
                'wrapper_class' => 'field party-stmt-pick-supplier',
                'wrapper_style' => 'flex:1 1 16rem' . ($partyType === 'customer' ? ';display:none' : ''),
                'json_id' => 'party-stmt-suppliers-json',
            ]) ?>
        </div>

        <div style="margin-top:0.75rem;">
            <button class="btn btn-primary" type="submit">عرض كشف الحساب</button>
        </div>
    </form>

    <?php if ($showResult): ?>
        <div class="report-sales-result report-sales-print-area">
            <?= document_print_header_html($reportTitle, $pdo) ?>

            <div class="party-stmt-report-head">
                <p class="party-stmt-report-customer"><?= esc($partyName) ?></p>
                <?php if ($partyType === 'customer' && $salesRepNames !== ''): ?>
                    <p class="party-stmt-report-rep">المندوب: <?= esc($salesRepNames) ?></p>
                <?php endif; ?>
                <p class="party-stmt-report-dates">
                    <span>من تاريخ: <?= esc(format_date_dmY($from)) ?></span>
                    <span class="party-stmt-report-dates-sep">|</span>
                    <span>إلى تاريخ: <?= esc(format_date_dmY($to)) ?></span>
                </p>
            </div>

            <div class="report-sales-table-wrap">
                <table class="data-table report-sales-table party-stmt-table">
                    <thead>
                    <tr>
                        <th class="col-date">التاريخ</th>
                        <th class="col-desc">الوصف</th>
                        <th class="col-doc">الرقم</th>
                        <th class="col-money">مدين</th>
                        <th class="col-money">دائن</th>
                        <th class="col-money">الرصيد</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="6" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد حركات في هذه الفترة.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= esc(format_date_dmY($r['date'])) ?></td>
                            <td class="col-desc"><?= esc($r['description']) ?></td>
                            <td class="party-stmt-doc-cell">
                                <?php if (($r['ref_no'] ?? '') !== ''): ?>
                                    <span class="party-stmt-doc-kind"><?= esc($r['doc_label'] ?? 'رقم المستند') ?></span>
                                    <span class="party-stmt-doc-no-wrap">
                                        <code class="party-stmt-doc-no"><?= esc($r['ref_no']) ?></code>
                                        <?php if (trim((string) ($r['doc_hint'] ?? '')) !== ''): ?>
                                            <span class="party-stmt-doc-hint"><?= esc((string) $r['doc_hint']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-money"><?= $r['debit'] > 0 ? esc(format_money($r['debit'])) : '—' ?></td>
                            <td class="col-money"><?= $r['credit'] > 0 ? esc(format_money($r['credit'])) : '—' ?></td>
                            <td class="col-money" style="font-weight:700;"><?= esc(format_money($r['balance'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php
                    $footerDebit = $openingDebit + $totalDebit;
                    $footerCredit = $openingCredit + $totalCredit;
                    ?>
                    <tfoot class="party-stmt-tfoot">
                        <tr class="party-stmt-totals">
                            <td colspan="3"><strong>المجموع</strong></td>
                            <td class="col-money">
                                <span class="party-stmt-foot-label">مجموع المدين</span>
                                <strong class="party-stmt-foot-value"><?= esc(format_money($footerDebit)) ?></strong>
                            </td>
                            <td class="col-money">
                                <span class="party-stmt-foot-label">مجموع الدائن</span>
                                <strong class="party-stmt-foot-value"><?= esc(format_money($footerCredit)) ?></strong>
                            </td>
                            <td class="col-money">
                                <span class="party-stmt-foot-label">الرصيد النهائي</span>
                                <strong class="party-stmt-foot-value"><?= esc(format_money($closingBalance)) ?></strong>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="<?= esc($partyJsUrl) ?>" defer></script>
<script src="<?= esc($exportJsUrl) ?>" defer></script>
