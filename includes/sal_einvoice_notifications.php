<?php
declare(strict_types=1);

require_once app_path('includes/sal_documents_list.php');
require_once app_path('includes/sal_einvoice_tracking.php');
require_once app_path('includes/dashboard_stats.php');

const SAL_EINVOICE_NOTIFY_PER_KIND = 8;
const SAL_EINVOICE_NOTIFY_PANEL_MAX = 16;

function sal_einvoice_notifications_user_can_see(): bool
{
    $perms = [
        'sales_invoices',
        'sales_invoices_list',
        'sales_returns',
        'sales_returns_list',
        'sales_documents_list',
        'sales_returns_documents_list',
    ];
    foreach ($perms as $perm) {
        if (user_can($perm)) {
            return true;
        }
    }

    return function_exists('user_can_action') && user_can_action('sales_send_einvoice');
}

function sal_einvoice_notifications_schema_ready(PDO $pdo): bool
{
    einvoice_ensure_schema($pdo);

    return einvoice_column_exists($pdo, 'sal_invoice', 'einv_qr')
        || einvoice_column_exists($pdo, 'sal_return', 'einv_qr');
}

/**
 * @return list<array<string,mixed>>
 */
function sal_einvoice_unsent_invoice_alerts(PDO $pdo, int $limit = SAL_EINVOICE_NOTIFY_PER_KIND): array
{
    if (
        !sal_einvoice_notifications_user_can_see()
        || !sal_einvoice_notifications_schema_ready($pdo)
        || !dashboard_table_exists($pdo, 'sal_invoice')
        || !einvoice_column_exists($pdo, 'sal_invoice', 'einv_qr')
    ) {
        return [];
    }
    if (!user_can('sales_invoices') && !user_can('sales_invoices_list') && !user_can('sales_documents_list')) {
        if (!function_exists('user_can_action') || !user_can_action('sales_send_einvoice')) {
            return [];
        }
    }

    require_once app_path('includes/sal_invoice_post.php');
    $posted = sal_invoice_sql_is_posted_expr('i');
    $sent = sal_documents_list_einv_sent_expr_invoice($pdo, 'i');
    $tracking = sal_einvoice_sql_invoice_requires_tracking('i');
    $limit = max(1, min(50, $limit));

    $sql = "SELECT i.id, i.invoice_no AS doc_no, i.invoice_date AS doc_date, i.total AS amount,
                   c.name_ar AS party_name
            FROM sal_invoice i
            INNER JOIN crm_customer c ON c.id = i.customer_id
            WHERE i.status = 'confirmed'
              AND ({$tracking})
              AND ({$posted})
              AND NOT ({$sent})
            ORDER BY i.invoice_date ASC, i.id ASC
            LIMIT {$limit}";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $out[] = [
            'kind' => 'sale_invoice',
            'doc_id' => $id,
            'type_label' => 'فاتورة بيع',
            'doc_no' => (string) ($row['doc_no'] ?? ''),
            'doc_date' => (string) ($row['doc_date'] ?? ''),
            'party_name' => (string) ($row['party_name'] ?? ''),
            'amount' => (float) ($row['amount'] ?? 0),
            'url' => app_url('index.php?r=sales_invoices&id=' . $id),
            'urgency' => 'einvoice',
            'urgency_label' => 'لم تُرسل للفوترة',
        ];
    }

    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function sal_einvoice_unsent_return_alerts(PDO $pdo, int $limit = SAL_EINVOICE_NOTIFY_PER_KIND): array
{
    if (
        !sal_einvoice_notifications_user_can_see()
        || !sal_einvoice_notifications_schema_ready($pdo)
        || !dashboard_table_exists($pdo, 'sal_return')
        || !einvoice_column_exists($pdo, 'sal_return', 'einv_qr')
    ) {
        return [];
    }
    if (!user_can('sales_returns') && !user_can('sales_returns_list') && !user_can('sales_returns_documents_list')) {
        if (!function_exists('user_can_action') || !user_can_action('sales_send_einvoice')) {
            return [];
        }
    }

    require_once app_path('includes/sal_return_post.php');
    require_once app_path('includes/sal_return_schema.php');
    if (!sal_return_has_tables($pdo)) {
        return [];
    }

    $posted = sal_return_sql_is_posted_expr('r');
    $sent = sal_documents_list_einv_sent_expr_return($pdo, 'r');
    $invSent = sal_documents_list_einv_sent_expr_invoice($pdo, 'i');
    $tracking = sal_einvoice_sql_return_requires_tracking('r');
    $limit = max(1, min(50, $limit));

    $sql = "SELECT r.id, r.return_no AS doc_no, r.return_date AS doc_date, r.total AS amount,
                   c.name_ar AS party_name, i.invoice_no AS ref_invoice_no
            FROM sal_return r
            INNER JOIN crm_customer c ON c.id = r.customer_id
            INNER JOIN sal_invoice i ON i.id = r.invoice_id
            WHERE r.status <> 'cancelled'
              AND ({$tracking})
              AND ({$posted})
              AND NOT ({$sent})
              AND ({$invSent})
            ORDER BY r.return_date ASC, r.id ASC
            LIMIT {$limit}";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $out[] = [
            'kind' => 'sale_return',
            'doc_id' => $id,
            'type_label' => 'مردود مبيعات',
            'doc_no' => (string) ($row['doc_no'] ?? ''),
            'doc_date' => (string) ($row['doc_date'] ?? ''),
            'party_name' => (string) ($row['party_name'] ?? ''),
            'ref_invoice_no' => (string) ($row['ref_invoice_no'] ?? ''),
            'amount' => (float) ($row['amount'] ?? 0),
            'url' => app_url('index.php?r=sales_returns&id=' . $id),
            'urgency' => 'einvoice',
            'urgency_label' => 'لم يُرسل للفوترة',
        ];
    }

    return $out;
}

