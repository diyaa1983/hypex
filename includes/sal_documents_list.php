<?php
declare(strict_types=1);

require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/sal_return_schema.php');
require_once app_path('includes/sal_invoice_post.php');
require_once app_path('includes/sal_return_post.php');
require_once app_path('includes/einvoice_schema.php');

/** تعبير SQL: مُرسلة للضريبة (فاتورة بيع). */
function sal_documents_list_einv_sent_expr_invoice(PDO $pdo, string $alias = 'i'): string
{
    if (!einvoice_column_exists($pdo, 'sal_invoice', 'einv_qr')) {
        return '0';
    }

    return "(TRIM(COALESCE({$alias}.einv_qr, '')) <> '')";
}

/** تعبير SQL: مُرسلة للضريبة (مرتجع بيع). */
function sal_documents_list_einv_sent_expr_return(PDO $pdo, string $alias = 'r'): string
{
    if (!einvoice_column_exists($pdo, 'sal_return', 'einv_qr')) {
        return '0';
    }

    return "(TRIM(COALESCE({$alias}.einv_qr, '')) <> '')";
}

/** عدد وأسماء مرتجعات الفاتورة (للعرض في قائمة الفواتير). */
function sal_invoices_list_return_subqueries(PDO $pdo): array
{
    if (!sal_return_has_tables($pdo)) {
        return ['count' => '0', 'nos' => "''"];
    }

    return [
        'count' => '(SELECT COUNT(*) FROM sal_return r WHERE r.invoice_id = i.id AND r.status <> \'cancelled\')',
        'nos' => "(SELECT GROUP_CONCAT(r.return_no ORDER BY r.return_date DESC, r.id DESC SEPARATOR '، ')
                  FROM sal_return r WHERE r.invoice_id = i.id AND r.status <> 'cancelled')",
    ];
}

/**
 * @return array{sql:string, params:list<mixed>, count_sql:string, count_params:list<mixed>}
 */
function sal_invoices_list_query(PDO $pdo, string $search = ''): array
{
    sal_invoice_ensure_schema($pdo);
    einvoice_ensure_schema($pdo);

    $invPosted = sal_invoice_sql_is_posted_expr('i');
    $invEinv = sal_documents_list_einv_sent_expr_invoice($pdo, 'i');
    $retSub = sal_invoices_list_return_subqueries($pdo);

    $sql = "SELECT i.id AS doc_id, i.invoice_no AS doc_no, i.invoice_date AS doc_date,
            c.name_ar AS customer_name, i.total AS total,
            ({$invPosted}) AS is_posted, ({$invEinv}) AS einv_sent,
            ({$retSub['count']}) AS return_count,
            ({$retSub['nos']}) AS return_nos
        FROM sal_invoice i
        INNER JOIN crm_customer c ON c.id = i.customer_id
        WHERE i.status = 'confirmed'";

    $params = [];
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= ' AND (i.invoice_no LIKE ? OR c.name_ar LIKE ? OR c.code LIKE ?)';
        $params = [$like, $like, $like];
    }

    $sql .= ' ORDER BY i.invoice_date DESC, i.id DESC';
    $countSql = 'SELECT COUNT(*) FROM sal_invoice i
        INNER JOIN crm_customer c ON c.id = i.customer_id
        WHERE i.status = \'confirmed\'';
    $countParams = $params;
    if ($search !== '') {
        $countSql .= ' AND (i.invoice_no LIKE ? OR c.name_ar LIKE ? OR c.code LIKE ?)';
    }

    return [
        'sql' => $sql,
        'params' => $params,
        'count_sql' => $countSql,
        'count_params' => $countParams,
    ];
}

/**
 * @return array{sql:string, params:list<mixed>, count_sql:string, count_params:list<mixed>}
 */
function sal_returns_documents_list_query(PDO $pdo, string $search = ''): array
{
    sal_return_ensure_schema($pdo);
    sal_invoice_ensure_schema($pdo);
    einvoice_ensure_schema($pdo);

    if (!sal_return_has_tables($pdo)) {
        return [
            'sql' => 'SELECT NULL AS doc_id WHERE 1=0',
            'params' => [],
            'count_sql' => 'SELECT 0',
            'count_params' => [],
        ];
    }

    $retPosted = sal_return_sql_is_posted_expr('r');
    $retEinv = sal_documents_list_einv_sent_expr_return($pdo, 'r');

    $sql = "SELECT r.id AS doc_id, r.return_no AS doc_no, i.invoice_no AS ref_invoice_no,
            r.return_date AS doc_date, c.name_ar AS customer_name, r.total AS total,
            ({$retPosted}) AS is_posted, ({$retEinv}) AS einv_sent
        FROM sal_return r
        INNER JOIN crm_customer c ON c.id = r.customer_id
        INNER JOIN sal_invoice i ON i.id = r.invoice_id
        WHERE r.status <> 'cancelled'";

    $params = [];
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= ' AND (r.return_no LIKE ? OR i.invoice_no LIKE ? OR c.name_ar LIKE ? OR c.code LIKE ?)';
        $params = [$like, $like, $like, $like];
    }

    $sql .= ' ORDER BY r.return_date DESC, r.id DESC';
    $countSql = 'SELECT COUNT(*) FROM sal_return r
        INNER JOIN crm_customer c ON c.id = r.customer_id
        INNER JOIN sal_invoice i ON i.id = r.invoice_id
        WHERE r.status <> \'cancelled\'';
    $countParams = $params;
    if ($search !== '') {
        $countSql .= ' AND (r.return_no LIKE ? OR i.invoice_no LIKE ? OR c.name_ar LIKE ? OR c.code LIKE ?)';
    }

    return [
        'sql' => $sql,
        'params' => $params,
        'count_sql' => $countSql,
        'count_params' => $countParams,
    ];
}

/** @return list<array<string, mixed>> */
function sal_invoices_list_fetch(PDO $pdo, string $search, array $pager): array
{
    $q = sal_invoices_list_query($pdo, $search);

    $stCount = $pdo->prepare($q['count_sql']);
    $stCount->execute($q['count_params']);
    $total = (int) $stCount->fetchColumn();

    require_once app_path('includes/list_pagination.php');
    $pager = list_pager_with_total($pager, $total);

    $sql = $q['sql'] . list_pager_sql_limit($pager);
    $st = $pdo->prepare($sql);
    $st->execute($q['params']);

    return [
        'rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'pager' => $pager,
    ];
}

/** @return list<array<string, mixed>> */
function sal_returns_documents_list_fetch(PDO $pdo, string $search, array $pager): array
{
    $q = sal_returns_documents_list_query($pdo, $search);

    $stCount = $pdo->prepare($q['count_sql']);
    $stCount->execute($q['count_params']);
    $total = (int) $stCount->fetchColumn();

    require_once app_path('includes/list_pagination.php');
    $pager = list_pager_with_total($pager, $total);

    $sql = $q['sql'] . list_pager_sql_limit($pager);
    $st = $pdo->prepare($sql);
    $st->execute($q['params']);

    return [
        'rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'pager' => $pager,
    ];
}

function sal_documents_list_invoice_open_url(int $invoiceId): string
{
    return app_url('index.php?r=sales_invoices&id=' . $invoiceId);
}

function sal_documents_list_return_open_url(int $returnId): string
{
    return app_url('index.php?r=sales_returns&id=' . $returnId);
}
