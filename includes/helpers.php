<?php
declare(strict_types=1);

function esc(string|int|float|null $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function verify_csrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['_csrf'])
        && hash_equals($_SESSION['_csrf'], $token);
}

function request_wants_json_invoice_save(): bool
{
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (str_contains($accept, 'application/json')) {
        return true;
    }

    return isset($_SERVER['HTTP_X_INVOICE_SAVE']) && (string) $_SERVER['HTTP_X_INVOICE_SAVE'] === '1';
}

/** @param array<string, mixed> $payload */
function json_invoice_save_response(bool $ok, array $payload = [], int $httpStatus = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($httpStatus);
    echo json_encode(array_merge(['ok' => $ok], $payload), JSON_UNESCAPED_UNICODE);
    exit;
}

function format_amount(float|string $n, ?int $decimals = null, bool $groupThousands = true): string
{
    if (!function_exists('company_decimal_places')) {
        require_once app_path('includes/company_settings.php');
    }
    $dp = $decimals ?? company_decimal_places();

    return number_format((float) $n, $dp, '.', $groupThousands ? ',' : '');
}

function format_money(float|string $n, ?int $decimals = null): string
{
    return format_amount($n, $decimals, true);
}

/** تاريخ ISO (Y-m-d) → عرض يوم-شهر-سنة (d-m-Y). */
function format_date_dmY(string $isoDate): string
{
    $isoDate = trim($isoDate);
    if ($isoDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $isoDate)) {
        return $isoDate;
    }

    $ts = strtotime($isoDate);

    return $ts !== false ? date('d-m-Y', $ts) : $isoDate;
}

/** قبول Y-m-d أو d-m-Y → Y-m-d صالح أو null. */
function parse_date_to_iso(string $date): ?string
{
    $date = trim($date);
    if ($date === '') {
        return null;
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $date, $m)) {
        $d = (int) $m[1];
        $mo = (int) $m[2];
        $y = (int) $m[3];

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    return null;
}

function flash_set(string $type, string $message): void
{
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

/** @return array{type:string,message:string}|null */
function flash_get(): ?array
{
    if (empty($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
        return null;
    }
    $f = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    if (!isset($f['type'], $f['message'])) {
        return null;
    }
    return ['type' => (string) $f['type'], 'message' => (string) $f['message']];
}
