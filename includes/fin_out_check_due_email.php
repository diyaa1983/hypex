<?php
declare(strict_types=1);

/**
 * تنبيهات بريد لاستحقاق الشيكات الصادرة (سندات الصرف).
 * يعيد استخدام سجل fin_check_due_email_log مع notify_type مسبوق بـ out_ (مثل out_d5).
 */

require_once app_path('includes/company_smtp.php');
require_once app_path('includes/fin_voucher_checks.php');
require_once app_path('includes/fin_check_due_email.php');

function fin_out_check_due_email_ensure_settings_columns(PDO $pdo): void
{
    $cols = [
        'out_check_email_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'out_check_email_days_before' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 5',
        'out_check_email_on_due_day' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'out_check_email_recipients' => 'TEXT NULL',
    ];
    foreach ($cols as $name => $def) {
        try {
            $pdo->query("SELECT $name FROM sys_company_settings LIMIT 1");
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'Unknown column') === false) {
                continue;
            }
            try {
                $pdo->exec("ALTER TABLE sys_company_settings ADD COLUMN $name $def");
            } catch (Throwable $e2) {
                // ignored
            }
        }
    }
}

/**
 * @return array{
 *   enabled:bool,
 *   days_before:int,
 *   on_due_day:bool,
 *   recipients:list<string>
 * }
 */
