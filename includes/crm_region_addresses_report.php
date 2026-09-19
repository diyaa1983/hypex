<?php
declare(strict_types=1);

/**
 * تقرير العناوين والمنطقة: كل منطقة وعناوينها والمندوب المربوط.
 *
 * @return list<array{
 *   region_id:int,region_code:string,region_name:string,region_active:int,
 *   address_id:int,address_name:string,address_active:int,
 *   sales_rep_name:string,customer_count:int
 * }>
 */
function crm_report_region_addresses(PDO $pdo, bool $activeOnly = false, int $regionId = 0): array
{
    require_once app_path('includes/crm_region.php');
    crm_region_ensure_schema($pdo);

    $where = ['1=1'];
    $params = [];
    if ($activeOnly) {
        $where[] = 'rg.is_active = 1';
        $where[] = '(a.id IS NULL OR a.is_active = 1)';
    }
    if ($regionId > 0) {
        $where[] = 'rg.id = ?';
        $params[] = $regionId;
    }

    $sql = 'SELECT
        rg.id AS region_id,
        COALESCE(rg.code, \'\') AS region_code,
        rg.name_ar AS region_name,
        rg.is_active AS region_active,
        COALESCE(a.id, 0) AS address_id,
        COALESCE(a.name_ar, \'\') AS address_name,
        COALESCE(a.is_active, 0) AS address_active,
        COALESCE(
          NULLIF(TRIM((
            SELECT GROUP_CONCAT(DISTINCT sr.name_ar ORDER BY sr.name_ar SEPARATOR \'، \')
            FROM crm_sales_rep_region_address sra
            INNER JOIN crm_sales_rep sr ON sr.id = sra.sales_rep_id AND sr.is_active = 1
            WHERE sra.region_address_id = a.id
          )), \'\'),
          NULLIF(TRIM((
            SELECT GROUP_CONCAT(DISTINCT sr.name_ar ORDER BY sr.name_ar SEPARATOR \'، \')
            FROM crm_sales_rep_region srr
            INNER JOIN crm_sales_rep sr ON sr.id = srr.sales_rep_id AND sr.is_active = 1
            WHERE srr.region_id = rg.id
          )), \'\'),
          \'\'
        ) AS sales_rep_name,
        CASE
          WHEN a.id IS NOT NULL THEN (
            SELECT COUNT(*) FROM crm_customer c WHERE c.region_address_id = a.id
          )
          ELSE (
            SELECT COUNT(*) FROM crm_customer c
            WHERE c.region_id = rg.id
              AND (c.region_address_id IS NULL OR c.region_address_id = 0)
          )
        END AS customer_count
     FROM crm_region rg
     LEFT JOIN crm_region_address a ON a.region_id = rg.id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY
       COALESCE(rg.sort_order, 0) ASC,
       rg.name_ar ASC,
       COALESCE(a.sort_order, 0) ASC,
       a.name_ar ASC,
       rg.id ASC';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'region_id' => (int) ($row['region_id'] ?? 0),
                'region_code' => (string) ($row['region_code'] ?? ''),
                'region_name' => (string) ($row['region_name'] ?? ''),
                'region_active' => (int) ($row['region_active'] ?? 0),
                'address_id' => (int) ($row['address_id'] ?? 0),
                'address_name' => (string) ($row['address_name'] ?? ''),
                'address_active' => (int) ($row['address_active'] ?? 0),
                'sales_rep_name' => (string) ($row['sales_rep_name'] ?? ''),
                'customer_count' => (int) ($row['customer_count'] ?? 0),
            ];
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}
