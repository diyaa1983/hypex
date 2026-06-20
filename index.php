<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_login();
$_SESSION['app_context'] = 'desktop';

require_once app_path('includes/sys_screens.php');
require_once app_path('includes/sys_action_permissions.php');
$pdo = db();
sys_sync_bootstrap_caches($pdo);
sys_sync_screens_from_routes($pdo);
sys_sync_action_permissions($pdo);

require_once app_path('includes/sql_migration.php');
$appBootMigrations = [
    'database/migrations/016_default_group_screen_permissions.sql',
    'database/migrations/018_seed_admin_if_missing.sql',
    'database/migrations/019_tax_rates_screen_permissions.sql',
    'database/migrations/021_report_sales_by_rep.sql',
    'database/migrations/022_report_sales_by_item.sql',
    'database/migrations/023_report_sales_returns.sql',
    'database/migrations/072_report_purchase_returns.sql',
    'database/migrations/073_report_chart_of_accounts_print.sql',
    'database/migrations/042_report_sales_returns_totals.sql',
    'database/migrations/044_report_sales_qty_extra.sql',
    'database/migrations/045_report_invoice_tax.sql',
    'database/migrations/070_sales_documents_list_screen.sql',
    'database/migrations/074_sales_returns_documents_list.sql',
    'database/migrations/075_purchase_documents_list_screens.sql',
    'database/migrations/076_action_permissions.sql',
    'database/migrations/077_report_supplier_payables.sql',
    'database/migrations/078_fin_check_due_email_notify.sql',
    'database/migrations/079_check_email_settings.sql',
    'database/migrations/071_inventory_align_warehouse_screen.sql',
    'database/migrations/069_vat_report_split_routes.sql',
    'database/migrations/046_report_warehouse_items.sql',
    'database/migrations/047_report_customers.sql',
    'database/migrations/043_invoice_line_qty_extra.sql',
    'database/migrations/024_pur_invoice_supplier_invoice_no.sql',
    'database/migrations/025_report_party_statement.sql',
    'database/migrations/040_report_receivables.sql',
    'database/migrations/041_report_inventory_item_movements.sql',
    'database/migrations/048_item_stock_movements_screen.sql',
    'database/migrations/049_item_stock_movements_rename.sql',
    'database/migrations/050_inv_item_sale_price_adj.sql',
    'database/migrations/051_inv_invoice_discount.sql',
    'database/migrations/026_acc_journal_tables.sql',
    'database/migrations/027_fin_voucher.sql',
    'database/migrations/028_sal_delivery.sql',
    'database/migrations/029_fin_voucher_receipt_ext.sql',
    'database/migrations/030_fin_debit_credit_notes.sql',
    'database/migrations/031_journal_voucher_screen.sql',
    'database/migrations/032_acc_gl_posting.sql',
    'database/migrations/033_smb_coa_bootstrap.sql',
    'database/migrations/034_acc_financial_reports.sql',
    'database/migrations/068_report_trial_balance_detailed.sql',
    'database/migrations/035_acc_coa_dedupe_names.sql',
    'database/migrations/036_acc_coa_stop_clone_accounts.sql',
    'database/migrations/058_user_favorites.sql',
    'database/migrations/066_returns_renumber_prefix.sql',
    'database/migrations/067_sal_invoice_backfill_sales_rep.sql',
    'database/migrations/086_inv_movement_types.sql',
    'database/migrations/087_inv_movement_type_adjust_split.sql',
    'database/migrations/088_inv_movement_type_drop_adjustment.sql',
    'database/migrations/089_inv_wh_move.sql',
    'database/migrations/090_inv_movement_type_affects_gl.sql',
    'database/migrations/106_hr_employee_marital_status.sql',
    // 107 يُشغَّل من hr_income_tax_ensure_schema عند الحاجة — لا تكراره هنا (يُعيد زرع الشرائح الافتراضية إن حُذفت كلها).
    'database/migrations/108_acc_hr_income_tax_account.sql',
    'database/migrations/109_hr_income_tax_account_2007.sql',
    // ترحيل 111 (استعادة الشرائح الشهرية) يُنفَّذ يدوياً مرة واحدة — لا يُشغَّل تلقائياً لأنه يحذف الشرائح.
    'database/migrations/112_acc_hr_payroll_gl_accounts.sql',
    'database/migrations/113_hr_ss_posting_single_account.sql',
    'database/migrations/114_hr_payroll_standard_accounting.sql',
    'database/migrations/115_hr_payroll_liability_posting.sql',
    'database/migrations/116_hr_payroll_mapping_fix.sql',
    'database/migrations/117_hr_payroll_expense_account.sql',
    'database/migrations/118_hr_payroll_expense_cleanup.sql',
    'database/migrations/119_hr_payroll_payable_mapping_fix.sql',
    'database/migrations/120_hr_payroll_liability_group.sql',
    'database/migrations/121_report_account_statement.sql',
    'database/migrations/122_sal_invoice_post_gps.sql',
    'database/migrations/123_sales_invoice_gps_screens.sql',
    'database/migrations/124_sal_invoice_post_gps_source.sql',
    'database/migrations/125_sal_invoice_post_gps_place.sql',
    'database/migrations/126_sal_invoice_post_gps_landmark.sql',
    'database/migrations/132_sal_invoice_post_gps_manual_source.sql',
    'database/migrations/127_sal_invoice_posted_by.sql',
    'database/migrations/128_sys_user_location.sql',
    'database/migrations/129_user_gps_locations_screens.sql',
    'database/migrations/130_sys_user_location_landmark.sql',
    'database/migrations/131_sys_user_location_place.sql',
    'database/migrations/133_report_incoming_checks.sql',
    'database/migrations/134_report_incoming_checks_summary.sql',
    'database/migrations/135_report_outgoing_checks.sql',
    'database/migrations/136_report_income_statement_comprehensive.sql',
    'database/migrations/137_report_tax_declaration.sql',
    'database/migrations/138_report_tax_ar3.sql',
    'database/migrations/139_report_warehouse_negative_qty.sql',
    'database/migrations/140_hr_employee_name_parts.sql',
    'database/migrations/141_report_hr_employees.sql',
    'database/migrations/142_sys_backup.sql',
    'database/migrations/143_report_purchases_by_item.sql',
    'database/migrations/144_screen_permissions_sync.sql',
    'database/migrations/145_unpost_action_permissions.sql',
    'database/migrations/146_login_security_password_reset.sql',
    'database/migrations/147_acc_purchases_not_expense_label.sql',
    'database/migrations/148_merge_purchases_to_6001.sql',
    'database/migrations/149_fin_voucher_pay_method_bank.sql',
    'database/migrations/150_report_receivables_aging.sql',
    'database/migrations/157_sys_audit_log.sql',
    'database/migrations/158_invoice_decimal_10_places.sql',
];
sql_migration_bootstrap_registry($pdo, $appBootMigrations);
sql_migration_run_files_once($pdo, $appBootMigrations);

