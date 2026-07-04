<?php
declare(strict_types=1);

require_once app_path('includes/db.php');
require_once app_path('includes/date_defaults.php');

function sys_backup_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->query('SELECT id FROM sys_backup_settings LIMIT 1');
    } catch (Throwable $e) {
        if (
            str_contains($e->getMessage(), "doesn't exist")
            || str_contains($e->getMessage(), 'no such table')
            || str_contains($e->getMessage(), 'Base table or view not found')
        ) {
            try {
                require_once app_path('includes/sql_migration.php');
                sql_migration_run_file($pdo, 'database/migrations/142_sys_backup.sql');
            } catch (Throwable $e2) {
                try {
                    $pdo->exec(
                        "CREATE TABLE IF NOT EXISTS sys_backup_settings (
                            id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
                            backup_dir VARCHAR(500) NOT NULL DEFAULT '',
                            last_backup_at DATETIME NULL,
                            last_backup_path VARCHAR(500) NULL,
                            updated_by INT UNSIGNED NULL,
                            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                    );
                    $pdo->exec("INSERT IGNORE INTO sys_backup_settings (id, backup_dir) VALUES (1, '')");
                } catch (Throwable $e3) {
                    // ignored
                }
            }
        }
    }
}

/** @return array{backup_dir:string, last_backup_at:?string, last_backup_path:string} */
function sys_backup_settings(PDO $pdo): array
{
    sys_backup_ensure_schema($pdo);
    try {
        $row = $pdo->query('SELECT backup_dir, last_backup_at, last_backup_path FROM sys_backup_settings WHERE id = 1 LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $row = false;
    }

    return [
        'backup_dir' => trim((string) ($row['backup_dir'] ?? '')),
        'last_backup_at' => isset($row['last_backup_at']) && $row['last_backup_at'] !== null
            ? (string) $row['last_backup_at']
            : null,
        'last_backup_path' => trim((string) ($row['last_backup_path'] ?? '')),
    ];
}

function sys_backup_normalize_dir(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    if (preg_match('/^[a-zA-Z]:/', $path) || preg_match('/^(\\\\\\\\|\\/\\/)/', $path)) {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    } elseif (DIRECTORY_SEPARATOR === '/') {
        $path = str_replace('\\', '/', $path);
    } else {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    return rtrim($path, DIRECTORY_SEPARATOR);
}

/** إصلاح مسارات ناقصة مثل d:backup → d:/backup */
function sys_backup_prepare_input_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    if (preg_match('/^([a-zA-Z]:)([^\\\\\\/].*)$/', $path, $m)) {
        $path = $m[1] . DIRECTORY_SEPARATOR . $m[2];
    }

    return $path;
}

function sys_backup_is_windows_drive_path(string $path): bool
{
    return (bool) preg_match('/^[a-zA-Z]:/', $path);
}

function sys_backup_is_linux_server(): bool
{
    return DIRECTORY_SEPARATOR === '/';
}

function sys_backup_server_label(): string
{
    return sys_backup_is_linux_server() ? 'Linux' : 'Windows';
}

/** مسار مقترح يعمل على الخادم الحالي */
function sys_backup_recommended_dir(): string
{
    $appRoot = rtrim(app_path(''), DIRECTORY_SEPARATOR);
    $parent = dirname($appRoot);
    if ($parent !== '' && $parent !== '.' && $parent !== $appRoot) {
        return sys_backup_normalize_dir($parent . DIRECTORY_SEPARATOR . 'manager_backups');
    }

    return sys_backup_normalize_dir($appRoot . DIRECTORY_SEPARATOR . 'backups');
}

/** @return string|null رسالة تحذير إن كان المسار المحفوظ غير مناسب للخادم */
function sys_backup_path_issue(string $path): ?string
{
    $path = sys_backup_prepare_input_path($path);
    if ($path === '') {
        return null;
    }

    $norm = sys_backup_normalize_dir($path);
    if (sys_backup_is_linux_server() && sys_backup_is_windows_drive_path($norm)) {
        return 'المسار المحفوظ بصيغة Windows (مثل D:\\backup) لا يعمل على خادم Linux.'
            . ' استخدم مساراً مثل: ' . sys_backup_recommended_dir();
    }

    if (!sys_backup_is_absolute_path($norm)) {
        return 'المسار المحفوظ غير مطلق. أعد حفظ مسار كامل للمجلد.';
    }

    return null;
}

function sys_backup_is_absolute_path(string $path): bool
{
    if ($path === '') {
        return false;
    }

    if (preg_match('/^[a-zA-Z]:([\\\\\\/]|$)/', $path)) {
        return true;
    }

    if (preg_match('/^(\\\\\\\\|\\/\\/)[^\\\\\\/]+([\\\\\\/]|$)/', $path)) {
        return true;
    }

    return str_starts_with($path, '/');
}

function sys_backup_dir_is_within_root(string $dirReal, string $rootReal): bool
{
    $rootNorm = rtrim(str_replace('\\', '/', $rootReal), '/');
    $dirNorm = str_replace('\\', '/', $dirReal);
    if ($rootNorm === '' || $dirNorm === '') {
        return false;
    }

    return $dirNorm === $rootNorm || str_starts_with($dirNorm, $rootNorm . '/');
}

function sys_backup_ensure_dir_protected(string $path): void
{
    if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('تعذر إنشاء مجلد النسخ الاحتياطي: ' . $path);
    }

    $htaccess = $path . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Deny from all\n");
    }
}

