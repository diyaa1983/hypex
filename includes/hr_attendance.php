<?php
declare(strict_types=1);

if (!defined('HR_ATT_MDB_ONLY')) {
    require_once app_path('includes/hr_schema.php');
}

function hr_attendance_ensure_schema(PDO $pdo): void
{
    if (defined('HR_ATT_MDB_ONLY')) {
        return;
    }

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

    hr_attendance_ensure_sync_token_column($pdo);
}

function hr_attendance_ensure_sync_token_column(PDO $pdo): void
{
    try {
        $pdo->query('SELECT sync_token FROM hr_att_config LIMIT 1');
    } catch (Throwable $e) {
        if (
            str_contains($e->getMessage(), 'Unknown column')
            || str_contains($e->getMessage(), 'no such column')
        ) {
            try {
                $pdo->exec(
                    'ALTER TABLE hr_att_config ADD COLUMN sync_token VARCHAR(64) NULL DEFAULT NULL AFTER last_punch_time'
                );
            } catch (Throwable $e2) {
                // ignored
            }
        }
    }
}

function hr_attendance_remote_agent_marker(): string
{
    return 'remote://zk-agent';
}

/** @return array{mdb_path:string,last_sync_at:?string,last_punch_time:?string,sync_token:?string} */
function hr_attendance_load_config(PDO $pdo): array
{
    hr_attendance_ensure_schema($pdo);
    hr_attendance_ensure_sync_token_column($pdo);
    $row = $pdo->query('SELECT mdb_path, last_sync_at, last_punch_time, sync_token FROM hr_att_config WHERE id = 1 LIMIT 1')
        ->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $defaultPath = hr_attendance_uses_remote_agent()
            ? hr_attendance_remote_agent_marker()
            : 'C:\\Program Files (x86)\\ZKTeco\\att2000.mdb';

        return [
            'mdb_path' => $defaultPath,
            'last_sync_at' => null,
            'last_punch_time' => null,
            'sync_token' => null,
        ];
    }

    return [
        'mdb_path' => (string) ($row['mdb_path'] ?? ''),
        'last_sync_at' => $row['last_sync_at'] !== null ? (string) $row['last_sync_at'] : null,
        'last_punch_time' => $row['last_punch_time'] !== null ? (string) $row['last_punch_time'] : null,
        'sync_token' => isset($row['sync_token']) && (string) $row['sync_token'] !== ''
            ? (string) $row['sync_token']
            : null,
    ];
}

function hr_attendance_save_config(PDO $pdo, string $mdbPath, bool $forceLocalScreen = false): void
{
    hr_attendance_ensure_schema($pdo);
    $mdbPath = trim($mdbPath);
    if ($mdbPath === '') {
        throw new RuntimeException('أدخل مسار ملف att2000.mdb.');
    }
    if (!$forceLocalScreen && hr_attendance_uses_remote_agent()) {
        if ($mdbPath === hr_attendance_remote_agent_marker() || hr_attendance_path_issue($mdbPath) !== null) {
            $mdbPath = hr_attendance_remote_agent_marker();
            $st = $pdo->prepare(
                'INSERT INTO hr_att_config (id, mdb_path) VALUES (1, ?)
                 ON DUPLICATE KEY UPDATE mdb_path = VALUES(mdb_path)'
            );
            $st->execute([$mdbPath]);

            return;
        }
    }
    if (hr_attendance_path_issue($mdbPath) !== null) {
        throw new RuntimeException(
            'مسار Windows (مثل C:\\Program Files\\...) لا يعمل على خادم Linux.' . "\n\n"
            . 'ارفع att2000.mdb إلى:' . "\n"
            . hr_attendance_recommended_mdb_path()
        );
    }
    if (!is_file($mdbPath)) {
        throw new RuntimeException(
            'ملف قاعدة البصمة غير موجود: ' . $mdbPath
            . (hr_attendance_is_linux_server()
                ? "\n\nارفع الملف من جهاز ZKT عبر FTP/cPanel إلى المسار أعلاه."
                : '')
        );
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

function hr_attendance_is_linux_server(): bool
{
    return DIRECTORY_SEPARATOR === '/';
}

/**
 * auto = Linux → وكيل ZKT | Windows → قراءة مباشرة من att2000.mdb
 * remote | local = تجاوز يدوي للاختبار (في config/app.local.php: HR_ATT_SYNC_MODE)
 *
 * @return 'auto'|'local'|'remote'
 */
function hr_attendance_sync_mode_setting(): string
{
    if (defined('HR_ATT_SYNC_MODE')) {
        $mode = strtolower(trim((string) HR_ATT_SYNC_MODE));
        if (in_array($mode, ['auto', 'local', 'remote'], true)) {
            return $mode;
        }
    }

    return 'auto';
}

function hr_attendance_uses_remote_agent(): bool
{
    $mode = hr_attendance_sync_mode_setting();
    if ($mode === 'remote') {
        return true;
    }
    if ($mode === 'local') {
        return false;
    }

    return hr_attendance_is_linux_server();
}

/** @return array{key:string,label:string,hint:string} */
function hr_attendance_sync_mode_info(): array
{
    $setting = hr_attendance_sync_mode_setting();
    $remote = hr_attendance_uses_remote_agent();

    if ($remote) {
        return [
            'key' => 'remote',
            'label' => $setting === 'remote'
                ? 'وكيل ZKT (إعداد يدوي)'
                : 'وكيل ZKT (سيرفر Linux)',
            'hint' => 'ملف att2000.mdb يبقى على جهاز البصمة — شغّل zk_sync_run.bat لإرسال البصمات.',
        ];
    }

    return [
        'key' => 'local',
        'label' => $setting === 'local'
            ? 'مزامنة مباشرة (إعداد يدوي)'
            : 'مزامنة مباشرة (Windows محلي)',
        'hint' => 'يقرأ Manager ملف att2000.mdb مباشرة — زر «مزامنة الآن» من داخل النظام.',
    ];
}

function hr_attendance_is_windows_drive_path(string $path): bool
{
    return (bool) preg_match('/^[a-zA-Z]:/', trim($path));
}

function hr_attendance_recommended_mdb_path(): string
{
    return hr_attendance_mdb_cache_file();
}

/** @return string|null */
function hr_attendance_path_issue(string $path): ?string
{
    $path = trim($path);
    if ($path === '') {
        return null;
    }
    if (hr_attendance_is_linux_server() && hr_attendance_is_windows_drive_path($path)) {
        return 'المسار المحفوظ بصيغة Windows (C:\\...) لا يعمل على خادم Linux.'
            . ' ارفع att2000.mdb إلى: ' . hr_attendance_recommended_mdb_path();
    }

    return null;
}

function hr_attendance_mdbtools_bin(): ?string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached !== '' ? $cached : null;
    }

    $candidates = ['mdb-export', '/usr/bin/mdb-export', '/usr/local/bin/mdb-export'];
    foreach ($candidates as $bin) {
        $out = [];
        $code = 1;
        @exec(escapeshellarg($bin) . ' 2>&1', $out, $code);
        $text = strtolower(implode(' ', $out));
        if ($code === 0 || str_contains($text, 'usage') || str_contains($text, 'mdb-export')) {
            $cached = $bin;

            return $bin;
        }
    }

    $whichOut = [];
    @exec('command -v mdb-export 2>/dev/null', $whichOut, $whichCode);
    if ($whichCode === 0 && !empty($whichOut[0])) {
        $cached = trim((string) $whichOut[0]);

        return $cached;
    }

    $cached = '';

    return null;
}

function hr_attendance_mdbtools_available(): bool
{
    return hr_attendance_mdbtools_bin() !== null;
}

function hr_attendance_mdb_driver_label(): string
{
    if (hr_attendance_pdo_odbc_available()) {
        return 'PDO ODBC';
    }
    if (hr_attendance_com_available()) {
        return 'OLEDB (COM)';
    }
    if (hr_attendance_mdbtools_available()) {
        return 'mdbtools (Linux)';
    }

    return 'غير متاح';
}

/**
 * @return list<array<string, string>>
 */
function hr_attendance_mdbtools_export_table(string $mdbPath, string $table): array
{
    $bin = hr_attendance_mdbtools_bin();
    if ($bin === null) {
        throw new RuntimeException('حزمة mdbtools غير متوفرة على الخادم (mdb-export).');
    }

    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table) ?? '';
    if ($table === '') {
        throw new RuntimeException('اسم جدول غير صالح.');
    }

    $cmd = escapeshellarg($bin)
        . ' -H -D ' . escapeshellarg('%Y-%m-%d %H:%M:%S')
        . ' ' . escapeshellarg($mdbPath)
        . ' ' . escapeshellarg($table);

    $lines = [];
    $code = 1;
    @exec($cmd . ' 2>&1', $lines, $code);
    if ($code !== 0 || $lines === []) {
        throw new RuntimeException(
            'تعذر قراءة جدول ' . $table . ' عبر mdbtools.'
            . ($lines !== [] ? ' ' . implode(' ', array_slice($lines, 0, 2)) : '')
        );
    }

    $headers = str_getcsv((string) $lines[0]);
    $rows = [];
    for ($i = 1, $n = count($lines); $i < $n; $i++) {
        $cols = str_getcsv((string) $lines[$i]);
        if ($cols === [null] || $cols === []) {
            continue;
        }
        $row = [];
        foreach ($headers as $idx => $header) {
            $row[(string) $header] = isset($cols[$idx]) ? (string) $cols[$idx] : '';
        }
        $rows[] = $row;
    }

    return $rows;
}

