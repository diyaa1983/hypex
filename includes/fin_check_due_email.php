<?php
declare(strict_types=1);

require_once app_path('includes/company_smtp.php');
require_once app_path('includes/fin_voucher_checks.php');

function fin_check_due_email_ensure_schema(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id FROM fin_check_due_email_log LIMIT 1');
    } catch (Throwable $e) {
        require_once app_path('includes/sql_migration.php');
        sql_migration_run_file($pdo, 'database/migrations/078_fin_check_due_email_notify.sql');
    }

    fin_check_due_email_ensure_settings_columns($pdo);

    try {
        $pdo->query('SELECT id FROM fin_check_due_email_log LIMIT 1');

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function fin_check_due_email_ensure_settings_columns(PDO $pdo): void
{
    $cols = [
        'check_email_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'check_email_days_before' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 5',
        'check_email_on_due_day' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'check_email_recipients' => 'TEXT NULL',
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
function fin_check_due_email_settings(PDO $pdo): array
{
    fin_check_due_email_ensure_settings_columns($pdo);
    $defaults = [
        'enabled' => false,
        'days_before' => 5,
        'on_due_day' => true,
        'recipients' => [],
    ];
    try {
        $row = $pdo->query(
            'SELECT check_email_enabled, check_email_days_before, check_email_on_due_day,
                    check_email_recipients, email
             FROM sys_company_settings WHERE id = 1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $defaults['enabled'] = (int) ($row['check_email_enabled'] ?? 0) === 1;
        $days = (int) ($row['check_email_days_before'] ?? 5);
        $defaults['days_before'] = max(1, min(60, $days));
        $defaults['on_due_day'] = (int) ($row['check_email_on_due_day'] ?? 1) === 1;
        $parsed = fin_check_due_email_parse_recipients((string) ($row['check_email_recipients'] ?? ''));
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

/**
 * @return list<string>
 */
function fin_check_due_email_parse_recipients(string $raw): array
{
    $raw = str_replace(["\r\n", "\r", ";"], "\n", $raw);
    $parts = preg_split('/[\n,]+/', $raw) ?: [];
    $out = [];
    $seen = [];
    foreach ($parts as $p) {
        $email = trim((string) $p);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $key = strtolower($email);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $email;
    }

    return $out;
}

/** notify_type: d0 = يوم الاستحقاق، d5 = قبل 5 أيام، إلخ */
function fin_check_due_email_notify_type(int $daysBeforeDue): string
{
    return 'd' . max(0, $daysBeforeDue);
}

/**
 * @param list<array{check_id:int, due_date:string}> $items
 * @return array<int, true>
 */
function fin_check_due_email_already_sent_map(PDO $pdo, array $items, int $daysBeforeDue): array
{
    if ($items === [] || !fin_check_due_email_ensure_schema($pdo)) {
        return [];
    }

    $notifyType = fin_check_due_email_notify_type($daysBeforeDue);
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

function fin_check_due_email_days_until(string $todayIso, string $dueIso): ?int
{
    try {
        $todayDt = new DateTimeImmutable($todayIso);
        $dueDt = new DateTimeImmutable($dueIso);

        return (int) $todayDt->diff($dueDt)->format('%r%a');
    } catch (Throwable $e) {
        return null;
    }
}

function fin_check_due_email_subject(int $daysBeforeDue, string $companyName): string
{
    if ($daysBeforeDue === 0) {
        $subject = 'تنبيه: شيكات مستحقة اليوم';
    } elseif ($daysBeforeDue === 1) {
        $subject = 'تنبيه: شيكات — يتبقى يوم واحد على الاستحقاق';
    } else {
        $subject = 'تنبيه: شيكات — يتبقى ' . $daysBeforeDue . ' أيام على الاستحقاق';
    }
    if ($companyName !== '') {
        $subject .= ' — ' . $companyName;
    }

    return $subject;
}

/**
 * @param list<array<string, mixed>> $checks
 */
function fin_check_due_email_build_html(PDO $pdo, array $checks, int $daysBeforeDue): string
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
        $intro = 'هذا تنبيه تلقائي: الشيكات التالية <strong>مستحقة اليوم</strong> في صندوق الشيكات.';
    } elseif ($daysBeforeDue === 1) {
        $intro = 'هذا تنبيه تلقائي: يتبقى <strong>يوم واحد</strong> على استحقاق الشيكات التالية في صندوق الشيكات.';
    } else {
        $intro = 'هذا تنبيه تلقائي: يتبقى <strong>' . $daysBeforeDue
            . ' أيام</strong> على استحقاق الشيكات التالية في صندوق الشيكات.';
    }

    $title = $company !== '' ? $company : 'النظام';

    return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;direction:rtl;color:#0f172a;">'
        . '<p style="margin:0 0 12px;">مرحباً،</p>'
        . '<p style="margin:0 0 16px;">' . $intro . ' العدد: <strong>' . count($checks) . '</strong>.</p>'
        . '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
        . '<thead><tr style="background:#f1f5f9;">'
        . '<th style="padding:8px;border:1px solid #e2e8f0;">رقم الشيك</th>'
        . '<th style="padding:8px;border:1px solid #e2e8f0;">البنك</th>'
        . '<th style="padding:8px;border:1px solid #e2e8f0;">العميل</th>'
        . '<th style="padding:8px;border:1px solid #e2e8f0;">سند القبض</th>'
        . '<th style="padding:8px;border:1px solid #e2e8f0;">تاريخ الاستحقاق</th>'
        . '<th style="padding:8px;border:1px solid #e2e8f0;">المبلغ</th>'
        . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
        . '<p style="margin:16px 0 0;color:#64748b;font-size:12px;">'
        . 'تم الإرسال تلقائياً من ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . ' · <a href="' . htmlspecialchars(app_url('index.php?r=dashboard'), ENT_QUOTES, 'UTF-8') . '">لوحة التحكم</a>'
        . '</p></div>';
}

/**
 * @return array{
 *   cfg:array{enabled:bool,days_before:int,on_due_day:bool,recipients:list<string>},
 *   buckets:array<int, list<array<string, mixed>>>,
 *   company:string
 * }|null
 */
function fin_check_due_email_prepare_buckets(PDO $pdo, ?string $today = null): ?array
{
    $today = $today ?? date('Y-m-d');
    $todayIso = parse_date_to_iso($today) ?? $today;

    if (!fin_check_due_email_ensure_schema($pdo)) {
        return null;
    }

    $cfg = fin_check_due_email_settings($pdo);
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

    $allPending = fin_voucher_checks_pending_collection($pdo, $todayIso);
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

/** هل يوجد شيكات تحتاج تنبيه بريد ولم يُرسل لها بعد؟ */
function fin_check_due_email_has_pending(PDO $pdo, ?string $today = null): bool
{
    $prep = fin_check_due_email_prepare_buckets($pdo, $today);
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
        $already = fin_check_due_email_already_sent_map($pdo, $items, (int) $daysBefore);
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
 * إرسال تنبيهات الشيكات حسب الإعدادات (يومياً قبل الاستحقاق + اختياري يوم الاستحقاق).
 *
 * @return array{ok:bool, sent:int, emails:int, skipped?:bool, reason?:string, error?:string}
 */
function fin_check_due_email_run(PDO $pdo, ?string $today = null): array
{
    if (!fin_check_due_email_ensure_schema($pdo)) {
        return ['ok' => false, 'sent' => 0, 'emails' => 0, 'error' => 'جدول سجل التنبيهات غير متوفر.'];
    }

    $prep = fin_check_due_email_prepare_buckets($pdo, $today);
    if ($prep === null) {
        $cfg = fin_check_due_email_settings($pdo);
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
        $already = fin_check_due_email_already_sent_map($pdo, $items, (int) $daysBefore);
        $toSend = [];
        foreach ($checks as $chk) {
            $cid = (int) ($chk['check_id'] ?? 0);
            if ($cid > 0 && !isset($already[$cid])) {
                $toSend[] = $chk;
            }
        }
        if ($toSend === []) {
            continue;
        }

        $subject = fin_check_due_email_subject((int) $daysBefore, $company);
        $bodyHtml = fin_check_due_email_build_html($pdo, $toSend, (int) $daysBefore);
        $notifyType = fin_check_due_email_notify_type((int) $daysBefore);
        $batchOk = true;

        foreach ($recipients as $to) {
            $result = company_smtp_send($to, $subject, $bodyHtml);
            if (!($result['ok'] ?? false)) {
                $batchOk = false;
                $lastError = (string) ($result['error'] ?? 'تعذر إرسال البريد.');
                break;
            }
            $totalEmails++;
        }

        if (!$batchOk) {
            return [
                'ok' => false,
                'sent' => $totalChecks,
                'emails' => $totalEmails,
                'error' => $lastError ?? 'تعذر إرسال البريد.',
            ];
        }

        foreach ($toSend as $chk) {
            $cid = (int) ($chk['check_id'] ?? 0);
            $dueIso = parse_date_to_iso((string) ($chk['due_date'] ?? '')) ?? (string) ($chk['due_date'] ?? '');
            if ($cid > 0 && $dueIso !== '') {
                $ins->execute([$cid, $dueIso, $notifyType]);
                $totalChecks++;
            }
        }
    }

    if ($totalChecks === 0) {
        return ['ok' => true, 'sent' => 0, 'emails' => 0, 'skipped' => true, 'reason' => 'already_sent'];
    }

    return ['ok' => true, 'sent' => $totalChecks, 'emails' => $totalEmails];
}

/** فترة الانتظار بين محاولات الإرسال (ثوانٍ) عند وجود شيكات معلّقة. */
function fin_check_due_email_scheduled_interval_seconds(bool $hasPending): int
{
    if (!$hasPending) {
        return 86400;
    }
    if (empty($_SESSION['fin_check_due_email_boot'])) {
        return 0;
    }

    return 120;
}

/**
 * @return array{ok:bool, sent:int, emails?:int, skipped?:bool, reason?:string, error?:string}
 */
function fin_check_due_email_run_scheduled(PDO $pdo, bool $force = false): array
{
    require_once app_path('includes/acc_coa_bootstrap.php');

    if (!$force && !fin_check_due_email_has_pending($pdo)) {
        return ['ok' => true, 'sent' => 0, 'skipped' => true, 'reason' => 'nothing_pending'];
    }

    if (!$force) {
        $interval = fin_check_due_email_scheduled_interval_seconds(true);
        if ($interval > 0) {
            $last = acc_coa_meta_get($pdo, 'check_due_email_last_ts');
            if ($last !== null && $last !== '' && (time() - (int) $last) < $interval) {
                return ['ok' => true, 'sent' => 0, 'skipped' => true, 'reason' => 'throttled'];
            }
        }
    }

    $out = fin_check_due_email_run($pdo);
    acc_coa_meta_set($pdo, 'check_due_email_last_ts', (string) time());
    $_SESSION['fin_check_due_email_boot'] = 1;

    return $out;
}

/** تشغيل الإرسال بعد عرض الصفحة حتى لا يتأخر فتح النظام. */
function fin_check_due_email_register_background_runner(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    try {
        require_once app_path('includes/acc_coa_bootstrap.php');
        $interval = fin_check_due_email_scheduled_interval_seconds(true);
        if ($interval > 0) {
            $last = acc_coa_meta_get(db(), 'check_due_email_last_ts');
            if ($last !== null && $last !== '' && (time() - (int) $last) < $interval) {
                return;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    register_shutdown_function(static function (): void {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @flush();
        }

        try {
            fin_check_due_email_run_scheduled(db());
        } catch (Throwable $e) {
            // لا يوقف التطبيق
        }
    });
}
