<?php
declare(strict_types=1);

require_once app_path('includes/xlsx_simple_reader.php');
require_once app_path('includes/crm_region.php');
require_once app_path('includes/crm_sales_rep_schema.php');

/**
 * مسارات ملف الاستيراد الافتراضية (أول موجود).
 *
 * @return list<string>
 */
function crm_region_excel_candidate_paths(): array
{
    $paths = [];
    $paths[] = 'C:\\xampp\\htdocs\\system\\المنطقة.xlsx';
    $paths[] = 'C:/xampp/htdocs/system/المنطقة.xlsx';
    // إن كان المشروع تحت system
    $paths[] = dirname(app_path()) . DIRECTORY_SEPARATOR . 'المنطقة.xlsx';
    $paths[] = app_path('data') . DIRECTORY_SEPARATOR . 'المنطقة.xlsx';
    $paths[] = app_path('uploads') . DIRECTORY_SEPARATOR . 'المنطقة.xlsx';
    // بدائل إنجليزية
    $paths[] = 'C:\\xampp\\htdocs\\system\\region.xlsx';
    $paths[] = dirname(app_path()) . DIRECTORY_SEPARATOR . 'region.xlsx';

    return array_values(array_unique($paths));
}

function crm_region_excel_resolve_path(?string $override = null): ?string
{
    if ($override !== null && $override !== '' && is_readable($override)) {
        return $override;
    }
    foreach (crm_region_excel_candidate_paths() as $p) {
        if (is_readable($p)) {
            return $p;
        }
    }

    return null;
}

function crm_region_excel_normalize_header(string $h): string
{
    $h = trim(mb_strtolower($h, 'UTF-8'));
    $h = str_replace(['ـ', '_', '-', '.', ' '], '', $h);

    return $h;
}

/**
 * رؤوس ملف «المنطقة.xlsx» النموذجي:
 *   A رقم العميل · B اسم العميل · C العنوان · D المنطقة · F اسم المندوب
 * (أعمدة فارغة/مخفية تُتجاهل — الربط بالاسم وليس برقم العمود)
 *
 * @param list<string> $header
 * @return array<string,int> map field => column index
 */
function crm_region_excel_map_columns(array $header): array
{
    $map = [];
    foreach ($header as $i => $raw) {
        // إزالة BOM/فواصل أعمدة Excel
        $raw = preg_replace('/^\xEF\xBB\xBF/u', '', (string) $raw) ?? (string) $raw;
        $h = crm_region_excel_normalize_header($raw);
        if ($h === '') {
            continue;
        }

        // رقم / رمز العميل — قبل أي مطابقة تحوي كلمة «عميل»
        if (
            $h === 'رقمالعميل' || $h === 'رمزالعميل' || $h === 'كودالعميل'
            || $h === 'رقم' || str_contains($h, 'رقمالعميل') || str_contains($h, 'رمزالعميل')
            || str_contains($h, 'كودالعميل') || str_contains($h, 'رقمالحساب')
            || $h === 'code' || str_contains($h, 'customercode') || str_contains($h, 'customerno')
            || str_contains($h, 'oracle')
        ) {
            $map['customer_code'] = $map['customer_code'] ?? $i;
            continue;
        }

        // اسم المندوب — قبل «اسم» العام
        if (
            str_contains($h, 'مندوب') || str_contains($h, 'بائع')
            || str_contains($h, 'salesrep') || $h === 'rep' || str_contains($h, 'repname')
            || $h === 'اسمالمندوب'
        ) {
            $map['rep'] = $map['rep'] ?? $i;
            continue;
        }

        // اسم العميل
        if (
            $h === 'اسمالعميل' || str_contains($h, 'اسمالعميل')
            || str_contains($h, 'customername') || str_contains($h, 'اسمالحساب')
            || ($h === 'اسم' && !isset($map['customer_name']))
            || ($h === 'name' && !isset($map['customer_name']))
            || ($h === 'العميل' && !isset($map['customer_name']))
        ) {
            $map['customer_name'] = $map['customer_name'] ?? $i;
            continue;
        }

        // المنطقة (مثال: عمان الغربية / شمال عمان)
        if (
            $h === 'المنطقة' || $h === 'المنطقه' || $h === 'منطقة' || $h === 'منطقه'
            || str_contains($h, 'منطقة') || str_contains($h, 'منطقه')
            || $h === 'region' || str_contains($h, 'regionname')
        ) {
            $map['region'] = $map['region'] ?? $i;
            continue;
        }

        // العنوان (مثال: الرابية / الشميساني / شفا بدران)
        if (
            $h === 'العنوان' || $h === 'عنوان' || str_contains($h, 'عنوان')
            || $h === 'address' || str_contains($h, 'address')
        ) {
            $map['address'] = $map['address'] ?? $i;
            continue;
        }

        // يوم الزيارة — مسموح وغير مستخدم حالياً
        if ($h === 'اليوم' || $h === 'يوم' || str_contains($h, 'visitday')) {
            $map['day'] = $map['day'] ?? $i;
            continue;
        }
    }

    // ترتيب ملف المناطق عند غياب الرؤوس: رقم، اسم، عنوان، منطقة، …، مندوب
    if (!isset($map['region']) && !isset($map['rep']) && !isset($map['customer_code'])) {
        if (count($header) >= 4) {
            $map = [
                'customer_code' => 0,
                'customer_name' => 1,
                'address' => 2,
                'region' => 3,
            ];
            // عمود المندوب غالباً F = فهرس 5
            if (count($header) >= 6) {
                $map['rep'] = 5;
            } elseif (count($header) >= 5) {
                $map['rep'] = 4;
            }
        }
    }

    return $map;
}

