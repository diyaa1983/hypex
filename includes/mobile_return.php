<?php
declare(strict_types=1);

require_once app_path('includes/mobile_auth.php');

function user_can_mobile_sales_returns(): bool
{
    return user_can('m_sales_returns') || user_can_sales_returns();
}

function mobile_can_post_sales_return(): bool
{
    return user_can_mobile_sales_returns() && user_can_action('action_post_sales_return');
}

function mobile_can_delete_sales_return(): bool
{
    return user_can_mobile_sales_returns() && user_can_action('action_delete_sales_return');
}

function mobile_can_edit_sales_return(): bool
{
    return user_can_mobile_sales_returns();
}

/** إرسال مرتجع للفوترة: صلاحية سطح المكتب أو مدير النظام. */
function mobile_can_send_sales_return_einvoice(): bool
{
    if (function_exists('user_is_system_admin') && user_is_system_admin()) {
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

/**
 * قائمة مرتجعات المبيعات للموبايل.
 *
 * @return list<array<string, mixed>>
 */
function mobile_return_list_rows(PDO $pdo, string $filter = 'all', string $search = '', int $limit = 120): array
{
    require_once app_path('includes/sal_return_schema.php');
    require_once app_path('includes/sal_return_post.php');
    require_once app_path('includes/sal_invoice_schema.php');

    if (!sal_return_ensure_schema($pdo) || !sal_return_has_tables($pdo)) {
        return [];
    }

    if (!in_array($filter, ['all', 'unposted', 'posted'], true)) {
        $filter = 'all';
    }
    $limit = max(1, min(200, $limit));
    $search = trim($search);

    $postedExpr = sal_return_sql_is_posted_expr('r');
    require_once app_path('includes/einvoice_schema.php');
    $hasEinvQr = function_exists('einvoice_column_exists')
        && einvoice_column_exists($pdo, 'sal_return', 'einv_qr');
    $einvSelect = $hasEinvQr ? ', r.einv_qr' : '';

    $sql = "SELECT r.id, r.return_no, r.return_date, r.total, r.customer_id,
                   c.name_ar AS customer_name, c.code AS customer_code,
                   i.invoice_no AS ref_invoice_no,
                   ({$postedExpr}) AS is_posted{$einvSelect}
            FROM sal_return r
            INNER JOIN crm_customer c ON c.id = r.customer_id
            INNER JOIN sal_invoice i ON i.id = r.invoice_id
            WHERE r.status <> 'cancelled'";
    $params = [];

    require_once app_path('includes/crm_sales_rep_schema.php');
    $scopedRepId = crm_mobile_scoped_sales_rep_id($pdo);
    if ($scopedRepId !== null) {
        [$linkSql, $linkParams] = crm_customer_sql_linked_to_rep($pdo, 'c', $scopedRepId);
        $sql .= ' AND ' . $linkSql;
        $params = array_merge($params, $linkParams);
    }

    if ($filter === 'unposted') {
        $sql .= " AND NOT ({$postedExpr})";
    } elseif ($filter === 'posted') {
        $sql .= " AND ({$postedExpr})";
    }

    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= ' AND (r.return_no LIKE ? OR i.invoice_no LIKE ? OR c.name_ar LIKE ? OR c.code LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= ' ORDER BY r.return_date DESC, r.id DESC LIMIT ' . (int) $limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['is_posted'] = !empty($row['is_posted']);
        $row['einv_sent'] = !empty($row['einv_qr']);
        $row['return_date_dmy'] = format_date_dmY((string) ($row['return_date'] ?? ''));
        $row['total_fmt'] = format_amount((float) ($row['total'] ?? 0));
    }
    unset($row);

    return $rows;
}
