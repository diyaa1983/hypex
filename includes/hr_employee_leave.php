<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');
require_once app_path('includes/hr_leave_type.php');
require_once app_path('includes/hr_employee_leave_balance.php');

function hr_employee_leave_ensure_schema(PDO $pdo): void
{
    hr_employee_leave_balance_ensure_schema($pdo);

    try {
        $pdo->query('SELECT id FROM hr_employee_leave LIMIT 1');

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
        sql_migration_run_file($pdo, 'database/migrations/203_hr_employee_leaves.sql');
    } catch (Throwable $e) {
        // ignored
    }
}

function hr_employee_leave_next_voucher_no(PDO $pdo): string
{
    hr_employee_leave_ensure_schema($pdo);

    $maxVoucher = 0;
    $maxId = 0;

    try {
        $maxVoucher = (int) $pdo->query(
            'SELECT COALESCE(MAX(CAST(voucher_no AS UNSIGNED)), 0) FROM hr_employee_leave'
        )->fetchColumn();
    } catch (Throwable $e) {
        // ignore — fallback to max(id) below
    }

    try {
        $maxId = (int) $pdo->query(
            'SELECT COALESCE(MAX(id), 0) FROM hr_employee_leave'
        )->fetchColumn();
    } catch (Throwable $e) {
        // ignore
    }

    return (string) (max($maxVoucher, $maxId) + 1);
}

function hr_employee_leave_calc_days(string $dateFrom, string $dateTo): float
{
    try {
        $from = new DateTimeImmutable($dateFrom);
        $to = new DateTimeImmutable($dateTo);
    } catch (Throwable $e) {
        throw new RuntimeException('تواريخ الإجازة غير صالحة.');
    }
    if ($to < $from) {
        throw new RuntimeException('تاريخ نهاية الإجازة يجب أن يكون بعد تاريخ البداية.');
    }

    return (float) ($from->diff($to)->days + 1);
}

function hr_employee_leave_posted_label(int $isPosted): string
{
    return $isPosted === 1 ? 'مرحّل' : 'مسودة';
}

/**
 * @return array<string,mixed>
 */
function hr_employee_leave_parse_row(PDO $pdo, array $row, int $id = 0): array
{
    hr_employee_leave_ensure_schema($pdo);

    $employeeId = (int) ($row['employee_id'] ?? 0);
    if ($employeeId < 1) {
        throw new RuntimeException('اختر الموظف.');
    }

    $typeId = (int) ($row['leave_type_id'] ?? 0);
    if ($typeId < 1) {
        throw new RuntimeException('اختر نوع الإجازة.');
    }

    $stType = $pdo->prepare('SELECT id FROM hr_leave_type WHERE id = ? AND is_active = 1 LIMIT 1');
    $stType->execute([$typeId]);
    if (!$stType->fetchColumn()) {
        throw new RuntimeException('نوع الإجازة غير موجود أو غير نشط.');
    }

    $leaveDate = parse_date_to_iso(trim((string) ($row['leave_date'] ?? '')));
    $dateFrom = parse_date_to_iso(trim((string) ($row['date_from'] ?? '')));
    $dateTo = parse_date_to_iso(trim((string) ($row['date_to'] ?? '')));
    if ($leaveDate === null) {
        throw new RuntimeException('تاريخ الإجازة غير صالح.');
    }
    if ($dateFrom === null || $dateTo === null) {
        throw new RuntimeException('تواريخ بداية ونهاية الإجازة غير صالحة.');
    }

    $calcDays = hr_employee_leave_calc_days($dateFrom, $dateTo);
    $daysCount = round((float) str_replace(',', '.', trim((string) ($row['days_count'] ?? '0'))), 2);
    if ($daysCount <= 0) {
        throw new RuntimeException('عدد أيام الإجازة يجب أن يكون أكبر من صفر.');
    }
    if (abs($daysCount - $calcDays) > 0.001) {
        throw new RuntimeException(
            'عدد الأيام (' . number_format($daysCount, 2, '.', '')
            . ') لا يتوافق مع الفترة من ' . $dateFrom . ' إلى ' . $dateTo
            . ' (المتوقع: ' . number_format($calcDays, 2, '.', '') . ' يوم).'
        );
    }

    $notes = trim((string) ($row['notes'] ?? ''));

    return [
        'employee_id' => $employeeId,
        'leave_type_id' => $typeId,
        'leave_date' => $leaveDate,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'days_count' => $daysCount,
        'notes' => $notes !== '' ? $notes : null,
    ];
}

