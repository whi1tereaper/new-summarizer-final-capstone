<?php
declare(strict_types=1);
require_once __DIR__.'/test_bootstrap.php';

/** Synthetic fixtures only; qaConnection denies access to the live schema. */
function claimFixture(PDO $db,string $number,bool $history=false): int {
    qaConnection();
    $access=new App\Src\Services\CampusAccessService($db);
    $user=fixtureLegacyPending($db,'qa_shell_'.substr($number,3),'shell-'.substr($number,3).'@local.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),$number,fixtureVisitor($access));
    $id=(int)$user['id'];
    $db->prepare("UPDATE users SET active=0,password_hash='',terms_accepted=0,terms_accepted_at=NULL WHERE id=?")->execute([$id]);
    $db->prepare("UPDATE student_applications SET account_claim_state='unclaimed' WHERE user_id=?")->execute([$id]);
    if($history){
        $db->prepare("INSERT INTO summaries(user_id,share_token,article_title,input_type,summary_style,status) VALUES(?,?,?,'text','standard_paragraph','completed')")->execute([$id,bin2hex(random_bytes(32)),'Synthetic preserved placeholder history']);
        $summary=(int)$db->lastInsertId();
        $db->prepare('INSERT INTO summary_artifacts(summary_id,original_text,generated_summary) VALUES(?,?,?)')->execute([$summary,'Synthetic source retained across account claim.','Synthetic retained summary.']);
        $db->prepare("INSERT INTO feedback(summary_id,user_id,rating,comment) VALUES(?,?,4,'Synthetic feedback retained across account claim')")->execute([$summary,$id]);
    }
    return $id;
}
function claimSnapshot(PDO $db,int $id): array {
    $out=[];
    foreach(['user'=>'SELECT * FROM users WHERE id=?','app'=>'SELECT * FROM student_applications WHERE user_id=?','summaries'=>'SELECT * FROM summaries WHERE user_id=? ORDER BY id','feedback'=>'SELECT * FROM feedback WHERE user_id=? ORDER BY id','artifacts'=>'SELECT a.* FROM summary_artifacts a JOIN summaries s ON s.id=a.summary_id WHERE s.user_id=? ORDER BY a.id','trial'=>'SELECT * FROM student_trial_links WHERE user_id=?'] as $name=>$sql){
        $q=$db->prepare($sql);$q->execute([$id]);$out[$name]=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    return $out;
}
function claimImport(PDO $db,string $number): void {
    $stream=fopen('php://temp','w+');fwrite($stream,$number);rewind($stream);
    try{(new App\Src\Services\StudentEnrollmentRegistryService($db))->import(1,$stream,'txt');}finally{fclose($stream);}
}
