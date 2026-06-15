<?php
declare(strict_types=1);

/** @param array<int, int> $out */
function crm_party_add_grouped_counts(PDO $pdo, array &$out, string $table, string $idColumn, string $whereExtra = ''): void
{
    try {
        $sql = 'SELECT ' . $idColumn . ' AS pid, COUNT(*) AS c FROM ' . $table;
        if ($whereExtra !== '') {
            $sql .= ' WHERE ' . $whereExtra;
        }
        $sql .= ' GROUP BY ' . $idColumn;
        foreach ($pdo->query($sql) as $row) {
            $pid = (int) ($row['pid'] ?? 0);
            if ($pid < 1) {
                continue;
            }
            $out[$pid] = ($out[$pid] ?? 0) + (int) ($row['c'] ?? 0);
        }
    } catch (Throwable $e) {
        // جدول غير موجود بعد
    }
}

/** @return array<int, int> customer_id => عدد الحركات المرتبطة */
function crm_customer_usage_counts(PDO $pdo): array
{
    $out = [];
    crm_party_add_grouped_counts($pdo, $out, 'sal_invoice', 'customer_id');
    crm_party_add_grouped_counts($pdo, $out, 'sal_return', 'customer_id');
    crm_party_add_grouped_counts($pdo, $out, 'sal_delivery', 'customer_id');
    crm_party_add_grouped_counts($pdo, $out, 'crm_customer_ledger', 'customer_id');
    crm_party_add_grouped_counts($pdo, $out, 'fin_voucher', 'party_id', "party_type = 'customer'");
    crm_party_add_grouped_counts($pdo, $out, 'fin_debit_note', 'party_id', "party_type = 'customer'");
    crm_party_add_grouped_counts($pdo, $out, 'fin_credit_note', 'party_id', "party_type = 'customer'");

    return $out;
}

/** @return array<int, int> supplier_id => عدد الحركات المرتبطة */
function crm_supplier_usage_counts(PDO $pdo): array
{
    $out = [];
    crm_party_add_grouped_counts($pdo, $out, 'pur_invoice', 'supplier_id');
    crm_party_add_grouped_counts($pdo, $out, 'pur_return', 'supplier_id');
    crm_party_add_grouped_counts($pdo, $out, 'crm_supplier_ledger', 'supplier_id');
    crm_party_add_grouped_counts($pdo, $out, 'fin_voucher', 'party_id', "party_type = 'supplier'");
    crm_party_add_grouped_counts($pdo, $out, 'fin_debit_note', 'party_id', "party_type = 'supplier'");
    crm_party_add_grouped_counts($pdo, $out, 'fin_credit_note', 'party_id', "party_type = 'supplier'");

    return $out;
}

/**
 * @return array{can_delete:bool, usage_count:int, message:string}
 */
function crm_customer_delete_check(PDO $pdo, int $customerId): array
{
    if ($customerId < 1) {
        return ['can_delete' => false, 'usage_count' => 0, 'message' => 'معرّف العميل غير صالح.'];
    }

    $st = $pdo->prepare('SELECT code, name_ar FROM crm_customer WHERE id = ? LIMIT 1');
    $st->execute([$customerId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['can_delete' => false, 'usage_count' => 0, 'message' => 'العميل غير موجود.'];
    }

    $label = trim((string) ($row['name_ar'] ?? ''));
    if ($label === '') {
        $label = (string) ($row['code'] ?? $customerId);
    }

    $usage = crm_customer_usage_counts($pdo);
    $count = (int) ($usage[$customerId] ?? 0);
    if ($count > 0) {
        return [
            'can_delete' => false,
            'usage_count' => $count,
            'message' => 'لا يمكن حذف العميل «' . $label . '»: مرتبط بـ ' . $count . ' حركة (فواتير، سندات، دفتر، …).',
        ];
    }

    return ['can_delete' => true, 'usage_count' => 0, 'message' => ''];
}

/**
 * @return array{can_delete:bool, usage_count:int, message:string}
 */
function crm_supplier_delete_check(PDO $pdo, int $supplierId): array
{
    if ($supplierId < 1) {
        return ['can_delete' => false, 'usage_count' => 0, 'message' => 'معرّف المورد غير صالح.'];
    }

    $st = $pdo->prepare('SELECT code, name_ar FROM crm_supplier WHERE id = ? LIMIT 1');
    $st->execute([$supplierId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['can_delete' => false, 'usage_count' => 0, 'message' => 'المورد غير موجود.'];
    }

    $label = trim((string) ($row['name_ar'] ?? ''));
    if ($label === '') {
        $label = (string) ($row['code'] ?? $supplierId);
    }

    $usage = crm_supplier_usage_counts($pdo);
    $count = (int) ($usage[$supplierId] ?? 0);
    if ($count > 0) {
        return [
            'can_delete' => false,
            'usage_count' => $count,
            'message' => 'لا يمكن حذف المورد «' . $label . '»: مرتبط بـ ' . $count . ' حركة (فواتير، سندات، دفتر، …).',
        ];
    }

    return ['can_delete' => true, 'usage_count' => 0, 'message' => ''];
}
