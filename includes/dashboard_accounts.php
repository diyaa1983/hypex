<?php
declare(strict_types=1);

function dashboard_accounts_table_exists(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        $st->execute(['sys_dashboard_account']);
        $ok = (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        $ok = false;
    }

    return $ok;
}

function dashboard_accounts_ensure_schema(PDO $pdo): bool
{
    if (dashboard_accounts_table_exists($pdo)) {
        return true;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sys_dashboard_account (
              account_id INT UNSIGNED NOT NULL,
              is_visible TINYINT(1) NOT NULL DEFAULT 0,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (account_id),
              CONSTRAINT fk_sys_dashboard_account_acc
                FOREIGN KEY (account_id) REFERENCES acc_account (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        return dashboard_accounts_table_exists($pdo);
    } catch (Throwable $e) {
        return false;
    }
}

function dashboard_accounts_register(PDO $pdo, int $accountId): void
{
    if ($accountId < 1 || !dashboard_accounts_ensure_schema($pdo)) {
        return;
    }
    try {
        $pdo->prepare(
            'INSERT IGNORE INTO sys_dashboard_account (account_id, is_visible) VALUES (?, 0)'
        )->execute([$accountId]);
    } catch (Throwable $e) {
        // ignore
    }
}

/** يضمن وجود صف لكل حساب نهائي نشط. */
function dashboard_accounts_sync_all(PDO $pdo): void
{
    if (!dashboard_accounts_ensure_schema($pdo)) {
        return;
    }
    try {
        $pdo->exec(
            'INSERT IGNORE INTO sys_dashboard_account (account_id, is_visible)
             SELECT a.id, 0
             FROM acc_account a
             WHERE a.is_active = 1 AND a.is_leaf = 1'
        );
    } catch (Throwable $e) {
        // ignore
    }
}

/** @return list<int> */
function dashboard_accounts_default_visible_ids(PDO $pdo): array
{
    require_once app_path('includes/acc_gl.php');
    require_once app_path('includes/fin_voucher.php');

    if (!acc_gl_has_posting_table($pdo)) {
        return [];
    }

    $ids = [];
    $push = static function (int $id) use (&$ids): void {
        if ($id > 0) {
            $ids[$id] = $id;
        }
    };

    $push(acc_gl_cash_box_account_id($pdo));
    $push(acc_gl_checks_fund_account_id($pdo));

    $settings = acc_gl_load_settings($pdo);
    foreach (['bank', 'ar_customers', 'ap_suppliers', 'inventory'] as $ruleCode) {
        $push((int) ($settings[$ruleCode]['account_id'] ?? 0));
    }

    foreach (fin_voucher_load_cash_bank_accounts($pdo) as $acc) {
        if ((string) ($acc['group_key'] ?? '') === 'bank') {
            $push((int) ($acc['id'] ?? 0));
        }
    }
    $mappedBankId = (int) ($settings['bank']['account_id'] ?? 0);
    if ($mappedBankId > 0) {
        $push($mappedBankId);
    }

    foreach (['salaries_payable'] as $ruleCode) {
        $push((int) ($settings[$ruleCode]['account_id'] ?? 0));
    }

    require_once app_path('includes/hr_social_security_payroll.php');
    require_once app_path('includes/hr_income_tax.php');
    $push((int) ($settings[HR_SS_PAYABLE_RULE_CODE]['account_id'] ?? 0));
    $push((int) ($settings[HR_INCOME_TAX_RULE_CODE]['account_id'] ?? 0));

    require_once app_path('includes/acc_vat_trust_account.php');
    require_once app_path('includes/acc_report_vat_jordan.php');
    require_once app_path('includes/date_defaults.php');
    $vat = acc_report_vat_jordan_summary($pdo, app_default_date_from(), date('Y-m-d'));
    $push((int) ($vat['trust_account_id'] ?? 0));

    return array_values($ids);
}

function dashboard_accounts_seed_defaults_if_empty(PDO $pdo): void
{
    if (!dashboard_accounts_ensure_schema($pdo)) {
        return;
    }
    dashboard_accounts_sync_all($pdo);

    try {
        $visibleCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM sys_dashboard_account WHERE is_visible = 1'
        )->fetchColumn();
        if ($visibleCount > 0) {
            return;
        }

        $defaultIds = dashboard_accounts_default_visible_ids($pdo);
        if ($defaultIds === []) {
            return;
        }

        $pdo->beginTransaction();
        $pdo->exec('UPDATE sys_dashboard_account SET is_visible = 0');
        $st = $pdo->prepare('UPDATE sys_dashboard_account SET is_visible = 1 WHERE account_id = ?');
        foreach ($defaultIds as $id) {
            dashboard_accounts_register($pdo, (int) $id);
            $st->execute([(int) $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

/**
 * @return list<array<string, mixed>>
 */
function dashboard_accounts_list(PDO $pdo): array
{
    dashboard_accounts_sync_all($pdo);

    try {
        $rows = $pdo->query(
            'SELECT a.id, a.code, a.name_ar, a.account_type,
                    COALESCE(d.is_visible, 0) AS is_visible
             FROM acc_account a
             LEFT JOIN sys_dashboard_account d ON d.account_id = a.id
             WHERE a.is_active = 1 AND a.is_leaf = 1
             ORDER BY a.code ASC, a.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @param list<int> $visibleIds */
function dashboard_accounts_save_visibility(PDO $pdo, array $visibleIds): void
{
    if (!dashboard_accounts_ensure_schema($pdo)) {
        throw new RuntimeException('جدول إعدادات لوحة التحكم غير متوفر.');
    }

    dashboard_accounts_sync_all($pdo);

    $allowed = [];
    foreach ($visibleIds as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $allowed[$id] = $id;
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE sys_dashboard_account SET is_visible = 0');
        if ($allowed !== []) {
            $st = $pdo->prepare('UPDATE sys_dashboard_account SET is_visible = 1 WHERE account_id = ?');
            foreach ($allowed as $id) {
                dashboard_accounts_register($pdo, $id);
                $st->execute([$id]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** @return array{treasury:list<array>, liabilities:list<array>} */
function dashboard_accounts_collect_panels(PDO $pdo, string $dateFrom, string $dateTo, array $checkSummary = []): array
{
    dashboard_accounts_seed_defaults_if_empty($pdo);

    $treasury = [];
    $liabilities = [];

    if (!dashboard_accounts_table_exists($pdo)) {
        return ['treasury' => [], 'liabilities' => []];
    }

    require_once app_path('includes/acc_gl.php');
    if (!acc_gl_has_posting_table($pdo) || !acc_journal_has_tables($pdo)) {
        return ['treasury' => [], 'liabilities' => []];
    }

    $checksFundId = acc_gl_checks_fund_account_id($pdo);

    try {
        $st = $pdo->query(
            'SELECT a.id, a.code, a.name_ar, a.account_type
             FROM acc_account a
             INNER JOIN sys_dashboard_account d ON d.account_id = a.id AND d.is_visible = 1
             WHERE a.is_active = 1 AND a.is_leaf = 1
             ORDER BY a.code ASC, a.id ASC'
        );
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return ['treasury' => [], 'liabilities' => []];
    }

    foreach ($rows as $row) {
        $accountId = (int) ($row['id'] ?? 0);
        if ($accountId < 1) {
            continue;
        }

        $accountType = (string) ($row['account_type'] ?? '');
        $isLiability = $accountType === 'liability';
        $label = trim((string) ($row['name_ar'] ?? ''));
        if ($label === '') {
            $label = trim((string) ($row['code'] ?? ''));
        }

        $opts = [
            'label' => $label,
            'liability' => $isLiability,
            'tone' => $isLiability ? 'warn' : 'primary',
        ];

        if ($accountId === $checksFundId && (int) ($checkSummary['total'] ?? 0) > 0) {
            require_once app_path('includes/dashboard_permissions.php');
            if (dashboard_widget_can('dashboard_panel_checks')) {
                $opts['click_filter'] = 'all';
            }
        }

        $metric = dashboard_account_metric_by_id($pdo, $accountId, $dateFrom, $dateTo, $opts);
        if ($metric === null) {
            continue;
        }

        if ($isLiability) {
            $liabilities[] = $metric;
        } else {
            $treasury[] = $metric;
        }
    }

    return ['treasury' => $treasury, 'liabilities' => $liabilities];
}

/**
 * @return array{label:string, value:string, hint?:string, tone?:string, url?:string, click_filter?:string, details?:list<array>}|null
 */
function dashboard_account_metric_by_id(
    PDO $pdo,
    int $accountId,
    string $dateFrom,
    string $dateTo,
    array $opts = []
): ?array {
    require_once app_path('includes/dashboard_stats.php');
    require_once app_path('includes/acc_report.php');

    if ($accountId < 1) {
        return null;
    }

    try {
        $st = $pdo->prepare('SELECT code, name_ar, account_type FROM acc_account WHERE id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$accountId]);
        $acc = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    if (!$acc) {
        return null;
    }

    $sums = acc_report_account_sums($pdo, $accountId);
    $rawBal = (float) ($sums['balance'] ?? 0);
    $isLiability = !empty($opts['liability']) || (string) ($acc['account_type'] ?? '') === 'liability';
    $displayBal = $isLiability ? max(0.0, -$rawBal) : $rawBal;

    $label = trim((string) ($opts['label'] ?? ''));
    if ($label === '') {
        $label = trim((string) ($acc['name_ar'] ?? ''));
    }

    $code = trim((string) ($acc['code'] ?? ''));
    $name = trim((string) ($acc['name_ar'] ?? ''));
    $shortHint = $code !== '' ? $code . ' · من الدفتر' : 'من الدفتر';
    $hintExtra = trim((string) ($opts['hint_extra'] ?? ''));
    if ($hintExtra !== '') {
        $shortHint .= ' · ' . $hintExtra;
    }

    $tone = (string) ($opts['tone'] ?? 'primary');
    if ($isLiability) {
        if ($displayBal > 0.0005) {
            $tone = 'warn';
        } elseif ($displayBal <= 0.0005) {
            $tone = 'success';
        }
    } elseif ($displayBal < -0.0005) {
        $tone = 'warn';
    }

    $m = dashboard_metric($label, $displayBal, 'money', $shortHint, $tone);
    $url = dashboard_sensitive_account_url($pdo, $accountId, $dateFrom, $dateTo);
    if ($url !== '') {
        $m['url'] = $url;
    }
    $clickFilter = trim((string) ($opts['click_filter'] ?? ''));
    if ($clickFilter !== '') {
        $m['click_filter'] = $clickFilter;
    }

    $detailLabel = $name !== '' ? $name : ($code !== '' ? $code : $label);
    $m['details'] = dashboard_gl_account_details($pdo, $accountId, $displayBal, $dateFrom, $dateTo, $isLiability);

    return $m;
}
