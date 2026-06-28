<?php
declare(strict_types=1);

require_once app_path('includes/fin_outgoing_check_register.php');
require_once app_path('includes/document_header.php');
require_once app_path('includes/arabic_tafqit.php');

function fin_outgoing_check_print_stylesheet_url(): string
{
    $path = app_path('assets/css/fin-outgoing-check-print.css');

    return app_url('assets/css/fin-outgoing-check-print.css')
        . (is_file($path) ? '?v=' . (string) filemtime($path) : '');
}

function fin_outgoing_check_print_document_html(string $contentHtml, string $pageTitle): string
{
    $cssUrl = fin_outgoing_check_print_stylesheet_url();

    return '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8">'
        . '<title>' . esc($pageTitle) . '</title>'
        . '<link rel="stylesheet" href="' . esc($cssUrl) . '">'
        . '<style>@page{size:17.8cm 8.9cm;margin:0.35cm;}html,body{margin:0;padding:0;}</style>'
        . '</head><body class="bank-check-page">'
        . $contentHtml
        . '</body></html>';
}

/**
 * @return array{html:string, title:string}
 */
function fin_outgoing_check_print_single_build(PDO $pdo, int $checkId): array
{
    $row = fin_outgoing_check_register_load_one($pdo, $checkId);
    if ($row === null) {
        return ['html' => '<p>الشيك غير موجود.</p>', 'title' => 'شيك صادر'];
    }

    $registerNo = (string) ($row['register_no'] ?? '');
    $title = 'شيك صادر' . ($registerNo !== '' ? ' — ' . $registerNo : '');
    $amount = (float) ($row['check_amount'] ?? 0);
    $amountWords = arabic_tafqit_amount($amount, $pdo);
    $amountFigures = format_amount($amount, null, false);
    $checkNo = trim((string) ($row['check_no'] ?? ''));
    $bankName = trim((string) ($row['bank_name'] ?? ''));
    $dueDate = trim((string) ($row['due_date'] ?? ''));
    $voucherDate = format_date_dmY((string) ($row['voucher_date'] ?? ''));
    $checkDate = $dueDate !== '' ? format_date_dmY($dueDate) : $voucherDate;
    $partyName = trim((string) ($row['party_name'] ?? ''));
    if ($partyName === '' || $partyName === '—') {
        $partyName = '—';
    }

    $brand = document_header_brand($pdo);
    $companyName = trim((string) ($brand['company_name_ar'] ?? ''));
    $logoUrl = (string) ($brand['logo_url'] ?? '');

    $refParts = [];
    if ($registerNo !== '') {
        $refParts[] = 'س. ' . $registerNo;
    }
    $voucherNo = trim((string) ($row['voucher_no'] ?? ''));
    if ($voucherNo !== '') {
        $refParts[] = 'سند ' . $voucherNo;
    }
    $refLine = implode(' | ', $refParts);

    $micrLine = '';
    if ($checkNo !== '') {
        $micrLine = '⑆' . preg_replace('/\D+/', '', $checkNo) . '⑆ ' . str_replace('.', '', $amountFigures) . '⑈';
    }

    ob_start();
    ?>
    <div class="bank-check-slip">
        <div class="bank-check-slip__frame">
            <header class="bank-check-slip__head">
                <div class="bank-check-slip__drawer">
                    <?php if ($logoUrl !== ''): ?>
                        <div class="bank-check-slip__drawer-logo">
                            <img src="<?= esc($logoUrl) ?>" alt="">
                        </div>
                    <?php endif; ?>
                    <?php if ($companyName !== ''): ?>
                        <div class="bank-check-slip__drawer-name"><?= esc($companyName) ?></div>
                    <?php endif; ?>
                </div>
                <div class="bank-check-slip__bank-block">
                    <div class="bank-check-slip__bank-name"><?= esc($bankName !== '' ? $bankName : '—') ?></div>
                    <div class="bank-check-slip__meta-grid">
                        <span class="lbl">التاريخ</span>
                        <span class="val"><?= esc($checkDate) ?></span>
                        <span class="lbl">رقم الشيك</span>
                        <span class="val"><?= esc($checkNo !== '' ? $checkNo : '—') ?></span>
                    </div>
                </div>
            </header>

            <div class="bank-check-slip__row bank-check-slip__payee">
                <span class="lbl">
                    ادفعوا لأمر
                    <span class="lbl-en">Pay to the order of</span>
                </span>
                <span class="bank-check-slip__line"><?= esc($partyName) ?></span>
            </div>

            <div class="bank-check-slip__amount-row">
                <div class="bank-check-slip__words">
                    <span class="lbl">
                        مبلغ وقدره
                        <span class="lbl-en">The sum of</span>
                    </span>
                    <span class="bank-check-slip__line"><?= esc($amountWords) ?></span>
                </div>
                <div class="bank-check-slip__figures" aria-label="المبلغ">
                    <span class="hash">#</span>
                    <span class="amount"><?= esc($amountFigures) ?></span>
                    <span class="hash">#</span>
                </div>
            </div>

            <footer class="bank-check-slip__footer">
                <div class="bank-check-slip__sig">
                    <span class="lbl">
                        التوقيع المعتمد
                        <span class="lbl-en">Authorized Signature</span>
                    </span>
                    <span class="line" aria-hidden="true"></span>
                </div>
                <?php if ($refLine !== ''): ?>
                    <div class="bank-check-slip__ref"><?= esc($refLine) ?></div>
                <?php endif; ?>
            </footer>

            <?php if ($micrLine !== ''): ?>
                <div class="bank-check-slip__micr" aria-hidden="true"><?= esc($micrLine) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return [
        'html' => (string) ob_get_clean(),
        'title' => $title,
    ];
}

function fin_outgoing_check_print_emit_page(
    string $pageTitle,
    string $contentHtml,
    bool $autoPrint = true
): never {
    header('Content-Type: text/html; charset=utf-8');
    echo fin_outgoing_check_print_document_html($contentHtml, $pageTitle);
    if ($autoPrint) {
        echo '<script>window.onload=function(){window.print();};</script>';
    }
    exit;
}
