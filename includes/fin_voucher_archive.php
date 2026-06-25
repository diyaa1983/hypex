<?php
declare(strict_types=1);

require_once app_path('includes/date_defaults.php');
require_once app_path('includes/sys_backup.php');

function fin_voucher_archive_kinds(): array
{
    return [
        'receipt',
        'payment',
        'journal',
        'sales_invoice',
        'purchase_invoice',
        'sales_delivery',
        'sales_return',
        'purchase_return',
    ];
}

function fin_voucher_archive_kind_label(string $kind): string
{
    return match ($kind) {
        'receipt' => 'سندات القبض',
        'payment' => 'سندات الصرف',
        'journal' => 'سندات القيد',
        'sales_invoice' => 'فواتير المبيعات',
        'purchase_invoice' => 'فواتير المشتريات',
        'sales_delivery' => 'سندات التسليم',
        'sales_return' => 'مرتجعات المبيعات',
        'purchase_return' => 'مرتجعات المشتريات',
        default => $kind,
    };
}

function fin_voucher_archive_folder_segment(string $kind): string
{
    return match ($kind) {
        'receipt' => 'receipts',
        'payment' => 'payments',
        'journal' => 'journal',
        'sales_invoice' => 'sales_invoices',
        'purchase_invoice' => 'purchase_invoices',
        'sales_delivery' => 'sales_delivery',
        'sales_return' => 'sales_returns',
        'purchase_return' => 'purchase_returns',
        default => 'other',
    };
}

function fin_voucher_archive_permission(string $kind): string
{
    return match ($kind) {
        'receipt' => 'action_archive_cash_receipt',
        'payment' => 'action_archive_cash_payment',
        'journal' => 'action_archive_journal_voucher',
        'sales_invoice' => 'action_archive_sales_invoice',
        'purchase_invoice' => 'action_archive_purchase_invoice',
        'sales_delivery' => 'action_archive_sales_delivery',
        'sales_return' => 'action_archive_sales_return',
        'purchase_return' => 'action_archive_purchase_return',
        default => '',
    };
}

function fin_voucher_archive_screen_route(string $kind): string
{
    return match ($kind) {
        'receipt' => 'cash_receipt',
        'payment' => 'cash_payment',
        'journal' => 'journal_voucher',
        'sales_invoice' => 'sales_invoices',
        'purchase_invoice' => 'purchase_invoices',
        'sales_delivery' => 'sales_delivery',
        'sales_return' => 'sales_returns',
        'purchase_return' => 'purchase_returns',
        default => '',
    };
}

function fin_voucher_archive_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->query('SELECT id FROM fin_voucher_document LIMIT 1');
    } catch (Throwable $e) {
        if (
            str_contains($e->getMessage(), "doesn't exist")
            || str_contains($e->getMessage(), 'no such table')
            || str_contains($e->getMessage(), 'Base table or view not found')
        ) {
            try {
                require_once app_path('includes/sql_migration.php');
                sql_migration_run_file($pdo, 'database/migrations/179_fin_voucher_archive.sql');
            } catch (Throwable $e2) {
                // ignored
            }
        }
    }

    foreach (
        [
            'document_archive_dir VARCHAR(500) NULL DEFAULT NULL',
            'document_archive_max_mb TINYINT UNSIGNED NOT NULL DEFAULT 10',
        ] as $colDef
    ) {
        try {
            $pdo->exec('ALTER TABLE sys_company_settings ADD COLUMN ' . $colDef);
        } catch (Throwable $e) {
            // column exists
        }
    }

    try {
        $pdo->exec(
            "ALTER TABLE fin_voucher_document MODIFY voucher_kind ENUM(
                'receipt','payment','journal',
                'sales_invoice','purchase_invoice','sales_delivery','sales_return','purchase_return'
            ) NOT NULL"
        );
    } catch (Throwable $e) {
        // ignored
    }
}

