<?php
declare(strict_types=1);
// Read-only incident evidence: no email, password/token hash, IP or reset code.
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../../app/src/Support/config.php';
require_once __DIR__.'/../../app/src/Database.php';
$db=App\Src\Database::getConfiguredMySqlConnection();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='ai_summarizer'||(int)config('database.port')!==3307)throw new RuntimeException('Expected the local live schema.');
$id=141;$q=$db->prepare('SELECT id,expires_at,used_at FROM password_resets WHERE user_id=? ORDER BY id DESC LIMIT 5');$q->execute([$id]);$resets=$q->fetchAll(PDO::FETCH_ASSOC);
$q=$db->prepare('SELECT action,created_epoch FROM student_registry_events WHERE affected_user_id=? ORDER BY id DESC LIMIT 5');$q->execute([$id]);$events=$q->fetchAll(PDO::FETCH_ASSOC);
$logging=[];foreach(['general_log','log_bin'] as $name){$q=$db->query("SHOW VARIABLES LIKE ".$db->quote($name));$row=$q->fetch(PDO::FETCH_ASSOC);$logging[$name]=$row['Value']??null;}
$http=[];$log='C:/xampp/apache/logs/ai-summarizer-access.log';
foreach(is_file($log)?file($log,FILE_IGNORE_NEW_LINES):[] as $line){
    if(preg_match('~\[(24/Oct/2026:09:1[01]:[0-9]{2} [^\]]+)\] "(GET|POST) ([^ ]+)~',$line,$m)&&preg_match('~forgot_password|reset_password|action=(forgotPassword|resetPassword)~',$m[3])){
        $action=null;if(preg_match('~action=(forgotPassword|resetPassword)~',$m[3],$a))$action=$a[1];
        $http[]=['time'=>$m[1],'method'=>$m[2],'path'=>strtok($m[3],'?'),'action'=>$action];
    }
}
$report=['read_only'=>true,'user_id'=>$id,'recent_reset_metadata_without_tokens'=>$resets,'recent_student_actions'=>$events,'matching_live_http_without_client_identifiers'=>$http,'database_logging'=>$logging,'changed_password_attribution'=>'A matching live recovery request was observed; actor authorization is not established. The operator reports no known password change. No live credential has been reset or restored by this task.'];
$json=json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;file_put_contents(__DIR__.'/../../docs/qa/student-account-claim/live-password-change-audit.json',$json);echo $json;
