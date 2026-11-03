<?php
declare(strict_types=1);
require __DIR__.'/isolation.php';
require_once __DIR__.'/../../app/src/Support/config.php';
require_once __DIR__.'/../../app/src/Services/StudentEnrollmentRegistryService.php';
$db=qaConnection();$action=$argv[1]??'';
if($action==='setup'){
 $stream=fopen('php://temp','w+');fwrite($stream,'PEN2093-550001');rewind($stream);
 (new App\Src\Services\StudentEnrollmentRegistryService($db))->import(1,$stream,'txt');fclose($stream);echo json_encode(['eligible'=>true]);
}elseif($action==='limits'){
 $db->exec("DELETE FROM rate_limits WHERE action_type IN ('reset_request','otp_verify','login')");echo '{}';
}elseif($action==='flash'){
 $id=$argv[2]??'';if(!preg_match('/\A[a-zA-Z0-9,-]{16,128}\z/',$id))throw new RuntimeException('Invalid QA session');
 session_save_path((string)getenv('QA_PRIVATE_DIR'));session_id($id);session_start();
 $sent=str_contains((string)($_SESSION['success']??''),'a 6-digit recovery code was sent');session_write_close();echo json_encode(['says_sent'=>$sent]);
}else{
 $email=$argv[2]??'new-student@local.invalid';
 if(!in_array($email,['new-student@local.invalid','existing@example.invalid'],true))throw new RuntimeException('Only synthetic QA accounts are supported.');
 $q=$db->prepare('SELECT id,password_hash,active FROM users WHERE email=?');$q->execute([$email]);$user=$q->fetch(PDO::FETCH_ASSOC);
 if(!$user)throw new RuntimeException('QA account not found');
 if($action==='expire'){$db->prepare('UPDATE password_resets SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE user_id=? AND used_at IS NULL')->execute([$user['id']]);echo '{}';exit;}
 $q=$db->prepare('SELECT token_hash,used_at,TIMESTAMPDIFF(SECOND,NOW(),expires_at) AS seconds_remaining FROM password_resets WHERE user_id=? ORDER BY id DESC LIMIT 1');$q->execute([$user['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
 echo json_encode(['active'=>(bool)$user['active'],'password_original_valid'=>password_verify('QaStrong123!',$user['password_hash']),'password_new_valid'=>password_verify('QAResetStrong456!',$user['password_hash']),'token'=>$row?['hashed'=>password_get_info($row['token_hash'])['algoName']!=='unknown','used'=>$row['used_at']!==null,'seconds_remaining'=>(int)$row['seconds_remaining']]:null,'reset_rows'=>(int)$db->query('SELECT COUNT(*) FROM password_resets')->fetchColumn()]);
}
