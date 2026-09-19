<?php
declare(strict_types=1);

require_once app_path('includes/mobile_auth.php');

/** كشف حساب من الهاتف أو سطح المكتب. */
function mobile_can_access_party_statement_api(): bool
{
    return user_can('report_party_statement') || user_can('m_party_statement');
}