/** @return array{document_archive_dir:string, document_archive_max_mb:int} */
function fin_voucher_archive_settings(PDO $pdo): array
{
    fin_voucher_archive_ensure_schema($pdo);
    try {
        $row = $pdo->query(
            'SELECT document_archive_dir, document_archive_max_mb FROM sys_company_settings WHERE id = 1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $row = false;
    }

    $maxMb = (int) ($row['document_archive_max_mb'] ?? 10);
    if ($maxMb < 1) {
        $maxMb = 1;
    } elseif ($maxMb > 100) {
        $maxMb = 100;
    }

    return [
        'document_archive_dir' => trim((string) ($row['document_archive_dir'] ?? '')),
        'document_archive_max_mb' => $maxMb,
    ];
}

function fin_voucher_archive_recommended_dir(): string
{
    $appRoot = rtrim(app_path(''), DIRECTORY_SEPARATOR);
    $parent = dirname($appRoot);
    if ($parent !== '' && $parent !== '.' && $parent !== $appRoot) {
        return sys_backup_normalize_dir($parent . DIRECTORY_SEPARATOR . 'manager_documents');
    }

    return sys_backup_normalize_dir($appRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'documents');
}

function fin_voucher_archive_save_dir(PDO $pdo, string $path): void
{
    fin_voucher_archive_ensure_schema($pdo);
    $path = sys_backup_validate_dir($path, true);
    sys_backup_ensure_dir_protected($path);
    $st = $pdo->prepare('UPDATE sys_company_settings SET document_archive_dir = ? WHERE id = 1');
    $st->execute([$path]);
    $GLOBALS['_company_settings_cache'] = null;
}

function fin_voucher_archive_save_max_mb(PDO $pdo, int $maxMb): void
{
    fin_voucher_archive_ensure_schema($pdo);
    $maxMb = max(1, min(100, $maxMb));
    $st = $pdo->prepare('UPDATE sys_company_settings SET document_archive_max_mb = ? WHERE id = 1');
    $st->execute([$maxMb]);
    $GLOBALS['_company_settings_cache'] = null;
}

/** @throws RuntimeException */
function fin_voucher_archive_root(PDO $pdo): string
{
    $settings = fin_voucher_archive_settings($pdo);
    $path = trim($settings['document_archive_dir']);
    if ($path === '') {
        throw new RuntimeException('حدّد مسار أرشيف المستندات من الإعدادات أولاً.');
    }

    $path = sys_backup_validate_dir($path, true);
    sys_backup_ensure_dir_protected($path);

    return $path;
}

function fin_voucher_archive_assert_kind(string $kind): void
{
    if (!in_array($kind, fin_voucher_archive_kinds(), true)) {
        throw new RuntimeException('نوع السند غير مدعوم.');
    }
}

function fin_voucher_archive_assert_access(string $kind): void
{
    fin_voucher_archive_assert_kind($kind);
    $perm = fin_voucher_archive_permission($kind);
    if ($perm === '' || !user_can_action($perm)) {
        throw new RuntimeException('لا تملك صلاحية أرشيف المستندات لهذا السند.');
    }
    $route = fin_voucher_archive_screen_route($kind);
    if ($route !== '' && !user_can($route)) {
        throw new RuntimeException('لا تملك صلاحية فتح هذه الشاشة.');
    }
}

/** @return array{posted:bool, cancelled:bool} */
function fin_voucher_archive_doc_status_flags(PDO $pdo, string $table, int $id, callable $isPostedFn): array
{
    $tbl = '`' . str_replace('`', '``', $table) . '`';
    $st = $pdo->prepare("SELECT status FROM {$tbl} WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $status = (string) ($st->fetchColumn() ?: '');
    if ($status === '') {
        throw new RuntimeException('المستند غير موجود.');
    }

    return [
        'posted' => (bool) $isPostedFn($pdo, $id),
        'cancelled' => $status === 'cancelled',
    ];
}

/** @return array{posted:bool, cancelled:bool} */
function fin_voucher_archive_voucher_flags(PDO $pdo, string $kind, int $id): array
{
    if ($kind === 'journal') {
        require_once app_path('includes/acc_journal.php');
        if (!acc_journal_ensure_schema($pdo)) {
            throw new RuntimeException('جداول القيود غير موجودة.');
        }
        $st = $pdo->prepare('SELECT status FROM acc_journal_entry WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $status = (string) ($st->fetchColumn() ?: '');
        if ($status === '') {
            throw new RuntimeException('سند القيد غير موجود.');
        }

        return [
            'posted' => $status === 'posted',
            'cancelled' => $status === 'cancelled',
        ];
    }

    if (in_array($kind, ['receipt', 'payment'], true)) {
        require_once app_path('includes/fin_voucher_schema.php');

        return [
            'posted' => fin_voucher_is_posted($pdo, $id),
            'cancelled' => fin_voucher_is_cancelled($pdo, $id),
        ];
    }

    return match ($kind) {
        'sales_invoice' => (static function () use ($pdo, $id): array {
            require_once app_path('includes/sal_invoice_post.php');

            return fin_voucher_archive_doc_status_flags($pdo, 'sal_invoice', $id, 'sal_invoice_is_posted');
        })(),
        'purchase_invoice' => (static function () use ($pdo, $id): array {
            require_once app_path('includes/pur_invoice_post.php');

            return fin_voucher_archive_doc_status_flags($pdo, 'pur_invoice', $id, 'pur_invoice_is_posted');
        })(),
        'sales_delivery' => (static function () use ($pdo, $id): array {
            require_once app_path('includes/sal_delivery_schema.php');

            return fin_voucher_archive_doc_status_flags($pdo, 'sal_delivery', $id, 'sal_delivery_is_posted');
        })(),
        'sales_return' => (static function () use ($pdo, $id): array {
            require_once app_path('includes/sal_return_post.php');

            return fin_voucher_archive_doc_status_flags($pdo, 'sal_return', $id, 'sal_return_is_posted');
        })(),
        'purchase_return' => (static function () use ($pdo, $id): array {
            require_once app_path('includes/pur_return_post.php');

            return fin_voucher_archive_doc_status_flags($pdo, 'pur_return', $id, 'pur_return_is_posted');
        })(),
        default => throw new RuntimeException('نوع السند غير مدعوم.'),
    };
}

function fin_voucher_archive_is_read_only(PDO $pdo, string $kind, int $id): bool
{
    $flags = fin_voucher_archive_voucher_flags($pdo, $kind, $id);

    return $flags['posted'] && !$flags['cancelled'];
}

/** يسمح بعرض القائمة والتحميل (يشمل المرحّل). */
function fin_voucher_archive_assert_viewable(PDO $pdo, string $kind, int $id): void
{
    $flags = fin_voucher_archive_voucher_flags($pdo, $kind, $id);
    if ($flags['cancelled']) {
        throw new RuntimeException('لا يمكن استخدام الأرشيف على سند ملغى.');
    }
}

/** يسمح بالرفع والحذف (مسودة غير مرحّلة فقط). */
function fin_voucher_archive_assert_editable(PDO $pdo, string $kind, int $id): void
{
    fin_voucher_archive_assert_viewable($pdo, $kind, $id);
    $flags = fin_voucher_archive_voucher_flags($pdo, $kind, $id);
    if ($flags['posted']) {
        throw new RuntimeException('لا يمكن تعديل الأرشيف بعد ترحيل السند — العرض فقط.');
    }
}

/** @return array{id:int, no:string} */
function fin_voucher_archive_resolve_voucher(PDO $pdo, string $kind, int $id): array
{
    fin_voucher_archive_assert_kind($kind);
    if ($id < 1) {
        throw new RuntimeException('احفظ السند أولاً ثم أرفق المستندات.');
    }

    if ($kind === 'journal') {
        require_once app_path('includes/acc_journal.php');
        if (!acc_journal_ensure_schema($pdo)) {
            throw new RuntimeException('جداول القيود غير موجودة.');
        }
        if (!acc_journal_is_manual_voucher($pdo, $id)) {
            throw new RuntimeException('أرشيف المرفقات متاح لسندات القيد اليدوية فقط.');
        }
        $st = $pdo->prepare('SELECT id, entry_no FROM acc_journal_entry WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('سند القيد غير موجود.');
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'no' => trim((string) ($row['entry_no'] ?? '')),
        ];
    }

    if (in_array($kind, ['receipt', 'payment'], true)) {
        require_once app_path('includes/fin_voucher.php');
        $row = fin_voucher_load($pdo, $id, $kind);
        if (!$row) {
            throw new RuntimeException('السند غير موجود.');
        }

        return [
            'id' => $id,
            'no' => trim((string) ($row['voucher_no'] ?? '')),
        ];
    }

    $docQuery = match ($kind) {
        'sales_invoice' => ['sal_invoice', 'invoice_no', 'فاتورة المبيعات'],
        'purchase_invoice' => ['pur_invoice', 'invoice_no', 'فاتورة الشراء'],
        'sales_delivery' => ['sal_delivery', 'delivery_no', 'سند التسليم'],
        'sales_return' => ['sal_return', 'return_no', 'مرتجع المبيعات'],
        'purchase_return' => ['pur_return', 'return_no', 'مرتجع الشراء'],
        default => null,
    };
    if ($docQuery === null) {
        throw new RuntimeException('نوع السند غير مدعوم.');
    }
    [$table, $noCol, $label] = $docQuery;
    $tbl = '`' . str_replace('`', '``', $table) . '`';
    $col = '`' . str_replace('`', '``', $noCol) . '`';
    $st = $pdo->prepare("SELECT id, {$col} AS doc_no FROM {$tbl} WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException($label . ' غير موجود.');
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'no' => trim((string) ($row['doc_no'] ?? '')),
    ];
}

function fin_voucher_archive_safe_voucher_folder(string $voucherNo, int $voucherId): string
{
    $voucherNo = trim($voucherNo);
    if ($voucherNo !== '') {
        $safe = preg_replace('/[^A-Za-z0-9._\-]+/u', '_', $voucherNo) ?? '';
        $safe = trim($safe, '._-');
        if ($safe !== '') {
            return $safe;
        }
    }

    return 'ID-' . $voucherId;
}

function fin_voucher_archive_allowed_extensions(): array
{
    return ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
}

function fin_voucher_archive_extension_from_name(string $name): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    return preg_replace('/[^a-z0-9]+/', '', $ext) ?? '';
}

function fin_voucher_archive_mime_map(): array
{
    return [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
    ];
}

/** @throws RuntimeException */
function fin_voucher_archive_validate_upload(array $file, int $maxBytes): array
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('لم يُرفَع أي ملف.');
    }
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('تعذر رفع الملف (رمز ' . $err . ').');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('ملف الرفع غير صالح.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1) {
        throw new RuntimeException('الملف فارغ.');
    }
    if ($size > $maxBytes) {
        throw new RuntimeException('حجم الملف أكبر من الحد المسموح.');
    }

    $original = trim((string) ($file['name'] ?? 'document'));
    if ($original === '') {
        $original = 'document';
    }
    $ext = fin_voucher_archive_extension_from_name($original);
    if ($ext === '' || !in_array($ext, fin_voucher_archive_allowed_extensions(), true)) {
        throw new RuntimeException('نوع الملف غير مسموح. المسموح: PDF، Word، JPG، PNG.');
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi !== false) {
            $detected = finfo_file($fi, $tmp);
            finfo_close($fi);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
        }
    }
    $allowedMimes = fin_voucher_archive_mime_map()[$ext] ?? [];
    if ($mime !== '' && $allowedMimes !== [] && !in_array($mime, $allowedMimes, true)) {
        throw new RuntimeException('نوع الملف لا يطابق الامتداد.');
    }
    if ($mime === '') {
        $mime = $allowedMimes[0] ?? 'application/octet-stream';
    }

    return [
        'tmp' => $tmp,
        'original' => $original,
        'ext' => $ext,
        'mime' => $mime,
        'size' => $size,
    ];
}