function hr_employee_leave_is_posted(PDO $pdo, int $id): bool
{
    if ($id < 1) {
        return false;
    }
    hr_employee_leave_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT is_posted FROM hr_employee_leave WHERE id = ? LIMIT 1');
    $st->execute([$id]);

    return (int) ($st->fetchColumn() ?: 0) === 1;
}

/** @return array{can_edit:bool,message:string} */
function hr_employee_leave_edit_check(PDO $pdo, int $id): array
{
    if ($id < 1) {
        return ['can_edit' => true, 'message' => ''];
    }
    if (hr_employee_leave_is_posted($pdo, $id)) {
        return ['can_edit' => false, 'message' => 'لا يمكن تعديل إجازة مرحّلة — فك الترحيل أولاً.'];
    }

    return ['can_edit' => true, 'message' => ''];
}

/** @return array{can_delete:bool,message:string} */
function hr_employee_leave_delete_check(PDO $pdo, int $id): array
{
    if ($id < 1) {
        return ['can_delete' => false, 'message' => 'معرّف غير صالح.'];
    }
    if (hr_employee_leave_is_posted($pdo, $id)) {
        return ['can_delete' => false, 'message' => 'لا يمكن حذف إجازة مرحّلة — فك الترحيل أولاً.'];
    }

    return ['can_delete' => true, 'message' => ''];
}

