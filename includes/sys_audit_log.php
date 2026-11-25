<?php
declare(strict_types=1);

/**
 * سجل حركات التعديل (حفظ، حذف، ترحيل، فك ترحيل) للمستندات والشاشات التشغيلية.
 */
function sys_audit_log_action_labels(): array
{
    return [
        'create' => 'إضافة',
        'update' => 'تعديل',
        'save' => 'حفظ',
        'delete' => 'حذف',
        'post' => 'ترحيل',
        'unpost' => 'فك ترحيل',
        'cancel' => 'إلغاء',
        'approve' => 'اعتماد',
        'unapprove' => 'فك اعتماد',
        'toggle' => 'تفعيل/إيقاف',
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
    require_once app_path('includes/sql_migration.php');
    if (!sys_audit_log_table_exists($pdo)) {
        sql_migration_run_file_once($pdo, 'database/migrations/157_sys_audit_log.sql');
    }
    if (sys_audit_log_table_exists($pdo)) {
        sql_migration_run_file_once($pdo, 'database/migrations/301_sys_audit_log_detail.sql');
    }
}

function sys_audit_log_money(PDO $pdo, float|string|null $amount): string
{
    if (!function_exists('format_money')) {
        require_once app_path('includes/helpers.php');
    }
    $dp = null;
    if (function_exists('company_decimal_places')) {
        try {
            $dp = company_decimal_places($pdo);
        } catch (Throwable $e) {
            $dp = null;
        }
    }

    return format_money((float) ($amount ?? 0), $dp);
}

/** @param array<string, scalar|null> $parts */
function sys_audit_log_build_summary(array $parts): ?string
{
    $chunks = [];
    foreach ($parts as $label => $value) {
        $v = trim((string) ($value ?? ''));
        if ($v === '' || $v === '—') {
            continue;
        }
        $chunks[] = trim((string) $label) . ': ' . $v;
    }
    if ($chunks === []) {
        return null;
    }

    return implode(' | ', $chunks);
}

function sys_audit_log_status_label(string $status): string
{
    return match ($status) {
        'draft' => 'مسودة',
        'confirmed' => 'مؤكدة',
        'cancelled' => 'ملغاة',
        'posted' => 'مرحّل',
        'approved' => 'معتمد',
        'submitted' => 'مُرسَل',
        'partial' => 'منفَّذ جزئياً',
        'closed' => 'مغلق',
        default => $status !== '' ? $status : '—',
    };
}

function sys_audit_log_payment_type_label(?string $ptype): string
{
  $p = strtolower(trim((string) ($ptype ?? '')));

  return match ($p) {
    'credit' => 'ذمم',
    'cash' => 'نقدي',
    default => $p !== '' ? $p : '—',
  };
}

function sys_audit_log_party_label(?string $name, ?string $code = null): string
{
    $n = trim((string) ($name ?? ''));
    $c = trim((string) ($code ?? ''));
    if ($n === '' && $c === '') {
        return '—';
    }
    if ($c !== '' && $n !== '') {
        return $n . ' (' . $c . ')';
    }

    return $n !== '' ? $n : $c;
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
    $actionLabel = isset($ctx['action_label_ar']) ? trim((string) $ctx['action_label_ar']) : '';
    if (!isset($actions[$actionCode])) {
        if ($actionLabel === '') {
            return;
        }
    } elseif ($actionLabel === '') {
        $actionLabel = $actions[$actionCode];
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
        $actionLabel,
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
    ?string $extraWhere = null,
    ?string $summary = null,
    array $extraCtx = []
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

    $ctx = array_merge($extraCtx, [
        'entity_type' => $entityType !== '' ? $entityType : $table,
        'entity_id' => $entityId,
        'entity_ref' => (string) ($row['_ref'] ?? ''),
        'doc_date' => (string) ($row['_doc_date'] ?? ''),
    ]);
    if ($summary !== null && trim($summary) !== '') {
        $ctx['summary'] = $summary;
    }

    sys_audit_log_write($pdo, $screenCode, $actionCode, $ctx);
}

/**
 * @param callable(array<string,mixed>):?string $summaryFn
 */
function sys_audit_log_doc(
    PDO $pdo,
    string $screenCode,
    string $actionCode,
    string $entityType,
    int $entityId,
    string $sql,
    callable $summaryFn,
    array $extraCtx = [],
    ?array $fallback = null
): void {
    if ($entityId < 1) {
        return;
    }
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$entityId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        if (is_array($fallback)) {
            sys_audit_log_from_table(
                $pdo,
                $screenCode,
                $actionCode,
                (string) ($fallback['table'] ?? $entityType),
                $entityId,
                (string) ($fallback['ref'] ?? 'id'),
                (string) ($fallback['date'] ?? 'created_at'),
                $entityType,
                $fallback['where'] ?? null,
                null,
                $extraCtx
            );
        }
        return;
    }
    if (!$row) {
        if (is_array($fallback)) {
            sys_audit_log_from_table(
                $pdo,
                $screenCode,
                $actionCode,
                (string) ($fallback['table'] ?? $entityType),
                $entityId,
                (string) ($fallback['ref'] ?? 'id'),
                (string) ($fallback['date'] ?? 'created_at'),
                $entityType,
                $fallback['where'] ?? null,
                null,
                $extraCtx
            );
        }
        return;
    }
    $summary = null;
    try {
        $summary = $summaryFn($row, $pdo);
    } catch (Throwable $e) {
        $summary = null;
    }
    $ctx = array_merge($extraCtx, [
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'entity_ref' => (string) ($row['_ref'] ?? ($row['entity_ref'] ?? '')),
        'doc_date' => (string) ($row['_doc_date'] ?? ($row['doc_date'] ?? '')),
    ]);
    if ($summary !== null && trim($summary) !== '') {
        $ctx['summary'] = $summary;
    }
    sys_audit_log_write($pdo, $screenCode, $actionCode, $ctx);
}

function sys_audit_log_sal_invoice(PDO $pdo, string $actionCode, int $invoiceId): void
{
    $sql = 'SELECT i.invoice_no AS _ref, i.invoice_date AS _doc_date, i.total, i.status, i.payment_type,
                   c.name_ar AS party_name, c.code AS party_code
            FROM sal_invoice i
            LEFT JOIN crm_customer c ON c.id = i.customer_id
            WHERE i.id = ? LIMIT 1';
    sys_audit_log_doc($pdo, 'sales_invoices', $actionCode, 'sal_invoice', $invoiceId, $sql, static function (array $row, PDO $pdo): ?string {
        return sys_audit_log_build_summary([
            'العميل' => sys_audit_log_party_label($row['party_name'] ?? '', $row['party_code'] ?? ''),
            'المبلغ' => sys_audit_log_money($pdo, $row['total'] ?? 0),
            'الحالة' => sys_audit_log_status_label((string) ($row['status'] ?? '')),
            'الدفع' => sys_audit_log_payment_type_label($row['payment_type'] ?? null),
        ]);
    }, [], ['table' => 'sal_invoice', 'ref' => 'invoice_no', 'date' => 'invoice_date']);
}

function sys_audit_log_pur_invoice(PDO $pdo, string $actionCode, int $invoiceId): void
{
    $sql = 'SELECT i.invoice_no AS _ref, i.invoice_date AS _doc_date, i.total, i.status,
                   s.name_ar AS party_name, s.code AS party_code
            FROM pur_invoice i
            LEFT JOIN crm_supplier s ON s.id = i.supplier_id
            WHERE i.id = ? LIMIT 1';
    sys_audit_log_doc($pdo, 'purchase_invoices', $actionCode, 'pur_invoice', $invoiceId, $sql, static function (array $row, PDO $pdo): ?string {
        return sys_audit_log_build_summary([
            'المورد' => sys_audit_log_party_label($row['party_name'] ?? '', $row['party_code'] ?? ''),
            'المبلغ' => sys_audit_log_money($pdo, $row['total'] ?? 0),
            'الحالة' => sys_audit_log_status_label((string) ($row['status'] ?? '')),
        ]);
    }, [], ['table' => 'pur_invoice', 'ref' => 'invoice_no', 'date' => 'invoice_date']);
}

function sys_audit_log_sal_return(PDO $pdo, string $actionCode, int $returnId): void
{
    $sql = 'SELECT r.return_no AS _ref, r.return_date AS _doc_date, r.total, r.status,
                   c.name_ar AS party_name, c.code AS party_code
            FROM sal_return r
            LEFT JOIN crm_customer c ON c.id = r.customer_id
            WHERE r.id = ? LIMIT 1';
    sys_audit_log_doc($pdo, 'sales_returns', $actionCode, 'sal_return', $returnId, $sql, static function (array $row, PDO $pdo): ?string {
        return sys_audit_log_build_summary([
            'العميل' => sys_audit_log_party_label($row['party_name'] ?? '', $row['party_code'] ?? ''),
            'المبلغ' => sys_audit_log_money($pdo, $row['total'] ?? 0),
            'الحالة' => sys_audit_log_status_label((string) ($row['status'] ?? '')),
        ]);
    }, [], ['table' => 'sal_return', 'ref' => 'return_no', 'date' => 'return_date']);
}

function sys_audit_log_pur_return(PDO $pdo, string $actionCode, int $returnId): void
{
    $sql = 'SELECT r.return_no AS _ref, r.return_date AS _doc_date, r.total, r.status,
                   s.name_ar AS party_name, s.code AS party_code
            FROM pur_return r
            LEFT JOIN crm_supplier s ON s.id = r.supplier_id
            WHERE r.id = ? LIMIT 1';
    sys_audit_log_doc($pdo, 'purchase_returns', $actionCode, 'pur_return', $returnId, $sql, static function (array $row, PDO $pdo): ?string {
        return sys_audit_log_build_summary([
            'المورد' => sys_audit_log_party_label($row['party_name'] ?? '', $row['party_code'] ?? ''),
            'المبلغ' => sys_audit_log_money($pdo, $row['total'] ?? 0),
            'الحالة' => sys_audit_log_status_label((string) ($row['status'] ?? '')),
        ]);
    }, [], ['table' => 'pur_return', 'ref' => 'return_no', 'date' => 'return_date']);
}

function sys_audit_log_sal_delivery(PDO $pdo, string $actionCode, int $deliveryId): void
{
    $sql = 'SELECT d.delivery_no AS _ref, d.delivery_date AS _doc_date, d.status,
                   c.name_ar AS party_name, c.code AS party_code
            FROM sal_delivery d
            LEFT JOIN crm_customer c ON c.id = d.customer_id
            WHERE d.id = ? LIMIT 1';
    sys_audit_log_doc($pdo, 'sales_delivery', $actionCode, 'sal_delivery', $deliveryId, $sql, static function (array $row, PDO $pdo): ?string {
        return sys_audit_log_build_summary([
            'العميل' => sys_audit_log_party_label($row['party_name'] ?? '', $row['party_code'] ?? ''),
            'الحالة' => sys_audit_log_status_label((string) ($row['status'] ?? '')),
        ]);
    }, [], ['table' => 'sal_delivery', 'ref' => 'delivery_no', 'date' => 'delivery_date']);
}

function sys_audit_log_fin_voucher(PDO $pdo, string $actionCode, int $voucherId, string $type): void
{
    $screen = $type === 'payment' ? 'cash_payment' : 'cash_receipt';
    $vtype = $type === 'payment' ? 'payment' : 'receipt';
    $sql = "SELECT v.voucher_no AS _ref, v.voucher_date AS _doc_date, v.amount, v.party_type, v.party_id,
                   c.name_ar AS customer_name, c.code AS customer_code,
                   s.name_ar AS supplier_name, s.code AS supplier_code
            FROM fin_voucher v
            LEFT JOIN crm_customer c ON v.party_type = 'customer' AND c.id = v.party_id
            LEFT JOIN crm_supplier s ON v.party_type = 'supplier' AND s.id = v.party_id
            WHERE v.id = ? AND v.voucher_type = '{$vtype}' LIMIT 1";
    sys_audit_log_doc($pdo, $screen, $actionCode, 'fin_voucher', $voucherId, $sql, static function (array $row, PDO $pdo): ?string {
        $party = '—';
        $pt = (string) ($row['party_type'] ?? '');
        if ($pt === 'customer') {
            $party = sys_audit_log_party_label($row['customer_name'] ?? '', $row['customer_code'] ?? '');
        } elseif ($pt === 'supplier') {
            $party = sys_audit_log_party_label($row['supplier_name'] ?? '', $row['supplier_code'] ?? '');
        }
        $posted = isset($row['is_posted']) ? ((int) $row['is_posted'] === 1 ? 'مرحّل' : 'غير مرحّل') : null;

        return sys_audit_log_build_summary([
            'الطرف' => $party,
            'المبلغ' => sys_audit_log_money($pdo, $row['amount'] ?? 0),
            'الترحيل' => $posted,
        ]);
    });
}

function sys_audit_log_acc_journal(PDO $pdo, string $actionCode, int $entryId): void
{
    $sql = 'SELECT e.entry_no AS _ref, e.entry_date AS _doc_date, e.status,
                   (SELECT COALESCE(SUM(debit), 0) FROM acc_journal_line l WHERE l.journal_id = e.id) AS debit_total
            FROM acc_journal_entry e WHERE e.id = ? LIMIT 1';
    sys_audit_log_doc($pdo, 'journal_voucher', $actionCode, 'acc_journal_entry', $entryId, $sql, static function (array $row, PDO $pdo): ?string {
        return sys_audit_log_build_summary([
            'الحالة' => sys_audit_log_status_label((string) ($row['status'] ?? '')),
            'مدين' => sys_audit_log_money($pdo, $row['debit_total'] ?? 0),
        ]);
    }, [], ['table' => 'acc_journal_entry', 'ref' => 'entry_no', 'date' => 'entry_date']);
}

function sys_audit_log_inv_wh_move(PDO $pdo, string $actionCode, int $moveId): void
{
    $sql = 'SELECT m.move_no AS _ref, m.move_date AS _doc_date, m.status, m.movement_type_code,
                   w.name_ar AS warehouse_name
            FROM inv_wh_move m
            LEFT JOIN inv_warehouse w ON w.id = m.warehouse_id
            WHERE m.id = ? LIMIT 1';
    sys_audit_log_doc($pdo, 'warehouse_moves', $actionCode, 'inv_wh_move', $moveId, $sql, static function (array $row, PDO $pdo): ?string {
        return sys_audit_log_build_summary([
            'المستودع' => (string) ($row['warehouse_name'] ?? ''),
            'النوع' => (string) ($row['movement_type_code'] ?? ''),
            'الحالة' => sys_audit_log_status_label((string) ($row['status'] ?? '')),
        ]);
    }, [], ['table' => 'inv_wh_move', 'ref' => 'move_no', 'date' => 'move_date']);
}

function sys_audit_log_inv_stocktake(PDO $pdo, string $actionCode, int $docId): void
{
    $sql = 'SELECT d.take_no AS _ref, d.take_date AS _doc_date, d.status, w.name_ar AS warehouse_name
            FROM inv_stocktake_doc d
            LEFT JOIN inv_warehouse w ON w.id = d.warehouse_id
            WHERE d.id = ? LIMIT 1';
    sys_audit_log_doc($pdo, 'inventory_stocktake', $actionCode, 'inv_stocktake_doc', $docId, $sql, static function (array $row, PDO $pdo): ?string {
        return sys_audit_log_build_summary([
            'المستودع' => (string) ($row['warehouse_name'] ?? ''),
            'الحالة' => sys_audit_log_status_label((string) ($row['status'] ?? '')),
        ]);
    }, [], ['table' => 'inv_stocktake_doc', 'ref' => 'take_no', 'date' => 'take_date']);
}

function sys_audit_log_sal_customer_order(PDO $pdo, string $actionCode, int $orderId, ?string $screenCode = null, array $extraCtx = []): void
{
    if ($orderId < 1) {
        return;
    }
    if ($screenCode === null || $screenCode === '') {
        $screenCode = 'sales_customer_order_entry';
    }
    $sql = 'SELECT o.id, o.order_no AS _ref, o.order_date AS _doc_date, o.total, o.status, o.payment_type,
                   c.name_ar AS customer_name, c.code AS customer_code,
                   COALESCE(r.name_ar, \'\') AS sales_rep_name
            FROM sal_customer_order o
            INNER JOIN crm_customer c ON c.id = o.customer_id
            LEFT JOIN crm_sales_rep r ON r.id = o.sales_rep_id
            WHERE o.id = ? LIMIT 1';
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$orderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        sys_audit_log_from_table($pdo, $screenCode, $actionCode, 'sal_customer_order', $orderId, 'order_no', 'order_date', 'sal_customer_order', null, null, $extraCtx);

        return;
    }
    if (!$row) {
        return;
    }
    require_once app_path('includes/sal_customer_order.php');
    $statusLabel = function_exists('sal_customer_order_status_label')
        ? sal_customer_order_status_label((string) ($row['status'] ?? ''))
        : sys_audit_log_status_label((string) ($row['status'] ?? ''));
    $summary = sys_audit_log_build_summary([
        'العميل' => sys_audit_log_party_label($row['customer_name'] ?? '', $row['customer_code'] ?? ''),
        'المندوب' => (string) ($row['sales_rep_name'] ?? ''),
        'المبلغ' => sys_audit_log_money($pdo, $row['total'] ?? 0),
        'الحالة' => $statusLabel,
        'الدفع' => sys_audit_log_payment_type_label($row['payment_type'] ?? null),
    ]);
    $ctx = array_merge($extraCtx, [
        'entity_type' => 'sal_customer_order',
        'entity_id' => $orderId,
        'entity_ref' => (string) ($row['_ref'] ?? ''),
        'doc_date' => (string) ($row['_doc_date'] ?? ''),
        'summary' => $summary,
    ]);
    sys_audit_log_write($pdo, $screenCode, $actionCode, $ctx);
}

/** @param array<string,mixed> $snapshot */
function sys_audit_log_sal_customer_order_snapshot(PDO $pdo, string $actionCode, array $snapshot, ?string $screenCode = null, array $extraCtx = []): void
{
    if ($screenCode === null || $screenCode === '') {
        $screenCode = 'sales_customer_order_entry';
    }
    $orderId = isset($snapshot['id']) ? (int) $snapshot['id'] : 0;
    require_once app_path('includes/sal_customer_order.php');
    $statusLabel = function_exists('sal_customer_order_status_label')
        ? sal_customer_order_status_label((string) ($snapshot['status'] ?? ''))
        : sys_audit_log_status_label((string) ($snapshot['status'] ?? ''));
    $summary = sys_audit_log_build_summary([
        'العميل' => sys_audit_log_party_label($snapshot['customer_name'] ?? '', $snapshot['customer_code'] ?? ''),
        'المندوب' => (string) ($snapshot['sales_rep_name'] ?? ''),
        'المبلغ' => sys_audit_log_money($pdo, $snapshot['total'] ?? 0),
        'الحالة' => $statusLabel,
        'الدفع' => sys_audit_log_payment_type_label($snapshot['payment_type'] ?? null),
    ]);
    $ctx = array_merge($extraCtx, [
        'entity_type' => 'sal_customer_order',
        'entity_id' => $orderId > 0 ? $orderId : null,
        'entity_ref' => (string) ($snapshot['order_no'] ?? ''),
        'doc_date' => (string) ($snapshot['order_date'] ?? ''),
        'summary' => $summary,
    ]);
    sys_audit_log_write($pdo, $screenCode, $actionCode, $ctx);
}

function sys_audit_log_pur_order(PDO $pdo, string $actionCode, int $orderId, ?array $snapshot = null, array $extraCtx = []): void
{
  $screen = 'purchase_orders';
  if ($snapshot !== null && $snapshot !== []) {
    require_once app_path('includes/pur_order_schema.php');
    $statusLabel = function_exists('pur_order_status_label')
      ? pur_order_status_label((string) ($snapshot['status'] ?? ''))
      : sys_audit_log_status_label((string) ($snapshot['status'] ?? ''));
    $summary = sys_audit_log_build_summary([
      'المورد' => (string) ($snapshot['supplier_name'] ?? ''),
      'المبلغ' => sys_audit_log_money($pdo, $snapshot['total'] ?? 0),
      'الحالة' => $statusLabel,
    ]);
    sys_audit_log_write($pdo, $screen, $actionCode, array_merge($extraCtx, [
      'entity_type' => 'pur_order',
      'entity_id' => $orderId > 0 ? $orderId : null,
      'entity_ref' => (string) ($snapshot['order_no'] ?? ''),
      'doc_date' => (string) ($snapshot['order_date'] ?? ''),
      'summary' => $summary,
    ]));
    return;
  }
  $sql = 'SELECT o.order_no AS _ref, o.order_date AS _doc_date, o.total, o.status, s.name_ar AS supplier_name
          FROM pur_order o LEFT JOIN crm_supplier s ON s.id = o.supplier_id WHERE o.id = ? LIMIT 1';
  sys_audit_log_doc($pdo, $screen, $actionCode, 'pur_order', $orderId, $sql, static function (array $row, PDO $pdo): ?string {
    require_once app_path('includes/pur_order_schema.php');
    $statusLabel = function_exists('pur_order_status_label')
      ? pur_order_status_label((string) ($row['status'] ?? ''))
      : sys_audit_log_status_label((string) ($row['status'] ?? ''));
    return sys_audit_log_build_summary([
      'المورد' => (string) ($row['supplier_name'] ?? ''),
      'المبلغ' => sys_audit_log_money($pdo, $row['total'] ?? 0),
      'الحالة' => $statusLabel,
    ]);
  }, $extraCtx);
}

function sys_audit_log_crm_customer(PDO $pdo, string $actionCode, ?array $snapshot, array $extraCtx = []): void
{
    $screen = (string) ($extraCtx['screen_code'] ?? 'm_customer_list');
    unset($extraCtx['screen_code']);
    $customerId = isset($snapshot['id']) ? (int) $snapshot['id'] : 0;
    $summary = sys_audit_log_build_summary([
        'العميل' => sys_audit_log_party_label($snapshot['name_ar'] ?? '', $snapshot['code'] ?? ''),
    ]);
    sys_audit_log_write($pdo, $screen, $actionCode, array_merge($extraCtx, [
        'entity_type' => 'crm_customer',
        'entity_id' => $customerId > 0 ? $customerId : null,
        'entity_ref' => (string) ($snapshot['code'] ?? $snapshot['name_ar'] ?? ''),
        'summary' => $summary,
    ]));
}

/** @param array<string,mixed> $snapshot */


function sys_audit_log_inv_item(PDO $pdo, string $actionCode, int $itemId, array $extraCtx = []): void
{
    $itemId = (int) $itemId;
    if ($itemId < 1) {
        return;
    }
    $cols = 'id, sku, barcode, name_ar, default_sale';
    try {
        $pdo->query('SELECT default_wholesale FROM inv_item LIMIT 1');
        $cols .= ', default_wholesale';
    } catch (Throwable $e) {
    }
    $st = $pdo->prepare("SELECT {$cols} FROM inv_item WHERE id = ? LIMIT 1");
    $st->execute([$itemId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return;
    }
    $parts = [
        'المادة' => (string) ($row['name_ar'] ?? ''),
        'رمز' => (string) ($row['sku'] ?? ''),
        'بيع' => sys_audit_log_money($pdo, $row['default_sale'] ?? 0),
    ];
    if (trim((string) ($row['barcode'] ?? '')) !== '') {
        $parts['باركود'] = (string) $row['barcode'];
    }
    if (array_key_exists('default_wholesale', $row)) {
        $parts['جملة'] = sys_audit_log_money($pdo, $row['default_wholesale'] ?? 0);
    }
    if (isset($extraCtx['الحالة'])) {
        $parts['الحالة'] = (string) $extraCtx['الحالة'];
        unset($extraCtx['الحالة']);
    }
    $summary = sys_audit_log_build_summary($parts);
    $ref = trim((string) ($row['barcode'] ?? '')) !== ''
        ? (string) $row['barcode']
        : (string) ($row['sku'] ?? (string) $itemId);
    sys_audit_log_write($pdo, 'items', $actionCode, array_merge($extraCtx, [
        'domain_code' => 'inventory',
        'entity_type' => 'inv_item',
        'entity_id' => $itemId,
        'entity_ref' => $ref,
        'summary' => $summary,
    ]));
}

function sys_audit_log_visit_line(PDO $pdo, string $actionCode, array $snapshot, array $extraCtx = []): void
{
    $lineId = (int) ($snapshot['line_id'] ?? 0);
    $summary = sys_audit_log_build_summary([
        'العميل' => sys_audit_log_party_label($snapshot['customer_name'] ?? '', $snapshot['customer_code'] ?? ''),
        'المندوب' => (string) ($snapshot['sales_rep_name'] ?? ''),
        'تاريخ المسار' => (string) ($snapshot['route_date'] ?? ''),
        'دخول' => (string) ($snapshot['visit_checkin_at'] ?? ''),
    ]);
    sys_audit_log_write($pdo, 'report_sales_rep_visits', $actionCode, array_merge($extraCtx, [
        'entity_type' => 'sal_rep_route_line',
        'entity_id' => $lineId > 0 ? $lineId : null,
        'entity_ref' => (string) ($snapshot['entity_ref'] ?? ''),
        'doc_date' => (string) ($snapshot['route_date'] ?? ''),
        'summary' => $summary,
    ]));
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
        'sales_customer_orders' => 'sales_customer_order_entry',
        'sales_customer_order_entry' => 'sales_customer_order_entry',
        'sales_customer_orders_approve' => 'sales_customer_orders_approve',
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
    ?int $userId = null,
    ?string $search = null,
    ?int $limit = null,
    ?int $offset = null,
    ?string $actionCode = null
): array {
    if (!sys_audit_log_table_exists($pdo)) {
        return [];
    }

    [$whereSql, $params] = sys_audit_log_filter_sql(
        $fromIso,
        $toIso,
        $domainCode,
        $screenCode,
        $userId,
        $search,
        $actionCode
    );

    $sql = 'SELECT a.*, COALESCE(u.full_name_ar, u.username, \'—\') AS user_name
            FROM sys_audit_log a
            LEFT JOIN sys_user u ON u.id = a.user_id
            WHERE ' . $whereSql . '
            ORDER BY a.logged_at DESC, a.id DESC';

    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, (int) $limit);
        if ($offset !== null && $offset > 0) {
            $sql .= ' OFFSET ' . max(0, (int) $offset);
        }
    } else {
        $sql .= ' LIMIT 5000';
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sys_audit_log_count(
    PDO $pdo,
    string $fromIso,
    string $toIso,
    ?string $domainCode = null,
    ?string $screenCode = null,
    ?int $userId = null,
    ?string $search = null,
    ?string $actionCode = null
): int {
    if (!sys_audit_log_table_exists($pdo)) {
        return 0;
    }

    [$whereSql, $params] = sys_audit_log_filter_sql(
        $fromIso,
        $toIso,
        $domainCode,
        $screenCode,
        $userId,
        $search,
        $actionCode
    );

    $sql = 'SELECT COUNT(*)
            FROM sys_audit_log a
            LEFT JOIN sys_user u ON u.id = a.user_id
            WHERE ' . $whereSql;
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (int) $st->fetchColumn();
}

/**
 * @return array{0:string,1:list<mixed>}
 */
function sys_audit_log_filter_sql(
    string $fromIso,
    string $toIso,
    ?string $domainCode = null,
    ?string $screenCode = null,
    ?int $userId = null,
    ?string $search = null,
    ?string $actionCode = null
): array {
    $sql = 'DATE(a.logged_at) >= ? AND DATE(a.logged_at) <= ?';
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
    if ($actionCode !== null && $actionCode !== '') {
        $sql .= ' AND a.action_code = ?';
        $params[] = $actionCode;
    }

    $q = trim((string) $search);
    if ($q !== '') {
        $like = '%' . $q . '%';
        $sql .= ' AND (
            a.entity_ref LIKE ?
            OR a.screen_label_ar LIKE ?
            OR a.action_label_ar LIKE ?
            OR IFNULL(a.summary, \'\') LIKE ?
            OR COALESCE(u.full_name_ar, u.username, \'\') LIKE ?
            OR a.screen_code LIKE ?
            OR a.action_code LIKE ?
        )';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    return [$sql, $params];
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