function fin_voucher_archive_build_target_dir(PDO $pdo, string $kind, string $voucherNo, int $voucherId): string
{
    $root = fin_voucher_archive_root($pdo);
    $dateFolder = app_today_ymd();
    $segment = fin_voucher_archive_folder_segment($kind);
    $voucherFolder = fin_voucher_archive_safe_voucher_folder($voucherNo, $voucherId);
    $target = sys_backup_normalize_dir(
        $root . DIRECTORY_SEPARATOR . $dateFolder . DIRECTORY_SEPARATOR . $segment . DIRECTORY_SEPARATOR . $voucherFolder
    );
    if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
        throw new RuntimeException('تعذر إنشاء مجلد الأرشيف.');
    }
    sys_backup_ensure_dir_protected($target);

    return $target;
}

function fin_voucher_archive_relative_path(string $root, string $absoluteFile): string
{
    $rootNorm = sys_backup_normalize_dir($root);
    $fileNorm = sys_backup_normalize_dir($absoluteFile);
    $prefix = $rootNorm . DIRECTORY_SEPARATOR;
    if (str_starts_with($fileNorm, $prefix)) {
        return substr($fileNorm, strlen($prefix));
    }

    return basename($fileNorm);
}

function fin_voucher_archive_file_count(PDO $pdo, string $kind, int $voucherId): int
{
    if ($voucherId < 1) {
        return 0;
    }
    fin_voucher_archive_ensure_schema($pdo);
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM fin_voucher_document WHERE voucher_kind = ? AND voucher_id = ?'
    );
    $st->execute([$kind, $voucherId]);

    return (int) $st->fetchColumn();
}

