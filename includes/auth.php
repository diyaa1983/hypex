<?php
declare(strict_types=1);

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect(app_url('login.php'));
    }
}

function user_is_system_admin(?int $userId = null): bool
{
    if ($userId === null) {
        if (array_key_exists('is_system_admin', $_SESSION)) {
            return (bool) $_SESSION['is_system_admin'];
        }
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
    $st->execute(['ADMINS', $userId]);
    $isAdmin = (bool) $st->fetchColumn();

    if ($userId === (int) (current_user()['id'] ?? 0)) {
        $_SESSION['is_system_admin'] = $isAdmin;
    }

    return $isAdmin;
}

function load_user_permissions(int $userId): array
{
    if (user_is_system_admin($userId)) {
        $codes = db()->query('SELECT code FROM sys_screen ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN);
        return $codes ?: [];
    }

    $sql = 'SELECT DISTINCT s.code
            FROM sys_user_group ug
            INNER JOIN sys_group_permission gp ON gp.group_id = ug.group_id AND gp.allowed = 1
            INNER JOIN sys_screen s ON s.id = gp.screen_id
            WHERE ug.user_id = ?';
    $st = db()->prepare($sql);
    $st->execute([$userId]);
    return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/** إعادة تحميل صلاحيات الجلسة من قاعدة البيانات بعد تعديل المجموعات أو صلاحيات الشاشات. */
function refresh_session_permissions(?int $userId = null): void
{
    if (!is_logged_in()) {
        return;
    }

    if ($userId === null || $userId < 1) {
        $userId = (int) (current_user()['id'] ?? 0);
    }
    if ($userId < 1) {
        return;
    }

    unset($_SESSION['is_system_admin']);
    if (($_SESSION['app_context'] ?? '') === 'mobile') {
        require_once app_path('includes/mobile_auth.php');
        $_SESSION['permissions'] = load_user_mobile_permissions($userId);
    } else {
        $_SESSION['permissions'] = load_user_permissions($userId);
    }
    $_SESSION['permissions_user_id'] = $userId;
}

function user_can(string $screenCode): bool
{
    if (user_is_system_admin()) {
        return true;
    }
    $perms = $_SESSION['permissions'] ?? [];
    return in_array($screenCode, $perms, true);
}

/** صلاحية إجراء حساس (فك ترحيل، حذف، ترحيل، فوترة…). */
function user_can_action(string $actionCode): bool
{
    return user_can($actionCode);
}

/** يتطلب صلاحية الشاشة وصلاحية الإجراء معاً. */
function user_can_screen_and_action(string $screenCode, string $actionCode): bool
{
    return user_can($screenCode) && user_can_action($actionCode);
}

/** صلاحية شاشة إنشاء الفاتورة أو شاشة قائمة الفواتير. */
function user_can_sales_invoices(): bool
{
    return user_can('sales_invoices') || user_can('sales_invoices_list');
}

/** صلاحية شاشة المرتجع أو شاشة ترحيل المرتجعات. */
function user_can_sales_returns(): bool
{
    return user_can('sales_returns') || user_can('sales_returns_list');
}

function user_can_sales_delivery(): bool
{
    return user_can('sales_delivery');
}

/** صلاحية طلب الشراء أو قائمة اعتماد الطلبات. */
function user_can_purchase_orders(): bool
{
    return user_can('purchase_orders') || user_can('purchase_orders_list');
}

/** صلاحية فاتورة الشراء أو قائمة ترحيل فواتير الشراء. */
function user_can_purchase_invoices(): bool
{
    return user_can('purchase_invoices') || user_can('purchase_invoices_list');
}

/** صلاحية مردود المشتريات أو قائمة ترحيل المردودات. */
function user_can_purchase_returns(): bool
{
    return user_can('purchase_returns') || user_can('purchase_returns_list');
}

function require_permission(string $screenCode): void
{
    if (!user_can($screenCode)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>ممنوع</title>';
        echo '<body style="font-family:system-ui;padding:2rem;">ليس لديك صلاحية لهذه الشاشة.</body></html>';
        exit;
    }
}

/** التحقق من كلمة مرور المستخدم الحالي (لإجراءات حساسة مثل تعديل سند مرحّل). */
function verify_current_user_password(string $password): bool
{
    $user = current_user();
    if (!$user || trim($password) === '') {
        return false;
    }

    $st = db()->prepare('SELECT password_hash FROM sys_user WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([(int) ($user['id'] ?? 0)]);
    $hash = $st->fetchColumn();
    if (!$hash || !is_string($hash)) {
        return false;
    }

    return password_verify($password, $hash);
}

function attempt_login(string $username, string $password): bool
{
    $username = trim($username);
    $st = db()->prepare('SELECT id, username, password_hash, full_name_ar, is_active FROM sys_user WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $row = $st->fetch();
    if (!$row || !(int) $row['is_active']) {
        return false;
    }
    if (!password_verify($password, $row['password_hash'])) {
        return false;
    }

    $uid = (int) $row['id'];
    $_SESSION['user'] = [
        'id' => $uid,
        'username' => $row['username'],
        'full_name_ar' => $row['full_name_ar'],
    ];
    $_SESSION['is_system_admin'] = user_is_system_admin($uid);
    $_SESSION['permissions'] = load_user_permissions($uid);
    $_SESSION['permissions_user_id'] = $uid;
    $_SESSION['app_context'] = 'desktop';
    try {
        require_once app_path('includes/company_settings.php');
        unset($_SESSION['ui_theme'], $_SESSION['ui_theme_loaded']);
    } catch (Throwable $e) {
        unset($_SESSION['ui_theme'], $_SESSION['ui_theme_loaded']);
    }
    unset($_SESSION['fin_check_due_email_boot']);
    unset($_SESSION['fin_out_check_due_email_boot']);
    session_regenerate_id(true);
    try {
        require_once app_path('includes/sys_user_open_session.php');
        sys_user_open_session_register_windows($uid);
    } catch (Throwable $e) {
        error_log('attempt_login open_session: ' . $e->getMessage());
    }
    return true;
}

function logout(): void
{
    try {
        require_once app_path('includes/sys_user_open_session.php');
        sys_user_open_session_close_current();
    } catch (Throwable $e) {
        error_log('logout open_session: ' . $e->getMessage());
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
