<?php
declare(strict_types=1);

require_once app_path('includes/oracle_pdo.php');

function oracle_customer_schema_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $st = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_customer' AND COLUMN_NAME = 'oracle_key'"
        );
        if ((int) $st->fetchColumn() === 0) {
            $pdo->exec(
                "ALTER TABLE crm_customer
                 ADD COLUMN oracle_key VARCHAR(80) NULL,
                 ADD UNIQUE KEY uq_crm_customer_oracle_key (oracle_key)"
            );
        }
    } catch (Throwable $e) {
        // ignore if concurrent
    }
}

/**
 * @param array<string, string> $columnMap field => oracle_column
 * @return array{inserted:int, updated:int, skipped:int, errors:list<string>}
 */
function oracle_sync_customers_to_mysql(
    PDO $mysql,
    array $oraConn,
    string $owner,
    string $table,
    array $columnMap
): array {
    oracle_customer_schema_ensure($mysql);

    $result = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
    $owner = strtoupper(trim($owner));
    $table = strtoupper(trim($table));

    $codeCol = strtoupper(trim((string) ($columnMap['code'] ?? '')));
    $nameCol = strtoupper(trim((string) ($columnMap['name_ar'] ?? '')));
    $keyCol = strtoupper(trim((string) ($columnMap['oracle_key'] ?? $columnMap['code'] ?? '')));

    if ($owner === '' || $table === '' || $nameCol === '' || $keyCol === '') {
        $result['errors'][] = 'يجب تحديد المالك والجدول وأعمدة name_ar و oracle_key (أو code).';

        return $result;
    }

    $cols = array_values(array_unique(array_filter([
        $keyCol,
        $codeCol,
        $nameCol,
        strtoupper(trim((string) ($columnMap['phone'] ?? ''))),
        strtoupper(trim((string) ($columnMap['email'] ?? ''))),
        strtoupper(trim((string) ($columnMap['tax_number'] ?? ''))),
        strtoupper(trim((string) ($columnMap['address_ar'] ?? ''))),
        strtoupper(trim((string) ($columnMap['is_active'] ?? ''))),
    ])));

    $quoted = [];
    foreach ($cols as $c) {
        $quoted[] = '"' . str_replace('"', '""', $c) . '"';
    }
    $sql = 'SELECT ' . implode(', ', $quoted)
        . ' FROM "' . str_replace('"', '""', $owner) . '"."' . str_replace('"', '""', $table) . '"';

    try {
        $rows = oracle_query_all($oraConn, $sql);
    } catch (Throwable $e) {
        $result['errors'][] = 'فشل قراءة Oracle: ' . $e->getMessage();

        return $result;
    }

    $cfg = oracle_config();
    $activeTrue = $cfg['customers']['active_true_values'] ?? ['1', 'Y', 'YES', 'ACTIVE', 'A'];
    $activeTrue = array_map('strtoupper', array_map('strval', $activeTrue));

    $sel = $mysql->prepare('SELECT id FROM crm_customer WHERE oracle_key = ? LIMIT 1');
    $selCode = $mysql->prepare('SELECT id FROM crm_customer WHERE code = ? LIMIT 1');
    $ins = $mysql->prepare(
        'INSERT INTO crm_customer (code, name_ar, phone, email, tax_number, address_ar, is_active, oracle_key)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $upd = $mysql->prepare(
        'UPDATE crm_customer
         SET code = ?, name_ar = ?, phone = ?, email = ?, tax_number = ?, address_ar = ?, is_active = ?, oracle_key = ?
         WHERE id = ?'
    );

    $usedCodes = [];

    foreach ($rows as $row) {
        $get = static function (array $r, string $col): string {
            if ($col === '') {
                return '';
            }
            $v = $r[$col] ?? $r[strtolower($col)] ?? $r[ucfirst(strtolower($col))] ?? '';
            if (is_object($v) && method_exists($v, 'load')) {
                $v = $v->load();
            }

            return trim((string) $v);
        };

        $oracleKey = $get($row, $keyCol);
        $name = $get($row, $nameCol);
        if ($oracleKey === '' || $name === '') {
            $result['skipped']++;
            continue;
        }

        $code = $codeCol !== '' ? $get($row, $codeCol) : $oracleKey;
        if ($code === '') {
            $code = $oracleKey;
        }
        // أكواد MySQL UNIQUE
        $baseCode = mb_substr($code, 0, 40);
        $codeFinal = $baseCode;
        $n = 1;
        while (isset($usedCodes[$codeFinal])) {
            $suffix = '-' . $n;
            $codeFinal = mb_substr($baseCode, 0, 40 - strlen($suffix)) . $suffix;
            $n++;
        }
        $usedCodes[$codeFinal] = true;

        $phone = $get($row, strtoupper(trim((string) ($columnMap['phone'] ?? ''))));
        $email = $get($row, strtoupper(trim((string) ($columnMap['email'] ?? ''))));
        $tax = $get($row, strtoupper(trim((string) ($columnMap['tax_number'] ?? ''))));
        $addr = $get($row, strtoupper(trim((string) ($columnMap['address_ar'] ?? ''))));
        $activeRaw = strtoupper($get($row, strtoupper(trim((string) ($columnMap['is_active'] ?? '')))));
        $isActive = 1;
        if ($activeRaw !== '') {
            $isActive = in_array($activeRaw, $activeTrue, true) ? 1 : 0;
        }

        try {
            $sel->execute([$oracleKey]);
            $id = (int) ($sel->fetchColumn() ?: 0);
            if ($id < 1) {
                $selCode->execute([$codeFinal]);
                $id = (int) ($selCode->fetchColumn() ?: 0);
            }

            if ($id > 0) {
                $upd->execute([
                    $codeFinal,
                    $name,
                    $phone !== '' ? $phone : null,
                    $email !== '' ? $email : null,
                    $tax !== '' ? $tax : null,
                    $addr !== '' ? $addr : null,
                    $isActive,
                    $oracleKey,
                    $id,
                ]);
                $result['updated']++;
            } else {
                $ins->execute([
                    $codeFinal,
                    $name,
                    $phone !== '' ? $phone : null,
                    $email !== '' ? $email : null,
                    $tax !== '' ? $tax : null,
                    $addr !== '' ? $addr : null,
                    $isActive,
                    $oracleKey,
                ]);
                $result['inserted']++;
            }
        } catch (Throwable $e) {
            $result['errors'][] = 'مفتاح ' . $oracleKey . ': ' . $e->getMessage();
            if (count($result['errors']) > 30) {
                $result['errors'][] = '… توقفت بعد 30 خطأ';
                break;
            }
        }
    }

    return $result;
}
