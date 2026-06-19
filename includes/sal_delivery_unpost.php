<?php
declare(strict_types=1);

require_once app_path('includes/sal_delivery_schema.php');
require_once app_path('includes/sal_delivery_stock.php');
require_once app_path('includes/sal_delivery_invoice_link.php');

/**
 * @return array{ok:bool, error:?string, message:?string}
 */
function sal_delivery_unpost_by_id(PDO $pdo, int $deliveryId): array
{
    if ($deliveryId < 1) {
        return ['ok' => false, 'error' => 'معرّف السند غير صالح.', 'message' => null];
    }
    if (!sal_delivery_is_posted($pdo, $deliveryId)) {
        return ['ok' => true, 'error' => null, 'message' => 'السند غير مرحّل.'];
    }
    sal_delivery_detach_unposted_invoice_links($pdo, $deliveryId);

    if (sal_delivery_has_linked_invoice($pdo, $deliveryId)) {
        $linked = sal_delivery_first_linked_invoice($pdo, $deliveryId);
        $invLabel = $linked && ($linked['invoice_no'] ?? '') !== ''
            ? ' «' . $linked['invoice_no'] . '»'
            : '';
        $posted = $linked && !empty($linked['is_posted']);

        return [
            'ok' => false,
            'error' => $posted
                ? 'لا يمكن فك ترحيل السند: فاتورة مبيعات مرحّلة' . $invLabel
                    . ' مربوطة به. إن كان الربط خاطئاً: من شاشة السند أو الفاتورة استخدم «فك الربط» (حتى لو أُرسلت للضريبة). وإلا افك ترحيل الفاتورة أولاً إن أمكن.'
                : 'لا يمكن فك ترحيل السند: فاتورة مسودة' . $invLabel
                    . ' مربوطة به. احذفها أو استخدم «فك الربط بالفاتورة» من شاشة السند.',
            'message' => null,
        ];
    }

    $stock = sal_delivery_stock_unpost($pdo, $deliveryId);
    if (!$stock['ok']) {
        return ['ok' => false, 'error' => $stock['error'] ?? 'تعذر إلغاء حركة المخزون.', 'message' => null];
    }

    $pdo->prepare(
        'UPDATE sal_delivery SET is_posted = 0, posted_at = NULL WHERE id = ? AND is_posted = 1'
    )->execute([$deliveryId]);

    require_once app_path('includes/sys_audit_log.php');
    sys_audit_log_sal_delivery($pdo, 'unpost', $deliveryId);

    return ['ok' => true, 'error' => null, 'message' => 'تم فك ترحيل سند التسليم وإرجاع المخزون.'];
}
