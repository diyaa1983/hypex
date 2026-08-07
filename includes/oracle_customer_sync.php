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

    $cfg = oracle_config();
    // فقط العملاء الذين يبدأ رقمهم بـ 112 (قابل للتغيير عبر code_prefix)
    $codePrefix = trim((string) ($cfg['customers']['code_prefix'] ?? '112'));
    if ($codePrefix === '') {
        $codePrefix = '112'; // إجباري في هذا التكامل
    }
    // العمود المستخدم لفلترة رقم العميل
    $filterCol = $codeCol !== '' ? $codeCol : $keyCol;
    $filterQuoted = '"' . str_replace('"', '""', $filterCol) . '"';

    $sql = 'SELECT ' . implode(', ', $quoted)
        . ' FROM "' . str_replace('"', '""', $owner) . '"."' . str_replace('"', '""', $table) . '"';
    $binds = [];
    // LTRIM يزيل فراغات TO_CHAR على NUMBER
    $sql .= ' WHERE LTRIM(TO_CHAR(' . $filterQuoted . ')) LIKE :code_prefix';
    $binds['code_prefix'] = $codePrefix . '%';

    try {
        $rows = oracle_query_all($oraConn, $sql, $binds);
    } catch (Throwable $e) {
        try {
            $sql2 = 'SELECT ' . implode(', ', $quoted)
                . ' FROM "' . str_replace('"', '""', $owner) . '"."' . str_replace('"', '""', $table) . '"'
                . ' WHERE TO_CHAR(' . $filterQuoted . ') LIKE :code_prefix';
            $rows = oracle_query_all($oraConn, $sql2, $binds);
        } catch (Throwable $e2) {
            try {
                // VARCHAR / CHAR
                $sql3 = 'SELECT ' . implode(', ', $quoted)
                    . ' FROM "' . str_replace('"', '""', $owner) . '"."' . str_replace('"', '""', $table) . '"'
                    . ' WHERE ' . $filterQuoted . ' LIKE :code_prefix';
                $rows = oracle_query_all($oraConn, $sql3, $binds);
            } catch (Throwable $e3) {
                $result['errors'][] = 'فشل قراءة Oracle (فلتر ' . $codePrefix . '): ' . $e->getMessage();

                return $result;
            }
        }
    }

    $result['code_prefix'] = $codePrefix;
    $result['oracle_rows'] = count($rows);

    $activeTrue = $cfg['customers']['active_true_values'] ?? ['1', 'Y', 'YES', 'ACTIVE', 'A'];
    $activeTrue = array_map('strtoupper', array_map('strval', $activeTrue));

    $normCode = static function (string $s): string {
        $s = trim($s);
        // "112000.0" من بعض أنواع NUMBER
        if (preg_match('/^(\d+)\.0+$/', $s, $m)) {
            return $m[1];
        }

        return $s;
    };

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

        $oracleKey = $normCode($get($row, $keyCol));
        $name = $get($row, $nameCol);
        if ($oracleKey === '' || $name === '') {
            $result['skipped']++;
            continue;
        }

        $code = $codeCol !== '' ? $normCode($get($row, $codeCol)) : $oracleKey;
        if ($code === '') {
            $code = $oracleKey;
        }

        // فقط البادئة 112 (أو code_prefix) — رفض 212 وغيرها
        $checkNum = $code !== '' ? $code : $oracleKey;
        if (!str_starts_with($checkNum, $codePrefix) && !str_starts_with($oracleKey, $codePrefix)) {
            $result['skipped']++;
            continue;
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

    // حذف من Hypex كل عميل رمزه لا يبدأ بالبادئة (112) — المطلوب: القائمة = 112 فقط
    $result['cleaned'] = 0;
    $result['deleted_non_prefix'] = 0;
    $result['kept_with_usage'] = 0;
    try {
        $like = $codePrefix . '%';
        require_once app_path('includes/crm_party_delete.php');

        $st = $mysql->prepare(
            'SELECT id, code, oracle_key FROM crm_customer WHERE code NOT LIKE ? OR (
                oracle_key IS NOT NULL AND oracle_key <> \'\' AND oracle_key NOT LIKE ?
            )'
        );
        $st->execute([$like, $like]);
        $toRemove = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $usage = crm_customer_usage_counts($mysql);
        $delRep = null;
        try {
            $delRep = $mysql->prepare('DELETE FROM crm_customer_sales_rep WHERE customer_id = ?');
        } catch (Throwable $e) {
            $delRep = null;
        }
        $delCust = $mysql->prepare('DELETE FROM crm_customer WHERE id = ?');
        $disCust = $mysql->prepare(
            'UPDATE crm_customer SET is_active = 0, oracle_key = NULL WHERE id = ?'
        );

        foreach ($toRemove as $row) {
            $cid = (int) ($row['id'] ?? 0);
            if ($cid < 1) {
                continue;
            }
            $used = (int) ($usage[$cid] ?? 0);
            if ($used > 0) {
                // لا يُحذف بسبب حركات — عطّل فقط
                try {
                    $disCust->execute([$cid]);
                    $result['kept_with_usage']++;
                    $result['cleaned']++;
                } catch (Throwable $e) {
                    // ignore
                }
                continue;
            }
            try {
                if ($delRep !== null) {
                    $delRep->execute([$cid]);
                }
            } catch (Throwable $e) {
                // ignore
            }
            try {
                $delCust->execute([$cid]);
                if ($delCust->rowCount() > 0) {
                    $result['deleted_non_prefix']++;
                }
            } catch (Throwable $e) {
                try {
                    $disCust->execute([$cid]);
                    $result['cleaned']++;
                } catch (Throwable $e2) {
                    $result['errors'][] = 'تعذر حذف عميل #' . $cid . ': ' . $e->getMessage();
                }
            }
        }
    } catch (Throwable $e) {
        $result['errors'][] = 'حذف غير ' . $codePrefix . ': ' . $e->getMessage();
    }

    return $result;
}

