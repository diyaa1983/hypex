<?php
declare(strict_types=1);

/**
 * مزامنة Oracle المستمرة (عملاء + حسابات العملاء).
 * تُستدعى من API / CLI / شاشة العملاء — ليست إعداداً يدوياً كل مرة.
 */

require_once app_path('includes/oracle_pdo.php');
require_once app_path('includes/oracle_customer_sync.php');
require_once app_path('includes/oracle_item_sync.php');

/**
 * أعمدة CRM لحساب Oracle المربوط بالعميل.
 */
function oracle_customer_account_schema_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $st = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'crm_customer'
               AND COLUMN_NAME = 'oracle_acc_key'"
        );
        if ((int) $st->fetchColumn() === 0) {
            $pdo->exec(
                "ALTER TABLE crm_customer
                 ADD COLUMN oracle_acc_key VARCHAR(80) NULL,
                 ADD KEY idx_crm_customer_oracle_acc (oracle_acc_key)"
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * @return array{ok:bool, token:string}
 */
function oracle_sync_token_info(): array
{
    $cfg = oracle_config();
    $token = trim((string) ($cfg['sync_token'] ?? ''));

    return ['ok' => $token !== '', 'token' => $token];
}

function oracle_sync_token_valid(?string $provided): bool
{
    $info = oracle_sync_token_info();
    if (!$info['ok'] || $provided === null || $provided === '') {
        return false;
    }

    return hash_equals($info['token'], $provided);
}

/**
 * مزامنة حسابات العملاء من Oracle → تحديث oracle_acc_key على crm_customer
 * يطابق رقم الحساب مع oracle_key أو code للعميل.
 *
 * @return array{updated:int, skipped:int, errors:list<string>}
 */
function oracle_sync_customer_accounts_to_mysql(PDO $mysql, array $oraConn, string $owner, string $table, array $columnMap): array
{
    oracle_customer_account_schema_ensure($mysql);
    $result = ['updated' => 0, 'skipped' => 0, 'errors' => []];
    $owner = strtoupper(trim($owner));
    $table = strtoupper(trim($table));
    $accCol = strtoupper(trim((string) ($columnMap['acc_num'] ?? $columnMap['oracle_key'] ?? '')));
    $nameCol = strtoupper(trim((string) ($columnMap['name_ar'] ?? $columnMap['acc_name'] ?? '')));
    $linkCol = strtoupper(trim((string) ($columnMap['customer_key'] ?? $columnMap['cus_num'] ?? $accCol)));

    if ($owner === '' || $table === '' || $accCol === '') {
        $result['errors'][] = 'تعيين حسابات العملاء ناقص (owner/table/acc_num).';

        return $result;
    }

    $cols = array_values(array_unique(array_filter([$accCol, $linkCol, $nameCol])));
    $quoted = [];
    foreach ($cols as $c) {
        $quoted[] = '"' . str_replace('"', '""', $c) . '"';
    }
    $sql = 'SELECT ' . implode(', ', $quoted)
        . ' FROM "' . str_replace('"', '""', $owner) . '"."' . str_replace('"', '""', $table) . '"';

    try {
        $rows = oracle_query_all($oraConn, $sql);
    } catch (Throwable $e) {
        $result['errors'][] = 'فشل قراءة حسابات Oracle: ' . $e->getMessage();

        return $result;
    }

    $updAny = $mysql->prepare(
        'UPDATE crm_customer SET oracle_acc_key = ? WHERE oracle_key = ? OR code = ?'
    );

    foreach ($rows as $row) {
        $get = static function (array $r, string $col): string {
            if ($col === '') {
                return '';
            }
            $v = $r[$col] ?? $r[strtolower($col)] ?? '';
            if (is_object($v) && method_exists($v, 'load')) {
                $v = $v->load();
            }

            return trim((string) $v);
        };
        $acc = $get($row, $accCol);
        $link = $linkCol !== '' ? $get($row, $linkCol) : $acc;
        if ($acc === '' || $link === '') {
            $result['skipped']++;
            continue;
        }
        try {
            $updAny->execute([$acc, $link, $link]);
            $n = $updAny->rowCount();
            if ($n > 0) {
                $result['updated'] += $n;
            } else {
                $result['skipped']++;
            }
        } catch (Throwable $e) {
            $result['errors'][] = 'حساب ' . $acc . ': ' . $e->getMessage();
            if (count($result['errors']) > 25) {
                break;
            }
        }
    }

    return $result;
}

/**
 * تشغيل مزامنة مستمرة حسب الإعداد المحفوظ.
 *
 * @param list<string> $entities  customers|accounts|all
 * @return array<string, mixed>
 */
function oracle_run_continuous_sync(PDO $mysql, array $entities = ['customers', 'accounts']): array
{
    $started = microtime(true);
    $entities = array_map('strtolower', $entities);
    if (in_array('all', $entities, true)) {
        $entities = ['customers', 'item_groups', 'items'];
    }
    // مرادفات
    $normalized = [];
    foreach ($entities as $e) {
        if ($e === 'categories' || $e === 'groups') {
            $e = 'item_groups';
        }
        if ($e === 'materials' || $e === 'products') {
            $e = 'items';
        }
        $normalized[] = $e;
    }
    $entities = array_values(array_unique($normalized));

    $out = [
        'ok' => true,
        'at' => date('Y-m-d H:i:s'),
        'entities' => $entities,
        'customers' => null,
        'accounts' => null,
        'item_groups' => null,
        'items' => null,
        'errors' => [],
        'elapsed_ms' => 0,
    ];

    if (!oracle_is_enabled()) {
        $out['ok'] = false;
        $out['errors'][] = oracle_config_status_message();

        return $out;
    }

    $conn = oracle_connect();
    if (!$conn['ok']) {
        $out['ok'] = false;
        $out['errors'][] = (string) $conn['message'];

        return $out;
    }

    $cfg = oracle_config();

    if (in_array('customers', $entities, true)) {
        $mapCfg = oracle_customers_saved_mapping();
        // تعيين افتراضي شائع لنظامكم إن لم يُحفظ بعد
        if ($mapCfg === null) {
            $cust = is_array($cfg['customers'] ?? null) ? $cfg['customers'] : [];
            $owner = strtoupper(trim((string) ($cust['owner'] ?? 'ACCINV')));
            $table = strtoupper(trim((string) ($cust['table'] ?? 'CUSTOMER')));
            $cols = is_array($cust['columns'] ?? null) ? $cust['columns'] : [];
            if ($cols === [] && $owner !== '' && $table !== '') {
                $cols = [
                    'oracle_key' => 'CUS_NUM',
                    'code' => 'CUS_NUM',
                    'name_ar' => 'CUSTOMER',
                ];
            }
            if ($owner !== '' && $table !== '' && $cols !== []) {
                $mapCfg = [
                    'owner' => $owner,
                    'table' => $table,
                    'columns' => $cols,
                    'last_synced_at' => '',
                ];
            }
        }
        if ($mapCfg === null) {
            $out['customers'] = [
                'inserted' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => ['لم يُضبط تعيين العملاء. احفظ المزامنة مرة من الشاشة أو عبّئ customers في oracle.local.php'],
            ];
            $out['ok'] = false;
        } else {
            $cres = oracle_sync_customers_to_mysql(
                $mysql,
                $conn,
                $mapCfg['owner'],
                $mapCfg['table'],
                $mapCfg['columns']
            );
            $out['customers'] = $cres;
            if ($cres['errors'] !== []) {
                $out['ok'] = false;
                $out['errors'] = array_merge($out['errors'], $cres['errors']);
            } else {
                try {
                    oracle_customers_save_mapping($mapCfg['owner'], $mapCfg['table'], $mapCfg['columns']);
                } catch (Throwable $e) {
                    // ignore
                }
            }
        }
    }

    if (in_array('accounts', $entities, true)) {
        $acc = is_array($cfg['customer_accounts'] ?? null) ? $cfg['customer_accounts'] : [];
        $owner = strtoupper(trim((string) ($acc['owner'] ?? '')));
        $table = strtoupper(trim((string) ($acc['table'] ?? '')));
        $cols = is_array($acc['columns'] ?? null) ? $acc['columns'] : [];
        if ($owner === '' || $table === '' || $cols === []) {
            $out['accounts'] = [
                'updated' => 0,
                'skipped' => 0,
                'errors' => ['customer_accounts غير مضبوط أو الجدول غير موجود — تُتخطى'],
            ];
        } else {
            $ares = oracle_sync_customer_accounts_to_mysql($mysql, $conn, $owner, $table, $cols);
            $out['accounts'] = $ares;
            if ($ares['errors'] !== []) {
                $out['ok'] = false;
                $out['errors'] = array_merge($out['errors'], $ares['errors']);
            }
            try {
                oracle_customer_accounts_save_mapping($owner, $table, $cols);
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    // مجموعات المواد أولاً ثم المواد (للربط category_id)
    if (in_array('item_groups', $entities, true)) {
        $map = oracle_cfg_entity_map('item_groups');
        if ($map === null) {
            $out['item_groups'] = [
                'inserted' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => ['item_groups غير مضبوط في oracle.local.php'],
            ];
            $out['ok'] = false;
        } else {
            $gres = oracle_sync_item_categories_to_mysql(
                $mysql,
                $conn,
                $map['owner'],
                $map['table'],
                $map['columns']
            );
            $out['item_groups'] = $gres;
            if ($gres['errors'] !== []) {
                $out['ok'] = false;
                $out['errors'] = array_merge($out['errors'], $gres['errors']);
            }
        }
    }

    if (in_array('items', $entities, true)) {
        $map = oracle_cfg_entity_map('items');
        if ($map === null) {
            $out['items'] = [
                'inserted' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => ['items غير مضبوط في oracle.local.php'],
            ];
            $out['ok'] = false;
        } else {
            $ires = oracle_sync_items_to_mysql(
                $mysql,
                $conn,
                $map['owner'],
                $map['table'],
                $map['columns']
            );
            $out['items'] = $ires;
            if ($ires['errors'] !== []) {
                $out['ok'] = false;
                $out['errors'] = array_merge($out['errors'], $ires['errors']);
            }
        }
    }

    $out['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);

    return $out;
}

/**
 * @param array<string, string> $columns
 */
function oracle_customer_accounts_save_mapping(string $owner, string $table, array $columns): void
{
    $path = app_path('config' . DIRECTORY_SEPARATOR . 'oracle.local.php');
    if (!is_file($path)) {
        return;
    }
    $cfg = include $path;
    if (!is_array($cfg)) {
        return;
    }
    $clean = [];
    foreach ($columns as $k => $v) {
        $v = strtoupper(trim((string) $v));
        if ($v !== '') {
            $clean[(string) $k] = $v;
        }
    }
    $cfg['customer_accounts'] = [
        'owner' => strtoupper(trim($owner)),
        'table' => strtoupper(trim($table)),
        'columns' => $clean,
        'last_synced_at' => date('Y-m-d H:i:s'),
    ];
    $php = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($cfg, true) . ";\n";
    @file_put_contents($path, $php);
    oracle_config(true);
}
