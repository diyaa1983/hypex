<?php
declare(strict_types=1);

require_once app_path('includes/fin_voucher_schema.php');
require_once app_path('includes/fin_voucher.php');
require_once app_path('includes/acc_journal.php');

/**
 * @return list<array<string, mixed>>
 */
function fin_cancelled_vouchers_report_fetch(
    PDO $pdo,
    ?string $fromIso = null,
    ?string $toIso = null,
    string $docKind = 'all'
): array {
    fin_voucher_ensure_schema($pdo);
    acc_journal_ensure_schema($pdo);
    fin_voucher_ensure_cancel_columns($pdo);

    $rows = [];

    if ($docKind === 'all' || $docKind === 'receipt' || $docKind === 'payment') {
        $types = $docKind === 'all' ? ['receipt', 'payment'] : [$docKind];
        foreach ($types as $type) {
            $sql = "SELECT v.id, v.voucher_type AS doc_kind, v.voucher_no AS doc_no, v.voucher_date AS doc_date,
                           v.amount, v.description, v.pay_method, v.party_type, v.party_id,
                           v.cancelled_at, v.posted_at, v.created_at,
                           u.full_name_ar AS cancelled_by_name
                    FROM fin_voucher v
                    LEFT JOIN sys_user u ON u.id = v.cancelled_by
                    WHERE v.voucher_type = ? AND v.is_cancelled = 1";
            $params = [$type];
            if ($fromIso !== null && $fromIso !== '') {
                $sql .= ' AND v.voucher_date >= ?';
                $params[] = $fromIso;
            }
            if ($toIso !== null && $toIso !== '') {
                $sql .= ' AND v.voucher_date <= ?';
                $params[] = $toIso;
            }
            $sql .= ' ORDER BY v.cancelled_at DESC, v.id DESC';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $r['doc_kind_label'] = ($r['doc_kind'] ?? '') === 'payment' ? 'سند صرف' : 'سند قبض';
                $r['party_name'] = fin_voucher_party_name(
                    $pdo,
                    (string) ($r['party_type'] ?? ''),
                    (int) ($r['party_id'] ?? 0)
                );
                $r['pay_method_label'] = fin_voucher_pay_method_label((string) ($r['pay_method'] ?? 'cash'));
                $rows[] = $r;
            }
        }
    }

    if ($docKind === 'all' || $docKind === 'journal') {
        $sql = "SELECT e.id, 'journal' AS doc_kind, e.entry_no AS doc_no, e.entry_date AS doc_date,
                       0 AS amount, e.description_ar AS description, NULL AS pay_method,
                       NULL AS party_type, NULL AS party_id, e.created_at AS cancelled_at,
                       NULL AS posted_at, e.created_at, NULL AS cancelled_by_name
                FROM acc_journal_entry e
                WHERE e.status = 'cancelled' AND e.source = 'manual'";
        $params = [];
        if ($fromIso !== null && $fromIso !== '') {
            $sql .= ' AND e.entry_date >= ?';
            $params[] = $fromIso;
        }
        if ($toIso !== null && $toIso !== '') {
            $sql .= ' AND e.entry_date <= ?';
            $params[] = $toIso;
        }
        $sql .= ' ORDER BY e.entry_date DESC, e.id DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $r['doc_kind_label'] = 'سند قيد';
            $r['party_name'] = '';
            $r['pay_method_label'] = '';
            $loaded = acc_journal_load_entry($pdo, (int) ($r['id'] ?? 0));
            if ($loaded) {
                $sum = 0.0;
                foreach ($loaded['lines'] as $ln) {
                    $sum += (float) ($ln['debit'] ?? 0);
                }
                $r['amount'] = $sum;
            }
            $rows[] = $r;
        }
    }

    usort($rows, static function (array $a, array $b): int {
        $da = (string) ($a['cancelled_at'] ?? $a['doc_date'] ?? '');
        $db = (string) ($b['cancelled_at'] ?? $b['doc_date'] ?? '');

        return strcmp($db, $da);
    });

    return $rows;
}

function fin_cancelled_voucher_view_url(string $docKind, int $id): string
{
    $route = match ($docKind) {
        'payment' => 'cash_payment',
        'journal' => 'journal_voucher',
        default => 'cash_receipt',
    };

    return app_url('index.php?r=' . $route . '&id=' . $id);
}
