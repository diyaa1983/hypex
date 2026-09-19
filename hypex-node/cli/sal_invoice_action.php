<?php
declare(strict_types=1);

/**
 * CLI لعمليات فاتورة المبيعات من hypex-node.
 * Usage: php sal_invoice_action.php <action> <userId>
 * actions: post | unpost | delete | einvoice
 * stdin (JSON): { "invoice_id": N }
 * stdout: single JSON line
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

$action = strtolower(trim((string) ($argv[1] ?? '')));
$userId = (int) ($argv[2] ?? 0);

$raw = stream_get_contents(STDIN);
$payload = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);
if (!is_array($payload)) {
    $payload = [];
}

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once app_path('includes/sal_invoice_schema.php');
require_once app_path('includes/sal_invoice_post.php');
require_once app_path('includes/sal_invoice_unpost.php');
require_once app_path('includes/sal_invoice_delete.php');
require_once app_path('includes/crm_customer_ledger.php');
require_once app_path('includes/sys_audit_log.php');
require_once app_path('includes/einvoice_send.php');
require_once app_path('includes/einvoice_schema.php');

function cli_out(array $data, int $code = 0): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    exit($code);
}

function cli_login(int $userId): void
{
    if ($userId < 1) {
        cli_out(['ok' => false, 'error' => 'user_id مطلوب.'], 1);
    }
    // sys_user يستخدم full_name_ar (ليست full_name)
    $u = null;
    $queries = [
        'SELECT id, username, full_name_ar AS full_name FROM sys_user WHERE id = ? AND is_active = 1 LIMIT 1',
        'SELECT id, username, COALESCE(full_name_ar, username) AS full_name FROM sys_user WHERE id = ? AND is_active = 1 LIMIT 1',
        'SELECT id, username, username AS full_name FROM sys_user WHERE id = ? AND is_active = 1 LIMIT 1',
    ];
    foreach ($queries as $sql) {
        try {
            $st = db()->prepare($sql);
            $st->execute([$userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $u = $row;
                break;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    if (!$u) {
        cli_out(['ok' => false, 'error' => 'المستخدم غير موجود.'], 1);
    }
    $_SESSION['user'] = [
        'id' => (int) $u['id'],
        'username' => (string) ($u['username'] ?? ''),
        'name' => (string) ($u['full_name'] ?? $u['username'] ?? ''),
        'full_name' => (string) ($u['full_name'] ?? ''),
        'full_name_ar' => (string) ($u['full_name'] ?? ''),
    ];
    $_SESSION['is_system_admin'] = user_is_system_admin((int) $u['id']);
    $_SESSION['permissions'] = load_user_permissions((int) $u['id']);
    $_SESSION['permissions_user_id'] = (int) $u['id'];
    $_SESSION['permissions_loaded_at'] = time();
    $_SESSION['app_context'] = 'desktop';
}

try {
    cli_login($userId);
    $pdo = db();
    sal_invoice_ensure_schema($pdo);
    crm_ledger_ensure_schema($pdo);

    $invoiceId = (int) ($payload['invoice_id'] ?? $payload['id'] ?? 0);
    if ($invoiceId < 1 && $action !== 'post') {
        // post may accept invoice_ids
    }

    /**
     * DDL (CREATE/ALTER) في MySQL يُنهي أي transaction مفتوحة ضمنياً.
     * يجب تدفئة الجداول قبل beginTransaction لتجنّب "There is no active transaction" عند commit.
     */
    $warmUnpostSchemas = static function (PDO $pdo): void {
        try {
            require_once app_path('includes/sal_invoice_gps.php');
            sal_invoice_gps_ensure_schema($pdo);
        } catch (Throwable $e) {
            error_log('sal_invoice_action warm gps: ' . $e->getMessage());
        }
        try {
            require_once app_path('includes/acc_gl.php');
            acc_gl_ensure_schema($pdo);
        } catch (Throwable $e) {
            error_log('sal_invoice_action warm gl: ' . $e->getMessage());
        }
        try {
            require_once app_path('includes/sys_audit_log.php');
            sys_audit_log_ensure_schema($pdo);
        } catch (Throwable $e) {
            error_log('sal_invoice_action warm audit: ' . $e->getMessage());
        }
        try {
            require_once app_path('includes/doc_number_pool.php');
            doc_number_pool_ensure_table($pdo);
        } catch (Throwable $e) {
            error_log('sal_invoice_action warm pool: ' . $e->getMessage());
        }
        try {
            require_once app_path('includes/einvoice_schema.php');
            if (function_exists('einvoice_ensure_schema')) {
                einvoice_ensure_schema($pdo);
            }
        } catch (Throwable $e) {
            error_log('sal_invoice_action warm einvoice: ' . $e->getMessage());
        }
        try {
            require_once app_path('includes/sal_return_schema.php');
            if (function_exists('sal_return_ensure_schema')) {
                sal_return_ensure_schema($pdo);
            }
        } catch (Throwable $e) {
            // optional
        }
    };

    if ($action === 'post') {
        if (!user_can_sales_invoices() || !user_can_action('action_post_sales_invoice')) {
            cli_out(['ok' => false, 'error' => 'لا صلاحية ترحيل الفاتورة.'], 1);
        }
        $ids = [];
        if ($invoiceId > 0) {
            $ids[] = $invoiceId;
        }
        if (isset($payload['invoice_ids']) && is_array($payload['invoice_ids'])) {
            foreach ($payload['invoice_ids'] as $rawId) {
                $ids[] = (int) $rawId;
            }
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            cli_out(['ok' => false, 'error' => 'لم يُحدَّد أي فاتورة.'], 1);
        }

        $result = sal_invoice_post_by_ids($pdo, $ids);
        $posted = (int) ($result['posted'] ?? 0);
        $skipped = (int) ($result['skipped'] ?? 0);
        $errors = $result['errors'] ?? [];
        $warnings = $result['warnings'] ?? [];

        if ($posted === 0 && $errors === [] && $skipped > 0) {
            $msg = 'الفاتورة/الفواتير مرحّلة مسبقًا.';
        } elseif ($posted > 0) {
            $msg = 'تم ترحيل ' . $posted . ' فاتورة (مستودعيًا وماليًا).';
            if ($skipped > 0) {
                $msg .= ' (' . $skipped . ' كانت مرحّلة مسبقًا)';
            }
        } else {
            $msg = 'لم يُرحَّل أي مستند.';
        }
        if ($errors !== []) {
            $msg .= ' — أخطاء: ' . implode('؛ ', array_slice($errors, 0, 3));
        }

        $ok = $posted > 0 || ($errors === [] && $skipped > 0);
        cli_out([
            'ok' => $ok,
            'posted' => $posted,
            'skipped' => $skipped,
            'errors' => $errors,
            'warnings' => $warnings,
            'message' => $msg,
            'invoice_id' => $ids[0] ?? 0,
        ], $ok ? 0 : 1);
    }

    if ($action === 'unpost') {
        if (!user_can_sales_invoices() || !user_can_action('action_unpost_sales_invoice')) {
            cli_out(['ok' => false, 'error' => 'لا صلاحية فك ترحيل الفاتورة.'], 1);
        }
        if ($invoiceId < 1) {
            cli_out(['ok' => false, 'error' => 'لم تُحدَّد الفاتورة.'], 1);
        }

        $warmUnpostSchemas($pdo);

        try {
            $pdo->beginTransaction();
            $res = sal_invoice_unpost_by_id($pdo, $invoiceId);
            if (empty($res['ok'])) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                cli_out([
                    'ok' => false,
                    'error' => (string) ($res['error'] ?? 'تعذر فك الترحيل.'),
                    'message' => (string) ($res['error'] ?? 'تعذر فك الترحيل.'),
                ], 1);
            }
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            cli_out([
                'ok' => true,
                'message' => (string) ($res['message'] ?? 'تم فك ترحيل الفاتورة.'),
                'invoice_id' => $invoiceId,
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable $rb) {
                    // ignore
                }
            }
            $msg = $e->getMessage();
            // إن انتهى الـDDL بالـcommit الضمني فقد اكتمل فك الترحيل فعلياً
            if (stripos($msg, 'no active transaction') !== false) {
                $stillPosted = false;
                try {
                    $stillPosted = sal_invoice_has_posting_artifacts($pdo, $invoiceId)
                        || sal_invoice_is_fully_posted($pdo, $invoiceId);
                } catch (Throwable $checkE) {
                    $stillPosted = true;
                }
                if (!$stillPosted) {
                    cli_out([
                        'ok' => true,
                        'message' => 'تم فك ترحيل الفاتورة.',
                        'invoice_id' => $invoiceId,
                    ]);
                }
                cli_out([
                    'ok' => false,
                    'error' => 'تعذر إتمام فك الترحيل. حدّث الصفحة وتحقق من حالة الفاتورة ثم أعد المحاولة.',
                    'message' => 'تعذر إتمام فك الترحيل. حدّث الصفحة وتحقق من حالة الفاتورة ثم أعد المحاولة.',
                ], 1);
            }
            throw $e;
        }
    }

    if ($action === 'delete') {
        if (!user_can_sales_invoices() || !user_can_action('action_delete_sales_invoice')) {
            cli_out(['ok' => false, 'error' => 'لا صلاحية حذف الفاتورة.'], 1);
        }
        if ($invoiceId < 1) {
            cli_out(['ok' => false, 'error' => 'لم تُحدَّد الفاتورة.'], 1);
        }
        $res = sal_invoice_delete_by_id($pdo, $invoiceId, false);
        if (empty($res['ok'])) {
            cli_out([
                'ok' => false,
                'error' => (string) ($res['error'] ?? 'تعذر الحذف.'),
                'message' => (string) ($res['error'] ?? 'تعذر الحذف.'),
            ], 1);
        }
        cli_out([
            'ok' => true,
            'message' => (string) ($res['message'] ?? 'تم حذف الفاتورة.'),
            'invoice_id' => $invoiceId,
        ]);
    }

    if ($action === 'einvoice') {
        $maySend = user_can_sales_invoices()
            && (user_can_action('sales_send_einvoice') || user_can('sales_send_einvoice') || user_is_system_admin($userId));
        // إن لم توجد صلاحية منفصلة، يسمح للمخولين بفاتورة المبيعات + ترحيل
        if (!$maySend && user_can_sales_invoices() && user_can_action('action_post_sales_invoice')) {
            $maySend = true;
        }
        if (!$maySend) {
            cli_out(['ok' => false, 'error' => 'لا صلاحية إرسال الفوترة الإلكترونية.'], 1);
        }
        if ($invoiceId < 1) {
            cli_out(['ok' => false, 'error' => 'لم تُحدَّد الفاتورة.'], 1);
        }
        einvoice_ensure_schema($pdo);
        $pdo->beginTransaction();
        $result = einvoice_send_sale_invoice($pdo, $invoiceId);
        if (($result['error'] ?? null) !== null) {
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            cli_out([
                'ok' => false,
                'error' => (string) $result['error'],
                'message' => (string) $result['error'],
                'http_code' => $result['http_code'] ?? null,
            ], 1);
        }
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        cli_out([
            'ok' => true,
            'skipped' => (bool) ($result['skipped'] ?? false),
            'message' => (string) ($result['message'] ?? 'تمت عملية الفوترة.'),
            'invoice_id' => $invoiceId,
        ]);
    }

    cli_out(['ok' => false, 'error' => 'إجراء غير معروف: ' . $action], 1);
} catch (Throwable $e) {
    error_log('sal_invoice_action: ' . $e->getMessage());
    $msg = trim($e->getMessage());
    if ($msg === '' || stripos($msg, 'no active transaction') !== false) {
        $msg = 'تعذر إتمام العملية. حدّث الصفحة وتحقق من الحالة ثم أعد المحاولة.';
    }
    cli_out(['ok' => false, 'error' => $msg], 1);
}
