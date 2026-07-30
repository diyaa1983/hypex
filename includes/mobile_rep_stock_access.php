<?php
declare(strict_types=1);

require_once app_path('includes/mobile_rep_custody.php');
require_once app_path('includes/warehouse_access.php');

/**
 * مستودعات رصيد المندوب/المستخدم: مستودع العهدة + مستودعات صلاحية العرض.
 *
 * @return array{
 *   ok:bool,
 *   error:?string,
 *   message:string,
 *   warehouses:list<array{id:int,code:string,name_ar:string,is_van:bool}>,
 *   default_warehouse_id:int,
 *   rep_name:string,
 *   has_rep:bool,
 *   custody:?array
 * }
 */
function mobile_rep_stock_access(PDO $pdo, ?int $userId = null): array
{
    if ($userId === null) {
        $userId = (int) (current_user()['id'] ?? 0);
    }

    $custody = mobile_rep_custody_context($pdo, $userId);
    $acl = wh_access_list_warehouses($pdo, 'view', $userId);

    $byId = [];
    if ($custody !== null) {
        $vanId = (int) $custody['van_warehouse_id'];
        if ($vanId > 0) {
            $byId[$vanId] = [
                'id' => $vanId,
                'code' => (string) ($custody['van_warehouse_code'] ?? ''),
                'name_ar' => (string) ($custody['van_warehouse_name'] ?? ''),
                'is_van' => true,
            ];
        }
    }

    foreach ($acl as $wh) {
        $id = (int) ($wh['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        if (isset($byId[$id])) {
            continue;
        }
        $byId[$id] = [
            'id' => $id,
            'code' => (string) ($wh['code'] ?? ''),
            'name_ar' => (string) ($wh['name_ar'] ?? ''),
            'is_van' => false,
        ];
    }

    $warehouses = array_values($byId);
    if ($warehouses === []) {
        return [
            'ok' => false,
            'error' => 'no_warehouse',
            'message' => 'لا يوجد مستودع عهدة مربوط بحسابك، ولا صلاحية عرض على أي مستودع.',
            'warehouses' => [],
            'default_warehouse_id' => 0,
            'rep_name' => '',
            'has_rep' => false,
            'custody' => null,
        ];
    }

    $defaultId = $custody !== null
        ? (int) $custody['van_warehouse_id']
        : (int) $warehouses[0]['id'];

    return [
        'ok' => true,
        'error' => null,
        'message' => '',
        'warehouses' => $warehouses,
        'default_warehouse_id' => $defaultId,
        'rep_name' => (string) ($custody['rep_name'] ?? ''),
        'has_rep' => $custody !== null,
        'custody' => $custody,
    ];
}

/**
 * @param array $access ناتج mobile_rep_stock_access
 * @return ?array{id:int,code:string,name_ar:string,is_van:bool}
 */
function mobile_rep_stock_pick_warehouse(array $access, int $requestedId): ?array
{
    $warehouses = $access['warehouses'] ?? [];
    if (!is_array($warehouses) || $warehouses === []) {
        return null;
    }

    $want = $requestedId > 0
        ? $requestedId
        : (int) ($access['default_warehouse_id'] ?? 0);

    foreach ($warehouses as $wh) {
        if ((int) ($wh['id'] ?? 0) === $want) {
            return [
                'id' => (int) $wh['id'],
                'code' => (string) ($wh['code'] ?? ''),
                'name_ar' => (string) ($wh['name_ar'] ?? ''),
                'is_van' => !empty($wh['is_van']),
            ];
        }
    }

    $first = $warehouses[0];

    return [
        'id' => (int) ($first['id'] ?? 0),
        'code' => (string) ($first['code'] ?? ''),
        'name_ar' => (string) ($first['name_ar'] ?? ''),
        'is_van' => !empty($first['is_van']),
    ];
}

/**
 * سياق مبسّط لطباعة/PDF رصيد المستودع المختار.
 *
 * @return array{rep_name:string,van_warehouse_id:int,van_warehouse_name:string,van_warehouse_code:string}
 */
function mobile_rep_stock_print_ctx(array $access, array $warehouse): array
{
    return [
        'rep_name' => (string) ($access['rep_name'] ?? ''),
        'van_warehouse_id' => (int) ($warehouse['id'] ?? 0),
        'van_warehouse_name' => (string) ($warehouse['name_ar'] ?? ''),
        'van_warehouse_code' => (string) ($warehouse['code'] ?? ''),
    ];
}
