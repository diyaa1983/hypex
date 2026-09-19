<?php
declare(strict_types=1);

require_once app_path('includes/pur_invoice_schema.php');
require_once app_path('includes/pur_return_schema.php');
require_once app_path('includes/pur_invoice_post.php');
require_once app_path('includes/pur_return_post.php');

/** عدد وأسماء مردودات فاتورة الشراء. */
function pur_invoices_list_return_subqueries(PDO $pdo): array
{
    if (!pur_return_has_tables($pdo)) {
        return ['count' => '0', 'nos' => "''"];
    }

    return [
        'count' => '(SELECT COUNT(*) FROM pur_return r WHERE r.invoice_id = i.id AND r.status <> \'cancelled\')',
        'nos' => "(SELECT GROUP_CONCAT(r.return_no ORDER BY r.return_date DESC, r.id DESC SEPARATOR '، ')
                  FROM pur_return r WHERE r.invoice_id = i.id AND r.status <> 'cancelled')",
    ];
}

/**
 * @return array{sql:string, params:list<mixed>, count_sql:string, count_params:list<mixed>}
 */
function pur_invoices_list_query(PDO $pdo, string $search = ''): array
{
    pur_invoice_ensure_schema($pdo);

    $posted = pur_invoice_sql_is_posted_expr('i');
    $retSub = pur_invoices_list_return_subqueries($pdo);

    $sql = "SELECT i.id AS doc_id, i.invoice_no AS doc_no, i.invoice_date AS doc_date,
            s.name_ar AS supplier_name, i.total AS total,
            ({$posted}) AS is_posted,
            ({$retSub['count']}) AS return_count,
            ({$retSub['nos']}) AS return_nos
        FROM pur_invoice i
        INNER JOIN crm_supplier s ON s.id = i.supplier_id
        WHERE i.status = 'confirmed'";

    $params = [];
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= ' AND (i.invoice_no LIKE ? OR s.name_ar LIKE ? OR s.code LIKE ?)';
        $params = [$like, $like, $like];
    }

    $sql .= ' ORDER BY i.invoice_date DESC, i.id DESC';
    $countSql = 'SELECT COUNT(*) FROM pur_invoice i
        INNER JOIN crm_supplier s ON s.id = i.supplier_id
        WHERE i.status = \'confirmed\'';
    $countParams = $params;
    if ($search !== '') {
        $countSql .= ' AND (i.invoice_no LIKE ? OR s.name_ar LIKE ? OR s.code LIKE ?)';
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
function pur_returns_documents_list_query(PDO $pdo, string $search = ''): array
{
    pur_return_ensure_schema($pdo);
    pur_invoice_ensure_schema($pdo);

    if (!pur_return_has_tables($pdo)) {
        return [
            'sql' => 'SELECT NULL AS doc_id WHERE 1=0',
            'params' => [],
            'count_sql' => 'SELECT 0',
            'count_params' => [],
        ];
    }

    $posted = pur_return_sql_is_posted_expr('r');

    $sql = "SELECT r.id AS doc_id, r.return_no AS doc_no, i.invoice_no AS ref_invoice_no,
            r.return_date AS doc_date, s.name_ar AS supplier_name, r.total AS total,
            ({$posted}) AS is_posted
        FROM pur_return r
        INNER JOIN crm_supplier s ON s.id = r.supplier_id
        INNER JOIN pur_invoice i ON i.id = r.invoice_id
        WHERE r.status <> 'cancelled'";

    $params = [];
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= ' AND (r.return_no LIKE ? OR i.invoice_no LIKE ? OR s.name_ar LIKE ? OR s.code LIKE ?)';
        $params = [$like, $like, $like, $like];
    }

    $sql .= ' ORDER BY r.return_date DESC, r.id DESC';
    $countSql = 'SELECT COUNT(*) FROM pur_return r
        INNER JOIN crm_supplier s ON s.id = r.supplier_id
        INNER JOIN pur_invoice i ON i.id = r.invoice_id
        WHERE r.status <> \'cancelled\'';
    $countParams = $params;
    if ($search !== '') {
        $countSql .= ' AND (r.return_no LIKE ? OR i.invoice_no LIKE ? OR s.name_ar LIKE ? OR s.code LIKE ?)';
    }

    return [
        'sql' => $sql,
        'params' => $params,
        'count_sql' => $countSql,
        'count_params' => $countParams,
    ];
}

/** @return array{rows:list<array<string,mixed>>, pager:array<string,mixed>} */
function pur_invoices_list_fetch(PDO $pdo, string $search, array $pager): array
{
    $q = pur_invoices_list_query($pdo, $search);

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

/** @return array{rows:list<array<string,mixed>>, pager:array<string,mixed>} */
function pur_returns_documents_list_fetch(PDO $pdo, string $search, array $pager): array
{
    $q = pur_returns_documents_list_query($pdo, $search);

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

function pur_documents_list_invoice_open_url(int $invoiceId): string
{
    return app_url('index.php?r=purchase_invoices&id=' . $invoiceId);
}

function pur_documents_list_return_open_url(int $returnId): string
{
    return app_url('index.php?r=purchase_returns&id=' . $returnId);
}
