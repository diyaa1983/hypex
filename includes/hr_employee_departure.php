<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_departure_type.php');

function hr_employee_departure_ensure_schema(PDO $pdo): void
{
    hr_departure_type_ensure_schema($pdo);

    try {
        $pdo->query('SELECT id FROM hr_employee_departure LIMIT 1');

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
        sql_migration_run_file($pdo, 'database/migrations/200_hr_employee_departures.sql');
    } catch (Throwable $e) {
        // ignored
    }
}

function hr_employee_departure_next_voucher_no(PDO $pdo): string
{
    hr_employee_departure_ensure_schema($pdo);

    $maxVoucher = 0;
    $maxId = 0;

    try {
        $maxVoucher = (int) $pdo->query(
            'SELECT COALESCE(MAX(CAST(voucher_no AS UNSIGNED)), 0) FROM hr_employee_departure'
        )->fetchColumn();
    } catch (Throwable $e) {
        // ignore — fallback to max(id) below
    }

    try {
        $maxId = (int) $pdo->query(
            'SELECT COALESCE(MAX(id), 0) FROM hr_employee_departure'
        )->fetchColumn();
    } catch (Throwable $e) {
        // ignore
    }

    return (string) (max($maxVoucher, $maxId) + 1);
}

function hr_employee_departure_time_minutes(string $hhmm): int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) {
        return 0;
    }

    return max(0, (int) $m[1]) * 60 + max(0, (int) $m[2]);
}

function hr_employee_departure_normalize_time(string $raw): string
{
    $raw = trim(str_replace(['.', '٫'], ':', $raw));
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m)) {
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
            throw new RuntimeException('وقت المغادرة خارج النطاق (00:00 — 23:59).');
        }

        return sprintf('%02d:%02d', $h, $min);
    }

    throw new RuntimeException('صيغة الوقت غير صحيحة — استخدم ساعة:دقيقة (مثل 09:30).');
}

function hr_employee_departure_time_to_sql(string $hhmm): string
{
    return hr_employee_departure_normalize_time($hhmm) . ':00';
}

function hr_employee_departure_format_time(?string $sqlTime): string
{
    if ($sqlTime === null || trim($sqlTime) === '') {
        return '';
    }

    return substr(trim($sqlTime), 0, 5);
}

function hr_employee_departure_posted_label(int $isPosted): string
{
    return $isPosted === 1 ? 'مرحّل' : 'مسودة';
}

/**
 * @return array<string,mixed>
 */
function hr_employee_departure_parse_row(PDO $pdo, array $row, int $id = 0): array
{
    hr_employee_departure_ensure_schema($pdo);

    $employeeId = (int) ($row['employee_id'] ?? 0);
    if ($employeeId < 1) {
        throw new RuntimeException('اختر الموظف.');
    }

    $typeId = (int) ($row['departure_type_id'] ?? 0);
    if ($typeId < 1) {
        throw new RuntimeException('اختر نوع المغادرة.');
    }

    $stType = $pdo->prepare('SELECT id FROM hr_departure_type WHERE id = ? AND is_active = 1 LIMIT 1');
    $stType->execute([$typeId]);
    if (!$stType->fetchColumn()) {
        throw new RuntimeException('نوع المغادرة غير موجود أو غير نشط.');
    }

    $departureDate = parse_date_to_iso(trim((string) ($row['departure_date'] ?? '')));
    if ($departureDate === null) {
        throw new RuntimeException('تاريخ المغادرة غير صالح.');
    }

    $timeFrom = hr_employee_departure_normalize_time((string) ($row['time_from'] ?? ''));
    $timeTo = hr_employee_departure_normalize_time((string) ($row['time_to'] ?? ''));
    if (hr_employee_departure_time_minutes($timeTo) <= hr_employee_departure_time_minutes($timeFrom)) {
        throw new RuntimeException('وقت نهاية المغادرة يجب أن يكون بعد وقت البداية.');
    }

    $notes = trim((string) ($row['notes'] ?? ''));

    return [
        'employee_id' => $employeeId,
        'departure_type_id' => $typeId,
        'departure_date' => $departureDate,
        'time_from' => hr_employee_departure_time_to_sql($timeFrom),
        'time_to' => hr_employee_departure_time_to_sql($timeTo),
        'notes' => $notes !== '' ? $notes : null,
    ];
}

