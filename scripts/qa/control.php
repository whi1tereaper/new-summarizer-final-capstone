<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../../app/src/Support/config.php';
require_once __DIR__.'/../../app/src/Database.php';
$action=$argv[1]??'';$folder=realpath($argv[2]??'');
if(!$folder||str_contains(strtolower(str_replace('\\','/',$folder)),'/htdocs/'))throw new RuntimeException('Private run folder outside Apache required.');
$prod=\App\Src\Database::getConfiguredMySqlConnection();
function snapshot(PDO $db):array{
 $result=[];foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
  $rows=$db->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
  $hashes=array_map(fn($r)=>hash('sha256',json_encode($r,JSON_INVALID_UTF8_SUBSTITUTE)),$rows);sort($hashes);
  $result[$table]=['rows'=>count($rows),'sha256'=>hash('sha256',implode('',$hashes))];
 }ksort($result);return $result;
}
if($action==='setup'||$action==='setup-benchmark'){
 $token=bin2hex(random_bytes(4));$name='qa_light_'.$token;$fresh='qa_light_fresh_'.$token;$restore='qa_light_restore_'.$token;$password=bin2hex(random_bytes(24));
 $server=new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4',config('database.username'),config('database.password'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 foreach([$name,$restore] as $target)$server->exec('CREATE DATABASE `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
 $server->exec("CREATE USER '$name'@'127.0.0.1' IDENTIFIED BY '$password'");
 foreach([$name,$fresh] as $target)$server->exec("GRANT ALL PRIVILEGES ON `$target`.* TO '$name'@'127.0.0.1'");
 $env=['DB_HOST'=>'127.0.0.1','DB_PORT'=>'3307','DB_NAME'=>$name,'DB_USERNAME'=>$name,'DB_PASSWORD'=>$password,'DB_DRIVER'=>'mysql','LIGHT_QA_FRESH_DB'=>$fresh,
 'STUDENT_PRIVACY_CONTACT'=>'qa@example.invalid','STUDENT_NUMBER_SECRET'=>base64_encode(random_bytes(32)),'STUDENT_ENROLLMENT_AUTHORIZED'=>'true','SESSION_NAME'=>'LIGHT_QA_SESSID','APP_ENV'=>'testing','APP_DEBUG'=>'false',
 'APP_URL'=>'http://127.0.0.1:58139','PYTHON_WORKER_PORT'=>'58149','PYTHON_WORKER_SECRET'=>bin2hex(random_bytes(32)),'SMTP_HOST'=>'127.0.0.1','SMTP_PORT'=>'58125','SMTP_USER'=>'capture','SMTP_PASS'=>'capture','SMTP_FROM'=>'qa@example.invalid',
 'SMTP_AUTH'=>'false','SMTP_ENCRYPTION'=>'none','STORAGE_ROOT'=>$folder.'/storage','STORAGE_UPLOADS'=>$folder.'/storage/uploads','STORAGE_AUDIO'=>$folder.'/storage/audio','STORAGE_LOGS'=>$folder.'/storage/logs','STORAGE_TMP'=>$folder.'/storage/tmp',
 'AUDIO_OUTPUT_DIR'=>$folder.'/storage/audio','TTS_OUTPUT_DIR'=>$folder.'/storage/audio','PIPER_CACHE_DIR'=>$folder.'/storage/audio'];
 file_put_contents($folder.'/config.json',json_encode(['env'=>$env,'restore'=>$restore,'before'=>snapshot($prod)],JSON_PRETTY_PRINT));chmod($folder.'/config.json',0600);
 if($action!=='setup-benchmark'){
 $defaults=$folder.'/mysql.cnf';
 $quote=fn($v)=>'"'.addcslashes((string)$v,"\\\"").'"';
 file_put_contents($defaults,"[client]\nhost=127.0.0.1\nport=3307\nuser=".$quote(config('database.username'))."\npassword=".$quote(config('database.password'))."\ndefault-character-set=utf8mb4\n");chmod($defaults,0600);
 $backup=$folder.'/rollback.sql';
 $pipes=[];$process=proc_open(['C:/xampp/mysql/bin/mysqldump.exe','--defaults-extra-file='.$defaults,'--single-transaction','--routines','--events','--triggers','--result-file='.$backup,'ai_summarizer'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 foreach($pipes as $p){stream_get_contents($p);fclose($p);}if(proc_close($process)!==0)throw new RuntimeException('Backup failed.');
 $pipes=[];$process=proc_open(['C:/xampp/mysql/bin/mysql.exe','--defaults-extra-file='.$defaults,$restore],[0=>['file',$backup,'r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 foreach($pipes as $p){stream_get_contents($p);fclose($p);}if(proc_close($process)!==0)throw new RuntimeException('Restore failed.');
 $restored=new PDO('mysql:host=127.0.0.1;port=3307;dbname='.$restore,config('database.username'),config('database.password'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $restoreMatch=snapshot($restored)===snapshot($prod);file_put_contents($folder.'/backup.json',json_encode(['path'=>$backup,'bytes'=>filesize($backup),'sha256'=>hash_file('sha256',$backup),'restored_all_tables_identical'=>$restoreMatch],JSON_PRETTY_PRINT));
 unlink($defaults);if(!$restoreMatch)throw new RuntimeException('Restored backup mismatch.');
 }else{
  $cfg=json_decode(file_get_contents($folder.'/config.json'),true);$cfg['benchmark_core']=[];
  foreach(['users','summaries','feedback'] as $table)$cfg['benchmark_core'][$table]=array_map(fn($row)=>hash('sha256',json_encode($row,JSON_INVALID_UTF8_SUBSTITUTE)),$prod->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC));
  file_put_contents($folder.'/config.json',json_encode($cfg,JSON_PRETTY_PRINT));
 }
 $test=new PDO('mysql:host=127.0.0.1;port=3307;dbname='.$name,$name,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 foreach(['database/schema.sql','database/analytics_schema.sql'] as $file)$test->exec(file_get_contents(__DIR__.'/../../'.$file));
 $test->exec('CREATE TABLE schema_migrations(id INT AUTO_INCREMENT PRIMARY KEY,migration VARCHAR(255) NOT NULL UNIQUE,applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
 $files=glob(__DIR__.'/../../database/migrations/*.sql');sort($files,SORT_NATURAL);foreach($files as $file){$test->exec(file_get_contents($file));$test->prepare('INSERT INTO schema_migrations(migration)VALUES(?)')->execute([basename($file)]);}
 $test->prepare("INSERT INTO users(username,email,password_hash,role,terms_accepted)VALUES(?,?,?,'admin',1),(?,?,?,'user',1)")->execute(['qa_admin','admin@example.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),'qa_existing','existing@example.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT)]);
 echo json_encode(['database'=>$name,'migrations'=>count($files),'production_counts'=>array_map(fn($r)=>$r['rows'],snapshot($prod))]);exit;
}
$cfg=json_decode(file_get_contents($folder.'/config.json'),true);$env=$cfg['env'];
if(!preg_match('/\Aqa_light_[a-f0-9]{8}\z/',$env['DB_NAME']))throw new RuntimeException('Invalid cleanup scope.');
if($action==='verify'&&isset($cfg['benchmark_core'])){
 $preserved=true;foreach($cfg['benchmark_core'] as $table=>$hashes){$current=array_map(fn($row)=>hash('sha256',json_encode($row,JSON_INVALID_UTF8_SUBSTITUTE)),$prod->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC));if(array_diff($hashes,$current))$preserved=false;}
 $report=['original_core_rows_preserved'=>$preserved,'live_activity_observed'=>snapshot($prod)!==$cfg['before'],'benchmark_uses_restricted_isolated_database'=>true];file_put_contents($folder.'/integrity.json',json_encode($report,JSON_PRETTY_PRINT));echo json_encode($report);exit($preserved?0:1);
}
if($action==='verify'){
 $after=snapshot($prod);$same=$after===$cfg['before'];file_put_contents($folder.'/integrity.json',json_encode(['identical'=>$same,'before'=>$cfg['before'],'after'=>$after],JSON_PRETTY_PRINT));echo json_encode(['production_unchanged'=>$same]);exit($same?0:1);
}
if($action==='cleanup'){
 $name=$env['DB_NAME'];$fresh=$env['LIGHT_QA_FRESH_DB'];$restore=$cfg['restore'];
 foreach([$name,$fresh,$restore] as $target){if(!preg_match('/\Aqa_light_(?:(?:fresh|restore)_)?[a-f0-9]{8}\z/',$target))throw new RuntimeException('Invalid cleanup database.');$prod->exec('DROP DATABASE IF EXISTS `'.$target.'`');}
 $prod->exec("DROP USER IF EXISTS '$name'@'127.0.0.1'");echo 'QA databases and restricted user removed.';
}
