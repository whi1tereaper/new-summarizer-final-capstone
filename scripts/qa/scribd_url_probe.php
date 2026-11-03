<?php
declare(strict_types=1);
require __DIR__.'/../student-access/test_bootstrap.php';
$db=fixtureConnection('mysql');
$jobs=$db->query('SELECT state,result_id,error_details,payload FROM summary_jobs')->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['summaries'=>(int)$db->query('SELECT COUNT(*) FROM summaries')->fetchColumn(),
    'active_reservations'=>(int)$db->query("SELECT COUNT(*) FROM summary_access_reservations WHERE state='reserved'")->fetchColumn(),
    'job_results'=>array_map(static fn($r)=>['state'=>$r['state'],'has_result'=>$r['result_id']!==null,'failure_code'=>json_decode((string)$r['error_details'],true)['code']??null,'payload_erased'=>$r['payload']==='{}'],$jobs)]);
