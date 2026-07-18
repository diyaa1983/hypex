<?php
declare(strict_types=1);

/** @return array<string, mixed> */
function nav_menu_config(): array
{
    static $menu = null;
    if ($menu === null) {
        $menu = require app_path('config/nav_menu.php');
        $menu = nav_menu_inject_favorites($menu);
    }

    return $menu;
}

/**
 * يحقن مجال «المفضلة» الديناميكي من قاعدة بيانات المستخدم الحالي في آخر القائمة (بعد «النظام»).
 * يظهر دائماً حتى لو كانت قائمة المفضلة فارغة (لإرشاد المستخدم).
 */
function nav_menu_inject_favorites(array $menu): array
{
    if (!is_logged_in()) {
        return $menu;
    }
    require_once app_path('includes/sys_favorites.php');
    try {
        $pdo = db();
    } catch (Throwable $e) {
        return $menu;
    }
    $userId = (int) (current_user()['id'] ?? 0);
    $items = sys_favorites_menu_items_for_user($pdo, $userId);

    $favDomain = [
        'id' => 'favorites',
        'title' => 'المفضلة',
        'subgroups' => [
            [
                'id' => 'favorites',
                'title' => 'المفضلة',
                'items' => $items,
            ],
        ],
    ];

    $domains = [];
    foreach ($menu['domains'] ?? [] as $domain) {
        if ((string) ($domain['id'] ?? '') === 'favorites') {
            continue;
        }
        $domains[] = $domain;
    }
    $domains[] = $favDomain;
    $menu['domains'] = $domains;

    return $menu;
}

function nav_hub_url(string $domainId, string $subId, string $nestedSubId = ''): string
{
    $q = 'index.php?r=menu_hub&d=' . rawurlencode($domainId) . '&s=' . rawurlencode($subId);
    if ($nestedSubId !== '') {
        $q .= '&ss=' . rawurlencode($nestedSubId);
    }

    return app_url($q);
}

/** عرض مجلدات المجال في منطقة المحتوى الرئيسية. */
function nav_domain_hub_url(string $domainId): string
{
    return app_url('index.php?r=menu_hub&d=' . rawurlencode($domainId));
}

/** @return array<string, mixed>|null */
function nav_find_domain(string $domainId): ?array
{
    foreach (nav_menu_config()['domains'] as $domain) {
        if (($domain['id'] ?? '') === $domainId) {
            return $domain;
        }
    }

    return null;
}

/** @return array{domain: array, subgroup: array}|null */
function nav_find_subgroup(string $domainId, string $subId): ?array
{
    $domain = nav_find_domain($domainId);
    if (!$domain) {
        return null;
    }
    foreach ($domain['subgroups'] as $sg) {
        if (($sg['id'] ?? '') === $subId) {
            return ['domain' => $domain, 'subgroup' => $sg];
        }
    }

    return null;
}

/** @return list<array<string, mixed>> */
function nav_subgroup_nested_folders(array $subgroup): array
{
    $out = [];
    foreach ($subgroup['subgroups'] ?? [] as $child) {
        if (!is_array($child)) {
            continue;
        }
        if (nav_subgroup_visible($child)) {
            $out[] = $child;
        }
    }

    return $out;
}

function nav_subgroup_is_folder_container(array $subgroup): bool
{
    return nav_subgroup_nested_folders($subgroup) !== [] && nav_subgroup_allowed_items($subgroup) === [];
}

/** @return array{domain: array, subgroup: array, nested_subgroup?: array}|null */
function nav_find_subgroup_path(string $domainId, string $subId, string $nestedSubId = ''): ?array
{
    $found = nav_find_subgroup($domainId, $subId);
    if ($found === null) {
        return null;
    }

    $nestedSubId = trim($nestedSubId);
    if ($nestedSubId === '') {
        return $found;
    }

    foreach ($found['subgroup']['subgroups'] ?? [] as $child) {
        if (!is_array($child) || ($child['id'] ?? '') !== $nestedSubId) {
            continue;
        }

        return [
            'domain' => $found['domain'],
            'subgroup' => $found['subgroup'],
            'nested_subgroup' => $child,
        ];
    }

    return null;
}

/** @return list<array{r: string, label: string, icon: string}> */
function nav_subgroup_allowed_items(array $subgroup): array
{
    $out = [];
    foreach ($subgroup['items'] ?? [] as $it) {
        if (!is_array($it) || empty($it['r'])) {
            continue;
        }
        if (!empty($it['always_visible']) || user_can((string) $it['r'])) {
            $out[] = $it;
        }
    }

    return $out;
}

