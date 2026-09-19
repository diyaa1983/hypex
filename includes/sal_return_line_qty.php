<?php
declare(strict_types=1);

require_once app_path('includes/sal_return_schema.php');

/** ضمان عمود qty_extra على بنود مرتجع المبيعات. */
function sal_return_line_ensure_qty_extra(PDO $pdo): void
{
    require_once app_path('includes/sql_migration.php');
    sql_migration_run_file($pdo, 'database/migrations/190_sal_return_line_qty_extra.sql');

    if (!sal_return_has_line_table($pdo)) {
        return;
    }
    if (!sal_invoice_column_exists($pdo, 'sal_return_line', 'qty_extra')) {
        try {
            $pdo->exec(
                'ALTER TABLE sal_return_line ADD COLUMN qty_extra DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER qty'
            );
        } catch (Throwable $e) {
            // ignore
        }
    }
}

function sal_return_line_has_qty_extra(PDO $pdo): bool
{
    sal_return_line_ensure_qty_extra($pdo);

    return sal_return_has_line_table($pdo)
        && sal_invoice_column_exists($pdo, 'sal_return_line', 'qty_extra');
}