require_once app_path('includes/acc_account_reassign.php');
require_once app_path('includes/acc_coa_bootstrap.php');
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

$currentUserId = (int) current_user()['id'];
if (
    !isset($_SESSION['permissions'])
    || !is_array($_SESSION['permissions'])
    || (int) ($_SESSION['permissions_user_id'] ?? 0) !== $currentUserId
) {
    $_SESSION['permissions'] = load_user_permissions($currentUserId);
    $_SESSION['permissions_user_id'] = $currentUserId;
}

$r = isset($_GET['r']) ? (string) $_GET['r'] : 'dashboard';
$routes = require app_path('config/routes.php');

if (!isset($routes[$r])) {
    http_response_code(404);
    exit('الصفحة غير موجودة');
}

$route = $routes[$r];
if (!user_can($route['permission'])) {
    if ($r === 'dashboard') {
        foreach ($routes as $code => $meta) {
            if (user_can((string) $meta['permission'])) {
                redirect(app_url('index.php?r=' . rawurlencode((string) $code)));
                exit;
            }
        }
    }
    require_permission($route['permission']);
}

$routeTitle = (string) ($route['title'] ?? '');
$pageTitle = $routeTitle;
if (!empty($route['hide_screen_title'])) {
    $pageTitle = '';
}
$activeRoute = $r;
require_once app_path('includes/report_oracle12_ui.php');
$reportOracleUi = report_ora12_route_enabled($r);
$hrOracleUi = str_starts_with($r, 'hr_') && !empty($route['hide_screen_title']) && !$reportOracleUi;
$ora12PickerUi = $hrOracleUi || $reportOracleUi || !empty($route['hide_screen_title']);
$reportVatKind = (string) ($route['vat_kind'] ?? '');

if ($r !== 'chart_of_accounts') {
    unset($_SESSION['coa_tree_in_session']);
}

require_once app_path('includes/nav_helpers.php');
nav_apply_return_from_request($activeRoute);
nav_track_page_visit($activeRoute);

if ($r === 'menu_hub') {
    $hubD = trim((string) ($_GET['d'] ?? ''));
    $hubS = trim((string) ($_GET['s'] ?? ''));
    $hubDomain = nav_find_domain($hubD);
    if ($hubDomain) {
        if ($hubS === '') {
            $pageTitle = (string) ($hubDomain['title'] ?? $pageTitle);
        } else {
            $hubFound = nav_find_subgroup($hubD, $hubS);
            if ($hubFound) {
                $pageTitle = (string) ($hubFound['subgroup']['title'] ?? $pageTitle);
            }
        }
    }
}

ob_start();
require app_path($route['file']);
$content = ob_get_clean();

require app_path('templates/layout.php');
