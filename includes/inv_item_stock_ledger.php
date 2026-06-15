<?php
declare(strict_types=1);

require_once app_path('includes/inv_stock.php');
require_once app_path('includes/acc_report_ref.php');
require_once app_path('includes/inv_invoice_line_qty.php');
require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/pur_invoice_schema.php');
require_once app_path('includes/company_settings.php');
require_once app_path('includes/inv_item_display.php');

function inv_stock_qty_approx_equal(float $a, float $b): bool
{
    return abs($a - $b) < 0.000001;
}

/**
 * ربط حركات المخزون بسعر الوحدة غير الشامل من بنود المستند (فاتورة، مرتجع، …).
 */
final class InvStockMoveUnitPriceResolver
{
    /** @var array<string, list<array{stock_qty:float, unit_price:float}>> */
    private array $queues = [];

    /** @var array<string, int> */
    private array $cursor = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $itemId
    ) {
    }

    /**
     * @param list<array{ref_type?:string, ref_id?:int}> $moves
     */
    public function preload(array $moves): void
    {
        $idsByType = [];
        foreach ($moves as $row) {
            $refType = (string) ($row['ref_type'] ?? '');
            $refId = (int) ($row['ref_id'] ?? 0);
            if ($refType === '' || $refId < 1) {
                continue;
            }
            $idsByType[$refType][$refId] = true;
        }

        foreach ($idsByType as $refType => $idMap) {
            $this->loadRefType($refType, array_map('intval', array_keys($idMap)));
        }
    }

    public function resolve(string $refType, int $refId, float $qtyDelta): ?float
    {
        if ($refId < 1 || $this->itemId < 1) {
            return null;
        }

        if ($refType === 'item_opening') {
            return $this->openingUnitPrice($refId);
        }

        $key = $refType . ':' . $refId . ':' . $this->itemId;
        if (!isset($this->queues[$key])) {
            $this->loadRefType($refType, [$refId]);
        }

        $queue = $this->queues[$key] ?? [];
        if ($queue === []) {
            return null;
        }

        $mag = abs($qtyDelta);
        $start = $this->cursor[$key] ?? 0;
        for ($i = $start, $n = count($queue); $i < $n; $i++) {
            if (inv_stock_qty_approx_equal($queue[$i]['stock_qty'], $mag)) {
                $this->cursor[$key] = $i + 1;

                return $queue[$i]['unit_price'];
            }
        }

        if ($start < count($queue)) {
            $this->cursor[$key] = $start + 1;

            return $queue[$start]['unit_price'];
        }

        if (inv_stock_move_is_warehouse_document_ref($refType)) {
            return $this->openingUnitPrice($this->itemId);
        }

        return null;
    }

    private function openingUnitPrice(int $refId): ?float
    {
        $itemId = $refId > 0 ? $refId : $this->itemId;
        $st = $this->pdo->prepare('SELECT default_cost FROM inv_item WHERE id = ? LIMIT 1');
        $st->execute([$itemId]);
        $cost = $st->fetchColumn();
        if ($cost === false || $cost === null) {
            return null;
        }
        $v = (float) $cost;

        return $v > 0 ? $v : null;
    }

    /**
     * @param list<int> $refIds
     */
    private function loadRefType(string $refType, array $refIds): void
    {
        $refIds = array_values(array_filter(array_map('intval', $refIds), static fn (int $id): bool => $id > 0));
        if ($refIds === []) {
            return;
        }

        inv_invoice_line_ensure_qty_extra($this->pdo);
        sal_invoice_ensure_schema($this->pdo);
        pur_invoice_ensure_schema($this->pdo);

        $placeholders = implode(',', array_fill(0, count($refIds), '?'));
        $params = array_merge($refIds, [$this->itemId]);

        $rows = [];
        try {
            switch ($refType) {
                case 'sale_invoice':
                    $hasExtra = inv_invoice_line_has_qty_extra($this->pdo, 'sal_invoice_line');
                    $extra = $hasExtra ? 'COALESCE(l.qty_extra, 0)' : '0';
                    $st = $this->pdo->prepare(
                        "SELECT l.invoice_id AS ref_id, l.id AS line_id, l.unit_price, l.qty, {$extra} AS qty_extra
                         FROM sal_invoice_line l
                         WHERE l.invoice_id IN ({$placeholders}) AND l.item_id = ?
                         ORDER BY l.invoice_id ASC, l.id ASC"
                    );
                    $st->execute($params);
                    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    break;

                case 'purchase_invoice':
                    $hasExtra = inv_invoice_line_has_qty_extra($this->pdo, 'pur_invoice_line');
                    $extra = $hasExtra ? 'COALESCE(l.qty_extra, 0)' : '0';
                    $st = $this->pdo->prepare(
                        "SELECT l.invoice_id AS ref_id, l.id AS line_id, l.unit_price, l.qty, {$extra} AS qty_extra
                         FROM pur_invoice_line l
                         WHERE l.invoice_id IN ({$placeholders}) AND l.item_id = ?
                         ORDER BY l.invoice_id ASC, l.id ASC"
                    );
                    $st->execute($params);
                    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    break;

                case 'sale_return':
                    $st = $this->pdo->prepare(
                        "SELECT l.return_id AS ref_id, l.id AS line_id, l.unit_price, l.qty, 0 AS qty_extra
                         FROM sal_return_line l
                         WHERE l.return_id IN ({$placeholders}) AND l.item_id = ?
                         ORDER BY l.return_id ASC, l.id ASC"
                    );
                    $st->execute($params);
                    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    break;

                case 'purchase_return':
                    $st = $this->pdo->prepare(
                        "SELECT l.return_id AS ref_id, l.id AS line_id, l.unit_price, l.qty, 0 AS qty_extra
                         FROM pur_return_line l
                         WHERE l.return_id IN ({$placeholders}) AND l.item_id = ?
                         ORDER BY l.return_id ASC, l.id ASC"
                    );
                    $st->execute($params);
                    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    break;

                default:
                    return;
            }
        } catch (Throwable $e) {
            return;
        }

        foreach ($rows as $row) {
            $refId = (int) ($row['ref_id'] ?? 0);
            if ($refId < 1) {
                continue;
            }
            $stockQty = inv_invoice_line_stock_qty_sum(
                (float) ($row['qty'] ?? 0),
                (float) ($row['qty_extra'] ?? 0)
            );
            if ($refType === 'sale_return' || $refType === 'purchase_return') {
                $stockQty = max(0.0, (float) ($row['qty'] ?? 0));
            }
            if ($stockQty <= 0) {
                continue;
            }

            $key = $refType . ':' . $refId . ':' . $this->itemId;
            $this->queues[$key] ??= [];
            $this->queues[$key][] = [
                'stock_qty' => $stockQty,
                'unit_price' => (float) ($row['unit_price'] ?? 0),
            ];
        }
    }
}

