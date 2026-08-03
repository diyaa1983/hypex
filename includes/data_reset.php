<?php
declare(strict_types=1);

/**
 * تفريغ بيانات التشغيل (فواتير، قيود، مخزون، دفاتر أطراف) مع الإبقاء على البيانات الأساسية.
 *
 * لنسخة قالب فارغة بالكامل (للأنظمة الجديدة): نفّذ
 *   database/scripts/factory_empty_template.sql
 * على قاعدة البيانات بعد نسخة احتياطية.
 */

function data_reset_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        $st->execute([$table]);
        $cache[$table] = (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

function data_reset_delete_all(PDO $pdo, string $table): int
{
    if (!data_reset_table_exists($pdo, $table)) {
        return 0;
    }
    try {
        return $pdo->exec('DELETE FROM `' . str_replace('`', '``', $table) . '`') ?: 0;
    } catch (Throwable $e) {
        throw new RuntimeException('تعذر تفريغ جدول «' . $table . '»: ' . $e->getMessage(), 0, $e);
    }
}

/** @return list<string> */
function data_reset_tables_in_order(): array
{
    return [
        'acc_journal_line',
        'acc_journal_entry',
        'fin_credit_note_line',
        'fin_credit_note',
        'fin_debit_note_line',
        'fin_debit_note',
        'fin_voucher',
        'inv_stock_move',
        'crm_customer_ledger',
        'crm_supplier_ledger',
        'sal_return_line',
        'sal_return',
        'sal_delivery_line',
        'sal_delivery',
        'sal_invoice_line',
        'sal_invoice',
        'pur_return_line',
        'pur_return',
        'pur_invoice_line',
        'pur_invoice',
    ];
}

/** @return list<string> */
function data_reset_keep_master_labels(): array
{
    return [
        'العملاء (crm_customer)',
        'المواد والأصناف (inv_item + التصنيفات والوحدات والمستودعات)',
        'شجرة الحسابات (acc_account) وربط الترحيل (acc_posting_setting)',
        'إعدادات النظام والمستخدمين والصلاحيات',
    ];
}

/**
 * @param array{
 *   keep_suppliers?: bool,
 *   keep_sales_reps?: bool,
 * } $options
 * @return array{ok:bool, deleted:array<string,int>, message:string}
 */
function data_reset_transactional_data(PDO $pdo, array $options = []): array
{
    $keepSuppliers = !empty($options['keep_suppliers']);
    $keepSalesReps = !empty($options['keep_sales_reps']);
    $deleted = [];

    try {
        $pdo->beginTransaction();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach (data_reset_tables_in_order() as $table) {
            if (!data_reset_table_exists($pdo, $table)) {
                continue;
            }
            $deleted[$table] = data_reset_delete_all($pdo, $table);
        }

        if (!$keepSalesReps && data_reset_table_exists($pdo, 'crm_customer')) {
            try {
                $pdo->exec('UPDATE crm_customer SET sales_rep_id = NULL WHERE sales_rep_id IS NOT NULL');
            } catch (Throwable $e) {
            }
        }
        if (!$keepSalesReps && data_reset_table_exists($pdo, 'crm_sales_rep')) {
            $deleted['crm_sales_rep'] = data_reset_delete_all($pdo, 'crm_sales_rep');
        }
        if (!$keepSuppliers && data_reset_table_exists($pdo, 'crm_supplier')) {
            $deleted['crm_supplier'] = data_reset_delete_all($pdo, 'crm_supplier');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $pdo->commit();
    } catch (Throwable $e) {
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable $ignored) {
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'deleted' => $deleted, 'message' => $e->getMessage()];
    }

    return [
        'ok' => true,
        'deleted' => $deleted,
        'message' => 'تم تفريغ بيانات التشغيل بنجاح.',
    ];
}
