<?php
declare(strict_types=1);

/**
 * مزامنة config/routes.php مع جدول sys_screen.
 * عند إضافة شاشة/تقرير جديد: جدول مستقل في MySQL + routes.php + nav_menu.php — راجع includes/db_tables.php
 */
function sys_screen_type_for_code(string $code): string
{
    return str_starts_with($code, 'report_') ? 'report' : 'screen';
}

function sys_sync_routes_mtime(): string
{
    $routesFile = app_path('config/routes.php');
    $mobileFile = app_path('config/routes_mobile.php');
    $parts = [];
    if (is_file($routesFile)) {
        $parts[] = (string) filemtime($routesFile);
    }
    if (is_file($mobileFile)) {
        $parts[] = 'm:' . (string) filemtime($mobileFile);
    }

    return $parts !== [] ? implode(':', $parts) . ':sync-v2' : '0';
}

function sys_sync_is_routes_cached(PDO $pdo): bool
{
    try {
        require_once app_path('includes/acc_coa_bootstrap.php');
        $mtime = sys_sync_routes_mtime();

        return acc_coa_meta_get($pdo, 'sys_sync_routes_mtime') === $mtime;
    } catch (Throwable $e) {
        return false;
    }
}

function sys_sync_mark_routes_cached(PDO $pdo): void
{
    try {
        require_once app_path('includes/acc_coa_bootstrap.php');
        acc_coa_meta_set($pdo, 'sys_sync_routes_mtime', sys_sync_routes_mtime());
    } catch (Throwable $e) {
        // ignore
    }
}

