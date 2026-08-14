<?php
declare(strict_types=1);

/**
 * تحويل طلبات GET لشاشات سطح المكتب من PHP إلى مسارات Node.
 * يُتجاهل عند: POST، force_php=1، سياق الموبايل.
 *
 * @return bool true إذا تم إرسال Location والخروج يجب أن يتوقف المستدعي
 */
function node_try_redirect_desktop_screen(string $routeCode): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return false;
    }
    if (!empty($_GET['force_php'])) {
        return false;
    }
    if (($_SESSION['app_context'] ?? '') === 'mobile') {
        return false;
    }

    $map = node_desktop_screen_path_map();
    if (!isset($map[$routeCode])) {
        // شاشة بلا مسار Node معروف → صفحة توضيح Node بدل PHP
        $base = defined('APP_URL_BASE') ? rtrim((string) APP_URL_BASE, '/') : '';
        $qs = $_GET;
        unset($qs['r']);
        $extra = $qs !== [] ? ('?' . http_build_query($qs)) : '';
        header('Location: ' . $base . '/embed/' . rawurlencode($routeCode) . $extra, true, 302);
        return true;
    }

    $path = $map[$routeCode];
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        if (str_ends_with($path, '/new')) {
            $path = preg_replace('#/new$#', '/' . $id, $path) ?: ($path . '/' . $id);
        } elseif (preg_match('#^/(customers|suppliers|sales-reps)$#', $path)) {
            $path .= '/' . $id;
        } elseif (preg_match('#/(invoices|returns|orders|customer-orders|delivery|offers)$#', $path)) {
            $path .= '/' . $id;
        } elseif (!str_contains($path, 'id=')) {
            $path .= (str_contains($path, '?') ? '&' : '?') . 'id=' . $id;
        }
    } elseif (!empty($_GET['new']) && isset($map[$routeCode . '__new'])) {
        $path = $map[$routeCode . '__new'];
    }

    // مرّر فلاتر شائعة
    $keep = [];
    foreach (['from', 'to', 'filter', 'q', 'status', 'sales_rep_id', 'method'] as $k) {
        if (isset($_GET[$k]) && (string) $_GET[$k] !== '') {
            $keep[$k] = (string) $_GET[$k];
        }
    }
    if ($keep !== []) {
        $path .= (str_contains($path, '?') ? '&' : '?') . http_build_query($keep);
    }

    $base = defined('APP_URL_BASE') ? rtrim((string) APP_URL_BASE, '/') : '';
    header('Location: ' . $base . $path, true, 302);
    return true;
}

/**
 * @return array<string, string>
 */
function node_desktop_screen_path_map(): array
{
    return [
        'dashboard' => '/app',
        'menu_hub' => '/app',
        'sales_invoices' => '/sales/invoices/new',
        'sales_invoices__new' => '/sales/invoices/new',
        'sales_invoices_list' => '/sales/posting',
        'sales_documents_list' => '/sales/invoices',
        'sales_returns' => '/sales/returns/new',
        'sales_returns_list' => '/sales/returns',
        'sales_returns_documents_list' => '/sales/returns',
        'sales_customer_orders' => '/sales/customer-orders/new',
        'sales_customer_order_returns' => '/sales/order-returns',
        'report_customer_order_returns' => '/sales/reports/order-returns',
        'report_customer_orders' => '/sales/reports/customer-orders',
        'report_customer_orders_by_item' => '/sales/reports/customer-orders-by-item',
        'sales_delivery' => '/sales/delivery/new',
        'sales_offers' => '/sales/offers/new',
        'purchase_invoices' => '/purchases/invoices/new',
        'purchase_invoices_list' => '/purchases/posting',
        'purchase_documents_list' => '/purchases/invoices',
        'purchase_orders' => '/purchases/orders/new',
        'purchase_orders_list' => '/purchases/orders/approve',
        'purchase_orders_documents_list' => '/purchases/orders',
        'purchase_returns' => '/purchases/returns/new',
        'purchase_returns_list' => '/purchases/returns/posting',
        'customers' => '/customers',
        'oracle_customers_sync' => '/customers/oracle-sync',
        'suppliers' => '/suppliers',
        'sales_reps' => '/sales-reps',
        'sales_rep_route' => '/sales-reps/route',
        'report_sales_rep_tours' => '/sales-reps/reports/tours',
        'report_sales_rep_visits' => '/sales-reps/reports/visits',
        'sales_rep_visit_checkout_approve' => '/sales-reps/visit-checkout-approve',
        'warehouses' => '/inventory/warehouses',
        'items' => '/inventory/items',
        'item_categories' => '/inventory/categories',
        'item_units' => '/inventory/units',
        'warehouse_moves' => '/inventory/moves',
        'inventory_stocktake' => '/inventory/stocktake',
        'chart_of_accounts' => '/accounting/chart',
        'journal_entries' => '/accounting/journals',
        'journal_voucher' => '/accounting/journal-voucher',
        'cash_receipt' => '/accounting/receipts/new',
        'cash_receipts_list' => '/accounting/receipts',
        'cash_payment' => '/accounting/payments/new',
        'cash_payments_list' => '/accounting/payments',
        'debit_notes' => '/accounting/debit-notes',
        'credit_notes' => '/accounting/credit-notes',
        'fin_checks' => '/accounting/checks',
        'fin_outgoing_checks' => '/accounting/outgoing-checks',
        'account_mapping' => '/accounting/account-mapping',
        'hr_employees' => '/hr/employees',
        'hr_employee_attendance' => '/hr/attendance',
        'users' => '/system/users',
        'groups' => '/system/groups',
        'permissions' => '/system/permissions',
        'settings' => '/system/settings',
        'report_customers_by_rep' => '/customers/reports/by-rep',
        'report_sales_between_dates' => '/sales/reports/between-dates',
        'report_party_statement' => '/accounting/reports/party-statement',
        'report_oracle_customer_statement' => '/accounting/reports/oracle-statement',
    ];
}
