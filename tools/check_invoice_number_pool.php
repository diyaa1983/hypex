<?php
declare(strict_types=1);

/**
 * فحص أرقام الفواتير المتاحة (doc_number_pool) والمستخدمة.
 * افتح من المتصفح: /manager/tools/check_invoice_number_pool.php
 */
header('Content-Type: text/plain; charset=utf-8');

require dirname(__DIR__) . '/includes/bootstrap.php';
require app_path('includes/doc_number_pool.php');

$pdo = db();
require_once app_path('includes/sql_migration.php');
sql_migration_run_file_once($pdo, 'database/migrations/182_clear_legacy_invoice_number_pool.sql');
doc_number_pool_ensure_table($pdo);

function table_exists(PDO $pdo, string $table): bool
{
    try {
        $tableSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $pdo->query("SELECT 1 FROM `{$tableSafe}` LIMIT 1");

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return list<int> */
function seq_numbers_for_year(array $rows, int $year, string $noKey = 'invoice_no'): array
{
    $suffix = '-' . $year;
    $suffixQ = preg_quote($suffix, '/');
    $nums = [];
    foreach ($rows as $row) {
        $no = (string) ($row[$noKey] ?? '');
        if (preg_match('/^(\d+)' . $suffixQ . '$/', $no, $m)) {
            $nums[] = (int) $m[1];
        }
    }
    sort($nums);

    return $nums;
}

/** @return list<int> */
function missing_sequences(array $usedSeq, int $maxSeq): array
{
    if ($maxSeq < 1) {
        return [];
    }
    $set = array_fill_keys($usedSeq, true);
    $missing = [];
    for ($i = 1; $i <= $maxSeq; $i++) {
        if (!isset($set[$i])) {
            $missing[] = $i;
        }
    }

    return $missing;
}

function print_section(string $title, array $rows, callable $formatter): void
{
    echo "\n=== {$title} ===\n";
    if ($rows === []) {
        echo "  (لا توجد)\n";

        return;
    }
    foreach ($rows as $row) {
        echo '  ' . $formatter($row) . "\n";
    }
}

$year = (int) date('Y');

print_section(
    'فواتير البيع — أرقام متاحة للإعادة (sal_invoice)',
    doc_number_pool_list($pdo, doc_number_pool_key_sal_invoice(), $year),
    static fn (array $r): string => sprintf('%s  year=%s  since=%s', $r['doc_no'], $r['doc_year'], $r['created_at'])
);

print_section(
    'فواتير الشراء — أرقام متاحة للإعادة (pur_invoice)',
    doc_number_pool_list($pdo, doc_number_pool_key_pur_invoice(), $year),
    static fn (array $r): string => sprintf('%s  year=%s  since=%s', $r['doc_no'], $r['doc_year'], $r['created_at'])
);

print_section(
    'كل الأرقام المتاحة في النظام (سندات + قيود + فواتير)',
    doc_number_pool_list($pdo),
    static fn (array $r): string => sprintf(
        '%s  key=%s  year=%s  since=%s',
        $r['doc_no'],
        $r['pool_key'],
        $r['doc_year'],
        $r['created_at']
    )
);

if (table_exists($pdo, 'sal_invoice')) {
    $salRows = $pdo->query(
        'SELECT invoice_no, invoice_date, status FROM sal_invoice ORDER BY invoice_no ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    print_section(
        'فواتير البيع — مستخدمة حالياً',
        $salRows,
        static fn (array $r): string => sprintf(
            '%s  date=%s  status=%s',
            $r['invoice_no'],
            $r['invoice_date'],
            $r['status']
        )
    );
    $salSeq = seq_numbers_for_year($salRows, $year);
    $salMax = $salSeq !== [] ? max($salSeq) : 0;
    $salMissing = missing_sequences($salSeq, $salMax);
    $salPooled = doc_number_pool_pooled_invoice_nos($pdo, doc_number_pool_key_sal_invoice(), $year);
    $salMissingNotPooled = array_values(array_filter(
        $salMissing,
        static fn (int $n): bool => !isset($salPooled[str_pad((string) $n, 3, '0', STR_PAD_LEFT) . '-' . $year])
    ));
    echo "\n=== فواتير البيع {$year} — فجوات غير معالجة (لا فاتورة ولا في المجموعة) ===\n";
    if ($salMissingNotPooled === []) {
        echo "  (لا فجوات — أو كل الفجوات في doc_number_pool)\n";
    } else {
        foreach ($salMissingNotPooled as $n) {
            echo '  ' . str_pad((string) $n, 3, '0', STR_PAD_LEFT) . '-' . $year . "\n";
        }
    }
    echo '  أعلى رقم مستخدم: ' . ($salMax > 0 ? str_pad((string) $salMax, 3, '0', STR_PAD_LEFT) . '-' . $year : '—') . "\n";
    echo '  الرقم التالي إن لم توجد فجوات/متاح: ' . str_pad((string) ($salMax + 1), 3, '0', STR_PAD_LEFT) . '-' . $year . "\n";
}

if (table_exists($pdo, 'pur_invoice')) {
    $purRows = $pdo->query(
        'SELECT invoice_no, invoice_date, status FROM pur_invoice ORDER BY invoice_no ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    print_section(
        'فواتير الشراء — مستخدمة حالياً',
        $purRows,
        static fn (array $r): string => sprintf(
            '%s  date=%s  status=%s',
            $r['invoice_no'],
            $r['invoice_date'],
            $r['status']
        )
    );
    $purSeq = seq_numbers_for_year($purRows, $year);
    $purMax = $purSeq !== [] ? max($purSeq) : 0;
    $purMissing = missing_sequences($purSeq, $purMax);
    echo "\n=== فواتير الشراء {$year} — فجوات في التسلسل ===\n";
    if ($purMissing === []) {
        echo "  (لا فجوات بين 1 و {$purMax})\n";
    } else {
        foreach ($purMissing as $n) {
            echo '  ' . str_pad((string) $n, 3, '0', STR_PAD_LEFT) . '-' . $year . "\n";
        }
    }
    echo '  أعلى رقم مستخدم: ' . ($purMax > 0 ? str_pad((string) $purMax, 3, '0', STR_PAD_LEFT) . '-' . $year : '—') . "\n";
}

echo "\n";