function fin_out_check_due_email_settings(PDO $pdo): array
{
    fin_out_check_due_email_ensure_settings_columns($pdo);
    $defaults = [
        'enabled' => false,
        'days_before' => 5,
        'on_due_day' => true,
        'recipients' => [],
    ];
    try {
        $row = $pdo->query(
            'SELECT out_check_email_enabled, out_check_email_days_before, out_check_email_on_due_day,
                    out_check_email_recipients, email
             FROM sys_company_settings WHERE id = 1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $defaults['enabled'] = (int) ($row['out_check_email_enabled'] ?? 0) === 1;
        $days = (int) ($row['out_check_email_days_before'] ?? 5);
        $defaults['days_before'] = max(1, min(60, $days));
        $defaults['on_due_day'] = (int) ($row['out_check_email_on_due_day'] ?? 1) === 1;
        $parsed = fin_check_due_email_parse_recipients((string) ($row['out_check_email_recipients'] ?? ''));
        if ($parsed === []) {
            $main = trim((string) ($row['email'] ?? ''));
            if ($main !== '' && filter_var($main, FILTER_VALIDATE_EMAIL)) {
                $parsed = [$main];
            }
        }
        $defaults['recipients'] = $parsed;
    } catch (Throwable $e) {
        // ignore
    }

    return $defaults;
}

function fin_out_check_due_email_notify_type(int $daysBeforeDue): string
{
    return 'out_d' . max(0, $daysBeforeDue);
}

/**
 * @param list<array{check_id:int, due_date:string}> $items
 * @return array<int, true>
 */
function fin_out_check_due_email_already_sent_map(PDO $pdo, array $items, int $daysBeforeDue): array
{
    if ($items === [] || !fin_check_due_email_ensure_schema($pdo)) {
        return [];
    }

    $notifyType = fin_out_check_due_email_notify_type($daysBeforeDue);
    $pairs = [];
    foreach ($items as $item) {
        $cid = (int) ($item['check_id'] ?? 0);
        $due = parse_date_to_iso((string) ($item['due_date'] ?? '')) ?? (string) ($item['due_date'] ?? '');
        if ($cid < 1 || $due === '') {
            continue;
        }
        $pairs[] = [$cid, $due];
    }

    if ($pairs === []) {
        return [];
    }

    $sent = [];
    foreach (array_chunk($pairs, 80) as $chunk) {
        $ors = [];
        $params = [$notifyType];
        foreach ($chunk as [$cid, $due]) {
            $ors[] = '(check_id = ? AND due_date = ?)';
            $params[] = $cid;
            $params[] = $due;
        }
        try {
            $st = $pdo->prepare(
                'SELECT check_id FROM fin_check_due_email_log
                 WHERE notify_type = ? AND (' . implode(' OR ', $ors) . ')'
            );
            $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $cid) {
                $sent[(int) $cid] = true;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    return $sent;
}

function fin_out_check_due_email_subject(int $daysBeforeDue, string $companyName): string
{
    if ($daysBeforeDue === 0) {
        $subject = 'تنبيه: شيكات صادرة مستحقة اليوم';
    } elseif ($daysBeforeDue === 1) {
        $subject = 'تنبيه: شيكات صادرة — يتبقى يوم واحد على الاستحقاق';
    } else {
        $subject = 'تنبيه: شيكات صادرة — يتبقى ' . $daysBeforeDue . ' أيام على الاستحقاق';
    }
    if ($companyName !== '') {
        $subject .= ' — ' . $companyName;
    }

    return $subject;
}

/**
 * @param list<array<string, mixed>> $checks
 */
function fin_out_check_due_email_build_html(PDO $pdo, array $checks, int $daysBeforeDue): string
{
    $company = '';
    try {
        $row = $pdo->query('SELECT company_name_ar FROM sys_company_settings WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
        $company = trim((string) ($row['company_name_ar'] ?? ''));
    } catch (Throwable $e) {
        // ignore
    }

    $rowsHtml = '';
    foreach ($checks as $chk) {
        $dueIso = trim((string) ($chk['due_date'] ?? ''));
        $dueDisplay = $dueIso !== '' ? format_date_dmY($dueIso) : '—';
        $rowsHtml .= '<tr>'
            . '<td style="padding:8px;border:1px solid #e2e8f0;">' . htmlspecialchars((string) ($chk['check_no'] !== '' ? $chk['check_no'] : '—'), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:8px;border:1px solid #e2e8f0;">' . htmlspecialchars((string) ($chk['bank_name'] !== '' ? $chk['bank_name'] : '—'), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:8px;border:1px solid #e2e8f0;">' . htmlspecialchars((string) ($chk['party_name'] !== '' ? $chk['party_name'] : '—'), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:8px;border:1px solid #e2e8f0;">' . htmlspecialchars((string) ($chk['voucher_no'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:8px;border:1px solid #e2e8f0;">' . htmlspecialchars($dueDisplay, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:8px;border:1px solid #e2e8f0;text-align:left;">' . htmlspecialchars(format_money((float) ($chk['amount'] ?? 0)), ENT_QUOTES, 'UTF-8') . '</td>'
            . '</tr>';
    }

    if ($daysBeforeDue === 0) {
        $intro = 'هذا تنبيه تلقائي: الشيكات الصادرة التالية <strong>مستحقة اليوم</strong> (سندات الصرف).';
    } elseif ($daysBeforeDue === 1) {
        $intro = 'هذا تنبيه تلقائي: يتبقى <strong>يوم واحد</strong> على استحقاق الشيكات الصادرة التالية.';
    } else {
        $intro = 'هذا تنبيه تلقائي: يتبقى <strong>' . $daysBeforeDue
            . ' أيام</strong> على استحقاق الشيكات الصادرة التالية.';
    }

    $title = $company !== '' ? $company : 'النظام';

    return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;direction:rtl;color:#0f172a;">'
        . '<p style="margin:0 0 12px;">مرحباً،</p>'
        . '<p style="margin:0 0 16px;">' . $intro . ' العدد: <strong>' . count($checks) . '</strong>.</p>'
        . '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
        . '<thead><tr style="background:#f1f5f9;">'
        . '<th style="padding:8px;border:1px solid #e2e8f0;">رقم الشيك</th>'
        . '<th style="padding:8px;border:1px solid #e2e8f0;">البنك</th>'
        . '<th style="padding:8px;border:1px solid #e2e8f0;">المستفيد</th>'
        . '<th style="padding:8px;border:1px solid #e2e8f0;">سند الصرف</th>'
        . '<th style="padding:8px;border:1px solid #e2e8f0;">تاريخ الاستحقاق</th>'
        . '<th style="padding:8px;border:1px solid #e2e8f0;">المبلغ</th>'
        . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
        . '<p style="margin:16px 0 0;color:#64748b;font-size:12px;">'
        . 'تم الإرسال تلقائياً من ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . ' · <a href="' . htmlspecialchars(app_url('index.php?r=fin_outgoing_checks'), ENT_QUOTES, 'UTF-8') . '">سجل الشيكات الصادرة</a>'
        . '</p></div>';
}

/**
 * @return array{
 *   cfg:array{enabled:bool,days_before:int,on_due_day:bool,recipients:list<string>},
 *   buckets:array<int, list<array<string, mixed>>>,
 *   company:string
 * }|null
 */
function fin_out_check_due_email_prepare_buckets(PDO $pdo, ?string $today = null): ?array
{
    $today = $today ?? date('Y-m-d');
    $todayIso = parse_date_to_iso($today) ?? $today;

    if (!fin_check_due_email_ensure_schema($pdo)) {
        return null;
    }
    fin_out_check_due_email_ensure_settings_columns($pdo);

    $cfg = fin_out_check_due_email_settings($pdo);
    if (!$cfg['enabled'] || $cfg['recipients'] === [] || !company_smtp_is_configured($pdo)) {
        return null;
    }

    $company = '';
    try {
        $row = $pdo->query('SELECT company_name_ar FROM sys_company_settings WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
        $company = trim((string) ($row['company_name_ar'] ?? ''));
    } catch (Throwable $e) {
        // ignore
    }

    $allPending = fin_voucher_checks_pending_disbursement($pdo, $todayIso);
    /** @var array<int, list<array<string, mixed>>> $buckets */
    $buckets = [];

    foreach ($allPending as $chk) {
        $due = trim((string) ($chk['due_date'] ?? ''));
        if ($due === '') {
            continue;
        }
        $dueIso = parse_date_to_iso($due) ?? $due;
        $daysUntil = fin_check_due_email_days_until($todayIso, $dueIso);
        if ($daysUntil === null) {
            continue;
        }

        $bucket = null;
        if ($daysUntil === 0 && $cfg['on_due_day']) {
            $bucket = 0;
        } elseif ($daysUntil > 0 && $daysUntil <= $cfg['days_before']) {
            $bucket = $daysUntil;
        }

        if ($bucket === null) {
            continue;
        }

        $buckets[$bucket][] = $chk;
    }

    if ($buckets === []) {
        return null;
    }

    krsort($buckets, SORT_NUMERIC);

    return [
        'cfg' => $cfg,
        'buckets' => $buckets,
        'company' => $company,
    ];
}

function fin_out_check_due_email_has_pending(PDO $pdo, ?string $today = null): bool
{
    $prep = fin_out_check_due_email_prepare_buckets($pdo, $today);
    if ($prep === null) {
        return false;
    }

    foreach ($prep['buckets'] as $daysBefore => $checks) {
        $items = [];
        foreach ($checks as $chk) {
            $items[] = [
                'check_id' => (int) ($chk['check_id'] ?? 0),
                'due_date' => (string) ($chk['due_date'] ?? ''),
            ];
        }
        $already = fin_out_check_due_email_already_sent_map($pdo, $items, (int) $daysBefore);
        foreach ($checks as $chk) {
            $cid = (int) ($chk['check_id'] ?? 0);
            if ($cid > 0 && !isset($already[$cid])) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @return array{ok:bool, sent:int, emails:int, skipped?:bool, reason?:string, error?:string}
 */
function fin_out_check_due_email_run(PDO $pdo, ?string $today = null): array
{
    if (!fin_check_due_email_ensure_schema($pdo)) {
        return ['ok' => false, 'sent' => 0, 'emails' => 0, 'error' => 'جدول سجل التنبيهات غير متوفر.'];
    }
    fin_out_check_due_email_ensure_settings_columns($pdo);

    $prep = fin_out_check_due_email_prepare_buckets($pdo, $today);
    if ($prep === null) {
        $cfg = fin_out_check_due_email_settings($pdo);
        if (!$cfg['enabled']) {
            return ['ok' => true, 'sent' => 0, 'emails' => 0, 'skipped' => true, 'reason' => 'disabled'];
        }
        if ($cfg['recipients'] === []) {
            return ['ok' => true, 'sent' => 0, 'emails' => 0, 'skipped' => true, 'reason' => 'no_recipients'];
        }
        if (!company_smtp_is_configured($pdo)) {
            return ['ok' => true, 'sent' => 0, 'emails' => 0, 'skipped' => true, 'reason' => 'smtp_not_configured'];
        }

        return ['ok' => true, 'sent' => 0, 'emails' => 0, 'skipped' => true, 'reason' => 'no_checks_in_window'];
    }

    $cfg = $prep['cfg'];
    $recipients = $cfg['recipients'];
    $company = $prep['company'];
    $buckets = $prep['buckets'];

    $totalChecks = 0;
    $totalEmails = 0;
    $lastError = null;
    $ins = $pdo->prepare(
        'INSERT IGNORE INTO fin_check_due_email_log (check_id, due_date, notify_type) VALUES (?,?,?)'
    );

    foreach ($buckets as $daysBefore => $checks) {
        $items = [];
        foreach ($checks as $chk) {
            $items[] = [
                'check_id' => (int) ($chk['check_id'] ?? 0),
                'due_date' => (string) ($chk['due_date'] ?? ''),
            ];
        }
        $already = fin_out_check_due_email_already_sent_map($pdo, $items, (int) $daysBefore);
        $toSend = [];
        foreach ($checks as $chk) {
            $cid = (int) ($chk['check_id'] ?? 0);
            if ($cid < 1 || isset($already[$cid])) {
                continue;
            }
            $toSend[] = $chk;
        }
        if ($toSend === []) {
            continue;
        }

        $subject = fin_out_check_due_email_subject((int) $daysBefore, $company);
        $bodyHtml = fin_out_check_due_email_build_html($pdo, $toSend, (int) $daysBefore);
        $notifyType = fin_out_check_due_email_notify_type((int) $daysBefore);

        $bucketOk = true;
        foreach ($recipients as $to) {
            $result = company_smtp_send($to, $subject, $bodyHtml);
            if (!empty($result['ok'])) {
                $totalEmails++;
            } else {
                $bucketOk = false;
                $lastError = (string) ($result['error'] ?? $result['message'] ?? 'تعذر الإرسال');
            }
        }

        if (!$bucketOk) {
            continue;
        }

        foreach ($toSend as $chk) {
            $cid = (int) ($chk['check_id'] ?? 0);
            $due = parse_date_to_iso((string) ($chk['due_date'] ?? '')) ?? (string) ($chk['due_date'] ?? '');
            if ($cid < 1 || $due === '') {
                continue;
            }
            try {
                $ins->execute([$cid, $due, $notifyType]);
                $totalChecks++;
            } catch (Throwable $e) {
                // ignore duplicate
            }
        }
    }

    if ($totalChecks === 0 && $lastError !== null) {
        return ['ok' => false, 'sent' => 0, 'emails' => $totalEmails, 'error' => $lastError];
    }

    if ($totalChecks === 0) {
        return ['ok' => true, 'sent' => 0, 'emails' => 0, 'skipped' => true, 'reason' => 'already_sent'];
    }

    return ['ok' => true, 'sent' => $totalChecks, 'emails' => $totalEmails];
}

function fin_out_check_due_email_run_scheduled(PDO $pdo, bool $force = false): array
{
    require_once app_path('includes/acc_coa_bootstrap.php');

    if (!$force && !fin_out_check_due_email_has_pending($pdo)) {
        return ['ok' => true, 'sent' => 0, 'skipped' => true, 'reason' => 'nothing_pending'];
    }

    if (!$force) {
        $interval = fin_check_due_email_scheduled_interval_seconds(true);
        if ($interval > 0) {
            $last = acc_coa_meta_get($pdo, 'out_check_due_email_last_ts');
            if ($last !== null && $last !== '' && (time() - (int) $last) < $interval) {
                return ['ok' => true, 'sent' => 0, 'skipped' => true, 'reason' => 'throttled'];
            }
        }
    }

    $out = fin_out_check_due_email_run($pdo);
    acc_coa_meta_set($pdo, 'out_check_due_email_last_ts', (string) time());
    $_SESSION['fin_out_check_due_email_boot'] = 1;

    return $out;
}
