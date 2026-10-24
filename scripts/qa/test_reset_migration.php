<?php
declare(strict_types=1);
require __DIR__.'/isolation.php';
require_once __DIR__.'/../../app/src/Support/config.php';
$name=(string)getenv('LIGHT_QA_FRESH_DB');
if(!preg_match('/\Aqa_light_fresh_[a-f0-9]{8}\z/',$name))throw new RuntimeException('Isolated migration target required.');
$db=qaConnection();$db->exec('CREATE DATABASE '.$name);$db->exec('USE '.$name);
$sql=file_get_contents(__DIR__.'/../../database/migrations/018_password_reset_contract.sql');
try{
 $db->exec('CREATE TABLE password_resets(id INT PRIMARY KEY,used SMALLINT NOT NULL DEFAULT 0,expires_at DATETIME)');
 $db->exec("INSERT INTO password_resets VALUES(1,1,'2026-01-01'),(2,0,'2026-01-02')");
 $db->exec($sql);$db->exec($sql);
 if((int)$db->query('SELECT COUNT(*) FROM password_resets WHERE (id=1 AND used_at IS NOT NULL) OR (id=2 AND used_at IS NULL)')->fetchColumn()!==2)throw new RuntimeException('Consumed reset token preservation failed.');
 $db->exec('DROP TABLE password_resets');
 $db->exec('CREATE TABLE password_resets(id INT PRIMARY KEY,expires_at DATETIME,used_at DATETIME NULL)');
 $db->exec("INSERT INTO password_resets VALUES(7,'2026-01-02','2026-01-01')");
 $db->exec($sql);$db->exec($sql);
 if($db->query('SELECT DATE(used_at) FROM password_resets WHERE id=7')->fetchColumn()!=='2026-01-01')throw new RuntimeException('Legacy reset timestamp changed.');
 echo "PASS boolean-used and timestamp-used schemas, consumed-token preservation, and repeat application.\n";
}finally{$db->exec('DROP DATABASE '.$name);}
