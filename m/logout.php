<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once app_path('includes/mobile_auth.php');

mobile_logout();
redirect(mobile_login_url());
