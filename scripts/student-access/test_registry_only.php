<?php
declare(strict_types=1);
require __DIR__.'/test_bootstrap.php';
require_once __DIR__.'/../../app/src/Support/StudentRegistrationForm.php';
use App\Src\Services\StudentVerificationService as Verification;
use App\Src\Services\StudentEnrollmentRegistryService as Registry;
use App\Src\Services\CampusAccessService as Access;
$db=fixtureConnection('mysql');injectFixture($db);$v=new Verification($db);$r=new Registry($db);$a=new Access($db);$checks=[];
$ids=json_decode(file_get_contents((string)getenv('QA_INITIAL_LIST_FILE')),true,512,JSON_THROW_ON_ERROR);
function proof(string $name,bool $ok):void{global $checks;$checks[]=['name'=>$name,'passed'=>$ok];echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;}
function refuses(callable $call):bool{try{$call();return false;}catch(Throwable $error){return true;}}
function inputStream(string $text){$s=fopen('php://temp','w+');fwrite($s,$text);rewind($s);return $s;}
function addIds(string $text):array{global $r;$s=inputStream($text);try{return $r->import(1,$s,'paste');}finally{fclose($s);}}
function signup(string $number,?string $username=null,?string $email=null):array{global $v,$a;$suffix=bin2hex(random_bytes(5));return $v->register($username??'allow_'.$suffix,$email??$suffix.'@local.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),$v::validateSubmission(['student_number'=>$number]),fixtureVisitor($a));}
function counts():array{global $db;$out=[];foreach(['users','summaries','feedback'] as $table)$out[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();return $out;}
$original=$db->query('SELECT id,password_hash FROM users ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);$originalCounts=counts();
proof('runtime is the required PHP 8.3',PHP_VERSION_ID>=80300&&PHP_VERSION_ID<80400);
proof('private runtime input contains exactly the requested 38 distinct IDs',count($ids)===38&&count(array_unique($ids))===38);
$db->exec(file_get_contents(__DIR__.'/../../database/migrations/020_registry_only_allowlist.sql'));
proof('migration 020 replays without duplicate policy rows',(int)$db->query('SELECT COUNT(*) FROM student_registry_policy')->fetchColumn()===1);
// An old authorized-registry entry not in the new baseline must lose eligibility.
addIds('PEN2098-880001');
$legacy=fixtureLegacyPending($db,'qa_old_manual','old-manual@example.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),'PEN2098-880001',fixtureVisitor($a));$legacyId=(int)$legacy['id'];
$db->prepare("UPDATE student_applications SET status='verified',approval_method='manual_review',evidence_kind='registrar_confirmation',expires_epoch=? WHERE user_id=?")->execute([time()+86400*365,$legacyId]);
proof('manual approval without a registry claim no longer bypasses access',!$a->continuedAccess($legacyId));
$baseline=$r->initializeAllowlist($ids);
proof('initial list is established once with 38 active exact string records',$baseline['initial_count']===38&&(int)$db->query("SELECT COUNT(*) FROM student_enrollment_registry WHERE eligibility_status='active'")->fetchColumn()===38);
proof('older out-of-list IDs are disabled without deletion',$baseline['excluded']===1&&$r->entries(1,'PEN2098-880001')[0]['eligibility_status']==='inactive');
proof('old unapproved account is restricted while its password and account survive',$v->forUser($legacyId)['status']==='suspended'&&!$a->continuedAccess($legacyId)&&password_verify('QaStrong123!',$db->query('SELECT password_hash FROM users WHERE id='.$legacyId)->fetchColumn()));
proof('administrator access is exempt from student matching',$a->continuedAccess(1));
proof('unlisted original user is not automatically approved',!$a->continuedAccess(2));
$before=counts();
proof('registration service rejects a missing email without an account',refuses(fn()=>$v->register('qa_bad_email','',password_hash('QaStrong123!',PASSWORD_DEFAULT),['student_number'=>$ids[0]],fixtureVisitor($a)))&&counts()===$before);
proof('registration service rejects malformed email without an account',refuses(fn()=>$v->register('qa_bad_email','not-an-email',password_hash('QaStrong123!',PASSWORD_DEFAULT),['student_number'=>$ids[0]],fixtureVisitor($a)))&&counts()===$before);
proof('registration service cannot store a plaintext password',refuses(fn()=>$v->register('qa_bad_hash','hash-check@local.invalid','QaStrong123!',['student_number'=>$ids[0]],fixtureVisitor($a)))&&counts()===$before);
\App\Src\Support\StudentRegistrationForm::remember(['student_number'=>'PEN2090-00001','email'=>'safe@local.invalid','password'=>'never-retain','confirm_password'=>'never-retain','role'=>'admin'],['email'=>'Check email']);
proof('form feedback contains no password confirmation role or other arbitrary input',!str_contains(json_encode($_SESSION['registration_feedback']),'never-retain')&&!isset($_SESSION['registration_feedback']['values']['role']));
$feedback=\App\Src\Support\StudentRegistrationForm::consume();
proof('safe ID and email feedback are available exactly once',$feedback['values']['student_number']==='PEN2090-00001'&&$feedback['values']['email']==='safe@local.invalid'&&\App\Src\Support\StudentRegistrationForm::consume()['values']===[]);
\App\Src\Support\StudentRegistrationForm::remember(['email'=>'expired@local.invalid'],['email'=>'Check email']);$_SESSION['registration_feedback']['expires']=time()-1;
proof('expired form values are discarded rather than displayed',\App\Src\Support\StudentRegistrationForm::consume()['values']===[]);
$owners=[];
foreach($ids as $index=>$number){
 $row=$r->entries(1,$number)[0]??null;
 proof('initial ID '.($index+1).' is exact active and has preserved zeros',$row&&$row['normalized_student_number']===$number&&$row['eligibility_status']==='active');
 $user=signup($index%2===0?substr($number,3):strtolower($number),'qa_initial_'.($index+1),'initial-'.($index+1).'@local.invalid');$owner=(int)$user['id'];$owners[]=$owner;$app=$v->forUser($owner);
 proof('initial ID '.($index+1).' registers and approves without any secondary verification',$app['status']==='verified'&&$app['approval_method']==='registry_match'&&$a->continuedAccess($owner));
 $resolved=(new App\Src\Repositories\UserRepository($db))->findActiveByCredential($number);
 proof('initial ID '.($index+1).' resolves its same account and password',$resolved&&(int)$resolved['id']===$owner&&password_verify('QaStrong123!',$resolved['password_hash']));
}
$before=counts();proof('duplicate claimed ID rejects and rolls back its new account',refuses(fn()=>signup($ids[0]))&&counts()===$before);
// These requested negative cases must never be added to the trusted registry.
$negative=['PEN2023-99999','PEN2023-02042','PEN2024-02071','PEN2023-12345'];
foreach($negative as $index=>$number){$before=counts();proof('unimported negative ID '.($index+1).' rejects without an account',refuses(fn()=>signup($number))&&counts()===$before&&$r->entries(1,$number)===[]);}
proof('PEN prefix alone and malformed suffixes are rejected',refuses(fn()=>Verification::registrationNumber('PEN'))&&refuses(fn()=>Verification::registrationNumber('2023-'))&&refuses(fn()=>Verification::registrationNumber('PEN-2023-02071')));
proof('wrong PEN year is not treated as a known ID',refuses(fn()=>signup('2097-02071')));
proof('student names photos documents and OTP are not requested by validation',Verification::validateSubmission(['student_number'=>substr($ids[1],3)])['normalized']===$ids[1]);
proof('legacy manual review and resubmission methods cannot approve',refuses(fn()=>$v->review(1,$v->forUser($legacyId)['application_ref'],1,'accept',[]))&&refuses(fn()=>$v->resubmit($legacyId,['student_number'=>$ids[0]],1,fixtureVisitor($a))));
proof('ordinary users cannot manage imports or policy',refuses(fn()=>$r->preview($owners[0],inputStream('PEN2099-880002'),'txt'))&&refuses(fn()=>$r->reconcileAccounts($owners[0])));
$pending=fixtureLegacyPending($db,'qa_matching_pending','matching-pending@example.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),'PEN2099-880001',fixtureVisitor($a));$pendingId=(int)$pending['id'];
$extension=addIds('PEN2099-880001');
proof('new administrator import automatically reconciles a matching existing pending account',(int)$extension['approved_count']===1&&$a->continuedAccess($pendingId)&&$v->forUser($pendingId)['approval_method']==='registry_match');
$again=$r->initializeAllowlist($ids);
proof('repeat initialization preserves later authorized administrator additions',$again['already_initialized']&&$r->entries(1,'PEN2099-880001')[0]['eligibility_status']==='active'&&$a->continuedAccess($pendingId));
$entry=$r->entries(1,'PEN2099-880001')[0];$r->changeStatus(1,$entry['registry_ref'],(int)$entry['version'],'revoked','Synthetic administrator revocation');
proof('revoked registry ID blocks an existing session immediately',!$a->continuedAccess($pendingId));
$entry=$r->entries(1,'PEN2099-880001')[0];$r->changeStatus(1,$entry['registry_ref'],(int)$entry['version'],'active','Synthetic administrator reauthorization');
proof('explicit registry reauthorization restores the exact account without another identity step',$a->continuedAccess($pendingId));
addIds('PEN2099-880002');$entry=$r->entries(1,'PEN2099-880002')[0];$r->changeStatus(1,$entry['registry_ref'],(int)$entry['version'],'inactive','Synthetic inactive registration fixture');
proof('inactive listed ID rejects registration',refuses(fn()=>signup('PEN2099-880002')));
addIds('PEN2099-880003');$entry=$r->entries(1,'PEN2099-880003')[0];$db->prepare('UPDATE student_enrollment_registry SET eligible_until_epoch=? WHERE id=?')->execute([time()-1,$entry['id']]);
proof('expired listed ID rejects registration',refuses(fn()=>signup('PEN2099-880003')));
$large=[];for($i=0;$i<3000;$i++)$large[]='PEN2099-'.(900000+$i);
$bulk=addIds(implode("\n",$large));proof('3000 valid IDs import without duplicates',(int)$bulk['inserted_count']===3000);
$repeat=addIds(implode("\n",$large));proof('repeat bulk imports skip all existing IDs',(int)$repeat['inserted_count']===0&&(int)$repeat['skipped_count']===3000);
$preview=$r->preview(1,inputStream("PEN2099-880004\nPEN2099-880004\nBAD ID\n=1+1"),'txt');
proof('preview validates duplicates and invalid rows without approving IDs',$r->entries(1,'PEN2099-880004')===[]&&(int)$preview['duplicate_count']===1&&(int)$preview['invalid_count']===2);
$done=$r->confirm(1,$preview['batch_ref']);proof('confirmed preview inserts only valid unique rows',(int)$done['inserted_count']===1&&(int)$done['skipped_count']===1&&(int)$done['invalid_count']===2);
proof('reconfirming a committed batch is idempotent',(int)$r->confirm(1,$preview['batch_ref'])['inserted_count']===1);
proof('oversized and malformed binary imports are rejected',refuses(fn()=>$r->preview(1,inputStream(str_repeat('A',1048577)),'txt'))&&refuses(fn()=>$r->preview(1,inputStream("PEN2099-00001\0"),'txt')));
$fault=$r->preview(1,inputStream("PEN2099-880005\nPEN2099-880006"),'txt');
$db->exec("CREATE TRIGGER qa_allowlist_import_failure BEFORE INSERT ON student_enrollment_registry FOR EACH ROW BEGIN IF NEW.normalized_student_number='PEN2099-880006' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic atomic import failure'; END IF; END");
try{proof('mid-import failure rolls back every earlier ID in the batch',refuses(fn()=>$r->confirm(1,$fault['batch_ref']))&&$r->entries(1,'PEN2099-880005')===[]);}finally{$db->exec('DROP TRIGGER qa_allowlist_import_failure');}
addIds('PEN2099-880007');$children=[];
for($i=0;$i<8;$i++){$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/allowlist_race_worker.php','PEN2099-880007'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$process,$pipes];}
$out=[];foreach($children as [$process,$pipes]){$out[]=trim(stream_get_contents($pipes[1]));stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);proc_close($process);}
proof('eight concurrent registrations claim exactly one ID once',count(array_filter($out,fn($value)=>$value==='approved'))===1&&count(array_filter($out,fn($value)=>$value==='duplicate'))===7);
// Simulate conflicting old raw-ID claims without weakening schema constraints.
addIds('PEN2099-880008');$conflictA=fixtureLegacyPending($db,'qa_conflict_a','conflict-a@example.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),'PEN2099-880008',fixtureVisitor($a));
$conflictB=fixtureLegacyPending($db,'qa_conflict_b','conflict-b@example.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),'PEN2099-880009',fixtureVisitor($a));
$db->prepare('UPDATE student_applications SET student_number=?,normalized_student_number=NULL WHERE user_id=?')->execute(['PEN2099-880008',$conflictB['id']]);
$summary=$r->reconcileAccounts(1);
proof('conflicting historical claims approve neither account and preserve both',!$a->continuedAccess((int)$conflictA['id'])&&!$a->continuedAccess((int)$conflictB['id'])&&$summary['conflicts']>0&&(int)$r->entries(1,'PEN2099-880008')[0]['claim_conflict']===1);
proof('conflict flag blocks a third account from claiming the disputed ID',refuses(fn()=>signup('PEN2099-880008')));
$eligibleCount=(int)$db->query("SELECT COUNT(*) FROM student_enrollment_registry WHERE eligibility_status='active' AND claim_conflict=0 AND eligible_until_epoch>".time())->fetchColumn();
proof('active eligible statistics exclude disputed claims',$r->stats(1)['active']===$eligibleCount);
$token=fixtureVisitor($a);proof('guest starts with exactly three shared attempts',$a->status(null,$token)['remaining']===3);
$ticket=$a->reserve(null,$token,'allowlist-failed');$a->release($ticket);proof('failed processing does not consume an attempt',$a->status(null,$token)['remaining']===3);
for($i=0;$i<3;$i++){$ticket=$a->reserve(null,$token,'allowlist-success-'.$i);$db->beginTransaction();$a->complete($ticket);$db->commit();}
proof('fourth guest request is blocked while completed history remains readable',refuses(fn()=>$a->reserve(null,$token,'allowlist-fourth'))&&$a->status(null,$token)['remaining']===0);
$race=fixtureVisitor($a);$children=[];
for($i=0;$i<8;$i++){$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/race_worker.php','mysql',$race],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$process,$pipes];}
$out=[];foreach($children as [$process,$pipes]){$out[]=trim(stream_get_contents($pipes[1]));stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);proc_close($process);}
proof('eight concurrent guest requests complete at most exactly three',count(array_filter($out,fn($value)=>$value==='completed'))===3&&$a->status(null,$race)['remaining']===0);
$stats=$r->stats(1);proof('management statistics come from actual database state',$stats['accounts']>=38&&$stats['unclaimed']>0&&$stats['rejected']>=count($negative));
$q=$db->prepare('SELECT COUNT(*) FROM student_enrollment_registry WHERE normalized_student_number IN (?,?,?,?)');$q->execute($negative);proof('none of the requested negative IDs were ever imported',(int)$q->fetchColumn()===0);
proof('pre-existing original password hashes are unchanged',array_intersect_key($db->query('SELECT id,password_hash FROM users ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR),$original)===$original);
proof('pre-existing summary and feedback counts are unchanged',counts()['summaries']===$originalCounts['summaries']&&counts()['feedback']===$originalCounts['feedback']);
$failed=count(array_filter($checks,fn($row)=>!$row['passed']));$report=['passed'=>count($checks)-$failed,'failed'=>$failed,'initial_ids_tested'=>38,'checks'=>$checks];
file_put_contents((string)getenv('QA_REGISTRY_REPORT').'/backend-results.json',json_encode($report,JSON_PRETTY_PRINT));echo json_encode(['passed'=>$report['passed'],'failed'=>$failed,'initial_ids_tested'=>38]).PHP_EOL;exit($failed?1:0);