function nav_domain_visible(array $domain): bool
{
    if ((string) ($domain['id'] ?? '') === 'favorites') {
        return true;
    }

    foreach ($domain['subgroups'] as $sg) {
        if (nav_subgroup_visible($sg)) {
            return true;
        }
    }

    return false;
}

function nav_subgroup_visible(array $subgroup): bool
{
    if (nav_subgroup_allowed_items($subgroup) !== []) {
        return true;
    }

    return nav_subgroup_nested_folders($subgroup) !== [];
}

/** @return array{domain_id: string, sub_id: string}|null */
function nav_resolve_active_hub(string $activeRoute): ?array
{
    if ($activeRoute === 'menu_hub') {
        $d = trim((string) ($_GET['d'] ?? ''));
        $s = trim((string) ($_GET['s'] ?? ''));
        $ss = trim((string) ($_GET['ss'] ?? ''));
        if ($d !== '') {
            $hub = ['domain_id' => $d, 'sub_id' => $s];
            if ($ss !== '') {
                $hub['nested_sub_id'] = $ss;
            }

            return $hub;
        }

        return null;
    }

    foreach (nav_menu_config()['domains'] as $domain) {
        $domainId = (string) ($domain['id'] ?? '');
        foreach ($domain['subgroups'] as $sg) {
            $subId = (string) ($sg['id'] ?? '');
            foreach ($sg['items'] ?? [] as $it) {
                if (is_array($it) && ($it['r'] ?? '') === $activeRoute && user_can((string) $it['r'])) {
                    return ['domain_id' => $domainId, 'sub_id' => $subId];
                }
            }
            foreach ($sg['subgroups'] ?? [] as $nested) {
                if (!is_array($nested)) {
                    continue;
                }
                $nestedSubId = (string) ($nested['id'] ?? '');
                foreach ($nested['items'] ?? [] as $it) {
                    if (is_array($it) && ($it['r'] ?? '') === $activeRoute && user_can((string) $it['r'])) {
                        return [
                            'domain_id' => $domainId,
                            'sub_id' => $subId,
                            'nested_sub_id' => $nestedSubId,
                        ];
                    }
                }
            }
        }
    }

    return null;
}

/** أول مجموعة فرعية متاحة في المجال (للتوجيه التلقائي). */
function nav_first_visible_subgroup_id(array $domain): ?string
{
    foreach ($domain['subgroups'] as $sg) {
        if (nav_subgroup_visible($sg)) {
            return (string) ($sg['id'] ?? '');
        }
    }

    return null;
}

/** تسجيل زيارة مجلد أصفر (subgroup) لتفعيل زر العودة للمجلد السابق. */
function nav_hub_track_folder_visit(string $domainId, string $subId, string $subTitle, string $nestedSubId = ''): void
{
    if ($domainId === '' || $subId === '') {
        return;
    }

    $current = [
        'd' => $domainId,
        's' => $subId,
        'title' => trim($subTitle) !== '' ? trim($subTitle) : $subId,
    ];
    if ($nestedSubId !== '') {
        $current['ss'] = $nestedSubId;
    }

    $last = $_SESSION['nav_hub_last_folder'] ?? null;
    if (
        is_array($last)
        && (
            ($last['d'] ?? '') !== $domainId
            || ($last['s'] ?? '') !== $subId
            || (string) ($last['ss'] ?? '') !== $nestedSubId
        )
    ) {
        $_SESSION['nav_hub_prev_folder'] = [
            'd' => (string) ($last['d'] ?? ''),
            's' => (string) ($last['s'] ?? ''),
            'ss' => (string) ($last['ss'] ?? ''),
            'title' => (string) ($last['title'] ?? ''),
        ];
    }

    $_SESSION['nav_hub_last_folder'] = $current;
}

/**
 * رابط العودة للمجلد الأصفر الذي كان مفتوحاً قبل المجلد الحالي.
 *
 * @return array{url: string, label: string}|null
 */
