<?php
declare(strict_types=1);

/**
 * قائمة الموردين لتقرير الموردين.
 *
 * @return list<array{
 *   supplier_code:string,
 *   supplier_name:string,
 *   phone:string,
 *   email:string,
 *   tax_number:string,
 *   address_ar:string,
 *   is_active:int,
 *   created_at:string
 * }>
 */
function crm_report_suppliers_list(PDO $pdo, bool $activeOnly = false): array
{
    $sql =
        'SELECT s.code AS supplier_code,
                s.name_ar AS supplier_name,
                COALESCE(s.phone, \'\') AS phone,
                COALESCE(s.email, \'\') AS email,
                COALESCE(s.tax_number, \'\') AS tax_number,
                COALESCE(s.address_ar, \'\') AS address_ar,
                s.is_active,
                s.created_at
         FROM crm_supplier s';

    if ($activeOnly) {
        $sql .= ' WHERE s.is_active = 1';
    }

    $sql .= ' ORDER BY s.name_ar ASC, s.code ASC';

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'supplier_code' => (string) ($row['supplier_code'] ?? ''),
            'supplier_name' => (string) ($row['supplier_name'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'tax_number' => (string) ($row['tax_number'] ?? ''),
            'address_ar' => (string) ($row['address_ar'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    return $out;
}
