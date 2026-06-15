<?php

declare(strict_types=1);



require_once dirname(__DIR__) . '/includes/bootstrap.php';

require_once app_path('includes/sal_invoice_gps.php');

require_once app_path('includes/mobile_invoice.php');



header('Content-Type: application/json; charset=utf-8');



$mayView = is_logged_in() && (

    user_can('sales_invoice_gps')

    || user_can('sales_documents_list')

    || mobile_can_access_sales_invoice_api()

);



if (!$mayView) {

    http_response_code(403);

    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);

    exit;

}



$pdo = db();

$invoiceId = (int) ($_GET['invoice_id'] ?? 0);

$lat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;

$lng = isset($_GET['lng']) ? (float) $_GET['lng'] : null;



try {

    if ($invoiceId > 0) {

        $st = $pdo->prepare(

            'SELECT post_latitude, post_longitude FROM sal_invoice WHERE id = ? LIMIT 1'

        );

        $st->execute([$invoiceId]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (

            !$row

            || !sal_invoice_gps_coords_valid((float) ($row['post_latitude'] ?? 0), (float) ($row['post_longitude'] ?? 0))

        ) {

            echo json_encode([

                'ok' => false,

                'error' => 'not_found',

                'message' => 'لا توجد إحداثيات لهذه الفاتورة.',

            ], JSON_UNESCAPED_UNICODE);

            exit;

        }



        $location = sal_invoice_gps_location_for_invoice($pdo, $invoiceId, true);

        echo json_encode([

            'ok' => true,

            'place' => $location['place'] ?? '',

            'landmark' => $location['landmark'] ?? '',

            'invoice_id' => $invoiceId,

        ], JSON_UNESCAPED_UNICODE);

        exit;

    }



    if (!sal_invoice_gps_coords_valid($lat, $lng)) {

        http_response_code(400);

        echo json_encode([

            'ok' => false,

            'error' => 'invalid_coords',

            'message' => 'إحداثيات غير صالحة.',

        ], JSON_UNESCAPED_UNICODE);

        exit;

    }



    $location = sal_invoice_gps_resolve_location((float) $lat, (float) $lng);

    echo json_encode([

        'ok' => true,

        'place' => $location['place'] ?? '',

        'landmark' => $location['landmark'] ?? '',

    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    error_log('sales_invoice_gps_geocode: ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([

        'ok' => false,

        'error' => 'server_error',

        'message' => 'تعذر تحديد الموقع وأقرب معلم.',

    ], JSON_UNESCAPED_UNICODE);

}

