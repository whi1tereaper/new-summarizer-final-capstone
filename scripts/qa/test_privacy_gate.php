<?php
require __DIR__.'/isolation.php';
$checks=[];$valid=base64_encode(random_bytes(32));
$cases=[
 ['all three prerequisites permit synthetic test registration',['STUDENT_PRIVACY_CONTACT'=>'qa@example.invalid','STUDENT_NUMBER_SECRET'=>$valid,'STUDENT_ENROLLMENT_AUTHORIZED'=>'true'],true],
 ['explicit processing hold blocks registration',['STUDENT_PRIVACY_CONTACT'=>'qa@example.invalid','STUDENT_NUMBER_SECRET'=>$valid,'STUDENT_ENROLLMENT_AUTHORIZED'=>'false'],false],
 ['invalid authorization value fails closed',['STUDENT_PRIVACY_CONTACT'=>'qa@example.invalid','STUDENT_NUMBER_SECRET'=>$valid,'STUDENT_ENROLLMENT_AUTHORIZED'=>'not-authorized'],false],
 // Windows removes zero-length process variables; whitespace keeps an explicit blank override.
 ['blank key cannot be bypassed by authorization',['STUDENT_PRIVACY_CONTACT'=>'qa@example.invalid','STUDENT_NUMBER_SECRET'=>' ','STUDENT_ENROLLMENT_AUTHORIZED'=>'true'],false],
 ['short key cannot be bypassed by authorization',['STUDENT_PRIVACY_CONTACT'=>'qa@example.invalid','STUDENT_NUMBER_SECRET'=>base64_encode('short'),'STUDENT_ENROLLMENT_AUTHORIZED'=>'true'],false],
 ['invalid privacy email blocks registration',['STUDENT_PRIVACY_CONTACT'=>'not-an-email','STUDENT_NUMBER_SECRET'=>$valid,'STUDENT_ENROLLMENT_AUTHORIZED'=>'true'],false],
 ['blank privacy override uses configured project email',['STUDENT_PRIVACY_CONTACT'=>'','SMTP_FROM'=>'project-admin@example.invalid','STUDENT_NUMBER_SECRET'=>$valid,'STUDENT_ENROLLMENT_AUTHORIZED'=>'true'],true],
 ['blank project and override email blocks registration',['STUDENT_PRIVACY_CONTACT'=>' ','SMTP_FROM'=>' ','STUDENT_NUMBER_SECRET'=>$valid,'STUDENT_ENROLLMENT_AUTHORIZED'=>'true'],false],
];
foreach($cases as [$name,$overrides,$open]){
 $code='require "app/src/Support/config.php"; require "app/src/Services/StudentVerificationService.php"; echo json_encode(["open"=>App\\Src\\Services\\StudentVerificationService::configurationError()===null,"email"=>config("student_access.privacy_contact")]);';
 $pipes=[];$p=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,2),array_merge(getenv(),$overrides));
 $result=json_decode(stream_get_contents($pipes[1]),true);stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);$exit=proc_close($p);
 $ok=$exit===0&&($result['open']??null)===$open;
 if($name==='blank privacy override uses configured project email')$ok=$ok&&($result['email']??'')==='project-admin@example.invalid';
 $checks[]=['name'=>$name,'passed'=>$ok];echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;
}
$failed=count(array_filter($checks,fn($c)=>!$c['passed']));file_put_contents(__DIR__.'/../../docs/qa/privacy-gate-results.json',json_encode(['checks'=>$checks,'passed'=>count($checks)-$failed,'failed'=>$failed],JSON_PRETTY_PRINT));exit($failed?1:0);