function inv_item_stock_ledger_format_unit_price(?float $unitPrice, ?PDO $pdo = null): string
{
    if ($unitPrice === null || abs($unitPrice) < 0.000001) {
        return '—';
    }

    return format_money($unitPrice, company_invoice_unit_price_decimal_places($pdo));
}

function inv_item_stock_ledger_format_qty(float $qty): string
{
    if ($qty < 0.000001) {
        return '—';
    }
    $rounded = round($qty, 6);
    if (abs($rounded - round($rounded)) < 0.000001) {
        return format_amount($rounded, 0);
    }

    return rtrim(rtrim(format_amount($rounded, 3), '0'), '.');
}

function inv_stock_move_is_warehouse_document_ref(string $refType): bool
{
    return in_array($refType, [
        'stock_adjust_in',
        'stock_adjust_out',
        'stock_disposal',
        'warehouse_transfer_out',
        'warehouse_transfer_in',
    ], true);
}

function inv_item_stock_ledger_line_total(float $qty, ?float $unitPrice): float
{
    if ($unitPrice === null || $qty < 0.000001 || abs($unitPrice) < 0.000001) {
        return 0.0;
    }

    return round($qty * $unitPrice, 6);
}

function inv_item_stock_ledger_format_line_total(float $qty, ?float $unitPrice, ?PDO $pdo = null): string
{
    $total = inv_item_stock_ledger_line_total($qty, $unitPrice);
    if ($total < 0.000001) {
        return '—';
    }

    return format_amount($total, company_decimal_places($pdo));
}

/**
 * تسميات أنواع حركة المخزون للعرض.
 *
 * @return array<string, string>
 */
function inv_stock_move_type_labels(): array
{
    return [
        'sale_invoice' => 'مبيعات',
        'purchase_invoice' => 'مشتريات',
        'sale_return' => 'مرتجع مبيعات',
        'purchase_return' => 'مردود مشتريات',
        'item_opening' => 'رصيد افتتاحي',
        'warehouse_transfer' => 'نقل',
        'stock_transfer' => 'نقل',
        'warehouse_transfer_in' => 'نقل وارد',
        'warehouse_transfer_out' => 'نقل صادر',
        'stock_adjustment' => 'تعديل',
        'inventory_adjustment' => 'تعديل',
        'stock_adjust_in' => 'تعديل بالزيادة',
        'stock_adjust_out' => 'تعديل بالنقصان',
        'stock_disposal' => 'إتلاف',
        'stocktake_adjust_in' => 'جرد بالزيادة',
        'stocktake_adjust_out' => 'جرد بالنقصان',
    ];
}