/** @throws RuntimeException */
function sys_backup_validate_dir(string $path, bool $create = true): string
{
    $path = sys_backup_normalize_dir(sys_backup_prepare_input_path($path));
    if ($path === '') {
        throw new RuntimeException('حدّد مجلد النسخ الاحتياطي.');
    }

    if (!sys_backup_is_absolute_path($path)) {
        throw new RuntimeException(
            'يجب أن يكون المسار مطلقاً. Windows: D:\\Backups\\Manager — Linux: '
            . sys_backup_recommended_dir()
        );
    }

    if (sys_backup_is_linux_server() && sys_backup_is_windows_drive_path($path)) {
        throw new RuntimeException(
            'مسار Windows (مثل D:\\Backups) لا يعمل على خادم Linux. استخدم: '
            . sys_backup_recommended_dir()
        );
    }

    if (!is_dir($path)) {
        if (!$create) {
            throw new RuntimeException('مجلد النسخ الاحتياطي غير موجود: ' . $path);
        }
        sys_backup_ensure_dir_protected($path);
    }

    if (!is_writable($path)) {
        throw new RuntimeException('مجلد النسخ الاحتياطي غير قابل للكتابة: ' . $path);
    }

    $real = realpath($path);
    if ($real !== false) {
        return sys_backup_normalize_dir($real);
    }

    return $path;
}

function sys_backup_save_dir(PDO $pdo, string $path, int $userId = 0): void
{
    sys_backup_ensure_schema($pdo);
    $path = sys_backup_validate_dir($path, true);
    $st = $pdo->prepare(
        'UPDATE sys_backup_settings SET backup_dir = ?, updated_by = ?, updated_at = NOW() WHERE id = 1'
    );
    $st->execute([$path, $userId > 0 ? $userId : null]);
}

function sys_backup_today_folder_name(): string
{
    return app_today_ymd();
}

function sys_backup_find_mysqldump(): ?string
{
    $candidates = [
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
        'C:\\Program Files\\MariaDB 10.4\\bin\\mysqldump.exe',
        'mysqldump',
        'mysqldump.exe',
    ];

    foreach ($candidates as $bin) {
        if ($bin === 'mysqldump' || $bin === 'mysqldump.exe') {
            $out = [];
            $code = 1;
            @exec('where mysqldump 2>nul', $out, $code);
            if ($code === 0 && !empty($out[0])) {
                $found = trim((string) $out[0]);
                if ($found !== '' && is_file($found)) {
                    return $found;
                }
            }
            continue;
        }
        if (is_file($bin)) {
            return $bin;
        }
    }

    return null;
}

function sys_backup_run_mysqldump(array $cfg, string $outFile): bool
{
    $bin = sys_backup_find_mysqldump();
    if ($bin === null) {
        return false;
    }

    $host = (string) ($cfg['host'] ?? '127.0.0.1');
    $user = (string) ($cfg['user'] ?? 'root');
    $pass = (string) ($cfg['pass'] ?? '');
    $name = (string) ($cfg['name'] ?? '');

    $cmd = escapeshellarg($bin)
        . ' --host=' . escapeshellarg($host)
        . ' --user=' . escapeshellarg($user)
        . ' --password=' . escapeshellarg($pass)
        . ' --default-character-set=utf8mb4'
        . ' --single-transaction --routines --triggers --hex-blob'
        . ' ' . escapeshellarg($name)
        . ' > ' . escapeshellarg($outFile);

    if (DIRECTORY_SEPARATOR === '\\') {
        $cmd = 'cmd /C ' . $cmd;
    }

    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);

    return $code === 0 && is_file($outFile) && filesize($outFile) > 32;
}

