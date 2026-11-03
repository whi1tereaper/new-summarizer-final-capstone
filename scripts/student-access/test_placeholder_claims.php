<?php
declare(strict_types=1);
require __DIR__.'/claim_fixture.php';
use App\Src\Services\StudentVerificationService as Verification;
use App\Src\Services\StudentEnrollmentRegistryService as Registry;
use App\Src\Services\CampusAccessService as Access;
$db=fixtureConnection('mysql');injectFixture($db);$v=new Verification($db);$r=new Registry($db);$a=new Access($db);$checks=[];$sequence=440000;
function proof(string $name,bool $ok): void{global $checks;$checks[]=['name'=>$name,'passed'=>$ok];echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;}
function refuses(callable $call): bool{try{$call();return false;}catch(Throwable $error){return true;}}
function nextId(): string{global $db,$sequence;$number='PEN2092-'.(++$sequence);claimImport($db,$number);return $number;}
function signup(string $number,?string $email=null): array{global $v,$a;return $v->register('chosen_'.bin2hex(random_bytes(5)),$email??bin2hex(random_bytes(5)).'@local.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),['student_number'=>$number],fixtureVisitor($a));}
function userCount(): int{global $db;return (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();}
function deniedIntact(string $name,string $number,int $id): void{global $db;$before=claimSnapshot($db,$id);$count=userCount();proof($name,refuses(fn()=>signup($number))&&claimSnapshot($db,$id)===$before&&userCount()===$count);}
$originalRegistry=$db->query('SELECT * FROM student_enrollment_registry ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
proof('all preexisting student records default conservatively to claimed',(int)$db->query("SELECT COUNT(*) FROM student_applications WHERE account_claim_state<>'claimed'")->fetchColumn()===0);
$db->exec(file_get_contents(__DIR__.'/../../database/migrations/021_student_placeholder_claims.sql'));
proof('additive migration 021 can replay without changing records',$db->query('SELECT * FROM student_enrollment_registry ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$originalRegistry);
proof('claim state database constraint rejects unknown states',refuses(fn()=>$db->exec("UPDATE student_applications SET account_claim_state='arbitrary' LIMIT 1")));
$number=nextId();$count=userCount();$created=signup(substr(strtolower($number),3),'New.Preferred@LOCAL.INVALID');$new=claimSnapshot($db,(int)$created['id']);
proof('Case A registry-only ID creates one immediately active account',userCount()===$count+1&&$new['user'][0]['active']===1&&$new['app'][0]['account_claim_state']==='claimed'&&$a->continuedAccess((int)$created['id']));
proof('Case A stores the chosen normalized email and secure password',$new['user'][0]['email']==='new.preferred@local.invalid'&&password_verify('QaStrong123!',$new['user'][0]['password_hash'])&&$new['user'][0]['password_hash']!=='QaStrong123!');
deniedIntact('Case C established new account cannot be claimed a second time',$number,(int)$created['id']);
$number=nextId();$id=claimFixture($db,$number,true);$before=claimSnapshot($db,$id);$count=userCount();$owner=signup('  '.strtolower($number).'  ','Student.Chosen@LOCAL.INVALID');$after=claimSnapshot($db,$id);
proof('Case B claims the existing user and application without duplicate records',(int)$owner['id']===$id&&userCount()===$count&&$before['app'][0]['id']===$after['app'][0]['id']&&$before['app'][0]['application_ref']===$after['app'][0]['application_ref']);
proof('placeholder claim preserves username and original account creation date',$before['user'][0]['username']===$after['user'][0]['username']&&$before['user'][0]['created_at']===$after['user'][0]['created_at']);
proof('placeholder claim stores student-chosen email hashed password and accepted terms',$after['user'][0]['email']==='student.chosen@local.invalid'&&password_verify('QaStrong123!',$after['user'][0]['password_hash'])&&$after['user'][0]['terms_accepted']===1);
proof('placeholder becomes active verified and linked to the approved registry',$after['user'][0]['active']===1&&$after['app'][0]['account_claim_state']==='claimed'&&$after['app'][0]['status']==='verified'&&$after['app'][0]['approval_method']==='registry_match'&&$after['app'][0]['registry_id']!==null&&$a->continuedAccess($id));
foreach(['summaries','artifacts','feedback','trial'] as $relationship)proof('placeholder claim preserves existing '.$relationship.' records byte-for-byte',$before[$relationship]!==[]&&$before[$relationship]===$after[$relationship]);
foreach([$number,'student.chosen@local.invalid',$after['user'][0]['username']] as $i=>$credential){$found=(new App\Src\Repositories\UserRepository($db))->findActiveByCredential($credential);proof('claimed account resolves by login credential '.($i+1),$found&&(int)$found['id']===$id&&password_verify('QaStrong123!',$found['password_hash']));}
$q=$db->prepare("SELECT detail FROM student_registry_events WHERE affected_user_id=? AND action='placeholder_claimed'");$q->execute([$id]);$events=$q->fetchAll(PDO::FETCH_COLUMN);
proof('successful placeholder claim records exactly one privacy-safe transactional audit',count($events)===1&&!preg_match('/PEN[0-9]|QaStrong|@local.invalid/',json_encode($events)));
deniedIntact('a successfully claimed placeholder cannot be reclaimed',$number,$id);
$number=nextId();$id=claimFixture($db,$number);$db->prepare("UPDATE student_applications SET account_claim_state='claimed' WHERE user_id=?")->execute([$id]);deniedIntact('empty credentials and inactive status alone do not establish an unclaimed placeholder',$number,$id);
$number=nextId();$id=claimFixture($db,$number);$db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash('SavedReal123!',PASSWORD_DEFAULT),$id]);deniedIntact('an unclaimed marker cannot overwrite any existing valid password',$number,$id);
$number=nextId();$id=claimFixture($db,$number);$db->prepare("UPDATE users SET password_hash='legacy-nonempty-credential' WHERE id=?")->execute([$id]);deniedIntact('malformed but nonempty legacy credentials are never treated as blank',$number,$id);
foreach(['active'=>"active=1",'accepted terms'=>"terms_accepted=1",'terms timestamp'=>"terms_accepted_at=CURRENT_TIMESTAMP",'administrator role'=>"role='admin'"] as $label=>$change){$number=nextId();$id=claimFixture($db,$number);$db->exec('UPDATE users SET '.$change.' WHERE id='.$id);deniedIntact('placeholder with '.$label.' cannot be claimed',$number,$id);}
$number=nextId();$id=claimFixture($db,$number);$db->prepare('UPDATE users SET active=1 WHERE id=?')->execute([$id]);$r->reconcileAccounts(1);
proof('reconciliation never approves even a wrongly active unclaimed record',claimSnapshot($db,$id)['app'][0]['status']==='pending'&&!$a->continuedAccess($id));
$q=$db->prepare('SELECT id FROM student_enrollment_registry WHERE normalized_student_number=?');$q->execute([$number]);$registryId=$q->fetchColumn();
$db->prepare("UPDATE student_applications SET status='verified',approval_method='registry_match',registry_id=?,expires_epoch=? WHERE user_id=?")->execute([$registryId,time()+86400,$id]);
proof('central access rejects an unclaimed marker even with stale active verified flags',!$a->continuedAccess($id));
foreach(['verified','declined','suspended','expired'] as $status){$number=nextId();$id=claimFixture($db,$number);$db->prepare('UPDATE student_applications SET status=?,expires_epoch=? WHERE user_id=?')->execute([$status,time()+86400,$id]);deniedIntact('historical '.$status.' account cannot be reopened as a placeholder',$number,$id);}
$number=nextId();$id=claimFixture($db,$number);$db->prepare("INSERT INTO user_tokens(user_id,selector,hashed_validator,expires) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 1 DAY))")->execute([$id,bin2hex(random_bytes(10)),hash('sha256','synthetic validator')]);deniedIntact('authentication token history blocks placeholder claiming',$number,$id);
$number=nextId();$id=claimFixture($db,$number);$db->prepare('INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 1 DAY))')->execute([$id,hash('sha256','synthetic reset')]);deniedIntact('password recovery history blocks placeholder claiming',$number,$id);
$number=nextId();$id=claimFixture($db,$number);$db->prepare("INSERT INTO student_registry_events(affected_user_id,action,detail,created_epoch) VALUES(?,'registration_created','Synthetic previous registration',?)")->execute([$id,time()]);deniedIntact('completed registration audit blocks placeholder claiming',$number,$id);
$number=nextId();$id=claimFixture($db,$number);$before=claimSnapshot($db,$id);$count=userCount();
proof('duplicate chosen email rolls back the entire placeholder claim',refuses(fn()=>signup($number,'EXISTING@EXAMPLE.INVALID'))&&claimSnapshot($db,$id)===$before&&userCount()===$count);
proof('invalid chosen email does not change the placeholder',refuses(fn()=>signup($number,'invalid-email'))&&claimSnapshot($db,$id)===$before);
proof('SQL injection in a Student ID does not alter the registry or placeholder',refuses(fn()=>signup($number."' OR 1=1 --"))&&claimSnapshot($db,$id)===$before);
$db->exec("CREATE TRIGGER qa_placeholder_failure BEFORE UPDATE ON student_applications FOR EACH ROW BEGIN IF OLD.user_id=$id AND NEW.account_claim_state='claimed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic claim failure'; END IF; END");
try{proof('failure after credentials update rolls back the original email password and activation',refuses(fn()=>signup($number))&&claimSnapshot($db,$id)===$before);}finally{$db->exec('DROP TRIGGER qa_placeholder_failure');}
proof('a rolled-back claim can safely retry on the exact same record',(int)signup($number)['id']===$id);
foreach(['inactive','revoked','expired'] as $status){$number=nextId();$id=claimFixture($db,$number);if($status==='expired')$db->prepare('UPDATE student_enrollment_registry SET eligible_until_epoch=? WHERE normalized_student_number=?')->execute([time()-1,$number]);else $db->prepare('UPDATE student_enrollment_registry SET eligibility_status=? WHERE normalized_student_number=?')->execute([$status,$number]);deniedIntact($status.' registry entry cannot activate a placeholder',$number,$id);}
$number='PEN2092-449999';$id=claimFixture($db,$number);$count=(int)$db->query('SELECT COUNT(*) FROM student_enrollment_registry')->fetchColumn();deniedIntact('Case D an unlisted placeholder cannot authorize itself',$number,$id);proof('rejected unlisted placeholder never inserts an approved registry entry',(int)$db->query('SELECT COUNT(*) FROM student_enrollment_registry')->fetchColumn()===$count);
$number=nextId();$id=claimFixture($db,$number,true);$db->prepare('UPDATE student_applications SET details_delete_epoch=? WHERE user_id=?')->execute([time()-1,$id]);
(new App\Src\Services\StudentRetentionService($db))->run(true,5000);$retained=claimSnapshot($db,$id)['app'][0];
proof('retention erases old visible details but retains the protected unclaimed owner identifier',$retained['student_number']===null&&$retained['normalized_student_number']===null&&$retained['student_number_fingerprint']!==null&&$retained['status']==='resubmission_required');
proof('a retained credential-free placeholder still claims the same account',(int)signup($number)['id']===$id&&$a->continuedAccess($id));
$number=nextId();$id=claimFixture($db,$number);$r->reconcileAccounts(1);$shell=claimSnapshot($db,$id);
proof('administrator reconciliation cannot activate or suspend an explicitly unclaimed inactive shell',$shell['app'][0]['status']==='pending'&&$shell['user'][0]['active']===0&&$shell['user'][0]['password_hash']==='');
proof('reconciliation leaves a safe shell claimable through normal registration',(int)signup($number)['id']===$id);
$number=nextId();$id=claimFixture($db,$number);$other=nextId();$otherId=claimFixture($db,$other);$db->prepare('UPDATE student_applications SET normalized_student_number=NULL,student_number=? WHERE user_id=?')->execute([$number,$otherId]);
deniedIntact('unreconciled conflicting historical raw-ID owners block a third claim',$number,$id);
proof('historical raw-ID conflict also preserves the other owner',$db->query('SELECT password_hash FROM users WHERE id='.$otherId)->fetchColumn()==='');
$number=nextId();$id=claimFixture($db,$number,true);$before=claimSnapshot($db,$id);$count=userCount();$children=[];
for($i=0;$i<8;$i++){$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/allowlist_race_worker.php',$number],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$children[]=[$process,$pipes];}
$out=[];foreach($children as [$process,$pipes]){$out[]=trim(stream_get_contents($pipes[1]));stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);proc_close($process);}
$after=claimSnapshot($db,$id);
proof('eight concurrent placeholder claims produce exactly one success and seven denials',count(array_filter($out,fn($s)=>$s==='approved'))===1&&count(array_filter($out,fn($s)=>$s==='duplicate'))===7);
proof('concurrent claiming retains the original user application and all history',userCount()===$count&&$before['app'][0]['id']===$after['app'][0]['id']&&$before['summaries']===$after['summaries']&&$before['feedback']===$after['feedback']&&$before['artifacts']===$after['artifacts']);
$q=$db->prepare("SELECT COUNT(*) FROM student_registry_events WHERE affected_user_id=? AND action='placeholder_claimed'");$q->execute([$id]);proof('concurrent claiming commits one audit event only',(int)$q->fetchColumn()===1);
// Keep a clean synthetic shell for the actual HTTP tests in this isolated run.
claimImport($db,'PEN2092-448001');claimFixture($db,'PEN2092-448001',true);
$failed=count(array_filter($checks,fn($c)=>!$c['passed']));$report=['passed'=>count($checks)-$failed,'failed'=>$failed,'checks'=>$checks];
file_put_contents((string)getenv('QA_REGISTRY_REPORT').'/placeholder-backend.json',json_encode($report,JSON_PRETTY_PRINT));echo json_encode(['passed'=>$report['passed'],'failed'=>$failed]).PHP_EOL;exit($failed?1:0);
