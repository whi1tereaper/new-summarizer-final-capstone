<?php
declare(strict_types=1);
// Supplied identifiers stay in the invoking process; never embed a real roster in this file.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../../app/src/Support/config.php';
require_once __DIR__.'/../../app/src/Services/StudentEnrollmentRegistryService.php';
use App\Src\Services\StudentEnrollmentRegistryService as Registry;
use App\Src\Services\StudentVerificationService as Verification;
use App\Src\Services\CampusAccessService as Access;
$samples=json_decode((string)getenv('QA_STUDENT_ID_SAMPLES'),true,512,JSON_THROW_ON_ERROR);
if(!is_array($samples)||!$samples||count($samples)>100)throw new RuntimeException('Provide a bounded private sample list.');
$numbers=array_map([Verification::class,'normalizeNumber'],$samples);
if(($argv[1]??'')==='live'){
 $db=App\Src\Database::getConfiguredMySqlConnection();$db->exec('START TRANSACTION READ ONLY');
 try{
  $q=$db->prepare('SELECT normalized_student_number,student_number_fingerprint,eligibility_status,eligible_until_epoch FROM student_enrollment_registry WHERE normalized_student_number IN ('.implode(',',array_fill(0,count($numbers),'?')).')');$q->execute($numbers);$rows=$q->fetch(PDO::FETCH_ASSOC);$rows=$rows?[$rows]:[];while($row=$q->fetch(PDO::FETCH_ASSOC))$rows[]=$row;
  $unique=$db->query("SELECT INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_used FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='student_enrollment_registry' AND NON_UNIQUE=0 GROUP BY INDEX_NAME")->fetchAll(PDO::FETCH_ASSOC);
  $out=['read_only'=>true,'sample_count'=>count($numbers),'all_sample_formats_accepted'=>count($samples)===count($numbers),'registry_total'=>(int)$db->query('SELECT COUNT(*) FROM student_enrollment_registry')->fetchColumn(),'sample_registry_matches'=>count($rows),'sample_eligible_matches'=>count(array_filter($rows,fn($r)=>$r['eligibility_status']==='active'&&(int)$r['eligible_until_epoch']>time())),'matching_fingerprints'=>count(array_filter($rows,fn($r)=>hash_equals(Verification::fingerprint($r['normalized_student_number']),$r['student_number_fingerprint']))),'unique_constraints'=>$unique,'privacy_contact_configured'=>filter_var(config('student_access.privacy_contact',''),FILTER_VALIDATE_EMAIL)!==false,'student_key_configured'=>strlen((string)base64_decode(config('student_access.number_secret',''),true))===32,'enrollment_processing_authorized'=>config('student_access.enrollment_authorized',false),'registration_open'=>Verification::configurationError()===null];
  echo json_encode($out,JSON_PRETTY_PRINT).PHP_EOL;
 }finally{$db->rollBack();}
 exit;
}
require __DIR__.'/test_bootstrap.php';
$db=fixtureConnection('mysql');injectFixture($db);$r=new Registry($db);$v=new Verification($db);$a=new Access($db);$checks=[];
$check=function(string $name,bool $ok)use(&$checks){$checks[]=['name'=>$name,'passed'=>$ok];echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;};
$stream=function(array $ids){$f=fopen('php://temp','w+');fwrite($f,implode("\n",$ids));rewind($f);return $f;};
$register=function(string $number)use($v,$a){$suffix=bin2hex(random_bytes(6));return $v->register('idcheck_'.$suffix,$suffix.'@local.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),Verification::validateSubmission(['student_number'=>$number]),fixtureVisitor($a));};
try{
 $check('all supplied ID formats accepted with separators and leading zeros intact',$numbers===array_map(fn($s)=>strtoupper(trim($s)),$samples));
 $pending=fixtureLegacyPending($db,'idcheck_legacy','idcheck-legacy@local.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),$numbers[0],fixtureVisitor($a));$owner=(int)$pending['id'];$hash=$db->query('SELECT password_hash FROM users WHERE id='.$owner)->fetchColumn();
 $check('historical unlisted account remains preserved and restricted',$v->forUser($owner)['status']==='pending'&&!$a->continuedAccess($owner));
 $check('unverified submissions never populate the trusted registry',(int)$db->query('SELECT COUNT(*) FROM student_enrollment_registry')->fetchColumn()===0);
 $f=$stream($numbers);try{$batch=$r->import(1,$f,'paste');}finally{fclose($f);}
 $check('administrator paste inserts every supplied ID',(int)$batch['inserted_count']===count($numbers)&&(int)$batch['invalid_count']===0);
 $check('matching pending account approved within import transaction',(int)$batch['approved_count']===1&&$v->forUser($owner)['status']==='verified'&&$a->continuedAccess($owner));
 $check('existing account password hash preserved',$db->query('SELECT password_hash FROM users WHERE id='.$owner)->fetchColumn()===$hash);
 $accounts=[$owner];foreach(array_slice($numbers,1) as $number)$accounts[]=(int)$register(' '.strtolower($number).' ')['id'];
 $check('every matched registration approved without another manual review',count(array_filter($accounts,fn($id)=>$v->forUser($id)['status']==='verified'&&$v->forUser($id)['approval_method']==='registry_match'))===count($numbers));
 $check('all matched accounts receive continued summarizer access',count(array_filter($accounts,fn($id)=>$a->continuedAccess($id)))===count($numbers));
 $repository=new App\Src\Repositories\UserRepository($db);$loginOk=true;
 foreach($numbers as $index=>$number){$user=$repository->findActiveByCredential(strtolower($number));$loginOk=$loginOk&&$user&&(int)$user['id']===$accounts[$index]&&password_verify('QaStrong123!',$user['password_hash']);}
 $check('student-number login resolves the approved account and password',$loginOk);
 $exact=$db->prepare('SELECT COUNT(*) FROM student_enrollment_registry WHERE normalized_student_number=? AND student_number_fingerprint=?');$exactOk=true;
 foreach($numbers as $number){$exact->execute([$number,Verification::fingerprint($number)]);$exactOk=$exactOk&&(int)$exact->fetchColumn()===1;}
 $check('exact normalized IDs and keyed identities persisted uniquely',$exactOk);
 $f=$stream($numbers);try{$repeat=$r->import(1,$f,'paste');}finally{fclose($f);}
 $check('repeat import skips duplicates without rewriting approvals',(int)$repeat['inserted_count']===0&&(int)$repeat['skipped_count']===count($numbers)&&(int)$repeat['approved_count']===0);
 $users=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();$duplicate=false;
 try{$register($numbers[0]);}catch(PDOException $e){$duplicate=(int)($e->errorInfo[1]??0)===1062;}
 $check('duplicate account claim rejected with complete transaction rollback',$duplicate&&(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===$users);
 $check('duplicate claim audited for administrators',(int)$db->query("SELECT COUNT(*) FROM student_registry_events WHERE action='duplicate_claim'")->fetchColumn()===1);
 $denied=false;$f=$stream(['QA-UNAUTHORIZED-IMPORT']);try{$r->import($owner,$f,'paste');}catch(App\Src\Support\ProcessingError $e){$denied=$e->errorCode==='ACCESS_DENIED';}finally{fclose($f);}
 $check('ordinary student cannot import trusted enrollment IDs',$denied);
 $users=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();$rejected=false;
 try{$register('QA-UNLISTED-'.strtoupper(bin2hex(random_bytes(4))));}catch(InvalidArgumentException $e){$rejected=true;}
 $check('unmatched number rejected without retaining an account',$rejected&&(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===$users);
 $inactive='QA-INACTIVE-'.strtoupper(bin2hex(random_bytes(4)));$f=$stream([$inactive]);try{$r->import(1,$f,'txt');}finally{fclose($f);}
 $entry=$r->entries(1,$inactive)[0];$r->changeStatus(1,$entry['registry_ref'],(int)$entry['version'],'inactive','Synthetic eligibility test');$rejected=false;
 try{$register($inactive);}catch(InvalidArgumentException $e){$rejected=true;}
 $check('inactive registry number rejected without retaining an account',$rejected&&(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===$users);
 $events=(int)$db->query("SELECT COUNT(*) FROM student_registry_events WHERE action='registry_match'")->fetchColumn();
 $check('each automatic approval has a real audit event',$events===count($numbers));
}catch(Throwable $e){$check('unexpected exception (details withheld): '.get_class($e),false);}
$failed=count(array_filter($checks,fn($c)=>!$c['passed']));echo json_encode(['sample_count'=>count($numbers),'passed'=>count($checks)-$failed,'failed'=>$failed,'checks'=>$checks],JSON_PRETTY_PRINT).PHP_EOL;exit($failed?1:0);