function sys_backup_dump_database_pdo(PDO $pdo, string $outFile): void
{
    $cfg = require app_path('config/database.php');
    $dbName = (string) ($cfg['name'] ?? 'database');

    $fh = fopen($outFile, 'wb');
    if ($fh === false) {
        throw new RuntimeException('تعذر إنشاء ملف قاعدة البيانات.');
    }

    fwrite($fh, "-- Backup {$dbName} " . date('Y-m-d H:i:s') . "\n");
    fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($tables as $table) {
        $table = (string) $table;
        $createRow = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_ASSOC);
        $createSql = (string) ($createRow['Create Table'] ?? '');
        if ($createSql === '') {
            continue;
        }

        fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n{$createSql};\n\n");

        $st = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $cols = [];
            $vals = [];
            foreach ($row as $col => $val) {
                $cols[] = '`' . str_replace('`', '``', (string) $col) . '`';
                if ($val === null) {
                    $vals[] = 'NULL';
                } elseif (is_int($val) || is_float($val)) {
                    $vals[] = (string) $val;
                } else {
                    $vals[] = $pdo->quote((string) $val);
                }
            }
            fwrite($fh, 'INSERT INTO `' . $table . '` (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ");\n");
        }
        fwrite($fh, "\n");
    }

    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
}

function sys_backup_should_skip_relative(string $relativePath, string $backupRootNorm, string $appRootNorm): bool
{
    $relativePath = str_replace('\\', '/', strtolower($relativePath));
    $backupRootNorm = str_replace('\\', '/', strtolower($backupRootNorm));
    $appRootNorm = str_replace('\\', '/', strtolower(rtrim($appRootNorm, '/\\')));

    if ($backupRootNorm !== '' && str_starts_with($backupRootNorm, $appRootNorm)) {
        $relBackup = substr($backupRootNorm, strlen($appRootNorm));
        $relBackup = ltrim(str_replace('\\', '/', $relBackup), '/');
        if ($relBackup !== '' && ($relativePath === $relBackup || str_starts_with($relativePath, $relBackup . '/'))) {
            return true;
        }
    }

    $skip = [
        'node_modules/',
        '.git/',
        'tools/',
        'backups/',
        'manager_backups/',
        'vendor/mpdf/mpdf/tmp/',
    ];
    foreach ($skip as $prefix) {
        if ($relativePath === rtrim($prefix, '/') || str_starts_with($relativePath, $prefix)) {
            return true;
        }
    }

    return false;
}

function sys_backup_zip_app(string $zipPath, string $backupRootNorm): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('امتداد ZipArchive غير متوفر على الخادم.');
    }

    $appRoot = rtrim(app_path(''), DIRECTORY_SEPARATOR);
    $appRootNorm = sys_backup_normalize_dir($appRoot);
    $backupRootNorm = sys_backup_normalize_dir($backupRootNorm);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('تعذر إنشاء ملف ضغط ملفات النظام.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo) {
            continue;
        }
        $full = $fileInfo->getPathname();
        $relative = substr($full, strlen($appRoot) + 1);
        $relativeUnix = str_replace('\\', '/', $relative);
        if (sys_backup_should_skip_relative($relativeUnix, $backupRootNorm, $appRootNorm)) {
            continue;
        }

        if ($fileInfo->isDir()) {
            $zip->addEmptyDir($relativeUnix);
        } else {
            $zip->addFile($full, $relativeUnix);
        }
    }

    $zip->close();

    if (!is_file($zipPath) || filesize($zipPath) < 1) {
        throw new RuntimeException('فشل إنشاء ملف ملفات النظام.');
    }
}

function sys_backup_bundle_filename(): string
{
    return 'backup_full.zip';
}

function sys_backup_bundle_path(string $targetDir): string
{
    return sys_backup_normalize_dir($targetDir) . DIRECTORY_SEPARATOR . sys_backup_bundle_filename();
}

/** @return list<string> */
function sys_backup_allowed_download_files(): array
{
    return ['backup_full.zip', 'database.sql', 'system_files.zip'];
}