function inv_stock_move_type_label(string $refType): string
{
    $labels = inv_stock_move_type_labels();

    return $labels[$refType] ?? ($refType !== '' ? $refType : 'حركة');
}

function inv_stock_move_ref_url(string $refType, int $refId): ?string
{
    if ($refId < 1 || $refType === '') {
        return null;
    }

    $url = acc_report_ref_url($refType, $refId);
    if ($url !== null) {
        return $url;
    }

    if ($refType === 'item_opening') {
        return app_url('index.php?r=items&action=edit&id=' . $refId);
    }

    if (in_array($refType, ['stock_adjust_in', 'stock_adjust_out', 'stock_disposal', 'warehouse_transfer_out', 'warehouse_transfer_in'], true)) {
        return app_url('index.php?r=warehouse_moves&id=' . $refId);
    }
    if (in_array($refType, ['stocktake_adjust_in', 'stocktake_adjust_out'], true)) {
        return app_url('index.php?r=inventory_stocktake&id=' . $refId);
    }

    return null;
}

/**
 * رقم المستند المرتبط بالحركة (فاتورة، مردود، …).
 */
/**
 * يعيد اسم الطرف (عميل لمستندات المبيعات، مورد لمستندات الشراء) المرتبط بحركة المخزون.
 *
 * @return array{role:string, name:string}  role: 'customer' | 'supplier' | ''
 */
function inv_stock_move_party(PDO $pdo, string $refType, int $refId): array
{
    if ($refId < 1) {
        return ['role' => '', 'name' => ''];
    }

    static $cache = [];
    $key = $refType . ':' . $refId;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $role = '';
    $name = '';
    try {
        switch ($refType) {
            case 'sale_invoice':
                $role = 'customer';
                $st = $pdo->prepare(
                    'SELECT c.name_ar FROM sal_invoice i
                     LEFT JOIN crm_customer c ON c.id = i.customer_id
                     WHERE i.id = ? LIMIT 1'
                );
                $st->execute([$refId]);
                $name = (string) ($st->fetchColumn() ?: '');
                break;
            case 'sale_return':
                $role = 'customer';
                $st = $pdo->prepare(
                    'SELECT c.name_ar FROM sal_return r
                     LEFT JOIN crm_customer c ON c.id = r.customer_id
                     WHERE r.id = ? LIMIT 1'
                );
                $st->execute([$refId]);
                $name = (string) ($st->fetchColumn() ?: '');
                break;
            case 'purchase_invoice':
                $role = 'supplier';
                $st = $pdo->prepare(
                    'SELECT s.name_ar FROM pur_invoice i
                     LEFT JOIN crm_supplier s ON s.id = i.supplier_id
                     WHERE i.id = ? LIMIT 1'
                );
                $st->execute([$refId]);
                $name = (string) ($st->fetchColumn() ?: '');
                break;
            case 'purchase_return':
                $role = 'supplier';
                $st = $pdo->prepare(
                    'SELECT s.name_ar FROM pur_return r
                     LEFT JOIN crm_supplier s ON s.id = r.supplier_id
                     WHERE r.id = ? LIMIT 1'
                );
                $st->execute([$refId]);
                $name = (string) ($st->fetchColumn() ?: '');
                break;
        }
    } catch (Throwable $e) {
        $name = '';
    }

    $cache[$key] = ['role' => $role, 'name' => trim($name)];

    return $cache[$key];
}

