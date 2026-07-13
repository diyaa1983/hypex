<?php
declare(strict_types=1);

/** إقفال السنة المالية — تصفير الإيرادات والمصروفات وترحيل الصافي إلى الأرباح المحتجزة. */

require_once app_path('includes/acc_period_lock.php');
require_once app_path('includes/acc_gl.php');
require_once app_path('includes/acc_report.php');

function acc_year_close_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->query('SELECT fiscal_year FROM acc_fiscal_year LIMIT 1');
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/217_acc_fiscal_year_close.sql');
    }
}

function acc_year_close_retained_earnings_account_id(PDO $pdo): int
{
    acc_gl_ensure_schema($pdo);
    $st = $pdo->prepare(
        "SELECT account_id FROM acc_posting_setting WHERE rule_code = 'retained_earnings' AND account_id IS NOT NULL LIMIT 1"
    );
    $st->execute();
    $id = (int) $st->fetchColumn();
    if ($id > 0) {
        return $id;
    }

    $st = $pdo->query(
        "SELECT id FROM acc_account WHERE code = '32' AND is_active = 1 LIMIT 1"
    );
    $id = $st ? (int) $st->fetchColumn() : 0;
    if ($id > 0) {
        $pdo->prepare("UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = 'retained_earnings'")
            ->execute([$id]);

        return $id;
    }

    require_once app_path('includes/acc_coa_bootstrap.php');
    acc_coa_bootstrap_run($pdo);

    $st->execute();
    $id = (int) $st->fetchColumn();
    if ($id > 0) {
        return $id;
    }

    $st = $pdo->query(
        "SELECT id FROM acc_account WHERE code = '32' AND is_active = 1 LIMIT 1"
    );
    $id = $st ? (int) $st->fetchColumn() : 0;
    if ($id > 0) {
        $pdo->prepare("UPDATE acc_posting_setting SET account_id = ? WHERE rule_code = 'retained_earnings'")
            ->execute([$id]);
    }

    return $id;
}