function nav_hub_previous_folder_link(string $currentDomainId, string $currentSubId, string $currentNestedSubId = ''): ?array
{
    $prev = $_SESSION['nav_hub_prev_folder'] ?? null;
    if (!is_array($prev)) {
        return null;
    }

    $d = trim((string) ($prev['d'] ?? ''));
    $s = trim((string) ($prev['s'] ?? ''));
    $ss = trim((string) ($prev['ss'] ?? ''));
    $title = trim((string) ($prev['title'] ?? ''));
    if (
        $d === ''
        || $s === ''
        || ($d === $currentDomainId && $s === $currentSubId && $ss === $currentNestedSubId)
    ) {
        return null;
    }

    $found = nav_find_subgroup_path($d, $s, $ss);
    if ($found === null) {
        return null;
    }
    if ($ss !== '') {
        if (!nav_subgroup_visible($found['nested_subgroup'] ?? [])) {
            return null;
        }
    } elseif (!nav_subgroup_visible($found['subgroup'])) {
        return null;
    }

    if ($title === '') {
        if ($ss !== '' && isset($found['nested_subgroup'])) {
            $title = (string) ($found['nested_subgroup']['title'] ?? $ss);
        } else {
            $title = (string) ($found['subgroup']['title'] ?? $s);
        }
    }

    return [
        'url' => nav_hub_url($d, $s, $ss),
        'label' => $title,
    ];
}

/** تسجيل مصدر العودة من hub_d / hub_s في الطلب (يُستدعى من index.php). */
function nav_apply_return_from_request(string $activeRoute): void
{
    if ($activeRoute === 'menu_hub') {
        $d = trim((string) ($_GET['d'] ?? ''));
        $s = trim((string) ($_GET['s'] ?? ''));
        if ($d !== '' && $s !== '') {
            unset($_SESSION['nav_return_url'], $_SESSION['nav_return_hub']);
        }

        return;
    }

    $hubD = trim((string) ($_GET['hub_d'] ?? ''));
    $hubS = trim((string) ($_GET['hub_s'] ?? ''));
    $hubSs = trim((string) ($_GET['hub_ss'] ?? ''));
    if ($hubD === '' || $hubS === '' || nav_find_subgroup_path($hubD, $hubS, $hubSs) === null) {
        return;
    }

    $_SESSION['nav_return_url'] = nav_hub_url($hubD, $hubS, $hubSs);
    $_SESSION['nav_return_hub'] = ['d' => $hubD, 's' => $hubS, 'ss' => $hubSs];
}

/** عنوان URL الحالي للشاشة (مع معاملات GET). */
function nav_current_request_url(): string
{
    $params = $_GET;
    if ($params === []) {
        return app_url('index.php?r=dashboard');
    }

    return app_url('index.php?' . http_build_query($params));
}

function nav_is_safe_back_url(string $url): bool
{
    $url = trim($url);
    if ($url === '' || str_contains($url, "\n") || str_contains($url, "\r")) {
        return false;
    }

    $appBase = rtrim(app_url(''), '/');
    if (!str_starts_with($url, $appBase)) {
        return false;
    }

    if (!str_contains($url, 'index.php')) {
        return false;
    }

    if (preg_match('/[?&]r=logout\b/', $url)) {
        return false;
    }

    return true;
}

/** @param list<array{url:string, route:string}> $stack */
function nav_sync_back_from_stack(array $stack): void
{
    $n = count($stack);
    if ($n >= 2) {
        $_SESSION['nav_back_url'] = (string) ($stack[$n - 2]['url'] ?? '');
        $_SESSION['nav_back_route'] = (string) ($stack[$n - 2]['route'] ?? '');
    } else {
        unset($_SESSION['nav_back_url'], $_SESSION['nav_back_route']);
    }
}

/** تسجيل الصفحة السابقة للعودة إليها عند الإغلاق (×) أو «رجوع». */
function nav_track_page_visit(string $activeRoute): void
{
    if (!is_logged_in()) {
        return;
    }

    if (in_array($activeRoute, ['login', 'logout'], true)) {
        return;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }

    $url = nav_current_request_url();
    if (!nav_is_safe_back_url($url)) {
        return;
    }

    if (!isset($_SESSION['nav_stack']) || !is_array($_SESSION['nav_stack'])) {
        $_SESSION['nav_stack'] = [];
    }

    /** @var list<array{url:string, route:string}> $stack */
    $stack = $_SESSION['nav_stack'];

    if (in_array($activeRoute, ['menu_hub', 'dashboard'], true)) {
        $_SESSION['nav_stack'] = [['url' => $url, 'route' => $activeRoute]];
        unset($_SESSION['nav_back_url'], $_SESSION['nav_back_route']);
        $_SESSION['nav_last_url'] = $url;
        $_SESSION['nav_last_route'] = $activeRoute;

        return;
    }

    $count = count($stack);
    if ($count >= 2 && (string) ($stack[$count - 2]['url'] ?? '') === $url) {
        array_pop($stack);
        $_SESSION['nav_stack'] = $stack;
        nav_sync_back_from_stack($stack);
        $_SESSION['nav_last_url'] = $url;
        $_SESSION['nav_last_route'] = $activeRoute;

        return;
    }

    if ($count > 0 && (string) ($stack[$count - 1]['url'] ?? '') === $url) {
        return;
    }

    $stack[] = ['url' => $url, 'route' => $activeRoute];
    if (count($stack) > 40) {
        array_shift($stack);
    }
    $_SESSION['nav_stack'] = $stack;
    nav_sync_back_from_stack($stack);

    $_SESSION['nav_last_url'] = $url;
    $_SESSION['nav_last_route'] = $activeRoute;
}

