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
 * @param list<string> $header
 * @return array<string,int> map field => column index
 */
function crm_region_excel_map_columns(array $header): array
{
    $map = [];
    foreach ($header as $i => $raw) {
        $h = crm_region_excel_normalize_header((string) $raw);
        if ($h === '') {
            continue;
        }
        // منطقة
        if (
            str_contains($h, 'منطقة') || str_contains($h, 'منطقه')
            || $h === 'region' || str_contains($h, 'regionname')
        ) {
            $map['region'] = $map['region'] ?? $i;
            continue;
        }
        // عنوان
        if (
            str_contains($h, 'عنوان') || str_contains($h, 'العنوان')
            || $h === 'address' || str_contains($h, 'address')
        ) {
            $map['address'] = $map['address'] ?? $i;
            continue;
        }
        // مندوب
        if (
            str_contains($h, 'مندوب') || str_contains($h, 'بائع')
            || str_contains($h, 'salesrep') || $h === 'rep' || str_contains($h, 'repname')
        ) {
            $map['rep'] = $map['rep'] ?? $i;
            continue;
        }
        // رمز عميل
        if (
            str_contains($h, 'رمزالعميل') || str_contains($h, 'رقمالعميل')
            || str_contains($h, 'كودالعميل') || $h === 'code'
            || str_contains($h, 'customercode') || str_contains($h, 'acc')
            || str_contains($h, 'oracle') || $h === 'sku'
        ) {
            $map['customer_code'] = $map['customer_code'] ?? $i;
            continue;
        }
        // اسم عميل
        if (
            str_contains($h, 'اسمالعميل') || str_contains($h, 'العميل')
            || str_contains($h, 'customername') || ($h === 'name' && !isset($map['customer_name']))
            || str_contains($h, 'اسمالحساب')
        ) {
            $map['customer_name'] = $map['customer_name'] ?? $i;
            continue;
        }
    }

    // إن لم يُعثر على رؤوس: افتراض ترتيب شائع [منطقة، عنوان، مندوب، رمز، اسم]
    if (!isset($map['region']) && !isset($map['rep']) && !isset($map['customer_code'])) {
        if (count($header) >= 3) {
            $map = [
                'region' => 0,
                'address' => 1,
                'rep' => 2,
            ];
            if (count($header) >= 4) {
                $map['customer_code'] = 3;
            }
            if (count($header) >= 5) {
                $map['customer_name'] = 4;
            }
        }
    }

    return $map;
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
 * إيجاد أو إنشاء منطقة (الاسم + العنوان).
 */
function crm_region_find_or_create(PDO $pdo, string $nameAr, string $addressAr = '', ?int $sortHint = null): int
{
    crm_region_ensure_schema($pdo);
    $nameAr = trim($nameAr);
    $addressAr = trim($addressAr);
    if ($nameAr === '') {
        throw new RuntimeException('اسم المنطقة فارغ.');
    }

    if ($addressAr !== '') {
        $st = $pdo->prepare(
            'SELECT id FROM crm_region WHERE name_ar = ? AND COALESCE(address_ar, \'\') = ? LIMIT 1'
        );
        $st->execute([$nameAr, $addressAr]);
    } else {
        $st = $pdo->prepare(
            'SELECT id FROM crm_region WHERE name_ar = ? AND (address_ar IS NULL OR address_ar = \'\') LIMIT 1'
        );
        $st->execute([$nameAr]);
    }
    $id = (int) $st->fetchColumn();
    if ($id > 0) {
        // تفعيل إن كانت موقوفة
        $pdo->prepare('UPDATE crm_region SET is_active = 1 WHERE id = ?')->execute([$id]);

        return $id;
    }

    $n = (int) $pdo->query('SELECT IFNULL(MAX(id), 0) FROM crm_region')->fetchColumn();
    $code = 'R' . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    // تأكد من فرادة الرمز
    $try = 0;
    while ($try < 50) {
        $chk = $pdo->prepare('SELECT id FROM crm_region WHERE code = ? LIMIT 1');
        $chk->execute([$code]);
        if (!$chk->fetch()) {
            break;
        }
        $try++;
        $code = 'R' . str_pad((string) ($n + 1 + $try), 4, '0', STR_PAD_LEFT);
    }

    $sort = $sortHint !== null ? $sortHint : (($n + 1) * 10);
    $ins = $pdo->prepare(
        'INSERT INTO crm_region (code, name_ar, address_ar, sort_order, is_active) VALUES (?,?,?,?,1)'
    );
    $ins->execute([$code, $nameAr, $addressAr !== '' ? $addressAr : null, $sort]);

    return (int) $pdo->lastInsertId();
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
 * إيجاد عميل بالرمز أو الاسم.
 */
function crm_customer_find_for_import(PDO $pdo, string $code, string $name): ?int
{
    $code = trim($code);
    $name = trim($name);
    if ($code !== '') {
        $st = $pdo->prepare('SELECT id FROM crm_customer WHERE code = ? LIMIT 1');
        $st->execute([$code]);
        $id = (int) $st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        // مطابقة جزئية لرمز Oracle الطويل
        $st = $pdo->prepare('SELECT id FROM crm_customer WHERE code LIKE ? ORDER BY id ASC LIMIT 1');
        $st->execute(['%' . $code]);
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
        'regions_used' => 0,
        'customers_linked' => 0,
        'customers_missing' => 0,
        'skipped' => 0,
    ];
    $warnings = [];
    $repRegionPairs = []; // "repId:regionId" => true
    $existingRepNames = [];
    $existingRegionKeys = [];

    // snapshot موجود مسبقاً
    try {
        foreach ($pdo->query('SELECT id, name_ar FROM crm_sales_rep')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $existingRepNames[mb_strtolower(trim((string) $r['name_ar']), 'UTF-8')] = (int) $r['id'];
        }
        foreach ($pdo->query('SELECT id, name_ar, address_ar FROM crm_region')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $key = mb_strtolower(trim((string) $r['name_ar']) . '|' . trim((string) ($r['address_ar'] ?? '')), 'UTF-8');
            $existingRegionKeys[$key] = (int) $r['id'];
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
            $custCode = crm_region_excel_cell($row, $map, 'customer_code');
            $custName = crm_region_excel_cell($row, $map, 'customer_name');

            // إن كان الجدول فقط مناطق/مندوب بدون أسماء أعمدة واضحة
            if ($regionName === '' && isset($row[0])) {
                // skip — already mapped
            }

            if ($regionName === '' && $repName === '' && $custCode === '' && $custName === '') {
                $stats['skipped']++;
                continue;
            }

            $regionId = 0;
            if ($regionName !== '') {
                $rkey = mb_strtolower($regionName . '|' . $address, 'UTF-8');
                $before = $existingRegionKeys[$rkey] ?? 0;
                $regionId = crm_region_find_or_create($pdo, $regionName, $address, ($ri + 1) * 10);
                if ($before < 1) {
                    $stats['regions_created']++;
                    $existingRegionKeys[$rkey] = $regionId;
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

            if ($repId > 0 && $regionId > 0) {
                $pair = $repId . ':' . $regionId;
                if (!isset($repRegionPairs[$pair])) {
                    // إضافة المنطقة للمندوب دون حذف بقية مناطقه
                    $ids = crm_sales_rep_region_ids($pdo, $repId);
                    if (!in_array($regionId, $ids, true)) {
                        $ids[] = $regionId;
                        crm_sales_rep_save_regions($pdo, $repId, $ids);
                    }
                    $repRegionPairs[$pair] = true;
                    $stats['reps_linked']++;
                }
            }

            if ($custCode !== '' || $custName !== '') {
                $customerId = crm_customer_find_for_import($pdo, $custCode, $custName);
                if ($customerId === null) {
                    $stats['customers_missing']++;
                    if (count($warnings) < 30) {
                        $warnings[] = 'عميل غير موجود: ' . ($custCode !== '' ? $custCode . ' ' : '') . $custName;
                    }
                } else {
                    if ($regionId > 0) {
                        $pdo->prepare('UPDATE crm_customer SET region_id = ? WHERE id = ?')
                            ->execute([$regionId, $customerId]);
                    }
                    if ($repId > 0) {
                        crm_customer_link_sales_rep($pdo, $customerId, $repId, $replaceCustomerReps);
                    }
                    // تحديث عنوان العميل من عنوان المنطقة إن كان فارغاً
                    if ($address !== '') {
                        $pdo->prepare(
                            'UPDATE crm_customer SET address_ar = COALESCE(NULLIF(TRIM(address_ar), \'\'), ?) WHERE id = ?'
                        )->execute([$address, $customerId]);
                    }
                    $stats['customers_linked']++;
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $msg = sprintf(
        'تم الاستيراد من «%s»: صفوف %d — مندوبون جدد %d — مناطق جديدة %d — عملاء مربوطون %d — عملاء غير موجودين %d.',
        basename($resolved),
        $stats['rows'],
        $stats['reps_created'],
        $stats['regions_created'],
        $stats['customers_linked'],
        $stats['customers_missing']
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

/**
 * مناطق مندوب مع العنوان — للاستخدام في واجهة العميل.
 *
 * @return list<array{id:int,name_ar:string,address_ar:string,label:string}>
 */
function crm_sales_rep_regions_detail(PDO $pdo, int $salesRepId): array
{
    if ($salesRepId < 1) {
        return [];
    }
    crm_sales_rep_region_ensure_schema($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT rg.id, rg.name_ar, COALESCE(rg.address_ar, \'\') AS address_ar
             FROM crm_sales_rep_region srr
             INNER JOIN crm_region rg ON rg.id = srr.region_id
             WHERE srr.sales_rep_id = ? AND rg.is_active = 1
             ORDER BY srr.sort_order ASC, rg.name_ar ASC'
        );
        $st->execute([$salesRepId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $name = (string) ($r['name_ar'] ?? '');
            $addr = trim((string) ($r['address_ar'] ?? ''));
            $label = $addr !== '' ? ($name . ' — ' . $addr) : $name;
            $out[] = [
                'id' => (int) ($r['id'] ?? 0),
                'name_ar' => $name,
                'address_ar' => $addr,
                'label' => $label,
            ];
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}