/**
 * @return array{dir:string, file:string, label:string}|null
 */
function sys_backup_resolve_download(PDO $pdo, string $dateFolder, string $fileKey): ?array
{
    $dateFolder = trim($dateFolder);
    if ($dateFolder === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFolder)) {
        return null;
    }

    $settings = sys_backup_settings($pdo);
    $root = trim($settings['backup_dir'] ?? '');
    if ($root === '') {
        return null;
    }

    try {
        $root = sys_backup_validate_dir($root, false);
    } catch (Throwable $e) {
        return null;
    }

    $targetDir = sys_backup_normalize_dir($root . DIRECTORY_SEPARATOR . $dateFolder);
    $rootReal = realpath($root);
    $dirReal = realpath($targetDir);
    if ($rootReal === false || $dirReal === false || !sys_backup_dir_is_within_root($dirReal, $rootReal)) {
        return null;
    }

    $map = [
        'bundle' => ['file' => sys_backup_bundle_filename(), 'label' => 'backup_full.zip'],
        'database' => ['file' => 'database.sql', 'label' => 'database.sql'],
        'files' => ['file' => 'system_files.zip', 'label' => 'system_files.zip'],
    ];
    $fileKey = strtolower(trim($fileKey));
    if (!isset($map[$fileKey])) {
        return null;
    }

    $filename = $map[$fileKey]['file'];
    if (!in_array($filename, sys_backup_allowed_download_files(), true)) {
        return null;
    }

    $fullPath = $dirReal . DIRECTORY_SEPARATOR . $filename;
    if ($fileKey === 'bundle' && (!is_file($fullPath) || !is_readable($fullPath))) {
        sys_backup_create_bundle($dirReal);
        $fullPath = $dirReal . DIRECTORY_SEPARATOR . $filename;
    }
    if (!is_file($fullPath) || !is_readable($fullPath)) {
        return null;
    }

    return [
        'dir' => $dirReal,
        'file' => $fullPath,
        'label' => $map[$fileKey]['label'],
    ];
}

function sys_backup_create_bundle(string $targetDir): bool
{
    if (!class_exists('ZipArchive')) {
        return false;
    }

    $targetDir = sys_backup_normalize_dir($targetDir);
    $bundlePath = sys_backup_bundle_path($targetDir);
    $parts = [
        'database.sql',
        'system_files.zip',
        'README.txt',
    ];

    $zip = new ZipArchive();
    if ($zip->open($bundlePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }

    foreach ($parts as $name) {
        $full = $targetDir . DIRECTORY_SEPARATOR . $name;
        if (is_file($full)) {
            $zip->addFile($full, $name);
        }
    }

    $zip->close();

    return is_file($bundlePath) && filesize($bundlePath) > 0;
}

/** @return list<array{date_folder:string, path:string, has_bundle:bool}> */
function sys_backup_recent_folders(PDO $pdo, int $limit = 8): array
{
    $settings = sys_backup_settings($pdo);
    $root = trim($settings['backup_dir'] ?? '');
    if ($root === '') {
        return [];
    }

    try {
        $root = sys_backup_validate_dir($root, false);
    } catch (Throwable $e) {
        return [];
    }

    if (!is_dir($root)) {
        return [];
    }

    $entries = [];
    $names = @scandir($root);
    if (!is_array($names)) {
        return [];
    }
    $names = array_values(array_diff($names, ['.', '..']));
    rsort($names, SORT_STRING);

    foreach ($names as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $name)) {
            continue;
        }
        $dir = sys_backup_normalize_dir($root . DIRECTORY_SEPARATOR . $name);
        if (!is_dir($dir)) {
            continue;
        }
        $entries[] = [
            'date_folder' => $name,
            'path' => $dir,
            'has_bundle' => is_file(sys_backup_bundle_path($dir)),
        ];
        if (count($entries) >= max(1, $limit)) {
            break;
        }
    }

    return $entries;
}

function sys_backup_download_url(string $dateFolder, string $fileKey = 'bundle'): string
{
    return app_url('api/backup_download.php')
        . '?date=' . rawurlencode($dateFolder)
        . '&file=' . rawurlencode($fileKey);
}

