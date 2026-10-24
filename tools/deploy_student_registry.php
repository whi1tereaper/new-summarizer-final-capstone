<?php
declare(strict_types=1);
// Reviewed local additive deployment, not a fixture runner. Requires a restore-verified backup.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/../app/src/Support/config.php';
require __DIR__.'/../app/src/Database.php';
$root=dirname(__DIR__);$apply=in_array('--apply',$argv,true);
if(config('database.name')!=='ai_summarizer'||(int)config('database.port')!==3307||!in_array(config('database.host'),['127.0.0.1','localhost'],true))throw new RuntimeException('This deployment is scoped to the configured local project database on port 3307.');
$db=App\Src\Database::getInstance()->getConnection();
$backup=json_decode(file_get_contents($root.'/docs/qa/backup.json'),true,512,JSON_THROW_ON_ERROR);
$path=realpath($backup['path']??'');
if(!$path||str_contains(strtolower(str_replace('\\','/',$path)),'/htdocs/')||!($backup['restored_all_tables_identical']??false)||!hash_equals($backup['sha256'],hash_file('sha256',$path)))throw new RuntimeException('A valid private restore-verified backup is required.');
function deploySnapshot(PDO $db,?array $columns=null):array{
 $result=[];$map=$columns??[];
 foreach($columns?array_keys($columns):$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
  if(!preg_match('/\A[a-zA-Z0-9_]+\z/',$table))throw new RuntimeException('Unexpected schema identifier.');
  $fields=$columns[$table]??$db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);$map[$table]=$fields;
  $sql='SELECT '.implode(',',array_map(fn($name)=>'`'.str_replace('`','``',$name).'`',$fields)).' FROM `'.$table.'`';
  $rows=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);$hashes=array_map(fn($row)=>hash('sha256',json_encode($row,JSON_INVALID_UTF8_SUBSTITUTE)),$rows);sort($hashes);
  $result[$table]=['rows'=>count($rows),'sha256'=>hash('sha256',implode('',$hashes))];
 }ksort($result);return ['tables'=>$result,'columns'=>$map];
}
$before=deploySnapshot($db);$qa=json_decode(file_get_contents($root.'/docs/qa/integrity.json'),true,512,JSON_THROW_ON_ERROR);
if(!($qa['identical']??false)||$before['tables']!==$qa['after'])throw new RuntimeException('Database changed since the verified backup. Create a fresh verified backup before deployment.');
$oldLedger=$db->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$pending=[];foreach(glob($root.'/database/migrations/*.sql') as $file)if(!in_array(basename($file),$oldLedger,true))$pending[]=basename($file);sort($pending);
if(array_diff($pending,['017_student_number_access.sql','018_password_reset_contract.sql','019_student_enrollment_registry.sql']))throw new RuntimeException('Unreviewed pending migration; stop and review.');
if(!$apply){echo json_encode(['read_only'=>true,'pending'=>$pending,'verified_backup'=>true,'registration_held'=>!config('student_access.enrollment_authorized',false)],JSON_PRETTY_PRINT).PHP_EOL;exit;}
if(config('student_access.enrollment_authorized',false)!==false)throw new RuntimeException('Keep enrollment processing authorization false during schema deployment.');
$pipes=[];$p=proc_open([PHP_BINARY,$root.'/tools/migrate.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);$exit=proc_close($p);
$after=deploySnapshot($db,$before['columns']);$preserved=true;
foreach($before['tables'] as $table=>$hash)if($table!=='schema_migrations'&&$after['tables'][$table]!==$hash)$preserved=false;
$newLedger=$db->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);$ledgerPreserved=!array_diff($oldLedger,$newLedger);
$report=['migration_exit'=>$exit,'applied'=>$pending,'verified_backup_path'=>$path,'verified_backup_sha256'=>$backup['sha256'],'original_data_preserved'=>$preserved,'existing_migration_ledger_preserved'=>$ledgerPreserved,'before'=>$before['tables'],'after_original_columns'=>$after['tables'],'registration_held'=>!config('student_access.enrollment_authorized',false)];
file_put_contents($root.'/docs/qa/registry-deployment.json',json_encode($report,JSON_PRETTY_PRINT));
echo json_encode(['migration_exit'=>$exit,'applied'=>$pending,'original_data_preserved'=>$preserved,'existing_migration_ledger_preserved'=>$ledgerPreserved,'registration_held'=>$report['registration_held']],JSON_PRETTY_PRINT).PHP_EOL;
if($exit||!$preserved||!$ledgerPreserved){fwrite(STDERR,'Deployment verification failed; stop writes and inspect the private verified rollback. No automatic restore was attempted.'.PHP_EOL);exit(1);}
