<?php
declare(strict_types=1);

/** مصدر الشيك في سجل تنبيهات البريد. */
const FIN_PRIVATE_OUT_CHECK_EMAIL_SOURCE = 'private';
const FIN_VOUCHER_OUT_CHECK_EMAIL_SOURCE = 'voucher';

function fin_private_out_check_ensure_schema(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM fin_private_out_check LIMIT 1');
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/229_fin_private_out_checks.sql');
    }

    fin_private_out_check_ensure_email_log_source($pdo);

    try {
        $pdo->query('SELECT id FROM fin_private_out_check LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function fin_private_out_check_ensure_email_log_source(PDO $pdo): void
{
    require_once app_path('includes/fin_check_due_email.php');
    if (!fin_check_due_email_ensure_schema($pdo)) {
        return;
    }

    try {
        $pdo->query('SELECT check_source FROM fin_check_due_email_log LIMIT 1');
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), 'Unknown column') === false) {
            return;
        }
        try {
            $pdo->exec(
                "ALTER TABLE fin_check_due_email_log
                 ADD COLUMN check_source VARCHAR(16) NOT NULL DEFAULT 'voucher' AFTER check_id"
            );
        } catch (Throwable $e2) {
            // ignore
        }
    }

    try {
        $pdo->exec('ALTER TABLE fin_check_due_email_log DROP INDEX uq_fcdel_check_due_type');
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $pdo->exec(
            'ALTER TABLE fin_check_due_email_log
             ADD UNIQUE KEY uq_fcdel_source_check_due_type (check_source, check_id, due_date, notify_type)'
        );
    } catch (Throwable $e) {
        // ignore if exists
    }
}

function fin_private_out_check_redirect_url(int $id = 0): string
{
    $url = app_url('index.php?r=fin_private_out_checks');
    if ($id > 0) {
        $url .= '&id=' . $id;
    }

    return $url;
}

function fin_private_out_check_allocate_entry_no(PDO $pdo): string
{
    $year = date('Y');
    $st = $pdo->prepare(
        "SELECT entry_no FROM fin_private_out_check
         WHERE entry_no LIKE ?
         ORDER BY id DESC LIMIT 1"
    );
    $st->execute(['K%-' . $year]);
    $last = trim((string) ($st->fetchColumn() ?: ''));
    $seq = 1;
    if ($last !== '' && preg_match('/^K(\d+)-' . preg_quote($year, '/') . '$/', $last, $m)) {
        $seq = (int) $m[1] + 1;
    }

    return sprintf('K%03d-%s', $seq, $year);
}

/**
 * @return array{status:string, check_no:string, beneficiary:string, from:string, to:string}
 */
function fin_private_out_check_parse_filters(array $get): array
{
    return [
        'status' => in_array((string) ($get['status'] ?? 'pending'), ['pending', 'done', 'cancelled', 'all'], true)
            ? (string) $get['status']
            : 'pending',
        'check_no' => trim((string) ($get['check_no'] ?? '')),
        'beneficiary' => trim((string) ($get['beneficiary'] ?? '')),
        'from' => trim((string) ($get['from'] ?? '')),
        'to' => trim((string) ($get['to'] ?? '')),
    ];
}

/**
 * @param array{status:string, check_no:string, beneficiary:string, from:string, to:string} $filters
 * @return list<array<string, mixed>>
 */
