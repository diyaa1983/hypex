<?php
declare(strict_types=1);

/**
 * سجل حركات التعديل (حفظ، حذف، ترحيل، فك ترحيل) للمستندات والشاشات التشغيلية.
 */
function sys_audit_log_action_labels(): array
{
    return [
        'save' => 'حفظ',
        'delete' => 'حذف',
        'post' => 'ترحيل',
        'unpost' => 'فك ترحيل',
    ];
}

/** @return array<string, array{domain_code:string, domain_title:string, label:string}> */
function sys_audit_log_screen_registry(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    $navPath = app_path('config/nav_menu.php');
    if (!is_file($navPath)) {
        return $cache;
    }

    $nav = require $navPath;
    foreach (($nav['domains'] ?? []) as $domain) {
        $domainCode = (string) ($domain['id'] ?? '');
        $domainTitle = (string) ($domain['title'] ?? $domainCode);
        foreach (($domain['subgroups'] ?? []) as $sub) {
            foreach (($sub['items'] ?? []) as $item) {
                $code = (string) ($item['r'] ?? '');
                if ($code === '' || str_starts_with($code, 'report_') || str_starts_with($code, 'm_')) {
                    continue;
                }
                if ($code === 'dashboard' || $code === 'menu_hub') {
                    continue;
                }
                $cache[$code] = [
                    'domain_code' => $domainCode,
                    'domain_title' => $domainTitle,
                    'label' => (string) ($item['label'] ?? $code),
                ];
            }
        }
    }

    return $cache;
}

/** @return list<array{id:string, title:string}> */
function sys_audit_log_domain_options(): array
{
    $navPath = app_path('config/nav_menu.php');
    if (!is_file($navPath)) {
        return [];
    }
    $nav = require $navPath;
    $out = [];
    foreach (($nav['domains'] ?? []) as $domain) {
        $id = (string) ($domain['id'] ?? '');
        if ($id === '' || $id === 'main' || $id === 'mobile') {
            continue;
        }
        $out[] = ['id' => $id, 'title' => (string) ($domain['title'] ?? $id)];
    }

    return $out;
}

/** @return list<array{code:string, label:string, domain_code:string}> */
function sys_audit_log_screen_options(?string $domainCode = null): array
{
    $registry = sys_audit_log_screen_registry();
    $out = [];
    foreach ($registry as $code => $meta) {
        if ($domainCode !== null && $domainCode !== '' && $meta['domain_code'] !== $domainCode) {
            continue;
        }
        $out[] = [
            'code' => $code,
            'label' => $meta['label'],
            'domain_code' => $meta['domain_code'],
        ];
    }
    usort($out, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));

    return $out;
}

