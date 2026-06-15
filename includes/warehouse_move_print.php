<?php
declare(strict_types=1);

require_once app_path('includes/inv_wh_move_schema.php');
require_once app_path('includes/inv_item_display.php');
require_once app_path('includes/document_header.php');

/** عنوان الشاشة في الطباعة (مطابق لـ config/routes.php). */
function warehouse_move_print_screen_title(): string
{
    return 'حركات المستودع';
}

/**
 * @return array{html:string, title:string}
 */
function warehouse_move_print_build(PDO $pdo, int $moveId): array
{
    $screenTitle = warehouse_move_print_screen_title();
    $move = inv_wh_move_by_id($pdo, $moveId);
    if ($move === null) {
        return ['html' => '<p>الحركة غير موجودة.</p>', 'title' => $screenTitle];
    }

    $lines = inv_wh_move_lines($pdo, $moveId);

    $whFrom = '';
    $st = $pdo->prepare('SELECT name_ar FROM inv_warehouse WHERE id = ? LIMIT 1');
    $st->execute([(int) ($move['warehouse_id'] ?? 0)]);
    $whFrom = (string) ($st->fetchColumn() ?: '');

    $whTo = '';
    if ((int) ($move['warehouse_to_id'] ?? 0) > 0) {
        $st->execute([(int) $move['warehouse_to_id']]);
        $whTo = (string) ($st->fetchColumn() ?: '');
    }

    $statusLabel = (string) ($move['status'] ?? '') === 'posted' ? 'مرحّل' : 'مسودة';
    $typeCode = (string) ($move['movement_type_code'] ?? '');
    $typeNameAr = trim((string) ($move['movement_type_name'] ?? ''));
    if ($typeNameAr === '') {
        $typeNameAr = $typeCode;
    }
    $qtyHdr = match ($typeCode) {
        'adjust_in', 'adjust_out', 'disposal' => 'الكمية المعدلة',
        'transfer' => 'كمية النقل',
        default => 'الكمية',
    };
    $dp = company_decimal_places($pdo);

    ob_start();
    ?>
    <?= document_print_header_html($screenTitle, $pdo, $typeNameAr) ?>

    <div class="doc-print-meta">
        <table>
            <tr>
                <td>
                    <strong>رقم الحركة:</strong> <?= esc((string) ($move['move_no'] ?? '')) ?>
                    &nbsp;&nbsp;|&nbsp;&nbsp;
                    <strong>التاريخ:</strong> <?= esc(format_date_dmY((string) ($move['move_date'] ?? ''))) ?>
                    &nbsp;&nbsp;|&nbsp;&nbsp;
                    <strong>الحالة:</strong> <?= esc($statusLabel) ?>
                </td>
            </tr>
            <tr>
                <td>
                    <?php if ($typeCode === 'transfer'): ?>
                        <strong>من مستودع:</strong>
                        <span class="doc-print-meta-value--party"><?= esc($whFrom) ?></span>
                        <?php if ($whTo !== ''): ?>
                            &nbsp;&nbsp;|&nbsp;&nbsp;
                            <strong>إلى مستودع:</strong>
                            <span class="doc-print-meta-value--party"><?= esc($whTo) ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <strong>المستودع:</strong>
                        <span class="doc-print-meta-value--party"><?= esc($whFrom) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if (trim((string) ($move['notes'] ?? '')) !== ''): ?>
                <tr>
                    <td><strong>ملاحظات:</strong> <?= esc((string) $move['notes']) ?></td>
                </tr>
            <?php endif; ?>
        </table>
    </div>

    <div class="report-sales-table-wrap">
        <table class="report-sales-table wh-move-print-table">
            <colgroup>
                <col class="col-seq">
                <col class="col-inv-no">
                <col>
                <col class="col-money">
            </colgroup>
            <thead>
            <tr>
                <th class="col-seq">#</th>
                <th class="col-inv-no">رقم المادة</th>
                <th>المادة</th>
                <th class="col-money"><?= esc($qtyHdr) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $i => $ln): ?>
                <tr>
                    <td class="col-seq"><?= (int) $i + 1 ?></td>
                    <td class="col-inv-no"><code><?= esc(inv_item_material_number_digits(
                        (string) ($ln['barcode'] ?? ''),
                        (string) ($ln['sku'] ?? '')
                    )) ?></code></td>
                    <td class="wh-move-print-item-name"><?= esc((string) ($ln['item_name'] ?? '')) ?></td>
                    <td class="col-money"><?= esc(format_amount((float) ($ln['qty'] ?? 0), $dp)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= document_print_recipient_signature_html() ?>
    <?php
    $html = (string) ob_get_clean();

    return [
        'html' => $html,
        'title' => $screenTitle . ' — ' . (string) ($move['move_no'] ?? ''),
    ];
}
