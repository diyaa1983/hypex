<?php
require __DIR__ . "/includes/bootstrap.php";
$pdo = db();
// Try common remote patterns - just show connection attributes
echo "ATTR_CONNECTION_STATUS: ";
try { echo $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS), PHP_EOL; } catch (Throwable $e) { echo $e->getMessage(), PHP_EOL; }
echo "SERVER: ";
try { echo $pdo->query("SELECT @@hostname, @@port, DATABASE()")->fetch(PDO::FETCH_NUM)[0] ?? "", " ", $pdo->query("SELECT @@port")->fetchColumn(), " ", $pdo->query("SELECT DATABASE()")->fetchColumn(), PHP_EOL; } catch (Throwable $e) { echo $e->getMessage(), PHP_EOL; }
$flags = $pdo->query("SELECT flag_key, applied_at FROM sys_schema_flag WHERE flag_key LIKE 'cust_retail%'")->fetchAll(PDO::FETCH_ASSOC);
echo "flags:\n";
print_r($flags);
