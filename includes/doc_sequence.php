<?php
declare(strict_types=1);

/**
 * رقم مستند تسلسلي: 001-2026 (ثلاثة أرقام على الأقل + سنة تاريخ المستند).
 * التسلسل يبدأ من 1 كل سنة.
 */
function doc_seq_generate_next_no(
    PDO $pdo,
    string $table,
    string $noColumn,
    string $dateIso,
    string $extraWhere = '',
    array $extraParams = []
): string {
    $ts = strtotime($dateIso);
    $year = $ts !== false ? (int) date('Y', $ts) : (int) date('Y');
    $suffix = '-' . $year;

    $tableSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $colSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $noColumn);
    if ($tableSafe === '' || $colSafe === '') {
        throw new InvalidArgumentException('جدول أو عمود الرقم غير صالح.');
    }

    $where = '`' . $colSafe . '` LIKE ?';
    $params = ['%' . $suffix];
    if ($extraWhere !== '') {
        $where = '(' . $extraWhere . ') AND ' . $where;
        $params = array_merge($extraParams, $params);
    }

    $st = $pdo->prepare("SELECT `{$colSafe}` FROM `{$tableSafe}` WHERE {$where} FOR UPDATE");
    $st->execute($params);

    $maxSeq = 0;
    $suffixQuoted = preg_quote($suffix, '/');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $no) {
        $no = (string) $no;
        if (preg_match('/^(\d+)' . $suffixQuoted . '$/', $no, $m)) {
            $maxSeq = max($maxSeq, (int) $m[1]);
        }
    }

    return str_pad((string) ($maxSeq + 1), 3, '0', STR_PAD_LEFT) . $suffix;
}