/** @return array{url: string, route: string}|null */
function nav_back_link(string $activeRoute = ''): ?array
{
    if ($activeRoute === '') {
        $activeRoute = (string) ($GLOBALS['activeRoute'] ?? '');
    }

    $ledgerBack = nav_item_stock_ledger_back_link();
    if ($ledgerBack !== null) {
        return [
            'url' => (string) ($ledgerBack['url'] ?? ''),
            'route' => 'item_stock_movements',
        ];
    }

    $back = trim((string) ($_SESSION['nav_back_url'] ?? ''));
    $current = nav_current_request_url();
    if ($back !== '' && $back !== $current && nav_is_safe_back_url($back)) {
        return [
            'url' => $back,
            'route' => (string) ($_SESSION['nav_back_route'] ?? ''),
        ];
    }

    return null;
}

/** رابط شاشة حركة مادة مع نفس اختيارات البحث. */
function nav_item_stock_ledger_return_url(int $itemId, int $warehouseId): string
{
    return app_url(
        'index.php?r=item_stock_movements&run=1'
        . '&item_id=' . $itemId
        . '&warehouse_id=' . $warehouseId
    );
}

/** فتح المستند من كشف حركات مادة — عرض فقط بدون إنشاء أو تعديل. */
function nav_is_ledger_view_request(): bool
{
    return nav_ledger_return_query_from_request() !== '';
}

/** معاملات العودة من شاشة حركة مادة (من الطلب الحالي). */
function nav_ledger_return_query_from_request(): string
{
    if ((string) ($_GET['from_ledger'] ?? '') !== '1') {
        return '';
    }
    $itemId = (int) ($_GET['ledger_item_id'] ?? 0);
    $warehouseId = (int) ($_GET['ledger_warehouse_id'] ?? 0);
    if ($itemId < 1 || $warehouseId < 1) {
        return '';
    }

    return 'from_ledger=1&ledger_item_id=' . $itemId . '&ledger_warehouse_id=' . $warehouseId;
}

/** @return array{url: string, label: string}|null */
function nav_item_stock_ledger_back_link(): ?array
{
    $itemId = (int) ($_GET['ledger_item_id'] ?? 0);
    $warehouseId = (int) ($_GET['ledger_warehouse_id'] ?? 0);
    if ((string) ($_GET['from_ledger'] ?? '') !== '1' || $itemId < 1 || $warehouseId < 1) {
        return null;
    }

    return [
        'url' => nav_item_stock_ledger_return_url($itemId, $warehouseId),
        'label' => 'كشف حركات مادة',
    ];
}

/** إلحاق معاملات العودة لشاشة حركة مادة برابط مستند. */
function nav_append_ledger_return_query(string $url, int $itemId, int $warehouseId): string
{
    $url = trim($url);
    if ($url === '' || $itemId < 1 || $warehouseId < 1) {
        return $url;
    }

    $qs = 'from_ledger=1&ledger_item_id=' . $itemId . '&ledger_warehouse_id=' . $warehouseId;

    return $url . (str_contains($url, '?') ? '&' : '?') . $qs;
}

function nav_exit_url_from_ledger_request(): ?string
{
    $itemId = (int) ($_GET['ledger_item_id'] ?? 0);
    $warehouseId = (int) ($_GET['ledger_warehouse_id'] ?? 0);
    if ((string) ($_GET['from_ledger'] ?? '') !== '1' || $itemId < 1 || $warehouseId < 1) {
        return null;
    }

    return nav_item_stock_ledger_return_url($itemId, $warehouseId);
}

