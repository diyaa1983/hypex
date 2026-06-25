<?php
declare(strict_types=1);

require_once app_path('includes/pur_invoice_post.php');
require_once app_path('includes/pur_invoice_schema.php');
require_once app_path('includes/crm_supplier_ledger.php');
require_once app_path('includes/pur_return_schema.php');
require_once app_path('includes/pur_return_post.php');
require_once app_path('includes/inv_stock.php');

const PUR_INVOICE_DELETE_REQUIRES_EMPTY_LINES_MSG =
    'لا يمكن حذف الفاتورة قبل حذف جميع بنود المواد منها. احذف البنود من الجدول ثم احفظ الفاتورة، وبعدها احذف الفاتورة.';

function pur_invoice_line_count(PDO $pdo, int $invoiceId): int
{
    if ($invoiceId < 1) {
        return 0;
    }
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM pur_invoice_line WHERE invoice_id = ?');
        $st->execute([$invoiceId]);

        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function pur_invoice_is_fully_posted(PDO $pdo, int $invoiceId): bool
{
    if ($invoiceId < 1) {
        return false;
    }
    try {
        return pur_invoice_is_posted($pdo, $invoiceId);
    } catch (Throwable $e) {
        return crm_supplier_ledger_purchase_invoice_is_posted($pdo, $invoiceId);
    }
}

/** @return array{ok:bool, error:?string, invoice_no:?string, invoice_date:?string} */
function pur_invoice_can_delete(PDO $pdo, int $invoiceId): array
{
    $out = ['ok' => false, 'error' => null, 'invoice_no' => null, 'invoice_date' => null];

    if ($invoiceId < 1) {
        $out['error'] = 'معرّف الفاتورة غير صالح.';

        return $out;
    }

    try {
        $st = $pdo->prepare('SELECT invoice_no, invoice_date, status FROM pur_invoice WHERE id = ? LIMIT 1');
        $st->execute([$invoiceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $out['error'] = 'تعذر التحقق من الفاتورة.';

        return $out;
    }

    if (!$row) {
        $out['error'] = 'الفاتورة غير موجودة.';

        return $out;
    }

    $out['invoice_no'] = (string) ($row['invoice_no'] ?? '');
    $out['invoice_date'] = (string) ($row['invoice_date'] ?? '');

    if (pur_invoice_is_fully_posted($pdo, $invoiceId)) {
        $out['error'] = 'لا يمكن حذف فاتورة مرحّلة (مخزون وذمة المورد).';

        return $out;
    }

    if (pur_return_has_tables($pdo)) {
        try {
            $ret = $pdo->prepare('SELECT id FROM pur_return WHERE invoice_id = ? AND status <> ?');
            $ret->execute([$invoiceId, 'cancelled']);
            foreach ($ret->fetchAll(PDO::FETCH_COLUMN) ?: [] as $returnId) {
                $rid = (int) $returnId;
                if ($rid < 1) {
                    continue;
                }
                if (pur_return_is_posted($pdo, $rid)) {
                    $out['error'] = 'لا يمكن حذف الفاتورة لوجود مردود مشتريات مرحّل مرتبط بها.';

                    return $out;
                }
            }
        } catch (Throwable $e) {
            $out['error'] = 'تعذر التحقق من مردودات المشتريات.';

            return $out;
        }
    }

    if (!pur_invoice_is_fully_posted($pdo, $invoiceId) && pur_invoice_line_count($pdo, $invoiceId) > 0) {
        $out['error'] = PUR_INVOICE_DELETE_REQUIRES_EMPTY_LINES_MSG;

        return $out;
    }

    $out['ok'] = true;

    return $out;
}

/**
 * حذف مردودات المشتريات غير المرحّلة المرتبطة بالفاتورة (قبل حذف فاتورة غير مرحّلة).
 * @return string|null رسالة خطأ أو null عند النجاح
 */
function pur_invoice_delete_unposted_returns_for_invoice(PDO $pdo, int $invoiceId): ?string
{
    if ($invoiceId < 1 || !pur_return_has_tables($pdo)) {
        return null;
    }

    require_once app_path('includes/pur_return_delete.php');

    $st = $pdo->prepare("SELECT id FROM pur_return WHERE invoice_id = ? AND status <> 'cancelled'");
    $st->execute([$invoiceId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $returnId) {
        $rid = (int) $returnId;
        if ($rid < 1) {
            continue;
        }
        if (pur_return_is_posted($pdo, $rid)) {
            return 'لا يمكن حذف الفاتورة لوجود مردود مشتريات مرحّل مرتبط بها.';
        }
        $del = pur_return_delete_by_id($pdo, $rid);
        if (!$del['ok']) {
            return $del['error'] ?? 'تعذر حذف مردود مشتريات مرتبط بالفاتورة.';
        }
    }

    return null;
}

function pur_invoice_delete_cleanup_posting_artifacts(PDO $pdo, int $invoiceId): void
{
    if ($invoiceId < 1) {
        return;
    }

    if (inv_stock_move_has_table($pdo)) {
        try {
            $pdo->prepare(
                "DELETE FROM inv_stock_move WHERE ref_type = 'purchase_invoice' AND ref_id = ?"
            )->execute([$invoiceId]);
        } catch (Throwable $e) {
        }
    }

    if (crm_supplier_ledger_has_table($pdo)) {
        try {
            $pdo->prepare(
                "DELETE FROM crm_supplier_ledger WHERE txn_type = 'purchase_invoice' AND ref_id = ?"
            )->execute([$invoiceId]);
        } catch (Throwable $e) {
        }
    }
}

/** @return array{ok:bool, error:?string, message:?string} */
function pur_invoice_delete_by_id(PDO $pdo, int $invoiceId): array
{
    $check = pur_invoice_can_delete($pdo, $invoiceId);
    if (!$check['ok']) {
        return ['ok' => false, 'error' => $check['error'], 'message' => null];
    }

    $no = (string) ($check['invoice_no'] ?? '');
    $invoiceDate = (string) ($check['invoice_date'] ?? '');

    try {
        if (pur_return_has_tables($pdo)) {
            $purge = pur_invoice_delete_unposted_returns_for_invoice($pdo, $invoiceId);
            if ($purge !== null) {
                return ['ok' => false, 'error' => $purge, 'message' => null];
            }
        }

        pur_invoice_delete_cleanup_posting_artifacts($pdo, $invoiceId);

        require_once app_path('includes/sys_audit_log.php');
        sys_audit_log_pur_invoice($pdo, 'delete', $invoiceId);

        $st = $pdo->prepare('DELETE FROM pur_invoice WHERE id = ?');
        $st->execute([$invoiceId]);
    } catch (PDOException $e) {
        $code = (string) $e->getCode();
        if ($code === '23000' || str_contains($e->getMessage(), 'foreign key') || str_contains($e->getMessage(), '1451')) {
            return [
                'ok' => false,
                'error' => 'لا يمكن حذف الفاتورة: توجد مستندات مرتبطة.',
                'message' => null,
            ];
        }

        return ['ok' => false, 'error' => 'تعذر حذف الفاتورة من قاعدة البيانات.', 'message' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'تعذر حذف الفاتورة.', 'message' => null];
    }

    if ($st->rowCount() < 1) {
        return ['ok' => false, 'error' => 'تعذر حذف الفاتورة (لم يُعثر على السجل).', 'message' => null];
    }

    if ($no !== '' && $invoiceDate !== '') {
        pur_invoice_release_no_to_pool($pdo, $no, $invoiceDate);
    }

    $label = $no !== '' ? $no : ('#' . $invoiceId);

    return [
        'ok' => true,
        'error' => null,
        'message' => 'تم حذف الفاتورة ' . $label . '. الرقم متاح لإعادة استخدامه في فاتورة جديدة.',
    ];
}

/** @param list<int> $invoiceIds @return array{deleted:int, errors:list<string>} */
function pur_invoice_delete_by_ids(PDO $pdo, array $invoiceIds): array
{
    $result = ['deleted' => 0, 'errors' => []];
    foreach ($invoiceIds as $rawId) {
        $id = (int) $rawId;
        if ($id < 1) {
            continue;
        }
        $one = pur_invoice_delete_by_id($pdo, $id);
        if ($one['ok']) {
            $result['deleted']++;
        } elseif ($one['error'] !== null) {
            $result['errors'][] = $one['error'];
        }
    }

    return $result;
}
