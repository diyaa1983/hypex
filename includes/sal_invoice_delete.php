<?php

declare(strict_types=1);



require_once app_path('includes/sal_invoice_post.php');

require_once app_path('includes/crm_customer_ledger.php');

require_once app_path('includes/sal_return_schema.php');

/** رسالة عند محاولة حذف فاتورة غير مرحّلة ما زالت تحتوي بنوداً. */
const SAL_INVOICE_DELETE_REQUIRES_EMPTY_LINES_MSG =
    'لا يمكن حذف الفاتورة قبل حذف جميع بنود المواد منها. احذف البنود من الجدول ثم احفظ الفاتورة، وبعدها احذف الفاتورة.';

function sal_invoice_line_count(PDO $pdo, int $invoiceId): int
{
    if ($invoiceId < 1) {
        return 0;
    }
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM sal_invoice_line WHERE invoice_id = ?');
        $st->execute([$invoiceId]);

        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** فاتورة مرحّلة بالكامل (مع حماية من أخطاء أعمدة/جداول ناقصة). */

function sal_invoice_is_fully_posted(PDO $pdo, int $invoiceId): bool

{

    if ($invoiceId < 1) {

        return false;

    }

    try {

        return sal_invoice_is_posted($pdo, $invoiceId);

    } catch (Throwable $e) {

        return crm_ledger_sale_invoice_is_posted($pdo, $invoiceId);

    }

}



/**

 * هل يمكن حذف الفاتورة (غير مرحّلة بالكامل، بدون مردود أو فوترة مرسلة).

 *

 * @return array{ok:bool, error:?string, invoice_no:?string}

 */

function sal_invoice_can_delete(PDO $pdo, int $invoiceId): array

{

    $out = ['ok' => false, 'error' => null, 'invoice_no' => null];



    if ($invoiceId < 1) {

        $out['error'] = 'معرّف الفاتورة غير صالح.';



        return $out;

    }



    try {

        $st = $pdo->prepare('SELECT invoice_no, status FROM sal_invoice WHERE id = ? LIMIT 1');

        $st->execute([$invoiceId]);

        $row = $st->fetch();

    } catch (Throwable $e) {

        $out['error'] = 'تعذر التحقق من الفاتورة.';



        return $out;

    }



    if (!$row) {

        $out['error'] = 'الفاتورة غير موجودة.';



        return $out;

    }



    $out['invoice_no'] = (string) ($row['invoice_no'] ?? '');



    if (sal_invoice_is_fully_posted($pdo, $invoiceId)) {

        $out['error'] = 'لا يمكن حذف فاتورة مرحّلة (مخزون وحساب العميل).';



        return $out;

    }



    try {

        require_once app_path('includes/einvoice_schema.php');

        require_once app_path('includes/einvoice_settings.php');

        if (einvoice_column_exists($pdo, 'sal_invoice', 'einv_qr') && einvoice_sale_is_sent($pdo, $invoiceId)) {

            $out['error'] = 'لا يمكن حذف فاتورة أُرسلت إلى نظام الفوترة.';



            return $out;

        }

    } catch (Throwable $e) {

        // تجاهل — لا نمنع الحذف بسبب فحص الفوترة

    }



    if (sal_return_has_tables($pdo)) {

        try {

            $ret = $pdo->prepare('SELECT id FROM sal_return WHERE invoice_id = ? LIMIT 1');

            $ret->execute([$invoiceId]);

            if ($ret->fetch()) {

                $out['error'] = 'لا يمكن حذف الفاتورة لوجود مردود مبيعات مرتبط بها.';



                return $out;

            }

        } catch (Throwable $e) {

            $out['error'] = 'تعذر التحقق من مردودات المبيعات.';



            return $out;

        }

    }



    if (!sal_invoice_is_fully_posted($pdo, $invoiceId) && sal_invoice_line_count($pdo, $invoiceId) > 0) {
        $out['error'] = SAL_INVOICE_DELETE_REQUIRES_EMPTY_LINES_MSG;

        return $out;
    }



    $out['ok'] = true;



    return $out;

}



/** إزالة آثار ترحيل جزئي قبل حذف الفاتورة. */

function sal_invoice_delete_cleanup_posting_artifacts(PDO $pdo, int $invoiceId): void

{

    if ($invoiceId < 1) {

        return;

    }



    if (inv_stock_move_has_table($pdo)) {

        try {

            $pdo->prepare(

                "DELETE FROM inv_stock_move WHERE ref_type = 'sale_invoice' AND ref_id = ?"

            )->execute([$invoiceId]);

        } catch (Throwable $e) {

            // ignore

        }

    }



    if (crm_ledger_has_table($pdo)) {

        try {

            $pdo->prepare(

                "DELETE FROM crm_customer_ledger WHERE txn_type = 'sale_invoice' AND ref_id = ?"

            )->execute([$invoiceId]);

        } catch (Throwable $e) {

            // ignore

        }

    }

    try {
        require_once app_path('includes/sal_delivery_invoice_link.php');
        sal_invoice_set_delivery_id($pdo, $invoiceId, null);
    } catch (Throwable $e) {
        // ignore
    }

}



/**

 * حذف فاتورة بيع غير المرحّلة (البنود تُحذف تلقائياً CASCADE).

 *

 * @return array{ok:bool, error:?string, message:?string}

 */

function sal_invoice_delete_by_id(PDO $pdo, int $invoiceId, bool $unpostFirst = false): array

{

    if ($unpostFirst) {

        require_once app_path('includes/sal_invoice_unpost.php');

        $unpost = sal_invoice_unpost_by_id($pdo, $invoiceId);

        if (!$unpost['ok']) {

            return ['ok' => false, 'error' => $unpost['error'], 'message' => null];

        }

    }



    $check = sal_invoice_can_delete($pdo, $invoiceId);

    if (!$check['ok']) {

        return ['ok' => false, 'error' => $check['error'], 'message' => null];

    }



    $no = (string) ($check['invoice_no'] ?? '');



    try {

        sal_invoice_delete_cleanup_posting_artifacts($pdo, $invoiceId);



        $st = $pdo->prepare('DELETE FROM sal_invoice WHERE id = ?');

        $st->execute([$invoiceId]);

    } catch (PDOException $e) {

        $code = (string) $e->getCode();

        if ($code === '23000' || str_contains($e->getMessage(), 'foreign key') || str_contains($e->getMessage(), '1451')) {

            return [

                'ok' => false,

                'error' => 'لا يمكن حذف الفاتورة: توجد مستندات مرتبطة (مثل مردود مبيعات).',

                'message' => null,

            ];

        }



        return ['ok' => false, 'error' => 'تعذر حذف الفاتورة من قاعدة البيانات.', 'message' => null];

    } catch (Throwable $e) {

        return ['ok' => false, 'error' => 'تعذر حذف الفاتورة.', 'message' => null];

    }



    if ($st->rowCount() < 1) {

        return ['ok' => false, 'error' => 'تعذر حذف الفاتورة (لم تُعثر على السجل).', 'message' => null];

    }



    $label = $no !== '' ? $no : ('#' . $invoiceId);



    return [

        'ok' => true,

        'error' => null,

        'message' => 'تم حذف الفاتورة ' . $label . '.',

    ];

}



/**

 * @param list<int> $invoiceIds

 * @return array{deleted:int, errors:list<string>}

 */

function sal_invoice_delete_by_ids(PDO $pdo, array $invoiceIds, bool $unpostFirst = false): array

{

    $result = ['deleted' => 0, 'errors' => []];

    foreach ($invoiceIds as $rawId) {

        $id = (int) $rawId;

        if ($id < 1) {

            continue;

        }

        $one = sal_invoice_delete_by_id($pdo, $id, $unpostFirst);

        if ($one['ok']) {

            $result['deleted']++;

        } elseif ($one['error'] !== null) {

            $result['errors'][] = $one['error'];

        }

    }



    return $result;

}

