<?php
declare(strict_types=1);

require_once app_path('includes/acc_journal.php');

function acc_journal_party_has_columns(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo->query('SELECT party_type FROM acc_journal_line LIMIT 1');
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function acc_journal_party_ensure_schema(PDO $pdo): bool
{
    if (acc_journal_party_has_columns($pdo)) {
        acc_journal_party_ensure_ledger_enums($pdo);

        return true;
    }
    require_once app_path('includes/sql_migration.php');
    sql_migration_run_file($pdo, 'database/migrations/159_acc_journal_line_party.sql');
    acc_journal_party_ensure_ledger_enums($pdo);

    return acc_journal_party_has_columns($pdo);
}

function acc_journal_party_ensure_ledger_enums(PDO $pdo): void
{
    require_once app_path('includes/crm_customer_ledger.php');
    require_once app_path('includes/crm_supplier_ledger.php');
    crm_ledger_ensure_journal_voucher_txn($pdo);
    crm_supplier_ledger_ensure_journal_voucher_txn($pdo);
}

/** @return array{ar:int, ap:int} */
function acc_journal_party_ar_ap_ids(PDO $pdo): array
{
    require_once app_path('includes/acc_gl.php');
    acc_gl_ensure_schema($pdo);
    $settings = acc_gl_load_settings($pdo);

    return [
        'ar' => (int) acc_gl_account_id($settings, 'ar_customers'),
        'ap' => (int) acc_gl_account_id($settings, 'ap_suppliers'),
    ];
}

function acc_journal_party_role_for_account(int $accountId, int $arId, int $apId): ?string
{
    if ($arId > 0 && $accountId === $arId) {
        return 'customer';
    }
    if ($apId > 0 && $accountId === $apId) {
        return 'supplier';
    }

    return null;
}

function acc_journal_party_display_name(PDO $pdo, string $partyType, int $partyId): string
{
    if ($partyId < 1) {
        return '';
    }
    if ($partyType === 'customer') {
        $st = $pdo->prepare('SELECT name_ar FROM crm_customer WHERE id = ? LIMIT 1');
    } elseif ($partyType === 'supplier') {
        $st = $pdo->prepare('SELECT name_ar FROM crm_supplier WHERE id = ? LIMIT 1');
    } else {
        return '';
    }
    $st->execute([$partyId]);
    $name = $st->fetchColumn();

    return $name !== false ? trim((string) $name) : '';
}

/**
 * @param list<array<string, mixed>> $lines
 * @return list<array<string, mixed>>
 */
function acc_journal_party_normalize_lines(PDO $pdo, array $lines): array
{
    if (!acc_journal_party_ensure_schema($pdo)) {
        return $lines;
    }
    $ids = acc_journal_party_ar_ap_ids($pdo);
    $out = [];
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $accountId = (int) ($ln['account_id'] ?? 0);
        $partyType = strtolower(trim((string) ($ln['party_type'] ?? '')));
        $partyId = (int) ($ln['party_id'] ?? 0);
        $role = acc_journal_party_role_for_account($accountId, $ids['ar'], $ids['ap']);
        if ($role === null) {
            $ln['party_type'] = null;
            $ln['party_id'] = null;
        } else {
            if ($partyId < 1 || $partyType !== $role) {
                $label = $role === 'customer' ? 'العميل' : 'المورد';
                throw new RuntimeException('اختر ' . $label . ' للسطر على حساب الذمم (مدين/دائن).');
            }
            $ln['party_type'] = $role;
            $ln['party_id'] = $partyId;
        }
        $out[] = $ln;
    }

    return $out;
}

function acc_journal_party_ledger_sync(PDO $pdo, int $journalId, bool $post): void
{
    if ($journalId < 1 || !acc_journal_party_ensure_schema($pdo)) {
        return;
    }

    require_once app_path('includes/crm_customer_ledger.php');
    require_once app_path('includes/crm_supplier_ledger.php');

    crm_ledger_delete_journal_voucher_by_journal($pdo, $journalId);
    crm_supplier_ledger_delete_journal_voucher_by_journal($pdo, $journalId);

    if (!$post) {
        return;
    }

    $loaded = acc_journal_load_entry($pdo, $journalId);
    if (!$loaded || (string) ($loaded['header']['status'] ?? '') !== 'posted') {
        return;
    }

    // تجيير الشيك يُسجّل في crm_supplier_ledger كنوع check_endorse (لا سند قيد)
    if ((string) ($loaded['header']['ref_type'] ?? '') === 'fin_check_endorse') {
        return;
    }

    $entryNo = (string) ($loaded['header']['entry_no'] ?? '');
    $entryDate = (string) ($loaded['header']['entry_date'] ?? '');
    $headerDesc = trim((string) ($loaded['header']['description_ar'] ?? ''));

    foreach ($loaded['lines'] as $ln) {
        $lineId = (int) ($ln['id'] ?? 0);
        $partyType = (string) ($ln['party_type'] ?? '');
        $partyId = (int) ($ln['party_id'] ?? 0);
        if ($lineId < 1 || $partyId < 1 || !in_array($partyType, ['customer', 'supplier'], true)) {
            continue;
        }

        $debit = (float) ($ln['debit'] ?? 0);
        $credit = (float) ($ln['credit'] ?? 0);
        if ($debit <= 0 && $credit <= 0) {
            continue;
        }

        $memo = trim((string) ($ln['memo'] ?? ''));
        if ($memo === '') {
            $memo = $headerDesc;
        }
        $partyName = (string) ($ln['party_name'] ?? acc_journal_party_display_name($pdo, $partyType, $partyId));
        if ($partyName !== '') {
            $tag = ($partyType === 'customer' ? 'عميل: ' : 'مورد: ') . $partyName;
            if ($memo === '') {
                $memo = $tag;
            } elseif (mb_stripos($memo, $partyName) === false) {
                $memo .= ' — ' . $tag;
            }
        }
        $refNo = $entryNo !== '' ? $entryNo : ('JV-' . $journalId);

        if ($partyType === 'customer') {
            crm_ledger_post_journal_voucher_line(
                $pdo,
                $lineId,
                $partyId,
                $entryDate,
                $refNo,
                $debit,
                $credit,
                $memo
            );
        } else {
            crm_supplier_ledger_post_journal_voucher_line(
                $pdo,
                $lineId,
                $partyId,
                $entryDate,
                $refNo,
                $debit,
                $credit,
                $memo
            );
        }
    }
}
