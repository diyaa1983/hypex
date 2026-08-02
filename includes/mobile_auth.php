<?php
declare(strict_types=1);

const MOBILE_GROUP_CODE = 'MOBILE';

function mobile_url(string $query = ''): string
{
    $base = app_url('m/index.php');
    $query = trim($query);
    if ($query === '') {
        return $base;
    }
    if ($query[0] === '?') {
        return $base . $query;
    }

    return $base . '?' . $query;
}

function mobile_login_url(): string
{
    return app_url('m/login.php');
}

function mobile_is_context(): bool
{
    return ($_SESSION['app_context'] ?? '') === 'mobile';
}

/** طلب HTTP من مسار /m/ — أدق من الجلسة وحدها عند التوجيه بعد الحفظ. */
function app_request_from_mobile_app(): bool
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    return str_contains($script, '/m/');
}

function user_in_mobile_group(?int $userId = null): bool
{
    if ($userId === null) {
        $user = current_user();
        if (!$user) {
            return false;
        }
        $userId = (int) $user['id'];
    }

    $st = db()->prepare(
        'SELECT 1 FROM sys_user_group ug
         INNER JOIN sys_group g ON g.id = ug.group_id AND g.code = ?
         WHERE ug.user_id = ? LIMIT 1'
    );
    $st->execute([MOBILE_GROUP_CODE, $userId]);

    return (bool) $st->fetchColumn();
}

function load_user_mobile_permissions(int $userId): array
{
    if (user_is_system_admin($userId)) {
        $codes = db()->query(
            "SELECT code FROM sys_screen WHERE code LIKE 'm_%' ORDER BY sort_order, id"
        )->fetchAll(PDO::FETCH_COLUMN);

        return $codes ?: ['m_home'];
    }

    $sql = 'SELECT DISTINCT s.code
            FROM sys_user_group ug
            INNER JOIN sys_group_permission gp ON gp.group_id = ug.group_id AND gp.allowed = 1
            INNER JOIN sys_screen s ON s.id = gp.screen_id
            WHERE ug.user_id = ? AND s.code LIKE ?
            ORDER BY s.code';
    $st = db()->prepare($sql);
    $st->execute([$userId, 'm_%']);

    return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function mobile_attempt_login(string $username, string $password): bool
{
    if (!attempt_login($username, $password)) {
        return false;
    }

    $uid = (int) (current_user()['id'] ?? 0);
    if ($uid < 1 || !user_in_mobile_group($uid)) {
        logout();
        return false;
    }

    $_SESSION['app_context'] = 'mobile';
    $_SESSION['permissions'] = load_user_mobile_permissions($uid);
    $_SESSION['permissions_user_id'] = $uid;
    $_SESSION['permissions_loaded_at'] = time();

    try {
        require_once app_path('includes/sys_user_open_session.php');
        $prev = (string) ($_SESSION['open_session_token'] ?? '');
        if ($prev !== '') {
            sys_user_open_session_close_token(db(), $prev);
        }
        $sid = session_id();
        if ($sid !== '') {
            sys_user_open_session_register(db(), $uid, 'mw:' . substr($sid, 0, 120), 'mobile', [
                'client_label' => 'هاتف ويب',
            ]);
        }
    } catch (Throwable $e) {
        error_log('mobile_attempt_login open_session: ' . $e->getMessage());
    }

    return true;
}

function require_mobile_login(): void
{
    if (!is_logged_in()) {
        redirect(mobile_login_url());
    }
    if (!mobile_is_context() || !user_in_mobile_group()) {
        logout();
        redirect(mobile_login_url());
    }
}

function require_mobile_permission(string $screenCode): void
{
    require_mobile_login();
    if (!user_can($screenCode)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>ممنوع</title><body style="font-family:system-ui;padding:1.5rem;text-align:center;">';
        echo '<p>ليس لديك صلاحية لهذه الشاشة على الهاتف.</p>';
        echo '<p><a href="' . esc(mobile_url('r=m_home')) . '">العودة</a></p></body></html>';
        exit;
    }
}

function mobile_logout(): void
{
    logout();
}

/**
 * شاشات تظهر في الرئيسية (مربعات) — من routes_mobile.php فقط.
 *
 * @return list<array{code: string, label: string, icon: string, url: string, kind: string}>
 */
function mobile_home_launcher_tiles(): array
{
    /** @var array<string, array<string, mixed>> $routes */
    $routes = require app_path('config/routes_mobile.php');
    $tiles = [];
    foreach ($routes as $code => $route) {
        if ($code === 'm_home' || !is_array($route)) {
            continue;
        }
        if (array_key_exists('home_tile', $route) && $route['home_tile'] === false) {
            continue;
        }
        if (!array_key_exists('home_tile', $route) && str_ends_with((string) $code, '_view')) {
            continue;
        }
        $perm = (string) ($route['permission'] ?? $code);
        if ($code === 'm_rep_custody_list') {
            require_once app_path('includes/mobile_rep_custody.php');
            if (!mobile_can_access_rep_custody_list()) {
                continue;
            }
        } elseif ($code === 'm_rep_route_today') {
            if (
                !user_can('m_rep_route_today')
                && !user_can('m_customer_orders')
                && !user_can('m_sales_invoices')
                && !user_is_system_admin()
            ) {
                continue;
            }
        } elseif (!user_can($perm)) {
            continue;
        }
        $tiles[] = [
            'code' => (string) $code,
            'label' => (string) ($route['home_label'] ?? $route['title'] ?? $code),
            'icon' => (string) ($route['icon'] ?? 'invoice'),
            'kind' => (string) ($route['tile_kind'] ?? 'doc'),
            'url' => mobile_url('r=' . rawurlencode((string) $code)),
        ];
    }

    return $tiles;
}