/** @return array{file_count:int, read_only:bool} */
function fin_voucher_archive_meta(PDO $pdo, string $kind, int $voucherId): array
{
    if ($voucherId < 1) {
        return ['file_count' => 0, 'read_only' => false];
    }

    fin_voucher_archive_assert_access($kind);
    fin_voucher_archive_assert_viewable($pdo, $kind, $voucherId);

    return [
        'file_count' => fin_voucher_archive_file_count($pdo, $kind, $voucherId),
        'read_only' => fin_voucher_archive_is_read_only($pdo, $kind, $voucherId),
    ];
}

function fin_voucher_archive_preview_kind(string $name, string $mime): string
{
    $ext = fin_voucher_archive_extension_from_name($name);
    if ($ext === 'pdf' || str_contains(strtolower($mime), 'pdf')) {
        return 'pdf';
    }
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        return 'image';
    }

    return 'file';
}

function fin_voucher_archive_file_urls(int $docId, string $kind): array
{
    $q = 'action=download&id=' . $docId . '&kind=' . rawurlencode($kind);

    return [
        'download_url' => app_url('api/fin_voucher_archive.php?' . $q),
        'view_url' => app_url('api/fin_voucher_archive.php?action=view&id=' . $docId . '&kind=' . rawurlencode($kind)),
    ];
}

