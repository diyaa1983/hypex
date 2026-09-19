<?php
declare(strict_types=1);

require_once app_path('includes/crm_sales_rep_schema.php');

/**
 * تقرير العملاء مجمّعاً حسب المندوب.
 *
 * @return array{
 *   groups: list<array{
 *     rep_id: int,
 *     rep_name: string,
 *     rep_code: string,
 *     rows: list<array{
 *       customer_code: string,
 *       customer_name: string,
 *       phone: string,
 *       email: string,
 *       tax_number: string,
 *       address_ar: string,
 *       is_active: int
 *     }>,
 *     customer_count: int,
 *     active_count: int
 *   }>,
 *   grand: array{customer_count: int, active_count: int, inactive_count: int, rep_count: int}
 * }
 */
function crm_report_customers_by_rep_build(PDO $pdo, bool $activeOnly = false, int $salesRepId = 0): array
{
    crm_sales_rep_ensure_customer_invoice_links($pdo);

    $empty = [
        'groups' => [],
        'grand' => [
            'customer_count' => 0,
            'active_count' => 0,
            'inactive_count' => 0,
            'rep_count' => 0,
        ],
    ];

    $hasLink = crm_customer_sales_rep_has_table($pdo);
    $params = [];
    $activeSql = $activeOnly ? ' AND c.is_active = 1' : '';

    if ($hasLink) {
        $sql =
            'SELECT DISTINCT
                c.id AS customer_id,
                c.code AS customer_code,
                c.name_ar AS customer_name,
                COALESCE(c.phone, \'\') AS phone,
                COALESCE(c.email, \'\') AS email,
                COALESCE(c.tax_number, \'\') AS tax_number,
                COALESCE(c.address_ar, \'\') AS address_ar,
                c.is_active,
                COALESCE(r.id, 0) AS rep_id,
                COALESCE(NULLIF(TRIM(r.name_ar), \'\'), \'— بدون مندوب —\') AS rep_name,
                COALESCE(r.code, \'\') AS rep_code
             FROM crm_customer c
             LEFT JOIN (
                 SELECT customer_id, sales_rep_id FROM crm_customer_sales_rep
                 UNION
                 SELECT id AS customer_id, sales_rep_id
                 FROM crm_customer
                 WHERE sales_rep_id IS NOT NULL AND sales_rep_id > 0
             ) map ON map.customer_id = c.id
             LEFT JOIN crm_sales_rep r ON r.id = map.sales_rep_id
             WHERE 1=1' . $activeSql;
    } else {
        $sql =
            'SELECT
                c.id AS customer_id,
                c.code AS customer_code,
                c.name_ar AS customer_name,
                COALESCE(c.phone, \'\') AS phone,
                COALESCE(c.email, \'\') AS email,
                COALESCE(c.tax_number, \'\') AS tax_number,
                COALESCE(c.address_ar, \'\') AS address_ar,
                c.is_active,
                COALESCE(r.id, 0) AS rep_id,
                COALESCE(NULLIF(TRIM(r.name_ar), \'\'), \'— بدون مندوب —\') AS rep_name,
                COALESCE(r.code, \'\') AS rep_code
             FROM crm_customer c
             LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id
             WHERE 1=1' . $activeSql;
    }

    if ($salesRepId > 0) {
        $sql .= ' AND COALESCE(r.id, 0) = ?';
        $params[] = $salesRepId;
    }

    $sql .= ' ORDER BY
                CASE WHEN COALESCE(r.id, 0) = 0 THEN 1 ELSE 0 END ASC,
                rep_name ASC,
                c.name_ar ASC,
                c.code ASC';

    try {
        if ($params !== []) {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $raw = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        return $empty;
    }

    if ($raw === []) {
        return $empty;
    }

    /** @var array<string, array{rep_id:int,rep_name:string,rep_code:string,rows:list<array<string,mixed>>,customer_count:int,active_count:int}> $groups */
    $groups = [];
    $seen = [];
    $grandCustomers = 0;
    $grandActive = 0;
    $grandInactive = 0;

    foreach ($raw as $row) {
        $repId = (int) ($row['rep_id'] ?? 0);
        $repName = (string) ($row['rep_name'] ?? '— بدون مندوب —');
        $groupKey = $repId . '|' . $repName;
        $customerId = (int) ($row['customer_id'] ?? 0);
        $uniqueKey = $groupKey . '#' . $customerId;
        if (isset($seen[$uniqueKey])) {
            continue;
        }
        $seen[$uniqueKey] = true;

        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'rep_id' => $repId,
                'rep_name' => $repName,
                'rep_code' => (string) ($row['rep_code'] ?? ''),
                'rows' => [],
                'customer_count' => 0,
                'active_count' => 0,
            ];
        }

        $isActive = (int) ($row['is_active'] ?? 0) === 1 ? 1 : 0;
        $groups[$groupKey]['rows'][] = [
            'customer_code' => (string) ($row['customer_code'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'tax_number' => (string) ($row['tax_number'] ?? ''),
            'address_ar' => (string) ($row['address_ar'] ?? ''),
            'is_active' => $isActive,
        ];
        $groups[$groupKey]['customer_count']++;
        if ($isActive === 1) {
            $groups[$groupKey]['active_count']++;
            $grandActive++;
        } else {
            $grandInactive++;
        }
        $grandCustomers++;
    }

    return [
        'groups' => array_values($groups),
        'grand' => [
            'customer_count' => $grandCustomers,
            'active_count' => $grandActive,
            'inactive_count' => $grandInactive,
            'rep_count' => count($groups),
        ],
    ];
}
