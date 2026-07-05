<?php
declare(strict_types=1);

require_once app_path('includes/mobile_auth.php');
require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/sal_invoice_post.php');

/** عرض/تحميل فواتير المبيعات من الهاتف أو سطح المكتب. */
function mobile_can_access_sales_invoice_api(): bool
{
    return user_can_sales_invoices() || user_can('m_sales_invoices');
}

/** تعديل فاتورة غير مرحّلة من تطبيق الهاتف. */
function mobile_can_edit_sales_invoice(): bool
{
    return mobile_can_access_sales_invoice_api();
}

/** ترحيل فاتورة من الهاتف (صلاحية ترحيل سطح المكتب أو شاشة فاتورة الموبايل). */
function mobile_can_post_sales_invoice(): bool
{
    if (!mobile_can_access_sales_invoice_api()) {
        return false;
    }
    if (user_can_action('action_post_sales_invoice')) {
        return true;
    }

    return user_can('m_sales_invoices') && mobile_is_context();
}

/** حذف فاتورة غير مرحّلة من الهاتف (صلاحية حذف سطح المكتب أو شاشة فاتورة الموبايل). */
function mobile_can_delete_sales_invoice(): bool
{
    if (!mobile_can_access_sales_invoice_api()) {
        return false;
    }
    if (user_can_action('action_delete_sales_invoice')) {
        return true;
    }

    return user_can('m_sales_invoices') && mobile_is_context();
}

/** رفع صورة الطلبية إلى أرشيف الفاتورة من الهاتف. */
function mobile_can_archive_sales_invoice(): bool
{
    if (!mobile_can_access_sales_invoice_api()) {
        return false;
    }
    if (user_can_action('action_archive_sales_invoice')) {
        return true;
    }

    return user_can('m_sales_invoices') && mobile_is_context();
}

/** إرسال فوترة: صلاحية سطح المكتب أو مدير النظام. */
function mobile_can_send_sales_einvoice(): bool
{
    if (user_is_system_admin()) {
        return true;
    }
    $uid = (int) (current_user()['id'] ?? 0);
    if ($uid < 1) {
        return false;
    }
    $st = db()->prepare(
        'SELECT 1 FROM sys_user_group ug
         INNER JOIN sys_group_permission gp ON gp.group_id = ug.group_id AND gp.allowed = 1
         INNER JOIN sys_screen s ON s.id = gp.screen_id AND s.code = ?
         WHERE ug.user_id = ? LIMIT 1'
    );
    $st->execute(['sales_send_einvoice', $uid]);

    return (bool) $st->fetchColumn();
}

/** @param array<string, mixed> $invoice */
function mobile_invoice_enrich_display(PDO $pdo, array $invoice): array
{
    $cid = (int) ($invoice['customer_id'] ?? 0);
    if ($cid > 0) {
        $st = $pdo->prepare('SELECT name_ar, code FROM crm_customer WHERE id = ? LIMIT 1');
        $st->execute([$cid]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if ($c) {
            $invoice['customer_name'] = (string) ($c['name_ar'] ?? '');
            $invoice['customer_code'] = (string) ($c['code'] ?? '');
        }
    }
    $wid = (int) ($invoice['warehouse_id'] ?? 0);
    if ($wid > 0) {
        $st = $pdo->prepare('SELECT name_ar FROM inv_warehouse WHERE id = ? LIMIT 1');
        $st->execute([$wid]);
        $w = $st->fetchColumn();
        if (is_string($w) && $w !== '') {
            $invoice['warehouse_name'] = $w;
        }
    }
    $invoice['payment_label'] = (($invoice['payment_type'] ?? '') === 'credit') ? 'ذمة' : 'نقدي';

    return $invoice;
}

/** @return list<array<string, mixed>> */
function mobile_invoice_list_rows(PDO $pdo, string $filter = 'all', string $search = '', int $limit = 100): array
{
    sal_invoice_ensure_schema($pdo);
    crm_ledger_ensure_schema($pdo);
    require_once app_path('includes/sal_documents_list.php');
    einvoice_ensure_schema($pdo);

    if (!in_array($filter, ['all', 'unposted', 'posted'], true)) {
        $filter = 'all';
    }
    $limit = max(1, min(200, $limit));
    $search = trim($search);

    $postedExpr = sal_invoice_sql_is_posted_expr('i');
    $einvExpr = sal_documents_list_einv_sent_expr_invoice($pdo, 'i');

    $sql = "SELECT i.id, i.invoice_no, i.invoice_date, i.total, i.subtotal, i.tax_amount,
                   i.payment_type, i.customer_id, c.name_ar AS customer_name, c.code AS customer_code,
                   ({$postedExpr}) AS is_posted,
                   ({$einvExpr}) AS einv_sent
            FROM sal_invoice i
            LEFT JOIN crm_customer c ON c.id = i.customer_id
            WHERE i.status = 'confirmed'";
    $params = [];
    if ($filter === 'unposted') {
        $sql .= " AND NOT ({$postedExpr})";
    } elseif ($filter === 'posted') {
        $sql .= " AND ({$postedExpr})";
    }
    if ($search !== '') {
        $sql .= ' AND (i.invoice_no LIKE ? OR c.name_ar LIKE ? OR c.code LIKE ?)';
        $like = '%' . $search . '%';
        $params = [$like, $like, $like];
    }
    $sql .= ' ORDER BY i.id DESC LIMIT ' . (int) $limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['is_posted'] = !empty($row['is_posted']);
        $row['einv_sent'] = !empty($row['einv_sent']);
        $row['payment_label'] = (($row['payment_type'] ?? '') === 'credit') ? 'ذمة' : 'نقدي';
        $row['invoice_date_dmy'] = format_date_dmY((string) ($row['invoice_date'] ?? ''));
        $row['total_fmt'] = format_amount((float) ($row['total'] ?? 0));
    }
    unset($row);

    return $rows;
}
