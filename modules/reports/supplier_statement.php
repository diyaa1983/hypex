<?php
declare(strict_types=1);

if (!isset($_GET['party_type'])) {
    $_GET['party_type'] = 'supplier';
}
require __DIR__ . '/party_statement.php';
