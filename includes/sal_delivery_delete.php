<?php
declare(strict_types=1);

require_once app_path('includes/sal_delivery_schema.php');

/**
 * @return array{ok:bool, error:?string, delivery_no:?string}
 */
function sal_delivery_delete_by_id(PDO $pdo, int $deliveryId): array
{
    $out = ['ok' => false, 'error' => null, 'delivery_no' => null];
    if ($deliveryId < 1) {
        $out['error'] = 'معرّف غير صالح.';

        return $out;
    }
    if (sal_delivery_is_posted($pdo, $deliveryId)) {
        $out['error'] = 'لا يمكن حذف سند مرحّل.';

        return $out;
    }
    require_once app_path('includes/sal_delivery_invoice_link.php');
    if (sal_delivery_has_linked_invoice($pdo, $deliveryId)) {
        $out['error'] = 'لا يمكن حذف سند مربوط بفاتورة مبيعات.';

        return $out;
    }
    $st = $pdo->prepare('SELECT delivery_no FROM sal_delivery WHERE id = ? LIMIT 1');
    $st->execute([$deliveryId]);
    $no = $st->fetchColumn();
    if ($no === false) {
        $out['error'] = 'السند غير موجود.';

        return $out;
    }
    $pdo->prepare('DELETE FROM sal_delivery WHERE id = ?')->execute([$deliveryId]);
    $out['ok'] = true;
    $out['delivery_no'] = (string) $no;

    return $out;
}
