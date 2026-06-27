<?php
declare(strict_types=1);

require_once app_path('includes/hr_schema.php');

function hr_employee_advance_ensure_post_columns(PDO $pdo): void
{
    hr_employee_advance_ensure_schema($pdo);

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM hr_employee_advance')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $colSet = [];
        foreach ($cols as $col) {
            $colSet[strtolower((string) ($col['Field'] ?? ''))] = true;
        }
        if (isset($colSet['is_posted'])) {
            return;
        }
    } catch (Throwable $e) {
        // continue to migration
    }

    try {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file_once($pdo, 'database/migrations/154_hr_employee_advance_posting.sql');
        sql_migration_run_file_once($pdo, 'database/migrations/164_hr_advance_disbursement.sql');
        sql_migration_run_file_once($pdo, 'database/migrations/165_hr_advance_disbursement_fix.sql');
    } catch (Throwable $e) {
        // ignored
    }
}

function hr_employee_advance_disbursement_columns_ready(PDO $pdo): bool
{
    hr_employee_advance_ensure_post_columns($pdo);
    try {
        $st = $pdo->query("SHOW COLUMNS FROM hr_employee_advance LIKE 'is_disbursed'");

        return (bool) $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function hr_employee_advance_post_columns_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    hr_employee_advance_ensure_post_columns($pdo);
    try {
        $st = $pdo->query("SHOW COLUMNS FROM hr_employee_advance LIKE 'is_posted'");
        $ready = (bool) ($st && $st->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        $ready = false;
    }

    return $ready;
}

function hr_employee_advance_ensure_schema(PDO $pdo): void
{
    hr_employee_ensure_schema($pdo);

    try {
        $pdo->query('SELECT id FROM hr_employee_advance LIMIT 1');
        $pdo->query('SELECT id FROM hr_salary_advance_deduction LIMIT 1');

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
        sql_migration_run_file($pdo, 'database/migrations/099_hr_employee_advances.sql');
    } catch (Throwable $e) {
        // ignored
    }
}

function hr_employee_advance_next_code(PDO $pdo): string
{
    hr_employee_advance_ensure_schema($pdo);
    try {
        $max = (int) $pdo->query(
            "SELECT COALESCE(MAX(CAST(advance_code AS UNSIGNED)), 0) FROM hr_employee_advance
             WHERE advance_code REGEXP '^[0-9]+$'"
        )->fetchColumn();
        $maxNum = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM hr_employee_advance')->fetchColumn();

        return (string) max($max, $maxNum) + 1;
    } catch (Throwable $e) {
        return '1';
    }
}

function hr_employee_advance_type_label(string $type): string
{
    return $type === 'long' ? 'سلفة طويلة' : 'سلفة لمرة واحدة';
}

function hr_employee_advance_status_label(string $status): string
{
    return match ($status) {
        'completed' => 'مكتملة',
        'cancelled' => 'ملغاة',
        default => 'فعّالة',
    };
}

function hr_employee_advance_posted_label(int $isPosted): string
{
    return $isPosted === 1 ? 'مرحّلة' : 'مسودة';
}

function hr_employee_advance_display_status(int $isPosted, string $status): string
{
    if ($status === 'cancelled') {
        return 'ملغاة';
    }

    $posted = hr_employee_advance_posted_label($isPosted);
    $repayment = hr_employee_advance_status_label($status);

    return $posted . ' — ' . $repayment;
}

function hr_employee_advance_is_posted(PDO $pdo, int $advanceId): bool
{
    if ($advanceId < 1) {
        return false;
    }

    if (!hr_employee_advance_post_columns_ready($pdo)) {
        return true;
    }

    hr_employee_advance_ensure_post_columns($pdo);
    try {
        $st = $pdo->prepare('SELECT is_posted FROM hr_employee_advance WHERE id = ? LIMIT 1');
        $st->execute([$advanceId]);

        return (int) ($st->fetchColumn() ?: 0) === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array{year:int, month:int} */
function hr_employee_advance_month_from_date(string $isoDate): array
{
    $isoDate = trim($isoDate);
    if ($isoDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $isoDate)) {
        return ['year' => 0, 'month' => 0];
    }

    return [
        'year' => (int) substr($isoDate, 0, 4),
        'month' => (int) substr($isoDate, 5, 2),
    ];
}

function hr_employee_advance_month_count(string $startIso, string $endIso): int
{
    $s = hr_employee_advance_month_from_date($startIso);
    $e = hr_employee_advance_month_from_date($endIso);
    if ($s['year'] < 1 || $s['month'] < 1 || $e['year'] < 1 || $e['month'] < 1) {
        return 1;
    }

    return max(1, ($e['year'] - $s['year']) * 12 + ($e['month'] - $s['month']) + 1);
}

function hr_employee_advance_month_in_range(int $year, int $month, string $startIso, string $endIso): bool
{
    $s = hr_employee_advance_month_from_date($startIso);
    $e = hr_employee_advance_month_from_date($endIso);
    if ($s['year'] < 1 || $e['year'] < 1) {
        return false;
    }

    $cur = $year * 12 + $month;
    $from = $s['year'] * 12 + $s['month'];
    $to = $e['year'] * 12 + $e['month'];

    return $cur >= $from && $cur <= $to;
}

function hr_employee_advance_installment_amount(float $total, int $months): float
{
    return round($total / max(1, $months), 3);
}

/** @return list<array{year:int, month:int}> */
function hr_employee_advance_months_in_period(string $startIso, string $endIso): array
{
    $s = hr_employee_advance_month_from_date($startIso);
    $e = hr_employee_advance_month_from_date($endIso);
    if ($s['year'] < 1 || $s['month'] < 1 || $e['year'] < 1 || $e['month'] < 1) {
        return [];
    }

    $months = [];
    $curYear = $s['year'];
    $curMonth = $s['month'];
    $endKey = $e['year'] * 12 + $e['month'];

    while ($curYear * 12 + $curMonth <= $endKey) {
        $months[] = ['year' => $curYear, 'month' => $curMonth];
        $curMonth++;
        if ($curMonth > 12) {
            $curMonth = 1;
            $curYear++;
        }
    }

    return $months;
}

/** @return array{is_posted:bool}|null */
function hr_employee_advance_employee_payroll_month(PDO $pdo, int $employeeId, int $year, int $month): ?array
{
    if ($employeeId < 1 || $year < 2000 || $month < 1 || $month > 12) {
        return null;
    }

    try {
        $st = $pdo->prepare(
            'SELECT is_posted FROM hr_salary
             WHERE employee_id = ? AND pay_year = ? AND pay_month = ?
             LIMIT 1'
        );
        $st->execute([$employeeId, $year, $month]);
        $posted = $st->fetchColumn();
        if ($posted === false) {
            return null;
        }

        return ['is_posted' => (int) $posted === 1];
    } catch (Throwable $e) {
        return null;
    }
}

function hr_employee_advance_filter_month_block_message(
    PDO $pdo,
    int $employeeId,
    int $year,
    int $month
): string {
    if ($employeeId < 1 || $year < 2000 || $month < 1 || $month > 12) {
        return '';
    }

    require_once app_path('includes/hr_salary.php');

    $payroll = hr_employee_advance_employee_payroll_month($pdo, $employeeId, $year, $month);
    if ($payroll === null) {
        return '';
    }

    $periodLabel = hr_salary_period_label_ar($year, $month);
    $statusLabel = !empty($payroll['is_posted']) ? 'مرحّل' : 'محتسب';

    return 'لا يمكن إضافة سلفة — راتب الموظف لشهر '
        . $periodLabel
        . ' '
        . $statusLabel
        . ' مسبقاً.';
}

/**
 * @param array{employee_id:int, advance_type:string, start_date:string, end_date:?string} $parsed
 */
function hr_employee_advance_payroll_months_block_message(PDO $pdo, array $parsed): string
{
    require_once app_path('includes/hr_salary.php');

    $employeeId = (int) ($parsed['employee_id'] ?? 0);
    $type = (string) ($parsed['advance_type'] ?? '');
    $startIso = (string) ($parsed['start_date'] ?? '');
    $endIso = (string) ($parsed['end_date'] ?? $startIso);

    if ($employeeId < 1 || $startIso === '') {
        return '';
    }

    $months = hr_employee_advance_months_in_period($startIso, $endIso);
    if ($months === [] && $type === 'once') {
        $months = [hr_employee_advance_month_from_date($startIso)];
    }

    foreach ($months as $m) {
        $year = (int) ($m['year'] ?? 0);
        $month = (int) ($m['month'] ?? 0);
        if ($year < 2000 || $month < 1 || $month > 12) {
            continue;
        }

        $payroll = hr_employee_advance_employee_payroll_month($pdo, $employeeId, $year, $month);
        if ($payroll === null) {
            continue;
        }

        $periodLabel = hr_salary_period_label_ar($year, $month);
        $statusLabel = !empty($payroll['is_posted']) ? 'مرحّل' : 'محتسب';

        return 'لا يمكن تعديل السلفة — راتب الموظف لشهر '
            . $periodLabel
            . ' '
            . $statusLabel
            . ' مسبقاً.';
    }

    return '';
}

/**
 * @param array{employee_id:int, advance_type:string, start_date:string, end_date:?string} $parsed
 */
function hr_employee_advance_assert_payroll_months_available(PDO $pdo, array $parsed): void
{
    $message = hr_employee_advance_payroll_months_block_message($pdo, $parsed);
    if ($message !== '') {
        throw new RuntimeException(str_replace('تعديل', 'إنشاء أو تعديل', $message));
    }
}

/**
 * @return array{
 *   advance_type:string,
 *   total_amount:float,
 *   start_date:string,
 *   end_date:?string,
 *   employee_id:int,
 *   notes:?string
 * }
 */
function hr_employee_advance_parse_row(array $row, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $type = (string) ($row['advance_type'] ?? '');
    if (!in_array($type, ['once', 'long'], true)) {
        throw new RuntimeException('حدد نوع السلفة: لمرة واحدة أو طويلة.');
    }

    $employeeId = (int) ($row['employee_id'] ?? 0);
    if ($employeeId < 1) {
        throw new RuntimeException('اختر الموظف.');
    }

    $amount = round((float) str_replace(',', '', (string) ($row['total_amount'] ?? '0')), 3);
    if ($amount <= 0) {
        throw new RuntimeException('مبلغ السلفة يجب أن يكون أكبر من صفر.');
    }

    $notes = trim((string) ($row['notes'] ?? ''));

    if ($type === 'once') {
        $deductIso = parse_date_to_iso(trim((string) ($row['deduct_date'] ?? '')));
        if ($deductIso === null) {
            throw new RuntimeException('أدخل تاريخ شهر الاقتطاع (يوم-شهر-سنة).');
        }

        $parsed = [
            'advance_type' => 'once',
            'total_amount' => $amount,
            'start_date' => $deductIso,
            'end_date' => $deductIso,
            'employee_id' => $employeeId,
            'notes' => $notes !== '' ? $notes : null,
        ];
        hr_employee_advance_assert_payroll_months_available($pdo, $parsed);

        return $parsed;
    }

    $startIso = parse_date_to_iso(trim((string) ($row['start_date'] ?? '')));
    $endIso = parse_date_to_iso(trim((string) ($row['end_date'] ?? '')));
    if ($startIso === null || $endIso === null) {
        throw new RuntimeException('أدخل تاريخ بداية ونهاية السلفة الطويلة.');
    }
    if ($endIso < $startIso) {
        throw new RuntimeException('تاريخ النهاية يجب أن يكون بعد تاريخ البداية أو مساوياً له.');
    }

    $parsed = [
        'advance_type' => 'long',
        'total_amount' => $amount,
        'start_date' => $startIso,
        'end_date' => $endIso,
        'employee_id' => $employeeId,
        'notes' => $notes !== '' ? $notes : null,
    ];
    hr_employee_advance_assert_payroll_months_available($pdo, $parsed);

    return $parsed;
}

/** @return array{can_delete:bool, message:string} */
function hr_employee_advance_delete_check(PDO $pdo, int $advanceId): array
{
    hr_employee_advance_ensure_schema($pdo);
    hr_employee_advance_ensure_post_columns($pdo);
    if ($advanceId < 1) {
        return ['can_delete' => false, 'message' => 'سلفة غير موجودة.'];
    }

    try {
        if (hr_employee_advance_is_posted($pdo, $advanceId)) {
            return [
                'can_delete' => false,
                'message' => 'لا يمكن حذف السلفة بعد ترحيلها — فك الترحيل أولاً.',
            ];
        }

        $st = $pdo->prepare('SELECT COUNT(*) FROM hr_salary_advance_deduction WHERE advance_id = ?');
        $st->execute([$advanceId]);
        if ((int) $st->fetchColumn() > 0) {
            return [
                'can_delete' => false,
                'message' => 'لا يمكن حذف السلفة بعد اقتطاعها من راتب موظف.',
            ];
        }

        if (hr_employee_advance_disbursement_columns_ready($pdo)) {
            $stV = $pdo->prepare(
                'SELECT disbursement_voucher_id FROM hr_employee_advance WHERE id = ? LIMIT 1'
            );
            $stV->execute([$advanceId]);
            $linkedVoucherId = (int) $stV->fetchColumn();
            if ($linkedVoucherId > 0) {
                return [
                    'can_delete' => false,
                    'message' => 'لا يمكن حذف السلفة: مرتبطة بسند صرف من المحاسبة.',
                ];
            }
        }
    } catch (Throwable $e) {
        // ignored
    }

    return ['can_delete' => true, 'message' => ''];
}

/** @return array{can_edit:bool, message:string} */
function hr_employee_advance_edit_check(PDO $pdo, int $advanceId): array
{
    hr_employee_advance_ensure_schema($pdo);
    hr_employee_advance_ensure_post_columns($pdo);
    if ($advanceId < 1) {
        return ['can_edit' => false, 'message' => 'سلفة غير موجودة.'];
    }

    try {
        $st = $pdo->prepare(
            'SELECT employee_id, advance_type, start_date, end_date, status
             FROM hr_employee_advance WHERE id = ? LIMIT 1'
        );
        $st->execute([$advanceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['can_edit' => false, 'message' => 'سلفة غير موجودة.'];
        }

        if ((string) ($row['status'] ?? '') === 'cancelled') {
            return ['can_edit' => false, 'message' => 'السلفة ملغاة ولا يمكن تعديلها.'];
        }

        if (hr_employee_advance_is_posted($pdo, $advanceId)) {
            return [
                'can_edit' => false,
                'message' => 'السلفة مرحّلة — فك الترحيل أولاً لتعديلها.',
            ];
        }

        $deleteCheck = hr_employee_advance_delete_check($pdo, $advanceId);
        if (!$deleteCheck['can_delete']) {
            return ['can_edit' => false, 'message' => (string) $deleteCheck['message']];
        }

        $payrollMsg = hr_employee_advance_payroll_months_block_message($pdo, [
            'employee_id' => (int) ($row['employee_id'] ?? 0),
            'advance_type' => (string) ($row['advance_type'] ?? 'once'),
            'start_date' => (string) ($row['start_date'] ?? ''),
            'end_date' => (string) ($row['end_date'] ?? ''),
        ]);
        if ($payrollMsg !== '') {
            return ['can_edit' => false, 'message' => $payrollMsg];
        }
    } catch (Throwable $e) {
        return ['can_edit' => false, 'message' => 'تعذر التحقق من إمكانية التعديل.'];
    }

    return ['can_edit' => true, 'message' => ''];
}

/** @return array{can_unpost:bool, message:string} */
function hr_employee_advance_unpost_check(PDO $pdo, int $advanceId): array
{
    hr_employee_advance_ensure_post_columns($pdo);
    if ($advanceId < 1) {
        return ['can_unpost' => false, 'message' => 'سلفة غير موجودة.'];
    }
    if (!hr_employee_advance_is_posted($pdo, $advanceId)) {
        return ['can_unpost' => false, 'message' => 'السلفة غير مرحّلة.'];
    }

    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM hr_salary_advance_deduction WHERE advance_id = ?');
        $st->execute([$advanceId]);
        if ((int) $st->fetchColumn() > 0) {
            return [
                'can_unpost' => false,
                'message' => 'لا يمكن فك ترحيل السلفة بعد اقتطاعها من الراتب.',
            ];
        }
    } catch (Throwable $e) {
        // ignored
    }

    if (hr_employee_advance_disbursement_columns_ready($pdo)) {
        try {
            $st = $pdo->prepare(
                'SELECT disbursement_voucher_id FROM hr_employee_advance WHERE id = ? LIMIT 1'
            );
            $st->execute([$advanceId]);
            $linkedVoucherId = (int) $st->fetchColumn();
            if ($linkedVoucherId > 0) {
                return [
                    'can_unpost' => false,
                    'message' => 'لا يمكن فك ترحيل السلفة: مرتبطة بسند صرف من المحاسبة (#' . $linkedVoucherId . ').',
                ];
            }
        } catch (Throwable $e) {
            // ignored
        }
    }

    return ['can_unpost' => true, 'message' => ''];
}

/** @return array<string, mixed> */
function hr_employee_advance_load(PDO $pdo, int $advanceId): ?array
{
    if ($advanceId < 1) {
        return null;
    }

    hr_employee_advance_ensure_post_columns($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT a.*, e.emp_code, e.name_ar
             FROM hr_employee_advance a
             INNER JOIN hr_employee e ON e.id = a.employee_id
             WHERE a.id = ?
             LIMIT 1'
        );
        $st->execute([$advanceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function hr_employee_advance_post(PDO $pdo, int $advanceId): void
{
    require_once app_path('includes/hr_employee_advance_gl.php');

    hr_employee_advance_ensure_post_columns($pdo);
    hr_employee_advance_gl_ensure_rule($pdo);

    $advance = hr_employee_advance_load($pdo, $advanceId);
    if (!$advance) {
        throw new RuntimeException('السلفة غير موجودة.');
    }
    if ((int) ($advance['is_posted'] ?? 0) === 1) {
        throw new RuntimeException('السلفة مرحّلة مسبقاً.');
    }
    if ((string) ($advance['status'] ?? '') === 'cancelled') {
        throw new RuntimeException('لا يمكن ترحيل سلفة ملغاة.');
    }

    hr_employee_advance_assert_payroll_months_available($pdo, [
        'employee_id' => (int) ($advance['employee_id'] ?? 0),
        'advance_type' => (string) ($advance['advance_type'] ?? ''),
        'start_date' => (string) ($advance['start_date'] ?? ''),
        'end_date' => (string) ($advance['end_date'] ?? ''),
    ]);

    if (acc_gl_is_ready($pdo)) {
        $settings = acc_gl_load_settings($pdo);
        $recvId = (int) ($settings[HR_EMPLOYEE_ADVANCE_RECEIVABLE_RULE]['account_id'] ?? 0);
        $payId = (int) ($settings[HR_EMPLOYEE_ADVANCE_PAYABLE_RULE]['account_id'] ?? 0);
        if ($recvId < 1) {
            throw new RuntimeException('حساب «ذمة سلف الموظفين» غير مربوط في إعدادات الترحيل.');
        }
        if ($payId < 1) {
            throw new RuntimeException('حساب «سلف موظفين مستحقة الصرف» غير مربوط. راجع ربط الحسابات أو نفّذ ترحيل قاعدة البيانات.');
        }
    }

    $pdo->beginTransaction();
    try {
        $gl = hr_employee_advance_gl_post($pdo, $advanceId, $advance);
        if (!$gl['ok'] && !$gl['skipped']) {
            throw new RuntimeException((string) ($gl['error'] ?? 'تعذر الترحيل المحاسبي للسلفة.'));
        }

        $uid = (int) (current_user()['id'] ?? 0) ?: null;
        $pdo->prepare(
            'UPDATE hr_employee_advance SET is_posted = 1, posted_at = NOW(), posted_by = ? WHERE id = ?'
        )->execute([$uid, $advanceId]);

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function hr_employee_advance_unpost(PDO $pdo, int $advanceId): void
{
    require_once app_path('includes/hr_employee_advance_gl.php');

    hr_employee_advance_ensure_post_columns($pdo);
    hr_employee_advance_gl_ensure_rule($pdo);

    $unpostCheck = hr_employee_advance_unpost_check($pdo, $advanceId);
    if (!$unpostCheck['can_unpost']) {
        throw new RuntimeException((string) ($unpostCheck['message'] ?? 'لا يمكن فك ترحيل السلفة.'));
    }

    $pdo->beginTransaction();
    try {
        $gl = hr_employee_advance_gl_unpost($pdo, $advanceId);
        if (!$gl['ok'] && !$gl['skipped']) {
            throw new RuntimeException((string) ($gl['error'] ?? 'تعذر فك الترحيل المحاسبي للسلفة.'));
        }

        $pdo->prepare(
            'UPDATE hr_employee_advance SET is_posted = 0, posted_at = NULL, posted_by = NULL WHERE id = ?'
        )->execute([$advanceId]);

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function hr_employee_advance_sync_status(PDO $pdo, int $advanceId): void
{
    if ($advanceId < 1) {
        return;
    }

    hr_employee_advance_ensure_schema($pdo);

    $st = $pdo->prepare(
        'SELECT advance_type, total_amount, status FROM hr_employee_advance WHERE id = ? LIMIT 1'
    );
    $st->execute([$advanceId]);
    $adv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$adv || (string) ($adv['status'] ?? '') === 'cancelled') {
        return;
    }

    $stSum = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0) FROM hr_salary_advance_deduction WHERE advance_id = ?'
    );
    $stSum->execute([$advanceId]);
    $deducted = round((float) $stSum->fetchColumn(), 3);
    $total = round((float) ($adv['total_amount'] ?? 0), 3);

    $newStatus = 'active';
    if ($deducted <= 0.0005) {
        $newStatus = 'active';
    } elseif ((string) ($adv['advance_type'] ?? '') === 'once') {
        $newStatus = 'completed';
    } elseif ($deducted + 0.001 >= $total) {
        $newStatus = 'completed';
    } else {
        $newStatus = 'active';
    }

    $pdo->prepare('UPDATE hr_employee_advance SET status = ? WHERE id = ?')
        ->execute([$newStatus, $advanceId]);
}

function hr_employee_advance_total_deducted(PDO $pdo, int $advanceId): float
{
    if ($advanceId < 1) {
        return 0.0;
    }

    hr_employee_advance_ensure_schema($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM hr_salary_advance_deduction WHERE advance_id = ?'
        );
        $st->execute([$advanceId]);

        return round((float) $st->fetchColumn(), 3);
    } catch (Throwable $e) {
        return 0.0;
    }
}

/** قيد راتب مرتبط باقتطاع السلفة لنفس شهر الرواتب (إن وُجد). */
function hr_employee_advance_deduction_salary_id_for_month(
    PDO $pdo,
    int $advanceId,
    int $year,
    int $month
): int {
    if ($advanceId < 1 || $year < 2000 || $month < 1 || $month > 12) {
        return 0;
    }

    hr_employee_advance_ensure_schema($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT sad.salary_id
             FROM hr_salary_advance_deduction sad
             INNER JOIN hr_salary s ON s.id = sad.salary_id
             WHERE sad.advance_id = ? AND s.pay_year = ? AND s.pay_month = ?
             LIMIT 1'
        );
        $st->execute([$advanceId, $year, $month]);

        return (int) ($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * اقتطاعات السلف للشهر (للموظف) عند الاحتساب.
 *
 * @return array{total:float, lines:list<array{advance_id:int, label:string, amount:float}>}
 */
function hr_employee_advance_deductions_for_month(
    PDO $pdo,
    int $employeeId,
    int $year,
    int $month,
    int $currentSalaryId = 0
): array {
    hr_employee_advance_ensure_schema($pdo);
    hr_employee_advance_ensure_post_columns($pdo);
    if ($employeeId < 1 || $year < 2000 || $month < 1 || $month > 12) {
        return ['total' => 0.0, 'lines' => []];
    }

    $postCols = hr_employee_advance_post_columns_ready($pdo) ? ', is_posted' : '';
    $postFilter = hr_employee_advance_post_columns_ready($pdo) ? ' AND COALESCE(is_posted, 0) = 1' : '';

    $st = $pdo->prepare(
        'SELECT id, advance_type, total_amount, start_date, end_date, status' . $postCols . '
         FROM hr_employee_advance
         WHERE employee_id = ?
           AND COALESCE(NULLIF(TRIM(status), \'\'), \'active\') <> \'cancelled\''
        . $postFilter . '
         ORDER BY id ASC'
    );
    $st->execute([$employeeId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /** @var array<int, array{advance_id:int, label:string, amount:float}> $lineMap */
    $lineMap = [];

    foreach ($rows as $adv) {
        $aid = (int) ($adv['id'] ?? 0);
        if ($aid < 1) {
            continue;
        }

        $type = (string) ($adv['advance_type'] ?? '');
        $amt = 0.0;
        $label = hr_employee_advance_type_label($type);

        if ($type === 'once') {
            if ((string) ($adv['status'] ?? '') === 'cancelled') {
                continue;
            }
            $m = hr_employee_advance_month_from_date((string) ($adv['start_date'] ?? ''));
            if ($m['year'] === $year && $m['month'] === $month) {
                $chk = $pdo->prepare(
                    'SELECT sad.salary_id FROM hr_salary_advance_deduction sad WHERE sad.advance_id = ? LIMIT 1'
                );
                $chk->execute([$aid]);
                $existingSalId = (int) ($chk->fetchColumn() ?: 0);
                if ($existingSalId > 0 && $existingSalId !== $currentSalaryId) {
                    continue;
                }
                $amt = round((float) ($adv['total_amount'] ?? 0), 3);
            }
        } elseif ($type === 'long') {
            if ((string) ($adv['status'] ?? '') === 'cancelled') {
                continue;
            }
            $start = (string) ($adv['start_date'] ?? '');
            $end = (string) ($adv['end_date'] ?? $start);
            if (!hr_employee_advance_month_in_range($year, $month, $start, $end)) {
                continue;
            }

            $totalAdv = round((float) ($adv['total_amount'] ?? 0), 3);
            $remaining = round($totalAdv - hr_employee_advance_total_deducted($pdo, $aid), 3);
            if ($remaining <= 0.0005) {
                continue;
            }

            $monthSalId = hr_employee_advance_deduction_salary_id_for_month($pdo, $aid, $year, $month);
            if ($monthSalId > 0 && $monthSalId !== $currentSalaryId) {
                continue;
            }

            $months = hr_employee_advance_month_count($start, $end);
            $installment = hr_employee_advance_installment_amount($totalAdv, $months);
            $amt = round(min($installment, $remaining), 3);
            $label = 'سلفة طويلة (' . $months . ' أشهر)';

            if ((string) ($adv['status'] ?? '') === 'completed' && $remaining > 0.0005) {
                hr_employee_advance_sync_status($pdo, $aid);
            }
        }

        if ($amt <= 0.0005) {
            continue;
        }

        $lineMap[$aid] = ['advance_id' => $aid, 'label' => $label, 'amount' => $amt];
    }

    // Keep deductions already linked to this salary row visible in posting grid,
    // then overlay them on top of preview lines to avoid duplicate counting.
    if ($currentSalaryId > 0) {
        try {
            $linked = $pdo->prepare(
                'SELECT sad.advance_id, sad.amount, a.advance_type, a.start_date, a.end_date
                 FROM hr_salary_advance_deduction sad
                 INNER JOIN hr_employee_advance a ON a.id = sad.advance_id
                 WHERE sad.salary_id = ?
                 ORDER BY sad.id ASC'
            );
            $linked->execute([$currentSalaryId]);
            foreach ($linked->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $aid = (int) ($row['advance_id'] ?? 0);
                $amt = round((float) ($row['amount'] ?? 0), 3);
                if ($aid < 1 || $amt <= 0.0005) {
                    continue;
                }

                $type = (string) ($row['advance_type'] ?? '');
                $label = hr_employee_advance_type_label($type);
                if ($type === 'long') {
                    $months = hr_employee_advance_month_count(
                        (string) ($row['start_date'] ?? ''),
                        (string) ($row['end_date'] ?? '')
                    );
                    $label = 'سلفة طويلة (' . $months . ' أشهر)';
                }

                $lineMap[$aid] = [
                    'advance_id' => $aid,
                    'label' => $label,
                    'amount' => $amt,
                ];
            }
        } catch (Throwable $e) {
            // ignored
        }
    }

    $lines = array_values($lineMap);
    $total = 0.0;
    foreach ($lines as $line) {
        $total += (float) ($line['amount'] ?? 0);
    }

    return ['total' => round($total, 3), 'lines' => $lines];
}

/** @param list<array{advance_id:int, label:string, amount:float}> $lines */
function hr_employee_advance_apply_to_salary(PDO $pdo, int $salaryId, array $lines): void
{
    hr_employee_advance_ensure_schema($pdo);
    if ($salaryId < 1) {
        return;
    }

    $pdo->prepare('DELETE FROM hr_salary_advance_deduction WHERE salary_id = ?')->execute([$salaryId]);

    if (!$lines) {
        return;
    }

    $st = $pdo->prepare(
        'INSERT INTO hr_salary_advance_deduction (salary_id, advance_id, amount) VALUES (?, ?, ?)'
    );
    $advanceIds = [];
    foreach ($lines as $ln) {
        $aid = (int) ($ln['advance_id'] ?? 0);
        if ($aid < 1) {
            continue;
        }
        $st->execute([$salaryId, $aid, (float) ($ln['amount'] ?? 0)]);
        $advanceIds[$aid] = true;
    }

    foreach (array_keys($advanceIds) as $aid) {
        hr_employee_advance_sync_status($pdo, (int) $aid);
    }
}

/** @return list<array{name_ar:string, amount:float}> */
function hr_salary_advance_deduction_lines(PDO $pdo, int $salaryId): array
{
    hr_employee_advance_ensure_schema($pdo);
    if ($salaryId < 1) {
        return [];
    }

    try {
        $st = $pdo->prepare(
            'SELECT sad.amount, a.advance_type, a.start_date, a.end_date
             FROM hr_salary_advance_deduction sad
             INNER JOIN hr_employee_advance a ON a.id = sad.advance_id
             WHERE sad.salary_id = ?
             ORDER BY sad.id ASC'
        );
        $st->execute([$salaryId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $type = (string) ($r['advance_type'] ?? '');
            $label = hr_employee_advance_type_label($type);
            if ($type === 'long') {
                $months = hr_employee_advance_month_count(
                    (string) ($r['start_date'] ?? ''),
                    (string) ($r['end_date'] ?? '')
                );
                $label = 'سلفة طويلة (' . $months . ' أشهر)';
            }
            $out[] = [
                'name_ar' => $label,
                'amount' => (float) ($r['amount'] ?? 0),
            ];
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<int> */
function hr_employee_advance_ids_for_salary_month(PDO $pdo, int $year, int $month): array
{
    hr_employee_advance_ensure_schema($pdo);

    try {
        $st = $pdo->prepare(
            'SELECT DISTINCT sad.advance_id
             FROM hr_salary_advance_deduction sad
             INNER JOIN hr_salary s ON s.id = sad.salary_id
             WHERE s.pay_year = ? AND s.pay_month = ?'
        );
        $st->execute([$year, $month]);
        $ids = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $aid = (int) ($row['advance_id'] ?? 0);
            if ($aid > 0) {
                $ids[] = $aid;
            }
        }

        return $ids;
    } catch (Throwable $e) {
        return [];
    }
}

/** إعادة حالة السلف بعد حذف قيود شهر (فك ترحيل). */
function hr_employee_advance_resync_after_salary_month_deleted(PDO $pdo, array $advanceIds): void
{
    foreach ($advanceIds as $aid) {
        if ((int) $aid > 0) {
            hr_employee_advance_sync_status($pdo, (int) $aid);
        }
    }
}

/** @return list<array<string, mixed>> */
function hr_employee_advances_for_period(PDO $pdo, int $year, int $month, int $employeeId = 0): array
{
    if ($year < 2000 || $month < 1 || $month > 12) {
        return [];
    }
    hr_employee_advance_ensure_schema($pdo);
    hr_employee_advance_ensure_post_columns($pdo);

    $monthPad = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
    $params = [$year, $month, $year, $monthPad, $year, $monthPad];
    $empFilter = '';
    if ($employeeId > 0) {
        $empFilter = ' AND a.employee_id = ?';
        $params[] = $employeeId;
    }

    try {
        $st = $pdo->prepare(
            'SELECT a.id, a.advance_code, a.employee_id, a.advance_type, a.total_amount,
                    a.start_date, a.end_date, a.notes, a.status, a.is_posted,
                    e.emp_code, e.name_ar AS emp_name
             FROM hr_employee_advance a
             INNER JOIN hr_employee e ON e.id = a.employee_id
             WHERE a.start_date IS NOT NULL
               AND (
                 (COALESCE(a.advance_type, \'once\') = \'once\'
                  AND YEAR(a.start_date) = ? AND MONTH(a.start_date) = ?)
                 OR (
                   a.advance_type = \'long\'
                   AND a.end_date IS NOT NULL
                   AND a.start_date <= LAST_DAY(CONCAT(?, \'-\', ?, \'-01\'))
                   AND a.end_date >= CONCAT(?, \'-\', ?, \'-01\')
                 )
               )'
            . $empFilter
            . ' ORDER BY e.name_ar ASC, CAST(a.advance_code AS UNSIGNED) ASC, a.id DESC'
        );
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function hr_employee_advances_count_for_period(PDO $pdo, int $year, int $month, int $employeeId = 0): int
{
    return count(hr_employee_advances_for_period($pdo, $year, $month, $employeeId));
}

/** @return list<array<string, mixed>> */
function hr_employee_advances_pending_disbursement(PDO $pdo, int $employeeId): array
{
    if ($employeeId < 1) {
        return [];
    }
    hr_employee_advance_ensure_post_columns($pdo);
    if (!hr_employee_advance_post_columns_ready($pdo)) {
        return [];
    }

    $disbursedFilter = hr_employee_advance_disbursement_columns_ready($pdo)
        ? ' AND (a.disbursement_voucher_id IS NULL OR a.disbursement_voucher_id = 0)'
        : '';

    try {
        $st = $pdo->prepare(
            'SELECT a.id, a.advance_code, a.advance_type, a.total_amount, a.start_date, a.end_date, a.notes
             FROM hr_employee_advance a
             WHERE a.employee_id = ?
               AND COALESCE(a.is_posted, 0) = 1'
            . $disbursedFilter
            . " AND COALESCE(NULLIF(TRIM(a.status), ''), 'active') <> 'cancelled'
             ORDER BY a.start_date ASC, a.id ASC"
        );
        $st->execute([$employeeId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $startIso = (string) ($row['start_date'] ?? '');
            $endIso = (string) ($row['end_date'] ?? '');
            $periodLabel = $startIso;
            if ($endIso !== '' && $endIso !== $startIso) {
                $periodLabel = $startIso . ' → ' . $endIso;
            }
            if ($startIso !== '' && preg_match('/^(\d{4})-(\d{2})/', $startIso, $m)) {
                $periodLabel = 'شهر ' . (int) $m[2] . ' / ' . $m[1]
                    . ($endIso !== '' && $endIso !== $startIso ? ' (أقساط)' : '');
            }
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'advance_code' => (string) ($row['advance_code'] ?? ''),
                'advance_type' => (string) ($row['advance_type'] ?? ''),
                'advance_type_label' => hr_employee_advance_type_label((string) ($row['advance_type'] ?? '')),
                'total_amount' => round((float) ($row['total_amount'] ?? 0), 3),
                'start_date' => $startIso,
                'end_date' => $endIso,
                'period_label' => $periodLabel,
                'notes' => (string) ($row['notes'] ?? ''),
            ];
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array<string, mixed>> */
function hr_employee_advances_pending_disbursement_all(PDO $pdo): array
{
    hr_employee_advance_ensure_post_columns($pdo);
    if (!hr_employee_advance_post_columns_ready($pdo)) {
        return [];
    }

    $disbursedFilter = hr_employee_advance_disbursement_columns_ready($pdo)
        ? ' AND (a.disbursement_voucher_id IS NULL OR a.disbursement_voucher_id = 0)'
        : '';

    try {
        $st = $pdo->query(
            'SELECT a.id, a.advance_code, a.advance_type, a.total_amount, a.start_date, a.end_date, a.notes,
                    a.posted_at, a.employee_id, e.emp_code, e.name_ar AS emp_name
             FROM hr_employee_advance a
             INNER JOIN hr_employee e ON e.id = a.employee_id
             WHERE COALESCE(a.is_posted, 0) = 1'
            . $disbursedFilter
            . " AND COALESCE(NULLIF(TRIM(a.status), ''), 'active') <> 'cancelled'
             ORDER BY COALESCE(a.posted_at, a.created_at) DESC, a.id DESC"
        );
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $startIso = (string) ($row['start_date'] ?? '');
            $endIso = (string) ($row['end_date'] ?? '');
            $periodLabel = $startIso;
            if ($endIso !== '' && $endIso !== $startIso) {
                $periodLabel = $startIso . ' → ' . $endIso;
            }
            if ($startIso !== '' && preg_match('/^(\d{4})-(\d{2})/', $startIso, $m)) {
                $periodLabel = 'شهر ' . (int) $m[2] . ' / ' . $m[1]
                    . ($endIso !== '' && $endIso !== $startIso ? ' (أقساط)' : '');
            }
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'employee_id' => (int) ($row['employee_id'] ?? 0),
                'emp_code' => (string) ($row['emp_code'] ?? ''),
                'emp_name' => (string) ($row['emp_name'] ?? ''),
                'advance_code' => (string) ($row['advance_code'] ?? ''),
                'advance_type' => (string) ($row['advance_type'] ?? ''),
                'advance_type_label' => hr_employee_advance_type_label((string) ($row['advance_type'] ?? '')),
                'total_amount' => round((float) ($row['total_amount'] ?? 0), 3),
                'start_date' => $startIso,
                'end_date' => $endIso,
                'period_label' => $periodLabel,
                'posted_at' => (string) ($row['posted_at'] ?? ''),
                'notes' => (string) ($row['notes'] ?? ''),
            ];
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function hr_employee_advance_validate_for_disbursement(
    PDO $pdo,
    int $advanceId,
    int $employeeId,
    float $amount,
    int $exceptVoucherId = 0
): ?string {
    if ($advanceId < 1) {
        return 'اختر السلفة المعتمدة للصرف.';
    }
    $advance = hr_employee_advance_load($pdo, $advanceId);
    if (!$advance) {
        return 'السلفة غير موجودة.';
    }
    if ((int) ($advance['employee_id'] ?? 0) !== $employeeId) {
        return 'السلفة لا تخص الموظف المختار.';
    }
    if ((int) ($advance['is_posted'] ?? 0) !== 1) {
        return 'السلفة غير مرحّلة من شؤون الموظفين بعد.';
    }
    if ((string) ($advance['status'] ?? '') === 'cancelled') {
        return 'السلفة ملغاة.';
    }
    if (hr_employee_advance_disbursement_columns_ready($pdo)) {
        $linkedVoucherId = (int) ($advance['disbursement_voucher_id'] ?? 0);
        if ($linkedVoucherId > 0 && $linkedVoucherId !== $exceptVoucherId) {
            return 'تم ربط هذه السلفة بسند صرف آخر من المحاسبة.';
        }
    }
    $expected = round((float) ($advance['total_amount'] ?? 0), 3);
    if ($expected <= 0.0005) {
        return 'مبلغ السلفة غير صالح.';
    }
    if (abs($amount - $expected) > 0.009) {
        return 'مبلغ سند الصرف يجب أن يساوي مبلغ السلفة (' . number_format($expected, 3) . ').';
    }

    return null;
}

function hr_employee_advance_assign_voucher(PDO $pdo, int $advanceId, int $voucherId): void
{
    if (!hr_employee_advance_disbursement_columns_ready($pdo)) {
        return;
    }
    if ($voucherId > 0) {
        $pdo->prepare(
            'UPDATE hr_employee_advance
             SET disbursement_voucher_id = NULL
             WHERE disbursement_voucher_id = ? AND id <> ?'
        )->execute([$voucherId, $advanceId > 0 ? $advanceId : 0]);
    }
    if ($advanceId > 0 && $voucherId > 0) {
        $pdo->prepare(
            'UPDATE hr_employee_advance
             SET disbursement_voucher_id = ?
             WHERE id = ?'
        )->execute([$voucherId, $advanceId]);
    }
}

function hr_employee_advance_mark_disbursed(PDO $pdo, int $advanceId, int $voucherId): void
{
    if ($advanceId < 1 || $voucherId < 1 || !hr_employee_advance_disbursement_columns_ready($pdo)) {
        return;
    }
    $pdo->prepare(
        'UPDATE hr_employee_advance
         SET is_disbursed = 1, disbursed_at = NOW(), disbursement_voucher_id = ?
         WHERE id = ?'
    )->execute([$voucherId, $advanceId]);
}

function hr_employee_advance_clear_disbursement_by_voucher(PDO $pdo, int $voucherId): void
{
    if ($voucherId < 1 || !hr_employee_advance_disbursement_columns_ready($pdo)) {
        return;
    }
    $pdo->prepare(
        'UPDATE hr_employee_advance
         SET is_disbursed = 0, disbursed_at = NULL, disbursement_voucher_id = NULL
         WHERE disbursement_voucher_id = ?'
    )->execute([$voucherId]);
}
