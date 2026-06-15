<?php
declare(strict_types=1);

require_once app_path('includes/crm_sales_rep_schema.php');

/**
 * قائمة العملاء لتقرير العملاء.
 *
 * @return list<array{
 *   customer_code:string,
 *   customer_name:string,
 *   phone:string,
 *   email:string,
 *   tax_number:string,
 *   address_ar:string,
 *   sales_rep_name:string,
 *   is_active:int,
 *   created_at:string
 * }>
 */
function crm_report_customers_list(PDO $pdo, bool $activeOnly = false): array
{
    crm_sales_rep_ensure_customer_invoice_links($pdo);

    $sql =
        'SELECT c.code AS customer_code,
                c.name_ar AS customer_name,
                COALESCE(c.phone, \'\') AS phone,
                COALESCE(c.email, \'\') AS email,
                COALESCE(c.tax_number, \'\') AS tax_number,
                COALESCE(c.address_ar, \'\') AS address_ar,
                COALESCE(r.name_ar, \'\') AS sales_rep_name,
                c.is_active,
                c.created_at
         FROM crm_customer c
         LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id';

    if ($activeOnly) {
        $sql .= ' WHERE c.is_active = 1';
    }

    $sql .= ' ORDER BY c.name_ar ASC, c.code ASC';

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'customer_code' => (string) ($row['customer_code'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'tax_number' => (string) ($row['tax_number'] ?? ''),
            'address_ar' => (string) ($row['address_ar'] ?? ''),
            'sales_rep_name' => (string) ($row['sales_rep_name'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    return $out;
}
