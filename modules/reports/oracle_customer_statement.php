<?php
declare(strict_types=1);

/**
 * كشف حساب تفصيلي للعميل من Oracle (قراءة فقط) — مطابق لنظام المحاسبة والذمم.
 */

require_once app_path('includes/oracle_statement.php');
require_once app_path('includes/oracle_customer_sync.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/customer_picker.php');

$pdo = db();
oracle_customer_schema_ensure($pdo);

$routeKey = 'report_oracle_customer_statement';
$listCustomersUrl = app_url('index.php?r=customers');

$customers = $pdo->query(
    "SELECT id, code, name_ar,
            COALESCE(NULLIF(TRIM(oracle_key), ''), code) AS acc_no
     FROM crm_customer
     WHERE is_active = 1
       AND (
         (oracle_key IS NOT NULL AND TRIM(oracle_key) <> '')
         OR code LIKE '112%'
       )
     ORDER BY name_ar"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$customerId = (int) ($_GET['customer_id'] ?? 0);
$accountOverride = preg_replace('/\D+/', '', (string) ($_GET['account'] ?? '')) ?? '';
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
if ($from === '') {
    $from = '2020-01-01';
}
if ($to === '') {
    $to = app_default_date_to();
}

$fmtAmt = static function (float $n): string {
    return number_format(round($n, 3), 3, '.', ',');
};
$fmtDate = static function (string $iso): string {
    $iso = trim($iso);
    if ($iso === '') {
        return '';
    }
    if (function_exists('format_date_dmY')) {
        $d = format_date_dmY($iso);
        if ($d !== '') {
            return $d;
        }
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    return $iso;
};

$result = null;
$err = '';
$showResult = false;
$partyName = '';
$partyCode = '';
$accountNo = '';

$submitted = isset($_GET['customer_id']) || (isset($_GET['account']) && trim((string) $_GET['account']) !== '');

if ($submitted) {
    $fromIso = function_exists('parse_date_to_iso') ? parse_date_to_iso($from) : null;
    $toIso = function_exists('parse_date_to_iso') ? parse_date_to_iso($to) : null;
    // إذا كان الإدخال ISO أصلاً
    if ($fromIso === null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $fromIso = $from;
    }
    if ($toIso === null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $toIso = $to;
    }
    // من حقول dmy المعروضة
    if ($fromIso === null && preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $from, $m)) {
        $fromIso = $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    if ($toIso === null && preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $to, $m)) {
        $toIso = $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    if ($fromIso === null || $toIso === null) {
        $err = 'تاريخ البداية والنهاية غير صالحين.';
    } elseif ($fromIso > $toIso) {
        $err = 'تاريخ البداية يجب أن يكون قبل أو يساوي تاريخ النهاية.';
    } else {
        $from = $fromIso;
        $to = $toIso;

        if ($accountOverride !== '') {
            $accountNo = $accountOverride;
            $st = $pdo->prepare(
                "SELECT id, code, name_ar FROM crm_customer
                 WHERE code = ? OR oracle_key = ? LIMIT 1"
            );
            $st->execute([$accountNo, $accountNo]);
            $party = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($party) {
                $customerId = (int) $party['id'];
                $partyName = (string) ($party['name_ar'] ?? '');
                $partyCode = (string) ($party['code'] ?? $accountNo);
            } else {
                $partyCode = $accountNo;
            }
        } elseif ($customerId > 0) {
            $st = $pdo->prepare(
                "SELECT id, code, name_ar, oracle_key FROM crm_customer WHERE id = ? LIMIT 1"
            );
            $st->execute([$customerId]);
            $party = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$party) {
                $err = 'العميل غير موجود.';
            } else {
                $partyName = (string) ($party['name_ar'] ?? '');
                $partyCode = (string) ($party['code'] ?? '');
                $accountNo = trim((string) ($party['oracle_key'] ?? ''));
                if ($accountNo === '') {
                    $accountNo = preg_replace('/\D+/', '', $partyCode) ?? '';
                }
            }
        } else {
            $err = 'اختر العميل أو أدخل رقم الحساب.';
        }

        if ($err === '' && $accountNo !== '') {
            $result = oracle_fetch_customer_statement($accountNo, $from, $to);
            if (!$result['ok']) {
                $err = (string) ($result['message'] ?? 'تعذر جلب الكشف من Oracle.');
            } else {
                $showResult = true;
                if ($partyName === '' && ($result['name'] ?? '') !== '') {
                    $partyName = (string) $result['name'];
                }
            }
        } elseif ($err === '') {
            $err = 'رقم الحساب غير متوفر لهذا العميل.';
        }
    }
}

$cssPath = app_path('assets/css/report-sales.css');
$cssUrl = app_url('assets/css/report-sales.css') . (is_file($cssPath) ? '?v=' . (string) filemtime($cssPath) : '');
$oraCssPath = app_path('assets/css/report-oracle-statement.css');
$oraCssUrl = app_url('assets/css/report-oracle-statement.css')
    . (is_file($oraCssPath) ? '?v=' . (string) filemtime($oraCssPath) : '');

$reportTitle = 'كشف حساب تفصيلي';
$printCompany = '';
if (function_exists('document_print_company_name')) {
    // optional
}

$pageDataAttrs = ' data-report-title="' . esc($reportTitle) . '"';
$pageDataAttrs .= ' data-report-route="' . esc($routeKey) . '"';
if ($showResult) {
    $pageDataAttrs .= ' data-export-label="' . esc($partyName !== '' ? $partyName : $accountNo) . '"';
    $pageDataAttrs .= ' data-from-dmy="' . esc($fmtDate($from)) . '"';
    $pageDataAttrs .= ' data-to-dmy="' . esc($fmtDate($to)) . '"';
}
?>
<link rel="stylesheet" href="<?= esc($cssUrl) ?>">
<link rel="stylesheet" href="<?= esc($oraCssUrl) ?>">
<?php
customer_picker_enqueue_assets();
customer_picker_json_script($customers, 'ora-stmt-customers-json');
?>

<div class="card report-sales-page ora-stmt-page"<?= $pageDataAttrs ?>>
    <?php if ($err !== ''): ?>
        <div class="alert alert-error no-print" style="margin-bottom:1rem;"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= esc(app_url('index.php')) ?>" class="report-sales-filters no-print" id="ora-stmt-form">
        <input type="hidden" name="r" value="<?= esc($routeKey) ?>">

        <div class="form-row">
            <label class="field">
                <span class="field-label">من تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="from"
                       value="<?= esc($fmtDate($from)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
            </label>
            <label class="field">
                <span class="field-label">إلى تاريخ *</span>
                <input class="input js-date-dmy" type="text" name="to"
                       value="<?= esc($fmtDate($to)) ?>"
                       placeholder="يوم-شهر-سنة" dir="ltr" autocomplete="off" inputmode="numeric" required>
            </label>
        </div>

        <div class="form-row" style="margin-top:0.5rem;">
            <?= customer_picker_field([
                'id' => 'ora_stmt_cust',
                'name' => 'customer_id',
                'value' => $customerId > 0 ? $customerId : 0,
                'label' => 'العميل (Oracle 112…)',
                'wrapper_class' => 'field',
                'wrapper_style' => 'flex:1 1 18rem',
                'json_id' => 'ora-stmt-customers-json',
            ]) ?>
            <label class="field" style="flex:0 1 12rem;">
                <span class="field-label">أو رقم الحساب</span>
                <input class="input" type="text" name="account" dir="ltr"
                       value="<?= esc($accountOverride !== '' ? $accountOverride : ($showResult ? $accountNo : '')) ?>"
                       placeholder="11200911" autocomplete="off">
            </label>
        </div>

        <div style="margin-top:0.75rem;display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
            <button class="btn btn-primary" type="submit">عرض الكشف من Oracle</button>
            <a class="btn btn-ghost" href="<?= esc($listCustomersUrl) ?>">قائمة العملاء</a>
            <?php if ($showResult): ?>
                <button class="btn btn-secondary no-print" type="button" onclick="window.print()">طباعة</button>
            <?php endif; ?>
        </div>
        <p class="muted" style="margin-top:0.5rem;font-size:0.9rem;">
            قراءة مباشرة من <code>GLVODMF</code> + الشيكات من <code>GLCHEQF</code> — بدون تعديل على Oracle.
        </p>
    </form>

    <?php if ($showResult && is_array($result)): ?>
        <?php
        $lines = is_array($result['lines'] ?? null) ? $result['lines'] : [];
        $cheques = is_array($result['cheques'] ?? null) ? $result['cheques'] : [];
        $totalDebit = (float) ($result['total_debit'] ?? 0);
        $totalCredit = (float) ($result['total_credit'] ?? 0);
        $balance = (float) ($result['balance'] ?? 0);
        $oraName = (string) ($result['name'] ?? '');
        if ($partyName === '' && $oraName !== '') {
            $partyName = $oraName;
        }
        $nowDate = date('d-m-Y');
        $nowTime = date('H:i:s');
        ?>
        <div class="report-sales-result report-sales-print-area ora-stmt-print">
            <div class="ora-stmt-header">
                <div class="ora-stmt-header__sys">نظام المحاسبة والذمم</div>
                <div class="ora-stmt-header__title"><?= esc($reportTitle) ?></div>
                <div class="ora-stmt-header__meta">
                    <span>التاريخ: <?= esc($nowDate) ?></span>
                    <span>الوقت: <?= esc($nowTime) ?></span>
                </div>
                <div class="ora-stmt-header__range">
                    من <?= esc($fmtDate($from)) ?>
                    إلى <?= esc($fmtDate($to)) ?>
                </div>
                <div class="ora-stmt-header__acc">
                    <div><strong>الحساب:</strong>
                        <span dir="ltr"><?= esc($accountNo) ?></span>
                        <?php if ($partyName !== ''): ?>
                            — <?= esc($partyName) ?>
                        <?php endif; ?>
                    </div>
                    <div><strong>العملة:</strong> محلية</div>
                </div>
            </div>

            <div class="report-sales-table-wrap">
                <table class="data-table report-sales-table ora-stmt-table">
                    <thead>
                    <tr>
                        <th>رقم السند</th>
                        <th>نوع السند</th>
                        <th>تاريخ الحركة</th>
                        <th class="col-money">مدين</th>
                        <th class="col-money">دائن</th>
                        <th class="col-money">الرصيد</th>
                        <th>البيان</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($lines === []): ?>
                        <tr>
                            <td colspan="7" class="muted" style="text-align:center;padding:1.25rem;">
                                لا توجد حركات في هذه الفترة.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($lines as $ln): ?>
                        <tr class="<?= !empty($ln['is_opening']) ? 'ora-stmt-opening' : '' ?>">
                            <td dir="ltr"><?= esc((string) ($ln['doc_no'] ?? '')) ?></td>
                            <td><?= esc((string) ($ln['doc_type'] ?? '')) ?></td>
                            <td dir="ltr"><?= esc($fmtDate((string) ($ln['trn_date'] ?? ''))) ?></td>
                            <td class="col-money" dir="ltr">
                                <?= ((float) ($ln['debit'] ?? 0)) > 0.0000001 ? esc($fmtAmt((float) $ln['debit'])) : '' ?>
                            </td>
                            <td class="col-money" dir="ltr">
                                <?= ((float) ($ln['credit'] ?? 0)) > 0.0000001 ? esc($fmtAmt((float) $ln['credit'])) : '' ?>
                            </td>
                            <td class="col-money" dir="ltr"><?= esc($fmtAmt((float) ($ln['balance'] ?? 0))) ?></td>
                            <td class="ora-stmt-desc"><?= esc((string) ($ln['description'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                    <tr class="ora-stmt-totals">
                        <td colspan="3">المجموع / الرصيد الختامي</td>
                        <td class="col-money" dir="ltr"><?= esc($fmtAmt($totalDebit)) ?></td>
                        <td class="col-money" dir="ltr"><?= esc($fmtAmt($totalCredit)) ?></td>
                        <td class="col-money" dir="ltr"><strong><?= esc($fmtAmt($balance)) ?></strong></td>
                        <td></td>
                    </tr>
                    </tfoot>
                </table>
            </div>

            <div class="ora-stmt-cheques">
                <h3 class="ora-stmt-cheques__title">الشيكات قيد التحصيل</h3>
                <div class="report-sales-table-wrap">
                    <table class="data-table report-sales-table ora-stmt-chq-table">
                        <thead>
                        <tr>
                            <th>الشيك</th>
                            <th>التاريخ</th>
                            <th class="col-money">قيمة الشيك</th>
                            <th>تاريخ القبض</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($cheques === []): ?>
                            <tr>
                                <td colspan="4" class="muted" style="text-align:center;">لا توجد شيكات قيد التحصيل.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($cheques as $ch): ?>
                            <tr>
                                <td dir="ltr"><?= esc((string) ($ch['chq_no'] ?? '')) ?></td>
                                <td dir="ltr"><?= esc($fmtDate((string) ($ch['chq_date'] ?? ''))) ?></td>
                                <td class="col-money" dir="ltr"><?= esc($fmtAmt((float) ($ch['amount'] ?? 0))) ?></td>
                                <td dir="ltr"><?= esc($fmtDate((string) ($ch['receipt_date'] ?? ''))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                        <tr>
                            <td colspan="2">مجموع الشيكات قيد التحصيل</td>
                            <td class="col-money" dir="ltr"><strong><?= esc($fmtAmt((float) ($result['cheque_total'] ?? 0))) ?></strong></td>
                            <td></td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