/** رابط مجلد القائمة (menu_hub) للشاشة في القائمة الجانبية. */
function nav_hub_folder_url(?array $hub): ?string
{
    if ($hub === null) {
        return null;
    }

    $subId = trim((string) ($hub['sub_id'] ?? ''));
    $nestedSubId = trim((string) ($hub['nested_sub_id'] ?? ''));
    if ($subId !== '') {
        return nav_hub_url((string) ($hub['domain_id'] ?? ''), $subId, $nestedSubId);
    }

    $domainId = trim((string) ($hub['domain_id'] ?? ''));
    if ($domainId === '') {
        return null;
    }

    return nav_domain_hub_url($domainId);
}

/** هل العودة المحفوظة هي لوحة التحكم فقط (وليست قائمة أيقونات). */
function nav_back_is_home_only(?array $back): bool
{
    if ($back === null) {
        return true;
    }

    $route = trim((string) ($back['route'] ?? ''));
    if ($route === 'menu_hub') {
        return false;
    }

    return $route === '' || $route === 'dashboard';
}

/** رابط إغلاق الشاشة (×) — العودة للصفحة السابقة مباشرة. */
function nav_close_url(string $activeRoute): string
{
    $ledgerBack = nav_exit_url_from_ledger_request();
    if ($ledgerBack !== null) {
        return $ledgerBack;
    }

    $back = nav_back_link($activeRoute);
    if ($back !== null && ($back['url'] ?? '') !== '') {
        return (string) $back['url'];
    }

    return nav_exit_url($activeRoute);
}

/** رابط الخروج الكامل — العودة لقائمة الأيقونات / المجلد الذي فُتحت منه الشاشة. */
function nav_exit_url(string $activeRoute): string
{
    $ledgerExit = nav_exit_url_from_ledger_request();
    if ($ledgerExit !== null) {
        return $ledgerExit;
    }

    $cfg = require app_path('config/master_toolbar.php');
    $exitRoute = (string) ($cfg['exit_route'] ?? 'dashboard');
    $default = app_url('index.php?r=' . rawurlencode($exitRoute));

    if ($activeRoute === 'menu_hub') {
        return $default;
    }

    $stored = trim((string) ($_SESSION['nav_return_url'] ?? ''));
    if ($stored !== '' && nav_is_safe_back_url($stored)) {
        return $stored;
    }

    $hub = nav_resolve_active_hub($activeRoute);
    $hubUrl = nav_hub_folder_url($hub);
    if ($hubUrl !== null) {
        return $hubUrl;
    }

    return $default;
}

/**
 * وضع الشاشة التشغيلية: ملء المحتوى + شريط علوي (بدون قائمة جانبية).
 */
function nav_layout_is_screen_focus(string $activeRoute): bool
{
    return !in_array($activeRoute, ['dashboard', 'menu_hub'], true);
}

/**
 * عنوان تبويب المتصفح / نافذة PWA.
 * اسم الشاشة فقط — اسم الشركة يظهر في manifest وشريط التطبيق.
 */
function app_browser_tab_title(string $pageTitle, string $activeRoute, string $companyNameAr): string
{
    $pageTitle = trim($pageTitle);
    if ($pageTitle !== '') {
        return $pageTitle;
    }

    $company = trim($companyNameAr);

    return $company !== '' ? $company : 'الشركة';
}

function nav_show_header_back_button(string $activeRoute): bool
{
    return $activeRoute !== 'dashboard';
}

/** رابط الشاشة الرئيسية (لوحة التحكم). */
function nav_home_url(): string
{
    $cfg = require app_path('config/master_toolbar.php');
    $homeRoute = (string) ($cfg['exit_route'] ?? 'dashboard');

    return app_url('index.php?r=' . rawurlencode($homeRoute));
}

function nav_show_header_home_button(string $activeRoute): bool
{
    $cfg = require app_path('config/master_toolbar.php');
    $homeRoute = (string) ($cfg['exit_route'] ?? 'dashboard');

    return $activeRoute !== $homeRoute;
}

/** زر إغلاق/رجوع في ترويسة التطبيق — الزاوية اليسرى (مثل ERPNext). */
function nav_back_button_info(string $activeRoute = ''): array
{
    if ($activeRoute === '') {
        $activeRoute = (string) ($GLOBALS['activeRoute'] ?? '');
    }

    $ledgerBack = nav_item_stock_ledger_back_link();
    if ($ledgerBack !== null) {
        return [
            'url' => (string) ($ledgerBack['url'] ?? nav_close_url($activeRoute)),
            'hint' => 'العودة إلى ' . (string) ($ledgerBack['label'] ?? ''),
        ];
    }

    $back = nav_back_link($activeRoute);
    if ($back !== null && trim((string) ($back['url'] ?? '')) !== '') {
        return [
            'url' => (string) $back['url'],
            'hint' => 'رجوع للصفحة السابقة',
        ];
    }

    return [
        'url' => nav_close_url($activeRoute),
        'hint' => 'رجوع',
    ];
}