function sys_backup_stream_file(string $fullPath, string $downloadName): void
{
    if (!is_file($fullPath) || !is_readable($fullPath)) {
        throw new RuntimeException('الملف غير موجود.');
    }

    $size = filesize($fullPath);
    if ($size === false) {
        throw new RuntimeException('تعذر قراءة حجم الملف.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
    header('Content-Length: ' . (string) $size);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $fh = fopen($fullPath, 'rb');
    if ($fh === false) {
        throw new RuntimeException('تعذر فتح الملف للتنزيل.');
    }

    while (!feof($fh)) {
        $chunk = fread($fh, 1024 * 1024);
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        if (function_exists('flush')) {
            flush();
        }
    }
    fclose($fh);
    exit;
}

/**
 * @return array{ok:bool, message:string, path:string, date_folder:string}
 */
function sys_backup_run(PDO $pdo, int $userId = 0): array
{
    @set_time_limit(0);
    @ini_set('memory_limit', '1024M');

    $settings = sys_backup_settings($pdo);
    $root = trim($settings['backup_dir']);
    if ($root === '') {
        return [
            'ok' => false,
            'message' => 'حدّد مجلد النسخ الاحتياطي أولاً ثم احفظ المسار.',
            'path' => '',
            'date_folder' => '',
        ];
    }

    try {
        $root = sys_backup_validate_dir($root, true);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => $e->getMessage(),
            'path' => '',
            'date_folder' => '',
        ];
    }

    $dateFolder = sys_backup_today_folder_name();
    $targetDir = sys_backup_normalize_dir($root . DIRECTORY_SEPARATOR . $dateFolder);

    if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        return [
            'ok' => false,
            'message' => 'تعذر إنشاء مجلد النسخ بتاريخ اليوم.',
            'path' => '',
            'date_folder' => $dateFolder,
        ];
    }

    $dbFile = $targetDir . DIRECTORY_SEPARATOR . 'database.sql';
    $zipFile = $targetDir . DIRECTORY_SEPARATOR . 'system_files.zip';
    $readmeFile = $targetDir . DIRECTORY_SEPARATOR . 'README.txt';

    $cfg = require app_path('config/database.php');
    $dumpOk = sys_backup_run_mysqldump($cfg, $dbFile);
    if (!$dumpOk) {
        sys_backup_dump_database_pdo($pdo, $dbFile);
    }

    if (!is_file($dbFile) || filesize($dbFile) < 1) {
        return [
            'ok' => false,
            'message' => 'تعذر أخذ نسخة قاعدة البيانات.',
            'path' => '',
            'date_folder' => $dateFolder,
        ];
    }

    sys_backup_zip_app($zipFile, $root);

    $readme = "نسخة احتياطية للنظام المحاسبي\r\n"
        . 'التاريخ: ' . $dateFolder . "\r\n"
        . 'وقت الإنشاء: ' . date('Y-m-d H:i:s') . "\r\n"
        . "المحتويات:\r\n"
        . "- database.sql (قاعدة البيانات)\r\n"
        . "- system_files.zip (ملفات النظام)\r\n";
    file_put_contents($readmeFile, $readme);

    sys_backup_create_bundle($targetDir);

    $st = $pdo->prepare(
        'UPDATE sys_backup_settings SET last_backup_at = NOW(), last_backup_path = ?, updated_by = ? WHERE id = 1'
    );
    $st->execute([$targetDir, $userId > 0 ? $userId : null]);

    return [
        'ok' => true,
        'message' => 'تم إنشاء النسخة الاحتياطية بنجاح في مجلد ' . $dateFolder . '.',
        'path' => $targetDir,
        'date_folder' => $dateFolder,
        'download_url' => sys_backup_download_url($dateFolder, 'bundle'),
        'download_database_url' => sys_backup_download_url($dateFolder, 'database'),
        'download_files_url' => sys_backup_download_url($dateFolder, 'files'),
    ];
}

function sys_backup_render_sidebar_link(string $activeRoute): void
{
    if (!is_logged_in() || !user_can('system_backup')) {
        return;
    }

    require_once app_path('includes/app_window_manager.php');

    $url = app_url('index.php?r=system_backup');
    if (app_mdi_is_park_menu_embed()) {
        $url = app_mdi_park_menu_url($url);
    }
    $isActive = $activeRoute === 'system_backup';

    echo '<a class="nav-domain-link nav-backup-link' . ($isActive ? ' is-active' : '') . '" href="' . esc($url) . '"';
    echo ' title="نسخة احتياطية لقاعدة البيانات وملفات النظام">';
    echo '💾 نسخة احتياطية';
    echo '</a>';
}
