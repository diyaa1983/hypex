<?php
/**
 * يصدّر SQL لتفريغ ثم إعادة بناء المناطق/العناوين وربط المندوبين على السيرفر.
 * الاستخدام: php tools/export_regions_for_server.php
 * يكتب مباشرة إلى items/link-regions-on-server.sql (بدون تحذيرات PHP في الملف).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pdo = db();
$outFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'items' . DIRECTORY_SEPARATOR . 'link-regions-on-server.sql';
ob_start();

function sql_str(?string $s): string
{
    if ($s === null) {
        return 'NULL';
    }

    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $s) . "'";
}

$regions = $pdo->query(
    'SELECT id, code, name_ar, sort_order, is_active FROM crm_region ORDER BY sort_order, id'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$addresses = $pdo->query(
    'SELECT a.id, a.region_id, r.name_ar AS region_name, a.name_ar, a.sort_order, a.is_active
     FROM crm_region_address a
     INNER JOIN crm_region r ON r.id = a.region_id
     ORDER BY a.region_id, a.sort_order, a.id'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$links = $pdo->query(
    'SELECT sr.name_ar AS rep_name, r.name_ar AS region_name, a.name_ar AS address_name, x.sort_order
     FROM crm_sales_rep_region_address x
     INNER JOIN crm_sales_rep sr ON sr.id = x.sales_rep_id
     INNER JOIN crm_region_address a ON a.id = x.region_address_id
     INNER JOIN crm_region r ON r.id = a.region_id
     ORDER BY sr.name_ar, r.name_ar, a.name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$regionLinks = $pdo->query(
    'SELECT sr.name_ar AS rep_name, r.name_ar AS region_name, x.sort_order
     FROM crm_sales_rep_region x
     INNER JOIN crm_sales_rep sr ON sr.id = x.sales_rep_id
     INNER JOIN crm_region r ON r.id = x.region_id
     ORDER BY sr.name_ar, r.name_ar'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo "-- نفّذ على السيرفر فقط (176.29.176.192) عبر Workbench.\n";
echo "-- مصدر: استيراد المناطق.xlsx محلياً ثم تصدير هذا الملف.\n";
echo "USE hypex;\n";
echo "SET SQL_SAFE_UPDATES = 0;\n";
echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

echo "-- 1) فك الربط ثم حذف المناطق/العناوين\n";
echo "UPDATE crm_customer SET region_address_id = NULL, region_id = NULL\n";
echo " WHERE region_id IS NOT NULL OR region_address_id IS NOT NULL;\n";
echo "DELETE FROM crm_sales_rep_region_address;\n";
echo "DELETE FROM crm_sales_rep_region;\n";
echo "DELETE FROM crm_region_address;\n";
echo "DELETE FROM crm_region;\n\n";

echo "SET FOREIGN_KEY_CHECKS = 1;\n\n";

echo "-- 2) المناطق\n";
foreach ($regions as $r) {
    echo 'INSERT INTO crm_region (code, name_ar, sort_order, is_active) VALUES ('
        . sql_str((string) $r['code']) . ', '
        . sql_str((string) $r['name_ar']) . ', '
        . (int) $r['sort_order'] . ', '
        . (int) $r['is_active']
        . ");\n";
}
echo "\n";

echo "-- 3) العناوين\n";
foreach ($addresses as $a) {
    echo 'INSERT INTO crm_region_address (region_id, name_ar, sort_order, is_active)' . "\n"
        . 'SELECT r.id, ' . sql_str((string) $a['name_ar']) . ', ' . (int) $a['sort_order'] . ', ' . (int) $a['is_active'] . "\n"
        . 'FROM crm_region r' . "\n"
        . 'WHERE r.name_ar = ' . sql_str((string) $a['region_name']) . "\n"
        . "LIMIT 1;\n";
}
echo "\n";

echo "-- 4) ربط المندوب بالعنوان (بالاسم)\n";
foreach ($links as $x) {
    echo 'INSERT IGNORE INTO crm_sales_rep_region_address (sales_rep_id, region_address_id, sort_order)' . "\n"
        . 'SELECT sr.id, a.id, ' . (int) $x['sort_order'] . "\n"
        . 'FROM crm_sales_rep sr' . "\n"
        . 'INNER JOIN crm_region r ON r.name_ar = ' . sql_str((string) $x['region_name']) . "\n"
        . 'INNER JOIN crm_region_address a ON a.region_id = r.id AND a.name_ar = ' . sql_str((string) $x['address_name']) . "\n"
        . 'WHERE sr.name_ar = ' . sql_str((string) $x['rep_name']) . "\n"
        . "LIMIT 1;\n";
}
echo "\n";

echo "-- 5) ربط المندوب بالمنطقة (احتياطي)\n";
foreach ($regionLinks as $x) {
    echo 'INSERT IGNORE INTO crm_sales_rep_region (sales_rep_id, region_id, sort_order)' . "\n"
        . 'SELECT sr.id, r.id, ' . (int) $x['sort_order'] . "\n"
        . 'FROM crm_sales_rep sr' . "\n"
        . 'INNER JOIN crm_region r ON r.name_ar = ' . sql_str((string) $x['region_name']) . "\n"
        . 'WHERE sr.name_ar = ' . sql_str((string) $x['rep_name']) . "\n"
        . "LIMIT 1;\n";
}

echo "\nSET SQL_SAFE_UPDATES = 1;\n";
echo "-- تحقق:\n";
echo "SELECT COUNT(*) AS regions FROM crm_region;\n";
echo "SELECT COUNT(*) AS addresses FROM crm_region_address;\n";
echo "SELECT COUNT(*) AS rep_addr FROM crm_sales_rep_region_address;\n";

$sql = (string) ob_get_clean();
// أزل أي BOM/مسافات قبل أول تعليق SQL
$sql = preg_replace('/^\xEF\xBB\xBF/u', '', $sql) ?? $sql;
$sql = ltrim($sql);
if ($sql === '' || $sql[0] !== '-') {
    fwrite(STDERR, "Export failed: unexpected SQL start\n");
    exit(1);
}
if (file_put_contents($outFile, $sql) === false) {
    fwrite(STDERR, "Cannot write: $outFile\n");
    exit(1);
}
fwrite(STDERR, "Wrote clean SQL: $outFile (" . strlen($sql) . " bytes)\n");