function render_app_header_back_button(?string $activeRoute = null): void
{
    if ($activeRoute === null) {
        $activeRoute = (string) ($GLOBALS['activeRoute'] ?? '');
    }

    if (!nav_show_header_back_button($activeRoute)) {
        echo '<span class="app-titlebar__btn app-titlebar__btn--placeholder" aria-hidden="true"></span>';

        return;
    }

    require_once app_path('includes/app_window_manager.php');
    $activeRoute = app_mdi_resolve_route($activeRoute);
    $info = nav_back_button_info($activeRoute);
    $url = (string) ($info['url'] ?? nav_close_url($activeRoute));
    $hint = (string) ($info['hint'] ?? 'رجوع');
    if (app_mdi_is_embed_request()) {
        $url = app_mdi_embed_url($url);
    }

    echo '<a class="app-titlebar__btn app-titlebar__back-btn" href="' . esc($url) . '"';
    echo ' title="' . esc($hint) . '" aria-label="' . esc($hint) . '"><span class="app-titlebar__btn-icon" aria-hidden="true">←</span></a>';
}

/** شريط عنوان PWA — أزرار رجوع/تحديث + اسم الشاشة على سطر واحد. */
function render_app_titlebar_refresh_button(): void
{
    echo '<button type="button" class="app-titlebar__btn app-titlebar__refresh-btn" id="app-titlebar-refresh" title="تحديث الصفحة" aria-label="تحديث الصفحة">';
    echo '<span class="app-titlebar__btn-icon" aria-hidden="true">↻</span>';
    echo '</button>';
}

function render_app_titlebar_home_button(?string $activeRoute = null): void
{
    if ($activeRoute === null) {
        $activeRoute = (string) ($GLOBALS['activeRoute'] ?? '');
    }

    if (!nav_show_header_home_button($activeRoute)) {
        return;
    }

    $url = nav_home_url();
    $hint = 'الشاشة الرئيسية';

    echo '<a class="app-titlebar__btn app-titlebar__home-btn" href="' . esc($url) . '"';
    echo ' title="' . esc($hint) . '" aria-label="' . esc($hint) . '">';
    echo '<svg class="app-titlebar__btn-svg" viewBox="0 0 24 24" width="15" height="15" aria-hidden="true">';
    echo '<path fill="currentColor" d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>';
    echo '</svg></a>';
}

function render_app_titlebar(string $pageTitle, string $routeTitle, string $activeRoute, string $companyNameAr = ''): void
{
    $screen = trim($pageTitle) !== '' ? trim($pageTitle) : trim($routeTitle);
    $company = trim($companyNameAr);
    $fullTitle = $screen;
    if ($company !== '' && $screen !== '') {
        $fullTitle = $company . ' - ' . $screen;
    } elseif ($company !== '') {
        $fullTitle = $company;
    }

    echo '<header class="app-titlebar no-print" role="banner">';
    echo '<div class="app-titlebar__row">';
    render_app_header_back_button($activeRoute);
    render_app_titlebar_refresh_button();
    render_app_titlebar_home_button($activeRoute);
    if ($fullTitle !== '') {
        echo '<span class="app-titlebar__title app-titlebar__title--wco">' . esc($fullTitle) . '</span>';
        echo '<span class="app-titlebar__title app-titlebar__title--browser">' . esc($fullTitle) . '</span>';
    }
    echo '</div>';
    echo '</header>';
}

/** @return array{href: string, type: string} */
function app_favicon_meta(?array $settingsRow = null): array
{
    $defaultPath = app_path('assets/favicon.svg');
    $defaultV = is_file($defaultPath) ? (string) filemtime($defaultPath) : '';
    $defaultHref = app_url('assets/favicon.svg') . ($defaultV !== '' ? '?v=' . rawurlencode($defaultV) : '');
    $default = ['href' => $defaultHref, 'type' => 'image/svg+xml'];

    if ($settingsRow === null) {
        return $default;
    }

    $logoPath = trim((string) ($settingsRow['logo_path'] ?? ''));
    if ($logoPath === '' || !is_file(app_path($logoPath))) {
        return $default;
    }

    $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
    $types = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
    ];
    $type = $types[$ext] ?? 'image/png';
    $v = (string) (filemtime(app_path($logoPath)) ?: time());

    return [
        'href' => app_url($logoPath) . '?v=' . rawurlencode($v),
        'type' => $type,
    ];
}

