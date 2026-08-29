<?php
declare(strict_types=1);

/**
 * رقم مستند تسلسلي: YYYY-N (مثل 2026-1، 2026-2).
 * التسلسل يبدأ من 1 كل سنة. يدعم الصيغ القديمة عند حساب الحد الأقصى.
 */
function doc_seq_generate_next_no(
    PDO $pdo,
    string $table,
    string $noColumn,
    string $dateIso,
    string $extraWhere = '',
    array $extraParams = [],
    ?string $poolKey = null
): string {
    $ts = strtotime($dateIso);
    $year = $ts !== false ? (int) date('Y', $ts) : (int) date('Y');
    $yearStr = (string) $year;
    $oldSuffix = '-' . $yearStr;

    if ($poolKey !== null && $poolKey !== '') {
        require_once app_path('includes/doc_number_pool.php');
        $pooled = doc_number_pool_take($pdo, $poolKey, $year, 1);
        if ($pooled !== []) {
            return (string) $pooled[0];
        }
    }

    $tableSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $colSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $noColumn);
    if ($tableSafe === '' || $colSafe === '') {
        throw new InvalidArgumentException('جدول أو عمود الرقم غير صالح.');
    }

    $where = '(`' . $colSafe . '` LIKE ? OR `' . $colSafe . '` LIKE ? OR `' . $colSafe . '` LIKE ?)';
    $params = [$yearStr . '-%', $yearStr . '%', '%' . $oldSuffix];
    if ($extraWhere !== '') {
        $where = '(' . $extraWhere . ') AND ' . $where;
        $params = array_merge($extraParams, $params);
    }

    $st = $pdo->prepare("SELECT `{$colSafe}` FROM `{$tableSafe}` WHERE {$where} FOR UPDATE");
    $st->execute($params);

    $maxSeq = 0;
    $yearQ = preg_quote($yearStr, '/');
    $oldSuffixQuoted = preg_quote($oldSuffix, '/');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $no) {
        $no = (string) $no;
        if (preg_match('/^' . $yearQ . '-(\\d+)$/', $no, $m)) {
            $maxSeq = max($maxSeq, (int) $m[1]);
            continue;
        }
        if (preg_match('/^' . $yearQ . '(\\d+)$/', $no, $m)) {
            $maxSeq = max($maxSeq, (int) $m[1]);
            continue;
        }
        if (preg_match('/^(\\d+)' . $oldSuffixQuoted . '$/', $no, $m)) {
            $maxSeq = max($maxSeq, (int) $m[1]);
        }
    }

    return $yearStr . '-' . (string) ($maxSeq + 1);
}
