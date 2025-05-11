<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/rentatool/includes/db_connection.php';

$stmt = $pdo->query("SELECT * FROM Review");
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<pre>";
print_r($reviews);
echo "</pre>";
?>