/** تنظيف رمز عميل من Excel (أرقام مثل 11200612.0). */
function crm_region_excel_normalize_code(string $code): string
{
    $code = trim($code);
    if ($code === '') {
        return '';
    }
    // علمية / عشري من Excel
    if (preg_match('/^-?\d+\.0+$/', $code)) {
        return (string) (int) round((float) $code);
    }
    if (is_numeric($code) && !str_contains($code, 'e') && !str_contains($code, 'E')) {
        // حافظ على رقم كامل بدون .0
        if (preg_match('/^\d+\.\d+$/', $code) && floor((float) $code) == (float) $code) {
            return sprintf('%.0f', (float) $code);
        }
    }
    // إزالة فواصل آلاف شائعة
    $code = str_replace([',', ' ', "\xC2\xA0"], '', $code);

    return $code;
}

function crm_region_excel_cell(array $row, array $map, string $key): string
{
    if (!isset($map[$key])) {
        return '';
    }
    $i = (int) $map[$key];

    return trim((string) ($row[$i] ?? ''));
}

/**
 * إيجاد أو إنشاء منطقة بالاسم فقط (العنوان يُربط عبر crm_region_address).
 * المعامل الثاني اختياري للتوافق مع الاستدعاءات القديمة — يُنشئ عنواناً ويُرجِع region_id.
 */
function crm_region_find_or_create(PDO $pdo, string $nameAr, string $addressAr = '', ?int $sortHint = null): int
{
    $regionId = crm_region_find_or_create_by_name($pdo, $nameAr, $sortHint);
    $addressAr = trim($addressAr);
    if ($addressAr !== '') {
        crm_region_address_find_or_create($pdo, $regionId, $addressAr, $sortHint);
    }

    return $regionId;
}

/**
 * @return array{region_id:int,address_id:int}
 */
function crm_region_with_address_find_or_create(PDO $pdo, string $regionName, string $addressName, ?int $sortHint = null): array
{
    $regionId = crm_region_find_or_create_by_name($pdo, $regionName, $sortHint);
    $addressId = 0;
    $addressName = trim($addressName);
    if ($addressName !== '') {
        $addressId = crm_region_address_find_or_create($pdo, $regionId, $addressName, $sortHint);
    }

    return ['region_id' => $regionId, 'address_id' => $addressId];
}

/**
 * إيجاد أو إنشاء مندوب بالاسم.
 */
function crm_sales_rep_find_or_create_by_name(PDO $pdo, string $nameAr): int
{
    crm_sales_rep_ensure_schema($pdo);
    $nameAr = trim($nameAr);
    if ($nameAr === '') {
        throw new RuntimeException('اسم المندوب فارغ.');
    }

    $st = $pdo->prepare(
        'SELECT id FROM crm_sales_rep WHERE name_ar = ? LIMIT 1'
    );
    $st->execute([$nameAr]);
    $id = (int) $st->fetchColumn();
    if ($id > 0) {
        $pdo->prepare('UPDATE crm_sales_rep SET is_active = 1 WHERE id = ?')->execute([$id]);

        return $id;
    }

    $n = (int) $pdo->query('SELECT IFNULL(MAX(id), 0) FROM crm_sales_rep')->fetchColumn();
    $code = 'REP-' . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    $try = 0;
    while ($try < 50) {
        $chk = $pdo->prepare('SELECT id FROM crm_sales_rep WHERE code = ? LIMIT 1');
        $chk->execute([$code]);
        if (!$chk->fetch()) {
            break;
        }
        $try++;
        $code = 'REP-' . str_pad((string) ($n + 1 + $try), 4, '0', STR_PAD_LEFT);
    }

    $ins = $pdo->prepare(
        'INSERT INTO crm_sales_rep (code, name_ar, is_active) VALUES (?,?,1)'
    );
    $ins->execute([$code, $nameAr]);

    return (int) $pdo->lastInsertId();
}

