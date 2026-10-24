<?php
require __DIR__.'/test_bootstrap.php';
$db=fixtureConnection('mysql');injectFixture($db);
try{
 $v=new App\Src\Services\StudentVerificationService($db);$a=new App\Src\Services\CampusAccessService($db);$suffix=bin2hex(random_bytes(6));
 $u=$v->register('claim_'.$suffix,$suffix.'@local.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),$v::validateSubmission(['student_number'=>$argv[1]??'']),fixtureVisitor($a));
 echo $a->continuedAccess((int)$u['id'])?'approved':'error';
}catch(InvalidArgumentException $e){echo $e->getMessage()===App\Src\Services\StudentVerificationService::UNAVAILABLE_ID?'duplicate':'ineligible';}catch(Throwable $e){echo 'error';exit(1);}
