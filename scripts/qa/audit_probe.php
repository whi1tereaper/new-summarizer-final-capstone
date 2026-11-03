<?php
declare(strict_types=1);
require __DIR__.'/../student-access/test_bootstrap.php';
require_once __DIR__.'/../../app/src/Services/AuditTrailService.php';
require_once __DIR__.'/../../app/src/Controllers/AdminController.php';
use App\Src\Services\AuditTrailService;
use App\Src\Services\AdminSecurityService;
$db=fixtureConnection('mysql');injectFixture($db);$action=$argv[1]??'';
$_SESSION=['user_id'=>1,'role'=>'admin','terms_accepted'=>1];
function value(PDO $db,string $sql,array $args=[]):mixed{$q=$db->prepare($sql);$q->execute($args);return $q->fetchColumn();}
if($action==='seed'){
    $logger=new AdminSecurityService($db);
    $logger->recordAudit(1,'admin_deleted_user','Deactivated user: synthetic legacy account','user',2);
    $logger->recordAudit(1,'admin_deleted_user','Hard deleted user: synthetic legacy account','user',901);
    $logger->recordAudit(1,'qa_escaped','<script>window.auditXss=1</script> & "quoted"','user',2);
    $marker=str_repeat('b',64);$db->prepare("INSERT INTO student_registry_events(actor_user_id,action,identity_fingerprint,detail,created_epoch)VALUES(1,'qa_registry_marker',?,'Synthetic registry event',?)")->execute([$marker,time()]);
    $statement=$db->prepare("INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,details,created_at)VALUES(1,'qa_pagination','user',2,?,'2031-04-17 04:00:00')");
    for($i=0;$i<61;$i++)$statement->execute(['Synthetic pagination event '.$i]);
    $newUser=static function(string $name)use($db):int{$db->prepare("INSERT INTO users(username,email,password_hash,role,terms_accepted)VALUES(?,?,?,'user',1)")->execute([$name,$name.'@example.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT)]);return (int)$db->lastInsertId();};
    $ids=['toggle'=>$newUser('qa_audit_toggle'),'deactivate'=>$newUser('qa_audit_deactivate'),'delete'=>$newUser('qa_audit_delete'),'fault'=>$newUser('qa_audit_fault')];
    $db->prepare("INSERT INTO summaries(user_id,article_title,input_type,status)VALUES(?,'Synthetic rollback relationship','text','completed')")->execute([$ids['fault']]);
    echo json_encode($ids);exit;
}
if($action==='user'){
    echo json_encode(['active'=>value($db,'SELECT active FROM users WHERE id=?',[(int)$argv[2]]),'summaries'=>(int)value($db,'SELECT COUNT(*) FROM summaries WHERE user_id=?',[(int)$argv[2]]),'events'=>$db->query('SELECT action,details FROM admin_audit_logs WHERE entity_type=\'user\' AND entity_id='.(int)$argv[2].' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)]);exit;
}
if($action==='latest'){$q=$db->prepare('SELECT action,details FROM admin_audit_logs WHERE action=? ORDER BY id DESC LIMIT 1');$q->execute([$argv[2]]);echo json_encode($q->fetch(PDO::FETCH_ASSOC));exit;}
if($action==='snapshot'){
    $out=[];foreach(['admin_audit_logs','student_registry_events'] as $table){$rows=$db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$out[$table]=['count'=>count($rows),'hash'=>hash('sha256',json_encode($rows))];}echo json_encode($out);exit;
}
if($action==='fault-on'){$db->exec("CREATE TRIGGER qa_audit_write_failure BEFORE INSERT ON admin_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");echo '{}';exit;}
if($action==='fault-off'){$db->exec('DROP TRIGGER IF EXISTS qa_audit_write_failure');echo '{}';exit;}
if($action==='core'){
    $checks=[];$check=static function(string $name,bool $ok)use(&$checks){$checks[]=['name'=>$name,'passed'=>$ok];echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;};
    $service=new AuditTrailService($db);$logger=new AdminSecurityService($db);
    $all=$service->browse(1,[]);$check('unified counts equal the two original ledgers', $all['total']===(int)value($db,'SELECT COUNT(*) FROM admin_audit_logs')+(int)value($db,'SELECT COUNT(*) FROM student_registry_events'));
    foreach(['admin','registry'] as $source){$r=$service->browse(1,['source'=>$source]);$check($source.' source filter returns only its original ledger',count($r['rows'])>0&&count(array_filter($r['rows'],fn($row)=>$row['source']!==$source))===0);}
    $a=$service->browse(1,['action'=>'qa_pagination']);$b=$service->browse(1,['action'=>'qa_pagination','page'=>2]);
    $keys=static fn($rows)=>array_map(fn($row)=>$row['source'].':'.$row['event_id'],$rows);
    $check('stable pagination separates 61 same-second events into 50 and 11',count($a['rows'])===50&&count($b['rows'])===11&&!array_intersect($keys($a['rows']),$keys($b['rows']))&&$keys($a['rows'])===$keys($service->browse(1,['action'=>'qa_pagination'])['rows']));
    $check('out-of-range page safely clamps to the last page',$service->browse(1,['action'=>'qa_pagination','page'=>999])['page']===2);
    $check('unknown action does not broaden the query',$service->browse(1,['action'=>"' OR 1=1 --"])['total']===0);
    $check('actor filter applies to both sources',count(array_filter($service->browse(1,['admin_id'=>1])['rows'],fn($row)=>(int)$row['actor_id']!==1))===0);
    foreach([['source'=>'invalid'],['source'=>[]],['admin_id'=>'-1'],['date_from'=>'2026-02-30'],['date_from'=>'2026-10-24','date_to'=>'2026-10-23'],['page'=>'9999999999999999999999999'],['action'=>str_repeat('x',101)]] as $index=>$bad){$blocked=false;try{$service->browse(1,$bad);}catch(InvalidArgumentException $e){$blocked=true;}$check('invalid filter case '.($index+1).' is rejected without SQL ambiguity',$blocked);}
    foreach([0,2] as $actor){$blocked=false;try{$service->browse($actor,[]);}catch(App\Src\Support\ProcessingError $e){$blocked=$e->errorCode==='ACCESS_DENIED';}$check('service denies non-administrator actor '.$actor,$blocked);}
    $check('legacy deactivation is labelled without rewriting its action',AuditTrailService::label('admin_deleted_user','Deactivated user: old')==='Account deactivated (legacy)'&&AuditTrailService::label('admin_deleted_user','Hard deleted user: old')==='Account permanently deleted (legacy)');
    $check('unrecorded connection metadata and identity fingerprints are not invented or returned',!str_contains(json_encode($service->browse(1,['source'=>'registry'])),'identity_fingerprint')&&array_reduce($service->browse(1,['source'=>'registry'])['rows'],fn($ok,$row)=>$ok&&$row['ip_address']===null&&$row['user_agent']===null,true));
    // Date boundaries must agree even if the MySQL session is in UTC.
    $originalZone=value($db,'SELECT @@session.time_zone');$db->exec("SET time_zone='+00:00'");
    try{
        $epoch=(new DateTimeImmutable('2032-03-02 00:00:00',new DateTimeZone('Asia/Manila')))->getTimestamp();
        $q=$db->prepare("INSERT INTO admin_audit_logs(admin_id,action,created_at)VALUES(1,'qa_boundary',FROM_UNIXTIME(?))");$r=$db->prepare("INSERT INTO student_registry_events(actor_user_id,action,created_epoch)VALUES(1,'qa_boundary',?)");
        foreach([$epoch-1,$epoch,$epoch+86399,$epoch+86400] as $instant){$q->execute([$instant]);$r->execute([$instant]);}
        $dates=$service->browse(1,['action'=>'qa_boundary','date_from'=>'2032-03-02','date_to'=>'2032-03-02']);
        $check('date range includes both boundary sources with UTC database and Manila application dates',$dates['total']===4&&$dates['counts']===['admin'=>2,'registry'=>2]);
    }finally{$db->exec('SET time_zone='.$db->quote((string)$originalZone));}
    $_SERVER['HTTP_USER_AGENT']=str_repeat('A',500)."\r\nInjected";$_SERVER['REMOTE_ADDR']='invalid';$logger->recordAudit(1,'qa_metadata','Synthetic bounded metadata');
    $metadata=$db->query("SELECT ip_address,user_agent FROM admin_audit_logs WHERE action='qa_metadata' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $check('audit metadata is bounded and invalid IP/control characters are excluded',$metadata['ip_address']===null&&strlen($metadata['user_agent'])<=255&&!str_contains($metadata['user_agent'],"\n"));
    $db->exec('RENAME TABLE student_registry_events TO qa_hidden_registry_events');
    try{$partial=$service->browse(1,[]);$check('missing registry ledger produces an explicit incomplete-view warning',$partial['warnings']!==[]&&$partial['counts']['admin']>0&&$partial['counts']['registry']===0);}finally{$db->exec('RENAME TABLE qa_hidden_registry_events TO student_registry_events');}
    $db->exec('RENAME TABLE student_registry_events TO qa_hidden_registry_events,admin_audit_logs TO qa_hidden_admin_logs');
    try{$blocked=false;try{$service->browse(1,[]);}catch(RuntimeException $e){$blocked=true;}$check('missing both ledgers fails instead of fabricating an empty success',$blocked);}finally{$db->exec('RENAME TABLE qa_hidden_registry_events TO student_registry_events,qa_hidden_admin_logs TO admin_audit_logs');}
    file_put_contents((string)getenv('QA_AUDIT_REPORT').'/service.json',json_encode($checks,JSON_PRETTY_PRINT));exit(count(array_filter($checks,fn($case)=>!$case['passed']))?1:0);
}
exit(1);
