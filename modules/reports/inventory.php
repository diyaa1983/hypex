<?php
declare(strict_types=1);

/** إعادة توجيه الرابط القديم (تقرير) إلى شاشة كشف حركات مادة. */
$query = $_GET;
$query['r'] = 'item_stock_movements';
if (!isset($query['run']) && isset($query['item_id'], $query['warehouse_id'])) {
    $query['run'] = '1';
}
redirect(app_url('index.php?' . http_build_query($query)));
