<?php
require __DIR__.'/../student-access/test_bootstrap.php';
$db=fixtureConnection('mysql');injectFixture($db);
if(($argv[1]??'')==='setup'){
 $r=new App\Src\Services\StudentEnrollmentRegistryService($db);$v=new App\Src\Services\StudentVerificationService($db);$a=new App\Src\Services\CampusAccessService($db);
 $stream=fopen('php://temp','w+');fwrite($stream,'BENCH-00001');rewind($stream);$batch=$r->preview(1,$stream,'paste','SYNTHETIC speed benchmark',date('Y-m-d',time()+86400*30));fclose($stream);$r->confirm(1,$batch['batch_ref']);
 $u=$v->register('qa_benchmark','benchmark@example.invalid',password_hash('QaStrong123!',PASSWORD_DEFAULT),$v::validateSubmission(['student_number'=>'BENCH-00001']),fixtureVisitor($a));
 echo json_encode(['verified'=>$a->continuedAccess((int)$u['id'])]);
}elseif(($argv[1]??'')==='inspect'){
 $q=$db->prepare("SELECT s.original_word_count,s.summary_word_count,s.processing_time,s.status,TIMESTAMPDIFF(SECOND,j.started_at,j.completed_at) AS recorded_job_seconds,a.original_text FROM summaries s JOIN users u ON u.id=s.user_id LEFT JOIN summary_jobs j ON j.result_id=s.id LEFT JOIN summary_artifacts a ON a.summary_id=s.id WHERE s.id=? AND u.username='qa_benchmark'");$q->execute([(int)($argv[2]??0)]);$record=$q->fetch(PDO::FETCH_ASSOC);if(!$record)exit(1);
 $record['database_original_word_count']=$record['original_word_count'];
 if(!(int)$record['original_word_count']&&trim((string)$record['original_text'])!=='')$record['original_word_count']=count(preg_split('/\s+/u',trim($record['original_text']),-1,PREG_SPLIT_NO_EMPTY));
 unset($record['original_text']);echo json_encode($record);
}else exit(1);