/** أيقونة تبويب المتصفح (شعار الشركة أو الافتراضي). */
function render_app_favicon_links(?array $settingsRow = null): void
{
    $meta = app_favicon_meta($settingsRow);
    $href = esc($meta['href']);
    $type = esc($meta['type']);
    echo '<link rel="icon" href="' . $href . '" type="' . $type . '">' . "\n";
    echo '<link rel="shortcut icon" href="' . $href . '">' . "\n";
}

/** عنوان الشاشة بخط صغير أعلى المحتوى (يُستثنى menu_hub لأنه يعرض عنواناً داخل الصفحة). */
function render_app_screen_title(string $pageTitle, string $activeRoute = ''): void
{
    require_once app_path('includes/app_window_manager.php');
    require_once app_path('includes/sys_favorites.php');
    $activeRoute = app_mdi_resolve_route($activeRoute);
    $pageTitle = trim($pageTitle);
    if ($pageTitle === '' || $activeRoute === 'menu_hub' || $activeRoute === 'dashboard') {
        return;
    }
    echo '<header class="app-screen-title-bar">';
    echo '<h1 class="app-screen-title">' . esc($pageTitle) . '</h1>';
    sys_favorites_render_toggle_button($activeRoute, ['icon_size' => 22]);
    nav_render_screen_close($activeRoute);
    echo '</header>';
}
function nav_sidebar_domain_href(array $domain): string
{
    $domainId = (string) ($domain['id'] ?? '');
    $visibleSubs = [];
    foreach ($domain['subgroups'] as $sg) {
        if (nav_subgroup_visible($sg)) {
            $visibleSubs[] = $sg;
        }
    }

    if ($domainId === 'main' && count($visibleSubs) === 1) {
        $items = nav_subgroup_allowed_items($visibleSubs[0]);
        if (count($items) === 1) {
            return app_url('index.php?r=' . rawurlencode((string) $items[0]['r']));
        }
    }

    if ($domainId === 'favorites') {
        return nav_hub_url($domainId, 'favorites');
    }

    return nav_domain_hub_url($domainId);
}

