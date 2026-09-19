<?php
declare(strict_types=1);

require_once app_path('includes/einvoice_settings.php');
require_once app_path('includes/einvoice_ubl.php');

/**
 * إرسال فاتورة بيع للفوترة — مطابق لسلوك admin (send_to_tax).
 *
 * @return array{ok:bool, skipped:bool, error:?string, message:?string}
 */
function einvoice_send_sale_invoice(PDO $pdo, int $invoiceId): array
{
    return einvoice_ubl_send_sale_invoice($pdo, $invoiceId);
}
