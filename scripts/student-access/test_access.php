<?php
require __DIR__.'/test_bootstrap.php';
use App\Src\Services\CampusAccessService as Access;
use App\Src\Services\StudentVerificationService as Verification;
use App\Src\Support\ProcessingError;
require_once __DIR__.'/../../app/src/Controllers/DocumentSummaryController.php';
require_once __DIR__.'/../../app/src/Services/ArticleService.php';
$driver=$argv[1]??'';$db=fixtureConnection($driver);
fixtureSchema($db);
injectFixture($db);$a=new Access($db);$v=new Verification($db);$passed=0;$failures=[];
function check(string $name,bool $ok):void{global $passed,$failures;if($ok){$passed++;echo "PASS $name\n";}else{$failures[]=$name;echo "FAIL $name\n";}}
function rejects(callable $call,?string $code=null): bool{try{$call();return false;}catch(Throwable $e){return $code===null||($e instanceof ProcessingError&&$e->errorCode===$code);}}
function complete(Access $a,PDO $db,array $ticket):void{$db->beginTransaction();try{$a->complete($ticket);$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}}
class FixtureBridge extends \App\Src\Services\LocalPythonBridge {
 public int $calls=0;public bool $fail=false;
 public function __construct(){}
 public function generateSummary(string $sourceText,string $profile,string $length,int $timeoutSeconds=60):array{$this->calls++;if($this->fail)throw new RuntimeException('fixture failure');return ['summary'=>'The source describes an academic service and its privacy safeguards.'];}
 public function summarize(array $payload,?int $timeoutSeconds=null):array{if($this->fail)throw new RuntimeException('fixture fallback failure');return ['plain_summary'=>'A shorter source-grounded summary.'];}
}
$token=fixtureVisitor($a);check('new guest has exactly three',$a->status(null,$token)['remaining']===3);
$t=$a->reserve(null,$token,'failed');$a->release($t);check('failure costs zero',$a->status(null,$token)['remaining']===3);
$t=$a->reserve(null,$token,'rolledback');$db->beginTransaction();$a->complete($t);$db->rollBack();$a->release($t);check('result rollback rolls back debit',$a->status(null,$token)['remaining']===3);
$t=$a->reserve(null,$token,'once');complete($a,$db,$t);complete($a,$db,$t);check('idempotent successful debit exactly one',$a->status(null,$token)['remaining']===2);
for($i=0;$i<2;$i++)complete($a,$db,$a->reserve(null,$token,'next'.$i));
check('fourth generation denied',rejects(fn()=>$a->reserve(null,$token,'fourth'),'GUEST_LIMIT_REACHED'));
$suffix=bin2hex(random_bytes(5));$data=Verification::validateSubmission(['student_number'=>' pen2023-'.$suffix]);
check('student number only accepted without name email or photos',$data['full_name']===null);
$user=fixtureLegacyPending($db,'student_'.$suffix,$suffix.'@example.invalid',password_hash('Strong123!',PASSWORD_DEFAULT),$data['student_number'],$token);$id=(int)$user['id'];$app=$v->forUser($id);
check('historical unverified account remains restricted',$app['status']==='pending'&&!$a->continuedAccess($id));
check('registration never resets exhausted trial',$a->status($id,$token)['remaining']===0);
check('password hash preserved',password_verify('Strong123!',$db->query('SELECT password_hash FROM users WHERE id='.$id)->fetchColumn()));
check('duplicate student claim refused',rejects(fn()=>$v->register('other_'.$suffix,'other'.$suffix.'@example.invalid','hash',$data,$token)));
check('missing student number refused',rejects(fn()=>Verification::normalizeNumber('')));
check('unsafe student number refused',rejects(fn()=>Verification::normalizeNumber("PEN'; DROP TABLE users--")));
check('name XSS rejected',rejects(fn()=>Verification::validateSubmission(['full_name'=>'<script>alert(1)</script>'])));
check('number syntax is configurable safety only',Verification::normalizeNumber(' abc-1234 ')==='ABC-1234');
check('unprivileged review refused',rejects(fn()=>$v->review($id,$app['application_ref'],1,'accept',[]),'ACCESS_DENIED'));
$db->prepare("INSERT INTO users(username,email,password_hash,role)VALUES(?,?,?,'admin')")->execute(['admin_'.$suffix,'admin'.$suffix.'@example.invalid','hash']);$admin=(int)$db->lastInsertId();
check('no evidence means no approval',rejects(fn()=>$v->review($admin,$app['application_ref'],1,'accept',['reason'=>'Actual review required'])));
$evidence=['reason'=>'Authorized fixture confirmation','evidence_kind'=>'registrar_confirmation','evidence_reference'=>'SYNTHETIC TEST ONLY: registrar fixture','ownership_reference'=>'SYNTHETIC TEST ONLY: campus contact fixture','verification_period'=>'Synthetic test period','expires_date'=>date('Y-m-d',time()+86400*30),'evidence_confirmed'=>'1'];
$v->review($admin,$app['application_ref'],1,'accept',$evidence);$app=$v->forUser($id);
check('evidenced human approval grants access',$app['status']==='verified'&&$a->continuedAccess($id));
check('repeated stale decision refused',rejects(fn()=>$v->review($admin,$app['application_ref'],1,'decline',['reason'=>'stale review'])));
check('student notification and audit are real',count($v->history((int)$app['id'],$id))===2);
$t=$a->reserve($id,$token,'verified');complete($a,$db,$t);check('verified generation does not debit trial',$a->status($id,$token)['continued']);
$t=$a->reserve($id,$token,'revoked');$v->review($admin,$app['application_ref'],(int)$app['version'],'suspend',['reason'=>'Suspension fixture']);
check('revocation checked again before persistence',rejects(fn()=>complete($a,$db,$t),'ACCESS_DENIED'));$a->release($t);
$app=$v->forUser($id);$v->review($admin,$app['application_ref'],(int)$app['version'],'correct',['reason'=>'Correct the mistyped number']);$app=$v->forUser($id);
$registry=new \App\Src\Services\StudentEnrollmentRegistryService($db);$stream=fopen('php://temp','w+');fwrite($stream,'PEN-NEW-'.$suffix);rewind($stream);$registry->import($admin,$stream,'paste');fclose($stream);
$v->resubmit($id,['full_name'=>'Corrected Fixture','student_number'=>'PEN-NEW-'.$suffix,'terms_accept'=>'1','privacy_consent'=>'1','eligibility_ack'=>'1'],(int)$app['version'],$token);$app=$v->forUser($id);
check('eligible correction reuses and activates existing account',$app['status']==='verified'&&$a->continuedAccess($id)&&(int)$db->query('SELECT s.completed_count FROM summary_access_subjects s JOIN student_trial_links l ON l.subject_key=s.subject_key WHERE l.user_id='.$id)->fetchColumn()===3);
$db->prepare('UPDATE student_applications SET details_delete_epoch=? WHERE user_id=?')->execute([time()-1,$id]);
check('overdue details hidden before cleanup',$v->forUser($id)['student_number']===null);
$db->prepare('UPDATE student_review_events SET created_epoch=? WHERE application_id=?')->execute([time()-259201,$app['id']]);
$retention=new \App\Src\Services\StudentRetentionService($db);$dry=$retention->run(false);check('retention dry run read only',$dry['applications_erased']===0);
$retention->run(true);$raw=$db->query('SELECT * FROM student_applications WHERE user_id='.$id)->fetch();
check('three day physical detail erasure',$raw['student_number']===null&&$raw['full_name']===null&&$raw['evidence_reference']===null&&$raw['ownership_reference']===null&&$raw['review_reason']===null);
check('minimal approval retained',$raw['status']==='verified'&&$raw['student_number_fingerprint']!==null&&$raw['expires_epoch']!==null&&$a->continuedAccess($id));
check('old review detail erased',(int)$db->query('SELECT COUNT(*) FROM student_review_events WHERE application_id='.(int)$app['id'].' AND notes IS NOT NULL')->fetchColumn()===0);
check('cleanup idempotent',$retention->run(true)['applications_erased']===0);
$db->prepare('UPDATE student_applications SET expires_epoch=? WHERE user_id=?')->execute([time()-1,$id]);check('expiry enforced without scheduler',!$a->continuedAccess($id));
check('admin role checked from database',$a->continuedAccess($admin));
// Real concurrent connections, not mocked counters.
$race=fixtureVisitor($a);$children=[];
for($i=0;$i<8;$i++){$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/race_worker.php',$driver,$race],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$process,$pipes];}
$outcomes=[];foreach($children as [$process,$pipes]){$outcomes[]=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)echo $stderr;}
check('eight concurrent requests admit exactly three',count(array_filter($outcomes,fn($o)=>$o==='completed'))===3&&$a->status(null,$race)['remaining']===0);
check('concurrency only produces expected denials',count(array_filter($outcomes,fn($o)=>!in_array($o,['completed','ACCESS_BUSY','GUEST_LIMIT_REACHED'],true)))===0);
$token=fixtureVisitor($a);$_SESSION['guest_token']=bin2hex(random_bytes(32));$bridge=new FixtureBridge();$docs=new \App\Src\Services\DocumentSummaryService($db,$bridge);
$source=str_repeat('Academic tools require careful testing and maintain private student records. ',30);
$result=$docs->createDocumentWithDefaultSummary($source);$docId=$result['documentId'];check('document API consumes shared allowance',$a->status(null,$token)['remaining']===2);
$cached=$docs->getOrGenerateSummary($docId,'general','balanced');check('cache does not consume allowance',$cached['cached']&&$a->status(null,$token)['remaining']===2&&$bridge->calls===1);
$bridge->fail=true;check('generation failure returned',rejects(fn()=>$docs->getOrGenerateSummary($docId,'general','detailed')));check('generation failure costs zero',$a->status(null,$token)['remaining']===2);$bridge->fail=false;
check('batch larger than allowance rolls back',rejects(fn()=>$docs->batchGetOrGenerateSummaries($docId,[['profile'=>'general','length'=>'brief'],['profile'=>'general','length'=>'detailed'],['profile'=>'general','length'=>'comprehensive']])));
check('failed batch has no hidden charge',$a->status(null,$token)['remaining']===2&&count($docs->getSummaries($docId))===1);
$controller=new \App\Src\Controllers\DocumentSummaryController($docs);ob_start();$controller->dispatch('POST','/api/documents',['text'=>$source]);$response=ob_get_clean();check('direct API missing CSRF denied',$controller->getLastStatusCode()===403);
$_SESSION['csrf_token']=bin2hex(random_bytes(32));ob_start();$controller->dispatch('POST','/api/documents/'.$docId.'/summaries',['profile'=>'general','length'=>'brief','csrf_token'=>$_SESSION['csrf_token']]);ob_end_clean();check('valid CSRF API request succeeds',$controller->getLastStatusCode()===200&&$a->status(null,$token)['remaining']===1);
$docs->getOrGenerateSummary($docId,'general','detailed');check('API cannot bypass exhausted limit',rejects(fn()=>$docs->getOrGenerateSummary($docId,'general','comprehensive'),'GUEST_LIMIT_REACHED'));
check('existing results readable at zero',count($docs->getSummaries($docId))===3);
$oldGuest=$_SESSION['guest_token'];$_SESSION['guest_token']=bin2hex(random_bytes(32));check('document IDOR blocked',$docs->getDocument($docId)===null);$_SESSION['guest_token']=$oldGuest;
$token=fixtureVisitor($a);$_SESSION['guest_token']=bin2hex(random_bytes(32));$article=new \App\Src\Services\ArticleService(pythonBridge:$bridge);$article->reserveSummaryAccess();
$stored=$article->storeSummary(null,$_SESSION['guest_token'],['sentences'=>['This fixture describes secure academic summarization.'],'plain_summary'=>'This fixture describes secure academic summarization.','validation_passed'=>true],'text','standard_paragraph',$source);
check('main pipeline result and debit atomic',(int)$stored>0&&$a->status(null,$token)['remaining']===2);
// Failure, lease and migration edge cases, using the same production services.
$token=fixtureVisitor($a);$t=$a->reserve(null,$token,'expired-lease');$db->prepare('UPDATE summary_access_reservations SET expires_epoch=? WHERE attempt_id=?')->execute([time()-1,$t['attempt_id']]);
check('expired reservation cannot persist',rejects(fn()=>complete($a,$db,$t),'ACCESS_DENIED'));
complete($a,$db,$a->reserve(null,$token,'replacement'));check('expired lease restores capacity without debit',$a->status(null,$token)['remaining']===2);
$old=$a->reserve(null,$token,Access::jobReference('fixture-job',11));$new=$a->reserve(null,$token,Access::jobReference('fixture-job',12));$a->releaseRequest(Access::jobReference('fixture-job',11));
check('stale worker release cannot release new attempt',$db->query("SELECT state FROM summary_access_reservations WHERE attempt_id='".$new['attempt_id']."'")->fetchColumn()==='reserved');$a->release($new);
check('old pending account binding survives fresh browser',$a->status($id,$token)['remaining']===0);
$db->prepare("INSERT INTO users(username,email,password_hash)VALUES(?,?,?)")->execute(['legacy_'.$suffix,'legacy'.$suffix.'@example.invalid','unchanged-hash']);$legacy=(int)$db->lastInsertId();
check('existing account never automatically verified',!$a->continuedAccess($legacy)&&$v->forUser($legacy)===null);
$_SESSION['role']='admin';check('client role cannot grant continued access',!$a->continuedAccess($legacy));unset($_SESSION['role']);
$db->prepare('UPDATE users SET active=0 WHERE id=?')->execute([$legacy]);check('inactive account is denied',rejects(fn()=>$a->status($legacy,$token),'ACCESS_DENIED'));
check('owner cannot read other review history',$v->history((int)$app['id'],$legacy)===[]);
$pendingToken=fixtureVisitor($a);$pending=fixtureLegacyPending($db,'pending_'.$suffix,'pending'.$suffix.'@example.invalid','hash','PEN-PENDING-'.$suffix,$pendingToken);$pendingId=(int)$pending['id'];
$db->prepare('UPDATE student_applications SET details_delete_epoch=? WHERE user_id=?')->execute([time()-1,$pendingId]);$retention->run(true);$raw=$db->query('SELECT * FROM student_applications WHERE user_id='.$pendingId)->fetch();
check('expired pending details require correction, not approval',$raw['status']==='resubmission_required'&&!$a->continuedAccess($pendingId));
check('erased unapproved claim releases duplicate identifier',$raw['student_number_fingerprint']===null&&$raw['normalized_student_number']===null);
$token=fixtureVisitor($a);$_SESSION['guest_token']=bin2hex(random_bytes(32));$article=new \App\Src\Services\ArticleService(pythonBridge:$bridge);$article->reserveSummaryAccess();$before=(int)$db->query('SELECT COUNT(*) FROM summaries')->fetchColumn();
check('mismatched persistence identity rejected',rejects(fn()=>$article->storeSummary($admin,null,['sentences'=>['Synthetic fixture summary.'],'plain_summary'=>'Synthetic fixture summary.'],'text','standard_paragraph',$source),'ACCESS_DENIED'));
check('persistence failure rolls back result and debit',(int)$db->query('SELECT COUNT(*) FROM summaries')->fetchColumn()===$before&&$a->status(null,$token)['remaining']===3);
for($i=0;$i<3;$i++)complete($a,$db,$a->reserve(null,$token,'exhaust-'.$i));
foreach(['text','url','file'] as $type)check('exhausted '.$type.' is gated before extraction/upload',rejects(fn()=>$article->buildPendingSummary(['source_type'=>$type],[],null,$_SESSION['guest_token']),'GUEST_LIMIT_REACHED'));
$token=fixtureVisitor($a);$_SESSION['guest_token']=bin2hex(random_bytes(32));$oldLimit=ini_get('max_execution_time');ini_set('max_execution_time','1');
$timedOut=rejects(fn()=>$docs->createDocumentWithDefaultSummary($source),'PROCESSING_TIMEOUT');ini_set('max_execution_time',$oldLimit);
check('request budget exhaustion is controlled and never debits',$timedOut&&$a->status(null,$token)['remaining']===3);
echo json_encode(['driver'=>$driver,'passed'=>$passed,'failed'=>count($failures),'failures'=>$failures,'race_outcomes'=>$outcomes],JSON_PRETTY_PRINT).PHP_EOL;
if(in_array('--report',$argv,true))file_put_contents(__DIR__.'/../../docs/student-number-access/tests-'.$driver.(in_array('--repair-report',$argv,true)?'-repair':'').'.json',json_encode(['recorded_utc'=>gmdate('c'),'isolated_fixture'=>true,'existing_schema'=>in_array('--existing-schema',$argv,true),'nlp'=>'fixture bridge for accounting tests; real NLP is separate HTTP evidence','driver'=>$driver,'passed'=>$passed,'failed'=>count($failures),'failures'=>$failures,'race_outcomes'=>$outcomes],JSON_PRETTY_PRINT).PHP_EOL);
exit($failures?1:0);
