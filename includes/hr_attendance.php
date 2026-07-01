<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');

function hr_attendance_ensure_schema(PDO $pdo): void
{
    hr_employee_ensure_schema($pdo);

    try {
        $pdo->query('SELECT id FROM hr_att_punch LIMIT 1');
        return;
    } catch (Throwable $e) {
        if (
            strpos($e->getMessage(), "doesn't exist") === false
            && strpos($e->getMessage(), 'no such table') === false
            && strpos($e->getMessage(), 'Base table or view not found') === false
        ) {
            return;
        }
    }

    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/194_hr_attendance.sql');
    } catch (Throwable $e) {
        // fallback minimal tables if migration file fails
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS hr_att_config (
                    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                    mdb_path VARCHAR(500) NOT NULL,
                    last_sync_at DATETIME NULL,
                    last_punch_time DATETIME NULL,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS hr_att_employee_map (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    zk_user_id INT NOT NULL,
                    badge_number VARCHAR(24) NULL,
                    employee_id INT UNSIGNED NOT NULL,
                    zk_name VARCHAR(80) NULL,
                    UNIQUE KEY uk_hr_att_map_zk_user (zk_user_id),
                    UNIQUE KEY uk_hr_att_map_employee (employee_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS hr_att_punch (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    employee_id INT UNSIGNED NULL,
                    zk_user_id INT NOT NULL,
                    badge_number VARCHAR(24) NULL,
                    zk_name VARCHAR(80) NULL,
                    punch_time DATETIME NOT NULL,
                    punch_type VARCHAR(4) NULL,
                    verify_code SMALLINT NULL,
                    sensor_id VARCHAR(12) NULL,
                    source_key VARCHAR(64) NOT NULL,
                    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_hr_att_punch_source (source_key),
                    KEY idx_hr_att_punch_time (punch_time)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Throwable $e2) {
            // ignored
        }
    }
}

/** @return array{mdb_path:string,last_sync_at:?string,last_punch_time:?string} */
function hr_attendance_load_config(PDO $pdo): array
{
    hr_attendance_ensure_schema($pdo);
    $row = $pdo->query('SELECT mdb_path, last_sync_at, last_punch_time FROM hr_att_config WHERE id = 1 LIMIT 1')
        ->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'mdb_path' => 'C:\\Program Files (x86)\\ZKTeco\\att2000.mdb',
            'last_sync_at' => null,
            'last_punch_time' => null,
        ];
    }

    return [
        'mdb_path' => (string) ($row['mdb_path'] ?? ''),
        'last_sync_at' => $row['last_sync_at'] !== null ? (string) $row['last_sync_at'] : null,
        'last_punch_time' => $row['last_punch_time'] !== null ? (string) $row['last_punch_time'] : null,
    ];
}

function hr_attendance_save_config(PDO $pdo, string $mdbPath): void
{
    hr_attendance_ensure_schema($pdo);
    $mdbPath = trim($mdbPath);
    if ($mdbPath === '') {
        throw new RuntimeException('أدخل مسار ملف att2000.mdb.');
    }
    if (!is_file($mdbPath)) {
        throw new RuntimeException('ملف قاعدة البصمة غير موجود: ' . $mdbPath);
    }

    $st = $pdo->prepare(
        'INSERT INTO hr_att_config (id, mdb_path) VALUES (1, ?)
         ON DUPLICATE KEY UPDATE mdb_path = VALUES(mdb_path)'
    );
    $st->execute([$mdbPath]);
}

function hr_attendance_pdo_odbc_available(): bool
{
    return extension_loaded('pdo_odbc');
}

function hr_attendance_com_available(): bool
{
    return class_exists('COM', false);
}

function hr_attendance_normalize_mdb_path(string $mdbPath): string
{
    return trim(str_replace('/', '\\', $mdbPath));
}

function hr_attendance_assert_mdb_readable(string $mdbPath): string
{
    $mdbPath = hr_attendance_normalize_mdb_path($mdbPath);
    if (!is_file($mdbPath)) {
        throw new RuntimeException('ملف قاعدة البصمة غير موجود: ' . $mdbPath);
    }
    if (!is_readable($mdbPath)) {
        throw new RuntimeException('لا يمكن قراءة ملف قاعدة البصمة (صلاحيات): ' . $mdbPath);
    }

    return $mdbPath;
}