/**
 * @return list<array<string,mixed>>
 */
function hr_attendance_mdbtools_fetch_punches_joined(string $mdbPath): array
{
    $checkins = hr_attendance_mdbtools_export_table($mdbPath, 'CHECKINOUT');
    $users = hr_attendance_mdbtools_export_table($mdbPath, 'USERINFO');
    $userMap = [];
    foreach ($users as $user) {
        $uid = (int) ($user['USERID'] ?? 0);
        if ($uid > 0) {
            $userMap[$uid] = $user;
        }
    }

    $rows = [];
    foreach ($checkins as $row) {
        $uid = (int) ($row['USERID'] ?? 0);
        $info = $userMap[$uid] ?? [];
        $rows[] = [
            'USERID' => $uid,
            'CHECKTIME' => $row['CHECKTIME'] ?? '',
            'CHECKTYPE' => $row['CHECKTYPE'] ?? '',
            'VERIFYCODE' => $row['VERIFYCODE'] ?? '',
            'SENSORID' => $row['SENSORID'] ?? '',
            'Flag' => $row['Flag'] ?? null,
            'BADGENUMBER' => $info['BADGENUMBER'] ?? '',
            'NAME' => $info['NAME'] ?? '',
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $ta = hr_attendance_parse_checktime($a['CHECKTIME'] ?? null) ?? '';
        $tb = hr_attendance_parse_checktime($b['CHECKTIME'] ?? null) ?? '';

        return strcmp($ta, $tb);
    });

    return $rows;
}

function hr_attendance_mdbtools_flag_is_pending(mixed $flag): bool
{
    if ($flag === null || $flag === '') {
        return true;
    }

    return (int) $flag === 0;
}

function hr_attendance_mdbtools_table_has_column(string $mdbPath, string $table, string $column): bool
{
    $bin = hr_attendance_mdbtools_bin();
    if ($bin === null) {
        return false;
    }

    $table = preg_replace('/[^A-Za-z0-9_]/', '', $table) ?? '';
    $column = preg_replace('/[^A-Za-z0-9_]/', '', $column) ?? '';
    if ($table === '' || $column === '') {
        return false;
    }

    $cmd = escapeshellarg($bin)
        . ' -H -D ' . escapeshellarg('%Y-%m-%d %H:%M:%S')
        . ' ' . escapeshellarg($mdbPath)
        . ' ' . escapeshellarg($table);

    $lines = [];
    @exec($cmd . ' 2>&1', $lines, $code);
    if ($code !== 0 || $lines === []) {
        return false;
    }

    $headers = str_getcsv((string) $lines[0]);

    return in_array($column, $headers, true);
}

function hr_attendance_normalize_mdb_path(string $mdbPath): string
{
    $mdbPath = trim($mdbPath);
    if ($mdbPath === '') {
        return '';
    }
    if (hr_attendance_is_linux_server() && !hr_attendance_is_windows_drive_path($mdbPath)) {
        return str_replace('\\', '/', $mdbPath);
    }

    return trim(str_replace('/', '\\', $mdbPath));
}

