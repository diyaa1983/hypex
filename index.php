<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_login();
$_SESSION['app_context'] = 'desktop';

$pdo = db();
require_once app_path('includes/app_boot.php');
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
    'database/migrations/200_out_check_email_settings.sql',
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
    'database/migrations/159_acc_journal_line_party.sql',
    'database/migrations/160_acc_vat_trust_account.sql',
    'database/migrations/161_report_vat_trust_title.sql',
    'database/migrations/162_fin_checks_manage.sql',
    'database/migrations/163_fin_voucher_payment_parties.sql',
    'database/migrations/164_hr_advance_disbursement.sql',
    'database/migrations/165_hr_advance_disbursement_fix.sql',
    'database/migrations/166_hr_salary_disbursement.sql',
    'database/migrations/167_fin_employee_advances_screen.sql',
    'database/migrations/168_report_hr_employee_advances.sql',
    'database/migrations/169_pur_order.sql',
    'database/migrations/170_pur_order_screens_fix.sql',
    'database/migrations/171_voucher_cancel_and_number_pool.sql',
    'database/migrations/180_sys_dashboard_account.sql',
    'database/migrations/181_fin_voucher_archive_docs.sql',
    'database/migrations/182_clear_legacy_invoice_number_pool.sql',
    'database/migrations/183_report_hr_employees_by_department.sql',
    'database/migrations/184_report_hr_employees_resigned.sql',
    'database/migrations/185_hr_overtime.sql',
    'database/migrations/186_hr_overtime_days_hours.sql',
    'database/migrations/187_hr_overtime_multiplier_b.sql',
    'database/migrations/188_report_hr_employee_overtime.sql',
    'database/migrations/189_acc_journal_updated_by.sql',
    'database/migrations/190_report_sales_delivery.sql',
    'database/migrations/191_fin_outgoing_checks_register.sql',
    'database/migrations/194_hr_attendance.sql',
    'database/migrations/195_report_hr_employee_attendance.sql',
    'database/migrations/196_hr_attendance_shifts.sql',
    'database/migrations/197_hr_att_shift_numeric_code.sql',
    'database/migrations/198_hr_employee_schedule.sql',
    'database/migrations/199_hr_departure_types.sql',
    'database/migrations/200_hr_employee_departures.sql',
    'database/migrations/201_hr_leave_types.sql',
    'database/migrations/202_hr_employee_leave_balance.sql',
    'database/migrations/203_hr_employee_leaves.sql',
    'database/migrations/204_hr_leave_type_prorate_yearly.sql',
    'database/migrations/205_report_hr_employee_leaves_departures.sql',
    'database/migrations/207_crm_sales_rep_mobile_custody.sql',
    'database/migrations/208_mobile_rep_custody.sql',
    'database/migrations/209_sys_group_warehouse.sql',
    'database/migrations/210_hr_attendance_sync_token.sql',
    'database/migrations/211_report_hr_att_punch_movements.sql',
    'database/migrations/212_hr_attendance_sync_screens.sql',
    'database/migrations/215_report_sales_invoice_discount.sql',
    'database/migrations/216_dashboard_widget_permissions.sql',
    'database/migrations/217_dashboard_journal_daily.sql',
    'database/migrations/218_sales_unpaid_invoices.sql',
    'database/migrations/219_purchase_unpaid_invoices.sql',
    'database/migrations/220_report_hr_employee_leave_balances.sql',
    'database/migrations/221_report_hr_employees_by_nationality.sql',
    'database/migrations/222_hr_dashboard.sql',
    'database/migrations/163_user_gps_tracker_screens.sql',
    'database/migrations/223_user_gps_track_history.sql',
    'database/migrations/226_gps_mobile_settings.sql',
    'database/migrations/227_gps_google_maps_key.sql',
    'database/migrations/228_gps_map_provider.sql',
    'database/migrations/229_fin_private_out_checks.sql',
    'database/migrations/231_sys_user_open_session.sql',
    'database/migrations/232_mobile_customer_add.sql',
    'database/migrations/235_sal_customer_order.sql',
    'database/migrations/236_report_customer_orders.sql',
    'database/migrations/237_inv_item_units.sql',
    'database/migrations/238_crm_customer_gps.sql',
    'database/migrations/239_sal_rep_route.sql',
    'database/migrations/240_sales_rep_visit_geofence.sql',
];
app_boot_run($pdo, $appBootMigrations);

$r = isset($_GET['r']) ? (string) $_GET['r'] : (isset($_POST['r']) ? (string) $_POST['r'] : 'dashboard');
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

if (!empty($route['standalone'])) {
    require app_path($route['file']);
    exit;
}

$routeTitle = (string) ($route['title'] ?? '');
if (function_exists('__') && $routeTitle !== '') {
    $routeTitle = __($routeTitle);
}
$pageTitle = $routeTitle;
if (!empty($route['hide_screen_title'])) {
    $pageTitle = '';
}
$activeRoute = $r;
$GLOBALS['activeRoute'] = $activeRoute;
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
    $hubSs = trim((string) ($_GET['ss'] ?? ''));
    $hubDomain = nav_find_domain($hubD);
    if ($hubDomain) {
        if ($hubS === '') {
            $pageTitle = (string) ($hubDomain['title'] ?? $pageTitle);
        } else {
            $hubFound = nav_find_subgroup_path($hubD, $hubS, $hubSs);
            if ($hubFound) {
                if ($hubSs !== '' && !empty($hubFound['nested_subgroup']['title'])) {
                    $pageTitle = (string) $hubFound['nested_subgroup']['title'];
                } else {
                    $pageTitle = (string) ($hubFound['subgroup']['title'] ?? $pageTitle);
                }
            }
        }
    }
}

ob_start();
require app_path($route['file']);
$content = ob_get_clean();

require_once app_path('includes/app_window_manager.php');
$layoutEmbed = app_mdi_is_embed_request();
ob_start();
require app_path($layoutEmbed ? 'templates/layout-embed.php' : 'templates/layout.php');
echo i18n_translate_blob(ob_get_clean());
