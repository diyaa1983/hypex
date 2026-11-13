<?php
declare(strict_types=1);

/**
 * خريطة باركود/SKU → فئة من كشف الاكسل (احتياط للتقرير).
 *
 * @return array{by_key: array<string,string>, excel_names: array<string,true>}
 */
function excel_item_categories_maps(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $rows = [
        ['400021', '6253500765090', 'Others'],
        ['400024', '6253500765076', 'Others'],
        ['400022', '6253500765083', 'Others'],
        ['400020', '6253500765113', 'Others'],
        ['200001', '6253500760019', 'Bleach'],
        ['200003', '6253500760200', 'Bleach'],
        ['200002', '6253500760194', 'Bleach'],
        ['200004', '6253500760026', 'Bleach'],
        ['200006', '6253500760231', 'Bleach'],
        ['200005', '6253500760255', 'Bleach'],
        ['200007', '6253500760033', 'Bleach'],
        ['200009', '6253500760248', 'Bleach'],
        ['200008', '6253500760224', 'Bleach'],
        ['200018', '6253500760712', 'Bleach'],
        ['200011', '6253500760705', 'Bleach'],
        ['130080', '6253500761399', 'Bleach'],
        ['130151', '6253500765571', 'Bleach'],
        ['130150', '6253500765564', 'Bleach'],
        ['130082', '6253500763041', 'Bleach'],
        ['130155', '6253500765540', 'Bleach'],
        ['130154', '6253500765557', 'Bleach'],
        ['500003', '6253500760095', 'Others'],
        ['300002', '6253500760064', 'Dishwashing'],
        ['300005', '6253500761313', 'Dishwashing'],
        ['300004', '6253500760187', 'Dishwashing'],
        ['300010', '6253500765489', 'Dishwashing'],
        ['300001', '6253500760057', 'Dishwashing'],
        ['300021', '6253500761511', 'Dishwashing'],
        ['300008', '6253500761498', 'Dishwashing'],
        ['300007', '6253500761467', 'Dishwashing'],
        ['300012', '6253500761504', 'Dishwashing'],
        ['300009', '6253500761474', 'Dishwashing'],
        ['300006', '6253500761450', 'Dishwashing'],
        ['300011', '6253500761481', 'Dishwashing'],
        ['300024', '6253500763607', 'Dishwashing'],
        ['500001', '6253500760132', 'Others'],
        ['600045', '6253500762679', 'Deterganet'],
        ['600043', '6253500762662', 'Deterganet'],
        ['600044', '6253500762655', 'Deterganet'],
        ['600031', '6253500762648', 'Deterganet'],
        ['600032', '6253500761429', 'Deterganet'],
        ['600033', '6253500762914', 'Deterganet'],
        ['600019', '6253500760736', 'Others'],
        ['600023', '6253500763034', 'Others'],
        ['600020', '6253500761184', 'Others'],
        ['600029', '6253500761191', 'Others'],
        ['600030', '6253500763195', 'Others'],
        ['600025', '6253500761221', 'Others'],
        ['600018', '6253500760156', 'Others'],
        ['600040', '6253011995245', 'Others'],
        ['600132', '6253500764949', 'Others'],
        ['600130', '6253500764963', 'Others'],
        ['600134', '6253500764956', 'Others'],
        ['600131', '6253500764932', 'Others'],
        ['600133', '6253500764925', 'Others'],
        ['600140', '6253500765052', 'Others'],
        ['600140', '6253500765038', 'Others'],
        ['600140', '6253500765069', 'Others'],
        ['600140', '6253500765045', 'Others'],
        ['600065', '6253500765021', 'Others'],
        ['600065', '6253500763256', 'Others'],
        ['600073', '6253500763270', 'Others'],
        ['600064', '6253500763249', 'Others'],
        ['600069', '6253500763300', 'Others'],
        ['600067', '6253500763294', 'Others'],
        ['600068', '6253500763324', 'Others'],
        ['600072', '6253500763263', 'Others'],
        ['600070', '6253500763317', 'Others'],
        ['600071', '6253500763331', 'Others'],
        ['600066', '6253500763287', 'Others'],
        ['400001', '6253500760361', 'Others'],
        ['400002', '6253500760279', 'Others'],
        ['600060', '6253500761320', 'Hand Wash'],
        ['600059', '6253500763010', 'Hand Wash'],
        ['600057', '6253500761306', 'Hand Wash'],
        ['600015', '6253500760910', 'Hand Wash'],
        ['600014', '6253500760903', 'Hand Wash'],
        ['600058', '6253500761337', 'Hand Wash'],
        ['600061', '6253011995474', 'Hand Wash'],
        ['600009', '6253011995436', 'Hand Wash'],
        ['600056', '6253011995450', 'Hand Wash'],
        ['600011', '6253011995467', 'Hand Wash'],
        ['600092', '6253500763683', 'Hand Wash'],
        ['600089', '6253500763454', 'Hand Wash'],
        ['600088', '6253500763652', 'Hand Wash'],
        ['600037', '6253011995528', 'Hand Wash'],
        ['600110', '6253500764383', 'Deterganet'],
        ['600110', '6253500761696', 'Deterganet'],
        ['400024', '6253500670125', 'Others'],
        ['400022', '6253500760408', 'Others'],
        ['200028', '6253500760323', 'Others'],
        ['400003', '6253500760378', 'Others'],
        ['400020', '621102391127', 'Deterganet'],
        ['600034', '6253500761412', 'Deterganet'],
        ['600129', '6211023913527', 'Deterganet'],
        ['600136', '6253500765533', 'Deterganet'],
        ['600127', '6253500765175', 'Deterganet'],
        ['200027', '6253500765519', 'Deterganet'],
        ['200028', '6253500765526', 'Deterganet'],
        ['400025', '6253500765106', 'Others'],
    ];
    $byKey = [];
    foreach ($rows as $r) {
        $sku = trim((string) $r[0]);
        $barcode = trim((string) $r[1]);
        $cat = trim((string) $r[2]);
        if ($sku !== '') {
            $byKey[$sku] = $cat;
        }
        if ($barcode !== '') {
            $byKey[$barcode] = $cat;
        }
    }
    $excelNames = [
        'others' => true,
        'bleach' => true,
        'dishwashing' => true,
        'deterganet' => true,
        'hand wash' => true,
    ];
    $cache = ['by_key' => $byKey, 'excel_names' => $excelNames];

    return $cache;
}

function excel_item_is_excel_category(string $name): bool
{
    $maps = excel_item_categories_maps();

    return isset($maps['excel_names'][strtolower(trim($name))]);
}

function excel_item_lookup_category(string ...$keys): string
{
    $maps = excel_item_categories_maps();
    $byKey = $maps['by_key'];
    foreach ($keys as $raw) {
        $k = trim((string) $raw);
        if ($k === '') {
            continue;
        }
        if (isset($byKey[$k])) {
            return $byKey[$k];
        }
        $digits = preg_replace('/\D+/', '', $k) ?? '';
        if ($digits !== '' && isset($byKey[$digits])) {
            return $byKey[$digits];
        }
        $norm = ltrim($digits, '0');
        if ($norm === '') {
            $norm = $digits;
        }
        if ($norm !== '' && isset($byKey[$norm])) {
            return $byKey[$norm];
        }
    }

    return '';
}
