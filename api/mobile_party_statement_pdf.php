<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/crm_party_statement.php');
require_once app_path('includes/mobile_party.php');
require_once app_path('includes/mobile_party_statement_print.php');
require_once app_path('includes/mobile_dompdf.php');

if (!is_logged_in() || !mobile_can_access_party_statement_api()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'forbidden';
    exit;
}

$partyType = trim((string) ($_GET['party_type'] ?? ''));
if ($partyType !== 'supplier' && $partyType !== 'customer') {
    $partyType = 'customer';
}
$partyId = (int) ($_GET['party_id'] ?? 0);
$fromRaw = trim((string) ($_GET['from'] ?? ''));
$toRaw = trim((string) ($_GET['to'] ?? ''));

if ($partyId < 1) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'party_required';
    exit;
}

$fromIso = parse_date_to_iso($fromRaw);
$toIso = parse_date_to_iso($toRaw);
if ($fromIso === null || $toIso === null) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'invalid_dates';
    exit;
}
if ($fromIso > $toIso) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'date_range';
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
    header('Content-Type: text/plain; charset=utf-8');
    echo 'not_found';
    exit;
}

$partyName = (string) ($party['name_ar'] ?? '');
$partyCode = (string) ($party['code'] ?? '');
$built = crm_party_statement_build($pdo, $partyType, $partyId, $fromIso, $toIso);
$rows = $built['rows'] ?? [];
$doc = mobile_party_statement_print_document($pdo, $partyType, $partyId, $partyName, $partyCode, $fromIso, $toIso, $rows, $built);

$html = (string) ($doc['html'] ?? $doc['html_pdf'] ?? '');
if ($html === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'no_html';
    exit;
}

$typeLabel = $partyType === 'supplier' ? 'مورد' : 'عميل';
$fname = 'كشف حساب ' . $typeLabel . ' - ' . ($partyName !== '' ? $partyName : 'report') . '.pdf';

mobile_dompdf_stream_pdf($html, $fname);
