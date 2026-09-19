<?php

declare(strict_types=1);



require_once dirname(__DIR__) . '/includes/bootstrap.php';

require_once app_path('includes/fin_voucher_schema.php');



header('Content-Type: application/json; charset=utf-8');



require_once app_path('includes/mobile_receipt.php');

if (!is_logged_in() || !mobile_can_delete_receipt()) {

    http_response_code(403);

    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);

    exit;

}



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);

    exit;

}



if (!verify_csrf($_POST['_csrf'] ?? null)) {

    http_response_code(403);

    echo json_encode(['ok' => false, 'message' => 'انتهت صلاحية الجلسة.'], JSON_UNESCAPED_UNICODE);

    exit;

}



$pdo = db();

fin_voucher_ensure_schema_full($pdo);



$ids = [];

if (isset($_POST['voucher_id'])) {

    $ids[] = (int) $_POST['voucher_id'];

}

if (isset($_POST['voucher_ids']) && is_array($_POST['voucher_ids'])) {

    foreach ($_POST['voucher_ids'] as $raw) {

        $ids[] = (int) $raw;

    }

}

$ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));



if ($ids === []) {

    echo json_encode(['ok' => true, 'cleared' => true], JSON_UNESCAPED_UNICODE);

    exit;

}



$errors = [];

$deleted = 0;

foreach ($ids as $id) {

    try {

        fin_voucher_delete($pdo, $id, 'receipt');

        $deleted++;

    } catch (Throwable $e) {

        $errors[] = $e->getMessage() ?: 'تعذر حذف السند #' . $id;

    }

}



if ($errors !== []) {

    echo json_encode([

        'ok' => $deleted > 0,

        'deleted' => $deleted,

        'message' => implode('؛ ', $errors),

        'errors' => $errors,

    ], JSON_UNESCAPED_UNICODE);

    exit;

}



echo json_encode(['ok' => true, 'deleted' => $deleted, 'message' => 'تم حذف السند.'], JSON_UNESCAPED_UNICODE);

