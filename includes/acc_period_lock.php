<?php
declare(strict_types=1);

/** إغلاق الأشهر المحاسبية — منع إدخال مستندات بتاريخ شهر مغلق. */

function acc_period_month_names_ar(): array
{
    return [
        1 => 'يناير',
        2 => 'فبراير',
        3 => 'مارس',
        4 => 'أبريل',
        5 => 'مايو',
        6 => 'يونيو',
        7 => 'يوليو',
        8 => 'أغسطس',
        9 => 'سبتمبر',
        10 => 'أكتوبر',
        11 => 'نوفمبر',
        12 => 'ديسمبر',
    ];
}

function acc_period_month_name_ar(int $month): string
{
    return acc_period_month_names_ar()[$month] ?? (string) $month;
}

function acc_period_month_label(int $year, int $month): string
{
    return acc_period_month_name_ar($month) . ' ' . $year;
}

function acc_period_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->query('SELECT period_year FROM acc_accounting_period LIMIT 1');
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/155_acc_accounting_period.sql');
    }
}

/** @return array<int, int> month => is_locked (0|1) */
function acc_period_overrides_for_year(PDO $pdo, int $year): array
{
    acc_period_ensure_schema($pdo);
    $st = $pdo->prepare(
        'SELECT period_month, is_locked FROM acc_accounting_period WHERE period_year = ?'
    );
    $st->execute([$year]);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $out[(int) $row['period_month']] = (int) $row['is_locked'] === 1 ? 1 : 0;
    }

    return $out;
}

/** الشهر مغلق افتراضياً إذا سبق الشهر الحالي (بعد انتهائه). */
function acc_period_default_locked(int $year, int $month): bool
{
    $now = new DateTimeImmutable('today');
    $curY = (int) $now->format('Y');
    $curM = (int) $now->format('n');

    return $year < $curY || ($year === $curY && $month < $curM);
}

function acc_period_month_is_locked(PDO $pdo, int $year, int $month): bool
{
    if ($month < 1 || $month > 12) {
        return false;
    }
    acc_period_ensure_schema($pdo);
    $st = $pdo->prepare(
        'SELECT is_locked FROM acc_accounting_period WHERE period_year = ? AND period_month = ? LIMIT 1'
    );
    $st->execute([$year, $month]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
        return (int) $row['is_locked'] === 1;
    }

    return acc_period_default_locked($year, $month);
}

/** @return array<int, array{month:int, name_ar:string, is_locked:bool, is_default:bool}> */
function acc_period_months_for_year(PDO $pdo, int $year): array
{
    $overrides = acc_period_overrides_for_year($pdo, $year);
    $months = [];
    for ($m = 1; $m <= 12; $m++) {
        $months[$m] = [
            'month' => $m,
            'name_ar' => acc_period_month_name_ar($m),
            'is_locked' => acc_period_month_is_locked($pdo, $year, $m),
            'is_default' => !array_key_exists($m, $overrides),
        ];
    }

    return $months;
}

function acc_period_date_lock_error(PDO $pdo, string $dateIso): ?string
{
    $dateIso = trim($dateIso);
    if ($dateIso === '') {
        return null;
    }
    $ts = strtotime($dateIso);
    if ($ts === false) {
        return null;
    }
    $year = (int) date('Y', $ts);
    $month = (int) date('n', $ts);
    if (!acc_period_month_is_locked($pdo, $year, $month)) {
        return null;
    }

    return 'الشهر المحاسبي «' . acc_period_month_label($year, $month) . '» مغلق — لا يمكن حفظ مستند بتاريخ هذا الشهر.';
}

function acc_period_assert_date_open(PDO $pdo, string $dateIso): void
{
    $err = acc_period_date_lock_error($pdo, $dateIso);
    if ($err !== null) {
        throw new RuntimeException($err);
    }
}

/** @param array<int, int> $locks month => 0|1 */
function acc_period_save_year_locks(PDO $pdo, int $year, array $locks, ?int $userId): void
{
    acc_period_ensure_schema($pdo);
    if ($year < 2000 || $year > 2100) {
        throw new RuntimeException('السنة غير صالحة.');
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'INSERT INTO acc_accounting_period (period_year, period_month, is_locked, updated_by, updated_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE is_locked = VALUES(is_locked), updated_by = VALUES(updated_by), updated_at = NOW()'
        );
        for ($m = 1; $m <= 12; $m++) {
            $locked = !empty($locks[$m]) ? 1 : 0;
            $st->execute([$year, $m, $locked, $userId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
