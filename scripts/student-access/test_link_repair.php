<?php
declare(strict_types=1);
require __DIR__.'/test_bootstrap.php';
require __DIR__.'/../../tools/repair_student_id_link.php';
use App\Src\Services\StudentVerificationService as Verification;
use App\Src\Services\StudentEnrollmentRegistryService as Registry;
use App\Src\Services\CampusAccessService as Access;
$db=fixtureConnection('mysql');injectFixture($db);$v=new Verification($db);$r=new Registry($db);$a=new Access($db);$checks=[];
$private=(string)getenv('QA_PRIVATE_DIR').'/link-repair-backups';if(!is_dir($private))mkdir($private,0700);
function proof(string $name,bool $ok):void{global $checks;$checks[]=['name'=>$name,'passed'=>$ok];echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;}
function refused(callable $fn):bool{try{$fn();return false;}catch(Throwable $e){return true;}}
function repairFixture(string $tag):array {
 global $db,$v,$r,$a;
 // Explicitly synthetic identifiers, never a campus enrollment roster.
 $old='PEN-QA-LINK-'.$tag;$number='PENQA-LINK-'.$tag;$suffix=strtolower($tag).'_'.bin2hex(random_bytes(3));
 $user=fixtureLegacyPending($db,'link_'.$suffix,$suffix.'@local.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),$old,fixtureVisitor($a));
 $stream=fopen('php://temp','w+');fwrite($stream,$number);rewind($stream);$r->import(1,$stream,'paste');fclose($stream);
 return ['user_id'=>(int)$user['id'],'expected_version'=>1,'old_student_number'=>$old,'student_number'=>$number,'operator_approved'=>true,'reason'=>'Synthetic explicitly approved operator correction'];
}
function repairCounts():array{global $db;$out=[];foreach(['users','summaries','feedback','student_review_events','student_registry_events'] as $table)$out[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();return $out;}
$input=repairFixture('PRIMARY');$user=$input['user_id'];$original=$v->forUser($user);$counts=repairCounts();
$q=$db->prepare('SELECT * FROM users WHERE id=?');$q->execute([$user]);$originalUser=$q->fetch(PDO::FETCH_ASSOC);
$originalApp=$db->query('SELECT * FROM student_applications WHERE user_id='.$user)->fetch(PDO::FETCH_ASSOC);
$repository=new App\Src\Repositories\UserRepository($db);
proof('legacy separator mismatch reproduces correct-ID account lookup failure',$repository->findActiveByCredential($input['student_number'])===null&&$repository->findActiveByCredential($input['old_student_number'])!==null&&!$a->continuedAccess($user));
$plan=repairStudentIdLink($db,$input);
proof('dry run verifies eligibility without altering the application',$plan['dry_run']&&!$plan['changed']&&$v->forUser($user)===$original&&repairCounts()===$counts);
proof('explicit operator approval is required',refused(fn()=>repairStudentIdLink($db,array_replace($input,['operator_approved'=>false]),true,$private)));
proof('maintenance reason is required',refused(fn()=>repairStudentIdLink($db,array_replace($input,['reason'=>'']),true,$private)));
proof('arbitrary ID replacements are rejected',refused(fn()=>repairStudentIdLink($db,array_replace($input,['student_number'=>'PENQA-LINK-OTHER']),true,$private)));
proof('stale version cannot overwrite an application',refused(fn()=>repairStudentIdLink($db,array_replace($input,['expected_version'=>2]),true,$private)));
proof('apply requires a private pre-change backup',refused(fn()=>repairStudentIdLink($db,$input,true)));
proof('backup directory inside the web tree is rejected',refused(fn()=>repairStudentIdLink($db,$input,true,dirname(__DIR__,2).'/storage')));
$db->prepare('UPDATE users SET active=0 WHERE id=?')->execute([$user]);
proof('inactive account cannot be repaired',refused(fn()=>repairStudentIdLink($db,$input,true,$private)));
$db->prepare('UPDATE users SET active=1,role=? WHERE id=?')->execute(['admin',$user]);
proof('administrator account cannot be repaired as a student',refused(fn()=>repairStudentIdLink($db,$input,true,$private)));
$db->prepare('UPDATE users SET role=? WHERE id=?')->execute(['user',$user]);
$entry=$r->entries(1,$input['student_number'])[0];$r->changeStatus(1,$entry['registry_ref'],(int)$entry['version'],'inactive','Synthetic inactive repair target');
proof('inactive imported ID cannot activate an account',refused(fn()=>repairStudentIdLink($db,$input,true,$private)));
$entry=$r->entries(1,$input['student_number'])[0];$r->changeStatus(1,$entry['registry_ref'],(int)$entry['version'],'active','Synthetic eligible repair target');
$db->prepare('UPDATE student_enrollment_registry SET eligible_until_epoch=? WHERE id=?')->execute([time()-1,$entry['id']]);
proof('expired imported ID cannot activate an account',refused(fn()=>repairStudentIdLink($db,$input,true,$private)));
$db->prepare('UPDATE student_enrollment_registry SET eligible_until_epoch=? WHERE id=?')->execute([Registry::PERMANENT_EPOCH,$entry['id']]);
$claimed=repairFixture('CLAIMED');$owner=$v->register('repair_other_owner','repair-other-owner@local.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),$v::validateSubmission(['student_number'=>$claimed['student_number']]),fixtureVisitor($a));
proof('another students claimed ID cannot be transferred',refused(fn()=>repairStudentIdLink($db,$claimed,true,$private))&&$v->forUser($claimed['user_id'])['status']==='pending'&&$a->continuedAccess((int)$owner['id']));
$baseline=repairCounts();
$db->exec("CREATE TRIGGER qa_link_repair_audit_failure BEFORE INSERT ON student_registry_events FOR EACH ROW BEGIN IF NEW.action='operator_id_correction' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic repair audit failure'; END IF; END");
try{proof('late audit failure rolls back link approval notifications and audit rows',refused(fn()=>repairStudentIdLink($db,$input,true,$private))&&$v->forUser($user)===$original&&repairCounts()===$baseline);}finally{$db->exec('DROP TRIGGER qa_link_repair_audit_failure');}
$result=repairStudentIdLink($db,$input,true,$private);
proof('operator repair uses the real registry approval path',$result['changed']&&$result['status']==='verified'&&$result['approval_method']==='registry_match'&&$a->continuedAccess($user));
$lookup=$repository->findActiveByCredential($input['student_number']);
proof('corrected student ID resolves the same existing account',$lookup&&(int)$lookup['id']===$user&&$repository->findActiveByCredential($input['old_student_number'])===null);
proof('existing password still verifies after repair',$lookup&&password_verify('QaStrong123!',$lookup['password_hash']));
$q->execute([$user]);proof('username email password role and all account fields are unchanged',$q->fetch(PDO::FETCH_ASSOC)===$originalUser);
$after=repairCounts();proof('no user summary or feedback is created deleted or replaced',array_intersect_key($after,array_flip(['users','summaries','feedback']))===array_intersect_key($baseline,array_flip(['users','summaries','feedback'])));
$backup=json_decode(file_get_contents($result['backup']),true,512,JSON_THROW_ON_ERROR);
proof('private pre-change application backup is exact and hash verified',$backup['application']===$originalApp&&hash_file('sha256',$result['backup'])===$result['backup_sha256']);
$qAudit=$db->prepare("SELECT actor_user_id,detail FROM student_registry_events WHERE affected_user_id=? AND action='operator_id_correction'");$qAudit->execute([$user]);$audit=$qAudit->fetch(PDO::FETCH_ASSOC);
proof('audit records local operator provenance without impersonating an admin',$audit&&$audit['actor_user_id']===null&&str_contains($audit['detail'],'operator_authorized_cli')&&!str_contains($audit['detail'],$input['old_student_number'])&&!str_contains($audit['detail'],$input['student_number']));
$events=$v->history((int)$original['id'],$user);proof('correction and verification notifications are retained on the same account',count(array_filter($events,fn($event)=>in_array($event['action'],['operator_id_correction','registry_match'],true)))===2);
$again=repairStudentIdLink($db,$input,true,$private);
proof('repeated repair is idempotent and does not duplicate audit events',!$again['changed']&&$again['already_repaired']&&repairCounts()===$after);
$failed=count(array_filter($checks,fn($c)=>!$c['passed']));$report=['passed'=>count($checks)-$failed,'failed'=>$failed,'checks'=>$checks];
file_put_contents((string)getenv('QA_REGISTRY_REPORT').'/link-repair-results.json',json_encode($report,JSON_PRETTY_PRINT));echo json_encode($report).PHP_EOL;exit($failed?1:0);