function fin_private_out_check_list(PDO $pdo, array $filters): array
{
    if (!fin_private_out_check_ensure_schema($pdo)) {
        return [];
    }

    $where = ['1=1'];
    $params = [];

    if (($filters['status'] ?? 'all') !== 'all') {
        $where[] = 'c.status = ?';
        $params[] = (string) $filters['status'];
    }
    if (($filters['check_no'] ?? '') !== '') {
        $where[] = 'c.check_no LIKE ?';
        $params[] = '%' . (string) $filters['check_no'] . '%';
    }
    if (($filters['beneficiary'] ?? '') !== '') {
        $where[] = 'c.beneficiary LIKE ?';
        $params[] = '%' . (string) $filters['beneficiary'] . '%';
    }
    if (($filters['from'] ?? '') !== '' && ($filters['to'] ?? '') !== '') {
        $where[] = 'c.due_date BETWEEN ? AND ?';
        $params[] = (string) $filters['from'];
        $params[] = (string) $filters['to'];
    }

    $sql =
        'SELECT c.* FROM fin_private_out_check c
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY c.due_date IS NULL, c.due_date ASC, c.id DESC';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return array<string, mixed>|null */
function fin_private_out_check_fetch(PDO $pdo, int $id): ?array
{
    if ($id < 1 || !fin_private_out_check_ensure_schema($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM fin_private_out_check WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function fin_private_out_check_status_label(string $status): string
{
    return match ($status) {
        'done' => 'منجز',
        'cancelled' => 'ملغى',
        default => 'قيد التذكير',
    };
}

/**
 * شيكات خاصة قيد التذكير — نفس شكل fin_voucher_checks_pending_disbursement للبريد.
 *
 * @return list<array<string, mixed>>
 */
function fin_private_out_check_pending_reminders(PDO $pdo, ?string $today = null): array
{
    if (!fin_private_out_check_ensure_schema($pdo)) {
        return [];
    }

    $today = $today ?? date('Y-m-d');

    try {
        $rows = $pdo->query(
            "SELECT id, entry_no, check_no, bank_name, check_amount, due_date, beneficiary, notes
             FROM fin_private_out_check
             WHERE status = 'pending'
               AND check_amount > 0.000001
               AND due_date IS NOT NULL
             ORDER BY due_date ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $due = trim((string) ($row['due_date'] ?? ''));
        $daysUntil = null;
        $urgency = 'pending';
        $urgencyLabel = 'قيد التذكير';
        if ($due !== '') {
            try {
                $dueDt = new DateTimeImmutable($due);
                $todayDt = new DateTimeImmutable($today);
                $daysUntil = (int) $todayDt->diff($dueDt)->format('%r%a');
                if ($daysUntil < 0) {
                    $urgency = 'overdue';
                    $urgencyLabel = 'متأخر';
                } elseif ($daysUntil === 0) {
                    $urgency = 'today';
                    $urgencyLabel = 'مستحق اليوم';
                } elseif ($daysUntil <= 7) {
                    $urgency = 'soon';
                    $urgencyLabel = 'قريب الاستحقاق';
                }
            } catch (Throwable $e) {
                // ignore
            }
        }

        $cid = (int) ($row['id'] ?? 0);
        $out[] = [
            'check_id' => $cid,
            'check_source' => FIN_PRIVATE_OUT_CHECK_EMAIL_SOURCE,
            'check_no' => trim((string) ($row['check_no'] ?? '')),
            'bank_name' => trim((string) ($row['bank_name'] ?? '')),
            'amount' => (float) ($row['check_amount'] ?? 0),
            'due_date' => $due,
            'days_until_due' => $daysUntil,
            'urgency' => $urgency,
            'urgency_label' => $urgencyLabel,
            'party_name' => trim((string) ($row['beneficiary'] ?? '')),
            'voucher_no' => trim((string) ($row['entry_no'] ?? '')),
            'voucher_date' => '',
            'notes' => trim((string) ($row['notes'] ?? '')),
            'is_private' => true,
            'url' => app_url('index.php?r=fin_private_out_checks&id=' . $cid),
        ];
    }

    return $out;
}

function fin_private_out_check_save(PDO $pdo, array $input, int $userId): int
{
    if (!fin_private_out_check_ensure_schema($pdo)) {
        throw new RuntimeException('جدول الشيكات الخاصة غير متوفر.');
    }

    $id = (int) ($input['id'] ?? 0);
    $checkNo = trim((string) ($input['check_no'] ?? ''));
    $bankName = trim((string) ($input['bank_name'] ?? ''));
    $amount = round(parse_amount_input($input['check_amount'] ?? 0), 6);
    $dueRaw = trim((string) ($input['due_date'] ?? ''));
    $dueDate = parse_date_to_iso($dueRaw) ?? '';
    $beneficiary = trim((string) ($input['beneficiary'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? ''));

    if ($amount <= 0) {
        throw new InvalidArgumentException('أدخل مبلغ الشيك.');
    }
    if ($dueDate === '') {
        throw new InvalidArgumentException('تاريخ الاستحقاق مطلوب للتذكير.');
    }

    if ($id > 0) {
        $row = fin_private_out_check_fetch($pdo, $id);
        if (!$row) {
            throw new InvalidArgumentException('الشيك غير موجود.');
        }
        if ((string) ($row['status'] ?? '') !== 'pending') {
            throw new InvalidArgumentException('لا يمكن تعديل شيك منجز أو ملغى.');
        }
        $pdo->prepare(
            'UPDATE fin_private_out_check
             SET check_no = ?, bank_name = ?, check_amount = ?, due_date = ?,
                 beneficiary = ?, notes = ?
             WHERE id = ? AND status = \'pending\''
        )->execute([$checkNo, $bankName, $amount, $dueDate, $beneficiary, $notes, $id]);

        return $id;
    }

    $entryNo = fin_private_out_check_allocate_entry_no($pdo);
    $pdo->prepare(
        'INSERT INTO fin_private_out_check
            (entry_no, check_no, bank_name, check_amount, due_date, beneficiary, notes, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\', ?)'
    )->execute([$entryNo, $checkNo, $bankName, $amount, $dueDate, $beneficiary, $notes, $userId > 0 ? $userId : null]);

    return (int) $pdo->lastInsertId();
}

function fin_private_out_check_mark_done(PDO $pdo, int $id, int $userId): void
{
    $row = fin_private_out_check_fetch($pdo, $id);
    if (!$row) {
        throw new InvalidArgumentException('الشيك غير موجود.');
    }
    if ((string) ($row['status'] ?? '') !== 'pending') {
        throw new InvalidArgumentException('الشيك ليس قيد التذكير.');
    }

    $pdo->prepare(
        'UPDATE fin_private_out_check
         SET status = \'done\', done_at = NOW(), done_by = ?
         WHERE id = ? AND status = \'pending\''
    )->execute([$userId > 0 ? $userId : null, $id]);
}

function fin_private_out_check_cancel(PDO $pdo, int $id): void
{
    $row = fin_private_out_check_fetch($pdo, $id);
    if (!$row) {
        throw new InvalidArgumentException('الشيك غير موجود.');
    }
    if ((string) ($row['status'] ?? '') !== 'pending') {
        throw new InvalidArgumentException('لا يمكن إلغاء هذا الشيك.');
    }

    $pdo->prepare(
        "UPDATE fin_private_out_check SET status = 'cancelled' WHERE id = ? AND status = 'pending'"
    )->execute([$id]);
}

function fin_private_out_check_delete(PDO $pdo, int $id): void
{
    $row = fin_private_out_check_fetch($pdo, $id);
    if (!$row) {
        throw new InvalidArgumentException('الشيك غير موجود.');
    }
    if ((string) ($row['status'] ?? '') !== 'pending') {
        throw new InvalidArgumentException('يمكن حذف الشيكات قيد التذكير فقط.');
    }

    $pdo->prepare('DELETE FROM fin_private_out_check WHERE id = ? AND status = \'pending\'')->execute([$id]);
}

function handle_fin_private_out_check_post(): void
{
    if (!user_can('fin_private_out_checks')) {
        http_response_code(403);
        exit('ليس لديك صلاحية.');
    }
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        flash_set('error', 'انتهت صلاحية الجلسة.');
        redirect(fin_private_out_check_redirect_url((int) ($_POST['id'] ?? 0)));
    }

    $pdo = db();
    $action = trim((string) ($_POST['_action'] ?? 'save'));
    $id = (int) ($_POST['id'] ?? 0);
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    try {
        if ($action === 'delete') {
            fin_private_out_check_delete($pdo, $id);
            flash_set('success', 'تم حذف الشيك.');
            redirect(fin_private_out_check_redirect_url());
        }
        if ($action === 'done') {
            fin_private_out_check_mark_done($pdo, $id, $userId);
            flash_set('success', 'تم إيقاف التذكير لهذا الشيك.');
            redirect(fin_private_out_check_redirect_url());
        }
        if ($action === 'cancel') {
            fin_private_out_check_cancel($pdo, $id);
            flash_set('success', 'تم إلغاء الشيك.');
            redirect(fin_private_out_check_redirect_url());
        }

        $savedId = fin_private_out_check_save($pdo, $_POST, $userId);
        flash_set('success', $id > 0 ? 'تم تحديث الشيك.' : 'تم حفظ الشيك.');
        redirect(fin_private_out_check_redirect_url($savedId));
    } catch (Throwable $e) {
        $msg = $e->getMessage() !== '' ? $e->getMessage() : 'تعذر حفظ الشيك.';
        flash_set('error', $msg);
        redirect(fin_private_out_check_redirect_url($id));
    }
}