function inv_stock_move_document_no(PDO $pdo, string $refType, int $refId): string
{
    if ($refId < 1) {
        return '';
    }

    static $cache = [];

    $key = $refType . ':' . $refId;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $docNo = '';
    try {
        switch ($refType) {
            case 'sale_invoice':
                $st = $pdo->prepare('SELECT invoice_no FROM sal_invoice WHERE id = ? LIMIT 1');
                $st->execute([$refId]);
                $docNo = (string) ($st->fetchColumn() ?: '');
                break;
            case 'purchase_invoice':
                $st = $pdo->prepare('SELECT invoice_no FROM pur_invoice WHERE id = ? LIMIT 1');
                $st->execute([$refId]);
                $docNo = (string) ($st->fetchColumn() ?: '');
                break;
            case 'sale_return':
                $st = $pdo->prepare('SELECT return_no FROM sal_return WHERE id = ? LIMIT 1');
                $st->execute([$refId]);
                $docNo = (string) ($st->fetchColumn() ?: '');
                break;
            case 'purchase_return':
                $st = $pdo->prepare('SELECT return_no FROM pur_return WHERE id = ? LIMIT 1');
                $st->execute([$refId]);
                $docNo = (string) ($st->fetchColumn() ?: '');
                break;
            case 'item_opening':
                $docNo = 'افتتاحي';
                break;
            case 'stock_adjust_in':
            case 'stock_adjust_out':
            case 'stock_disposal':
            case 'warehouse_transfer_out':
            case 'warehouse_transfer_in':
                $st = $pdo->prepare('SELECT move_no FROM inv_wh_move WHERE id = ? LIMIT 1');
                $st->execute([$refId]);
                $docNo = (string) ($st->fetchColumn() ?: '');
                break;
            case 'stocktake_adjust_in':
            case 'stocktake_adjust_out':
                $st = $pdo->prepare('SELECT take_no FROM inv_stocktake_doc WHERE id = ? LIMIT 1');
                $st->execute([$refId]);
                $docNo = (string) ($st->fetchColumn() ?: '');
                break;
            default:
                $st = $pdo->prepare(
                    'SELECT note FROM inv_stock_move WHERE ref_type = ? AND ref_id = ? ORDER BY id ASC LIMIT 1'
                );
                $st->execute([$refType, $refId]);
                $note = trim((string) ($st->fetchColumn() ?: ''));
                if ($note !== '') {
                    $docNo = $note;
                } elseif ($refId > 0) {
                    $docNo = '#' . $refId;
                }
                break;
        }
    } catch (Throwable $e) {
        $docNo = $refId > 0 ? '#' . $refId : '';
    }

    $cache[$key] = $docNo;

    return $docNo;
}

/**
 * شرط SQL: إظهار حركات لها أثر مستودعي فعلي (مستند مؤكّد/مرحّل، أو رصيد افتتاحي).
 */
function inv_stock_move_ledger_ref_filter_sql(string $moveAlias = 'm'): string
{
    $m = preg_replace('/[^a-zA-Z0-9_]/', '', $moveAlias) ?: 'm';

    return "(
        {$m}.ref_type = 'item_opening'
        OR ({$m}.ref_type = 'sale_invoice' AND EXISTS (
            SELECT 1 FROM sal_invoice si
            WHERE si.id = {$m}.ref_id AND si.status = 'confirmed'
        ))
        OR ({$m}.ref_type = 'purchase_invoice' AND EXISTS (
            SELECT 1 FROM pur_invoice pi
            WHERE pi.id = {$m}.ref_id AND pi.status = 'confirmed'
        ))
        OR ({$m}.ref_type = 'sale_return' AND EXISTS (
            SELECT 1 FROM sal_return sr
            WHERE sr.id = {$m}.ref_id AND sr.status = 'confirmed'
        ))
        OR ({$m}.ref_type = 'purchase_return' AND EXISTS (
            SELECT 1 FROM pur_return pr
            WHERE pr.id = {$m}.ref_id AND pr.status = 'confirmed'
        ))
        OR {$m}.ref_type NOT IN ('sale_invoice', 'purchase_invoice', 'sale_return', 'purchase_return', 'item_opening')
    )";
}

/**
 * الرصيد المستودعي الحالي لمادة في مستودع (كل الحركات).
 */
function inv_item_stock_qty_on_hand(PDO $pdo, int $itemId, int $warehouseId): float
{
    return inv_stock_qty_on_hand($pdo, $warehouseId, $itemId);
}

/**
 * الرصيد المستودعي من حركات مرحّلة فقط (كشف حركات مادة).
 */
function inv_item_stock_ledger_qty_on_hand(PDO $pdo, int $itemId, int $warehouseId): float
{
    if ($itemId < 1 || $warehouseId < 1 || !inv_stock_move_has_table($pdo)) {
        return 0.0;
    }

    inv_stock_move_ensure_table($pdo);
    $filter = inv_stock_move_ledger_ref_filter_sql('m');
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(m.qty_delta), 0)
         FROM inv_stock_move m
         WHERE m.item_id = ? AND m.warehouse_id = ? AND {$filter}"
    );
    $st->execute([$itemId, $warehouseId]);

    return (float) $st->fetchColumn();
}