/** @return array<string,mixed>|null */
function hr_employee_leave_get(PDO $pdo, int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    hr_employee_leave_ensure_schema($pdo);
    $st = $pdo->prepare(
        'SELECT l.*, e.emp_code, e.name_ar AS employee_name, t.leave_code, t.name_ar AS type_name
         FROM hr_employee_leave l
         INNER JOIN hr_employee e ON e.id = l.employee_id
         INNER JOIN hr_leave_type t ON t.id = l.leave_type_id
         WHERE l.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @return list<array<string,mixed>>
 */
function hr_employee_leave_list(
    PDO $pdo,
    ?string $dateFrom = null,
    ?string $dateTo = null,
    int $employeeId = 0,
    int $limit = 500
): array {
    hr_employee_leave_ensure_schema($pdo);
    $limit = max(1, min(2000, $limit));

    $where = ['1=1'];
    $params = [];

    if ($dateFrom !== null && $dateFrom !== '') {
        $where[] = 'l.date_from >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== null && $dateTo !== '') {
        $where[] = 'l.date_to <= ?';
        $params[] = $dateTo;
    }
    if ($employeeId > 0) {
        $where[] = 'l.employee_id = ?';
        $params[] = $employeeId;
    }

    $sql = 'SELECT l.*, e.emp_code, e.name_ar AS employee_name, t.leave_code, t.name_ar AS type_name
            FROM hr_employee_leave l
            INNER JOIN hr_employee e ON e.id = l.employee_id
            INNER JOIN hr_leave_type t ON t.id = l.leave_type_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY l.date_from DESC, CAST(l.voucher_no AS UNSIGNED) DESC, l.id DESC
            LIMIT ' . $limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function hr_employee_leave_save(PDO $pdo, array $post): int
{
    hr_employee_leave_ensure_schema($pdo);
    $id = (int) ($post['id'] ?? 0);
    if ($id > 0) {
        $chk = hr_employee_leave_edit_check($pdo, $id);
        if (!$chk['can_edit']) {
            throw new RuntimeException((string) ($chk['message'] ?? 'لا يمكن التعديل.'));
        }
    }

    $parsed = hr_employee_leave_parse_row($pdo, $post, $id);

    if ($id > 0) {
        $pdo->prepare(
            'UPDATE hr_employee_leave
             SET employee_id = ?, leave_type_id = ?, leave_date = ?, date_from = ?, date_to = ?,
                 days_count = ?, notes = ?
             WHERE id = ?'
        )->execute([
            $parsed['employee_id'],
            $parsed['leave_type_id'],
            $parsed['leave_date'],
            $parsed['date_from'],
            $parsed['date_to'],
            $parsed['days_count'],
            $parsed['notes'],
            $id,
        ]);

        return $id;
    }

    $voucherNo = hr_employee_leave_next_voucher_no($pdo);
    $uid = (int) (current_user()['id'] ?? 0);
    $pdo->prepare(
        'INSERT INTO hr_employee_leave
            (voucher_no, employee_id, leave_type_id, leave_date, date_from, date_to, days_count, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $voucherNo,
        $parsed['employee_id'],
        $parsed['leave_type_id'],
        $parsed['leave_date'],
        $parsed['date_from'],
        $parsed['date_to'],
        $parsed['days_count'],
        $parsed['notes'],
        $uid > 0 ? $uid : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function hr_employee_leave_delete(PDO $pdo, int $id): void
{
    $chk = hr_employee_leave_delete_check($pdo, $id);
    if (!$chk['can_delete']) {
        throw new RuntimeException((string) ($chk['message'] ?? 'لا يمكن الحذف.'));
    }
    $pdo->prepare('DELETE FROM hr_employee_leave WHERE id = ?')->execute([$id]);
}

function hr_employee_leave_post(PDO $pdo, int $id): void
{
    hr_employee_leave_ensure_schema($pdo);
    $row = hr_employee_leave_get($pdo, $id);
    if (!$row) {
        throw new RuntimeException('سند الإجازة غير موجود.');
    }
    if ((int) ($row['is_posted'] ?? 0) === 1) {
        throw new RuntimeException('الإجازة مرحّلة مسبقاً.');
    }

    $employeeId = (int) ($row['employee_id'] ?? 0);
    $typeId = (int) ($row['leave_type_id'] ?? 0);
    $days = (float) ($row['days_count'] ?? 0);

    $pdo->beginTransaction();
    try {
        hr_employee_leave_balance_assert_can_take(
            $pdo,
            $employeeId,
            $typeId,
            $days,
            (string) ($row['date_from'] ?? '')
        );
        $uid = (int) (current_user()['id'] ?? 0);
        $pdo->prepare(
            'UPDATE hr_employee_leave SET is_posted = 1, posted_at = NOW(), posted_by = ? WHERE id = ?'
        )->execute([$uid > 0 ? $uid : null, $id]);
        $period = hr_employee_leave_balance_period_for_date((string) ($row['date_from'] ?? ''));
        hr_employee_leave_balance_refresh_taken(
            $pdo,
            $employeeId,
            $typeId,
            $period['from'],
            $period['to']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function hr_employee_leave_unpost(PDO $pdo, int $id): void
{
    hr_employee_leave_ensure_schema($pdo);
    $row = hr_employee_leave_get($pdo, $id);
    if (!$row) {
        throw new RuntimeException('سند الإجازة غير موجود.');
    }
    if ((int) ($row['is_posted'] ?? 0) !== 1) {
        throw new RuntimeException('الإجازة غير مرحّلة.');
    }

    $employeeId = (int) ($row['employee_id'] ?? 0);
    $typeId = (int) ($row['leave_type_id'] ?? 0);
    $period = hr_employee_leave_balance_period_for_date((string) ($row['date_from'] ?? ''));

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE hr_employee_leave SET is_posted = 0, posted_at = NULL, posted_by = NULL WHERE id = ?'
        )->execute([$id]);
        hr_employee_leave_balance_refresh_taken(
            $pdo,
            $employeeId,
            $typeId,
            $period['from'],
            $period['to']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * إجازات مرحّلة مجمّعة حسب موظف وتاريخ — للتقرير.
 *
 * @return array<int, array<string, array{type_name:string}>>
 */
function hr_employee_leaves_report_grouped(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    int $departmentId = 0,
    int $employeeId = 0
): array {
    hr_employee_leave_ensure_schema($pdo);

    $sql = 'SELECT l.employee_id, l.date_from, l.date_to, t.name_ar AS type_name
            FROM hr_employee_leave l
            INNER JOIN hr_employee e ON e.id = l.employee_id
            INNER JOIN hr_leave_type t ON t.id = l.leave_type_id
            WHERE l.is_posted = 1
              AND l.date_to >= ? AND l.date_from <= ?';
    $params = [$dateFrom, $dateTo];

    if ($departmentId > 0) {
        $sql .= ' AND e.department_id = ?';
        $params[] = $departmentId;
    }
    if ($employeeId > 0) {
        $sql .= ' AND l.employee_id = ?';
        $params[] = $employeeId;
    }

    $sql .= ' ORDER BY l.employee_id ASC, l.date_from ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    $grouped = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $empId = (int) ($row['employee_id'] ?? 0);
        $from = (string) ($row['date_from'] ?? '');
        $to = (string) ($row['date_to'] ?? '');
        $typeName = trim((string) ($row['type_name'] ?? ''));
        if ($empId < 1 || $from === '' || $to === '') {
            continue;
        }

        try {
            $cur = new DateTimeImmutable($from);
            $end = new DateTimeImmutable($to);
        } catch (Throwable $e) {
            continue;
        }
        if ($cur > $end) {
            continue;
        }
        while ($cur <= $end) {
            $workDate = $cur->format('Y-m-d');
            $cur = $cur->modify('+1 day');
            if ($workDate < $dateFrom || $workDate > $dateTo) {
                continue;
            }
            $grouped[$empId][$workDate] = ['type_name' => $typeName];
        }
    }

    return $grouped;
}

/** @return list<int> */
function hr_employee_leave_search_ids_by_voucher_fragment(PDO $pdo, string $fragment, int $limit = 200): array
{
    require_once app_path('includes/doc_no_fragment_search.php');
    $fragment = trim($fragment);
    if ($fragment === '') {
        return [];
    }

    $limit = max(1, min(500, $limit));

    return doc_no_search_ids_like(
        $pdo,
        'SELECT id FROM hr_employee_leave
         WHERE voucher_no LIKE ?
         ORDER BY CAST(voucher_no AS UNSIGNED) ASC, id ASC
         LIMIT ' . $limit,
        [doc_no_sql_like_pattern($fragment)]
    );
}

/** @return array<string,mixed>|null */
function hr_employee_leave_lookup_by_voucher(PDO $pdo, string $no): ?array
{
    require_once app_path('includes/doc_no_fragment_search.php');

    return doc_no_fetch_exact_or_fragment(
        $pdo,
        $no,
        'SELECT id FROM hr_employee_leave WHERE voucher_no = ? LIMIT 1',
        [trim($no)],
        static fn (string $frag): array => hr_employee_leave_search_ids_by_voucher_fragment($pdo, $frag),
        static function (int $id) use ($pdo): ?array {
            $row = hr_employee_leave_get($pdo, $id);

            return $row ?: null;
        }
    );
}

/**
 * تنقّل بين سندات الإجازة حسب رقم السند تصاعدياً.
 *
 * @return array{prev:int,next:int,total:int,position:int}
 */
function hr_employee_leave_browse_nav(PDO $pdo, int $currentId): array
{
    hr_employee_leave_ensure_schema($pdo);
    $ids = [];
    try {
        $ids = array_map(
            'intval',
            $pdo->query(
                'SELECT id FROM hr_employee_leave
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