function hr_employee_departure_is_posted(PDO $pdo, int $id): bool
{
    if ($id < 1) {
        return false;
    }
    hr_employee_departure_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT is_posted FROM hr_employee_departure WHERE id = ? LIMIT 1');
    $st->execute([$id]);

    return (int) ($st->fetchColumn() ?: 0) === 1;
}

/** @return array{can_edit:bool,message:string} */
function hr_employee_departure_edit_check(PDO $pdo, int $id): array
{
    if ($id < 1) {
        return ['can_edit' => true, 'message' => ''];
    }
    if (hr_employee_departure_is_posted($pdo, $id)) {
        return ['can_edit' => false, 'message' => 'لا يمكن تعديل مغادرة مرحّلة — فك الترحيل أولاً.'];
    }

    return ['can_edit' => true, 'message' => ''];
}

/** @return array{can_delete:bool,message:string} */
function hr_employee_departure_delete_check(PDO $pdo, int $id): array
{
    if ($id < 1) {
        return ['can_delete' => false, 'message' => 'معرّف غير صالح.'];
    }
    if (hr_employee_departure_is_posted($pdo, $id)) {
        return ['can_delete' => false, 'message' => 'لا يمكن حذف مغادرة مرحّلة — فك الترحيل أولاً.'];
    }

    return ['can_delete' => true, 'message' => ''];
}

/**
 * @return list<array<string,mixed>>
 */