/** تسريع القواعد الموجودة: تخطّي فحص المزامنة الكامل عند أول زيارة بعد التحديث. */
function sys_sync_bootstrap_caches(PDO $pdo): void
{
    try {
        require_once app_path('includes/acc_coa_bootstrap.php');
        if (acc_coa_meta_get($pdo, 'sys_sync_routes_mtime') !== null) {
            return;
        }
        $screens = (int) $pdo->query('SELECT COUNT(*) FROM sys_screen')->fetchColumn();
        if ($screens < 5) {
            return;
        }
        sys_sync_mark_routes_cached($pdo);
        if (function_exists('sys_sync_mark_action_permissions_cached')) {
            sys_sync_mark_action_permissions_cached($pdo);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/** @return int عدد الشاشات المضافة */
function sys_sync_screens_from_routes(PDO $pdo): int
{
    if (sys_sync_is_routes_cached($pdo)) {
        return 0;
    }

    $routes = require app_path('config/routes.php');
    $added = 0;

    $maxOrder = (int) $pdo->query('SELECT IFNULL(MAX(sort_order), 0) FROM sys_screen')->fetchColumn();
    $nextOrder = $maxOrder;

    foreach ($routes as $code => $meta) {
        $code = (string) $code;
        $st = $pdo->prepare('SELECT id FROM sys_screen WHERE code = ? LIMIT 1');
        $st->execute([$code]);
        if ($st->fetch()) {
            continue;
        }

        $nextOrder += 10;
        $title = trim((string) ($meta['title'] ?? $code));
        $type = sys_screen_type_for_code($code);

        $ins = $pdo->prepare(
            'INSERT INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$code, $title, $type, $nextOrder]);
        $screenId = (int) $pdo->lastInsertId();
        $added++;

        $pdo->prepare(
            'INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
             SELECT g.id, ?, 1 FROM sys_group g WHERE g.code = ?'
        )->execute([$screenId, 'ADMINS']);

        if (in_array($code, ['purchase_returns', 'purchase_returns_list', 'purchase_invoices_list'], true)) {
            $srcSt = $pdo->prepare('SELECT id FROM sys_screen WHERE code = ? LIMIT 1');
            $srcSt->execute(['purchase_invoices']);
            $srcId = $srcSt->fetchColumn();
            if ($srcId !== false) {
                $groups = $pdo->prepare(
                    'SELECT group_id FROM sys_group_permission WHERE screen_id = ? AND allowed = 1'
                );
                $groups->execute([(int) $srcId]);
                $insGp = $pdo->prepare(
                    'INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed) VALUES (?, ?, 1)'
                );
                foreach ($groups->fetchAll(PDO::FETCH_COLUMN) as $gid) {
                    $insGp->execute([(int) $gid, $screenId]);
                }
            }
        }

        if (in_array($code, ['sales_returns', 'sales_returns_list', 'sales_invoices_list', 'sales_send_einvoice'], true)) {
            $srcSt = $pdo->prepare('SELECT id FROM sys_screen WHERE code = ? LIMIT 1');
            $srcSt->execute(['sales_invoices']);
            $srcId = $srcSt->fetchColumn();
            if ($srcId !== false) {
                $groups = $pdo->prepare(
                    'SELECT group_id FROM sys_group_permission WHERE screen_id = ? AND allowed = 1'
                );
                $groups->execute([(int) $srcId]);
                $insGp = $pdo->prepare(
                    'INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed) VALUES (?, ?, 1)'
                );
                foreach ($groups->fetchAll(PDO::FETCH_COLUMN) as $gid) {
                    $insGp->execute([(int) $gid, $screenId]);
                }
            }
        }

        if (in_array($code, ['tax_rates_settings', 'einvoice_settings'], true)) {
            $settingsSt = $pdo->prepare('SELECT id FROM sys_screen WHERE code = ? LIMIT 1');
            $settingsSt->execute(['settings']);
            $settingsId = $settingsSt->fetchColumn();
            if ($settingsId !== false) {
                $groups = $pdo->prepare(
                    'SELECT group_id FROM sys_group_permission WHERE screen_id = ? AND allowed = 1'
                );
                $groups->execute([(int) $settingsId]);
                $insGp = $pdo->prepare(
                    'INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed) VALUES (?, ?, 1)'
                );
                foreach ($groups->fetchAll(PDO::FETCH_COLUMN) as $gid) {
                    $insGp->execute([(int) $gid, $screenId]);
                }
            }
        }

        if (in_array($code, ['report_sales_returns_totals'], true)) {
            $srcSt = $pdo->prepare('SELECT id FROM sys_screen WHERE code = ? LIMIT 1');
            $srcSt->execute(['report_sales_returns']);
            $srcId = $srcSt->fetchColumn();
            if ($srcId !== false) {
                $groups = $pdo->prepare(
                    'SELECT group_id FROM sys_group_permission WHERE screen_id = ? AND allowed = 1'
                );
                $groups->execute([(int) $srcId]);
                $insGp = $pdo->prepare(
                    'INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed) VALUES (?, ?, 1)'
                );
                foreach ($groups->fetchAll(PDO::FETCH_COLUMN) as $gid) {
                    $insGp->execute([(int) $gid, $screenId]);
                }
            }
        }

        if (in_array($code, ['chart_of_accounts'], true)) {
            $srcSt = $pdo->prepare('SELECT id FROM sys_screen WHERE code = ? LIMIT 1');
            $srcSt->execute(['journal_entries']);
            $srcId = $srcSt->fetchColumn();
            if ($srcId !== false) {
                $groups = $pdo->prepare(
                    'SELECT group_id FROM sys_group_permission WHERE screen_id = ? AND allowed = 1'
                );
                $groups->execute([(int) $srcId]);
                $insGp = $pdo->prepare(
                    'INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed) VALUES (?, ?, 1)'
                );
                foreach ($groups->fetchAll(PDO::FETCH_COLUMN) as $gid) {
                    $insGp->execute([(int) $gid, $screenId]);
                }
            }
        }

        if (in_array($code, ['report_supplier_payables'], true)) {
            $srcSt = $pdo->prepare('SELECT id FROM sys_screen WHERE code = ? LIMIT 1');
            $srcSt->execute(['report_receivables']);
            $srcId = $srcSt->fetchColumn();
            if ($srcId !== false) {
                $groups = $pdo->prepare(
                    'SELECT group_id FROM sys_group_permission WHERE screen_id = ? AND allowed = 1'
                );
                $groups->execute([(int) $srcId]);
                $insGp = $pdo->prepare(
                    'INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed) VALUES (?, ?, 1)'
                );
                foreach ($groups->fetchAll(PDO::FETCH_COLUMN) as $gid) {
                    $insGp->execute([(int) $gid, $screenId]);
                }
            }
        }

        if (in_array($code, ['report_tax_declaration', 'report_tax_ar3'], true)) {
            foreach (['report_vat_net_payable', 'report_invoice_tax'] as $srcCode) {
                $srcSt = $pdo->prepare('SELECT id FROM sys_screen WHERE code = ? LIMIT 1');
                $srcSt->execute([$srcCode]);
                $srcId = $srcSt->fetchColumn();
                if ($srcId === false) {
                    continue;
                }
                $groups = $pdo->prepare(
                    'SELECT group_id FROM sys_group_permission WHERE screen_id = ? AND allowed = 1'
                );
                $groups->execute([(int) $srcId]);
                $insGp = $pdo->prepare(
                    'INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed) VALUES (?, ?, 1)'
                );
                foreach ($groups->fetchAll(PDO::FETCH_COLUMN) as $gid) {
                    $insGp->execute([(int) $gid, $screenId]);
                }
            }
        }

        if (in_array($code, ['report_incoming_checks'], true)) {
            foreach (['report_receivables', 'report_vouchers', 'report_party_statement'] as $srcCode) {
                $srcSt = $pdo->prepare('SELECT id FROM sys_screen WHERE code = ? LIMIT 1');
                $srcSt->execute([$srcCode]);
                $srcId = $srcSt->fetchColumn();
                if ($srcId === false) {
                    continue;
                }
                $groups = $pdo->prepare(
                    'SELECT group_id FROM sys_group_permission WHERE screen_id = ? AND allowed = 1'
                );
                $groups->execute([(int) $srcId]);
                $insGp = $pdo->prepare(
                    'INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed) VALUES (?, ?, 1)'
                );
                foreach ($groups->fetchAll(PDO::FETCH_COLUMN) as $gid) {
                    $insGp->execute([(int) $gid, $screenId]);
                }
            }
        }

        if (in_array($code, ['report_outgoing_checks'], true)) {
            foreach (['report_incoming_checks', 'report_vouchers', 'report_supplier_payables'] as $srcCode) {
                $srcSt = $pdo->prepare('SELECT id FROM sys_screen WHERE code = ? LIMIT 1');
                $srcSt->execute([$srcCode]);
                $srcId = $srcSt->fetchColumn();
                if ($srcId === false) {
                    continue;
                }
                $groups = $pdo->prepare(
                    'SELECT group_id FROM sys_group_permission WHERE screen_id = ? AND allowed = 1'
                );
                $groups->execute([(int) $srcId]);
                $insGp = $pdo->prepare(
                    'INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed) VALUES (?, ?, 1)'
                );
                foreach ($groups->fetchAll(PDO::FETCH_COLUMN) as $gid) {
                    $insGp->execute([(int) $gid, $screenId]);
                }
            }
        }

        if (in_array($code, ['report_general_ledger', 'report_income_statement', 'report_income_statement_comprehensive', 'report_balance_sheet', 'report_invoice_tax'], true)) {
            foreach (['report_journal', 'report_trial_balance'] as $srcCode) {
                $srcSt = $pdo->prepare('SELECT id FROM sys_screen WHERE code = ? LIMIT 1');
                $srcSt->execute([$srcCode]);
                $srcId = $srcSt->fetchColumn();
                if ($srcId === false) {
                    continue;
                }
                $groups = $pdo->prepare(
                    'SELECT group_id FROM sys_group_permission WHERE screen_id = ? AND allowed = 1'
                );
                $groups->execute([(int) $srcId]);
                $insGp = $pdo->prepare(
                    'INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed) VALUES (?, ?, 1)'
                );
                foreach ($groups->fetchAll(PDO::FETCH_COLUMN) as $gid) {
                    $insGp->execute([(int) $gid, $screenId]);
                }
            }
        }

        if (in_array($code, ['item_categories', 'item_units'], true)) {
            $itemsSt = $pdo->prepare('SELECT id FROM sys_screen WHERE code = ? LIMIT 1');
            $itemsSt->execute(['items']);
            $itemsId = $itemsSt->fetchColumn();
            if ($itemsId !== false) {
                $groups = $pdo->prepare(
                    'SELECT group_id FROM sys_group_permission WHERE screen_id = ? AND allowed = 1'
                );
                $groups->execute([(int) $itemsId]);
                $insGp = $pdo->prepare(
                    'INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed) VALUES (?, ?, 1)'
                );
                foreach ($groups->fetchAll(PDO::FETCH_COLUMN) as $gid) {
                    $insGp->execute([(int) $gid, $screenId]);
                }
            }
        }

        $inheritFrom = null;
        if (in_array($code, ['report_invoice_tax_purchase'], true)) {
            $inheritFrom = 'report_invoice_tax';
        } elseif (in_array($code, ['report_vat_return_tax_purchase'], true)) {
            $inheritFrom = 'report_vat_return_tax';
        } elseif (in_array($code, ['report_customer_statement', 'report_supplier_statement'], true)) {
            $inheritFrom = 'report_party_statement';
        } elseif ($code === 'system_backup') {
            $inheritFrom = 'settings';
        } elseif (in_array($code, ['inventory_align_warehouse', 'vat_returns_repost'], true)) {
            $inheritFrom = 'account_mapping';
        } elseif (in_array($code, ['hr_payroll_slip', 'hr_salary_slip'], true)) {
            $inheritFrom = 'hr_salaries';
        } elseif ($code === 'report_purchases_by_item') {
            $inheritFrom = 'report_purchases';
        } elseif (in_array($code, ['report_invoice_tax', 'report_vat_return_tax'], true)) {
            $inheritFrom = 'report_vat_net_payable';
        }
        if ($inheritFrom !== null) {
            $srcSt = $pdo->prepare('SELECT id FROM sys_screen WHERE code = ? LIMIT 1');
            $srcSt->execute([$inheritFrom]);
            $srcId = $srcSt->fetchColumn();
            if ($srcId !== false) {
                $groups = $pdo->prepare(
                    'SELECT group_id FROM sys_group_permission WHERE screen_id = ? AND allowed = 1'
                );
                $groups->execute([(int) $srcId]);
                $insGp = $pdo->prepare(
                    'INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed) VALUES (?, ?, 1)'
                );
                foreach ($groups->fetchAll(PDO::FETCH_COLUMN) as $gid) {
                    $insGp->execute([(int) $gid, $screenId]);
                }
            }
        }
    }

    $added += sys_sync_mobile_route_screens($pdo, $nextOrder);

    if ($added > 0) {
        sys_ensure_admins_all_permissions($pdo);
    }

    sys_sync_mark_routes_cached($pdo);

    return $added;
}

/** مزامنة أكواد صلاحيات تطبيق الهاتف (permission في routes_mobile.php). */
function sys_sync_mobile_route_screens(PDO $pdo, int &$nextOrder): int
{
    /** @var array<string, array<string, mixed>> $mobileRoutes */
    $mobileRoutes = require app_path('config/routes_mobile.php');
    $codes = [];
    foreach ($mobileRoutes as $code => $meta) {
        if (!is_array($meta)) {
            continue;
        }
        $perm = trim((string) ($meta['permission'] ?? $code));
        if ($perm === '') {
            continue;
        }
        if (!isset($codes[$perm])) {
            $label = trim((string) ($meta['title'] ?? $perm));
            if (!str_starts_with($label, 'هاتف')) {
                $label = 'هاتف — ' . $label;
            }
            $codes[$perm] = $label;
        }
    }

    $added = 0;
    $find = $pdo->prepare('SELECT id FROM sys_screen WHERE code = ? LIMIT 1');
    $ins = $pdo->prepare(
        'INSERT INTO sys_screen (code, name_ar, screen_type, sort_order) VALUES (?, ?, \'screen\', ?)'
    );
    $grantAdmins = $pdo->prepare(
        'INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
         SELECT g.id, ?, 1 FROM sys_group g WHERE g.code = ?'
    );
    $grantMobile = $pdo->prepare(
        'INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
         SELECT g.id, ?, 1 FROM sys_group g WHERE g.code = ?'
    );

    foreach ($codes as $perm => $title) {
        $find->execute([$perm]);
        if ($find->fetch()) {
            continue;
        }
        $nextOrder += 10;
        $ins->execute([$perm, $title, $nextOrder]);
        $screenId = (int) $pdo->lastInsertId();
        $added++;
        $grantAdmins->execute([$screenId, 'ADMINS']);
        $grantMobile->execute([$screenId, 'MOBILE']);
    }

    return $added;
}

/** كود الصلاحية الفعلي لمسار سطح المكتب أو الهاتف. */
function sys_screen_code_for_route(string $routeKey): string
{
    static $desktop = null;
    static $mobile = null;
    if ($desktop === null) {
        $desktop = require app_path('config/routes.php');
    }
    if ($mobile === null) {
        $mobile = require app_path('config/routes_mobile.php');
    }
    if (isset($desktop[$routeKey]['permission'])) {
        return (string) $desktop[$routeKey]['permission'];
    }
    if (isset($mobile[$routeKey]['permission'])) {
        return (string) $mobile[$routeKey]['permission'];
    }

    return $routeKey;
}

/** لوحة التحكم مسموحة لكل مجموعة حتى لا يعلق المستخدم عند الدخول على صفحة بلا صلاحيات. */
function sys_ensure_dashboard_for_all_groups(PDO $pdo): void
{
    try {
        require_once app_path('includes/acc_coa_bootstrap.php');
        if (acc_coa_meta_get($pdo, 'sys_dashboard_all_groups_v1') === '1') {
            return;
        }
        $pdo->exec(
            'INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
             SELECT g.id, s.id, 1
             FROM sys_group g
             INNER JOIN sys_screen s ON s.code = \'dashboard\''
        );
        acc_coa_meta_set($pdo, 'sys_dashboard_all_groups_v1', '1');
    } catch (Throwable $e) {
        // ignore
    }
}

/** مستخدم غير مرتبط بأي مجموعة لا يستطيع تحميل صلاحيات؛ يُربَط تلقائياً بمجموعة المشاهدين إن وُجدت. */
function sys_repair_user_without_groups(PDO $pdo, int $userId): void
{
    if ($userId < 1) {
        return;
    }

    if (!empty($_SESSION['_user_has_groups'][$userId])) {
        return;
    }

    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM sys_user_group WHERE user_id = ?');
        $st->execute([$userId]);
        if ((int) $st->fetchColumn() > 0) {
            $_SESSION['_user_has_groups'][$userId] = true;

            return;
        }

        $gSt = $pdo->prepare('SELECT id FROM sys_group WHERE code = ? LIMIT 1');
        foreach (['VIEWERS', 'ACCOUNTING'] as $groupCode) {
            $gSt->execute([$groupCode]);
            $gid = $gSt->fetchColumn();
            if ($gid !== false) {
                $pdo->prepare('INSERT INTO sys_user_group (user_id, group_id) VALUES (?, ?)')->execute([
                    $userId,
                    (int) $gid,
                ]);
                $_SESSION['_user_has_groups'][$userId] = true;

                return;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/** منح مجموعة مديرو النظام (ADMINS) كل الشاشات والتقارير المسجّلة. */
function sys_ensure_admins_all_permissions(PDO $pdo): void
{
    try {
        $pdo->exec(
            'INSERT IGNORE INTO sys_group_permission (group_id, screen_id, allowed)
             SELECT g.id, s.id, 1
             FROM sys_group g
             CROSS JOIN sys_screen s
             WHERE g.code = \'ADMINS\''
        );
    } catch (Throwable $e) {
        // ignore
    }
}