function sal_einvoice_unsent_invoice_count(PDO $pdo): int
{
    if (
        !sal_einvoice_notifications_user_can_see()
        || !sal_einvoice_notifications_schema_ready($pdo)
        || !dashboard_table_exists($pdo, 'sal_invoice')
        || !einvoice_column_exists($pdo, 'sal_invoice', 'einv_qr')
    ) {
        return 0;
    }
    if (!user_can('sales_invoices') && !user_can('sales_invoices_list') && !user_can('sales_documents_list')) {
        if (!function_exists('user_can_action') || !user_can_action('sales_send_einvoice')) {
            return 0;
        }
    }

    require_once app_path('includes/sal_invoice_post.php');
    $posted = sal_invoice_sql_is_posted_expr('i');
    $sent = sal_documents_list_einv_sent_expr_invoice($pdo, 'i');
    $tracking = sal_einvoice_sql_invoice_requires_tracking('i');
    $sql = "SELECT COUNT(*)
            FROM sal_invoice i
            WHERE i.status = 'confirmed'
              AND ({$tracking})
              AND ({$posted})
              AND NOT ({$sent})";

    return (int) $pdo->query($sql)->fetchColumn();
}

function sal_einvoice_unsent_return_count(PDO $pdo): int
{
    if (
        !sal_einvoice_notifications_user_can_see()
        || !sal_einvoice_notifications_schema_ready($pdo)
        || !dashboard_table_exists($pdo, 'sal_return')
        || !einvoice_column_exists($pdo, 'sal_return', 'einv_qr')
    ) {
        return 0;
    }
    if (!user_can('sales_returns') && !user_can('sales_returns_list') && !user_can('sales_returns_documents_list')) {
        if (!function_exists('user_can_action') || !user_can_action('sales_send_einvoice')) {
            return 0;
        }
    }

    require_once app_path('includes/sal_return_post.php');
    require_once app_path('includes/sal_return_schema.php');
    if (!sal_return_has_tables($pdo)) {
        return 0;
    }

    $posted = sal_return_sql_is_posted_expr('r');
    $sent = sal_documents_list_einv_sent_expr_return($pdo, 'r');
    $invSent = sal_documents_list_einv_sent_expr_invoice($pdo, 'i');
    $tracking = sal_einvoice_sql_return_requires_tracking('r');
    $sql = "SELECT COUNT(*)
            FROM sal_return r
            INNER JOIN sal_invoice i ON i.id = r.invoice_id
            WHERE r.status <> 'cancelled'
              AND ({$tracking})
              AND ({$posted})
              AND NOT ({$sent})
              AND ({$invSent})";

    return (int) $pdo->query($sql)->fetchColumn();
}

function sal_einvoice_unsent_count(PDO $pdo): int
{
    return sal_einvoice_unsent_invoice_count($pdo) + sal_einvoice_unsent_return_count($pdo);
}

/**
 * @return list<array<string,mixed>>
 */
function sal_einvoice_unsent_alerts_collect(PDO $pdo): array
{
    $items = array_merge(
        sal_einvoice_unsent_invoice_alerts($pdo),
        sal_einvoice_unsent_return_alerts($pdo)
    );
    usort($items, static function (array $a, array $b): int {
        $da = (string) ($a['doc_date'] ?? '');
        $db = (string) ($b['doc_date'] ?? '');
        if ($da !== $db) {
            return strcmp($da, $db);
        }

        return strcmp((string) ($a['doc_no'] ?? ''), (string) ($b['doc_no'] ?? ''));
    });

    return array_slice($items, 0, SAL_EINVOICE_NOTIFY_PANEL_MAX);
}
