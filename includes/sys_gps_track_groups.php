<?php
declare(strict_types=1);

/**
 * مجموعات الصلاحيات المسموح ظهور مندوبيها في شاشة تتبع مواقع المندوبين.
 */

function sys_gps_track_groups_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sys_gps_track_group (
                group_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (group_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        error_log('sys_gps_track_groups_ensure_schema: ' . $e->getMessage());
    }
    $done = true;
}

/** @return list<int> */
function sys_gps_track_group_ids(PDO $pdo): array
{
    sys_gps_track_groups_ensure_schema($pdo);
    try {
        $rows = $pdo->query('SELECT group_id FROM sys_gps_track_group ORDER BY group_id')
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_map('intval', $rows));
    } catch (Throwable $e) {
        return [];
    }
}

/** @return array<int, true> */
function sys_gps_track_group_id_set(PDO $pdo): array
{
    $set = [];
    foreach (sys_gps_track_group_ids($pdo) as $id) {
        if ($id > 0) {
            $set[$id] = true;
        }
    }
    return $set;
}

/**
 * @param list<int|string>|mixed $groupIds
 */
function sys_gps_track_groups_save(PDO $pdo, $groupIds): void
{
    sys_gps_track_groups_ensure_schema($pdo);
    $ids = [];
    if (is_array($groupIds)) {
        foreach ($groupIds as $g) {
            $id = (int) $g;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    }
    $ids = array_values($ids);

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM sys_gps_track_group');
        if ($ids !== []) {
            $ins = $pdo->prepare('INSERT INTO sys_gps_track_group (group_id) VALUES (?)');
            foreach ($ids as $id) {
                // تجاهل مجموعات محذوفة
                $ok = $pdo->prepare('SELECT 1 FROM sys_group WHERE id = ? LIMIT 1');
                $ok->execute([$id]);
                if ($ok->fetchColumn()) {
                    $ins->execute([$id]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * شرط SQL: مستخدم مندوب وينتمي لمجموعة مفعّل تتبّعها.
 * إن لم تُحدَّد أي مجموعة → لا أحد (يجب التحديد من الصلاحيات).
 *
 * @return array{0:string,1:list<mixed>} [sqlFragment, params]
 */
function sys_gps_track_groups_user_filter_sql(PDO $pdo, string $userAlias = 'u'): array
{
    $ids = sys_gps_track_group_ids($pdo);
    if ($ids === []) {
        return [' AND 1=0 ', []];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = ' AND ' . $userAlias . '.sales_rep_id IS NOT NULL'
        . ' AND ' . $userAlias . '.sales_rep_id > 0'
        . ' AND EXISTS ('
        . '   SELECT 1 FROM sys_user_group ug'
        . '   WHERE ug.user_id = ' . $userAlias . '.id'
        . '     AND ug.group_id IN (' . $placeholders . ')'
        . ' )';

    return [$sql, $ids];
}