/** @return array<string, mixed>|null */
function acc_fiscal_year_row(PDO $pdo, int $year): ?array
{
    acc_year_close_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM acc_fiscal_year WHERE fiscal_year = ? LIMIT 1');
    $st->execute([$year]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function acc_fiscal_year_is_closed(PDO $pdo, int $year): bool
{
    $row = acc_fiscal_year_row($pdo, $year);

    return $row !== null && (string) ($row['status'] ?? '') === 'closed';
}

function acc_fiscal_year_is_registered(PDO $pdo, int $year): bool
{
    return acc_fiscal_year_row($pdo, $year) !== null;
}

/** @return list<array<string, mixed>> */
function acc_fiscal_year_list(PDO $pdo, int $fromYear, int $toYear): array
{
    acc_year_close_ensure_schema($pdo);
    $st = $pdo->prepare(
        'SELECT * FROM acc_fiscal_year WHERE fiscal_year >= ? AND fiscal_year <= ? ORDER BY fiscal_year DESC'
    );
    $st->execute([$fromYear, $toYear]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function acc_fiscal_year_date_lock_error(PDO $pdo, string $dateIso): ?string
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
    if (!acc_fiscal_year_is_closed($pdo, $year)) {
        return null;
    }

    return 'السنة المالية ' . $year . ' مغلقة — لا يمكن حفظ مستند بتاريخ هذه السنة.';
}

function acc_year_close_can_close_year(int $year): bool
{
    if ($year < 2000 || $year > 2100) {
        return false;
    }
    $today = new DateTimeImmutable('today');
    $yearEnd = new DateTimeImmutable(sprintf('%04d-12-31', $year));

    return $today > $yearEnd;
}

/**
 * @return array{ok:bool, errors:list<string>, warnings:list<string>, preview:array<string, mixed>}
 */
function acc_year_close_preflight(PDO $pdo, int $year): array
{
    acc_year_close_ensure_schema($pdo);
    $errors = [];
    $warnings = [];

    if ($year < 2000 || $year > 2100) {
        return [
            'ok' => false,
            'errors' => ['السنة غير صالحة.'],
            'warnings' => [],
            'preview' => [],
        ];
    }

    if (acc_fiscal_year_is_closed($pdo, $year)) {
        $errors[] = 'السنة المالية ' . $year . ' مغلقة مسبقاً.';
    }

    if (!acc_year_close_can_close_year($year)) {
        $errors[] = 'لا يمكن إقفال سنة ' . $year . ' قبل انتهائها (31 ديسمبر).';
    }

    if (!acc_journal_has_tables($pdo)) {
        $errors[] = 'نظام القيود المحاسبية غير مهيأ.';
    }

    $reId = acc_year_close_retained_earnings_account_id($pdo);
    if ($reId < 1) {
        $errors[] = 'حساب الأرباح المحتجزة (32) غير موجود — راجع شجرة الحسابات أو ربط الترحيل.';
    } elseif (!acc_gl_is_valid_leaf_account($pdo, $reId)) {
        $errors[] = 'حساب الأرباح المحتجزة غير صالح للترحيل.';
    }

    $st = $pdo->prepare(
        "SELECT fiscal_year FROM acc_fiscal_year
         WHERE status = 'open' AND fiscal_year < ?
         ORDER BY fiscal_year ASC LIMIT 1"
    );
    $st->execute([$year]);
    $priorOpen = (int) $st->fetchColumn();
    if ($priorOpen > 0) {
        $errors[] = 'يجب إقفال السنة المالية ' . $priorOpen . ' قبل إقفال ' . $year . '.';
    }

    $dateFrom = sprintf('%04d-01-01', $year);
    $dateTo = sprintf('%04d-12-31', $year);

    if (acc_journal_has_tables($pdo)) {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM acc_journal_entry
             WHERE status = 'draft' AND entry_date >= ? AND entry_date <= ?"
        );
        $st->execute([$dateFrom, $dateTo]);
        $draftCount = (int) $st->fetchColumn();
        if ($draftCount > 0) {
            $errors[] = 'يوجد ' . $draftCount . ' قيداً مسودة في سنة ' . $year . ' — رحّلها أو احذفها قبل الإقفال.';
        }
    }

    $preview = acc_year_close_build_preview($pdo, $year);

    if ($preview['line_count'] < 1) {
        $warnings[] = 'لا توجد أرصدة إيرادات أو مصروفات لإقفالها — سيتم تسجيل الإقفال دون قيد محاسبي.';
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'warnings' => $warnings,
        'preview' => $preview,
    ];
}

/**
 * @return array{
 *   line_count:int,
 *   total_revenue:float,
 *   total_expenses:float,
 *   net_income:float,
 *   lines:list<array{code:string, name_ar:string, account_type:string, debit:float, credit:float}>
 * }
 */
function acc_year_close_build_preview(PDO $pdo, int $year): array
{
    $throughDate = sprintf('%04d-12-31', $year);
    $dateFrom = sprintf('%04d-01-01', $year);
    $lines = [];
    $totalDebit = 0.0;
    $totalCredit = 0.0;
    $totalRevenue = 0.0;
    $totalExpenses = 0.0;

    foreach (['revenue', 'expense'] as $accountType) {
        foreach (acc_report_pl_load_accounts($pdo, $accountType) as $acc) {
            if ((int) ($acc['is_leaf'] ?? 1) !== 1) {
                continue;
            }
            $id = (int) $acc['id'];
            $sums = acc_report_account_sums($pdo, $id, null, $throughDate, false);
            if ($accountType === 'revenue') {
                $net = round((float) $sums['sum_credit'] - (float) $sums['sum_debit'], 6);
                if (abs($net) < 0.000001) {
                    continue;
                }
                $totalRevenue += $net;
                if ($net > 0) {
                    $lines[] = [
                        'code' => (string) $acc['code'],
                        'name_ar' => (string) $acc['name_ar'],
                        'account_type' => 'revenue',
                        'debit' => $net,
                        'credit' => 0.0,
                    ];
                    $totalDebit += $net;
                } else {
                    $amt = abs($net);
                    $lines[] = [
                        'code' => (string) $acc['code'],
                        'name_ar' => (string) $acc['name_ar'],
                        'account_type' => 'revenue',
                        'debit' => 0.0,
                        'credit' => $amt,
                    ];
                    $totalCredit += $amt;
                }
            } else {
                $net = round((float) $sums['sum_debit'] - (float) $sums['sum_credit'], 6);
                if (abs($net) < 0.000001) {
                    continue;
                }
                $totalExpenses += $net;
                if ($net > 0) {
                    $lines[] = [
                        'code' => (string) $acc['code'],
                        'name_ar' => (string) $acc['name_ar'],
                        'account_type' => 'expense',
                        'debit' => 0.0,
                        'credit' => $net,
                    ];
                    $totalCredit += $net;
                } else {
                    $amt = abs($net);
                    $lines[] = [
                        'code' => (string) $acc['code'],
                        'name_ar' => (string) $acc['name_ar'],
                        'account_type' => 'expense',
                        'debit' => $amt,
                        'credit' => 0.0,
                    ];
                    $totalDebit += $amt;
                }
            }
        }
    }

    $periodPl = acc_report_income_statement($pdo, $dateFrom, $throughDate);
    $netIncome = (float) ($periodPl['net_income'] ?? 0);
    $diff = round($totalDebit - $totalCredit, 6);
    if (abs($diff) >= 0.000001) {
        if ($diff > 0) {
            $lines[] = [
                'code' => '32',
                'name_ar' => 'أرباح محتجزة',
                'account_type' => 'equity',
                'debit' => 0.0,
                'credit' => $diff,
            ];
        } else {
            $lines[] = [
                'code' => '32',
                'name_ar' => 'أرباح محتجزة',
                'account_type' => 'equity',
                'debit' => abs($diff),
                'credit' => 0.0,
            ];
        }
    }

    return [
        'line_count' => count($lines),
        'total_revenue' => round($totalRevenue, 6),
        'total_expenses' => round($totalExpenses, 6),
        'net_income' => round($netIncome, 6),
        'lines' => $lines,
    ];
}

/**
 * @return list<array{account_id:int, debit:float, credit:float, memo:string}>
 */
function acc_year_close_build_journal_lines(PDO $pdo, int $year): array
{
    $throughDate = sprintf('%04d-12-31', $year);
    $reId = acc_year_close_retained_earnings_account_id($pdo);
    $journalLines = [];
    $totalDebit = 0.0;
    $totalCredit = 0.0;

    foreach (acc_report_pl_load_accounts($pdo, 'revenue') as $acc) {
        if ((int) ($acc['is_leaf'] ?? 1) !== 1) {
            continue;
        }
        $id = (int) $acc['id'];
        $sums = acc_report_account_sums($pdo, $id, null, $throughDate, false);
        $net = round((float) $sums['sum_credit'] - (float) $sums['sum_debit'], 6);
        if (abs($net) < 0.000001) {
            continue;
        }
        if ($net > 0) {
            $journalLines[] = [
                'account_id' => $id,
                'debit' => $net,
                'credit' => 0.0,
                'memo' => 'إقفال إيرادات ' . $year,
            ];
            $totalDebit += $net;
        } else {
            $amt = abs($net);
            $journalLines[] = [
                'account_id' => $id,
                'debit' => 0.0,
                'credit' => $amt,
                'memo' => 'إقفال إيرادات ' . $year,
            ];
            $totalCredit += $amt;
        }
    }

    foreach (acc_report_pl_load_accounts($pdo, 'expense') as $acc) {
        if ((int) ($acc['is_leaf'] ?? 1) !== 1) {
            continue;
        }
        $id = (int) $acc['id'];
        $sums = acc_report_account_sums($pdo, $id, null, $throughDate, false);
        $net = round((float) $sums['sum_debit'] - (float) $sums['sum_credit'], 6);
        if (abs($net) < 0.000001) {
            continue;
        }
        if ($net > 0) {
            $journalLines[] = [
                'account_id' => $id,
                'debit' => 0.0,
                'credit' => $net,
                'memo' => 'إقفال مصروفات ' . $year,
            ];
            $totalCredit += $net;
        } else {
            $amt = abs($net);
            $journalLines[] = [
                'account_id' => $id,
                'debit' => $amt,
                'credit' => 0.0,
                'memo' => 'إقفال مصروفات ' . $year,
            ];
            $totalDebit += $amt;
        }
    }

    $diff = round($totalDebit - $totalCredit, 6);
    if (abs($diff) >= 0.000001 && $reId > 0) {
        if ($diff > 0) {
            $journalLines[] = [
                'account_id' => $reId,
                'debit' => 0.0,
                'credit' => $diff,
                'memo' => 'صافي ربح السنة ' . $year,
            ];
        } else {
            $journalLines[] = [
                'account_id' => $reId,
                'debit' => abs($diff),
                'credit' => 0.0,
                'memo' => 'صافي خسارة السنة ' . $year,
            ];
        }
    }

    return $journalLines;
}

/**
 * @return array{journal_id:int, next_year:int}
 */
function acc_year_close_execute(PDO $pdo, int $year, ?int $userId): array
{
    $check = acc_year_close_preflight($pdo, $year);
    if (!$check['ok']) {
        throw new RuntimeException(implode(' ', $check['errors']));
    }

    $dateTo = sprintf('%04d-12-31', $year);
    $journalId = 0;
    $lines = acc_year_close_build_journal_lines($pdo, $year);

    $pdo->beginTransaction();
    try {
        if ($lines !== []) {
            $journalId = acc_gl_post_entry(
                $pdo,
                'year_close',
                $year,
                $dateTo,
                'إقفال السنة المالية ' . $year,
                $lines
            );
        }

        $locks = [];
        for ($m = 1; $m <= 12; $m++) {
            $locks[$m] = 1;
        }
        acc_period_save_year_locks($pdo, $year, $locks, $userId);

        $st = $pdo->prepare(
            "INSERT INTO acc_fiscal_year (fiscal_year, status, journal_id, closed_at, closed_by)
             VALUES (?, 'closed', ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
                status = 'closed',
                journal_id = VALUES(journal_id),
                closed_at = NOW(),
                closed_by = VALUES(closed_by)"
        );
        $st->execute([$year, $journalId > 0 ? $journalId : null, $userId]);
        acc_year_close_open_next_year($pdo, $year + 1, $userId);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['journal_id' => $journalId, 'next_year' => $year + 1];
}

function acc_year_close_open_next_year(PDO $pdo, int $year, ?int $userId): void
{
    if ($year < 2000 || $year > 2100) {
        return;
    }
    acc_year_close_ensure_schema($pdo);

    $st = $pdo->prepare(
        "INSERT INTO acc_fiscal_year (fiscal_year, status, opened_at, opened_by)
         VALUES (?, 'open', NOW(), ?)
         ON DUPLICATE KEY UPDATE
            status = IF(status = 'closed', status, 'open'),
            opened_at = COALESCE(opened_at, NOW()),
            opened_by = COALESCE(opened_by, VALUES(opened_by))"
    );
    $st->execute([$year, $userId]);

    $locks = [];
    for ($m = 1; $m <= 12; $m++) {
        $locks[$m] = 0;
    }
    try {
        acc_period_save_year_locks($pdo, $year, $locks, $userId);
    } catch (Throwable $e) {
        // السنة الجديدة قد لا تحتاج قفل أشهر صراحةً
    }
}

/**
 * تسجيل سنة مفتوحة يدوياً (للسنوات القديمة غير المسجّلة).
 */
function acc_year_close_register_open_year(PDO $pdo, int $year, ?int $userId): void
{
    if ($year < 2000 || $year > 2100) {
        throw new RuntimeException('السنة غير صالحة.');
    }
    acc_year_close_ensure_schema($pdo);
    if (acc_fiscal_year_is_closed($pdo, $year)) {
        throw new RuntimeException('السنة المالية ' . $year . ' مغلقة ولا يمكن فتحها من هنا.');
    }

    $st = $pdo->prepare(
        "INSERT INTO acc_fiscal_year (fiscal_year, status, opened_at, opened_by)
         VALUES (?, 'open', NOW(), ?)
         ON DUPLICATE KEY UPDATE opened_at = COALESCE(opened_at, NOW())"
    );
    $st->execute([$year, $userId]);
}

function acc_year_close_can_reopen(PDO $pdo, int $year): bool
{
    if ($year < 2000 || $year > 2100) {
        return false;
    }
    if (!acc_fiscal_year_is_closed($pdo, $year)) {
        return false;
    }

    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM acc_fiscal_year WHERE status = 'closed' AND fiscal_year > ?"
    );
    $st->execute([$year]);

    return (int) $st->fetchColumn() === 0;
}

/**
 * @return array{ok:bool, errors:list<string>, warnings:list<string>}
 */
function acc_year_close_reopen_preflight(PDO $pdo, int $year): array
{
    acc_year_close_ensure_schema($pdo);
    $errors = [];
    $warnings = [];

    if ($year < 2000 || $year > 2100) {
        return [
            'ok' => false,
            'errors' => ['السنة غير صالحة.'],
            'warnings' => [],
        ];
    }

    if (!acc_fiscal_year_is_closed($pdo, $year)) {
        $errors[] = 'السنة المالية ' . $year . ' ليست مغلقة.';
    }

    $st = $pdo->prepare(
        "SELECT fiscal_year FROM acc_fiscal_year
         WHERE status = 'closed' AND fiscal_year > ?
         ORDER BY fiscal_year ASC LIMIT 1"
    );
    $st->execute([$year]);
    $laterClosed = (int) $st->fetchColumn();
    if ($laterClosed > 0) {
        $errors[] = 'يجب فتح السنة المالية ' . $laterClosed . ' أولاً قبل فتح ' . $year . '.';
    }

    $row = acc_fiscal_year_row($pdo, $year);
    $journalId = $row !== null ? (int) ($row['journal_id'] ?? 0) : 0;
    if ($journalId > 0 && acc_journal_has_tables($pdo)) {
        $st = $pdo->prepare(
            'SELECT ref_type, ref_id, source FROM acc_journal_entry WHERE id = ? LIMIT 1'
        );
        $st->execute([$journalId]);
        $jRow = $st->fetch(PDO::FETCH_ASSOC);
        if ($jRow === false) {
            $warnings[] = 'قيد الإقفال المسجّل (# ' . $journalId . ') غير موجود — سيتم فتح السنة دون حذف قيد.';
        } elseif ((string) ($jRow['ref_type'] ?? '') !== 'year_close' || (int) ($jRow['ref_id'] ?? 0) !== $year) {
            $errors[] = 'قيد الإقفال #' . $journalId . ' لا يطابق سنة ' . $year . ' — راجع القيود قبل الفتح.';
        }
    } elseif (!acc_gl_ref_exists($pdo, 'year_close', $year)) {
        $warnings[] = 'لا يوجد قيد إقفال لهذه السنة — سيتم فتح السنة وإعادة فتح أشهرها فقط.';
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'warnings' => $warnings,
    ];
}

/**
 * فتح سنة مغلقة — حذف قيد الإقفال وإعادة الأشهر للإدخال.
 *
 * @return array{journal_deleted:bool}
 */
function acc_year_close_reopen_execute(PDO $pdo, int $year, ?int $userId): array
{
    $check = acc_year_close_reopen_preflight($pdo, $year);
    if (!$check['ok']) {
        throw new RuntimeException(implode(' ', $check['errors']));
    }

    $row = acc_fiscal_year_row($pdo, $year);
    $journalId = $row !== null ? (int) ($row['journal_id'] ?? 0) : 0;
    $journalDeleted = false;

    $pdo->beginTransaction();
    try {
        if ($journalId > 0) {
            $st = $pdo->prepare(
                'SELECT ref_type, ref_id FROM acc_journal_entry WHERE id = ? LIMIT 1'
            );
            $st->execute([$journalId]);
            $jRow = $st->fetch(PDO::FETCH_ASSOC);
            if ($jRow !== false
                && (string) ($jRow['ref_type'] ?? '') === 'year_close'
                && (int) ($jRow['ref_id'] ?? 0) === $year) {
                acc_gl_delete_auto_journal($pdo, $journalId);
                $journalDeleted = true;
            }
        } else {
            $unpost = acc_gl_unpost_ref($pdo, 'year_close', $year);
            if (!$unpost['ok']) {
                throw new RuntimeException($unpost['error'] ?? 'تعذر حذف قيد الإقفال.');
            }
            $journalDeleted = !$unpost['skipped'];
        }

        $locks = [];
        for ($m = 1; $m <= 12; $m++) {
            $locks[$m] = 0;
        }
        acc_period_save_year_locks($pdo, $year, $locks, $userId);

        $pdo->prepare(
            "UPDATE acc_fiscal_year
             SET status = 'open', journal_id = NULL, closed_at = NULL, closed_by = NULL
             WHERE fiscal_year = ?"
        )->execute([$year]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['journal_deleted' => $journalDeleted];
}

/**
 * @return list<array{fiscal_year:int, status:string, status_label:string, journal_id:int, closed_at:?string, can_close:bool, can_reopen:bool}>
 */
function acc_year_close_status_board(PDO $pdo, int $centerYear): array
{
    acc_year_close_ensure_schema($pdo);
    $from = $centerYear - 8;
    $to = $centerYear + 2;
    $registered = [];
    foreach (acc_fiscal_year_list($pdo, $from, $to) as $row) {
        $registered[(int) $row['fiscal_year']] = $row;
    }

    $board = [];
    for ($y = $to; $y >= $from; $y--) {
        $row = $registered[$y] ?? null;
        $status = $row !== null ? (string) ($row['status'] ?? 'open') : 'legacy';
        $statusLabel = match ($status) {
            'closed' => 'مغلقة',
            'open' => 'مفتوحة',
            default => 'غير مسجّلة (مفتوحة افتراضياً)',
        };
        $board[] = [
            'fiscal_year' => $y,
            'status' => $status,
            'status_label' => $statusLabel,
            'journal_id' => $row !== null ? (int) ($row['journal_id'] ?? 0) : 0,
            'closed_at' => $row !== null ? ($row['closed_at'] ?? null) : null,
            'can_close' => $status !== 'closed' && acc_year_close_can_close_year($y),
            'can_reopen' => $status === 'closed' && acc_year_close_can_reopen($pdo, $y),
        ];
    }

    return $board;
}