/** @return list<array<string,mixed>> */
function fin_voucher_archive_list(PDO $pdo, string $kind, int $voucherId): array
{
    fin_voucher_archive_assert_access($kind);
    if ($voucherId < 1) {
        throw new RuntimeException('احفظ السند أولاً ثم أرفق المستندات.');
    }
    fin_voucher_archive_assert_viewable($pdo, $kind, $voucherId);
    fin_voucher_archive_resolve_voucher($pdo, $kind, $voucherId);
    fin_voucher_archive_ensure_schema($pdo);

    $st = $pdo->prepare(
        'SELECT id, original_name, mime_type, file_size, uploaded_at, uploaded_by
         FROM fin_voucher_document
         WHERE voucher_kind = ? AND voucher_id = ?
         ORDER BY uploaded_at DESC, id DESC'
    );
    $st->execute([$kind, $voucherId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $row) {
        $docId = (int) ($row['id'] ?? 0);
        $name = (string) ($row['original_name'] ?? '');
        $mime = (string) ($row['mime_type'] ?? '');
        $urls = fin_voucher_archive_file_urls($docId, $kind);
        $out[] = [
            'id' => $docId,
            'name' => $name,
            'mime' => $mime,
            'preview_kind' => fin_voucher_archive_preview_kind($name, $mime),
            'size' => (int) ($row['file_size'] ?? 0),
            'uploaded_at' => (string) ($row['uploaded_at'] ?? ''),
            'download_url' => $urls['download_url'],
            'view_url' => $urls['view_url'],
        ];
    }

    return $out;
}

/** @return array<string,mixed> */
function fin_voucher_archive_upload(PDO $pdo, string $kind, int $voucherId, array $file, int $userId = 0): array
{
    fin_voucher_archive_assert_access($kind);
    fin_voucher_archive_assert_editable($pdo, $kind, $voucherId);
    $voucher = fin_voucher_archive_resolve_voucher($pdo, $kind, $voucherId);
    $settings = fin_voucher_archive_settings($pdo);
    $maxBytes = (int) $settings['document_archive_max_mb'] * 1024 * 1024;
    $upload = fin_voucher_archive_validate_upload($file, $maxBytes);

    $root = fin_voucher_archive_root($pdo);
    $targetDir = fin_voucher_archive_build_target_dir($pdo, $kind, $voucher['no'], $voucher['id']);
    $storedName = date('His') . '_' . bin2hex(random_bytes(4)) . '.' . $upload['ext'];
    $dest = $targetDir . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($upload['tmp'], $dest)) {
        throw new RuntimeException('تعذر حفظ الملف في الأرشيف.');
    }

    $relative = fin_voucher_archive_relative_path($root, $dest);
    fin_voucher_archive_ensure_schema($pdo);
    $st = $pdo->prepare(
        'INSERT INTO fin_voucher_document
            (voucher_kind, voucher_id, voucher_no, original_name, stored_name, relative_path, mime_type, file_size, uploaded_by)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $st->execute([
        $kind,
        $voucher['id'],
        $voucher['no'],
        $upload['original'],
        $storedName,
        $relative,
        $upload['mime'],
        $upload['size'],
        $userId > 0 ? $userId : null,
    ]);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'name' => $upload['original'],
        'size' => $upload['size'],
    ];
}