/** عرض تاريخ/وقت تسجيل الحركة في قاعدة البيانات (created_at). */
function inv_stock_move_format_created_at(string $createdAt): string
{
    $createdAt = trim($createdAt);
    if ($createdAt === '') {
        return '—';
    }

    $ts = strtotime($createdAt);
    if ($ts === false) {
        return $createdAt;
    }

    $datePart = date('d-m-Y', $ts);
    $timePart = date('H:i', $ts);

    return $datePart . "\u{202F}" . $timePart;
}

/**
 * سجل حركات المخزون لمادة في مستودع مع الرصيد التراكمي بعد كل حركة.
 *
 * @return list<array{
 *   move_id:int,
 *   item_sku:string,
 *   item_name:string,
 *   mov_type_label:string,
 *   move_at:string,
 *   move_at_display:string,
 *   invoice_date:string,
 *   invoice_date_display:string,
 *   document_no:string,
 *   qty_delta:float,
 *   unit_price_excl:?float,
 *   unit_price_excl_display:string,
 *   balance_after:float,
 *   ref_type:string,
 *   ref_id:int,
 *   open_url:?string
 * }>
 */
function inv_item_stock_ledger_lines(PDO $pdo, int $itemId, int $warehouseId): array
{
    if ($itemId < 1 || $warehouseId < 1) {
        return [];
    }

    inv_stock_move_ensure_table($pdo);
    if (!inv_stock_move_has_table($pdo)) {
        return [];
    }

    $refFilter = inv_stock_move_ledger_ref_filter_sql('m');
    $itemNoSql = inv_item_sql_material_number($pdo, 'it', true);
    $st = $pdo->prepare(
        "SELECT m.id AS move_id, m.move_date, m.created_at, m.qty_delta, m.ref_type, m.ref_id,
                {$itemNoSql} AS item_sku, it.name_ar AS item_name
         FROM inv_stock_move m
         INNER JOIN inv_item it ON it.id = m.item_id
         WHERE m.item_id = ? AND m.warehouse_id = ? AND {$refFilter}
         ORDER BY m.created_at ASC, m.id ASC"
    );
    $st->execute([$itemId, $warehouseId]);

    $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    usort(
        $raw,
        static function (array $a, array $b): int {
            $c = strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
            if ($c !== 0) {
                return $c;
            }

            return ((int) ($a['move_id'] ?? 0)) <=> ((int) ($b['move_id'] ?? 0));
        }
    );

    $priceResolver = new InvStockMoveUnitPriceResolver($pdo, $itemId);
    $priceResolver->preload($raw);

    $balance = 0.0;
    $out = [];
    foreach ($raw as $row) {
        $refType = (string) ($row['ref_type'] ?? '');
        $refId = (int) ($row['ref_id'] ?? 0);
        $qtyDelta = (float) ($row['qty_delta'] ?? 0);
        $balance = round($balance + $qtyDelta, 6);
        $unitPrice = $priceResolver->resolve($refType, $refId, $qtyDelta);

        $party = inv_stock_move_party($pdo, $refType, $refId);

        $out[] = [
            'move_id' => (int) ($row['move_id'] ?? 0),
            'item_sku' => (string) ($row['item_sku'] ?? ''),
            'item_name' => (string) ($row['item_name'] ?? ''),
            'mov_type_label' => inv_stock_move_type_label($refType),
            'move_at' => (string) ($row['created_at'] ?? ''),
            'move_at_display' => inv_stock_move_format_created_at((string) ($row['created_at'] ?? '')),
            'invoice_date' => (string) ($row['move_date'] ?? ''),
            'invoice_date_display' => ($invDate = (string) ($row['move_date'] ?? '')) !== ''
                ? format_date_dmY($invDate)
                : '—',
            'document_no' => inv_stock_move_document_no($pdo, $refType, $refId),
            'qty_delta' => $qtyDelta,
            'qty_display' => inv_item_stock_ledger_format_qty(abs($qtyDelta)),
            'unit_price_excl' => $unitPrice,
            'unit_price_excl_display' => inv_item_stock_ledger_format_unit_price($unitPrice, $pdo),
            'line_total' => inv_item_stock_ledger_line_total(abs($qtyDelta), $unitPrice),
            'line_total_display' => inv_item_stock_ledger_format_line_total(abs($qtyDelta), $unitPrice, $pdo),
            'balance_after' => $balance,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'party_role' => $party['role'],
            'party_name' => $party['name'],
            'open_url' => inv_stock_move_ref_url($refType, $refId),
        ];
    }

    return $out;
}