function hr_attendance_assert_mdb_readable(string $mdbPath): string
{
    $mdbPath = hr_attendance_normalize_mdb_path($mdbPath);
    if (!is_file($mdbPath)) {
        $extra = hr_attendance_is_linux_server()
            ? "\n\nعلى Linux: انسخ att2000.mdb من جهاز ZKT وارفعه إلى:\n" . hr_attendance_recommended_mdb_path()
            : '';

        throw new RuntimeException('ملف قاعدة البصمة غير موجود: ' . $mdbPath . $extra);
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
function hr_attendance_mdb_oledb_connection_strings_writable(string $mdbPath): array
{
    return [
        'Provider=Microsoft.ACE.OLEDB.12.0;Data Source=' . $mdbPath . ';Mode=Share Deny None;Persist Security Info=False;',
        'Provider=Microsoft.Jet.OLEDB.4.0;Data Source=' . $mdbPath . ';Mode=Share Deny None;Jet OLEDB:Database Locking Mode=1;Persist Security Info=False;',
        'Provider=Microsoft.ACE.OLEDB.12.0;Data Source=' . $mdbPath . ';Persist Security Info=False;',
        'Provider=Microsoft.Jet.OLEDB.4.0;Data Source=' . $mdbPath . ';Persist Security Info=False;',
    ];
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

    @touch($dest, filemtime($sourcePath) ?: time());

    return $dest;
}

/**
 * مسار القراءة للمزامنة: الملف الأصلي إن أمكن، وإلا نسخة محدّثة.
 */
function hr_attendance_mdb_prepare_sync_path(string $mdbPath): string
{
    $mdbPath = hr_attendance_assert_mdb_readable($mdbPath);
    $cache = hr_attendance_mdb_cache_file();
    $srcMtime = @filemtime($mdbPath) ?: 0;
    $cacheMtime = is_file($cache) ? (@filemtime($cache) ?: 0) : 0;

    if ($srcMtime > $cacheMtime) {
        try {
            hr_attendance_mdb_snapshot_copy($mdbPath);
        } catch (Throwable $e) {
            // قد يكون الملف مقفولاً — نتابع بمحاولة فتح الأصل أو النسخة القديمة
        }
    }

    if (hr_attendance_pdo_odbc_available()) {
        $dsns = [
            'odbc:Driver={Microsoft Access Driver (*.mdb, *.accdb)};Dbq=%s;ReadOnly=1;',
            'odbc:Driver={Microsoft Access Driver (*.mdb, *.accdb)};Dbq=%s;',
            'odbc:Driver={Microsoft Access Driver (*.mdb)};Dbq=%s;',
        ];
        foreach ($dsns as $pattern) {
            try {
                $pdo = new PDO(sprintf($pattern, $mdbPath), '', '');
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->query('SELECT COUNT(*) FROM CHECKINOUT')->fetchColumn();
                hr_attendance_mdb_set_last_via_snapshot(false);

                return $mdbPath;
            } catch (Throwable $e) {
                // try next driver / snapshot
            }
        }
    } elseif (hr_attendance_com_available()) {
        try {
            $conn = hr_attendance_mdb_com_open_path($mdbPath);
            hr_attendance_mdb_com_scalar($conn, 'SELECT COUNT(*) FROM CHECKINOUT');
            hr_attendance_mdb_set_last_via_snapshot(false);

            return $mdbPath;
        } catch (Throwable $e) {
            // fall through to snapshot
        }
    }

    hr_attendance_mdb_snapshot_copy($mdbPath);
    hr_attendance_mdb_set_last_via_snapshot(true);

    return hr_attendance_mdb_cache_file();
}

function hr_attendance_mdb_open_pdo(string $mdbPath, bool $writable = false): PDO
{
    if (!hr_attendance_pdo_odbc_available()) {
        throw new RuntimeException('pdo_odbc غير متاح.');
    }
    $mdbPath = hr_attendance_assert_mdb_readable($mdbPath);
    $errors = [];
    $dsns = $writable
        ? [
            'odbc:Driver={Microsoft Access Driver (*.mdb, *.accdb)};Dbq=%s;',
            'odbc:Driver={Microsoft Access Driver (*.mdb)};Dbq=%s;',
        ]
        : [
            'odbc:Driver={Microsoft Access Driver (*.mdb, *.accdb)};Dbq=%s;ReadOnly=1;',
            'odbc:Driver={Microsoft Access Driver (*.mdb, *.accdb)};Dbq=%s;',
            'odbc:Driver={Microsoft Access Driver (*.mdb)};Dbq=%s;',
        ];
    foreach ($dsns as $pattern) {
        try {
            $mdb = new PDO(sprintf($pattern, $mdbPath), '', '');
            $mdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $mdb;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    throw new RuntimeException(
        'تعذر فتح att2000.mdb: ' . implode(' | ', array_slice($errors, 0, 2))
    );
}

function hr_attendance_mdb_com_open_path(string $mdbPath, bool $writable = false): COM
{
    $strings = $writable
        ? hr_attendance_mdb_oledb_connection_strings_writable($mdbPath)
        : hr_attendance_mdb_oledb_connection_strings($mdbPath);
    $errors = [];
    foreach ($strings as $cs) {
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

function hr_attendance_parse_checktime(mixed $raw): ?string
{
    if ($raw === null || $raw === '') {
        return null;
    }

    if ($raw instanceof DateTimeInterface) {
        return $raw->format('Y-m-d H:i:s');
    }

    if (is_numeric($raw)) {
        $serial = (float) $raw;
        if ($serial > 0) {
            $base = new DateTimeImmutable('1899-12-30 00:00:00');
            $seconds = (int) round($serial * 86400);

            return $base->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');
        }
    }

    $str = trim(preg_replace('/\s+/u', ' ', (string) $raw) ?? '');
    if ($str === '') {
        return null;
    }

    // Access/ZKT: 31/07/2026 12:08:15 م I  (التاريخ أولاً + ص/م)
    $dateFirst = preg_replace('/\s*[IO]\s*$/u', '', $str) ?? $str;
    $dateFirst = trim(preg_replace('/\s*[صم]\s*$/u', '', trim($dateFirst)) ?? $dateFirst);
    if (preg_match(
        '/^(\d{1,2}\/\d{1,2}\/\d{4})\s+(\d{1,2}:\d{2}(?::\d{2})?)/u',
        $dateFirst,
        $df
    )) {
        $timePart = $df[2];
        if (substr_count($timePart, ':') === 1) {
            $timePart .= ':00';
        }
        $dt = DateTimeImmutable::createFromFormat('d/m/Y H:i:s', $df[1] . ' ' . $timePart);
        if ($dt instanceof DateTimeInterface) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    // Access/ZKT: 12:08:15 31/05/2025 I  أو  ص 08:10:23 01/06/2026
    if (preg_match(
        '/^(\d{1,2}:\d{2}(?::\d{2})?)\s+(\d{1,2}\/\d{1,2}\/\d{4})/u',
        $str,
        $timeFirst
    )) {
        $timePart = $timeFirst[1];
        if (substr_count($timePart, ':') === 1) {
            $timePart .= ':00';
        }
        $dt = DateTimeImmutable::createFromFormat('d/m/Y H:i:s', $timeFirst[2] . ' ' . $timePart);
        if ($dt instanceof DateTimeInterface) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    // Access/ODBC أحياناً يعيد AM/PM عربي أو I/O ملحق بالوقت
    $str = preg_replace('/\s*[IO]\s*$/u', '', $str) ?? $str;
    $str = preg_replace('/^[صم]\s*/u', '', $str) ?? $str;
    $str = str_replace([' ص ', ' م ', 'ص ', 'م '], ' ', $str);
    $str = trim(preg_replace('/\s+/u', ' ', $str) ?? '');

    if (preg_match(
        '/^(\d{1,2}:\d{2}(?::\d{2})?)\s+(\d{1,2}\/\d{1,2}\/\d{4})/u',
        $str,
        $timeFirst
    )) {
        $timePart = $timeFirst[1];
        if (substr_count($timePart, ':') === 1) {
            $timePart .= ':00';
        }
        $dt = DateTimeImmutable::createFromFormat('d/m/Y H:i:s', $timeFirst[2] . ' ' . $timePart);
        if ($dt instanceof DateTimeInterface) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    $formats = [
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'H:i:s d/m/Y',
        'H:i d/m/Y',
        'd/m/Y H:i:s',
        'd/m/Y H:i',
        'm/d/Y H:i:s',
        'm/d/Y H:i',
        'd-m-Y H:i:s',
        'd-m-Y H:i',
    ];
    foreach ($formats as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $str);
        if ($dt instanceof DateTimeInterface) {
            $errors = DateTimeImmutable::getLastErrors();
            if (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0) {
                return $dt->format('Y-m-d H:i:s');
            }
        }
    }

    try {
        return (new DateTimeImmutable($str))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function hr_attendance_mdb_fetch_userids(string $mdbPath): array
{
    $mdbPath = hr_attendance_assert_mdb_readable($mdbPath);

    if (hr_attendance_pdo_odbc_available()) {
        $mdb = hr_attendance_mdb_connect($mdbPath);
        $rows = $mdb->query('SELECT DISTINCT USERID FROM CHECKINOUT WHERE USERID > 0 ORDER BY USERID ASC')
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } else {
        $conn = hr_attendance_mdb_com_open($mdbPath);
        $rows = array_map(
            static fn (array $r): int => (int) ($r['USERID'] ?? 0),
            hr_attendance_mdb_com_fetch_all($conn, 'SELECT DISTINCT USERID FROM CHECKINOUT WHERE USERID > 0 ORDER BY USERID ASC')
        );
    }

    return array_values(array_filter(array_map('intval', $rows), static fn (int $id): bool => $id > 0));
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function hr_attendance_merge_punch_rows(array $rows): array
{
    $merged = [];
    $seen = [];

    foreach ($rows as $row) {
        $uid = (int) ($row['USERID'] ?? 0);
        $raw = (string) ($row['CHECKTIME'] ?? '');
        $key = $uid . '|' . $raw;
        if ($uid < 1 || $raw === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $merged[] = $row;
    }

    usort($merged, static function (array $a, array $b): int {
        $ta = hr_attendance_parse_checktime($a['CHECKTIME'] ?? null) ?? '';
        $tb = hr_attendance_parse_checktime($b['CHECKTIME'] ?? null) ?? '';
        if ($ta === $tb) {
            return ((int) ($a['USERID'] ?? 0)) <=> ((int) ($b['USERID'] ?? 0));
        }

        return strcmp($ta, $tb);
    });

    return $merged;
}

function hr_attendance_resolve_punch_type(array $row): ?string
{
    $type = strtoupper(trim((string) ($row['CHECKTYPE'] ?? '')));
    if ($type === 'I' || $type === 'O' || $type === '0' || $type === '1') {
        return $type;
    }

    $raw = trim((string) ($row['CHECKTIME'] ?? ''));
    if (preg_match('/\s([IO])\s*$/u', $raw, $m)) {
        return $m[1];
    }

    return $type !== '' ? $type : null;
}

/**
 * @return array<string,true>
 */
function hr_attendance_existing_source_keys(PDO $pdo): array
{
    hr_attendance_ensure_schema($pdo);
    $keys = [];
    $st = $pdo->query('SELECT source_key FROM hr_att_punch');
    while ($row = $st->fetch(PDO::FETCH_NUM)) {
        $key = (string) ($row[0] ?? '');
        if ($key !== '') {
            $keys[$key] = true;
        }
    }

    return $keys;
}

/** اسم حقل المزامنة في CHECKINOUT (Access ODBC حساس لحالة الاسم). */
function hr_attendance_mdb_sync_flag_col(): string
{
    return '[Flag]';
}

function hr_attendance_mdb_sync_flag_pending_sql(string $tableAlias = ''): string
{
    $col = ($tableAlias !== '' ? $tableAlias . '.' : '') . hr_attendance_mdb_sync_flag_col();

    return '(' . $col . ' = 0 OR ' . $col . ' IS NULL)';
}

function hr_attendance_mdb_checkinout_has_flag(string $mdbPath): bool
{
    static $cache = [];
    $mdbPath = hr_attendance_normalize_mdb_path($mdbPath);
    if (array_key_exists($mdbPath, $cache)) {
        return $cache[$mdbPath];
    }

    $col = hr_attendance_mdb_sync_flag_col();
    try {
        if (hr_attendance_pdo_odbc_available()) {
            $mdb = hr_attendance_mdb_open_pdo($mdbPath);
            $mdb->query('SELECT TOP 1 ' . $col . ' FROM CHECKINOUT')->fetch(PDO::FETCH_ASSOC);
        } elseif (hr_attendance_com_available()) {
            $conn = hr_attendance_mdb_com_open_path($mdbPath);
            hr_attendance_mdb_com_fetch_all($conn, 'SELECT TOP 1 ' . $col . ' FROM CHECKINOUT');
        } elseif (hr_attendance_mdbtools_available()) {
            $cache[$mdbPath] = hr_attendance_mdbtools_table_has_column($mdbPath, 'CHECKINOUT', 'Flag');

            return $cache[$mdbPath];
        } else {
            $cache[$mdbPath] = false;

            return false;
        }
        $cache[$mdbPath] = true;
    } catch (Throwable $e) {
        $cache[$mdbPath] = false;
    }

    return $cache[$mdbPath];
}

/**
 * @return array{odbc:string,com:string}
 */
function hr_attendance_mdb_punch_select_sql(bool $unsyncedOnly = false): array
{
    $where = $unsyncedOnly ? ' WHERE ' . hr_attendance_mdb_sync_flag_pending_sql('c') : '';

    return [
        'odbc' => 'SELECT c.USERID, c.CHECKTIME, c.CHECKTYPE, c.VERIFYCODE, c.SENSORID,
                          u.BADGENUMBER, u.NAME
                   FROM CHECKINOUT c
                   LEFT JOIN USERINFO u ON u.USERID = c.USERID' . $where . '
                   ORDER BY c.CHECKTIME ASC',
        'com' => 'SELECT c.USERID, c.CHECKTIME, c.CHECKTYPE, c.VERIFYCODE, c.SENSORID,
                         u.BADGENUMBER, u.NAME
                  FROM CHECKINOUT AS c
                  LEFT JOIN USERINFO AS u ON u.USERID = c.USERID' . $where . '
                  ORDER BY c.CHECKTIME ASC',
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function hr_attendance_mdb_fetch_unsynced_punches(string $mdbPath): array
{
    $mdbPath = hr_attendance_assert_mdb_readable($mdbPath);
    $sql = hr_attendance_mdb_punch_select_sql(true);

    if (hr_attendance_pdo_odbc_available()) {
        $mdb = hr_attendance_mdb_open_pdo($mdbPath);

        return $mdb->query($sql['odbc'])->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if (hr_attendance_com_available()) {
        $conn = hr_attendance_mdb_com_open_path($mdbPath);

        return hr_attendance_mdb_com_fetch_all($conn, $sql['com']);
    }

    if (hr_attendance_mdbtools_available()) {
        $all = hr_attendance_mdbtools_fetch_punches_joined($mdbPath);

        return array_values(array_filter(
            $all,
            static fn (array $row): bool => hr_attendance_mdbtools_flag_is_pending($row['Flag'] ?? null)
        ));
    }

    throw new RuntimeException('لا يمكن قراءة att2000.mdb — فعّل ODBC/COM أو mdbtools.');
}

function hr_attendance_mdb_count_unsynced_punches(string $mdbPath): int
{
    $mdbPath = hr_attendance_assert_mdb_readable($mdbPath);
    $where = ' WHERE ' . hr_attendance_mdb_sync_flag_pending_sql();

    if (hr_attendance_pdo_odbc_available()) {
        $mdb = hr_attendance_mdb_open_pdo($mdbPath);

        return (int) $mdb->query('SELECT COUNT(*) FROM CHECKINOUT' . $where)->fetchColumn();
    }

    if (hr_attendance_com_available()) {
        $conn = hr_attendance_mdb_com_open_path($mdbPath);

        return hr_attendance_mdb_com_scalar($conn, 'SELECT COUNT(*) FROM CHECKINOUT' . $where);
    }

    if (hr_attendance_mdbtools_available()) {
        return count(hr_attendance_mdb_fetch_unsynced_punches($mdbPath));
    }

    throw new RuntimeException('لا يمكن قراءة att2000.mdb — فعّل ODBC/COM أو mdbtools.');
}

function hr_attendance_mdb_checktime_param(mixed $checkTimeRaw): mixed
{
    if ($checkTimeRaw instanceof DateTimeInterface) {
        return $checkTimeRaw->format('Y-m-d H:i:s');
    }

    $parsed = hr_attendance_parse_checktime($checkTimeRaw);
    if ($parsed !== null) {
        return $parsed;
    }

    return trim((string) $checkTimeRaw);
}

function hr_attendance_mdb_set_last_write_error(?string $message): void
{
    $GLOBALS['hr_att_mdb_write_error'] = $message;
}

function hr_attendance_mdb_last_write_error(): ?string
{
    $msg = $GLOBALS['hr_att_mdb_write_error'] ?? null;

    return ($msg !== null && (string) $msg !== '') ? (string) $msg : null;
}

function hr_attendance_mdb_checktime_access_literal(mixed $checkTimeRaw): string
{
    if ($checkTimeRaw instanceof DateTimeInterface) {
        return hr_attendance_access_date_literal($checkTimeRaw->format('Y-m-d H:i:s'));
    }

    $parsed = hr_attendance_parse_checktime($checkTimeRaw);
    if ($parsed !== null) {
        return hr_attendance_access_date_literal($parsed);
    }

    $str = trim((string) $checkTimeRaw);
    if ($str !== '' && preg_match('/^#.+#$/', $str)) {
        return $str;
    }

    return hr_attendance_access_date_literal($str !== '' ? $str : '1900-01-01');
}

function hr_attendance_mdb_mark_checkinout_synced_com_conn(COM $conn, int $userId, mixed $checkTimeRaw): bool
{
    $flagCol = hr_attendance_mdb_sync_flag_col();
    $timeLit = hr_attendance_mdb_checktime_access_literal($checkTimeRaw);
    $where = '[USERID] = ' . $userId . ' AND [CHECKTIME] = ' . $timeLit;

    try {
        $rs = $conn->Execute('SELECT ' . $flagCol . ' FROM [CHECKINOUT] WHERE ' . $where);
        if ($rs && !$rs->EOF) {
            $rs->Fields('Flag')->Value = 1;
            $rs->Update();

            return true;
        }
    } catch (Throwable $e) {
        // fall through to SQL UPDATE
    }

    $sql = 'UPDATE [CHECKINOUT] SET ' . $flagCol . ' = 1 WHERE ' . $where;
    $conn->Execute($sql);

    return true;
}

function hr_attendance_mdb_mark_checkinout_synced_com(string $mdbPath, int $userId, mixed $checkTimeRaw): bool
{
    $conn = hr_attendance_mdb_com_open_path($mdbPath, true);

    return hr_attendance_mdb_mark_checkinout_synced_com_conn($conn, $userId, $checkTimeRaw);
}

function hr_attendance_mdb_mark_checkinout_synced_odbc(string $mdbPath, int $userId, mixed $checkTimeRaw): bool
{
    $mdb = hr_attendance_mdb_open_pdo($mdbPath, true);
    $flagCol = hr_attendance_mdb_sync_flag_col();
    $timeLit = hr_attendance_mdb_checktime_access_literal($checkTimeRaw);
    $sql = 'UPDATE [CHECKINOUT] SET ' . $flagCol . ' = 1 WHERE [USERID] = ' . $userId
        . ' AND [CHECKTIME] = ' . $timeLit;
    $mdb->exec($sql);

    return true;
}

/** @return array{ok:bool,message:string} */
function hr_attendance_mdb_test_write_access(string $mdbPath): array
{
    if (!hr_attendance_mdb_checkinout_has_flag($mdbPath)) {
        return ['ok' => false, 'message' => 'حقل Flag غير موجود.'];
    }

    $mdbPath = hr_attendance_assert_mdb_readable($mdbPath);
    $row = null;

    try {
        if (hr_attendance_pdo_odbc_available()) {
            $mdb = hr_attendance_mdb_open_pdo($mdbPath);
            $row = $mdb->query(
                'SELECT TOP 1 USERID, CHECKTIME FROM CHECKINOUT WHERE '
                . hr_attendance_mdb_sync_flag_pending_sql()
            )->fetch(PDO::FETCH_ASSOC);
        } else {
            $conn = hr_attendance_mdb_com_open_path($mdbPath);
            $rows = hr_attendance_mdb_com_fetch_all(
                $conn,
                'SELECT TOP 1 USERID, CHECKTIME FROM CHECKINOUT WHERE '
                . hr_attendance_mdb_sync_flag_pending_sql()
            );
            $row = $rows[0] ?? null;
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'تعذر قراءة CHECKINOUT: ' . $e->getMessage()];
    }

    if (!is_array($row)) {
        return ['ok' => true, 'message' => 'لا توجد سجلات Flag=0 للاختبار — الكتابة غير مطلوبة حالياً.'];
    }

    $userId = (int) ($row['USERID'] ?? 0);
    $checkTime = $row['CHECKTIME'] ?? null;
    if ($userId < 1 || $checkTime === null) {
        return ['ok' => false, 'message' => 'تعذر اختبار الكتابة — لا توجد بصمة تجريبية.'];
    }

    if (!hr_attendance_mdb_mark_checkinout_synced($mdbPath, $userId, $checkTime)) {
        $err = hr_attendance_mdb_last_write_error() ?? 'تعذر تحديث Flag';

        return [
            'ok' => false,
            'message' => $err,
        ];
    }

    return ['ok' => true, 'message' => 'الكتابة على Access تعمل — يمكن تحديث Flag تلقائياً.'];
}

function hr_attendance_mdb_mark_checkinout_synced(string $mdbPath, int $userId, mixed $checkTimeRaw): bool
{
    if ($userId < 1) {
        return false;
    }

    hr_attendance_mdb_set_last_write_error(null);
    $mdbPath = hr_attendance_assert_mdb_readable($mdbPath);
    $checkParam = hr_attendance_mdb_checktime_param($checkTimeRaw);
    if ($checkParam === '' || $checkParam === null) {
        hr_attendance_mdb_set_last_write_error('تعذر قراءة وقت البصمة');

        return false;
    }

    $errors = [];

    if (hr_attendance_com_available()) {
        try {
            hr_attendance_mdb_mark_checkinout_synced_com($mdbPath, $userId, $checkTimeRaw);

            return true;
        } catch (Throwable $e) {
            $errors[] = 'COM: ' . $e->getMessage();
        }
    }

    if (hr_attendance_pdo_odbc_available()) {
        try {
            hr_attendance_mdb_mark_checkinout_synced_odbc($mdbPath, $userId, $checkTimeRaw);

            return true;
        } catch (Throwable $e) {
            $errors[] = 'ODBC: ' . $e->getMessage();
        }
    }

    hr_attendance_mdb_set_last_write_error(
        $errors !== []
            ? implode(' | ', array_slice($errors, 0, 2))
              . ' — أغلق ZKT. إن استمر الخطأ: انقل att2000.mdb لمجلد قابل للكتابة'
              . ' (مثل C:\\ZKTData) وحدّث مساره في ZKT وManager، أو امنح Apache صلاحية الكتابة على الملف.'
            : 'لا يتوفر COM أو ODBC للكتابة على Access'
    );

    return false;
}

/** @return array{ok:bool,updated:int,message:string} */
function hr_attendance_mdb_mark_all_pending_flags(string $mdbPath): array
{
    hr_attendance_mdb_set_last_write_error(null);
    $mdbPath = hr_attendance_assert_mdb_readable($mdbPath);

    if (!hr_attendance_mdb_checkinout_has_flag($mdbPath)) {
        return ['ok' => false, 'updated' => 0, 'message' => 'حقل Flag غير موجود في CHECKINOUT.'];
    }

    $pending = hr_attendance_mdb_count_unsynced_punches($mdbPath);
    if ($pending < 1) {
        return ['ok' => true, 'updated' => 0, 'message' => 'لا توجد سجلات Flag=0.'];
    }

    $flagCol = hr_attendance_mdb_sync_flag_col();
    $where = hr_attendance_mdb_sync_flag_pending_sql();
    $errors = [];

    if (hr_attendance_com_available()) {
        try {
            $conn = hr_attendance_mdb_com_open_path($mdbPath, true);
            $conn->Execute('UPDATE [CHECKINOUT] SET ' . $flagCol . ' = 1 WHERE ' . $where);

            return [
                'ok' => true,
                'updated' => $pending,
                'message' => 'تم تعليم ' . $pending . ' سجل Flag=1 في Access.',
            ];
        } catch (Throwable $e) {
            $errors[] = 'COM: ' . $e->getMessage();
        }
    }

    if (hr_attendance_pdo_odbc_available()) {
        try {
            $mdb = hr_attendance_mdb_open_pdo($mdbPath, true);
            $mdb->exec('UPDATE [CHECKINOUT] SET ' . $flagCol . ' = 1 WHERE ' . $where);

            return [
                'ok' => true,
                'updated' => $pending,
                'message' => 'تم تعليم ' . $pending . ' سجل Flag=1 في Access.',
            ];
        } catch (Throwable $e) {
            $errors[] = 'ODBC: ' . $e->getMessage();
        }
    }

    $msg = $errors !== [] ? implode(' | ', array_slice($errors, 0, 2)) : 'تعذر الكتابة على Access';

    return ['ok' => false, 'updated' => 0, 'message' => $msg];
}

/**
 * @return list<array<string,mixed>>
 */
function hr_attendance_mdb_fetch_all_punches(string $mdbPath): array
{
    $mdbPath = hr_attendance_assert_mdb_readable($mdbPath);
    $sql = hr_attendance_mdb_punch_select_sql(false);

    if (hr_attendance_pdo_odbc_available()) {
        $mdb = hr_attendance_mdb_open_pdo($mdbPath);

        return $mdb->query($sql['odbc'])->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if (hr_attendance_com_available()) {
        $conn = hr_attendance_mdb_com_open_path($mdbPath);

        return hr_attendance_mdb_com_fetch_all($conn, $sql['com']);
    }

    if (hr_attendance_mdbtools_available()) {
        return hr_attendance_mdbtools_fetch_punches_joined($mdbPath);
    }

    throw new RuntimeException(
        hr_attendance_is_linux_server()
            ? 'على Linux: ارفع att2000.mdb ثم ثبّت mdbtools (mdb-export).'
            : 'فعّل pdo_odbc أو com_dotnet لقراءة att2000.mdb.'
    );
}

/**
 * @return array{rows:list<array<string,mixed>>,access_total:int,candidates:int,use_flag:bool,pending_flag:int}
 */
function hr_attendance_mdb_fetch_punches_for_sync(PDO $pdo, string $mdbPath, bool $useFlag = false): array
{
    $useFlag = $useFlag && hr_attendance_mdb_checkinout_has_flag($mdbPath);
    $pendingFlag = 0;

    if ($useFlag) {
        $allRows = hr_attendance_mdb_fetch_unsynced_punches($mdbPath);
        $pendingFlag = count($allRows);
        $filtered = $allRows;
    } else {
        $existingKeys = hr_attendance_existing_source_keys($pdo);
        $allRows = hr_attendance_mdb_fetch_all_punches($mdbPath);
        $filtered = [];

        foreach ($allRows as $row) {
            $zkUserId = (int) ($row['USERID'] ?? 0);
            if ($zkUserId < 1) {
                continue;
            }

            $punchTime = hr_attendance_parse_checktime($row['CHECKTIME'] ?? null);
            if ($punchTime !== null) {
                $sourceKey = hr_attendance_build_source_key($zkUserId, $punchTime);
                if (isset($existingKeys[$sourceKey])) {
                    continue;
                }
            }

            $filtered[] = $row;
        }
    }

    $accessTotal = 0;
    try {
        $stats = hr_attendance_mdb_stats($mdbPath);
        $accessTotal = (int) ($stats['checkinout_count'] ?? 0);
    } catch (Throwable $e) {
        $accessTotal = count($allRows ?? hr_attendance_mdb_fetch_all_punches($mdbPath));
    }
    if ($useFlag && $pendingFlag === 0) {
        try {
            $pendingFlag = hr_attendance_mdb_count_unsynced_punches($mdbPath);
        } catch (Throwable $e) {
            $pendingFlag = 0;
        }
    }

    $merged = hr_attendance_merge_punch_rows($filtered);

    return [
        'rows' => $merged,
        'access_total' => $accessTotal,
        'candidates' => count($merged),
        'use_flag' => $useFlag,
        'pending_flag' => $pendingFlag,
    ];
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

    if (hr_attendance_com_available()) {
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

    if (hr_attendance_mdbtools_available()) {
        $sinceTs = strtotime($since);
        $all = hr_attendance_mdbtools_fetch_punches_joined($mdbPath);
        if ($sinceTs === false) {
            return $all;
        }

        return array_values(array_filter($all, static function (array $row) use ($sinceTs): bool {
            $parsed = hr_attendance_parse_checktime($row['CHECKTIME'] ?? null);
            if ($parsed === null) {
                return false;
            }
            $ts = strtotime($parsed);

            return $ts !== false && $ts > $sinceTs;
        }));
    }

    throw new RuntimeException('لا يمكن قراءة att2000.mdb — فعّل ODBC/COM أو mdbtools.');
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

    if (hr_attendance_com_available()) {
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

    if (hr_attendance_mdbtools_available()) {
        $checkins = hr_attendance_mdbtools_export_table($mdbPath, 'CHECKINOUT');
        $users = hr_attendance_mdbtools_export_table($mdbPath, 'USERINFO');

        return [
            'checkinout_count' => count($checkins),
            'userinfo_count' => count($users),
            'driver' => 'mdbtools (Linux — قراءة فقط)',
        ];
    }

    throw new RuntimeException(
        hr_attendance_is_linux_server()
            ? 'على Linux: ارفع att2000.mdb إلى ' . hr_attendance_recommended_mdb_path()
            . ' وثبّت mdbtools، أو نفّذ المزامنة من Windows (XAMPP).'
            : 'فعّل pdo_odbc أو com_dotnet في php.ini.'
    );
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

    if (hr_attendance_com_available()) {
        $conn = hr_attendance_mdb_com_open($mdbPath);
        $sql = 'SELECT BADGENUMBER, NAME FROM USERINFO WHERE USERID = ' . $zkUserId;
        $rows = hr_attendance_mdb_com_fetch_all($conn, $sql);

        return $rows[0] ?? null;
    }

    if (hr_attendance_mdbtools_available()) {
        $users = hr_attendance_mdbtools_export_table($mdbPath, 'USERINFO');
        foreach ($users as $user) {
            if ((int) ($user['USERID'] ?? 0) === $zkUserId) {
                return [
                    'BADGENUMBER' => $user['BADGENUMBER'] ?? '',
                    'NAME' => $user['NAME'] ?? '',
                ];
            }
        }

        return null;
    }

    throw new RuntimeException('لا يمكن قراءة att2000.mdb — فعّل ODBC/COM أو mdbtools.');
}

/** @return array{ok:bool,message:string,checkinout_count:int,userinfo_count:int} */
function hr_attendance_test_mdb(string $mdbPath): array
{
    try {
        $stats = hr_attendance_mdb_stats($mdbPath);
        $msg = 'الاتصال ناجح (' . $stats['driver'] . ') — سجلات حضور: '
            . $stats['checkinout_count'] . '، موظفين في البصمة: ' . $stats['userinfo_count'];
        if (hr_attendance_mdb_checkinout_has_flag($mdbPath)) {
            $msg .= ' — حقل Flag موجود (مزامنة سريعة)';
            if (hr_attendance_mdbtools_available() && !hr_attendance_pdo_odbc_available() && !hr_attendance_com_available()) {
                $msg .= ' — قراءة فقط على Linux (لا يُحدَّث Flag في Access)';
            } else {
                $writeTest = hr_attendance_mdb_test_write_access($mdbPath);
                $msg .= $writeTest['ok']
                    ? ' — ' . $writeTest['message']
                    : ' — تحذير: ' . $writeTest['message'];
            }
        }

        return [
            'ok' => true,
            'message' => $msg,
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

function hr_attendance_save_manual_map(PDO $pdo, int $zkUserId, int $employeeId, bool $allowInactive = false): void
{
    if ($zkUserId < 1) {
        throw new RuntimeException('رقم مستخدم البصمة غير صالح.');
    }
    if ($employeeId < 1) {
        throw new RuntimeException('أدخل رقم الموظف من النظام.');
    }

    $st = $pdo->prepare(
        $allowInactive
            ? 'SELECT id FROM hr_employee WHERE id = ? LIMIT 1'
            : 'SELECT id FROM hr_employee WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $st->execute([$employeeId]);
    if (!$st->fetchColumn()) {
        throw new RuntimeException($allowInactive ? 'الموظف غير موجود.' : 'الموظف غير موجود أو غير نشط.');
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
            . ' مربوط مسبقاً برقم بصمة آخر'
            . ($otherBadge !== '' ? ' (' . $otherBadge . ')' : '')
            . '. أزل الربط القديم أولاً أو اختر رقم موظف آخر.'
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

    $pdo->prepare('UPDATE hr_att_punch SET employee_id = ? WHERE zk_user_id = ?')
        ->execute([$employeeId, $zkUserId]);
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
            $badge = hr_attendance_badge_for_zk_user($pdo, $zkUserId);
            $label = $badge !== '' ? 'رقم البصمة ' . $badge : 'ZK #' . $zkUserId;
            $errors[] = $label . ': ' . ($msg !== '' ? $msg : 'تعذر الربط');
        }
    }

    if ($saved < 1 && $errors === []) {
        throw new RuntimeException('اختر موظفاً ورقم بصمة واحداً على الأقل للربط.');
    }

    return ['saved' => $saved, 'errors' => $errors];
}

function hr_attendance_employee_id_by_emp_code(PDO $pdo, string $empCode): ?int
{
    $empCode = trim($empCode);
    if ($empCode === '') {
        return null;
    }

    $employeeId = hr_attendance_find_employee_id($pdo, 0, $empCode);

    return $employeeId !== null && $employeeId > 0 ? $employeeId : null;
}

function hr_attendance_badge_for_zk_user(PDO $pdo, int $zkUserId): string
{
    if ($zkUserId < 1) {
        return '';
    }
    hr_attendance_ensure_schema($pdo);

    $st = $pdo->prepare('SELECT badge_number FROM hr_att_employee_map WHERE zk_user_id = ? LIMIT 1');
    $st->execute([$zkUserId]);
    $badge = trim((string) ($st->fetchColumn() ?: ''));
    if ($badge !== '') {
        return $badge;
    }

    $st = $pdo->prepare(
        'SELECT badge_number FROM hr_att_punch
         WHERE zk_user_id = ? AND badge_number IS NOT NULL AND TRIM(badge_number) <> \'\'
         ORDER BY punch_time DESC
         LIMIT 1'
    );
    $st->execute([$zkUserId]);

    return trim((string) ($st->fetchColumn() ?: ''));
}

function hr_attendance_badge_matches_emp_code(string $badge, string $empCode): bool
{
    $badge = trim($badge);
    $empCode = trim($empCode);
    if ($badge === '' || $empCode === '') {
        return false;
    }

    return hr_attendance_normalize_badge($badge) === hr_attendance_normalize_badge($empCode)
        || $badge === $empCode;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function hr_attendance_enrich_unmapped_rows(PDO $pdo, array $rows): array
{
    foreach ($rows as &$row) {
        $badge = trim((string) ($row['badge_number'] ?? ''));
        $employeeId = hr_attendance_find_employee_id($pdo, 0, $badge);
        $row['suggested_emp_code'] = '';
        $row['suggested_emp_name'] = '';
        if ($employeeId !== null && $employeeId > 0) {
            $st = $pdo->prepare('SELECT emp_code, name_ar FROM hr_employee WHERE id = ? LIMIT 1');
            $st->execute([$employeeId]);
            $emp = $st->fetch(PDO::FETCH_ASSOC);
            if ($emp) {
                $row['suggested_emp_code'] = trim((string) ($emp['emp_code'] ?? ''));
                $row['suggested_emp_name'] = trim((string) ($emp['name_ar'] ?? ''));
            }
        }
    }
    unset($row);

    return $rows;
}

function hr_attendance_save_manual_map_by_emp_code(PDO $pdo, int $zkUserId, string $empCode): void
{
    $employeeId = hr_attendance_employee_id_by_emp_code($pdo, $empCode);
    if ($employeeId === null || $employeeId < 1) {
        throw new RuntimeException('رقم الموظف «' . trim($empCode) . '» غير موجود أو غير نشط في النظام.');
    }

    hr_attendance_save_manual_map($pdo, $zkUserId, $employeeId);
}

/**
 * @param array<int|string,string> $empCodes zk_user_id => emp_code
 * @return array{saved:int,errors:list<string>}
 */
function hr_attendance_save_manual_maps_by_emp_code_batch(PDO $pdo, array $empCodes): array
{
    $saved = 0;
    $errors = [];

    foreach ($empCodes as $zkUserId => $empCode) {
        $zkUserId = (int) $zkUserId;
        $empCode = trim((string) $empCode);
        if ($zkUserId < 1 || $empCode === '') {
            continue;
        }
        try {
            hr_attendance_save_manual_map_by_emp_code($pdo, $zkUserId, $empCode);
            $saved++;
        } catch (Throwable $e) {
            $badge = hr_attendance_badge_for_zk_user($pdo, $zkUserId);
            $label = $badge !== '' ? 'رقم البصمة ' . $badge : 'ZK #' . $zkUserId;
            $msg = trim($e->getMessage());
            $errors[] = $label . ': ' . ($msg !== '' ? $msg : 'تعذر الربط');
        }
    }

    if ($saved < 1 && $errors === []) {
        throw new RuntimeException('أدخل رقم موظف واحداً على الأقل للربط.');
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

function hr_attendance_sync_token_generate(): string
{
    return bin2hex(random_bytes(32));
}

function hr_attendance_sync_token_ensure(PDO $pdo): string
{
    hr_attendance_ensure_schema($pdo);
    hr_attendance_ensure_sync_token_column($pdo);
    $cfg = hr_attendance_load_config($pdo);
    if ($cfg['sync_token'] !== null && $cfg['sync_token'] !== '') {
        return $cfg['sync_token'];
    }

    return hr_attendance_sync_token_regenerate($pdo);
}

function hr_attendance_sync_token_regenerate(PDO $pdo): string
{
    hr_attendance_ensure_schema($pdo);
    hr_attendance_ensure_sync_token_column($pdo);
    $token = hr_attendance_sync_token_generate();
    $st = $pdo->prepare(
        'INSERT INTO hr_att_config (id, mdb_path, sync_token) VALUES (1, ?, ?)
         ON DUPLICATE KEY UPDATE sync_token = VALUES(sync_token)'
    );
    $marker = hr_attendance_uses_remote_agent()
        ? hr_attendance_remote_agent_marker()
        : 'C:\\Program Files (x86)\\ZKTeco\\att2000.mdb';
    $st->execute([$marker, $token]);

    return $token;
}

function hr_attendance_verify_sync_token(PDO $pdo, string $token): bool
{
    $token = trim($token);
    if ($token === '' || strlen($token) < 32) {
        return false;
    }
    hr_attendance_ensure_schema($pdo);
    hr_attendance_ensure_sync_token_column($pdo);
    $st = $pdo->prepare('SELECT sync_token FROM hr_att_config WHERE id = 1 LIMIT 1');
    $st->execute();
    $saved = $st->fetchColumn();

    return is_string($saved) && $saved !== '' && hash_equals($saved, $token);
}

function hr_attendance_push_api_url(): string
{
    if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        return app_absolute_url('api/hr_attendance_push.php');
    }

    return app_url('api/hr_attendance_push.php');
}

/**
 * @param list<array<string,mixed>> $rows
 * @return array{inserted:int,skipped:int,unlinked:int,last_punch_time:?string,message:string,source_keys_inserted:list<string>}
 */
function hr_attendance_push_punches(PDO $pdo, array $rows): array
{
    @set_time_limit(300);
    hr_attendance_ensure_schema($pdo);
    $cfg = hr_attendance_load_config($pdo);

    $insertSt = $pdo->prepare(
        'INSERT IGNORE INTO hr_att_punch
            (employee_id, zk_user_id, badge_number, zk_name, punch_time, punch_type, verify_code, sensor_id, source_key)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $inserted = 0;
    $skipped = 0;
    $unlinked = 0;
    $parseFailed = 0;
    $sourceKeysInserted = [];
    $sourceKeysProcessed = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $zkUserId = (int) ($row['USERID'] ?? $row['zk_user_id'] ?? $row['userid'] ?? 0);
        if ($zkUserId < 1) {
            continue;
        }

        $punchTime = hr_attendance_parse_checktime($row['CHECKTIME'] ?? $row['checktime'] ?? $row['punch_time'] ?? null);
        if ($punchTime === null) {
            $parseFailed++;
            continue;
        }

        $sourceKey = hr_attendance_build_source_key($zkUserId, $punchTime);
        $badge = trim((string) ($row['BADGENUMBER'] ?? $row['badgenumber'] ?? $row['badge_number'] ?? ''));
        $zkName = trim((string) ($row['NAME'] ?? $row['name'] ?? $row['zk_name'] ?? ''));
        $employeeId = hr_attendance_find_employee_id($pdo, $zkUserId, $badge);
        if ($employeeId !== null && $employeeId > 0) {
            hr_attendance_upsert_map($pdo, $zkUserId, $employeeId, $badge, $zkName);
        } else {
            $unlinked++;
        }

        $punchType = hr_attendance_resolve_punch_type($row);
        $insertSt->execute([
            $employeeId > 0 ? $employeeId : null,
            $zkUserId,
            $badge !== '' ? $badge : null,
            $zkName !== '' ? $zkName : null,
            $punchTime,
            $punchType,
            isset($row['VERIFYCODE']) || isset($row['verifycode'])
                ? (int) ($row['VERIFYCODE'] ?? $row['verifycode'])
                : null,
            isset($row['SENSORID']) || isset($row['sensorid'])
                ? trim((string) ($row['SENSORID'] ?? $row['sensorid']))
                : null,
            $sourceKey,
        ]);

        if ($insertSt->rowCount() > 0) {
            $inserted++;
            $sourceKeysInserted[] = $sourceKey;
        } else {
            $skipped++;
        }
        $sourceKeysProcessed[] = $sourceKey;
    }

    $now = date('Y-m-d H:i:s');
    $newLastPunch = $pdo->query('SELECT MAX(punch_time) FROM hr_att_punch')->fetchColumn();
    $newLastPunch = ($newLastPunch !== false && (string) $newLastPunch !== '')
        ? (string) $newLastPunch
        : $cfg['last_punch_time'];

    $upd = $pdo->prepare(
        'UPDATE hr_att_config SET last_sync_at = ?, last_punch_time = ? WHERE id = 1'
    );
    $upd->execute([$now, $newLastPunch]);

    $msg = 'استلام من جهاز البصمة: ' . $inserted . ' سجل جديد';
    if ($skipped > 0) {
        $msg .= '، ' . $skipped . ' موجود مسبقاً';
    }
    if ($unlinked > 0) {
        $msg .= '، ' . $unlinked . ' غير مربوط بموظف';
    }
    if ($parseFailed > 0) {
        $msg .= '، ' . $parseFailed . ' تخطّى (صيغة وقت غير مقروءة)';
    }

    return [
        'inserted' => $inserted,
        'skipped' => $skipped,
        'unlinked' => $unlinked,
        'parse_failed' => $parseFailed,
        'last_punch_time' => $newLastPunch,
        'message' => $msg,
        'source_keys_inserted' => $sourceKeysInserted,
        'source_keys_processed' => $sourceKeysProcessed,
    ];
}

/**
 * @return array{inserted:int,skipped:int,unlinked:int,last_punch_time:?string,message:string}
 */
function hr_attendance_sync(PDO $pdo, bool $forceLocalScreen = false): array
{
    if (!$forceLocalScreen && hr_attendance_uses_remote_agent()) {
        throw new RuntimeException(
            'المزامنة المباشرة من السيرفر غير متاحة — شغّل وكيل المزامنة على جهاز ZKT (Windows).'
        );
    }
    @set_time_limit(600);
    hr_attendance_ensure_schema($pdo);
    $cfg = hr_attendance_load_config($pdo);
    $mdbPath = hr_attendance_normalize_mdb_path($cfg['mdb_path']);
    $readPath = hr_attendance_mdb_prepare_sync_path($mdbPath);
    $fetch = hr_attendance_mdb_fetch_punches_for_sync($pdo, $readPath, true);
    $rows = $fetch['rows'];
    $accessTotal = (int) ($fetch['access_total'] ?? 0);
    $candidates = (int) ($fetch['candidates'] ?? 0);
    $useFlag = !empty($fetch['use_flag']);
    $pendingFlag = (int) ($fetch['pending_flag'] ?? 0);
    $existingKeys = $useFlag ? hr_attendance_existing_source_keys($pdo) : [];

    $insertSt = $pdo->prepare(
        'INSERT IGNORE INTO hr_att_punch
            (employee_id, zk_user_id, badge_number, zk_name, punch_time, punch_type, verify_code, sensor_id, source_key)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $inserted = 0;
    $skipped = 0;
    $unlinked = 0;
    $parseFailed = 0;
    $flagsUpdated = 0;
    $flagsFailed = 0;
    $rowsToMark = [];
    $newRowCount = 0;

    foreach ($rows as $row) {
        $zkUserId = (int) ($row['USERID'] ?? 0);
        if ($zkUserId < 1) {
            continue;
        }

        $punchTime = hr_attendance_parse_checktime($row['CHECKTIME'] ?? null);
        if ($punchTime === null) {
            $parseFailed++;
            continue;
        }

        $sourceKey = hr_attendance_build_source_key($zkUserId, $punchTime);
        $alreadyInMysql = $useFlag && isset($existingKeys[$sourceKey]);

        if ($alreadyInMysql) {
            $skipped++;
            $rowsToMark[] = $row;
            continue;
        }

        $newRowCount++;
        $badge = trim((string) ($row['BADGENUMBER'] ?? ''));
        $zkName = trim((string) ($row['NAME'] ?? ''));
        $employeeId = hr_attendance_find_employee_id($pdo, $zkUserId, $badge);
        if ($employeeId !== null && $employeeId > 0) {
            hr_attendance_upsert_map($pdo, $zkUserId, $employeeId, $badge, $zkName);
        } else {
            $unlinked++;
        }

        $punchType = hr_attendance_resolve_punch_type($row);
        $insertSt->execute([
            $employeeId > 0 ? $employeeId : null,
            $zkUserId,
            $badge !== '' ? $badge : null,
            $zkName !== '' ? $zkName : null,
            $punchTime,
            $punchType,
            isset($row['VERIFYCODE']) ? (int) $row['VERIFYCODE'] : null,
            isset($row['SENSORID']) ? trim((string) $row['SENSORID']) : null,
            $sourceKey,
        ]);

        if ($insertSt->rowCount() > 0) {
            $inserted++;
            $existingKeys[$sourceKey] = true;
            if ($useFlag) {
                $rowsToMark[] = $row;
            }
        } else {
            $skipped++;
            if ($useFlag) {
                $rowsToMark[] = $row;
            }
        }
    }

    if ($useFlag && $rowsToMark !== []) {
        $canBulkMark = $newRowCount === 0
            || ($parseFailed === 0 && $inserted === $newRowCount);
        if ($canBulkMark && count($rowsToMark) > 50) {
            $bulk = hr_attendance_mdb_mark_all_pending_flags($mdbPath);
            if ($bulk['ok']) {
                $flagsUpdated = (int) ($bulk['updated'] ?? count($rowsToMark));
            } else {
                $flagsFailed = count($rowsToMark);
                hr_attendance_mdb_set_last_write_error($bulk['message']);
            }
        } else {
            $writeConn = null;
            if (hr_attendance_com_available()) {
                try {
                    $writeConn = hr_attendance_mdb_com_open_path($mdbPath, true);
                } catch (Throwable $e) {
                    $writeConn = null;
                }
            }
            foreach ($rowsToMark as $markRow) {
                $uid = (int) ($markRow['USERID'] ?? 0);
                if ($uid < 1) {
                    continue;
                }
                $ok = false;
                if ($writeConn instanceof COM) {
                    try {
                        hr_attendance_mdb_mark_checkinout_synced_com_conn(
                            $writeConn,
                            $uid,
                            $markRow['CHECKTIME'] ?? null
                        );
                        $ok = true;
                    } catch (Throwable $e) {
                        hr_attendance_mdb_set_last_write_error($e->getMessage());
                    }
                } elseif (hr_attendance_mdb_mark_checkinout_synced($mdbPath, $uid, $markRow['CHECKTIME'] ?? null)) {
                    $ok = true;
                }
                if ($ok) {
                    $flagsUpdated++;
                } else {
                    $flagsFailed++;
                }
            }
        }
    }

    $now = date('Y-m-d H:i:s');
    $newLastPunch = $pdo->query('SELECT MAX(punch_time) FROM hr_att_punch')->fetchColumn();
    $newLastPunch = ($newLastPunch !== false && (string) $newLastPunch !== '')
        ? (string) $newLastPunch
        : $cfg['last_punch_time'];

    $upd = $pdo->prepare(
        'UPDATE hr_att_config SET last_sync_at = ?, last_punch_time = ? WHERE id = 1'
    );
    $upd->execute([$now, $newLastPunch]);

    $msg = 'تمت المزامنة: ' . $inserted . ' سجل جديد';
    if ($useFlag) {
        $msg .= ' — وضع Flag: ' . $pendingFlag . ' بانتظار النقل';
        if ($flagsUpdated > 0) {
            $msg .= '، ' . $flagsUpdated . ' تم تعليمها Flag=1 في Access';
        }
        if ($flagsFailed > 0) {
            $writeErr = hr_attendance_mdb_last_write_error();
            $msg .= '، ' . $flagsFailed . ' لم يُحدَّث Flag في Access';
            if ($writeErr !== null) {
                $msg .= ' — ' . $writeErr;
            } else {
                $msg .= ' (أغلق ZKT وتحقق من صلاحية الكتابة على الملف)';
            }
        }
    } else {
        $msg .= ' — Access: ' . $accessTotal . ' بصمة، مرشّح للإدخال: ' . $candidates;
    }
    if ($skipped > 0) {
        $msg .= '، ' . $skipped . ' موجود مسبقاً';
    }
    if ($unlinked > 0) {
        $msg .= '، ' . $unlinked . ' غير مربوط بموظف';
    }
    if ($parseFailed > 0) {
        $msg .= '، ' . $parseFailed . ' تخطّى (صيغة وقت غير مقروءة)';
    }
    if (hr_attendance_mdb_last_via_snapshot()) {
        $msg .= ' — تمت القراءة من نسخة مؤقتة؛ أغلق برنامج ZKT ثم أعد المزامنة للحصول على آخر البيانات.';
    } elseif ($accessTotal < 1) {
        $msg .= ' — لم تُقرأ أي بصمة من Access؛ تحقق من مسار att2000.mdb.';
    } elseif (!$useFlag && $candidates < 1 && $accessTotal > 0) {
        $msg .= ' — جميع بصمات Access موجودة مسبقاً في MySQL.';
    } elseif ($useFlag && $pendingFlag < 1 && $inserted < 1) {
        $msg .= ' — لا توجد حركات جديدة (flag=0).';
    }

    return [
        'inserted' => $inserted,
        'skipped' => $skipped,
        'unlinked' => $unlinked,
        'last_punch_time' => $newLastPunch,
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
function hr_attendance_list_mapped_users(PDO $pdo, int $limit = 500): array
{
    hr_attendance_ensure_schema($pdo);
    $limit = max(1, min(1000, $limit));

    $sql = 'SELECT m.zk_user_id,
                   m.badge_number,
                   m.zk_name,
                   m.employee_id,
                   e.emp_code,
                   e.name_ar AS emp_name,
                   (SELECT COUNT(*) FROM hr_att_punch p WHERE p.zk_user_id = m.zk_user_id) AS punch_count,
                   (SELECT MAX(p.punch_time) FROM hr_att_punch p WHERE p.zk_user_id = m.zk_user_id) AS last_punch
            FROM hr_att_employee_map m
            INNER JOIN hr_employee e ON e.id = m.employee_id
            ORDER BY e.name_ar ASC, m.zk_user_id ASC
            LIMIT ' . $limit;

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function hr_attendance_delete_map(PDO $pdo, int $zkUserId): void
{
    if ($zkUserId < 1) {
        throw new RuntimeException('رقم مستخدم البصمة غير صالح.');
    }
    hr_attendance_ensure_schema($pdo);

    $st = $pdo->prepare('SELECT id FROM hr_att_employee_map WHERE zk_user_id = ? LIMIT 1');
    $st->execute([$zkUserId]);
    if (!$st->fetchColumn()) {
        throw new RuntimeException('لا يوجد ربط لهذا المستخدم.');
    }

    $pdo->prepare('DELETE FROM hr_att_employee_map WHERE zk_user_id = ?')->execute([$zkUserId]);
    $pdo->prepare('UPDATE hr_att_punch SET employee_id = NULL WHERE zk_user_id = ?')->execute([$zkUserId]);
}

/**
 * @return list<array{USERID?:mixed,BADGENUMBER?:string,NAME?:string}>
 */
function hr_attendance_mdb_fetch_userinfo_list(string $mdbPath, int $limit = 2000): array
{
    $mdbPath = hr_attendance_assert_mdb_readable($mdbPath);
    $limit = max(1, min(5000, $limit));

    if (hr_attendance_pdo_odbc_available()) {
        try {
            $mdb = hr_attendance_mdb_connect($mdbPath);
            $rows = $mdb->query(
                'SELECT USERID, BADGENUMBER, NAME FROM USERINFO WHERE USERID > 0 ORDER BY USERID ASC'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return array_slice($rows, 0, $limit);
        } catch (Throwable $e) {
            if (!hr_attendance_com_available()) {
                throw $e;
            }
        }
    }

    $conn = hr_attendance_mdb_com_open($mdbPath);
    $rows = hr_attendance_mdb_com_fetch_all(
        $conn,
        'SELECT USERID, BADGENUMBER, NAME FROM USERINFO WHERE USERID > 0 ORDER BY USERID ASC'
    );

    return array_slice($rows, 0, $limit);
}

/**
 * مستخدمو البصمة غير المربوطين (لقائمة الربط).
 *
 * @return list<array<string,mixed>>
 */
function hr_attendance_zk_users_for_link(PDO $pdo, int $limit = 500): array
{
    hr_attendance_ensure_schema($pdo);
    $limit = max(1, min(500, $limit));

    $mappedZk = [];
    foreach ($pdo->query('SELECT zk_user_id FROM hr_att_employee_map')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $mappedZk[$id] = true;
        }
    }

    $byZk = [];
    foreach (hr_attendance_unmapped_zk_users($pdo, $limit) as $row) {
        $zk = (int) ($row['zk_user_id'] ?? 0);
        if ($zk > 0 && !isset($mappedZk[$zk])) {
            $byZk[$zk] = $row;
        }
    }

    try {
        $cfg = hr_attendance_load_config($pdo);
        $path = hr_attendance_normalize_mdb_path((string) ($cfg['mdb_path'] ?? ''));
        if ($path !== '' && is_file($path)) {
            foreach (hr_attendance_mdb_fetch_userinfo_list($path, $limit) as $ur) {
                $zk = (int) ($ur['USERID'] ?? 0);
                if ($zk < 1 || isset($mappedZk[$zk])) {
                    continue;
                }
                if (!isset($byZk[$zk])) {
                    $byZk[$zk] = [
                        'zk_user_id' => $zk,
                        'badge_number' => trim((string) ($ur['BADGENUMBER'] ?? '')),
                        'zk_name' => trim((string) ($ur['NAME'] ?? '')),
                        'punch_count' => 0,
                        'last_punch' => null,
                    ];
                }
            }
        }
    } catch (Throwable $e) {
        // قائمة Access اختيارية
    }

    $rows = array_values($byZk);
    usort($rows, static function (array $a, array $b): int {
        $ba = trim((string) ($a['badge_number'] ?? ''));
        $bb = trim((string) ($b['badge_number'] ?? ''));
        if ($ba !== $bb) {
            return strnatcmp($ba, $bb);
        }

        return ((int) ($a['zk_user_id'] ?? 0)) <=> ((int) ($b['zk_user_id'] ?? 0));
    });

    return array_slice($rows, 0, $limit);
}

/** @return array{zk_user_id:int,badge_number:string,zk_name:string}|null */
function hr_attendance_employee_zk_map(PDO $pdo, int $employeeId): ?array
{
    if ($employeeId < 1) {
        return null;
    }
    hr_attendance_ensure_schema($pdo);

    $st = $pdo->prepare(
        'SELECT zk_user_id, badge_number, zk_name
         FROM hr_att_employee_map
         WHERE employee_id = ?
         LIMIT 1'
    );
    $st->execute([$employeeId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'zk_user_id' => (int) ($row['zk_user_id'] ?? 0),
        'badge_number' => trim((string) ($row['badge_number'] ?? '')),
        'zk_name' => trim((string) ($row['zk_name'] ?? '')),
    ];
}

function hr_attendance_clear_employee_maps(PDO $pdo, int $employeeId): void
{
    if ($employeeId < 1) {
        return;
    }
    hr_attendance_ensure_schema($pdo);

    $st = $pdo->prepare('SELECT zk_user_id FROM hr_att_employee_map WHERE employee_id = ?');
    $st->execute([$employeeId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $zkUserId) {
        $zkUserId = (int) $zkUserId;
        if ($zkUserId < 1) {
            continue;
        }
        $pdo->prepare('DELETE FROM hr_att_employee_map WHERE zk_user_id = ?')->execute([$zkUserId]);
        $pdo->prepare('UPDATE hr_att_punch SET employee_id = NULL WHERE zk_user_id = ?')->execute([$zkUserId]);
    }
}

function hr_attendance_apply_employee_badge_link(PDO $pdo, int $employeeId, int $zkUserId): void
{
    if ($employeeId < 1) {
        return;
    }

    $current = hr_attendance_employee_zk_map($pdo, $employeeId);
    $currentZk = $current ? (int) ($current['zk_user_id'] ?? 0) : 0;

    if ($zkUserId < 1) {
        if ($currentZk > 0) {
            hr_attendance_clear_employee_maps($pdo, $employeeId);
        }

        return;
    }

    if ($zkUserId === $currentZk) {
        return;
    }

    if ($currentZk > 0) {
        hr_attendance_clear_employee_maps($pdo, $employeeId);
    }

    hr_attendance_save_manual_map($pdo, $zkUserId, $employeeId, true);
}

/**
 * أرقام البصمة المتاحة لبطاقة الموظف (غير المربوطة + الربط الحالي إن وُجد).
 *
 * @return list<array<string,mixed>>
 */
function hr_attendance_zk_users_for_employee_form(PDO $pdo, int $employeeId = 0, int $limit = 500): array
{
    $options = hr_attendance_zk_users_for_link($pdo, $limit);
    if ($employeeId < 1) {
        return $options;
    }

    $current = hr_attendance_employee_zk_map($pdo, $employeeId);
    if ($current === null) {
        return $options;
    }

    $currentZk = (int) ($current['zk_user_id'] ?? 0);
    if ($currentZk < 1) {
        return $options;
    }

    foreach ($options as $opt) {
        if ((int) ($opt['zk_user_id'] ?? 0) === $currentZk) {
            return $options;
        }
    }

    array_unshift($options, [
        'zk_user_id' => $currentZk,
        'badge_number' => (string) ($current['badge_number'] ?? ''),
        'zk_name' => (string) ($current['zk_name'] ?? ''),
        'punch_count' => 0,
        'last_punch' => null,
        'is_current' => true,
    ]);

    return $options;
}

function hr_attendance_link_label_employee(array $employee): string
{
    $code = trim((string) ($employee['emp_code'] ?? ''));
    $name = trim((string) ($employee['name_ar'] ?? ''));
    if ($code !== '' && $name !== '') {
        return $code . ' — ' . $name;
    }

    return $name !== '' ? $name : ($code !== '' ? $code : '—');
}

function hr_attendance_link_label_zk(array $zkUser): string
{
    $badge = trim((string) ($zkUser['badge_number'] ?? ''));
    $name = trim((string) ($zkUser['zk_name'] ?? ''));
    if ($badge !== '' && $name !== '') {
        return $badge . ' — ' . $name;
    }
    if ($badge !== '') {
        return $badge;
    }

    return 'ZK #' . (int) ($zkUser['zk_user_id'] ?? 0);
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
