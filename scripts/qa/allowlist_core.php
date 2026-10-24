<?php
require __DIR__.'/isolation.php';
$results=[];$failed=0;
foreach(['test_phase1_security.php','test_phase2_decoupling.php','test_phase3_admin_analytics.php','test_phase4_database_lifecycle.php','test_feedback_hardening.php','test_history_repository_boundary.php'] as $script){
 $pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/../'.$script],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,2));
 $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);$exit=proc_close($process);
 $results[]=['name'=>$script,'exit'=>$exit,'output'=>$output,'stderr'=>$errors];$failed+=($exit!==0);
 echo ($exit===0?'PASS ':'FAIL ').$script.PHP_EOL;
}
file_put_contents((string)getenv('QA_REGISTRY_REPORT').'/core-results.json',json_encode($results,JSON_PRETTY_PRINT));exit($failed?1:0);
