<?php
declare(strict_types=1);

require_once app_path('includes/nav_helpers.php');

$ledgerBack = nav_item_stock_ledger_back_link();
if ($ledgerBack === null) {
    return;
}
?>
<a class="btn btn-secondary btn-sm ledger-back-btn no-print" href="<?= esc($ledgerBack['url']) ?>"
   title="العودة إلى كشف حركات مادة">← <?= esc($ledgerBack['label']) ?></a>
