<?php
// CLI-only fixture controls. qaConnection denies production before any mutation.
require __DIR__.'/../student-access/test_bootstrap.php';
$db=fixtureConnection('mysql');injectFixture($db);$action=$argv[1]??'';
if($action==='prepare-browser'){
 $a=new App\Src\Services\CampusAccessService($db);$v=new App\Src\Services\StudentVerificationService($db);
 $u=fixtureLegacyPending($db,'qa_registry_pending','registry-pending@local.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),'WEB-000000',fixtureVisitor($a));
 echo json_encode(['pending'=>$v->forUser((int)$u['id'])['status']==='pending']);
}elseif($action==='prepare-legacy-activation'){
 $a=new App\Src\Services\CampusAccessService($db);
 $u=fixtureLegacyPending($db,'qa_legacy_activation','legacy-activation@local.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),'QA-ACT-LEGACY',fixtureVisitor($a));
 echo json_encode(['id'=>(int)$u['id']]);
}elseif($action==='prepare-legacy-workflow'){
 $a=new App\Src\Services\CampusAccessService($db);
 fixtureLegacyPending($db,'qa_legacy_workflow','legacy-workflow@local.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),'PEN2023-02071',fixtureVisitor($a));
 echo 'created historical fixture';
}elseif($action==='set-test-eligibility'){
 $number=App\Src\Services\StudentVerificationService::normalizeNumber($argv[2]??'');$status=$argv[3]??'';
 $r=new App\Src\Services\StudentEnrollmentRegistryService($db);$entry=$r->entries(1,$number)[0]??null;
 if(!$entry||!in_array($status,['inactive','revoked','expired'],true))exit(1);
 if($status==='expired')$db->prepare('UPDATE student_enrollment_registry SET eligible_until_epoch=? WHERE id=?')->execute([time()-1,$entry['id']]);
 else $r->changeStatus(1,$entry['registry_ref'],(int)$entry['version'],$status,'Synthetic isolated eligibility fixture');
 echo 'updated isolated test eligibility';
}elseif($action==='reset-test-rate-limits'){
 $db->exec("DELETE FROM rate_limits WHERE action_type IN ('register','registry_import','registry_status','login')");echo 'reset isolated test counters';
}elseif($action==='inspect'){
 $number=$argv[2]??'';$fp=App\Src\Services\StudentVerificationService::fingerprint(App\Src\Services\StudentVerificationService::normalizeNumber($number));
 $q=$db->prepare('SELECT a.status,a.approval_method,a.user_id,a.registry_id,a.version FROM student_applications a WHERE student_number_fingerprint=?');$q->execute([$fp]);
 $r=$db->prepare('SELECT eligibility_status FROM student_enrollment_registry WHERE student_number_fingerprint=?');$r->execute([$fp]);
 $counts=[];foreach(['users','student_applications','student_review_events','student_trial_links'] as $table)$counts[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
 echo json_encode(['application'=>$q->fetch(PDO::FETCH_ASSOC),'registry'=>$r->fetchColumn(),'counts'=>$counts,'web_registry_count'=>(int)$db->query("SELECT COUNT(*) FROM student_enrollment_registry WHERE normalized_student_number LIKE 'WEB-%'")->fetchColumn()]);
}elseif($action==='verify-summary'){
 $q=$db->prepare('SELECT COUNT(*) FROM summaries WHERE article_title=? AND user_id IS NOT NULL');$q->execute([$argv[2]??'']);echo json_encode(['stored'=>(int)$q->fetchColumn()]);
}else exit(1);
