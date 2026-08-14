<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/crm_party_statement.php');
require_once app_path('includes/mobile_party.php');
require_once app_path('includes/mobile_party_statement_print.php');

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !mobile_can_access_party_statement_api()) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'forbidden',
        'message' => 'لا توجد صلاحية لعرض كشف الحساب.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$withPrint = isset($_GET['print']) && (string) $_GET['print'] === '1';

$partyType = trim((string) ($_GET['party_type'] ?? ''));
if ($partyType !== 'supplier' && $partyType !== 'customer') {
    $partyType = 'customer';
}
$partyId = (int) ($_GET['party_id'] ?? 0);
$fromRaw = trim((string) ($_GET['from'] ?? ''));
$toRaw = trim((string) ($_GET['to'] ?? ''));

if ($partyId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'party_required', 'message' => 'اختر العميل أو المورد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fromIso = parse_date_to_iso($fromRaw);
$toIso = parse_date_to_iso($toRaw);
if ($fromIso === null || $toIso === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_dates', 'message' => 'التواريخ غير صالحة.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($fromIso > $toIso) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'date_range', 'message' => 'تاريخ البداية يجب أن يكون قبل النهاية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
crm_ledger_ensure_schema($pdo);
crm_supplier_ledger_ensure_schema($pdo);

if ($partyType === 'supplier') {
    $st = $pdo->prepare('SELECT name_ar, code FROM crm_supplier WHERE id = ? LIMIT 1');
} else {
    $st = $pdo->prepare('SELECT name_ar, code FROM crm_customer WHERE id = ? LIMIT 1');
}
$st->execute([$partyId]);
$party = $st->fetch(PDO::FETCH_ASSOC);
if (!$party) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($partyType === 'customer') {
    require_once app_path('includes/crm_sales_rep_schema.php');
    $scopedRepId = crm_mobile_scoped_sales_rep_id($pdo);
    if ($scopedRepId !== null && !crm_customer_is_linked_to_sales_rep($pdo, $partyId, $scopedRepId)) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'forbidden',
            'message' => 'هذا العميل غير مربوط بمندوبك.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$partyName = (string) ($party['name_ar'] ?? '');
$partyCode = (string) ($party['code'] ?? '');
$salesRepNames = '';
if ($partyType === 'customer') {
    require_once app_path('includes/crm_sales_rep_schema.php');
    $salesRepNames = crm_customer_sales_rep_names($pdo, $partyId);
}

try {
    // رصيد العميل كامل دائماً — بدون فلترة حركات حسب المندوب
    $built = crm_party_statement_build($pdo, $partyType, $partyId, $fromIso, $toIso);
    $rows = $built['rows'] ?? [];

    $pdfQuery = http_build_query([
        'party_type' => $partyType,
        'party_id' => $partyId,
        'from' => format_date_dmY($fromIso),
        'to' => format_date_dmY($toIso),
    ], '', '&', PHP_QUERY_RFC3986);

    $brand = [];
    try {
        require_once app_path('includes/document_header.php');
        $brand = document_header_brand($pdo);
    } catch (Throwable $e) {
        $brand = [];
    }

    $payload = [
        'ok' => true,
        'company_name' => (string) ($brand['company_name_ar'] ?? 'الشركة'),
        'logo_url' => $brand['logo_url'] ?? null,
        'party_type' => $partyType,
        'party_id' => $partyId,
        'party_name' => $partyName,
        'party_code' => $partyCode,
        'sales_rep_name' => $salesRepNames,
        'sales_rep_names' => $salesRepNames,
        'from' => $fromIso,
        'to' => $toIso,
        'from_dmy' => format_date_dmY($fromIso),
        'to_dmy' => format_date_dmY($toIso),
        'opening_balance' => (float) ($built['opening_balance'] ?? 0),
        'opening_debit' => (float) ($built['opening_debit'] ?? 0),
        'opening_credit' => (float) ($built['opening_credit'] ?? 0),
        'total_debit' => (float) ($built['total_debit'] ?? 0),
        'total_credit' => (float) ($built['total_credit'] ?? 0),
        'closing_balance' => (float) ($built['closing_balance'] ?? 0),
        'rows' => $rows,
        'mobile_pdf' => true,
        'pdf_download_url' => app_url('api/mobile_party_statement_pdf.php?' . $pdfQuery),
    ];

    if ($withPrint) {
        $doc = mobile_party_statement_print_document(
            $pdo,
            $partyType,
            $partyId,
            $partyName,
            $partyCode,
            $fromIso,
            $toIso,
            $rows,
            $built,
            $salesRepNames
        );
        $payload['html'] = $doc['html'];
        $payload['styles'] = $doc['styles'];
        $payload['styles_pdf'] = $doc['styles_pdf'];
        $payload['inner'] = $doc['inner'];
        $payload['inner_pdf'] = $doc['inner_pdf'];
        $payload['html_pdf'] = $doc['html_pdf'];
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException('json_encode: ' . json_last_error_msg());
    }
    echo $json;
} catch (Throwable $e) {
    error_log('mobile_party_statement: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'حدث خطأ أثناء إعداد كشف الحساب.',
    ], JSON_UNESCAPED_UNICODE);
}