function sys_audit_log_table_exists(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM sys_audit_log LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function sys_audit_log_ensure_schema(PDO $pdo): void
{
    if (sys_audit_log_table_exists($pdo)) {
        return;
    }
    require_once app_path('includes/sql_migration.php');
    sql_migration_run_file_once($pdo, 'database/migrations/157_sys_audit_log.sql');
}

function sys_audit_log_write(
    PDO $pdo,
    string $screenCode,
    string $actionCode,
    array $ctx = []
): void {
    sys_audit_log_ensure_schema($pdo);
    if (!sys_audit_log_table_exists($pdo)) {
        return;
    }

    $actions = sys_audit_log_action_labels();
    if (!isset($actions[$actionCode])) {
        return;
    }

    $registry = sys_audit_log_screen_registry();
    $meta = $registry[$screenCode] ?? null;
    $domainCode = (string) ($ctx['domain_code'] ?? ($meta['domain_code'] ?? ''));
    $screenLabel = (string) ($ctx['screen_label_ar'] ?? ($meta['label'] ?? $screenCode));

    $userId = array_key_exists('user_id', $ctx)
        ? ($ctx['user_id'] !== null ? (int) $ctx['user_id'] : null)
        : (int) (current_user()['id'] ?? 0);
    if ($userId < 1) {
        $userId = null;
    }

    $entityRef = isset($ctx['entity_ref']) ? trim((string) $ctx['entity_ref']) : null;
    if ($entityRef === '') {
        $entityRef = null;
    }

    $docDate = isset($ctx['doc_date']) ? trim((string) $ctx['doc_date']) : null;
    if ($docDate === '') {
        $docDate = null;
    }

    $summary = isset($ctx['summary']) ? trim((string) $ctx['summary']) : null;
    if ($summary === '') {
        $summary = null;
    }

    $entityId = isset($ctx['entity_id']) ? (int) $ctx['entity_id'] : null;
    if ($entityId !== null && $entityId < 1) {
        $entityId = null;
    }

    $pdo->prepare(
        'INSERT INTO sys_audit_log
            (user_id, domain_code, screen_code, screen_label_ar, action_code, action_label_ar,
             entity_type, entity_id, entity_ref, doc_date, summary)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $userId,
        $domainCode,
        $screenCode,
        $screenLabel,
        $actionCode,
        $actions[$actionCode],
        (string) ($ctx['entity_type'] ?? ''),
        $entityId,
        $entityRef,
        $docDate,
        $summary,
    ]);
}

