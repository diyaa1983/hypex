<?php
declare(strict_types=1);

require_once app_path('includes/sal_delivery_schema.php');
require_once app_path('includes/sal_delivery_stock.php');

/**
 * ترحيل سند التسليم — صرف مخزون فقط (لا ذمم، لا قيود).
 *
 * @param list<int> $deliveryIds
 * @return array{posted:int, skipped:int, errors:list<string>}
 */
function sal_delivery_post_by_ids(PDO $pdo, array $deliveryIds): array
{
    $out = ['posted' => 0, 'skipped' => 0, 'errors' => []];

    foreach ($deliveryIds as $rawId) {
        $id = (int) $rawId;
        if ($id < 1) {
            continue;
        }
        if (sal_delivery_is_posted($pdo, $id)) {
            $out['skipped']++;
            continue;
        }
        $st = $pdo->prepare('SELECT delivery_no FROM sal_delivery WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $no = $st->fetchColumn();
        if ($no === false) {
            $out['errors'][] = 'سند #' . $id . ': غير موجود.';
            continue;
        }
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM sal_delivery_line WHERE delivery_id = ?');
        $cnt->execute([$id]);
        if ((int) $cnt->fetchColumn() < 1) {
            $out['errors'][] = 'سند «' . $no . '»: لا توجد مواد.';
            continue;
        }

        $stock = sal_delivery_stock_post($pdo, $id);
        if (!$stock['ok']) {
            $out['errors'][] = 'سند «' . $no . '»: ' . ($stock['error'] ?? 'تعذر صرف المخزون.');
            continue;
        }

        $pdo->prepare(
            'UPDATE sal_delivery SET is_posted = 1, posted_at = NOW() WHERE id = ? AND is_posted = 0'
        )->execute([$id]);
        $out['posted']++;
        require_once app_path('includes/sys_audit_log.php');
        sys_audit_log_sal_delivery($pdo, 'post', $id);
    }

    return $out;
}
