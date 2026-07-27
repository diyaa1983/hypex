<?php
declare(strict_types=1);

require_once app_path('includes/fin_outgoing_check_register.php');
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
        . '<style>@page{size:17.8cm 8.9cm;margin:0;}html,body{margin:0;padding:0;}</style>'
        . '</head><body class="bank-check-page">'
        . $contentHtml
        . '</body></html>';
}

/**
 * طباعة تعبئة فقط على شيك ورقي جاهز — بدون خلفية/إطار/شعار.
 *
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
    $dueDate = trim((string) ($row['due_date'] ?? ''));
    $voucherDate = format_date_dmY((string) ($row['voucher_date'] ?? ''));
    $checkDate = $dueDate !== '' ? format_date_dmY($dueDate) : $voucherDate;
    $partyName = trim((string) ($row['party_name'] ?? ''));
    if ($partyName === '' || $partyName === '—') {
        $partyName = '';
    }

    ob_start();
    ?>
    <div class="bank-check-slip bank-check-slip--fill">
        <div class="bank-check-fill" aria-label="تعبئة شيك">
            <div class="bank-check-fill__date"><?= esc($checkDate) ?></div>
            <div class="bank-check-fill__check-no"><?= esc($checkNo) ?></div>
            <div class="bank-check-fill__payee"><?= esc($partyName) ?></div>
            <div class="bank-check-fill__words"><?= esc($amountWords) ?></div>
            <div class="bank-check-fill__amount"><?= esc($amountFigures) ?></div>
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
