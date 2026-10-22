<?php
$t = microtime(true);
try {
  $pdo = new PDO('mysql:host=database-saya;dbname=sim_dokim;charset=utf8mb4', 'root', 'dede', [PDO::ATTR_TIMEOUT => 5]);
  echo 'connect: ' . round(microtime(true) - $t, 3) . "s\n";
} catch (PDOException $e) {
  echo 'connect GAGAL: ' . $e->getMessage() . "\n";
  exit;
}
$t = microtime(true);
$r = $pdo->query('SELECT COUNT(*) FROM dokumen')->fetchColumn();
echo 'query: ' . round(microtime(true) - $t, 3) . "s, hasil=$r\n";
