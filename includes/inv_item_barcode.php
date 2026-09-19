<?php
declare(strict_types=1);

const INV_ITEM_BARCODE_LEN = 6;
const INV_ITEM_BARCODE_MAX_LEN = 14;

/** هل عمود barcode موجود في inv_item؟ */
function inv_item_has_barcode_column(PDO $pdo, bool $refresh = false): bool
{
    static $cached = null;
    if ($refresh) {
        $cached = null;
    }
    if ($cached !== null) {
        return $cached;
    }
    try {
        $pdo->query('SELECT barcode FROM inv_item LIMIT 1');
        $cached = true;
    } catch (Throwable $e) {
        $cached = false;
    }

    return $cached;
}

/**
 * إنشاء عمود barcode تلقائيًا إن لم يكن موجودًا (بديل عن تنفيذ SQL يدويًا).
 */
function inv_item_ensure_barcode_schema(PDO $pdo): bool
{
    if (inv_item_has_barcode_column($pdo)) {
        return true;
    }

    try {
        $pdo->exec('ALTER TABLE inv_item ADD COLUMN barcode VARCHAR(' . INV_ITEM_BARCODE_MAX_LEN . ') NULL AFTER sku');
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            return false;
        }
    }

    try {
        $pdo->exec(
            'UPDATE inv_item SET barcode = LPAD(id, ' . INV_ITEM_BARCODE_LEN . ", '0')
             WHERE barcode IS NULL OR TRIM(barcode) = ''"
        );
        $pdo->exec('ALTER TABLE inv_item MODIFY barcode VARCHAR(' . INV_ITEM_BARCODE_MAX_LEN . ') NOT NULL');
    } catch (Throwable $e) {
        return false;
    }

    try {
        $pdo->exec('ALTER TABLE inv_item ADD UNIQUE KEY uq_inv_item_barcode (barcode)');
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') === false
            && strpos($e->getMessage(), 'Duplicate entry') === false) {
            // قد يكون الفهرس موجودًا مسبقًا
        }
    }

    return inv_item_has_barcode_column($pdo, true);
}

/** أعمدة SELECT للقائمة حسب توفر barcode. */
function inv_item_list_columns(PDO $pdo): string
{
    if (inv_item_has_barcode_column($pdo)) {
        return 'id, sku, barcode, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active';
    }

    return 'id, sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active';
}

/** استخراج الأرقام فقط من Barcode. */
function inv_item_barcode_digits_only(string $value): string
{
    return preg_replace('/\D+/', '', trim($value)) ?? '';
}

/** التحقق من صيغة Barcode (أرقام فقط، حتى 14 رقمًا). */
function inv_item_barcode_validate_format(string $barcode): void
{
    if ($barcode === '') {
        return;
    }
    $max = INV_ITEM_BARCODE_MAX_LEN;
    if (!preg_match('/^\d{1,' . $max . '}$/', $barcode)) {
        throw new RuntimeException('Barcode أرقام فقط، بحد أقصى ' . $max . ' رقمًا.');
    }
}

/** هل Barcode مستخدم لمادة أخرى؟ */
function inv_item_barcode_exists(PDO $pdo, string $barcode, int $excludeId = 0): bool
{
    if (!inv_item_has_barcode_column($pdo)) {
        return false;
    }
    $barcode = inv_item_barcode_digits_only($barcode);
    if ($barcode === '') {
        return false;
    }
    if ($excludeId > 0) {
        $st = $pdo->prepare('SELECT id FROM inv_item WHERE barcode = ? AND id <> ? LIMIT 1');
        $st->execute([$barcode, $excludeId]);
    } else {
        $st = $pdo->prepare('SELECT id FROM inv_item WHERE barcode = ? LIMIT 1');
        $st->execute([$barcode]);
    }

    return (bool) $st->fetch();
}

/** توليد Barcode فريد (أرقام فقط، 6 أرقام افتراضيًا). */
function inv_item_generate_barcode(PDO $pdo, int $excludeId = 0): string
{
    $maxId = (int) $pdo->query('SELECT IFNULL(MAX(id), 0) FROM inv_item')->fetchColumn();
    $mod = 10 ** INV_ITEM_BARCODE_LEN;

    for ($i = 0; $i < 200; $i++) {
        $num = ($maxId + 1 + $i) % $mod;
        if ($num === 0) {
            $num = 1;
        }
        $candidate = str_pad((string) $num, INV_ITEM_BARCODE_LEN, '0', STR_PAD_LEFT);
        if (!inv_item_barcode_exists($pdo, $candidate, $excludeId)) {
            return $candidate;
        }
    }

    for ($j = 0; $j < 50; $j++) {
        $candidate = '';
        for ($k = 0; $k < INV_ITEM_BARCODE_LEN; $k++) {
            $candidate .= (string) random_int(0, 9);
        }
        if (!inv_item_barcode_exists($pdo, $candidate, $excludeId)) {
            return $candidate;
        }
    }

    return str_pad((string) (time() % $mod), INV_ITEM_BARCODE_LEN, '0', STR_PAD_LEFT);
}

/** تطبيع Barcode أو توليده إن كان فارغًا. */
function inv_item_resolve_barcode(PDO $pdo, string $input, int $itemId = 0): string
{
    $raw = trim($input);
    if ($raw === '') {
        return inv_item_generate_barcode($pdo, $itemId);
    }

    $barcode = inv_item_barcode_digits_only($raw);
    if ($barcode === '' || $barcode !== $raw) {
        throw new RuntimeException('Barcode يجب أن يحتوي على أرقام فقط.');
    }

    inv_item_barcode_validate_format($barcode);

    if (inv_item_barcode_exists($pdo, $barcode, $itemId)) {
        throw new RuntimeException('Barcode مستخدم لمادة أخرى.');
    }

    return $barcode;
}
