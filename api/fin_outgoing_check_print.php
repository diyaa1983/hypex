<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/fin_outgoing_check_print.php';

if (!is_logged_in() || !user_can('fin_outgoing_checks')) {
    http_response_code(403);
    if (isset($_GET['embed']) && (string) $_GET['embed'] === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'غير مصرح.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    exit('Forbidden');
}

$checkId = (int) ($_GET['id'] ?? 0);
$embed = isset($_GET['embed']) && (string) $_GET['embed'] === '1';

if ($checkId < 1) {
    if ($embed) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'معرّف الشيك مطلوب.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(400);
    exit('معرّف الشيك مطلوب.');
}

$pdo = db();
fin_outgoing_check_register_ensure_schema($pdo);
$built = fin_outgoing_check_print_single_build($pdo, $checkId);

if ($embed) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'html' => $built['html'],
        'title' => $built['title'],
        'css_url' => fin_outgoing_check_print_stylesheet_url(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

fin_outgoing_check_print_emit_page(
    $built['title'],
    $built['html'],
    !isset($_GET['preview'])
);