function hr_attendance_access_date_literal(string $mysqlDatetime): string
{
    $ts = strtotime($mysqlDatetime);
    if ($ts === false) {
        return '#1900-01-01#';
    }

    return '#' . date('Y-m-d H:i:s', $ts) . '#';
}

/** @return mixed */
function hr_attendance_mdb_com_field_value($val)
{
    if ($val === null) {
        return null;
    }
    if (is_object($val)) {
        if ($val instanceof DateTimeInterface) {
            return $val->format('Y-m-d H:i:s');
        }
        if (method_exists($val, 'format')) {
            return $val->format('Y-m-d H:i:s');
        }

        return (string) $val;
    }

    return $val;
}

function hr_attendance_mdb_cache_file(): string
{
    $dir = app_path('data/zk_cache');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir . DIRECTORY_SEPARATOR . 'att2000_sync.mdb';
}

function hr_attendance_mdb_is_locked_error(string $message): bool
{
    $m = strtolower($message);

    return str_contains($m, 'already in use')
        || str_contains($m, 'file is locked')
        || str_contains($m, 'could not use')
        || str_contains($m, 'locked');
}

/** @return list<string> */
function hr_attendance_mdb_oledb_connection_strings(string $mdbPath): array
{
    return [
        'Provider=Microsoft.ACE.OLEDB.12.0;Data Source=' . $mdbPath . ';Mode=Share Deny None;Read Only=True;Persist Security Info=False;',
        'Provider=Microsoft.Jet.OLEDB.4.0;Data Source=' . $mdbPath . ';Mode=Share Deny None;Read Only=True;Jet OLEDB:Database Locking Mode=1;Persist Security Info=False;',
        'Provider=Microsoft.ACE.OLEDB.12.0;Data Source=' . $mdbPath . ';Persist Security Info=False;',
        'Provider=Microsoft.Jet.OLEDB.4.0;Data Source=' . $mdbPath . ';Persist Security Info=False;',
    ];
}

function hr_attendance_mdb_snapshot_copy(string $sourcePath): string
{
    $dest = hr_attendance_mdb_cache_file();
    if (!@copy($sourcePath, $dest)) {
        throw new RuntimeException(
            'ملف att2000.mdb مقفول من برنامج ZKT ولم نتمكن من نسخه تلقائياً. '
            . 'أغلق Attendance Management ثم أعد المحاولة، أو انسخ الملف يدوياً إلى: ' . $dest
        );
    }

    return $dest;
}

