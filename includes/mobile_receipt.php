<?php

declare(strict_types=1);



require_once app_path('includes/mobile_auth.php');



function mobile_can_access_receipt_api(): bool

{

    return user_can('cash_receipt') || user_can('m_receipt');

}



function mobile_can_post_receipt(): bool

{

    if (!mobile_can_access_receipt_api()) {

        return false;

    }

    if (user_can_action('action_post_cash_receipt')) {

        return true;

    }



    return user_can('m_receipt') && mobile_is_context();

}



function mobile_can_unpost_receipt(): bool

{

    if (!mobile_can_access_receipt_api()) {

        return false;

    }

    if (user_can_action('action_unpost_cash_receipt')) {

        return true;

    }



    return user_can('m_receipt') && mobile_is_context();

}



function mobile_can_delete_receipt(): bool

{

    if (!mobile_can_access_receipt_api()) {

        return false;

    }

    if (user_can_action('action_delete_cash_receipt')) {

        return true;

    }



    return user_can('m_receipt') && mobile_is_context();

}



/** @param array<string, mixed> $voucher */

function mobile_receipt_enrich_display(PDO $pdo, array $voucher): array

{

    $cid = (int) ($voucher['party_id'] ?? $voucher['customer_id'] ?? 0);

    if ($cid > 0 && empty($voucher['customer_name'])) {

        $st = $pdo->prepare(

            'SELECT c.name_ar, c.code, r.name_ar AS sales_rep_name

             FROM crm_customer c

             LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id AND r.is_active = 1

             WHERE c.id = ? LIMIT 1'

        );

        $st->execute([$cid]);

        $c = $st->fetch(PDO::FETCH_ASSOC);

        if ($c) {

            $voucher['customer_name'] = (string) ($c['name_ar'] ?? '');

            $voucher['customer_code'] = (string) ($c['code'] ?? '');

            $voucher['sales_rep_name'] = (string) ($c['sales_rep_name'] ?? '');

        }

    }



    return $voucher;

}



/**

 * قائمة سندات القبض للموبايل — نفس استعلام سطح المكتب مع حد أقصى للصفوف.

 *

 * @return list<array<string, mixed>>

 */

function mobile_receipt_list_rows(PDO $pdo, string $filter = 'all', string $search = '', int $limit = 120): array

{

    require_once app_path('includes/fin_voucher_list.php');



    crm_ledger_ensure_schema($pdo);



    $limit = max(1, min(200, $limit));

    require_once app_path('includes/crm_sales_rep_schema.php');
    $scopedRepId = crm_mobile_scoped_sales_rep_id($pdo);
    $list = fin_voucher_list_fetch($pdo, 'receipt', $filter, $search, $limit, $scopedRepId);

    $rows = $list['rows'] ?? [];



    foreach ($rows as &$row) {

        $row['is_posted'] = !empty($row['is_posted']);

        $row['voucher_date_dmy'] = format_date_dmY((string) ($row['voucher_date'] ?? ''));

        $payMethod = (string) ($row['pay_method'] ?? '');

        $row['pay_label'] = fin_voucher_pay_method_label($payMethod);

        $row['amount_fmt'] = format_amount((float) ($row['amount'] ?? 0));

    }

    unset($row);



    return $rows;

}

