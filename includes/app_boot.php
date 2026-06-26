<?php
declare(strict_types=1);

/** بصمة التشغيل — تتغير عند تحديث routes أو صلاحيات الإجراءات أو عدد الترحيلات. */
function app_boot_fingerprint(int $migrationCount): string
{
    require_once app_path('includes/sys_screens.php');
    require_once app_path('includes/sys_action_permissions.php');

    return sys_sync_routes_mtime() . ':' . sys_sync_action_permissions_mtime() . ':m' . $migrationCount;
}

function app_boot_is_warm(int $migrationCount): bool
{
    return isset($_SESSION['_app_boot_warm'])
        && $_SESSION['_app_boot_warm'] === app_boot_fingerprint($migrationCount);
}

function app_boot_mark_warm(int $migrationCount): void
{
    $_SESSION['_app_boot_warm'] = app_boot_fingerprint($migrationCount);
}

/** مسار سريع بعد أول طلب في الجلسة — يتخطى مزامنة الشاشات والترحيلات. */
function app_boot_try_fast(PDO $pdo, int $migrationCount): bool
{
    if (!app_boot_is_warm($migrationCount)) {
        return false;
    }

    require_once app_path('includes/acc_coa_bootstrap.php');
    acc_coa_meta_preload($pdo);
    require_once app_path('includes/sys_screens.php');
    sys_repair_user_without_groups($pdo, (int) (current_user()['id'] ?? 0));
    require_once app_path('includes/fin_check_due_email.php');
    fin_check_due_email_register_background_runner();

    return true;
}

/** @param list<string> $appBootMigrations */
function app_boot_run_full(PDO $pdo, array $appBootMigrations): void
{
    require_once app_path('includes/acc_coa_bootstrap.php');
    acc_coa_meta_preload($pdo);
    require_once app_path('includes/sys_screens.php');
    require_once app_path('includes/sys_action_permissions.php');
    sys_sync_bootstrap_caches($pdo);
    sys_sync_screens_from_routes($pdo);
    sys_sync_action_permissions($pdo);

    require_once app_path('includes/sql_migration.php');
    sql_migration_bootstrap_registry($pdo, $appBootMigrations);
    sql_migration_run_files_once($pdo, $appBootMigrations);

    require_once app_path('includes/acc_vat_trust_account.php');
    $vatTrustErr = acc_vat_trust_account_apply_once($pdo);
    if ($vatTrustErr !== null) {
        $_SESSION['coa_bootstrap_notice'] = array_merge(
            is_array($_SESSION['coa_bootstrap_notice'] ?? null) ? $_SESSION['coa_bootstrap_notice'] : [],
            ['تعذر توحيد حساب أمانات الضريبة: ' . $vatTrustErr]
        );
    }

    require_once app_path('includes/acc_account_reassign.php');
    try {
        if (acc_coa_meta_get($pdo, 'merge_cash_111_v1') !== '1') {
            $cashMerge = acc_account_merge_default_cash_box($pdo);
            if (!empty($cashMerge['ok']) && empty($cashMerge['skipped'])) {
                acc_coa_meta_set($pdo, 'merge_cash_111_v1', '1');
                if (($cashMerge['journal_lines'] ?? 0) > 0 || ($cashMerge['vouchers'] ?? 0) > 0) {
                    $msg = 'دمج حساب الصندوق (111) في صندوق رئيسي (1001001001): ' . (string) ($cashMerge['message'] ?? '');
                    $_SESSION['coa_bootstrap_notice'] = array_merge(
                        is_array($_SESSION['coa_bootstrap_notice'] ?? null) ? $_SESSION['coa_bootstrap_notice'] : [],
                        [$msg]
                    );
                } else {
                    acc_coa_meta_set($pdo, 'merge_cash_111_v1', '1');
                }
            } elseif (!empty($cashMerge['ok']) && !empty($cashMerge['skipped'])) {
                acc_coa_meta_set($pdo, 'merge_cash_111_v1', '1');
            }
        }
        if (acc_coa_meta_get($pdo, 'merge_purchases_6001_v1') !== '1') {
            $purchMerge = acc_account_merge_purchases_to_6001($pdo);
            if (!empty($purchMerge['ok'])) {
                if (empty($purchMerge['skipped'])) {
                    acc_coa_meta_set($pdo, 'merge_purchases_6001_v1', '1');
                }
                if ((int) ($purchMerge['journal_lines'] ?? 0) > 0) {
                    $msg = 'دمج المشتريات في 6001: ' . (string) ($purchMerge['message'] ?? '');
                    $_SESSION['coa_bootstrap_notice'] = array_merge(
                        is_array($_SESSION['coa_bootstrap_notice'] ?? null) ? $_SESSION['coa_bootstrap_notice'] : [],
                        [$msg]
                    );
                } elseif (!empty($purchMerge['skipped']) && acc_account_find_purchases_target_6001($pdo)) {
                    acc_coa_meta_set($pdo, 'merge_purchases_6001_v1', '1');
                }
            }
        }
    } catch (Throwable $e) {
        // لا يوقف التطبيق
    }

    try {
        $coaBootstrap = acc_coa_bootstrap_run($pdo, false);
        if (($coaBootstrap['mapped'] ?? 0) > 0 && !empty($coaBootstrap['messages'])) {
            $_SESSION['coa_bootstrap_notice'] = $coaBootstrap['messages'];
        }
    } catch (Throwable $e) {
        $_SESSION['coa_bootstrap_notice'] = ['تعذر ضبط الشجرة تلقائياً: ' . $e->getMessage()];
    }

    sys_ensure_dashboard_for_all_groups($pdo);
    sys_repair_user_without_groups($pdo, (int) current_user()['id']);

    require_once app_path('includes/fin_check_due_email.php');
    fin_check_due_email_register_background_runner();

    app_boot_mark_warm(count($appBootMigrations));
}

/** @param list<string> $appBootMigrations */
function app_boot_run(PDO $pdo, array $appBootMigrations): void
{
    $count = count($appBootMigrations);
    if (app_boot_try_fast($pdo, $count)) {
        return;
    }
    app_boot_run_full($pdo, $appBootMigrations);
}