function fin_voucher_archive_delete(PDO $pdo, int $docId, string $kind): void
{
    fin_voucher_archive_assert_access($kind);
    fin_voucher_archive_ensure_schema($pdo);
    $st = $pdo->prepare(
        'SELECT id, voucher_kind, voucher_id, relative_path FROM fin_voucher_document WHERE id = ? LIMIT 1'
    );
    $st->execute([$docId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (string) ($row['voucher_kind'] ?? '') !== $kind) {
        throw new RuntimeException('الملف غير موجود.');
    }

    $voucherId = (int) ($row['voucher_id'] ?? 0);
    if ($voucherId > 0) {
        fin_voucher_archive_assert_editable($pdo, $kind, $voucherId);
    }

    $root = fin_voucher_archive_root($pdo);
    $abs = sys_backup_normalize_dir($root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $row['relative_path']));
    if (is_file($abs)) {
        @unlink($abs);
    }

    $del = $pdo->prepare('DELETE FROM fin_voucher_document WHERE id = ? LIMIT 1');
    $del->execute([$docId]);
}

/** @return array{path:string, name:string, mime:string} */
function fin_voucher_archive_download(PDO $pdo, int $docId, string $kind): array
{
    fin_voucher_archive_assert_access($kind);
    fin_voucher_archive_ensure_schema($pdo);
    $st = $pdo->prepare(
        'SELECT voucher_kind, voucher_id, original_name, relative_path, mime_type FROM fin_voucher_document WHERE id = ? LIMIT 1'
    );
    $st->execute([$docId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (string) ($row['voucher_kind'] ?? '') !== $kind) {
        throw new RuntimeException('الملف غير موجود.');
    }

    $voucherId = (int) ($row['voucher_id'] ?? 0);
    if ($voucherId > 0) {
        fin_voucher_archive_assert_viewable($pdo, $kind, $voucherId);
    }

    $root = fin_voucher_archive_root($pdo);
    $abs = sys_backup_normalize_dir($root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $row['relative_path']));
    if (!is_file($abs)) {
        throw new RuntimeException('الملف غير موجود على القرص.');
    }

    return [
        'path' => $abs,
        'name' => (string) ($row['original_name'] ?? 'document'),
        'mime' => (string) ($row['mime_type'] ?? 'application/octet-stream'),
    ];
}

function fin_voucher_archive_path_issue(PDO $pdo): ?string
{
    $settings = fin_voucher_archive_settings($pdo);
    $path = trim($settings['document_archive_dir']);
    if ($path === '') {
        return 'لم يُحدَّد مسار أرشيف المستندات بعد.';
    }

    return sys_backup_path_issue($path);
}
