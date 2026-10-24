<?php
require __DIR__.'/../student-access/test_bootstrap.php';
$db=fixtureConnection('mysql');injectFixture($db);$action=$argv[1]??'';
if($action==='reset-limits'){
 $db->exec("DELETE FROM rate_limits WHERE action_type IN ('register','registry_import','registry_status','login')");echo 'isolated counters reset';
}elseif($action==='inspect'){
 $number=App\Src\Services\StudentVerificationService::normalizeNumber($argv[2]??'');
 $q=$db->prepare('SELECT r.eligibility_status,r.claim_conflict,a.user_id,a.status,a.approval_method,u.role,u.email,u.active,u.password_hash FROM student_enrollment_registry r LEFT JOIN student_applications a ON a.registry_id=r.id LEFT JOIN users u ON u.id=a.user_id WHERE r.normalized_student_number=?');$q->execute([$number]);$record=$q->fetch(PDO::FETCH_ASSOC);
 if($record){$record['password_hashed']=is_string($record['password_hash'])&&password_get_info($record['password_hash'])['algoName']!=='unknown';$record['password_valid']=is_string($record['password_hash'])&&password_verify('QaStrong123!',$record['password_hash']);unset($record['password_hash']);}
 echo json_encode(['record'=>$record,'users'=>(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()]);
}elseif($action==='stats'){
 echo json_encode((new App\Src\Services\StudentEnrollmentRegistryService($db))->stats(1));
}elseif($action==='events'){
 $rows=$db->query("SELECT action,detail FROM student_registry_events WHERE action IN ('registration_created','registration_rejected')")->fetchAll(PDO::FETCH_ASSOC);
 echo json_encode(['created'=>count(array_filter($rows,fn($r)=>$r['action']==='registration_created')),'rejected'=>count(array_filter($rows,fn($r)=>$r['action']==='registration_rejected')),'safe'=>!preg_match('/PEN[0-9]{4}-[0-9]+|QaStrong|@local.invalid/',json_encode($rows))]);
}elseif($action==='fault-on'){
 $db->exec("CREATE TRIGGER qa_registration_failure BEFORE INSERT ON student_applications FOR EACH ROW BEGIN IF NEW.normalized_student_number='PEN2095-660004' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic registration transaction failure'; END IF; END");echo 'enabled';
}elseif($action==='fault-off'){
 $db->exec('DROP TRIGGER IF EXISTS qa_registration_failure');echo 'disabled';
}elseif($action==='summary'){
 $q=$db->prepare('SELECT id,user_id,article_title,status,summary_word_count FROM summaries WHERE id=?');$q->execute([(int)($argv[2]??0)]);echo json_encode($q->fetch(PDO::FETCH_ASSOC));
}elseif($action==='counts'){
 $counts=[];foreach(['users','summaries','feedback','student_applications','student_enrollment_registry'] as $table)$counts[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();echo json_encode($counts);
}else exit(1);
