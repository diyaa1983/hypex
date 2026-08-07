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
 * تحميل أسماء العملاء من جدول GL: ACC_NUM → ACC_DESC
 * يجرّب عدة owners إن لزم.
 *
 * @param array{errors?:list<string>} $result
 * @return array<string, string>  رقم الحساب => الوصف/الاسم
 */
function oracle_load_gl_account_names(
    array $oraConn,
    string $glOwner,
    string $glTable,
    string $glAccNum,
    string $glAccDesc,
    string $codePrefix,
    array &$result
): array {
    $glTable = strtoupper(trim($glTable));
    $glAccNum = strtoupper(trim($glAccNum));
    $glAccDesc = strtoupper(trim($glAccDesc));
    if ($glTable === '' || $glAccNum === '' || $glAccDesc === '') {
        $result['errors'][] = 'تعيين GLACTMF ناقص (table/acc_num/acc_desc).';

        return [];
    }

    $ownersTry = array_values(array_unique(array_filter([
        strtoupper(trim($glOwner)),
        'ACCINV',
        'ACCT',
        'GL',
        'ACC',
        'MAS',
        'TAQWA',
    ])));

    $numQ = '"' . str_replace('"', '""', $glAccNum) . '"';
    $descQ = '"' . str_replace('"', '""', $glAccDesc) . '"';
    $pfx = str_replace("'", "''", $codePrefix);
    $binds = ['code_prefix' => $codePrefix . '%'];

    $lastErr = '';
    $rows = [];
    foreach ($ownersTry as $ow) {
        $from = ' FROM "' . str_replace('"', '""', $ow) . '"."' . str_replace('"', '""', $glTable) . '"';
        $attempts = [
            'SELECT ' . $numQ . ' AS ACC_NUM_V, ' . $descQ . ' AS ACC_DESC_V' . $from
                . ' WHERE LTRIM(TO_CHAR(' . $numQ . ')) LIKE :code_prefix',
            'SELECT ' . $numQ . ' AS ACC_NUM_V, ' . $descQ . ' AS ACC_DESC_V' . $from
                . ' WHERE TO_CHAR(' . $numQ . ') LIKE :code_prefix',
            'SELECT ' . $numQ . ' AS ACC_NUM_V, ' . $descQ . ' AS ACC_DESC_V' . $from
                . ' WHERE ' . $numQ . ' LIKE :code_prefix',
            'SELECT ' . $numQ . ' AS ACC_NUM_V, ' . $descQ . ' AS ACC_DESC_V' . $from
                . " WHERE TRIM(TO_CHAR(" . $numQ . ")) LIKE '" . $pfx . "%'",
            'SELECT ' . $numQ . ' AS ACC_NUM_V, ' . $descQ . ' AS ACC_DESC_V' . $from,
        ];
        foreach ($attempts as $sql) {
            try {
                $useBinds = (str_contains($sql, ':code_prefix')) ? $binds : [];
                $rows = oracle_query_all($oraConn, $sql, $useBinds);
                $result['gl_owner_used'] = $ow;
                $lastErr = '';
                break 2;
            } catch (Throwable $e) {
                $lastErr = $e->getMessage();
                $rows = [];
            }
        }
    }

    if ($lastErr !== '' && $rows === []) {
        $result['errors'][] = 'فشل قراءة GLACTMF: ' . $lastErr;

        return [];
    }

    $map = [];
    foreach ($rows as $r) {
        $num = $r['ACC_NUM_V']
            ?? $r['acc_num_v']
            ?? $r[$glAccNum]
            ?? $r[strtolower($glAccNum)]
            ?? '';
        $desc = $r['ACC_DESC_V']
            ?? $r['acc_desc_v']
            ?? $r[$glAccDesc]
            ?? $r[strtolower($glAccDesc)]
            ?? '';
        if (is_object($num) && method_exists($num, 'load')) {
            $num = $num->load();
        }
        if (is_object($desc) && method_exists($desc, 'load')) {
            $desc = $desc->load();
        }
        $num = trim((string) $num);
        $desc = trim((string) $desc);
        if (preg_match('/^(\d+)\.0+$/', $num, $m)) {
            $num = $m[1];
        }
        if ($num === '' || $desc === '') {
            continue;
        }
        if ($codePrefix !== '' && !str_starts_with($num, $codePrefix)) {
            continue;
        }
        $map[$num] = $desc;
    }

    return $map;
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

    $cfg = oracle_config();
    // اسم العميل من جدول GL (GLACTMF.ACC_DESC) عبر ACC_NUM
    $glCfg = is_array($cfg['customers']['name_from_gl'] ?? null)
        ? $cfg['customers']['name_from_gl']
        : [];
    // مفعّل افتراضياً إن وُجدت إعدادات الجدول، أو بالافتراضي GLACTMF
    $glEnabled = !array_key_exists('enabled', $glCfg) || !empty($glCfg['enabled']);
    $glOwner = strtoupper(trim((string) ($glCfg['owner'] ?? $owner)));
    $glTable = strtoupper(trim((string) ($glCfg['table'] ?? 'GLACTMF')));
    $glAccNum = strtoupper(trim((string) ($glCfg['acc_num'] ?? 'ACC_NUM')));
    $glAccDesc = strtoupper(trim((string) ($glCfg['acc_desc'] ?? 'ACC_DESC')));

    if ($owner === '' || $table === '' || $keyCol === '') {
        $result['errors'][] = 'يجب تحديد المالك والجدول وعمود oracle_key/code (رقم العميل).';

        return $result;
    }
    if (!$glEnabled && $nameCol === '') {
        $result['errors'][] = 'يجب تعيين name_ar من CUSTOMER أو تفعيل name_from_gl (GLACTMF).';

        return $result;
    }

    // فقط الأعمدة من CUSTOMER (الرقم أساساً)
    $cols = array_values(array_unique(array_filter([
        $keyCol,
        $codeCol,
        // الاسم من CUSTOMER اختياري — الاسم الرسمي من GLACTMF
        $glEnabled ? '' : $nameCol,
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

    // فقط العملاء الذين يبدأ رقمهم بـ 112
    $codePrefix = trim((string) ($cfg['customers']['code_prefix'] ?? '112'));
    if ($codePrefix === '') {
        $codePrefix = '112';
    }
    $filterCol = $codeCol !== '' ? $codeCol : $keyCol;
    $filterQuoted = '"' . str_replace('"', '""', $filterCol) . '"';
    $from = ' FROM "' . str_replace('"', '""', $owner) . '"."' . str_replace('"', '""', $table) . '"';
    $selectList = implode(', ', $quoted);

    $binds = ['code_prefix' => $codePrefix . '%'];
    $sqlAttempts = [
        'SELECT ' . $selectList . $from
            . ' WHERE LTRIM(TO_CHAR(' . $filterQuoted . ')) LIKE :code_prefix',
        'SELECT ' . $selectList . $from
            . ' WHERE TO_CHAR(' . $filterQuoted . ') LIKE :code_prefix',
        'SELECT ' . $selectList . $from
            . ' WHERE ' . $filterQuoted . ' LIKE :code_prefix',
        'SELECT ' . $selectList . $from
            . " WHERE TRIM(TO_CHAR(" . $filterQuoted . ")) LIKE '" . str_replace("'", "''", $codePrefix) . "%'",
        'SELECT ' . $selectList . $from,
    ];

    $rows = [];
    $lastErr = '';
    $sqlUsed = '';
    foreach ($sqlAttempts as $trySql) {
        try {
            $useBinds = (str_contains($trySql, ':code_prefix')) ? $binds : [];
            $rows = oracle_query_all($oraConn, $trySql, $useBinds);
            $sqlUsed = $trySql;
            break;
        } catch (Throwable $e) {
            $lastErr = $e->getMessage();
            $rows = [];
        }
    }
    if ($sqlUsed === '') {
        $result['errors'][] = 'فشل قراءة CUSTOMER: ' . ($lastErr !== '' ? $lastErr : 'خطأ غير معروف');

        return $result;
    }

    $result['code_prefix'] = $codePrefix;
    $result['oracle_rows_raw'] = count($rows);
    $result['sql_mode'] = str_contains($sqlUsed, 'WHERE') ? 'filtered' : 'full_php_filter';
    $result['name_source'] = $glEnabled ? ($glOwner . '.' . $glTable . '.' . $glAccDesc) : ('CUSTOMER.' . $nameCol);

    $normCode = static function (string $s): string {
        $s = trim($s);
        if (preg_match('/^(\d+)\.0+$/', $s, $m)) {
            return $m[1];
        }

        return $s;
    };

    $getCell = static function (array $r, string $col): string {
        if ($col === '') {
            return '';
        }
        $v = $r[$col]
            ?? $r[strtolower($col)]
            ?? $r[strtoupper($col)]
            ?? $r[ucfirst(strtolower($col))]
            ?? '';
        if (is_object($v) && method_exists($v, 'load')) {
            $v = $v->load();
        }

        return trim((string) $v);
    };

    // خريطة ACC_NUM → ACC_DESC من GLACTMF (اسم العميل)
    $glNames = [];
    $result['gl_rows'] = 0;
    $result['skipped_no_gl'] = 0;
    if ($glEnabled) {
        $glNames = oracle_load_gl_account_names(
            $oraConn,
            $glOwner,
            $glTable,
            $glAccNum,
            $glAccDesc,
            $codePrefix,
            $result
        );
        $result['gl_rows'] = count($glNames);
        if ($glNames === [] && empty($result['errors'])) {
            $result['errors'][] = 'لم تُقرأ أسماء من '
                . $glOwner . '.' . $glTable
                . ' (ACC_NUM/ACC_DESC). تحقق من اسم الجدول/المالك في Toad.';
        }
    }

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
    // ربط مفتاح الحساب إن وُجد العمود
    $updAcc = null;
    try {
        if (function_exists('oracle_customer_account_schema_ensure')) {
            oracle_customer_account_schema_ensure($mysql);
        }
        $mysql->query('SELECT oracle_acc_key FROM crm_customer LIMIT 1');
        $updAcc = $mysql->prepare('UPDATE crm_customer SET oracle_acc_key = ? WHERE id = ?');
    } catch (Throwable $e) {
        $updAcc = null;
    }

    $usedCodes = [];

    foreach ($rows as $row) {
        $oracleKey = $normCode($getCell($row, $keyCol));
        if ($oracleKey === '') {
            $result['skipped']++;
            continue;
        }

        $code = $codeCol !== '' ? $normCode($getCell($row, $codeCol)) : $oracleKey;
        if ($code === '') {
            $code = $oracleKey;
        }

        // فقط البادئة 112
        $checkNum = $code !== '' ? $code : $oracleKey;
        if (!str_starts_with($checkNum, $codePrefix) && !str_starts_with($oracleKey, $codePrefix)) {
            $result['skipped']++;
            continue;
        }

        // الاسم: من GLACTMF.ACC_DESC إذا وُجد ACC_NUM = رقم العميل — وإلا تخطَّ
        $name = '';
        $accKey = '';
        if ($glEnabled) {
            $name = $glNames[$oracleKey] ?? $glNames[$code] ?? '';
            if ($name === '') {
                $result['skipped']++;
                $result['skipped_no_gl']++;
                continue;
            }
            $accKey = $oracleKey;
        } else {
            $name = $getCell($row, $nameCol);
            if ($name === '') {
                $name = 'عميل ' . $code;
            }
        }

        $baseCode = mb_substr($code, 0, 40);
        $codeFinal = $baseCode;
        $n = 1;
        while (isset($usedCodes[$codeFinal])) {
            $suffix = '-' . $n;
            $codeFinal = mb_substr($baseCode, 0, 40 - strlen($suffix)) . $suffix;
            $n++;
        }
        $usedCodes[$codeFinal] = true;

        $phone = $getCell($row, strtoupper(trim((string) ($columnMap['phone'] ?? ''))));
        $email = $getCell($row, strtoupper(trim((string) ($columnMap['email'] ?? ''))));
        $tax = $getCell($row, strtoupper(trim((string) ($columnMap['tax_number'] ?? ''))));
        $addr = $getCell($row, strtoupper(trim((string) ($columnMap['address_ar'] ?? ''))));
        $activeRaw = strtoupper($getCell($row, strtoupper(trim((string) ($columnMap['is_active'] ?? '')))));
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
                $id = (int) $mysql->lastInsertId();
                $result['inserted']++;
            }
            if ($updAcc !== null && $accKey !== '' && $id > 0) {
                try {
                    $updAcc->execute([$accKey, $id]);
                } catch (Throwable $e) {
                    // ignore
                }
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
