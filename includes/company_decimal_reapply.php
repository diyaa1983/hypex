<?php
declare(strict_types=1);

require_once app_path('includes/company_settings.php');
require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/pur_invoice_schema.php');
require_once app_path('includes/invoice_amount_decimals.php');

/**
 * إعادة تقريب الفواتير غير المرحّلة فقط حسب إعداد الخانات العشرية الحالي.
 * الفواتير المرحّلة تحتفظ بـ amount_decimals المثبتة ولا تُعدَّل.
 *
 * @return array{
 *   ok:bool,
 *   sal_invoices:int,
 *   pur_invoices:int,
 *   sal_returns:int,
 *   pur_returns:int,
 *   errors:list<string>
 * }
 */
function company_reapply_decimal_places_all(PDO $pdo): array
{
    $out = [
        'ok' => true,
        'sal_invoices' => 0,
        'pur_invoices' => 0,
        'sal_returns' => 0,
        'pur_returns' => 0,
        'errors' => [],
    ];

    invoice_amount_decimals_ensure_schema($pdo);
    $dp = company_decimal_places($pdo);

    require_once app_path('includes/sal_invoice_post.php');
    require_once app_path('includes/pur_invoice_post.php');

    $started = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }

        $salIds = $pdo->query('SELECT id FROM sal_invoice ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($salIds as $rawId) {
            $invId = (int) $rawId;
            if ($invId < 1 || sal_invoice_is_posted($pdo, $invId)) {
                continue;
            }
            sal_invoice_persist_normalized($pdo, $invId, $dp);
            $out['sal_invoices']++;
        }

        $purIds = $pdo->query('SELECT id FROM pur_invoice ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($purIds as $rawId) {
            $invId = (int) $rawId;
            if ($invId < 1 || pur_invoice_is_posted($pdo, $invId)) {
                continue;
            }
            pur_invoice_persist_normalized($pdo, $invId, $dp);
            $out['pur_invoices']++;
        }

        require_once app_path('includes/sal_return_schema.php');
        if (sal_return_has_tables($pdo)) {
            require_once app_path('includes/sal_return_post.php');
            $retIds = $pdo->query('SELECT id FROM sal_return ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($retIds as $rawId) {
                $retId = (int) $rawId;
                if ($retId < 1 || sal_return_is_posted($pdo, $retId)) {
                    continue;
                }
                // مرتجعات غير مرحّلة: يُعاد التقريب عند الحفظ التالي
                $out['sal_returns']++;
            }
        }

        require_once app_path('includes/pur_return_schema.php');
        if (pur_return_has_tables($pdo)) {
            require_once app_path('includes/pur_return_post.php');
            $retIds = $pdo->query('SELECT id FROM pur_return ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($retIds as $rawId) {
                $retId = (int) $rawId;
                if ($retId < 1 || pur_return_is_posted($pdo, $retId)) {
                    continue;
                }
                $out['pur_returns']++;
            }
        }

        if ($started) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $out['ok'] = false;
        $out['errors'][] = $e->getMessage();
    }

    return $out;
}
