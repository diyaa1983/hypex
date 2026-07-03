<?php
declare(strict_types=1);

require_once app_path('includes/sql_migration.php');

function wh_access_ensure_schema(PDO $pdo): void
{
    sql_migration_run_file_once($pdo, 'database/migrations/209_sys_group_warehouse.sql');
}

function wh_access_bypass(?int $userId = null): bool
{
    return user_is_system_admin();
}

/** @return list<int> */
function wh_access_user_group_ids(PDO $pdo, ?int $userId = null): array
{
    if ($userId === null) {
        $userId = (int) (current_user()['id'] ?? 0);
    }
    if ($userId < 1) {
        return [];
    }

    $st = $pdo->prepare('SELECT group_id FROM sys_user_group WHERE user_id = ?');
    $st->execute([$userId]);
    $ids = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $gid) {
        $gid = (int) $gid;
        if ($gid > 0) {
            $ids[] = $gid;
        }
    }

    return $ids;
}

function wh_access_user_has_acl(PDO $pdo, ?int $userId = null): bool
{
    wh_access_ensure_schema($pdo);
    if (wh_access_bypass($userId)) {
        return false;
    }

    $groupIds = wh_access_user_group_ids($pdo, $userId);
    if ($groupIds === []) {
        return false;
    }

    $ph = implode(',', array_fill(0, count($groupIds), '?'));
    $st = $pdo->prepare(
        "SELECT 1 FROM sys_group_warehouse WHERE group_id IN ($ph)
         AND (can_view = 1 OR can_issue = 1) LIMIT 1"
    );
    $st->execute($groupIds);

    return (bool) $st->fetchColumn();
}

/**
 * @return array{mode:string, view:list<int>, issue:list<int>}
 */
function wh_access_resolve(PDO $pdo, ?int $userId = null): array
{
    wh_access_ensure_schema($pdo);

    if (wh_access_bypass($userId)) {
        return ['mode' => 'all', 'view' => [], 'issue' => []];
    }

    if (!wh_access_user_has_acl($pdo, $userId)) {
        return ['mode' => 'all', 'view' => [], 'issue' => []];
    }

    $groupIds = wh_access_user_group_ids($pdo, $userId);
    if ($groupIds === []) {
        return ['mode' => 'none', 'view' => [], 'issue' => []];
    }

    $ph = implode(',', array_fill(0, count($groupIds), '?'));
    $st = $pdo->prepare(
        "SELECT warehouse_id, MAX(can_view) AS can_view, MAX(can_issue) AS can_issue
         FROM sys_group_warehouse
         WHERE group_id IN ($ph)
         GROUP BY warehouse_id"
    );
    $st->execute($groupIds);

    $view = [];
    $issue = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $whId = (int) ($row['warehouse_id'] ?? 0);
        if ($whId < 1) {
            continue;
        }
        if ((int) ($row['can_view'] ?? 0) === 1) {
            $view[] = $whId;
        }
        if ((int) ($row['can_issue'] ?? 0) === 1) {
            $issue[] = $whId;
        }
    }

    return ['mode' => 'acl', 'view' => $view, 'issue' => $issue];
}

function wh_access_can(PDO $pdo, int $warehouseId, string $mode = 'view', ?int $userId = null): bool
{
    if ($warehouseId < 1) {
        return false;
    }

    $acl = wh_access_resolve($pdo, $userId);
    if ($acl['mode'] === 'all') {
        $st = $pdo->prepare('SELECT 1 FROM inv_warehouse WHERE id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$warehouseId]);

        return (bool) $st->fetchColumn();
    }

    if ($acl['mode'] === 'none') {
        return false;
    }

    $list = $mode === 'issue' ? $acl['issue'] : $acl['view'];

    return in_array($warehouseId, $list, true);
}

function wh_access_can_view(PDO $pdo, int $warehouseId, ?int $userId = null): bool
{
    return wh_access_can($pdo, $warehouseId, 'view', $userId);
}

function wh_access_can_issue(PDO $pdo, int $warehouseId, ?int $userId = null): bool
{
    return wh_access_can($pdo, $warehouseId, 'issue', $userId);
}

/**
 * @return list<array{id:int, code:string, name_ar:string}>
 */
function wh_access_list_warehouses(PDO $pdo, string $mode = 'view', ?int $userId = null): array
{
    $acl = wh_access_resolve($pdo, $userId);

    if ($acl['mode'] === 'all') {
        $rows = $pdo->query(
            'SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'code' => (string) ($row['code'] ?? ''),
                'name_ar' => (string) ($row['name_ar'] ?? ''),
            ];
        }, $rows);
    }

    $ids = $mode === 'issue' ? $acl['issue'] : $acl['view'];
    if ($ids === []) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        "SELECT id, code, name_ar FROM inv_warehouse
         WHERE is_active = 1 AND id IN ($ph) ORDER BY name_ar"
    );
    $st->execute($ids);

    return array_map(static function (array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'code' => (string) ($row['code'] ?? ''),
            'name_ar' => (string) ($row['name_ar'] ?? ''),
        ];
    }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/** @return array<int, array{view:bool, issue:bool}> */
