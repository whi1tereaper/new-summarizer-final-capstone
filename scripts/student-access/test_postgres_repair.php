<?php
declare(strict_types=1);
ob_start();
require __DIR__.'/test_bootstrap.php';
require_once __DIR__.'/../../app/src/Services/PostgresAccessMigrator.php';
require_once __DIR__.'/../../app/src/Repositories/HistoryRepository.php';
require_once __DIR__.'/../../app/src/Services/PasswordResetService.php';
require_once __DIR__.'/../../app/src/Services/RememberMeService.php';
require_once __DIR__.'/../../app/src/Services/AdminSecurityService.php';
require_once __DIR__.'/../../app/src/Controllers/AnalyticsController.php';
require_once __DIR__.'/../../app/src/Services/FeedbackService.php';
use App\Src\Services\PostgresAccessMigrator as Migrator;
use App\Src\Services\StudentVerificationService as Verification;
use App\Src\Services\CampusAccessService as Access;
use App\Src\Support\PostgresSchemaContract as Contract;
date_default_timezone_set('Asia/Manila');
session_start();
$db=fixtureConnection('pgsql');$checks=[];
function proof(string $name,bool $condition):void{global $checks;$checks[]=['name'=>$name,'passed'=>$condition];echo ($condition?'PASS ':'FAIL ').$name.PHP_EOL;if(!$condition)throw new RuntimeException($name);}
function refused(callable $f):bool{try{$f();return false;}catch(Throwable $e){return true;}}
function snapshot(PDO $db,string $table):array{return $db->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);}
function legacyBase(PDO $db):void{$db->exec(file_get_contents(__DIR__.'/../../database/schema.postgres.sql'));}
function isolatedCase(PDO $control,string $kind,bool $withBase=true):PDO{
 $name='repair_'.$kind.'_'.bin2hex(random_bytes(4));if(!preg_match('/\Arepair_[a-z_]+_[a-f0-9]{8}\z/',$name))throw new RuntimeException('Unsafe fixture name.');
 $control->exec('CREATE DATABASE "'.$name.'"');
 $pdo=new PDO('pgsql:host=127.0.0.1;port=55439;dbname='.$name,'postgres','isolated-fixture-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);if($withBase)legacyBase($pdo);return $pdo;
}
function legacySummary(PDO $db,string $title,string $text,string $summary,?int $owner=null):int{
 $q=$db->prepare("INSERT INTO summaries(user_id,share_token,article_title,input_type,original_text,generated_summary)VALUES(?,?,?,'text',?,?)");$q->execute([$owner,bin2hex(random_bytes(32)),$title,$text,$summary]);return (int)$db->lastInsertId();
}
function containerCommand(array $arguments):void{
 $pipes=[];$process=proc_open(array_merge(['docker','exec','light-pg-repair-test'],$arguments),[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($process))throw new RuntimeException('Fixture container command failed.');
 $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 if(proc_close($process)!==0)throw new RuntimeException('Fixture backup/restore command failed: '.$err);
}
try{
 $db=isolatedCase($db,'main',false);injectFixture($db);
 proof('test server is PostgreSQL 18',(int)$db->query('SHOW server_version_num')->fetchColumn()>=180000);
 proof('fresh dedicated database required',$db->query("SELECT to_regclass('users')")->fetchColumn()===null);
 legacyBase($db);
 $audit=json_decode(file_get_contents(__DIR__.'/../../docs/student-number-access/postgres-schema-before.json'),true,512,JSON_THROW_ON_ERROR);
 $current=$db->query('SELECT table_name,column_name,udt_name,is_nullable,character_maximum_length FROM information_schema.columns WHERE table_schema=current_schema() ORDER BY table_name,ordinal_position')->fetchAll();
 $signature=static fn(array $rows):array=>array_map(static fn($r)=>[$r['table_name'],$r['column_name'],$r['udt_name'],$r['is_nullable'],$r['character_maximum_length']],$rows);
 proof('legacy fixture columns/types/nullability match captured Neon schema',$signature($current)===$signature($audit['columns']));
 $hash=password_hash('Original123!',PASSWORD_ARGON2ID);
 $db->prepare("INSERT INTO users(username,email,password_hash,role,terms_accepted) VALUES ('legacy_student','student@example.invalid',?,'user',1),('legacy_admin','admin@example.invalid',?,'admin',1)")->execute([$hash,$hash]);
 $student=(int)$db->query("SELECT id FROM users WHERE username='legacy_student'")->fetchColumn();$admin=(int)$db->query("SELECT id FROM users WHERE username='legacy_admin'")->fetchColumn();
 $summary=legacySummary($db,'Historical article','Original historical source text is preserved verbatim.','Historical summary is preserved verbatim.',$student);
 $db->prepare("INSERT INTO summary_artifacts(summary_id,artifact_type,storage_path) VALUES (?,'source_pdf','private/fixture.pdf'),(?,'export_docx','private/fixture.docx')")->execute([$summary,$summary]);
 $usedHash=password_hash('123456',PASSWORD_BCRYPT);
 $db->prepare("INSERT INTO password_resets(user_id,token_hash,expires_at,used)VALUES(?,?,CURRENT_TIMESTAMP+INTERVAL '1 hour',1)")->execute([$student,$usedHash]);
 $db->prepare("INSERT INTO user_tokens(user_id,token_hash,token_type,expires_at)VALUES(?,?,'legacy_remember',CURRENT_TIMESTAMP+INTERVAL '1 day')")->execute([$student,str_repeat('a',64)]);
 $db->prepare("INSERT INTO admin_audit_logs(user_id,action,details)VALUES(?,'legacy_event','Synthetic historical audit retained')")->execute([$admin]);
 $db->prepare("INSERT INTO admin_security_challenges(user_id,challenge_type,secret_hash)VALUES(?,'retired_fixture','legacy-secret-hash-retained')")->execute([$admin]);
 $db->prepare("INSERT INTO feedback(summary_id,user_id,rating,reasons,comment)VALUES(?,?,4,'[\"too_short\"]','Synthetic preserved feedback')")->execute([$summary,$student]);
 $db->prepare("INSERT INTO nutshell_generations(summary_id,user_id,nutshell_text)VALUES(?,?,'Historical nutshell content')")->execute([$summary,$student]);
 $db->exec("INSERT INTO landing_page_sessions(session_id,ip_hash,max_scroll_percent,dwell_time_seconds,converted,lcp_ms)VALUES('historical-session','".str_repeat('b',64)."',50,60,1,1200)");
 $db->exec(file_get_contents(__DIR__.'/../../database/migrations/postgres/017_student_number_access.sql'));
 $access=new Access($db);$visitor=fixtureVisitor($access);
 $verification=new Verification($db);
 $verification->resubmit($student,['full_name'=>'Synthetic Existing Student','student_number'=>'PEN-EXISTING-001','terms_accept'=>'1','privacy_consent'=>'1','eligibility_ack'=>'1'],null,$visitor);
 $application=$verification->forUser($student);
 $verification->review($admin,$application['application_ref'],1,'accept',['reason'=>'Existing authorized synthetic approval','evidence_kind'=>'registrar_confirmation','evidence_reference'=>'Synthetic authorized evidence only','ownership_reference'=>'Synthetic campus contact only','verification_period'=>'Synthetic established period','expires_date'=>date('Y-m-d',time()+86400*30),'evidence_confirmed'=>'1']);
 $preserved=[];foreach(['users','summaries','summary_artifacts','password_resets','user_tokens','admin_audit_logs','admin_security_challenges','feedback','nutshell_generations','landing_page_sessions','student_applications','student_review_events'] as $table)$preserved[$table]=snapshot($db,$table);
 $fixtureName=$db->query('SELECT current_database()')->fetchColumn();$dump='/tmp/light-'.$fixtureName.'-before.dump';
 containerCommand(['pg_dump','-U','postgres','-Fc','-f',$dump,'-d',$fixtureName]);
 $restored=isolatedCase($db,'restore',false);$restoredName=$restored->query('SELECT current_database()')->fetchColumn();
 containerCommand(['pg_restore','-U','postgres','--single-transaction','--exit-on-error','-d',$restoredName,$dump]);
 $restoredSame=true;foreach($preserved as $table=>$rows)if(snapshot($restored,$table)!==$rows)$restoredSame=false;
 proof('actual pg_dump and restore to a new database preserve representative rows',$restoredSame);
 $migrator=new Migrator($db);proof('old schema preflight fails before any migration',!$migrator->plan()['contract']['compatible']);
 $migration=$migrator->apply();proof('production migration runner succeeds on representative legacy schema',$migration['contract']['compatible']&&count($migration['applied'])===3);
 foreach($preserved as $table=>$rows){
  $key=$table==='landing_page_sessions'?'session_id':'id';$after=array_column(snapshot($db,$table),null,$key);$same=true;
  foreach($rows as $r)foreach($r as $column=>$value)if($after[$r[$key]][$column]!==$value)$same=false;
  proof('existing '.$table.' records preserved byte-for-byte',$same);
 }
 $again=$migrator->apply();proof('runner replay is checksum-idempotent',count($again['applied'])===0&&count($again['skipped'])===3);
 $history=new \App\Src\Repositories\HistoryRepository($db);$record=$history->findSummaryById($summary);
 proof('historical original and generated text remain readable',$record['original_text']===$preserved['summaries'][0]['original_text']&&$record['generated_summary']===$preserved['summaries'][0]['generated_summary']);
 proof('multiple legacy file references do not duplicate history',count($history->findSummaryHistory($student,null,50,0))===1);
 proof('no historical analytics values fabricated',$record['original_word_count']===null&&$record['processing_time']===null);
 proof('unknown historical Nutshell source type and word count remain unknown',$db->query('SELECT input_type FROM nutshell_generations ORDER BY id LIMIT 1')->fetchColumn()===null&&$db->query('SELECT word_count FROM nutshell_generations ORDER BY id LIMIT 1')->fetchColumn()===null);
 $reset=new \App\Src\Services\PasswordResetService($db);proof('used legacy OTP cannot be revived',$reset->validateToken($student,'123456')===false);
 $otp=$reset->createToken($student);proof('new password reset is redeemable',(bool)$reset->validateToken($student,$otp));
 $seconds=(int)$db->query('SELECT EXTRACT(EPOCH FROM expires_at-CURRENT_TIMESTAMP) FROM password_resets ORDER BY id DESC LIMIT 1')->fetchColumn();proof('reset expiry is 15 minutes, not an eight-hour timezone extension',$seconds>850&&$seconds<=901);
 $reset->markTokenUsed($student);proof('used new OTP cannot be replayed',$reset->validateToken($student,$otp)===false);
 proof('legacy used timestamp is not invented',$db->query('SELECT used_at FROM password_resets ORDER BY id LIMIT 1')->fetchColumn()===null);
 $tokenCount=(int)$db->query('SELECT COUNT(*) FROM user_tokens')->fetchColumn();\App\Src\Services\RememberMeService::createCookieForUser($student);
 proof('new remember-me token persists alongside untouched legacy token',(int)$db->query('SELECT COUNT(*) FROM user_tokens')->fetchColumn()===$tokenCount+1);
 proof('legacy token not assigned fabricated credentials',$db->query('SELECT selector FROM user_tokens ORDER BY id LIMIT 1')->fetchColumn()===null);
 (new \App\Src\Services\AdminSecurityService())->auditLog($admin,'compat_test','No student numbers or emails in this event','student_verification',(int)$application['id']);
 proof('existing admin audit writer succeeds',(int)$db->query("SELECT COUNT(*) FROM admin_audit_logs WHERE action='compat_test'")->fetchColumn()===1);
 $analytics=new \App\Src\Controllers\AnalyticsController();$kpi=$analytics->getKpis();proof('landing KPI PostgreSQL syntax succeeds',$kpi['total_sessions']===1&&$kpi['conversion_rate']===100.0);
 proof('scroll funnel PostgreSQL syntax succeeds',$analytics->getScrollFunnel()[50]===100.0);
 proof('daily trend retains fourteen buckets',count($analytics->getDailyTrend())===14);
 proof('top referrer PostgreSQL string functions succeed',$analytics->getTopReferrers()===[]);
 proof('saved settings PostgreSQL literals and artifact join succeed',$analytics->getUserPreferredSettings($student)['count']===1);
 proof('admin sidebar PostgreSQL syntax succeeds',$analytics->getSidebarSnapshot()['total_summaries']===1);
 proof('student sidebar remains owner-scoped',$analytics->getUserSidebarStats($student)['my_summaries']===1);
 $dashboard=$analytics->getDashboardData($student,false,date('Y-m-d'),date('Y-m-d'));
 proof('summary analytics supports decimal casts, ordering and aggregates',$dashboard['kpis']['articles']===1&&$dashboard['kpis']['original_avg']===null);
 $feedback=new \App\Src\Repositories\FeedbackRepository($db);$telemetry=$feedback->getFeedbackTelemetryBreakdown();proof('feedback PostgreSQL string literal queries succeed',isset($telemetry['by_style']));
 proof('feedback admin projections do not disclose account email',($feedback->getAllFeedbackForAdmin()[0]['account_email']??null)===null);
 proof('continued student approval survives migration',$access->continuedAccess($student));
 $db->prepare('UPDATE student_applications SET details_delete_epoch=? WHERE user_id=?')->execute([time()-1,$student]);
 $retention=new \App\Src\Services\StudentRetentionService($db);$retention->run(true);$retained=$verification->forUser($student);
 proof('three-day erasure retains eligibility without application details',$retained['student_number']===null&&$retained['full_name']===null&&$retained['evidence_reference']===null&&$access->continuedAccess($student));
 proof('erasure preserves private account email and password hash',$db->query('SELECT email FROM users WHERE id='.$student)->fetchColumn()==='student@example.invalid'&&$db->query('SELECT password_hash FROM users WHERE id='.$student)->fetchColumn()===$hash);
 $db->prepare('UPDATE student_review_events SET created_epoch=? WHERE application_id=?')->execute([time()-259201,$application['id']]);$retention->run(true);
 proof('old review detail erased while minimal action audit remains',(int)$db->query('SELECT COUNT(*) FROM student_review_events WHERE application_id='.(int)$application['id'])->fetchColumn()===2&&(int)$db->query('SELECT COUNT(*) FROM student_review_events WHERE application_id='.(int)$application['id'].' AND (notes IS NOT NULL OR student_message IS NOT NULL)')->fetchColumn()===0);
 $declineToken=fixtureVisitor($access);$declined=$verification->register('decline_fixture','decline@example.invalid',$hash,['full_name'=>'Synthetic Decline Fixture','student_number'=>'PEN-DECLINE-002','normalized'=>'PEN-DECLINE-002'],$declineToken);$declineApp=$verification->forUser((int)$declined['id']);
 proof('new application stays pending and unprivileged',$declineApp['status']==='pending'&&!$access->continuedAccess((int)$declined['id']));
 proof('decline without a reason is refused',refused(fn()=>$verification->review($admin,$declineApp['application_ref'],1,'decline',[])));
 $verification->review($admin,$declineApp['application_ref'],1,'decline',['reason'=>'Synthetic evidence did not establish eligibility']);$declineApp=$verification->forUser((int)$declined['id']);
 proof('administrator decline changes real status and not privileges',$declineApp['status']==='declined'&&!$access->continuedAccess((int)$declined['id']));
 proof('decline reason and actor are auditable',(int)$db->query("SELECT actor_user_id FROM student_review_events WHERE application_id=".(int)$declineApp['id']." AND action='decline'")->fetchColumn()===$admin);
 $verification->resubmit((int)$declined['id'],['full_name'=>'Synthetic Corrected Fixture','student_number'=>'PEN-DECLINE-002','terms_accept'=>'1','privacy_consent'=>'1','eligibility_ack'=>'1'],(int)$declineApp['version'],$declineToken);
 proof('declined applicant can correct without duplicate registration',$verification->forUser((int)$declined['id'])['status']==='pending');
 proof('pooler URL refused for DDL',refused(fn()=>Migrator::assertDirectUrl('postgresql://u:p@ep-example-pooler.neon.tech/db','')));
 proof('different direct database refused',refused(fn()=>Migrator::assertDirectUrl('postgresql://u:p@ep-example-pooler.neon.tech/db','postgresql://u:p@ep-example.neon.tech/other')));
 Migrator::assertDirectUrl('postgresql://u:p@ep-example-pooler.neon.tech/db','postgresql://u:p@ep-example.neon.tech/db');proof('matching Neon direct/pooler pair accepted',true);
 $db->prepare('UPDATE schema_migrations SET checksum=? WHERE migration=?')->execute([str_repeat('0',64),'019_auth_audit_contract_compat.sql']);
 proof('checksum mismatch blocks migration and preflight',refused(fn()=>$migrator->apply())&&!$migrator->plan()['contract']['compatible']);
 $db->prepare('UPDATE schema_migrations SET checksum=? WHERE migration=?')->execute([hash_file('sha256',__DIR__.'/../../database/migrations/postgres/019_auth_audit_contract_compat.sql'),'019_auth_audit_contract_compat.sql']);
 $missing=isolatedCase($db,'missing_text');legacySummary($missing,'Unrecoverable legacy metadata','','');
 proof('unreadable completed summary fails closed',refused(fn()=>(new Migrator($missing))->apply()));
 proof('failed data gate rolls back DDL and ledger',$missing->query("SELECT 1 FROM information_schema.columns WHERE table_name='summaries' AND column_name='article_category'")->fetchColumn()===false&&$missing->query("SELECT to_regclass('student_applications')")->fetchColumn()===null);
 $duplicates=isolatedCase($db,'duplicates');$duplicateId=legacySummary($duplicates,'Ambiguous source','source','summary');
 $duplicates->exec('ALTER TABLE summary_artifacts ADD COLUMN original_text TEXT,ADD COLUMN generated_summary TEXT');
 $duplicates->prepare("INSERT INTO summary_artifacts(summary_id,artifact_type,storage_path,original_text,generated_summary)VALUES(?,'inline','reference-a','text A','summary A'),(?,'inline','reference-b','text B','summary B')")->execute([$duplicateId,$duplicateId]);
 proof('ambiguous inline duplicates are not silently merged',refused(fn()=>(new Migrator($duplicates))->apply())&&(int)$duplicates->query('SELECT COUNT(*) FROM summary_artifacts')->fetchColumn()===2);
 $wrongIndex=isolatedCase($db,'wrong_index');$wrongIndex->exec('CREATE INDEX uq_summary_artifacts_inline ON summary_artifacts(summary_id)');
 proof('IF NOT EXISTS is not mistaken for shape validation',refused(fn()=>(new Migrator($wrongIndex))->apply()));
 $report=['recorded_utc'=>gmdate('c'),'fixture_database'=>$db->query('SELECT current_database()')->fetchColumn(),'test_server_version'=>$db->query('SHOW server_version_num')->fetchColumn(),'configured_database_modified'=>false,'captured_schema_matches'=>true,'checks'=>$checks,'passed'=>count($checks),'failed'=>0,'post_migration_contract'=>Contract::inspect($db),'migration_sha256'=>array_map(static fn($file)=>['file'=>$file,'sha256'=>hash_file('sha256',__DIR__.'/../../database/migrations/postgres/'.$file)],Migrator::FILES)];
 file_put_contents(__DIR__.'/../../docs/student-number-access/tests-postgres-repair.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
 echo json_encode(['passed'=>count($checks),'failed'=>0]).PHP_EOL;
}catch(Throwable $e){echo 'FAIL '.get_class($e).': '.$e->getMessage().PHP_EOL;ob_end_flush();exit(1);}
finally{if(session_status()===PHP_SESSION_ACTIVE)session_destroy();}
ob_end_flush();
