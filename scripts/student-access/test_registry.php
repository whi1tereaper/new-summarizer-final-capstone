<?php
require __DIR__.'/test_bootstrap.php';
use App\Src\Services\StudentEnrollmentRegistryService as Registry;
use App\Src\Services\StudentVerificationService as Verification;
use App\Src\Services\CampusAccessService as Access;
$db=fixtureConnection('mysql');injectFixture($db);$r=new Registry($db);$v=new Verification($db);$a=new Access($db);$passed=0;$failed=[];
function test(string $name,bool $ok):void{global $passed,$failed;if($ok){$passed++;echo "PASS $name\n";}else{$failed[]=$name;echo "FAIL $name\n";}}
function refuses(callable $fn):bool{try{$fn();return false;}catch(Throwable $e){return true;}}
function stream(string $text){$f=fopen('php://temp','w+');fwrite($f,$text);rewind($f);return $f;}
function preview(string $text,string $source='paste'):array{global $r; $f=stream($text);try{return $r->preview(1,$f,$source,'SYNTHETIC QA enrollment period',date('Y-m-d',time()+86400*60));}finally{fclose($f);}}
function import(string $text):array{global $r;return $r->confirm(1,preview($text)['batch_ref']);}
function registration(string $number):array{global $v,$a;$s=bin2hex(random_bytes(6));return $v->register('reg_'.$s,$s.'@local.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),Verification::validateSubmission(['student_number'=>$number]),fixtureVisitor($a));}
function entry(string $number):array{global $r;return $r->entries(1,$number)[0];}
$before=$db->query('SELECT id,password_hash FROM users ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);$summaries=(int)$db->query('SELECT COUNT(*) FROM summaries')->fetchColumn();
$b=preview('00001234');test('preview never populates trusted registry',!$r->entries(1,'00001234'));
$done=$r->confirm(1,$b['batch_ref']);test('single insertion', (int)$done['inserted_count']===1);
test('confirmation idempotent',$r->confirm(1,$b['batch_ref'])['committed_epoch']===$done['committed_epoch']);
test('leading zeros preserved',entry('00001234')['normalized_student_number']==='00001234');
test('short IDs are genuinely masked',Verification::masked('1234')==='1••••4');
test('distinct numeric IDs never collapse',import('1234')['inserted_count']==1&&count($r->entries(1,'1234'))===1);
$batch=import(" abc-1001 ,ABC-1001\n00001234\nBAD ID\n=1+1\nGOOD-2001,,");
test('paste counts invalid and duplicate accurately',(int)$batch['total_count']===8&&(int)$batch['inserted_count']===2&&(int)$batch['skipped_count']===2&&(int)$batch['invalid_count']===4&&(int)$batch['duplicate_count']===1);
test('case and surrounding whitespace share one identity',entry(' abc-1001 ')['normalized_student_number']==='ABC-1001');
test('meaningful separators remain distinct',import("ABCD-1234\nABCD1234")['inserted_count']==2);
$p=preview("\xEF\xBB\xBFstudent_id\r\n\"CSV-00001\"\r\nCSV-00002",'csv');test('UTF-8 BOM optional CSV header and quoted strings',(int)$r->confirm(1,$p['batch_ref'])['inserted_count']===2);
$p=preview("TXT-00001\nTXT-00002",'txt');test('TXT parser', (int)$r->confirm(1,$p['batch_ref'])['inserted_count']===2);
test('empty input rejected',refuses(fn()=>preview("\n\n")));
test('broken CSV rejected',refuses(fn()=>preview("\"CSV-FAIL\n")));
test('binary contents rejected',refuses(fn()=>preview("ABCD\0EFGH")));
test('oversize input rejected',refuses(fn()=>preview(str_repeat('A',Registry::MAX_BYTES+1))));
test('row cap enforced',refuses(fn()=>preview(str_repeat("CAP-00001\n",10001))));
$injection=import("SAFE-00001\nABC');DROP TABLE users;--\n<script>alert(1)</script>");test('SQL/XSS/formula payloads rejected as invalid rows',(int)$injection['invalid_count']===2&&$db->query('SELECT COUNT(*) FROM users')->fetchColumn()>0);
$userCount=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
test('unknown number is rejected without retaining an account',refuses(fn()=>registration('UNKNOWN-00001'))&&(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===$userCount);
$existing=fixtureLegacyPending($db,'legacy_bulk','legacy-bulk@local.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),'BULK-00000',fixtureVisitor($a));$owner=(int)$existing['id'];$hash=$db->query('SELECT password_hash FROM users WHERE id='.$owner)->fetchColumn();
$rows=[];for($i=0;$i<3000;$i++)$rows[]=sprintf('BULK-%05d',$i);
$big=import(implode("\n",$rows));test('three thousand unique records imported',(int)$big['inserted_count']===3000);
test('pending account reconciled once',(int)$big['approved_count']===1&&$a->continuedAccess($owner));
test('approval provenance and notification',$v->forUser($owner)['approval_method']==='registry_match'&&count($v->history((int)$v->forUser($owner)['id'],$owner))===2);
test('pending password preserved',$db->query('SELECT password_hash FROM users WHERE id='.$owner)->fetchColumn()===$hash);
$version=$v->forUser($owner)['version'];$repeat=import('BULK-00000');test('rerun skips approved account without rewrite',(int)$repeat['skipped_count']===1&&(int)$repeat['approved_count']===0&&$v->forUser($owner)['version']===$version);
$new=registration('BULK-00001');$newId=(int)$new['id'];test('registration automatically approves eligible registry match',$a->continuedAccess($newId)&&$v->forUser($newId)['approval_method']==='registry_match');
$users=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();test('duplicate claim rejected and transaction removes new account',refuses(fn()=>registration('bulk-00001'))&&(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===$users);
test('duplicate claim administrator audit exists',(int)$db->query("SELECT COUNT(*) FROM student_registry_events WHERE action='duplicate_claim'")->fetchColumn()>0);
$e=entry('BULK-00002');$r->changeStatus(1,$e['registry_ref'],(int)$e['version'],'inactive','Synthetic inactive fixture');test('inactive registry rejects registration',refuses(fn()=>registration('BULK-00002')));
$inactive=fixtureLegacyPending($db,'legacy_inactive','legacy-inactive@local.invalid','synthetic-hash','BULK-00002',fixtureVisitor($a));
test('reimport never reactivates inactive entry',import('BULK-00002')['conflict_count']==1&&entry('BULK-00002')['eligibility_status']==='inactive');
$e=entry('BULK-00001');$ticket=$a->reserve($newId,null,'registry-revocation');$r->changeStatus(1,$e['registry_ref'],(int)$e['version'],'revoked','Synthetic revocation fixture');
test('revocation suspends previously approved account',$v->forUser($newId)['status']==='suspended'&&!$a->continuedAccess($newId));
$db->beginTransaction();$refused=refuses(fn()=>$a->complete($ticket));$db->rollBack();$a->release($ticket);test('in-flight generation rechecks revocation before saving',$refused);
test('stale status version rejected',refuses(fn()=>$r->changeStatus(1,$e['registry_ref'],(int)$e['version'],'active','stale change')));
$e=entry('BULK-00001');$r->changeStatus(1,$e['registry_ref'],(int)$e['version'],'active','Synthetic reactivation fixture');test('reactivation never silently restores suspended access',!$a->continuedAccess($newId));
test('unprivileged import denied',refuses(fn()=>$r->preview($newId,stream('EVIL-0001'),'paste','period',date('Y-m-d',time()+86400))));
test('unprivileged registry list and statistics denied',refuses(fn()=>$r->entries($newId))&&refuses(fn()=>$r->stats($newId)));
$expiry=entry('BULK-00003');$db->prepare('UPDATE student_enrollment_registry SET eligible_until_epoch=? WHERE id=?')->execute([time()-1,$expiry['id']]);
test('expired registry rejects registration',refuses(fn()=>registration('BULK-00003')));
$expiredUser=fixtureLegacyPending($db,'legacy_expired','legacy-expired@local.invalid','synthetic-hash','BULK-00003',fixtureVisitor($a));
test('expired entry needs an explicit campus renewal',refuses(fn()=>$r->changeStatus(1,$expiry['registry_ref'],(int)$expiry['version'],'active','Synthetic renewal')));
$r->changeStatus(1,$expiry['registry_ref'],(int)$expiry['version'],'active','Synthetic confirmed renewal',['verification_period'=>'Synthetic next period','eligible_until'=>date('Y-m-d',time()+90*86400)]);
test('explicit renewal updates eligibility without silently approving accounts',entry('BULK-00003')['eligible_until_epoch']>time()&&!$a->continuedAccess((int)$expiredUser['id']));
test('authorized reimport reconciles a pending account after renewal',import('BULK-00003')['approved_count']==1&&$a->continuedAccess((int)$expiredUser['id']));
$expired=preview('EXPIRED-0001');$db->prepare('UPDATE student_registry_batches SET preview_expires_epoch=? WHERE batch_ref=?')->execute([time()-1,$expired['batch_ref']]);test('expired preview cannot commit',refuses(fn()=>$r->confirm(1,$expired['batch_ref'])));
$other=preview('OTHER-0001');test('another admin cannot claim preview',refuses(fn()=>$r->confirm(999999,$other['batch_ref'])));
$fault=preview("ROLLBACK-0001\nROLLBACK-FAIL");$db->exec("CREATE TRIGGER qa_registry_failure BEFORE INSERT ON student_enrollment_registry FOR EACH ROW BEGIN IF NEW.normalized_student_number='ROLLBACK-FAIL' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic failure'; END IF; END");
test('mid-import failure rolls back prior rows',refuses(fn()=>$r->confirm(1,$fault['batch_ref']))&&!$r->entries(1,'ROLLBACK-0001')&&$r->batch(1,$fault['batch_ref'])['state']==='preview');$db->exec('DROP TRIGGER qa_registry_failure');
test('same preview safely retries after failure',$r->confirm(1,$fault['batch_ref'])['inserted_count']==2);
// Eight real PHP connections contend for one student-number claim.
import('RACE-CLAIM');$children=[];
for($i=0;$i<8;$i++){$pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/registry_race_worker.php','register','RACE-CLAIM'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$proc,$pipes];}
$out=[];foreach($children as [$proc,$pipes]){$out[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);foreach($pipes as $p)fclose($p);if(proc_close($proc)!==0)echo $err;}
test('eight simultaneous registrations activate exactly one account',count(array_filter($out,fn($s)=>$s==='approved'))===1&&count(array_filter($out,fn($s)=>$s==='duplicate'))===7);
$p1=preview('RACE-IMPORT');$p2=preview('RACE-IMPORT');$children=[];
foreach([['confirm',$p1['batch_ref']],['register','RACE-IMPORT'],['confirm',$p2['batch_ref']]] as $args){$pipes=[];$proc=proc_open(array_merge([PHP_BINARY,__DIR__.'/registry_race_worker.php'],$args),[1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$proc,$pipes];}
$out=[];foreach($children as [$proc,$pipes]){$out[]=trim(stream_get_contents($pipes[1]));foreach($pipes as $p)fclose($p);proc_close($proc);}
$q=$db->prepare("SELECT COUNT(*) FROM student_applications WHERE student_number_fingerprint=? AND status='verified'");$q->execute([Verification::fingerprint('RACE-IMPORT')]);
if((int)$q->fetchColumn()===0)registration('RACE-IMPORT'); // A signup preceding the import must retry, never queue.
$q->execute([Verification::fingerprint('RACE-IMPORT')]);test('concurrent overlapping imports and registration activate safely or require retry',(int)$q->fetchColumn()===1&&!in_array('error',$out,true));
$db->prepare('UPDATE student_applications SET details_delete_epoch=? WHERE user_id=?')->execute([time()-1,$owner]);(new App\Src\Services\StudentRetentionService($db))->run(true);
test('application detail erasure preserves registry approval and durable duplicate protection',$a->continuedAccess($owner)&&$v->forUser($owner)['student_number']===null&&refuses(fn()=>registration('BULK-00000')));
test('expired preview data physically erased',$db->query("SELECT preview_payload IS NULL FROM student_registry_batches WHERE batch_ref=".$db->quote($expired['batch_ref']))->fetchColumn()==1);
test('all pre-existing password hashes unchanged',array_intersect_key($db->query('SELECT id,password_hash FROM users ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR),$before)===$before);
test('existing summaries preserved',(int)$db->query('SELECT COUNT(*) FROM summaries')->fetchColumn()===$summaries);
test('committed preview payload erased',$db->query("SELECT COUNT(*) FROM student_registry_batches WHERE state='committed' AND preview_payload IS NOT NULL")->fetchColumn()==0);
test('audit records never contain complete list identifiers',!str_contains(json_encode($r->recent(1,'conflicts')),'BULK-00002'));
$registryBefore=(int)$db->query('SELECT COUNT(*) FROM student_enrollment_registry')->fetchColumn();$db->exec(file_get_contents(__DIR__.'/../../database/migrations/019_student_enrollment_registry.sql'));
test('additive registry migration replays without losing imported records',(int)$db->query('SELECT COUNT(*) FROM student_enrollment_registry')->fetchColumn()===$registryBefore);
echo json_encode(['passed'=>$passed,'failed'=>count($failed),'failures'=>$failed])."\n";exit($failed?1:0);
