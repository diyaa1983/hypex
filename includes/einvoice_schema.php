<?php
declare(strict_types=1);

function einvoice_run_migration_file(PDO $pdo, string $filename): void
{
    require_once app_path('includes/sql_migration.php');
    sql_migration_run_file($pdo, 'database/migrations/' . $filename);
}

function einvoice_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $pdo->query('SELECT `' . str_replace('`', '', $column) . '` FROM `' . str_replace('`', '', $table) . '` LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function einvoice_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    einvoice_run_migration_file($pdo, '012_einvoice_settings.sql');
    einvoice_run_migration_file($pdo, '013_sal_invoice_einvoice_admin_parity.sql');

    $parityCols = [
        'reference_status VARCHAR(20) NULL',
        'return_id INT UNSIGNED NULL',
        'einv_hash VARCHAR(255) NULL',
        // المبلغ الفعلي المُرسَل للفوترة (TaxInclusiveAmount في UBL XML)،
        // نَستخدمه في DocumentDescription لإشعار الدائن لضمان مطابقة JoFotara.
        'einv_total_amount DECIMAL(18,3) NULL',
    ];
    foreach ($parityCols as $col) {
        $name = strtok($col, ' ');
        if ($name && !einvoice_column_exists($pdo, 'sal_invoice', $name)) {
            try {
                $pdo->exec('ALTER TABLE sal_invoice ADD COLUMN ' . $col);
            } catch (Throwable $e) {
            }
        }
    }

    if (!einvoice_column_exists($pdo, 'sal_return', 'reason_return')) {
        try {
            $pdo->exec('ALTER TABLE sal_return ADD COLUMN reason_return TEXT NULL');
        } catch (Throwable $e) {
        }
    }
    if (!einvoice_column_exists($pdo, 'sal_return', 'reference_status')) {
        try {
            $pdo->exec('ALTER TABLE sal_return ADD COLUMN reference_status VARCHAR(20) NULL');
        } catch (Throwable $e) {
        }
    }

    if (!einvoice_column_exists($pdo, 'sal_invoice', 'einv_qr')) {
        $alters = [
            'invoice_uuid VARCHAR(64) NULL',
            'einv_status VARCHAR(40) NULL',
            'einv_results TEXT NULL',
            'einv_signed_invoice LONGTEXT NULL',
            'einv_qr TEXT NULL',
            'einv_num VARCHAR(80) NULL',
            'einv_inv_uuid VARCHAR(80) NULL',
            'einv_sent_at DATETIME NULL',
        ];
        foreach ($alters as $col) {
            try {
                $pdo->exec('ALTER TABLE sal_invoice ADD COLUMN ' . $col);
            } catch (Throwable $e) {
            }
        }
    }

    if (!einvoice_column_exists($pdo, 'sal_return', 'einv_qr')) {
        $alters = [
            'invoice_uuid VARCHAR(64) NULL',
            'einv_status VARCHAR(40) NULL',
            'einv_results TEXT NULL',
            'einv_signed_invoice LONGTEXT NULL',
            'einv_qr TEXT NULL',
            'einv_num VARCHAR(80) NULL',
            'einv_inv_uuid VARCHAR(80) NULL',
            'einv_sent_at DATETIME NULL',
            'einv_original_invoice_id INT UNSIGNED NULL',
        ];
        foreach ($alters as $col) {
            try {
                $pdo->exec('ALTER TABLE sal_return ADD COLUMN ' . $col);
            } catch (Throwable $e) {
            }
        }
    }

    try {
        $pdo->query('SELECT id FROM sys_einvoice_settings LIMIT 1');
    } catch (Throwable $e) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sys_einvoice_settings (
                id TINYINT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,
                company_name VARCHAR(255) NOT NULL DEFAULT '',
                trade_name VARCHAR(255) NULL,
                vat_no VARCHAR(64) NULL,
                gst_no VARCHAR(64) NULL,
                company_email VARCHAR(120) NULL,
                company_phone VARCHAR(64) NULL,
                address VARCHAR(500) NULL,
                city VARCHAR(120) NULL,
                taxes_type TINYINT UNSIGNED NOT NULL DEFAULT 2,
                invoice_cash VARCHAR(10) NOT NULL DEFAULT '011',
                invoice_debit VARCHAR(10) NOT NULL DEFAULT '021',
                client_id VARCHAR(255) NULL,
                secret_key LONGTEXT NULL,
                admin_email VARCHAR(120) NULL,
                jofotara_api_url VARCHAR(255) NOT NULL DEFAULT 'https://backend.jofotara.gov.jo/core/invoices/',
                notes VARCHAR(500) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec('INSERT IGNORE INTO sys_einvoice_settings (id) VALUES (1)');
    }

    $done = true;
}

/** @return array<string, string> */
function einvoice_invoice_cash_options(): array
{
    return [
        '011' => 'فاتورة مبيعات عامة نقدية محلية (011)',
        '1111' => 'فاتورة مبيعات عامة نقدية محلية (1111)',
        '012' => 'فاتورة مبيعات عامة نقدية تصدير (012)',
        '112' => 'فاتورة مبيعات عامة نقدية تصدير (112)',
        '212' => 'فاتورة مبيعات عامة نقدية تصدير (212)',
    ];
}

/** @return array<string, string> */
function einvoice_invoice_debit_options(): array
{
    return [
        '021' => 'فاتورة مبيعات عامة ذمم محلية (021)',
        '121' => 'فاتورة مبيعات عامة ذمم محلية (121)',
        '022' => 'فاتورة مبيعات عامة ذمم تصدير (022)',
        '122' => 'فاتورة مبيعات عامة ذمم تصدير (122)',
        '222' => 'فاتورة مبيعات عامة ذمم تصدير (222)',
    ];
}
