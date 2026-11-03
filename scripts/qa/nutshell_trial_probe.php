<?php
declare(strict_types=1);
require __DIR__.'/../student-access/test_bootstrap.php';
$db=fixtureConnection('mysql');injectFixture($db);$action=$argv[1]??'';
if($action==='generation'){
    $q=$db->prepare('SELECT id,user_id,guest_token,status,nutshell_text FROM nutshell_generations WHERE id=?');$q->execute([(int)$argv[2]]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if($r){$r['guest_owned']=$r['user_id']===null&&is_string($r['guest_token'])&&strlen($r['guest_token'])===64;unset($r['guest_token']);}echo json_encode($r);exit;
}
if($action==='history'){
    $q=$db->prepare('SELECT guest_token FROM nutshell_generations WHERE id=?');$q->execute([(int)$argv[2]]);$guest=$q->fetchColumn();
    $rows=(new App\Src\Repositories\HistoryRepository($db))->findNutshellHistory(null,is_string($guest)?$guest:null,50);
    echo json_encode(['ids'=>array_map('intval',array_column($rows,'id'))]);exit;
}
if(in_array($action,['hold-request','release-request'],true)){
    $q=$db->prepare('SELECT guest_token FROM nutshell_generations WHERE id=? AND user_id IS NULL');$q->execute([(int)$argv[2]]);$guest=$q->fetchColumn();
    if(!is_string($guest)||strlen($guest)!==64||!preg_match('/\A[a-f0-9]{64}\z/',$argv[3]??'')||!preg_match('/\A[a-f0-9]{32}\z/',$argv[4]??''))exit(1);
    $reference='nutshell:'.hash('sha256','guest:'.$guest.':standalone:'.$argv[4]);$access=new App\Src\Services\CampusAccessService($db);
    if($action==='hold-request')$access->reserve(null,$argv[3],$reference);else $access->releaseRequest($reference);
    echo '{}';exit;
}
if($action==='summary'){
    $q=$db->prepare('SELECT user_id,guest_token FROM nutshell_generations WHERE id=?');$q->execute([(int)$argv[2]]);$owner=$q->fetch(PDO::FETCH_ASSOC);if(!$owner)exit(1);
    $db->prepare("INSERT INTO summaries(user_id,guest_token,share_token,article_title,input_type,status) VALUES(?,?,?,'Synthetic guest Nutshell source','text','completed')")->execute([$owner['user_id'],$owner['guest_token'],bin2hex(random_bytes(32))]);$id=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO summary_artifacts(summary_id,original_text,generated_summary) VALUES(?,?,?)')->execute([$id,str_repeat('University libraries provide students with reliable academic research resources and learning support. ',30),str_repeat('Academic learning benefits from accessible library resources and student support. ',8)]);echo json_encode(['id'=>$id]);exit;
}
if($action==='verified-user'){
    require __DIR__.'/../student-access/claim_fixture.php';claimImport($db,'PEN2094-770001');
    $v=new App\Src\Services\StudentVerificationService($db);$a=new App\Src\Services\CampusAccessService($db);
    $user=$v->register('qa_nutshell_verified','nutshell-verified@local.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),['student_number'=>'PEN2094-770001'],fixtureVisitor($a));echo json_encode(['id'=>$user['id']]);exit;
}
if($action==='expire'){
    $q=$db->prepare('SELECT guest_token FROM nutshell_generations WHERE id=?');$q->execute([(int)$argv[2]]);$token=$q->fetchColumn();if(!is_string($token)||strlen($token)!==64)exit(1);
    $db->prepare('UPDATE guest_sessions SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE guest_token=?')->execute([$token]);
    (new App\Src\Services\GuestSessionService())->purgeExpiredGuests();echo json_encode(['expired'=>true]);exit;
}
if($action==='fault-on'){$db->exec("CREATE TRIGGER qa_nutshell_persist_failure BEFORE INSERT ON nutshell_generations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic Nutshell persistence failure'");echo '{}';exit;}
if($action==='fault-off'){$db->exec('DROP TRIGGER IF EXISTS qa_nutshell_persist_failure');echo '{}';exit;}
$token=$argv[2]??'';if(!preg_match('/\A[a-f0-9]{64}\z/',$token))exit(1);
$key=hash('sha256','visitor:'.$token);
if($action==='complete-regular'){
    $access=new App\Src\Services\CampusAccessService($db);$ticket=$access->reserve(null,$token,'qa:ordinary-summary:'.bin2hex(random_bytes(8)));
    $db->beginTransaction();$access->complete($ticket);$db->commit();echo '{}';exit;
}
if($action==='limit'){$db->prepare("INSERT INTO rate_limits(action_type,identifier,attempts,window_start) VALUES('nutshell',?,10,NOW()) ON DUPLICATE KEY UPDATE attempts=10,window_start=NOW()")->execute(['guest:'.hash('sha256',$token)]);echo '{}';exit;}
if($action==='status'){
    $q=$db->prepare('SELECT completed_count FROM summary_access_subjects WHERE subject_key=?');$q->execute([$key]);$completed=$q->fetchColumn();
    $q=$db->prepare("SELECT COUNT(*) FROM summary_access_reservations WHERE subject_key=? AND state='reserved' AND expires_epoch>?");$q->execute([$key,time()]);
    echo json_encode(['completed'=>(int)$completed,'reserved'=>(int)$q->fetchColumn()]);exit;
}
exit(1);
