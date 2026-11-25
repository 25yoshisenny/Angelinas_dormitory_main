<?php
// Restore the provided SQL snapshot into a disposable database to prove it imports.
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__).'/app/config.php';
$test='angelinas_test_'.bin2hex(random_bytes(6)); $pdo=mysql_connection(false);
try {
    $pdo->exec('CREATE DATABASE `'.$test.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `'.$test.'`');
    $pdo->exec(file_get_contents(dirname(__DIR__).'/database/angelinas_dormitory.sql'));
    $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if(count($tables)!==23) throw new RuntimeException('Unexpected exported table count.');
    if((int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','staff')")->fetchColumn()===0) throw new RuntimeException('Export is missing administrator and staff accounts.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=?');$q->execute([$test]);
    if((int)$q->fetchColumn()<20) throw new RuntimeException('Missing foreign keys in restored database.');
    echo 'PASS: SQL snapshot restores all 23 MySQL tables, administrator/staff accounts, and foreign keys.'.PHP_EOL;
} finally { $pdo->exec('DROP DATABASE IF EXISTS `'.$test.'`'); }
