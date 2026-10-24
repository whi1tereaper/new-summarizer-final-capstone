<?php
require __DIR__.'/test_bootstrap.php';
$db=fixtureConnection('mysql');injectFixture($db);
try{
 if(($argv[1]??'')==='confirm'){$r=(new App\Src\Services\StudentEnrollmentRegistryService($db))->confirm(1,$argv[2]);echo 'committed';}
 else{
  $v=new App\Src\Services\StudentVerificationService($db);$a=new App\Src\Services\CampusAccessService($db);$s=bin2hex(random_bytes(6));
  $u=$v->register('race_'.$s,$s.'@local.invalid','synthetic-hash', $v::validateSubmission(['student_number'=>$argv[2]]),fixtureVisitor($a));
  echo $a->continuedAccess((int)$u['id'])?'approved':'pending';
 }
}catch(PDOException $e){echo ($e->errorInfo[1]??0)===1062?'duplicate':'error';}catch(InvalidArgumentException $e){echo 'ineligible';}catch(Throwable $e){echo 'error';}
