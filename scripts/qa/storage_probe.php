<?php
declare(strict_types=1);
require __DIR__.'/../student-access/test_bootstrap.php';
require_once __DIR__.'/../../app/src/Services/StorageMaintenanceService.php';
use App\Src\Services\StorageMaintenanceService;
use App\Src\Support\RuntimePaths;
$db=fixtureConnection('mysql');injectFixture($db);$action=$argv[1]??'';
$root=realpath((string)getenv('QA_STORAGE_PRIVATE'));
if(!$root||str_contains(strtolower(str_replace('\\','/',$root)),'/htdocs/'))throw new RuntimeException('Private fixture directory required.');
function fixtureSummary(PDO $db): void {
    $db->prepare("INSERT INTO summaries(user_id,share_token,article_title,input_type,status) VALUES(1,?,'Synthetic storage test','text','completed')")->execute([bin2hex(random_bytes(20))]);
    $db->prepare('INSERT INTO summary_artifacts(summary_id,original_text,generated_summary) VALUES(?,?,?)')->execute([(int)$db->lastInsertId(),'Synthetic source','Synthetic result']);
}
if($action==='seed'){
    foreach(['uploads','audio','logs','tmp','models','backups','uploads/nested'] as $dir)if(!is_dir($root.'/storage/'.$dir))mkdir($root.'/storage/'.$dir,0700,true);
    foreach(['uploads/source.pdf'=>'1234567890','uploads/nested/extensionless'=>'abc','uploads/.htaccess'=>'ignored','audio/voice.wav'=>'12345','tmp/task'=>'ab','logs/application.log'=>'1234','models/model.bin'=>'1234567','backups/qa.sql'=>'123456789'] as $file=>$content)file_put_contents($root.'/storage/'.$file,$content);
    fixtureSummary($db);fixtureSummary($db);
    $db->exec("INSERT INTO summaries(user_id,share_token,article_title,input_type,status) VALUES(1,'qa_storage_failed','Synthetic failed','text','failed')");
    $db->exec("INSERT INTO nutshell_generations(user_id,input_type,status,nutshell_text) VALUES(1,'text','completed','Synthetic nutshell'),(1,'text','failed',NULL)");
    foreach(['queued','processing','completed','failed'] as $state)$db->prepare('INSERT INTO summary_jobs(job_id,user_id,state,payload,idempotency_key) VALUES(?,1,?,?,?)')->execute(['qa_storage_'.$state,$state,'{}','qa_storage_'.$state]);
    echo '{}';exit;
}
if($action==='add'){
    fixtureSummary($db);file_put_contents($root.'/storage/uploads/nested/new.bin','12345678901234567');echo '{}';exit;
}
if($action==='remove'){unlink($root.'/storage/uploads/nested/new.bin');echo '{}';exit;}
if($action==='admin-active'){$db->prepare('UPDATE users SET active=? WHERE id=1')->execute([(int)$argv[2]]);echo '{}';exit;}
if($action==='snapshot'){
    $out=[];
    foreach(['summaries','summary_artifacts','nutshell_generations','summary_jobs','users'] as $table)$out[$table]=hash('sha256',json_encode($db->query('SELECT * FROM '.$table.' ORDER BY '.($table==='summary_artifacts'?'summary_id':'id'))->fetchAll(PDO::FETCH_ASSOC)));
    $files=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/storage',FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile())$files[substr($file->getPathname(),strlen($root)+1)]=hash_file('sha256',$file->getPathname());ksort($files);$out['files']=$files;
    echo json_encode($out);exit;
}
if($action==='core'){
    $checks=[];$check=static function(string $name,bool $ok)use(&$checks){$checks[]=['name'=>$name,'passed'=>$ok];echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;};
    $service=new StorageMaintenanceService($db);$a=$service->snapshot(1);
    $check('summary and Nutshell counts exclude failed records',$a['database']['saved_summaries']===2&&$a['database']['summary_records']===3&&$a['database']['nutshells']===1);
    $check('job states come from actual MySQL rows',$a['database']['queued_jobs']===1&&$a['database']['processing_jobs']===1);
    $check('MySQL allocation is measured as a positive estimate',$a['database']['allocated_bytes']>0);
    $check('nested and extensionless uploads count but server metadata does not',$a['runtime']['folders']['uploads']['files']===2&&$a['runtime']['folders']['uploads']['bytes']===13);
    $check('configured audio aliases are not counted three times',$a['runtime']['folders']['audio']['files']===1&&$a['runtime']['folders']['audio']['bytes']===5);
    $check('runtime totals cover the six real configured categories',$a['runtime']['files']===7&&$a['runtime']['bytes']===40&&$a['runtime']['complete']&&$a['warnings']===[]);
    $check('output does not disclose paths filenames or source contents',!str_contains(json_encode($a),'source.pdf')&&!str_contains(json_encode($a),$root)&&!str_contains(json_encode($a),'Synthetic source'));
    foreach([0,2] as $actor){$blocked=false;try{$service->snapshot($actor);}catch(App\Src\Support\ProcessingError $e){$blocked=$e->errorCode==='ACCESS_DENIED';}$check('service rejects non-admin actor '.$actor,$blocked);}
    $db->exec('UPDATE users SET active=0 WHERE id=1');try{$blocked=false;try{$service->snapshot(1);}catch(App\Src\Support\ProcessingError $e){$blocked=$e->errorCode==='ACCESS_DENIED';}$check('inactive administrator is denied despite stale session',$blocked);}finally{$db->exec('UPDATE users SET active=1 WHERE id=1');}
    $db->exec('RENAME TABLE nutshell_generations TO qa_hidden_nutshells');
    try{$partial=$service->snapshot(1);$check('unavailable DB metric stays null rather than misleading zero',$partial['database']['nutshells']===null&&$partial['status']==='warning'&&$partial['database']['saved_summaries']===2);}finally{$db->exec('RENAME TABLE qa_hidden_nutshells TO nutshell_generations');}
    $db->exec('RENAME TABLE summary_jobs TO qa_hidden_jobs');
    try{$partial=$service->snapshot(1);$check('unavailable job table does not fabricate empty healthy queue',$partial['database']['queued_jobs']===null&&$partial['database']['processing_jobs']===null&&$partial['status']==='warning');}finally{$db->exec('RENAME TABLE qa_hidden_jobs TO summary_jobs');}
    rename($root.'/storage/models',$root.'/qa-models-hidden');
    try{$partial=$service->snapshot(1);$check('missing storage folder produces explicit partial measurement',$partial['runtime']['complete']===false&&$partial['runtime']['folders']['models']['status']==='missing'&&$partial['warnings']!==[]);}finally{rename($root.'/qa-models-hidden',$root.'/storage/models');}
    $scan=new ReflectionMethod($service,'scan');
    foreach([RuntimePaths::projectRoot(),dirname(RuntimePaths::projectRoot())] as $index=>$broad){$visited=[];$entries=0;$deadline=microtime(true)+2;$args=[$broad,&$visited,&$entries,$deadline];$result=$scan->invokeArgs($service,$args);$check('broad root configuration guard '.($index+1),$result['status']==='unsafe_root'&&$entries===0);}
    $visited=[];$entries=20000;$deadline=microtime(true)+2;$args=[$root.'/storage/uploads',&$visited,&$entries,$deadline];$result=$scan->invokeArgs($service,$args);$check('entry scan budget stops safely with explicit partial status',$result['status']==='limited'&&!$result['complete']);
    $visited=[];$entries=0;$args=[$root.'/storage/uploads',&$visited,&$entries,microtime(true)-1];$result=$scan->invokeArgs($service,$args);$check('elapsed scan budget stops safely with explicit partial status',$result['status']==='limited'&&!$result['complete']);
    $visited=[];$entries=0;$deadline=microtime(true)+2;$args=[$root.'/storage/audio',&$visited,&$entries,$deadline];$first=$scan->invokeArgs($service,$args);$second=$scan->invokeArgs($service,$args);$check('overlapping storage paths are counted once and explicitly flagged',$first['files']===1&&$second['files']===0&&$second['status']==='overlap');
    $check('byte formatting distinguishes unavailable from true zero',StorageMaintenanceService::formatBytes(null)==='Unavailable'&&StorageMaintenanceService::formatBytes(0)==='0 B'&&StorageMaintenanceService::formatBytes(1024)==='1.00 KiB');
    file_put_contents((string)getenv('QA_STORAGE_REPORT').'/service.json',json_encode($checks,JSON_PRETTY_PRINT));exit(count(array_filter($checks,fn($case)=>!$case['passed']))?1:0);
}
exit(1);
