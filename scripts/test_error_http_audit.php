<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../app/src/whitereaper.php';
require_once __DIR__ . '/../app/src/Database.php';
$db = \App\Src\Database::getInstance()->getConnection();
$path = __DIR__ . '/../artifacts/error-feedback/http-results.json';
$report = json_decode(file_get_contents($path), true);
$audit = [];
foreach (array_unique($report['job_ids'] ?? []) as $id) {
    if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/', $id)) throw new RuntimeException('Invalid test job identifier');
    $query=$db->prepare('SELECT job_id,state,result_id,error_details,payload,user_id FROM summary_jobs WHERE job_id=?');
    $query->execute([$id]); $job=$query->fetch(PDO::FETCH_ASSOC);
    if (!$job) continue;
    if ($job['user_id'] !== null) throw new RuntimeException('Refusing non-guest test row');
    $failure = json_decode((string)$job['error_details'],true);
    if (in_array($job['state'],['failed','timed_out','cancelled'],true) && $job['result_id'] !== null) throw new RuntimeException('Failure has a summary');
    if ($job['state']==='completed' && $job['result_id']===null) throw new RuntimeException('Missing completed summary');
    if ($job['payload']!=='{}') throw new RuntimeException('Terminal test source retained unexpectedly');
    $audit[]=['job_id'=>$id,'state'=>$job['state'],'result_id'=>$job['result_id'],'error_code'=>$failure['code']??null,'payload_cleared'=>true];
    if (in_array('--cleanup',$argv,true)) {
        $db->beginTransaction();
        $db->prepare('DELETE FROM summary_jobs WHERE job_id=? AND user_id IS NULL')->execute([$id]);
        if ($job['result_id']) $db->prepare('DELETE FROM summaries WHERE id=? AND user_id IS NULL')->execute([$job['result_id']]);
        $db->commit();
    }
}
$report['database_audit']=$audit;
$report['test_rows_cleaned']=in_array('--cleanup',$argv,true);
file_put_contents($path,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo 'PASS: '.count($audit).' actual terminal jobs audited; failed jobs have no summary; completed results unique; source payloads cleared.',PHP_EOL;