/**
 * ربط عميل بالمندوب (يُضاف إن لم يكن مربوطاً) دون مسح المندوبين الآخرين إلا إذا $replaceAll.
 */
function crm_customer_link_sales_rep(PDO $pdo, int $customerId, int $repId, bool $replaceAll = false): void
{
    if ($customerId < 1 || $repId < 1) {
        return;
    }
    if ($replaceAll) {
        crm_customer_save_sales_reps($pdo, $customerId, [$repId]);

        return;
    }
    $current = crm_customer_sales_rep_ids_for_customer($pdo, $customerId);
    if (!in_array($repId, $current, true)) {
        $current[] = $repId;
    }
    crm_customer_save_sales_reps($pdo, $customerId, $current);
}

/**
 * إيجاد عميل بالرمز / oracle_key / الاسم.
 */
function crm_customer_find_for_import(PDO $pdo, string $code, string $name): ?int
{
    $code = crm_region_excel_normalize_code($code);
    $name = trim($name);

    $hasOracleKey = false;
    try {
        $stCol = $pdo->query("SHOW COLUMNS FROM crm_customer LIKE 'oracle_key'");
        $hasOracleKey = (bool) $stCol->fetch();
    } catch (Throwable $e) {
        $hasOracleKey = false;
    }

    if ($code !== '') {
        $st = $pdo->prepare('SELECT id FROM crm_customer WHERE code = ? LIMIT 1');
        $st->execute([$code]);
        $id = (int) $st->fetchColumn();
        if ($id > 0) {
            return $id;
        }

        if ($hasOracleKey) {
            $st = $pdo->prepare('SELECT id FROM crm_customer WHERE oracle_key = ? LIMIT 1');
            $st->execute([$code]);
            $id = (int) $st->fetchColumn();
            if ($id > 0) {
                return $id;
            }
            // مفتاح طويل ينتهي برقم الحساب
            $st = $pdo->prepare(
                'SELECT id FROM crm_customer WHERE oracle_key LIKE ? OR oracle_key LIKE ? ORDER BY id ASC LIMIT 1'
            );
            $st->execute(['%' . $code, '%/' . $code]);
            $id = (int) $st->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }

        // مطابقة جزئية لرمز في قاعدة البيانات
        $st = $pdo->prepare(
            'SELECT id FROM crm_customer WHERE code LIKE ? OR code LIKE ? ORDER BY CHAR_LENGTH(code) ASC, id ASC LIMIT 1'
        );
        $st->execute([$code . '%', '%' . $code]);
        $id = (int) $st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
    }

    if ($name !== '') {
        $st = $pdo->prepare('SELECT id FROM crm_customer WHERE name_ar = ? LIMIT 1');
        $st->execute([$name]);
        $id = (int) $st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        // مطابقة مع تجاهل المسافات الزائدة في الوسط
        $st = $pdo->prepare(
            'SELECT id FROM crm_customer WHERE REPLACE(name_ar, " ", "") = REPLACE(?, " ", "") LIMIT 1'
        );
        $st->execute([$name]);
        $id = (int) $st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
    }

    return null;
}

/**
 * استيراد Excel: مندوبون + مناطق (مع عنوان) + ربط العملاء.
 *
 * @return array{
 *   ok:bool,
 *   path:string,
 *   message:string,
 *   stats:array<string,int>,
 *   warnings:list<string>,
 *   columns:array<string,int>
 * }
 */
