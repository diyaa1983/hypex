<?php
declare(strict_types=1);

/** @return array{exit_route:string,buttons:list<array<string,mixed>>} */
function master_toolbar_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require app_path('config/master_toolbar.php');
    }

    return $cfg;
}

/** أيقونة SVG لزر الخروج (العودة للشبكة) — currentColor يتوافق مع لون الزر. */
function master_toolbar_exit_icon_svg(): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        . '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>'
        . '<polyline points="16 17 21 12 16 7"/>'
        . '<line x1="21" y1="12" x2="9" y2="12"/>'
        . '</svg>';
}

function app_logout_icon_svg(): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        . '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>'
        . '<polyline points="16 17 21 12 16 7"/>'
        . '<line x1="21" y1="12" x2="9" y2="12"/>'
        . '</svg>';
}

/** اسم المستخدم + أيقونة تسجيل الخروج في الترويسة. */
function render_app_header_account(string $userLabel, string $logoutUrl): void
{
    echo '<div class="app-header-account">';
    echo '<span class="app-header-account__name">' . esc($userLabel) . '</span>';
    echo '<a class="app-header-logout-btn" href="' . esc($logoutUrl) . '" title="' . esc(t('تسجيل خروج')) . '" aria-label="' . esc(t('تسجيل خروج')) . '">';
    echo '<span class="app-header-logout-btn__icon" aria-hidden="true">' . app_logout_icon_svg() . '</span>';
    echo '</a>';
    echo '</div>';
}

/** @param array<string, mixed> $btn */
function master_toolbar_button_visible(array $btn, string $activeRoute): bool
{
    $screens = $btn['screens'] ?? null;
    if (is_array($screens) && $screens !== [] && !in_array($activeRoute, $screens, true)) {
        return false;
    }

    $hide = $btn['hide_screens'] ?? null;
    if (is_array($hide) && $hide !== [] && in_array($activeRoute, $hide, true)) {
        return false;
    }

    $perm = master_toolbar_button_permission($btn, $activeRoute);
    if ($perm !== '' && !user_can($perm)) {
        return false;
    }

    return true;
}

/** @param array<string, mixed> $btn */
function master_toolbar_button_permission(array $btn, string $activeRoute): string
{
    $byScreen = $btn['permission_by_screen'] ?? null;
    if (is_array($byScreen) && isset($byScreen[$activeRoute])) {
        return (string) $byScreen[$activeRoute];
    }

    return (string) ($btn['permission'] ?? '');
}

function render_master_toolbar(?string $activeRoute = null): void
{
    if ($activeRoute === null) {
        $activeRoute = (string) ($GLOBALS['activeRoute'] ?? '');
    }
    $cfg = master_toolbar_config();
    require_once app_path('includes/nav_helpers.php');
    $exitUrl = nav_exit_url($activeRoute);
    $closeUrl = nav_close_url($activeRoute);
    $ledgerBack = nav_item_stock_ledger_back_link();
    $buttons = $cfg['buttons'] ?? [];
    $allowedVariants = ['primary', 'secondary', 'danger'];

    $visibleButtons = [];
    foreach ($buttons as $btn) {
        if (!is_array($btn)) {
            continue;
        }
        $action = (string) ($btn['action'] ?? '');
        if ($action === '' || !master_toolbar_button_visible($btn, $activeRoute)) {
            continue;
        }
        $visibleButtons[] = $btn;
    }

    $visibleButtons = array_values(array_filter(
        $visibleButtons,
        static fn (array $btn): bool => ($btn['action'] ?? '') !== 'exit'
    ));

    echo '<div class="master-toolbar no-print" id="master-toolbar" role="toolbar" aria-label="' . esc(t('شريط الإجراءات')) . '"';
    echo ' data-active-route="' . esc($activeRoute) . '"';
    echo ' data-exit-url="' . esc($exitUrl) . '"';
    echo ' data-close-url="' . esc($closeUrl) . '">';
    echo '<div class="master-toolbar-inner">';

    foreach ($visibleButtons as $btn) {
        $action = (string) ($btn['action'] ?? '');
        $label = t((string) ($btn['label'] ?? $action));
        if ($action === 'exit' && $ledgerBack !== null) {
            $label = '← ' . t((string) $ledgerBack['label']);
        }
        $variant = (string) ($btn['variant'] ?? 'secondary');
        if (!in_array($variant, $allowedVariants, true)) {
            $variant = 'secondary';
        }
        $title = t((string) ($btn['title'] ?? $btn['label'] ?? $label));
        if ($action === 'exit' && $ledgerBack !== null) {
            $title = t('العودة إلى ') . t((string) $ledgerBack['label']);
        }
        $display = (string) ($btn['display'] ?? 'text');
        $iconOnly = $display === 'icon';
        $ariaLabel = $title !== '' ? $title : $label;

        $class = 'btn btn-' . $variant . ' master-toolbar-btn';
        if ($iconOnly) {
            $class .= ' master-toolbar-btn--icon';
        }
        $titleAttr = $title !== '' ? ' title="' . esc($title) . '"' : '';
        $ariaAttr = $iconOnly && $ariaLabel !== '' ? ' aria-label="' . esc($ariaLabel) . '"' : '';

        echo '<button type="button" class="' . $class . '" data-master-action="' . esc($action) . '"' . $titleAttr . $ariaAttr . '>';
        if ($iconOnly && $action === 'exit') {
            echo '<span class="master-toolbar-icon" aria-hidden="true">' . master_toolbar_exit_icon_svg() . '</span>';
            echo '<span class="sr-only">' . esc($label) . '</span>';
        } else {
            echo esc($label);
        }
        echo '</button>';
    }

    echo '</div></div>';
}

function nav_show_exit_button(string $activeRoute): bool
{
    return !in_array($activeRoute, ['dashboard', 'menu_hub'], true);
}

/** زر الخروج من الشاشة — بجانب «تسجيل خروج» وليس في شريط الأدوات. */
function render_nav_exit_button(?string $activeRoute = null): void
{
    if ($activeRoute === null) {
        $activeRoute = (string) ($GLOBALS['activeRoute'] ?? '');
    }
    if (!nav_show_exit_button($activeRoute)) {
        return;
    }
    require_once app_path('includes/nav_helpers.php');
    $exitUrl = nav_exit_url($activeRoute);
    $ledgerBack = nav_item_stock_ledger_back_link();
    $hub = nav_resolve_active_hub($activeRoute);
    $hubUrl = nav_hub_folder_url($hub);
    $storedHub = trim((string) ($_SESSION['nav_return_url'] ?? ''));

    if ($storedHub !== '' && nav_is_safe_back_url($storedHub)) {
        $title = 'الخروج والعودة إلى قائمة الشاشات';
    } elseif ($hubUrl !== null) {
        $title = 'الخروج والعودة إلى قائمة الشاشات';
    } elseif ($ledgerBack !== null) {
        $title = 'العودة إلى ' . (string) ($ledgerBack['label'] ?? '');
        $exitUrl = (string) ($ledgerBack['url'] ?? $exitUrl);
    } else {
        $title = 'الخروج والعودة إلى لوحة التحكم';
    }

    echo '<a class="nav-exit-btn" href="' . esc($exitUrl) . '" title="' . esc($title) . '" aria-label="' . esc($title) . '">';
    echo '<span class="nav-exit-btn__icon" aria-hidden="true">' . master_toolbar_exit_icon_svg() . '</span>';
    echo '</a>';
}
