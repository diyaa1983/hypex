<?php
declare(strict_types=1);

function sys_favorites_has_table(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM sys_user_favorite LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function sys_favorites_ensure_schema(PDO $pdo): bool
{
    if (sys_favorites_has_table($pdo)) {
        return true;
    }
    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/058_user_favorites.sql');
    } catch (Throwable $e) {
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS sys_user_favorite (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT UNSIGNED NOT NULL,
                    screen_code VARCHAR(64) NOT NULL,
                    sort_order INT NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_sys_user_favorite (user_id, screen_code),
                    KEY ix_sys_user_favorite_user (user_id, sort_order)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e2) {
            return false;
        }
    }

    return sys_favorites_has_table($pdo);
}

/**
 * @return list<string>
 */
function sys_favorites_codes_for_user(PDO $pdo, int $userId): array
{
    if ($userId < 1 || !sys_favorites_ensure_schema($pdo)) {
        return [];
    }
    try {
        $st = $pdo->prepare(
            'SELECT screen_code FROM sys_user_favorite WHERE user_id = ?
             ORDER BY sort_order ASC, id ASC'
        );
        $st->execute([$userId]);

        return array_values(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []));
    } catch (Throwable $e) {
        return [];
    }
}

function sys_favorites_is_favorite(PDO $pdo, int $userId, string $screenCode): bool
{
    $screenCode = trim($screenCode);
    if ($userId < 1 || $screenCode === '' || !sys_favorites_ensure_schema($pdo)) {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT 1 FROM sys_user_favorite WHERE user_id = ? AND screen_code = ? LIMIT 1');
        $st->execute([$userId, $screenCode]);

        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * يُبدِّل حالة المفضلة (إضافة/إزالة).
 *
 * @return array{ok:bool, favorited:bool, message:string}
 */
function sys_favorites_toggle(PDO $pdo, int $userId, string $screenCode): array
{
    $screenCode = trim($screenCode);
    if ($userId < 1 || $screenCode === '') {
        return ['ok' => false, 'favorited' => false, 'message' => 'بيانات غير صالحة.'];
    }
    if (!sys_favorites_ensure_schema($pdo)) {
        return ['ok' => false, 'favorited' => false, 'message' => 'تعذر تهيئة جدول المفضلات.'];
    }
    $blocked = ['dashboard', 'menu_hub', 'favorites_empty'];
    if (in_array($screenCode, $blocked, true)) {
        return ['ok' => false, 'favorited' => false, 'message' => 'لا يمكن تفضيل هذه الشاشة.'];
    }
    $routes = require app_path('config/routes.php');
    if (!isset($routes[$screenCode])) {
        return ['ok' => false, 'favorited' => false, 'message' => 'الشاشة غير معروفة.'];
    }
    $perm = (string) ($routes[$screenCode]['permission'] ?? $screenCode);
    if ($perm !== '' && !user_can($perm)) {
        return ['ok' => false, 'favorited' => false, 'message' => 'لا تملك صلاحية على هذه الشاشة.'];
    }

    try {
        $st = $pdo->prepare('SELECT id FROM sys_user_favorite WHERE user_id = ? AND screen_code = ? LIMIT 1');
        $st->execute([$userId, $screenCode]);
        $id = $st->fetchColumn();
        if ($id !== false && (int) $id > 0) {
            $pdo->prepare('DELETE FROM sys_user_favorite WHERE id = ?')->execute([(int) $id]);

            return ['ok' => true, 'favorited' => false, 'message' => 'أُزيلت من المفضلة.'];
        }
        $stMax = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM sys_user_favorite WHERE user_id = ?');
        $stMax->execute([$userId]);
        $next = (int) $stMax->fetchColumn() + 1;
        $pdo->prepare(
            'INSERT INTO sys_user_favorite (user_id, screen_code, sort_order) VALUES (?, ?, ?)'
        )->execute([$userId, $screenCode, $next]);

        return ['ok' => true, 'favorited' => true, 'message' => 'أُضيفت إلى المفضلة.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'favorited' => false, 'message' => 'تعذر حفظ المفضلة.'];
    }
}

/**
 * يرجع عناصر مفضلات المستخدم الحالي مع التسميات والمسارات الجاهزة للقائمة.
 *
 * @return list<array{r:string, label:string, icon:string}>
 */
function sys_favorites_menu_items_for_user(PDO $pdo, int $userId): array
{
    if ($userId < 1) {
        return [];
    }
    $codes = sys_favorites_codes_for_user($pdo, $userId);
    if ($codes === []) {
        return [];
    }
    $routes = require app_path('config/routes.php');
    $menu = require app_path('config/nav_menu.php');

    $iconByCode = [];
    $labelByCode = [];
    foreach ($menu['domains'] as $domain) {
        foreach ($domain['subgroups'] as $sg) {
            foreach ($sg['items'] ?? [] as $it) {
                if (!is_array($it) || empty($it['r'])) {
                    continue;
                }
                $r = (string) $it['r'];
                if (!isset($iconByCode[$r])) {
                    $iconByCode[$r] = (string) ($it['icon'] ?? '★');
                    $labelByCode[$r] = (string) ($it['label'] ?? '');
                }
            }
            foreach ($sg['subgroups'] ?? [] as $nested) {
                if (!is_array($nested)) {
                    continue;
                }
                foreach ($nested['items'] ?? [] as $it) {
                    if (!is_array($it) || empty($it['r'])) {
                        continue;
                    }
                    $r = (string) $it['r'];
                    if (!isset($iconByCode[$r])) {
                        $iconByCode[$r] = (string) ($it['icon'] ?? '★');
                        $labelByCode[$r] = (string) ($it['label'] ?? '');
                    }
                }
            }
        }
    }

    $screenNames = [];
    try {
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $st = $pdo->prepare("SELECT code, name_ar FROM sys_screen WHERE code IN ($placeholders)");
        $st->execute($codes);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $screenNames[(string) $row['code']] = (string) $row['name_ar'];
        }
    } catch (Throwable $e) {
        // ignore
    }

    $out = [];
    foreach ($codes as $code) {
        if (!isset($routes[$code])) {
            continue;
        }
        $perm = (string) ($routes[$code]['permission'] ?? $code);
        if ($perm !== '' && !user_can($perm)) {
            continue;
        }
        $label = $labelByCode[$code]
            ?? ($screenNames[$code] ?? '')
            ?: (string) ($routes[$code]['title'] ?? $code);
        $icon = $iconByCode[$code] ?? '★';
        $out[] = ['r' => $code, 'label' => $label, 'icon' => $icon];
    }

    return $out;
}
