<?php
declare(strict_types=1);
ob_start();
require __DIR__.'/test_bootstrap.php';
require_once __DIR__.'/../../app/src/Controllers/AnalyticsController.php';
require_once __DIR__.'/../../app/src/Repositories/HistoryRepository.php';
require_once __DIR__.'/../../app/src/Services/PasswordResetService.php';
require_once __DIR__.'/../../app/src/Services/RememberMeService.php';
require_once __DIR__.'/../../app/src/Services/AdminSecurityService.php';
require_once __DIR__.'/../../app/src/Services/FeedbackService.php';
$driver=$argv[1]??'';$db=fixtureConnection($driver);injectFixture($db);date_default_timezone_set('Asia/Manila');$checks=[];
function assertion(string $name,bool $condition):void{global $checks;$checks[]=['name'=>$name,'passed'=>$condition];echo ($condition?'PASS ':'FAIL ').$name.PHP_EOL;if(!$condition)throw new RuntimeException($name);}
try{
 if($driver==='mysql'){
  // Match XAMPP's application-local calendar; no production server setting changes.
  $db->exec("SET time_zone='+08:00'");
  $db->exec(file_get_contents(__DIR__.'/../../database/analytics_schema.sql'));
  $db->exec(file_get_contents(__DIR__.'/../../database/migrations/010_user_tokens.sql'));
  $db->exec(file_get_contents(__DIR__.'/../../database/migrations/003_admin_audit_logs.sql'));
  $db->exec('CREATE TABLE IF NOT EXISTS password_resets(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,token_hash VARCHAR(255),expires_at DATETIME,used_at DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
  $db->exec('CREATE TABLE IF NOT EXISTS feedback_comments(id INT AUTO_INCREMENT PRIMARY KEY,summary_id INT,rating INT,reasons TEXT,comment TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
  $fields=array_column($db->query('SHOW COLUMNS FROM feedback')->fetchAll(),'Field');
  foreach(['name'=>'VARCHAR(100)','email'=>'VARCHAR(150)','impression'=>'VARCHAR(50)','reasons'=>'TEXT','comment'=>'TEXT','updated_at'=>'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'] as $column=>$type)if(!in_array($column,$fields,true))$db->exec('ALTER TABLE feedback ADD COLUMN '.$column.' '.$type);
  $summaryFields=array_column($db->query('SHOW COLUMNS FROM summaries')->fetchAll(),'Field');
  if(!in_array('created_at',$summaryFields,true))$db->exec('ALTER TABLE summaries ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
 }
 $suffix=bin2hex(random_bytes(6));$db->prepare("INSERT INTO users(username,email,password_hash,role,terms_accepted)VALUES(?,?,?,'admin',1)")->execute(['sql_'.$suffix,$suffix.'@example.invalid',password_hash('Valid123!',PASSWORD_DEFAULT)]);$id=(int)$db->lastInsertId();
 $db->prepare("INSERT INTO summaries(user_id,share_token,article_title,input_type,summary_style,summary_length,original_word_count,summary_word_count,processing_time,status)VALUES(?,?,?,'text','standard_paragraph','exhaustive',200,100,0.5,'completed')")->execute([$id,bin2hex(random_bytes(32)),'Synthetic SQL fixture']);$summary=(int)$db->lastInsertId();
 $db->prepare('INSERT INTO summary_artifacts(summary_id,original_text,generated_summary)VALUES(?,?,?)')->execute([$summary,'Synthetic complete source.',json_encode(['plain_summary'=>'Synthetic short summary.','analysis_mode'=>'general','output_format'=>'paragraph','summary_depth'=>'exhaustive'])]);
 $db->prepare("INSERT INTO nutshell_generations(summary_id,user_id,input_type,nutshell_text,status,word_count,source_word_count)VALUES(?,?,'text','Synthetic Nutshell','completed',10,200)")->execute([$summary,$id]);
 $db->prepare('INSERT INTO landing_page_sessions(session_id,ip_hash,max_scroll_percent,dwell_time_seconds,converted,lcp_ms,referrer)VALUES(?,?,50,60,1,1200,?)')->execute([$suffix,str_repeat('c',64),'https://example.invalid/fixture']);
 $analytics=new \App\Src\Controllers\AnalyticsController();
 assertion('landing KPIs', $analytics->getKpis()['total_sessions']>=1);
 assertion('device breakdown',array_key_exists('desktop',$analytics->getDeviceBreakdown()));
 assertion('scroll funnel',array_key_exists(50,$analytics->getScrollFunnel()));
 assertion('fourteen-day trend',count($analytics->getDailyTrend())===14);
 assertion('referrer host parsing',in_array('example.invalid',array_column($analytics->getTopReferrers(),'domain'),true));
 assertion('administrative sidebar',$analytics->getSidebarSnapshot()['total_summaries']>=1);
 assertion('owner sidebar remains scoped',$analytics->getUserSidebarStats($id)['my_summaries']===1);
 $dashboard=$analytics->getDashboardData($id,false,date('Y-m-d'),date('Y-m-d'));
 assertion('summary analytics decimal reduction',(float)$dashboard['kpis']['reduction']===50.0);
 assertion('all-six-depth analytics ordering preserves exhaustive',$dashboard['lengths'][0]['label']==='exhaustive');
 assertion('fractional compression preserved',(float)$dashboard['performance']['average_time']===0.5);
 assertion('Nutshell analytics does not silently degrade',(int)$dashboard['nutshell']['generated']===1);
 assertion('saved preferences',$analytics->getUserPreferredSettings($id)['summary_depth']==='exhaustive');
 $reset=new \App\Src\Services\PasswordResetService($db);$otp=$reset->createToken($id);assertion('password reset SQL',(bool)$reset->validateToken($id,$otp));$reset->markTokenUsed($id);assertion('OTP replay denied',$reset->validateToken($id,$otp)===false);
 \App\Src\Services\RememberMeService::createCookieForUser($id);$q=$db->prepare('SELECT COUNT(*) FROM user_tokens WHERE user_id=? AND selector IS NOT NULL');$q->execute([$id]);assertion('remember-me storage SQL',(int)$q->fetchColumn()===1);
 (new \App\Src\Services\AdminSecurityService())->auditLog($id,'sql_compat_'.$suffix,'Synthetic non-personal test event');$q=$db->prepare('SELECT COUNT(*) FROM admin_audit_logs WHERE action=?');$q->execute(['sql_compat_'.$suffix]);assertion('audit logger SQL',(int)$q->fetchColumn()===1);
 $feedback=new \App\Src\Services\FeedbackService();assertion('existing feedback write SQL',($feedback->submitFeedback($summary,2,$id,null,null,'Synthetic feedback',['too_short'])['success']??false)===true);
 $repo=new \App\Src\Repositories\FeedbackRepository($db);assertion('feedback telemetry SQL',isset($repo->getFeedbackTelemetryBreakdown()['by_source']));
 $q=$db->prepare("INSERT INTO feedback_comments(summary_id,rating,comment,created_at)VALUES(?,2,'Synthetic expired feedback',?)");$q->execute([$summary,(new DateTimeImmutable())->modify('-100 days')->format($driver==='pgsql'?'c':'Y-m-d H:i:s')]);
 assertion('feedback retention interval SQL',$feedback->pruneFeedbackEvents(90)===1);
 $history=new \App\Src\Repositories\HistoryRepository($db);assertion('Nutshell history PostgreSQL/MySQL string literals',is_array($history->findNutshellHistory($id,null,20)));
 file_put_contents(__DIR__.'/../../docs/student-number-access/tests-sql-compat-'.$driver.'.json',json_encode(['recorded_utc'=>gmdate('c'),'driver'=>$driver,'isolated_fixture'=>true,'passed'=>count($checks),'failed'=>0,'checks'=>$checks],JSON_PRETTY_PRINT).PHP_EOL);
 echo json_encode(['driver'=>$driver,'passed'=>count($checks),'failed'=>0]).PHP_EOL;
}catch(Throwable $e){echo get_class($e).': '.$e->getMessage().PHP_EOL;ob_end_flush();exit(1);}
ob_end_flush();