function nav_sidebar_domain_is_active(string $domainId, string $activeRoute, ?array $activeHub): bool
{
    if ($activeRoute === 'dashboard' && $domainId === 'main') {
        return true;
    }
    if ($activeHub !== null && ($activeHub['domain_id'] ?? '') === $domainId) {
        return true;
    }
    if ($domainId === 'favorites' && $activeRoute !== '' && $activeRoute !== 'menu_hub') {
        require_once app_path('includes/sys_favorites.php');
        try {
            $uid = (int) (current_user()['id'] ?? 0);
            if ($uid > 0 && sys_favorites_is_favorite(db(), $uid, $activeRoute)) {
                return true;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    return false;
}

/** مجال في الشريط — المجلدات والشاشات تُعرض في منطقة المحتوى الرئيسية. */
function nav_render_sidebar_domain(array $block, string $activeRoute, ?array $activeHub): void
{
    if (!nav_domain_visible($block)) {
        return;
    }

    $domainId = (string) ($block['id'] ?? '');

    require_once app_path('includes/app_window_manager.php');
    $href = nav_sidebar_domain_href($block);
    if (app_mdi_is_park_menu_embed()) {
        $href = app_mdi_park_menu_url($href);
    }
    $isActive = nav_sidebar_domain_is_active($domainId, $activeRoute, $activeHub);

    echo '<a class="nav-domain-link' . ($isActive ? ' is-active' : '') . '" href="' . esc($href) . '">';
    echo esc((string) ($block['title'] ?? ''));
    echo '</a>';
}

/** رابط شاشة مع تمرير مصدر الـ hub للعودة لاحقاً. */
function nav_screen_url(string $routeCode, string $hubDomainId, string $hubSubId, string $hubNestedSubId = ''): string
{
    $q = 'index.php?r=' . rawurlencode($routeCode);
    if ($hubDomainId !== '' && $hubSubId !== '') {
        $q .= '&hub_d=' . rawurlencode($hubDomainId) . '&hub_s=' . rawurlencode($hubSubId);
        if ($hubNestedSubId !== '') {
            $q .= '&hub_ss=' . rawurlencode($hubNestedSubId);
        }
    }

    return app_url($q);
}

/** يُلحق بعناوين إعادة التوجيه بعد الحفظ للحفاظ على زر الخروج. */
function nav_hub_query_for_redirect(): string
{
    $hub = $_SESSION['nav_return_hub'] ?? null;
    if (!is_array($hub)) {
        return '';
    }
    $d = trim((string) ($hub['d'] ?? ''));
    $s = trim((string) ($hub['s'] ?? ''));
    $ss = trim((string) ($hub['ss'] ?? ''));
    if ($d === '' || $s === '') {
        return '';
    }

    $q = '&hub_d=' . rawurlencode($d) . '&hub_s=' . rawurlencode($s);
    if ($ss !== '') {
        $q .= '&hub_ss=' . rawurlencode($ss);
    }

    return $q;
}

/** بيانات زر × إغلاق الشاشة (رابط + تلميح) — الخروج الكامل للقائمة وليس الرجوع الداخلي. */
function nav_screen_close_info(string $activeRoute = ''): array
{
    if ($activeRoute === '') {
        $activeRoute = (string) ($GLOBALS['activeRoute'] ?? '');
    }

    $ledgerBack = nav_item_stock_ledger_back_link();
    if ($ledgerBack !== null) {
        return [
            'url' => (string) ($ledgerBack['url'] ?? nav_exit_url($activeRoute)),
            'hint' => 'العودة إلى ' . (string) ($ledgerBack['label'] ?? ''),
        ];
    }

    $hint = 'إغلاق والعودة';
    if ($activeRoute !== 'menu_hub' && $activeRoute !== 'dashboard') {
        $stored = trim((string) ($_SESSION['nav_return_url'] ?? ''));
        if ($stored !== '' && nav_is_safe_back_url($stored)) {
            $hint = 'إغلاق والعودة لقائمة الشاشات';
        } else {
            $hub = nav_resolve_active_hub($activeRoute);
            if ($hub !== null) {
                $hint = 'إغلاق والعودة لقائمة الشاشات';
            } else {
                $hint = 'إغلاق والعودة للوحة التحكم';
            }
        }
    }

    return [
        'url' => nav_exit_url($activeRoute),
        'hint' => $hint,
    ];
}

/** أزرار التحكم (تصغير / إغلاق) — يسار شريط العنوان الأزرق. */
function nav_render_screen_close(string $activeRoute = '', ?string $overrideUrl = null, ?string $overrideHint = null): void
{
    require_once app_path('includes/app_window_manager.php');
    $activeRoute = app_mdi_resolve_route($activeRoute);
    if (app_mdi_route_allowed($activeRoute) && !app_mdi_is_embed_request()) {
        app_mdi_render_title_bar_controls($activeRoute, $overrideUrl, $overrideHint);
        return;
    }

    if ($overrideUrl !== null && $overrideUrl !== '' && nav_is_safe_back_url($overrideUrl)) {
        $url = $overrideUrl;
        $hint = $overrideHint ?? 'إغلاق والعودة';
    } else {
        $info = nav_screen_close_info($activeRoute);
        $url = (string) ($info['url'] ?? nav_exit_url($activeRoute));
        $hint = (string) ($info['hint'] ?? 'إغلاق والعودة');
    }
    if (app_mdi_is_embed_request()) {
        $url = app_mdi_embed_url($url);
    }
    echo '<a class="ora12-title-bar__close app-screen-exit-btn" href="' . esc($url) . '"';
    echo ' title="' . esc($hint) . '" aria-label="' . esc($hint) . '">×</a>';
}

/** زر × ثابت — يسار الشاشة — للشاشات التي لا تعرض شريط عنوان Oracle. */
function nav_render_floating_screen_exit(string $activeRoute = ''): void
{
    if ($activeRoute === '') {
        $activeRoute = (string) ($GLOBALS['activeRoute'] ?? '');
    }
    if (in_array($activeRoute, ['dashboard', 'menu_hub', 'login', 'logout', 'favorites_empty'], true)) {
        return;
    }

    $info = nav_screen_close_info($activeRoute);
    $url = (string) ($info['url'] ?? nav_exit_url($activeRoute));
    $hint = (string) ($info['hint'] ?? 'إغلاق والعودة');
    require_once app_path('includes/app_window_manager.php');
    if (app_mdi_is_embed_request()) {
        $url = app_mdi_embed_url($url);
    }

    echo '<a class="app-floating-exit-btn ora12-title-bar__close no-print" href="' . esc($url) . '"';
    echo ' title="' . esc($hint) . '" aria-label="' . esc($hint) . '">×</a>';
}