function crm_region_excel_import(PDO $pdo, ?string $path = null, bool $replaceCustomerReps = true): array
{
    crm_region_ensure_schema($pdo);
    crm_sales_rep_ensure_schema($pdo);
    crm_sales_rep_region_ensure_schema($pdo);
    crm_sales_rep_ensure_customer_invoice_links($pdo);
    // تهيئة روابط مندوب-عميل قبل المعاملة (DDL في MySQL ينهي transaction)
    if (function_exists('crm_customer_sales_rep_ensure_schema')) {
        crm_customer_sales_rep_ensure_schema($pdo);
    }

    $resolved = crm_region_excel_resolve_path($path);
    if ($resolved === null) {
        $tried = implode(' | ', crm_region_excel_candidate_paths());

        return [
            'ok' => false,
            'path' => (string) ($path ?? ''),
            'message' => 'ملف Excel غير موجود. ضعه في: C:\\xampp\\htdocs\\system\\المنطقة.xlsx — المسارات المجربة: ' . $tried,
            'stats' => [],
            'warnings' => [],
            'columns' => [],
        ];
    }

    $rows = xlsx_simple_read_rows($resolved);
    if ($rows === []) {
        return [
            'ok' => false,
            'path' => $resolved,
            'message' => 'ملف Excel فارغ.',
            'stats' => [],
            'warnings' => [],
            'columns' => [],
        ];
    }

    $header = array_shift($rows);
    if (!is_array($header)) {
        $header = [];
    }
    $map = crm_region_excel_map_columns($header);
    if (!isset($map['region']) && !isset($map['rep'])) {
        return [
            'ok' => false,
            'path' => $resolved,
            'message' => 'تعذر التعرف على أعمدة Excel. تأكد من وجود: المنطقة، العنوان، المندوب، ويفضّل رمز/اسم العميل.',
            'stats' => [],
            'warnings' => [],
            'columns' => $map,
        ];
    }

    $stats = [
        'rows' => 0,
        'reps_created' => 0,
        'reps_linked' => 0,
        'regions_created' => 0,
        'addresses_created' => 0,
        'regions_used' => 0,
        'customers_linked' => 0,
        'customers_missing' => 0,
        'skipped' => 0,
    ];
    $warnings = [];
    $repAddrPairs = []; // "repId:addressId" => true
    $existingRepNames = [];
    $existingRegionNames = [];
    $existingAddrKeys = []; // "regionId|addr" => id

    try {
        foreach ($pdo->query('SELECT id, name_ar FROM crm_sales_rep')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $existingRepNames[mb_strtolower(trim((string) $r['name_ar']), 'UTF-8')] = (int) $r['id'];
        }
        foreach ($pdo->query('SELECT id, name_ar FROM crm_region')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $existingRegionNames[mb_strtolower(trim((string) $r['name_ar']), 'UTF-8')] = (int) $r['id'];
        }
        foreach ($pdo->query('SELECT id, region_id, name_ar FROM crm_region_address')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $key = (int) $r['region_id'] . '|' . mb_strtolower(trim((string) $r['name_ar']), 'UTF-8');
            $existingAddrKeys[$key] = (int) $r['id'];
        }
    } catch (Throwable $e) {
        //
    }

    $pdo->beginTransaction();
    try {
        foreach ($rows as $ri => $row) {
            if (!is_array($row)) {
                continue;
            }
            $stats['rows']++;
            $regionName = crm_region_excel_cell($row, $map, 'region');
            $address = crm_region_excel_cell($row, $map, 'address');
            $repName = crm_region_excel_cell($row, $map, 'rep');
            $custCode = crm_region_excel_normalize_code(crm_region_excel_cell($row, $map, 'customer_code'));
            $custName = crm_region_excel_cell($row, $map, 'customer_name');

            if ($regionName === '' && $repName === '' && $custCode === '' && $custName === '') {
                $stats['skipped']++;
                continue;
            }

            $regionId = 0;
            $addressId = 0;
            if ($regionName !== '') {
                $rnKey = mb_strtolower($regionName, 'UTF-8');
                $wasNewRegion = !isset($existingRegionNames[$rnKey]);
                $pair = crm_region_with_address_find_or_create($pdo, $regionName, $address, ($ri + 1) * 10);
                $regionId = (int) $pair['region_id'];
                $addressId = (int) $pair['address_id'];
                if ($wasNewRegion) {
                    $stats['regions_created']++;
                    $existingRegionNames[$rnKey] = $regionId;
                }
                if ($addressId > 0) {
                    $ak = $regionId . '|' . mb_strtolower($address, 'UTF-8');
                    if (!isset($existingAddrKeys[$ak])) {
                        $stats['addresses_created']++;
                        $existingAddrKeys[$ak] = $addressId;
                    }
                }
                $stats['regions_used']++;
            }

            $repId = 0;
            if ($repName !== '') {
                $nkey = mb_strtolower($repName, 'UTF-8');
                $beforeRep = $existingRepNames[$nkey] ?? 0;
                $repId = crm_sales_rep_find_or_create_by_name($pdo, $repName);
                if ($beforeRep < 1) {
                    $stats['reps_created']++;
                    $existingRepNames[$nkey] = $repId;
                }
            }

            // ربط المندوب بالمنطقة+العنوان (عدة مناطق/عناوين)
            if ($repId > 0 && $addressId > 0) {
                $pairKey = $repId . ':' . $addressId;
                if (!isset($repAddrPairs[$pairKey])) {
                    $ids = crm_sales_rep_region_address_ids($pdo, $repId);
                    if (!in_array($addressId, $ids, true)) {
                        $ids[] = $addressId;
                        crm_sales_rep_save_region_addresses($pdo, $repId, $ids);
                    }
                    $repAddrPairs[$pairKey] = true;
                    $stats['reps_linked']++;
                }
            } elseif ($repId > 0 && $regionId > 0) {
                $ids = crm_sales_rep_region_ids($pdo, $repId);
                if (!in_array($regionId, $ids, true)) {
                    $ids[] = $regionId;
                    crm_sales_rep_save_regions($pdo, $repId, $ids);
                    $stats['reps_linked']++;
                }
            }

            // ربط العميل بالمنطقة/العنوان/المندوب
            if ($custCode !== '' || $custName !== '') {
                $customerId = crm_customer_find_for_import($pdo, $custCode, $custName);
                if ($customerId === null) {
                    $stats['customers_missing']++;
                    if (count($warnings) < 40) {
                        $warnings[] = 'عميل غير موجود: ' . ($custCode !== '' ? $custCode . ' ' : '') . $custName;
                    }
                } else {
                    // تحديث حقول الربط على crm_customer (شاشة العميل تقرأ منها)
                    if ($regionId > 0 || $repId > 0 || $addressId > 0 || $address !== '') {
                        $sets = [];
                        $params = [];
                        if ($regionId > 0) {
                            $sets[] = 'region_id = ?';
                            $params[] = $regionId;
                            $sets[] = 'region_address_id = ?';
                            $params[] = $addressId > 0 ? $addressId : null;
                        }
                        if ($repId > 0) {
                            $sets[] = 'sales_rep_id = ?';
                            $params[] = $repId;
                        }
                        if ($address !== '') {
                            $sets[] = 'address_ar = COALESCE(NULLIF(TRIM(address_ar), \'\'), ?)';
                            $params[] = $address;
                        }
                        if ($sets !== []) {
                            $params[] = $customerId;
                            $pdo->prepare(
                                'UPDATE crm_customer SET ' . implode(', ', $sets) . ' WHERE id = ?'
                            )->execute($params);
                        }
                    }
                    if ($repId > 0) {
                        crm_customer_link_sales_rep($pdo, $customerId, $repId, $replaceCustomerReps);
                    }
                    $stats['customers_linked']++;
                }
            }
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // تحقق فعلي بعد الحفظ (ما يظهر في شاشة العميل)
    $verifiedRegion = 0;
    $verifiedRep = 0;
    try {
        $verifiedRegion = (int) $pdo->query(
            'SELECT COUNT(*) FROM crm_customer WHERE region_id IS NOT NULL AND region_id > 0'
        )->fetchColumn();
        $verifiedRep = (int) $pdo->query(
            'SELECT COUNT(*) FROM crm_customer WHERE sales_rep_id IS NOT NULL AND sales_rep_id > 0'
        )->fetchColumn();
    } catch (Throwable $e) {
        //
    }
    $stats['verified_with_region'] = $verifiedRegion;
    $stats['verified_with_rep'] = $verifiedRep;

    $msg = sprintf(
        'تم الاستيراد من «%s»: صفوف %d — عملاء رُبطوا في الملف %d — بدون مطابقة %d — مندوبون جدد %d — مناطق/عناوين جديدة %d/%d. في قاعدة البيانات الآن: %d عميل بمنطقة · %d عميل بمندوب.',
        basename($resolved),
        $stats['rows'],
        $stats['customers_linked'],
        $stats['customers_missing'],
        $stats['reps_created'],
        $stats['regions_created'],
        $stats['addresses_created'],
        $verifiedRegion,
        $verifiedRep
    );

    return [
        'ok' => true,
        'path' => $resolved,
        'message' => $msg,
        'stats' => $stats,
        'warnings' => $warnings,
        'columns' => $map,
    ];
}

// crm_sales_rep_regions_detail معرّف في crm_region.php
