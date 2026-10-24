<?php
// Explicit test-server router outside the public web root. No real database is reachable.
if(PHP_SAPI!=='cli-server'||getenv('LIGHT_STUDENT_FIXTURE')!=='isolated-local-only'){http_response_code(403);exit;}
// A test must not occupy the user's persistent NLP supervisor. Use subprocesses.
putenv('PYTHON_WORKER_PORT=58149');
require __DIR__.'/test_bootstrap.php';
$db=fixtureConnection('pgsql');injectFixture($db);
$public=realpath(__DIR__.'/../../app/public');$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/');
chdir($public); // Match Apache's existing entry-point working directory.
if(str_starts_with($path,'/__fixture_')){
 if(($_SERVER['HTTP_X_FIXTURE_KEY']??'')!=='isolated-fixture-only'){http_response_code(403);exit;}
 require_once __DIR__.'/../../app/src/whitereaper.php';
 if($path==='/__fixture_reset_limits'){
  $db->exec("DELETE FROM rate_limits WHERE action_type IN ('register','login') AND identifier IN ('127.0.0.1','0.0.0.0')");echo 'ok';exit;
 }
 if($path==='/__fixture_admin'){
  $suffix=bin2hex(random_bytes(8));$db->prepare("INSERT INTO users(username,email,password_hash,role,terms_accepted)VALUES(?,?,?,'admin',1)")->execute(['fixture_admin_'.$suffix,$suffix.'@example.invalid','not-a-real-password']);
  $_SESSION['user_id']=(int)$db->lastInsertId();$_SESSION['role']='admin';$_SESSION['terms_accepted']=1;echo 'ok';exit;
 }
 if($path==='/__fixture_exhaust'){
  $a=new \App\Src\Services\CampusAccessService($db);$key=$a->trialSubject(isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:null,$a->visitorToken());
  $db->prepare('UPDATE summary_access_subjects SET completed_count=3 WHERE subject_key=?')->execute([$key]);echo 'ok';exit;
 }
 if($path==='/__fixture_queue'){require __DIR__.'/../../app/public/partials/admin-student-verifications.php';exit;}
 http_response_code(404);exit;
}
if(preg_match('#\A/api/documents(?:/.*)?\z#',$path)){
 require_once __DIR__.'/../../app/src/whitereaper.php';
 require_once __DIR__.'/../../app/src/Controllers/DocumentSummaryController.php';
 (new \App\Src\Controllers\DocumentSummaryController())->dispatch();exit;
}
$file=realpath($public.'/'.ltrim($path,'/'));
if(!$file||!str_starts_with($file,$public.DIRECTORY_SEPARATOR)||!is_file($file)){http_response_code(404);exit;}
if(pathinfo($file,PATHINFO_EXTENSION)==='php'){require $file;exit;}
return false;