function hr_attendance_mdb_com_open_path(string $mdbPath): COM
{
    $errors = [];
    foreach (hr_attendance_mdb_oledb_connection_strings($mdbPath) as $cs) {
        try {
            $conn = new COM('ADODB.Connection');
            $conn->Open($cs);

            return $conn;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    throw new RuntimeException(implode(' | ', array_slice($errors, 0, 2)));
}

function hr_attendance_mdb_com_open(string $mdbPath): COM
{
    if (!hr_attendance_com_available()) {
        throw new RuntimeException(
            'امتداد COM غير متاح. فعّل extension=com_dotnet في php.ini (عادةً مفعّل على Windows).'
        );
    }

    $mdbPath = hr_attendance_assert_mdb_readable($mdbPath);
    $errors = [];

    try {
        return hr_attendance_mdb_com_open_path($mdbPath);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        if (hr_attendance_mdb_is_locked_error($e->getMessage())) {
            try {
                $snapshot = hr_attendance_mdb_snapshot_copy($mdbPath);

                return hr_attendance_mdb_com_open_path($snapshot);
            } catch (Throwable $e2) {
                $errors[] = $e2->getMessage();
            }
        }
    }

    $hint = 'أغلق برنامج Attendance Management أثناء المزامنة، أو انسخ att2000.mdb إلى '
        . hr_attendance_mdb_cache_file()
        . ' وحدّث المسار. إذا ظهر Provider cannot be found ثبّت Microsoft Access Database Engine '
        . 'بنفس نوعية PHP (32 أو 64 بت من phpinfo).';

    throw new RuntimeException(
        'تعذر فتح att2000.mdb. ' . $hint . ' — ' . implode(' | ', array_slice($errors, 0, 2))
    );
}

/**
 * @return list<array<string,mixed>>
 */
function hr_attendance_mdb_com_fetch_all(COM $conn, string $sql): array
{
    $rows = [];
    $rs = $conn->Execute($sql);
    if (!$rs) {
        return [];
    }
    while (!$rs->EOF) {
        $row = [];
        $fieldCount = (int) $rs->Fields->Count;
        for ($i = 0; $i < $fieldCount; $i++) {
            $field = $rs->Fields($i);
            $name = (string) $field->Name;
            $row[$name] = hr_attendance_mdb_com_field_value($field->Value);
        }
        $rows[] = $row;
        $rs->MoveNext();
    }

    return $rows;
}

function hr_attendance_mdb_com_scalar(COM $conn, string $sql): int
{
    $rs = $conn->Execute($sql);
    if (!$rs || $rs->EOF) {
        return 0;
    }

    return (int) hr_attendance_mdb_com_field_value($rs->Fields(0)->Value);
}

function hr_attendance_mdb_connect(string $mdbPath): PDO
{
    if (!hr_attendance_pdo_odbc_available()) {
        throw new RuntimeException(
            'امتداد PHP pdo_odbc غير مفعّل. افتح php.ini وفَعّل extension=pdo_odbc ثم أعد تشغيل Apache.'
        );
    }
    $mdbPath = hr_attendance_assert_mdb_readable($mdbPath);

    $errors = [];
    $dsns = [
        'odbc:Driver={Microsoft Access Driver (*.mdb, *.accdb)};Dbq=%s;ReadOnly=1;',
        'odbc:Driver={Microsoft Access Driver (*.mdb, *.accdb)};Dbq=%s;',
        'odbc:Driver={Microsoft Access Driver (*.mdb)};Dbq=%s;',
    ];

    $tryPaths = static function (array $paths) use ($dsns, &$errors): ?PDO {
        foreach ($paths as $path) {
            foreach ($dsns as $pattern) {
                try {
                    $mdb = new PDO(sprintf($pattern, $path), '', '');
                    $mdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                    return $mdb;
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }

        return null;
    };

    $pdo = $tryPaths([$mdbPath]);
    if ($pdo instanceof PDO) {
        hr_attendance_mdb_set_last_via_snapshot(false);

        return $pdo;
    }

    try {
        $snapshot = hr_attendance_mdb_snapshot_copy($mdbPath);
        $pdo = $tryPaths([$snapshot]);
        if ($pdo instanceof PDO) {
            hr_attendance_mdb_set_last_via_snapshot(true);

            return $pdo;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    throw new RuntimeException(
        'تعذر فتح att2000.mdb عبر ODBC. أغلق برنامج Attendance Management ثم أعد المحاولة. '
        . 'ثبّت Microsoft Access Database Engine 2016 (64-bit) إن لزم. '
        . implode(' | ', array_slice($errors, 0, 2))
    );
}

function hr_attendance_mdb_set_last_via_snapshot(bool $viaSnapshot): void
{
    $GLOBALS['hr_att_mdb_via_snapshot'] = $viaSnapshot;
}

function hr_attendance_mdb_last_via_snapshot(): bool
{
    return !empty($GLOBALS['hr_att_mdb_via_snapshot']);
}

/**
 * @return list<array<string,mixed>>
 */
function hr_attendance_mdb_fetch_punches_since(string $mdbPath, string $since): array
{
    $mdbPath = hr_attendance_assert_mdb_readable($mdbPath);
    $since = trim($since) !== '' ? $since : '1900-01-01 00:00:00';

    if (hr_attendance_pdo_odbc_available()) {
        $mdb = hr_attendance_mdb_connect($mdbPath);
        $sql = 'SELECT c.USERID, c.CHECKTIME, c.CHECKTYPE, c.VERIFYCODE, c.SENSORID,
                       u.BADGENUMBER, u.NAME
                FROM CHECKINOUT c
                LEFT JOIN USERINFO u ON u.USERID = c.USERID
                WHERE c.CHECKTIME > ?
                ORDER BY c.CHECKTIME ASC';
        $st = $mdb->prepare($sql);
        $st->execute([$since]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $conn = hr_attendance_mdb_com_open($mdbPath);
    $sinceLit = hr_attendance_access_date_literal($since);
    $sql = 'SELECT c.USERID, c.CHECKTIME, c.CHECKTYPE, c.VERIFYCODE, c.SENSORID,
                   u.BADGENUMBER, u.NAME
            FROM CHECKINOUT AS c
            LEFT JOIN USERINFO AS u ON u.USERID = c.USERID
            WHERE c.CHECKTIME > ' . $sinceLit . '
            ORDER BY c.CHECKTIME ASC';

    return hr_attendance_mdb_com_fetch_all($conn, $sql);
}

/** @return array{checkinout_count:int,userinfo_count:int,driver:string} */
function hr_attendance_mdb_stats(string $mdbPath): array
{
    $mdbPath = hr_attendance_assert_mdb_readable($mdbPath);

    if (hr_attendance_pdo_odbc_available()) {
        $mdb = hr_attendance_mdb_connect($mdbPath);
        $driver = 'PDO ODBC';
        if (hr_attendance_mdb_last_via_snapshot()) {
            $driver .= ' (نسخة مؤقتة)';
        }

        return [
            'checkinout_count' => (int) $mdb->query('SELECT COUNT(*) FROM CHECKINOUT')->fetchColumn(),
            'userinfo_count' => (int) $mdb->query('SELECT COUNT(*) FROM USERINFO')->fetchColumn(),
            'driver' => $driver,
        ];
    }

    $conn = hr_attendance_mdb_com_open($mdbPath);
    $provider = 'OLEDB';
    try {
        if (stripos((string) $conn->ConnectionString, 'ACE.OLEDB') !== false) {
            $provider = 'OLEDB ACE';
        } elseif (stripos((string) $conn->ConnectionString, 'Jet.OLEDB') !== false) {
            $provider = 'OLEDB Jet';
        }
    } catch (Throwable $e) {
        // ignore
    }

    return [
        'checkinout_count' => hr_attendance_mdb_com_scalar($conn, 'SELECT COUNT(*) FROM CHECKINOUT'),
        'userinfo_count' => hr_attendance_mdb_com_scalar($conn, 'SELECT COUNT(*) FROM USERINFO'),
        'driver' => $provider,
    ];
}

/** @return array{BADGENUMBER?:string,NAME?:string}|null */
function hr_attendance_mdb_fetch_userinfo(string $mdbPath, int $zkUserId): ?array
{
    if ($zkUserId < 1) {
        return null;
    }
    $mdbPath = hr_attendance_assert_mdb_readable($mdbPath);

    if (hr_attendance_pdo_odbc_available()) {
        try {
            $mdb = hr_attendance_mdb_connect($mdbPath);
            $st = $mdb->prepare('SELECT BADGENUMBER, NAME FROM USERINFO WHERE USERID = ?');
            $st->execute([$zkUserId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;
        } catch (Throwable $e) {
            if (!hr_attendance_com_available()) {
                throw $e;
            }
        }
    }

    $conn = hr_attendance_mdb_com_open($mdbPath);
    $sql = 'SELECT BADGENUMBER, NAME FROM USERINFO WHERE USERID = ' . $zkUserId;
    $rows = hr_attendance_mdb_com_fetch_all($conn, $sql);

    return $rows[0] ?? null;
}

/** @return array{ok:bool,message:string,checkinout_count:int,userinfo_count:int} */
function hr_attendance_test_mdb(string $mdbPath): array
{
    try {
        $stats = hr_attendance_mdb_stats($mdbPath);

        return [
            'ok' => true,
            'message' => 'الاتصال ناجح (' . $stats['driver'] . ') — سجلات حضور: '
                . $stats['checkinout_count'] . '، موظفين في البصمة: ' . $stats['userinfo_count'],
            'checkinout_count' => $stats['checkinout_count'],
            'userinfo_count' => $stats['userinfo_count'],
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => $e->getMessage(),
            'checkinout_count' => 0,
            'userinfo_count' => 0,
        ];
    }
}

function hr_attendance_normalize_badge(string $badge): string
{
    $badge = trim($badge);
    if ($badge === '') {
        return '';
    }
    if (preg_match('/^\d+$/', $badge)) {
        $trimmed = ltrim($badge, '0');
        return $trimmed !== '' ? $trimmed : '0';
    }

    return $badge;
}

function hr_attendance_find_employee_id(PDO $pdo, int $zkUserId, string $badge): ?int
{
    if ($zkUserId > 0) {
        $st = $pdo->prepare('SELECT employee_id FROM hr_att_employee_map WHERE zk_user_id = ? LIMIT 1');
        $st->execute([$zkUserId]);
        $mapped = $st->fetchColumn();
        if ($mapped !== false && (int) $mapped > 0) {
            return (int) $mapped;
        }
    }

    $badge = trim($badge);
    if ($badge === '') {
        return null;
    }

    $candidates = array_values(array_unique(array_filter([
        $badge,
        hr_attendance_normalize_badge($badge),
        str_pad(hr_attendance_normalize_badge($badge), strlen($badge), '0', STR_PAD_LEFT),
    ], static fn ($v) => $v !== '')));

    foreach ($candidates as $code) {
        $st = $pdo->prepare(
            'SELECT id FROM hr_employee WHERE TRIM(emp_code) = ? AND is_active = 1 LIMIT 1'
        );
        $st->execute([$code]);
        $id = $st->fetchColumn();
        if ($id !== false && (int) $id > 0) {
            return (int) $id;
        }
    }

    return null;
}

function hr_attendance_upsert_map(
    PDO $pdo,
    int $zkUserId,
    int $employeeId,
    string $badge,
    string $zkName
): void {
    if ($zkUserId < 1 || $employeeId < 1) {
        return;
    }
    $st = $pdo->prepare(
        'INSERT INTO hr_att_employee_map (zk_user_id, badge_number, employee_id, zk_name)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            badge_number = VALUES(badge_number),
            employee_id = VALUES(employee_id),
            zk_name = VALUES(zk_name)'
    );
    $st->execute([
        $zkUserId,
        trim($badge) !== '' ? trim($badge) : null,
        $employeeId,
        trim($zkName) !== '' ? trim($zkName) : null,
    ]);
}

function hr_attendance_save_manual_map(PDO $pdo, int $zkUserId, int $employeeId): void
{
    if ($zkUserId < 1) {
        throw new RuntimeException('رقم مستخدم البصمة غير صالح.');
    }
    if ($employeeId < 1) {
        throw new RuntimeException('اختر موظفاً من النظام.');
    }

    $st = $pdo->prepare('SELECT id FROM hr_employee WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$employeeId]);
    if (!$st->fetchColumn()) {
        throw new RuntimeException('الموظف غير موجود أو غير نشط.');
    }

    $st = $pdo->prepare(
        'SELECT m.zk_user_id, m.badge_number, m.zk_name, e.name_ar
         FROM hr_att_employee_map m
         LEFT JOIN hr_employee e ON e.id = m.employee_id
         WHERE m.employee_id = ? AND m.zk_user_id <> ?
         LIMIT 1'
    );
    $st->execute([$employeeId, $zkUserId]);
    $conflict = $st->fetch(PDO::FETCH_ASSOC);
    if ($conflict) {
        $empName = trim((string) ($conflict['name_ar'] ?? ''));
        $otherZk = (int) ($conflict['zk_user_id'] ?? 0);
        $otherBadge = trim((string) ($conflict['badge_number'] ?? ''));
        throw new RuntimeException(
            ($empName !== '' ? 'الموظف «' . $empName . '»' : 'هذا الموظف')
            . ' مربوط مسبقاً بمستخدم البصمة ZK #' . $otherZk
            . ($otherBadge !== '' ? ' (رقم البصمة ' . $otherBadge . ')' : '')
            . '. اختر موظفاً آخر أو أزل الربط القديم أولاً.'
        );
    }

    $badge = '';
    $zkName = '';
    $cfg = hr_attendance_load_config($pdo);
    try {
        $row = hr_attendance_mdb_fetch_userinfo($cfg['mdb_path'], $zkUserId);
        if ($row) {
            $badge = trim((string) ($row['BADGENUMBER'] ?? ''));
            $zkName = trim((string) ($row['NAME'] ?? ''));
        }
    } catch (Throwable $e) {
        // optional enrichment
    }

    hr_attendance_upsert_map($pdo, $zkUserId, $employeeId, $badge, $zkName);

    $pdo->prepare(
        'UPDATE hr_att_punch SET employee_id = ? WHERE zk_user_id = ? AND (employee_id IS NULL OR employee_id = 0)'
    )->execute([$employeeId, $zkUserId]);
}

/**
 * @param array<int|string,int|string> $maps zk_user_id => employee_id
 * @return array{saved:int,errors:list<string>}
 */
function hr_attendance_save_manual_maps_batch(PDO $pdo, array $maps): array
{
    $saved = 0;
    $errors = [];

    foreach ($maps as $zkUserId => $employeeId) {
        $zkUserId = (int) $zkUserId;
        $employeeId = (int) $employeeId;
        if ($zkUserId < 1 || $employeeId < 1) {
            continue;
        }
        try {
            hr_attendance_save_manual_map($pdo, $zkUserId, $employeeId);
            $saved++;
        } catch (Throwable $e) {
            $msg = trim($e->getMessage());
            $errors[] = 'ZK #' . $zkUserId . ': ' . ($msg !== '' ? $msg : 'تعذر الربط');
        }
    }

    if ($saved < 1 && $errors === []) {
        throw new RuntimeException('اختر موظفاً واحداً على الأقل للربط.');
    }

    return ['saved' => $saved, 'errors' => $errors];
}

/**
 * موظفون غير مربوطين بمستخدم بصمة آخر (لقائمة الاختيار).
 *
 * @return list<array<string,mixed>>
 */
function hr_attendance_picker_employees_available(PDO $pdo): array
{
    hr_attendance_ensure_schema($pdo);
    $mappedIds = $pdo->query('SELECT employee_id FROM hr_att_employee_map')
        ->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $blocked = [];
    foreach ($mappedIds as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $blocked[$id] = true;
        }
    }

    $all = hr_employee_picker_list($pdo);

    return array_values(array_filter(
        $all,
        static fn (array $row): bool => !isset($blocked[(int) ($row['id'] ?? 0)])
    ));
}

function hr_attendance_punch_type_label(?string $type): string
{
    $t = strtoupper(trim((string) $type));
    if ($t === 'I' || $t === '0') {
        return 'دخول';
    }
    if ($t === 'O' || $t === '1') {
        return 'خروج';
    }

    return $t !== '' ? $t : '—';
}

function hr_attendance_verify_label(?int $code): string
{
    return match ((int) $code) {
        1 => 'بصمة',
        2 => 'بطاقة',
        3 => 'بصمة+بطاقة',
        4 => 'وجه',
        0 => 'كلمة مرور',
        default => $code !== null && (int) $code > 0 ? (string) (int) $code : '—',
    };
}

function hr_attendance_build_source_key(int $zkUserId, string $punchTime): string
{
    return $zkUserId . '|' . $punchTime;
}

/**
 * @return array{inserted:int,skipped:int,unlinked:int,last_punch_time:?string,message:string}
 */
function hr_attendance_sync(PDO $pdo): array
{
    hr_attendance_ensure_schema($pdo);
    $cfg = hr_attendance_load_config($pdo);

    $since = $cfg['last_punch_time'];
    if ($since === null || trim($since) === '') {
        $since = '1900-01-01 00:00:00';
    }

    $rows = hr_attendance_mdb_fetch_punches_since($cfg['mdb_path'], $since);

    $insertSt = $pdo->prepare(
        'INSERT IGNORE INTO hr_att_punch
            (employee_id, zk_user_id, badge_number, zk_name, punch_time, punch_type, verify_code, sensor_id, source_key)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $inserted = 0;
    $skipped = 0;
    $unlinked = 0;
    $maxPunch = $since;

    foreach ($rows as $row) {
        $zkUserId = (int) ($row['USERID'] ?? 0);
        if ($zkUserId < 1) {
            continue;
        }

        $punchRaw = $row['CHECKTIME'] ?? '';
        if ($punchRaw === '' || $punchRaw === null) {
            continue;
        }

        try {
            $dt = new DateTimeImmutable((string) $punchRaw);
            $punchTime = $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            continue;
        }

        if ($punchTime > $maxPunch) {
            $maxPunch = $punchTime;
        }

        $badge = trim((string) ($row['BADGENUMBER'] ?? ''));
        $zkName = trim((string) ($row['NAME'] ?? ''));
        $employeeId = hr_attendance_find_employee_id($pdo, $zkUserId, $badge);
        if ($employeeId !== null && $employeeId > 0) {
            hr_attendance_upsert_map($pdo, $zkUserId, $employeeId, $badge, $zkName);
        } else {
            $unlinked++;
        }

        $sourceKey = hr_attendance_build_source_key($zkUserId, $punchTime);
        $insertSt->execute([
            $employeeId > 0 ? $employeeId : null,
            $zkUserId,
            $badge !== '' ? $badge : null,
            $zkName !== '' ? $zkName : null,
            $punchTime,
            isset($row['CHECKTYPE']) ? (string) $row['CHECKTYPE'] : null,
            isset($row['VERIFYCODE']) ? (int) $row['VERIFYCODE'] : null,
            isset($row['SENSORID']) ? trim((string) $row['SENSORID']) : null,
            $sourceKey,
        ]);

        if ($insertSt->rowCount() > 0) {
            $inserted++;
        } else {
            $skipped++;
        }
    }

    $now = date('Y-m-d H:i:s');
    $upd = $pdo->prepare(
        'UPDATE hr_att_config SET last_sync_at = ?, last_punch_time = ? WHERE id = 1'
    );
    $upd->execute([
        $now,
        $maxPunch !== '1900-01-01 00:00:00' ? $maxPunch : $cfg['last_punch_time'],
    ]);

    $msg = 'تمت المزامنة: ' . $inserted . ' سجل جديد';
    if ($skipped > 0) {
        $msg .= '، ' . $skipped . ' موجود مسبقاً';
    }
    if ($unlinked > 0) {
        $msg .= '، ' . $unlinked . ' غير مربوط بموظف';
    }

    return [
        'inserted' => $inserted,
        'skipped' => $skipped,
        'unlinked' => $unlinked,
        'last_punch_time' => $maxPunch !== '1900-01-01 00:00:00' ? $maxPunch : $cfg['last_punch_time'],
        'message' => $msg,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function hr_attendance_list_punches(
    PDO $pdo,
    ?string $dateFrom,
    ?string $dateTo,
    int $employeeId = 0,
    int $limit = 500
): array {
    hr_attendance_ensure_schema($pdo);
    $limit = max(1, min(2000, $limit));

    $where = ['1=1'];
    $params = [];

    if ($dateFrom !== null && $dateFrom !== '') {
        $where[] = 'p.punch_time >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== null && $dateTo !== '') {
        $where[] = 'p.punch_time <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }
    if ($employeeId > 0) {
        $where[] = 'p.employee_id = ?';
        $params[] = $employeeId;
    }

    $sql = 'SELECT p.*, e.name_ar AS employee_name, e.emp_code
            FROM hr_att_punch p
            LEFT JOIN hr_employee e ON e.id = p.employee_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY p.punch_time DESC
            LIMIT ' . $limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string,mixed>>
 */
function hr_attendance_unmapped_zk_users(PDO $pdo, int $limit = 100): array
{
    hr_attendance_ensure_schema($pdo);
    $limit = max(1, min(500, $limit));

    $sql = 'SELECT p.zk_user_id,
                   MAX(p.badge_number) AS badge_number,
                   MAX(p.zk_name) AS zk_name,
                   COUNT(*) AS punch_count,
                   MAX(p.punch_time) AS last_punch
            FROM hr_att_punch p
            LEFT JOIN hr_att_employee_map m ON m.zk_user_id = p.zk_user_id
            WHERE (p.employee_id IS NULL OR p.employee_id = 0)
              AND m.id IS NULL
            GROUP BY p.zk_user_id
            ORDER BY last_punch DESC
            LIMIT ' . $limit;

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function hr_attendance_count_punches(PDO $pdo): int
{
    hr_attendance_ensure_schema($pdo);

    return (int) $pdo->query('SELECT COUNT(*) FROM hr_att_punch')->fetchColumn();
}

function hr_attendance_auto_map_existing(PDO $pdo): int
{
    hr_attendance_ensure_schema($pdo);
    $rows = $pdo->query(
        'SELECT DISTINCT zk_user_id, badge_number, zk_name
         FROM hr_att_punch
         WHERE employee_id IS NULL OR employee_id = 0'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $mapped = 0;
    foreach ($rows as $row) {
        $zkUserId = (int) ($row['zk_user_id'] ?? 0);
        if ($zkUserId < 1) {
            continue;
        }
        $badge = trim((string) ($row['badge_number'] ?? ''));
        $employeeId = hr_attendance_find_employee_id($pdo, $zkUserId, $badge);
        if ($employeeId === null || $employeeId < 1) {
            continue;
        }
        hr_attendance_upsert_map(
            $pdo,
            $zkUserId,
            $employeeId,
            $badge,
            trim((string) ($row['zk_name'] ?? ''))
        );
        $pdo->prepare('UPDATE hr_att_punch SET employee_id = ? WHERE zk_user_id = ?')
            ->execute([$employeeId, $zkUserId]);
        $mapped++;
    }

    return $mapped;
}