function hr_employee_departure_list(
    PDO $pdo,
    ?string $dateFrom = null,
    ?string $dateTo = null,
    int $employeeId = 0,
    int $limit = 500
): array {
    hr_employee_departure_ensure_schema($pdo);
    $limit = max(1, min(2000, $limit));

    $where = ['1=1'];
    $params = [];

    if ($dateFrom !== null && $dateFrom !== '') {
        $where[] = 'd.departure_date >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== null && $dateTo !== '') {
        $where[] = 'd.departure_date <= ?';
        $params[] = $dateTo;
    }
    if ($employeeId > 0) {
        $where[] = 'd.employee_id = ?';
        $params[] = $employeeId;
    }

    $sql = 'SELECT d.*,
                   e.emp_code, e.name_ar AS employee_name,
                   t.type_code, t.name_ar AS type_name
            FROM hr_employee_departure d
            INNER JOIN hr_employee e ON e.id = d.employee_id
            INNER JOIN hr_departure_type t ON t.id = d.departure_type_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY d.departure_date DESC, CAST(d.voucher_no AS UNSIGNED) DESC, d.id DESC
            LIMIT ' . $limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<string,mixed>|null */
function hr_employee_departure_get(PDO $pdo, int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    hr_employee_departure_ensure_schema($pdo);
    $st = $pdo->prepare(
        'SELECT d.*,
                e.emp_code, e.name_ar AS employee_name,
                t.type_code, t.name_ar AS type_name
         FROM hr_employee_departure d
         INNER JOIN hr_employee e ON e.id = d.employee_id
         INNER JOIN hr_departure_type t ON t.id = d.departure_type_id
         WHERE d.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function hr_employee_departure_save(PDO $pdo, array $post): int
{
    hr_employee_departure_ensure_schema($pdo);
    $id = (int) ($post['id'] ?? 0);
    if ($id > 0) {
        $chk = hr_employee_departure_edit_check($pdo, $id);
        if (!$chk['can_edit']) {
            throw new RuntimeException((string) ($chk['message'] ?? 'لا يمكن التعديل.'));
        }
    }

    $parsed = hr_employee_departure_parse_row($pdo, $post, $id);

    if ($id > 0) {
        $st = $pdo->prepare(
            'UPDATE hr_employee_departure
             SET employee_id = ?, departure_type_id = ?, departure_date = ?,
                 time_from = ?, time_to = ?, notes = ?
             WHERE id = ?'
        );
        $st->execute([
            $parsed['employee_id'],
            $parsed['departure_type_id'],
            $parsed['departure_date'],
            $parsed['time_from'],
            $parsed['time_to'],
            $parsed['notes'],
            $id,
        ]);

        return $id;
    }

    $uid = (int) (current_user()['id'] ?? 0);
    $startedTx = !$pdo->inTransaction();
    if ($startedTx) {
        $pdo->beginTransaction();
    }

    try {
        $pdo->query('SELECT id FROM hr_employee_departure ORDER BY id DESC LIMIT 1 FOR UPDATE');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $voucherNo = hr_employee_departure_next_voucher_no($pdo);
            try {
                $st = $pdo->prepare(
                    'INSERT INTO hr_employee_departure
                        (voucher_no, employee_id, departure_type_id, departure_date, time_from, time_to, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $voucherNo,
                    $parsed['employee_id'],
                    $parsed['departure_type_id'],
                    $parsed['departure_date'],
                    $parsed['time_from'],
                    $parsed['time_to'],
                    $parsed['notes'],
                    $uid > 0 ? $uid : null,
                ]);
                $newId = (int) $pdo->lastInsertId();
                if ($startedTx) {
                    $pdo->commit();
                }

                return $newId;
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                $isDuplicateVoucher = str_contains($msg, '1062')
                    && str_contains($msg, 'uk_hr_emp_departure_voucher');
                if ($isDuplicateVoucher && $attempt < 4) {
                    continue;
                }
                throw $e;
            }
        }
    } catch (Throwable $e) {
        if ($startedTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    throw new RuntimeException('تعذر توليد رقم سند مغادرة جديد.');
}

function hr_employee_departure_delete(PDO $pdo, int $id): void
{
    $chk = hr_employee_departure_delete_check($pdo, $id);
    if (!$chk['can_delete']) {
        throw new RuntimeException((string) ($chk['message'] ?? 'لا يمكن الحذف.'));
    }
    $pdo->prepare('DELETE FROM hr_employee_departure WHERE id = ?')->execute([$id]);
}

function hr_employee_departure_post(PDO $pdo, int $id): void
{
    hr_employee_departure_ensure_schema($pdo);
    if ($id < 1) {
        throw new RuntimeException('سند المغادرة غير موجود.');
    }
    if (hr_employee_departure_is_posted($pdo, $id)) {
        throw new RuntimeException('المغادرة مرحّلة مسبقاً.');
    }

    $uid = (int) (current_user()['id'] ?? 0);
    $pdo->prepare(
        'UPDATE hr_employee_departure SET is_posted = 1, posted_at = NOW(), posted_by = ? WHERE id = ?'
    )->execute([$uid > 0 ? $uid : null, $id]);
}

function hr_employee_departure_unpost(PDO $pdo, int $id): void
{
    hr_employee_departure_ensure_schema($pdo);
    if ($id < 1) {
        throw new RuntimeException('سند المغادرة غير موجود.');
    }
    if (!hr_employee_departure_is_posted($pdo, $id)) {
        throw new RuntimeException('المغادرة غير مرحّلة.');
    }

    $pdo->prepare(
        'UPDATE hr_employee_departure SET is_posted = 0, posted_at = NULL, posted_by = NULL WHERE id = ?'
    )->execute([$id]);
}

/**
 * مغادرات مرحّلة مجمّعة حسب موظف وتاريخ — للتقرير.
 *
 * @return array<int, array<string, array{type_name:string}>>
 */
function hr_employee_departures_report_grouped(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    int $departmentId = 0,
    int $employeeId = 0
): array {
    hr_employee_departure_ensure_schema($pdo);

    $sql = 'SELECT d.employee_id, d.departure_date, t.name_ar AS type_name
            FROM hr_employee_departure d
            INNER JOIN hr_employee e ON e.id = d.employee_id
            INNER JOIN hr_departure_type t ON t.id = d.departure_type_id
            WHERE d.is_posted = 1
              AND d.departure_date >= ? AND d.departure_date <= ?';
    $params = [$dateFrom, $dateTo];

    if ($departmentId > 0) {
        $sql .= ' AND e.department_id = ?';
        $params[] = $departmentId;
    }
    if ($employeeId > 0) {
        $sql .= ' AND d.employee_id = ?';
        $params[] = $employeeId;
    }

    $sql .= ' ORDER BY d.employee_id ASC, d.departure_date ASC, d.time_from ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $grouped = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $empId = (int) ($row['employee_id'] ?? 0);
        $workDate = (string) ($row['departure_date'] ?? '');
        if ($empId < 1 || $workDate === '') {
            continue;
        }

        $typeName = trim((string) ($row['type_name'] ?? ''));

        if (!isset($grouped[$empId][$workDate])) {
            $grouped[$empId][$workDate] = ['type_name' => $typeName];
            continue;
        }

        $cur = $grouped[$empId][$workDate];
        if ($typeName !== '' && ($cur['type_name'] ?? '') === '') {
            $cur['type_name'] = $typeName;
        } elseif ($typeName !== '' && ($cur['type_name'] ?? '') !== $typeName) {
            $cur['type_name'] .= ' / ' . $typeName;
        }
        $grouped[$empId][$workDate] = $cur;
    }

    return $grouped;
}

/** @return list<int> */
function hr_employee_departure_search_ids_by_voucher_fragment(PDO $pdo, string $fragment, int $limit = 200): array
{
    require_once app_path('includes/doc_no_fragment_search.php');
    $fragment = trim($fragment);
    if ($fragment === '') {
        return [];
    }

    $limit = max(1, min(500, $limit));

    return doc_no_search_ids_like(
        $pdo,
        'SELECT id FROM hr_employee_departure
         WHERE voucher_no LIKE ?
         ORDER BY CAST(voucher_no AS UNSIGNED) ASC, id ASC
         LIMIT ' . $limit,
        [doc_no_sql_like_pattern($fragment)]
    );
}

/** @return array<string,mixed>|null */
function hr_employee_departure_lookup_by_voucher(PDO $pdo, string $no): ?array
{
    require_once app_path('includes/doc_no_fragment_search.php');

    return doc_no_fetch_exact_or_fragment(
        $pdo,
        $no,
        'SELECT id FROM hr_employee_departure WHERE voucher_no = ? LIMIT 1',
        [trim($no)],
        static fn (string $frag): array => hr_employee_departure_search_ids_by_voucher_fragment($pdo, $frag),
        static function (int $id) use ($pdo): ?array {
            $row = hr_employee_departure_get($pdo, $id);

            return $row ?: null;
        }
    );
}

/**
 * تنقّل بين سندات المغادرة حسب رقم السند تصاعدياً.
 *
 * @return array{prev:int,next:int,total:int,position:int}
 */
function hr_employee_departure_browse_nav(PDO $pdo, int $currentId): array
{
    hr_employee_departure_ensure_schema($pdo);
    $ids = [];
    try {
        $ids = array_map(
            'intval',
            $pdo->query(
                'SELECT id FROM hr_employee_departure
                 ORDER BY CAST(voucher_no AS UNSIGNED) ASC, id ASC'
            )->fetchAll(PDO::FETCH_COLUMN) ?: []
        );
    } catch (Throwable $e) {
        return ['prev' => 0, 'next' => 0, 'total' => 0, 'position' => 0];
    }

    $total = count($ids);
    if ($total === 0) {
        return ['prev' => 0, 'next' => 0, 'total' => 0, 'position' => 0];
    }

    if ($currentId < 1) {
        return [
            'prev' => $ids[$total - 1],
            'next' => $total > 1 ? $ids[$total - 2] : 0,
            'total' => $total,
            'position' => 0,
        ];
    }

    $pos = array_search($currentId, $ids, true);
    if ($pos === false) {
        return [
            'prev' => 0,
            'next' => $ids[$total - 1],
            'total' => $total,
            'position' => 0,
        ];
    }

    return [
        'prev' => $pos > 0 ? $ids[$pos - 1] : 0,
        'next' => $pos < $total - 1 ? $ids[$pos + 1] : 0,
        'total' => $total,
        'position' => $pos + 1,
    ];
}