function wh_access_load_group(PDO $pdo, int $groupId): array
{
    wh_access_ensure_schema($pdo);
    if ($groupId < 1) {
        return [];
    }

    $st = $pdo->prepare(
        'SELECT warehouse_id, can_view, can_issue FROM sys_group_warehouse WHERE group_id = ?'
    );
    $st->execute([$groupId]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $whId = (int) ($row['warehouse_id'] ?? 0);
        if ($whId < 1) {
            continue;
        }
        $out[$whId] = [
            'view' => (int) ($row['can_view'] ?? 0) === 1,
            'issue' => (int) ($row['can_issue'] ?? 0) === 1,
        ];
    }

    return $out;
}

/** @param array<int|string, mixed> $viewIds
 * @param array<int|string, mixed> $issueIds
 */
function wh_access_save_group(PDO $pdo, int $groupId, array $viewIds, array $issueIds): void
{
    wh_access_ensure_schema($pdo);
    if ($groupId < 1) {
        return;
    }

    $viewSet = [];
    foreach ($viewIds as $rawId => $val) {
        if ($val) {
            $viewSet[(int) $rawId] = true;
        }
    }
    $issueSet = [];
    foreach ($issueIds as $rawId => $val) {
        if ($val) {
            $issueSet[(int) $rawId] = true;
        }
    }

    $allIds = array_unique(array_merge(array_keys($viewSet), array_keys($issueSet)));

    $del = $pdo->prepare('DELETE FROM sys_group_warehouse WHERE group_id = ?');
    $del->execute([$groupId]);

    if ($allIds === []) {
        return;
    }

    $ins = $pdo->prepare(
        'INSERT INTO sys_group_warehouse (group_id, warehouse_id, can_view, can_issue)
         VALUES (?, ?, ?, ?)'
    );
    foreach ($allIds as $whId) {
        $whId = (int) $whId;
        if ($whId < 1) {
            continue;
        }
        $stWh = $pdo->prepare('SELECT 1 FROM inv_warehouse WHERE id = ? LIMIT 1');
        $stWh->execute([$whId]);
        if (!$stWh->fetchColumn()) {
            continue;
        }
        $ins->execute([
            $groupId,
            $whId,
            !empty($viewSet[$whId]) ? 1 : 0,
            !empty($issueSet[$whId]) ? 1 : 0,
        ]);
    }
}

function wh_access_default_issue_warehouse_id(PDO $pdo, ?int $userId = null): ?int
{
    require_once app_path('includes/inv_warehouse_items.php');
    require_once app_path('includes/crm_sales_rep_schema.php');

    if ($userId === null) {
        $userId = (int) (current_user()['id'] ?? 0);
    }

    $repWh = $userId > 0 ? crm_sales_rep_warehouse_id_for_user($pdo, $userId) : null;
    if ($repWh !== null && wh_access_can_issue($pdo, $repWh, $userId)) {
        return $repWh;
    }

    $list = wh_access_list_warehouses($pdo, 'issue', $userId);
    if ($list === []) {
        return null;
    }

    $default = inv_default_warehouse_id($pdo);
    if ($default !== null && wh_access_can_issue($pdo, (int) $default, $userId)) {
        return (int) $default;
    }

    return (int) ($list[0]['id'] ?? 0) ?: null;
}

function wh_access_deny_issue_message(): string
{
    return 'لا توجد صلاحية الصرف من هذا المستودع.';
}

function wh_access_deny_view_message(): string
{
    return 'لا توجد صلاحية عرض هذا المستودع.';
}