/**
 * حفظ تعيين جدول/أعمدة العملاء في oracle.local.php
 *
 * @param array<string, string> $columns
 */
function oracle_customers_save_mapping(string $owner, string $table, array $columns): void
{
    $path = app_path('config' . DIRECTORY_SEPARATOR . 'oracle.local.php');
    $cfg = [];
    if (is_file($path)) {
        clearstatcache(true, $path);
        $prev = include $path;
        if (is_array($prev)) {
            $cfg = $prev;
        }
    }
    if ($cfg === []) {
        throw new RuntimeException('لا يمكن حفظ التعيين: أنشئ oracle.local.php أولاً.');
    }
    $cleanCols = [];
    foreach ($columns as $k => $v) {
        $v = strtoupper(trim((string) $v));
        if ($v !== '') {
            $cleanCols[(string) $k] = $v;
        }
    }
    $cfg['customers'] = array_merge(
        is_array($cfg['customers'] ?? null) ? $cfg['customers'] : [],
        [
            'owner' => strtoupper(trim($owner)),
            'table' => strtoupper(trim($table)),
            'code_prefix' => trim((string) (
                (is_array($cfg['customers'] ?? null) ? ($cfg['customers']['code_prefix'] ?? '112') : '112')
            )) ?: '112',
            'columns' => $cleanCols,
            'active_true_values' => $cfg['customers']['active_true_values']
                ?? ['1', 'Y', 'YES', 'ACTIVE', 'A'],
            'last_synced_at' => date('Y-m-d H:i:s'),
        ]
    );
    $export = var_export($cfg, true);
    $php = "<?php\ndeclare(strict_types=1);\n\n// مولَّد من تكامل Oracle — لا ترفع إلى Git.\nreturn " . $export . ";\n";
    if (@file_put_contents($path, $php) === false) {
        throw new RuntimeException('تعذّر حفظ التعيين في: ' . $path);
    }
    oracle_config(true);
}

/** @return array{owner:string, table:string, columns:array<string,string>, last_synced_at:string}|null */
function oracle_customers_saved_mapping(): ?array
{
    $cfg = oracle_config();
    $c = is_array($cfg['customers'] ?? null) ? $cfg['customers'] : [];
    $owner = strtoupper(trim((string) ($c['owner'] ?? '')));
    $table = strtoupper(trim((string) ($c['table'] ?? '')));
    $cols = is_array($c['columns'] ?? null) ? $c['columns'] : [];
    if ($owner === '' || $table === '' || $cols === []) {
        return null;
    }
    $norm = [];
    foreach ($cols as $k => $v) {
        $v = strtoupper(trim((string) $v));
        if ($v !== '') {
            $norm[(string) $k] = $v;
        }
    }

    return [
        'owner' => $owner,
        'table' => $table,
        'columns' => $norm,
        'last_synced_at' => (string) ($c['last_synced_at'] ?? ''),
    ];
}

/**
 * مزامنة باستخدام التعيين المحفوظ.
 *
 * @return array{inserted:int, updated:int, skipped:int, errors:list<string>}
 */
function oracle_sync_customers_from_saved_config(PDO $mysql): array
{
    $mapCfg = oracle_customers_saved_mapping();
    if ($mapCfg === null) {
        return [
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => ['لم يُضبط جدول العملاء في التكامل بعد. افتح شاشة تكامل Oracle وكرّر المزامنة الأولى مع تعيين الأعمدة.'],
        ];
    }
    $conn = oracle_connect();
    if (!$conn['ok']) {
        return [
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [(string) $conn['message']],
        ];
    }
    $result = oracle_sync_customers_to_mysql(
        $mysql,
        $conn,
        $mapCfg['owner'],
        $mapCfg['table'],
        $mapCfg['columns']
    );
    if (($result['inserted'] + $result['updated']) > 0 || $result['errors'] === []) {
        try {
            oracle_customers_save_mapping($mapCfg['owner'], $mapCfg['table'], $mapCfg['columns']);
        } catch (Throwable $e) {
            // ignore
        }
    }

    return $result;
}
