<?php
declare(strict_types=1);
// CLI-only visual fixture. Restricted QA credentials must be denied live DB access.
require __DIR__.'/../student-access/test_bootstrap.php';
$db=fixtureConnection('mysql');injectFixture($db);
$registry=new App\Src\Services\StudentEnrollmentRegistryService($db);
$verification=new App\Src\Services\StudentVerificationService($db);
$access=new App\Src\Services\CampusAccessService($db);
$numbers=array_map(static fn($n)=>'PEN2099-'.(930000+$n),range(1,38));
$stream=fopen('php://temp','w+');fwrite($stream,implode("\n",$numbers));rewind($stream);
$preview=$registry->preview(1,$stream,'paste');fclose($stream);$registry->confirm(1,$preview['batch_ref']);
for($i=0;$i<8;$i++)$verification->register('qa_ui_student_'.$i,'ui-student-'.$i.'@example.invalid',password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT),['student_number'=>$numbers[$i]],fixtureVisitor($access));
for($i=0;$i<18;$i++)$registry->recordRegistrationRejection(null,'validation_failed');
$sessionDirectory=realpath((string)getenv('QA_UI_SESSION_DIR'));
if(!$sessionDirectory||str_contains(strtolower(str_replace('\\','/',$sessionDirectory)),'/htdocs/'))throw new RuntimeException('Private QA session directory required.');
session_save_path($sessionDirectory);session_name((string)getenv('SESSION_NAME'));
$sessions=[];
foreach(['admin'=>1,'user'=>2] as $role=>$id){
    session_id(bin2hex(random_bytes(24)));session_start();
    $_SESSION=['user_id'=>$id,'role'=>$role,'username'=>'qa_'.$role,'terms_accepted'=>1,'terms_accepted_at'=>date('Y-m-d H:i:s'),'csrf_token'=>bin2hex(random_bytes(32))];
    $sessions[$role]=session_id();session_write_close();
}
echo json_encode(['sessions'=>$sessions,'stats'=>$registry->stats(1)]);
