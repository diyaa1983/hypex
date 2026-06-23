<?php
declare(strict_types=1);

/** @return array{groups:list<array{title:string,items:list<array{code:string,name_ar:string,inherit_from:list<string>}>}>} */
function action_permissions_catalog(): array
{
    static $cat = null;
    if ($cat === null) {
        $cat = require app_path('config/action_permissions.php');
    }

    return $cat;
}

/** @return list<array{code:string,name_ar:string,inherit_from:list<string>}> */
function action_permissions_flat(): array
{
    $out = [];
    foreach (action_permissions_catalog()['groups'] as $group) {
        foreach ($group['items'] as $item) {
            $out[] = $item;
        }
    }

    return $out;
}

function sys_sync_action_permissions_mtime(): string
{
    $file = app_path('config/action_permissions.php');

    return is_file($file) ? (string) filemtime($file) . ':sync-v3-admins-only' : '0';
}

function sys_sync_is_action_permissions_cached(PDO $pdo): bool
{
    try {
        require_once app_path('includes/acc_coa_bootstrap.php');
        $mtime = sys_sync_action_permissions_mtime();

        return acc_coa_meta_get($pdo, 'sys_sync_action_perms_mtime') === $mtime;
    } catch (Throwable $e) {
        return false;
    }
}

function sys_sync_mark_action_permissions_cached(PDO $pdo): void
{
    try {
        require_once app_path('includes/acc_coa_bootstrap.php');
        acc_coa_meta_set($pdo, 'sys_sync_action_perms_mtime', sys_sync_action_permissions_mtime());
    } catch (Throwable $e) {
        // ignore
    }
}

/** تسجيل صلاحيات الإجراءات في sys_screen ومنح ADMINS فقط (باقي المجموعات من شاشة الصلاحيات). */
function sys_sync_action_permissions(PDO $pdo): int
{
    if (sys_sync_is_action_permissions_cached($pdo)) {
        return 0;
    }

    $added = 0;
    $maxOrder = (int) $pdo->query('SELECT IFNULL(MAX(sort_order), 0) FROM sys_screen')->fetchColumn();
    $nextOrder = max($maxOrder, 9000);

    $ins = $pdo->prepare(
        'INSERT INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES (?, ?, \'screen\', ?)'
    );
    $updName = $pdo->prepare('UPDATE sys_screen SET name_ar = ? WHERE code = ?');
    $find = $pdo->prepare('SELECT id FROM sys_screen WHERE code = ? LIMIT 1');
    $grantAdmins = $pdo->prepare(
        'INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
         SELECT g.id, ?, 1 FROM sys_group g WHERE g.code = ?'
    );

    foreach (action_permissions_flat() as $item) {
        $code = (string) $item['code'];
        $name = (string) $item['name_ar'];
        $find->execute([$code]);
        $existingId = $find->fetchColumn();

        if ($existingId === false) {
            $nextOrder += 1;
            $ins->execute([$code, $name, $nextOrder]);
            $screenId = (int) $pdo->lastInsertId();
            $added++;
            $grantAdmins->execute([$screenId, 'ADMINS']);
        } else {
            $updName->execute([$name, $code]);
        }
    }

    if ($added > 0) {
        require_once app_path('includes/sys_screens.php');
        sys_ensure_admins_all_permissions($pdo);
    }

    sys_sync_mark_action_permissions_cached($pdo);

    return $added;
}
