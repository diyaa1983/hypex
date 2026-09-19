<?php
declare(strict_types=1);

require_once app_path('includes/nav_helpers.php');

/** تحميل JS/CSS لوضع العرض من كشف حركات مادة. */
function ledger_document_view_enqueue_assets(): void
{
    if (!nav_is_ledger_view_request()) {
        return;
    }

    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $jsPath = app_path('assets/js/ledger-document-view.js');
    $jsUrl = app_url('assets/js/ledger-document-view.js')
        . (is_file($jsPath) ? '?v=' . (string) filemtime($jsPath) : '');
    echo '<script src="' . esc($jsUrl) . '" defer></script>' . "\n";
}
