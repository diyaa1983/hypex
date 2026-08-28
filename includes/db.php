<?php
declare(strict_types=1);

/** @return PDO */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = require app_path('config/database.php');
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['name'],
        $cfg['charset']
    );

    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        $opts[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
    }

    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $opts);

    require_once app_path('includes/date_defaults.php');
    app_mysql_apply_timezone($pdo);

    return $pdo;
}
