<?php
require __DIR__.'/../student-access/test_bootstrap.php';
$db=fixtureConnection('mysql');injectFixture($db);
$r=new App\Src\Services\StudentEnrollmentRegistryService($db);$v=new App\Src\Services\StudentVerificationService($db);$a=new App\Src\Services\CampusAccessService($db);$checks=[];
$check=function(string $name,bool $ok)use(&$checks){$checks[]=['name'=>$name,'passed'=>$ok];echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;};
$check('PHP 8.3 runtime',PHP_VERSION_ID>=80300&&PHP_VERSION_ID<80400);
$suffix=strtoupper(bin2hex(random_bytes(5)));$number='PHP83-'.$suffix;
$stream=fopen('php://temp','w+');fwrite($stream,$number);rewind($stream);
$preview=$r->preview(1,$stream,'paste','SYNTHETIC PHP 8.3 enrollment',date('Y-m-d',time()+86400*30));fclose($stream);$batch=$r->confirm(1,$preview['batch_ref']);
$check('PHP 8.3 transactional import',$batch['inserted_count']==1);
$user=$v->register('php83_'.$suffix,$suffix.'@local.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),$v::validateSubmission(['student_number'=>strtolower($number)]),fixtureVisitor($a));$id=(int)$user['id'];
$check('PHP 8.3 automatic approval',$v->forUser($id)['approval_method']==='registry_match'&&$a->continuedAccess($id));
$check('PHP 8.3 password hashing',password_verify('QaStrong123!',$db->query('SELECT password_hash FROM users WHERE id='.$id)->fetchColumn()));
$duplicate=false;try{$v->register('dup_'.$suffix,'dup'.$suffix.'@local.invalid','synthetic',$v::validateSubmission(['student_number'=>$number]),fixtureVisitor($a));}catch(PDOException $e){$duplicate=($e->errorInfo[1]??0)===1062;}
$check('PHP 8.3 duplicate claim blocked',$duplicate);
$entry=$r->entries(1,$number)[0];$r->changeStatus(1,$entry['registry_ref'],(int)$entry['version'],'revoked','Synthetic PHP 8.3 revocation');
$check('PHP 8.3 revocation enforced',!$a->continuedAccess($id));
$failed=count(array_filter($checks,fn($c)=>!$c['passed']));file_put_contents(__DIR__.'/../../docs/qa/registry-php83-results.json',json_encode(['runtime'=>PHP_VERSION,'passed'=>count($checks)-$failed,'failed'=>$failed,'checks'=>$checks],JSON_PRETTY_PRINT));exit($failed?1:0);