function sys_audit_log_from_table(
    PDO $pdo,
    string $screenCode,
    string $actionCode,
    string $table,
    int $entityId,
    string $refColumn,
    string $dateColumn,
    string $entityType = '',
    ?string $extraWhere = null
): void {
    if ($entityId < 1) {
        return;
    }

    $sql = "SELECT `{$refColumn}` AS _ref, `{$dateColumn}` AS _doc_date FROM `{$table}` WHERE id = ?";
    if ($extraWhere !== null && $extraWhere !== '') {
        $sql .= ' AND (' . $extraWhere . ')';
    }
    $sql .= ' LIMIT 1';

    try {
        $st = $pdo->prepare($sql);
        $st->execute([$entityId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return;
    }

    if (!$row) {
        return;
    }

    sys_audit_log_write($pdo, $screenCode, $actionCode, [
        'entity_type' => $entityType !== '' ? $entityType : $table,
        'entity_id' => $entityId,
        'entity_ref' => (string) ($row['_ref'] ?? ''),
        'doc_date' => (string) ($row['_doc_date'] ?? ''),
    ]);
}

function sys_audit_log_sal_invoice(PDO $pdo, string $actionCode, int $invoiceId): void
{
    sys_audit_log_from_table($pdo, 'sales_invoices', $actionCode, 'sal_invoice', $invoiceId, 'invoice_no', 'invoice_date', 'sal_invoice');
}

function sys_audit_log_pur_invoice(PDO $pdo, string $actionCode, int $invoiceId): void
{
    sys_audit_log_from_table($pdo, 'purchase_invoices', $actionCode, 'pur_invoice', $invoiceId, 'invoice_no', 'invoice_date', 'pur_invoice');
}

function sys_audit_log_sal_return(PDO $pdo, string $actionCode, int $returnId): void
{
    sys_audit_log_from_table($pdo, 'sales_returns', $actionCode, 'sal_return', $returnId, 'return_no', 'return_date', 'sal_return');
}

function sys_audit_log_pur_return(PDO $pdo, string $actionCode, int $returnId): void
{
    sys_audit_log_from_table($pdo, 'purchase_returns', $actionCode, 'pur_return', $returnId, 'return_no', 'return_date', 'pur_return');
}

function sys_audit_log_sal_delivery(PDO $pdo, string $actionCode, int $deliveryId): void
{
    sys_audit_log_from_table($pdo, 'sales_delivery', $actionCode, 'sal_delivery', $deliveryId, 'delivery_no', 'delivery_date', 'sal_delivery');
}

function sys_audit_log_fin_voucher(PDO $pdo, string $actionCode, int $voucherId, string $type): void
{
    $screen = $type === 'payment' ? 'cash_payment' : 'cash_receipt';
    sys_audit_log_from_table(
        $pdo,
        $screen,
        $actionCode,
        'fin_voucher',
        $voucherId,
        'voucher_no',
        'voucher_date',
        'fin_voucher',
        "voucher_type = '" . ($type === 'payment' ? 'payment' : 'receipt') . "'"
    );
}

function sys_audit_log_acc_journal(PDO $pdo, string $actionCode, int $entryId): void
{
    sys_audit_log_from_table($pdo, 'journal_voucher', $actionCode, 'acc_journal_entry', $entryId, 'entry_no', 'entry_date', 'acc_journal_entry');
}

function sys_audit_log_inv_wh_move(PDO $pdo, string $actionCode, int $moveId): void
{
    sys_audit_log_from_table($pdo, 'warehouse_moves', $actionCode, 'inv_wh_move', $moveId, 'move_no', 'move_date', 'inv_wh_move');
}

function sys_audit_log_inv_stocktake(PDO $pdo, string $actionCode, int $docId): void
{
    sys_audit_log_from_table($pdo, 'inventory_stocktake', $actionCode, 'inv_stocktake_doc', $docId, 'take_no', 'take_date', 'inv_stocktake_doc');
}

function sys_audit_log_entity_url(string $screenCode, ?int $entityId): ?string
{
    if ($entityId === null || $entityId < 1) {
        return null;
    }

    $map = [
        'sales_invoices' => 'sales_invoices',
        'sales_returns' => 'sales_returns',
        'sales_delivery' => 'sales_delivery',
        'purchase_invoices' => 'purchase_invoices',
        'purchase_returns' => 'purchase_returns',
        'cash_receipt' => 'cash_receipt',
        'cash_payment' => 'cash_payment',
        'journal_voucher' => 'journal_voucher',
        'warehouse_moves' => 'warehouse_moves',
        'inventory_stocktake' => 'inventory_stocktake',
        'debit_notes' => 'debit_notes',
        'credit_notes' => 'credit_notes',
    ];

    if (!isset($map[$screenCode])) {
        return null;
    }

    return app_url('index.php?r=' . $map[$screenCode] . '&id=' . $entityId);
}

/**
 * @return list<array<string, mixed>>
 */
function sys_audit_log_fetch(
    PDO $pdo,
    string $fromIso,
    string $toIso,
    ?string $domainCode = null,
    ?string $screenCode = null,
    ?int $userId = null
): array {
    if (!sys_audit_log_table_exists($pdo)) {
        return [];
    }

    $sql = 'SELECT a.*, COALESCE(u.full_name_ar, u.username, \'—\') AS user_name
            FROM sys_audit_log a
            LEFT JOIN sys_user u ON u.id = a.user_id
            WHERE DATE(a.logged_at) >= ? AND DATE(a.logged_at) <= ?';
    $params = [$fromIso, $toIso];

    if ($domainCode !== null && $domainCode !== '') {
        $sql .= ' AND a.domain_code = ?';
        $params[] = $domainCode;
    }
    if ($screenCode !== null && $screenCode !== '') {
        $sql .= ' AND a.screen_code = ?';
        $params[] = $screenCode;
    }
    if ($userId !== null && $userId > 0) {
        $sql .= ' AND a.user_id = ?';
        $params[] = $userId;
    }

    $sql .= ' ORDER BY a.logged_at DESC, a.id DESC LIMIT 5000';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array{id:int, label:string}> */
function sys_audit_log_user_options(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            'SELECT id, COALESCE(NULLIF(full_name_ar, \'\'), username) AS label
             FROM sys_user WHERE is_active = 1 ORDER BY label'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $out[] = ['id' => (int) $row['id'], 'label' => (string) $row['label']];
    }

    return $out;
}
