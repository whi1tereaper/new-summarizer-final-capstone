<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/src/whitereaper.php';
require_once __DIR__ . '/../app/src/Services/ArticleService.php';
require_once __DIR__ . '/../app/src/Services/SummaryJobService.php';
use App\Src\Database;
use App\Src\Services\ArticleService;
use App\Src\Services\SummaryJobService;
use App\Src\Support\ProcessingError;
$db = Database::getInstance()->getConnection();
$article = new ArticleService();
$jobs = new SummaryJobService();
$guest = bin2hex(random_bytes(32));
$jobIds = [];
$resultIds = [];
$checks = 0;
function check(bool $condition, string $name): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $name);
    ++$checks;
    echo "PASS: $name\n";
}
function failure(callable $call, string $code): void {
    try { $call(); } catch (ProcessingError $error) {
        check($error->errorCode === $code, $code . ' classification');
        $data = $error->toArray();
        check($data['title'] !== '' && $data['explanation'] !== '' && $data['recovery'] !== '' && preg_match('/^[a-f0-9]{24,32}$/', $data['reference']) === 1, $code . ' envelope');
        return;
    }
    throw new RuntimeException('Expected ' . $code);
}
function pending(array $override=[]): array {
    global $article, $guest;
    return $article->buildPendingSummary($override + [
        'source_type'=>'text', 'original_text'=>'The library opens on Monday morning.',
        'analysis_mode'=>'general', 'output_format'=>'paragraph', 'summary_depth'=>'brief',
        'request_token'=>bin2hex(random_bytes(16)),
    ], [], null, $guest);
}
function job(array $override=[]): array {
    global $jobs, $guest, $jobIds;
    $job = $jobs->ensureJobForPending(pending($override), null, $guest);
    $jobIds[] = $job['job_id'];
    return $job;
}
try {
    failure(fn()=>pending(['original_text'=>'  ']), 'INPUT_EMPTY');
    failure(fn()=>pending(['original_text'=>'*** !!!']), 'CONTENT_UNREADABLE');
    failure(fn()=>pending(['original_text'=>str_repeat('a',200001)]), 'INPUT_TOO_LARGE');
    failure(fn()=>pending(['analysis_mode'=>'invented']), 'SETTINGS_INVALID');
    failure(fn()=>pending(['document_title'=>str_repeat('a',256)]), 'TITLE_TOO_LONG');
    failure(fn()=>pending(['source_type'=>'url','original_text'=>'','source_url'=>'bad url']), 'URL_INVALID');
    failure(fn()=>pending(['source_type'=>'url','original_text'=>'','source_url'=>'http://127.0.0.1/private']), 'URL_UNSAFE');
    failure(fn()=>pending(['source_type'=>'text','source_url'=>'https://example.com']), 'SOURCE_CONFLICT');
    failure(fn()=>pending(['source_type'=>'file','original_text'=>'']), 'FILE_REQUIRED');
    check(pending()['original_text'] === 'The library opens on Monday morning.', 'valid short text accepted without fabricated minimum');
    check(strlen(pending(['original_text'=>str_repeat('a',200000)])['original_text'])===200000, 'exact text byte boundary');
    $unknown = ProcessingError::fromThrowable(new RuntimeException('C:\\private\\document.pdf secret-token'));
    check(!str_contains(json_encode($unknown->toArray()), 'private') && $unknown->errorCode==='PROCESSING_FAILED', 'unknown details do not leak');
    $forged = ProcessingError::fromWorker(['code'=>'FILE_ENCRYPTED','title'=>'sensitive','reference'=>'C:\\private']);
    check(!str_contains(json_encode($forged->toArray()),'sensitive') && preg_match('/^[a-f0-9]{24}$/',$forged->reference), 'worker prose and invalid reference ignored');

    $p = pending();
    $one = $jobs->ensureJobForPending($p, null, $guest); $jobIds[]=$one['job_id'];
    $p['job_id']=bin2hex(random_bytes(16));
    $two=$jobs->ensureJobForPending($p,null,$guest);
    check($one['job_id']===$two['job_id'],'same request token deduplicates submissions');
    check($jobs->getJobForOwner($one['job_id'],null,bin2hex(random_bytes(32)))===null,'foreign guest cannot read job');
    check($jobs->getJobForOwner($one['job_id'],null,$guest)!==null,'own guest can read job');
    check($jobs->markProcessing($one['job_id']),'first worker claims job');
    check(!$jobs->markProcessing($one['job_id']),'second worker cannot claim same attempt');
    $error = new ProcessingError('URL_ACCESS_RESTRICTED');
    $jobs->failJob($one['job_id'],$error,'failed',getmypid());
    $failed=$jobs->getJob($one['job_id']);
    check($failed['state']==='failed' && $failed['payload']==='{}','permanent failure clears retained source');
    check($jobs->failureForJob($failed)->reference===$error->reference,'failure reference persists across polls');
    check(!$jobs->canRetry($failed),'restricted source cannot be retried unchanged');
    $jobs->cancelJob($one['job_id']);
    check($jobs->getJob($one['job_id'])['state']==='failed','cancel does not overwrite failed classification');

    $temporary=job();
    foreach ([0,1,2] as $attempt) {
        $jobs->markProcessing($temporary['job_id']);
        $jobs->failJob($temporary['job_id'],new ProcessingError('WORKER_UNAVAILABLE'),'failed',getmypid());
        $temporary=$jobs->getJob($temporary['job_id']);
        check($jobs->canRetry($temporary)===($attempt<2),'bounded retry eligibility attempt '.$attempt);
        if ($attempt<2) {
            $id=$temporary['job_id'];
            $temporary=$jobs->retryJob($temporary);
            check($temporary['job_id']===$id && $temporary['state']==='queued','retry reuses same job');
        }
    }
    check($temporary['payload']==='{}','exhausted retry clears source');

    $timeout=job();
    $jobs->markProcessing($timeout['job_id']);
    $jobs->failJob($timeout['job_id'],new ProcessingError('PROCESSING_TIMEOUT'),'failed',getmypid());
    check($jobs->getJob($timeout['job_id'])['state']==='timed_out','timeout state reflects code');

    $cancel=job(); $jobs->markProcessing($cancel['job_id']); $jobs->cancelJob($cancel['job_id']);
    $jobs->failJob($cancel['job_id'],new ProcessingError('PROCESSING_FAILED'),'failed',getmypid());
    check($jobs->getJob($cancel['job_id'])['state']==='cancelled','late worker cannot overwrite cancellation');

    $complete=job(); $jobs->markProcessing($complete['job_id']);
    $result=['sentences'=>['The library opens on Monday morning.'],'plain_summary'=>'The library opens on Monday morning.','title'=>'LIGHT contract test fixture','validation_passed'=>true];
    $resultId=$article->storeSummary(null,$guest,$result,'text','standard_paragraph','The library opens on Monday morning.','brief',null,$complete['job_id']);
    $resultIds[]=$resultId;
    $completed=$jobs->getJob($complete['job_id']);
    check($completed['state']==='completed' && (string)$completed['result_id']===$resultId && $completed['payload']==='{}','result and completed state stored atomically');
    unset($_SESSION['stored_job_'.$complete['job_id']]);
    check($article->storeSummary(null,$guest,$result,'text','standard_paragraph','The library opens on Monday morning.','brief',null,$complete['job_id'])===$resultId,'completed result reused without duplicate entry');
    $jobs->cancelJob($complete['job_id']);
    $jobs->failJob($complete['job_id'],new ProcessingError('PROCESSING_FAILED'));
    check($jobs->getJob($complete['job_id'])['state']==='completed','completed job cannot be cancelled or failed');
    failure(fn()=>$article->storeSummary(null,$guest,['validation_passed'=>false]+$result,'text','standard_paragraph','text'),'SUMMARY_QUALITY_FAILED');
    failure(fn()=>$article->storeSummary(null,$guest,['sentences'=>[['unexpected'=>'nested']]]+$result,'text','standard_paragraph','text'),'SUMMARY_QUALITY_FAILED');
    failure(fn()=>$article->storeSummary(null,$guest,['plain_summary'=>['unexpected'=>'nested']]+$result,'text','standard_paragraph','text'),'SUMMARY_QUALITY_FAILED');
    failure(fn()=>$article->storeSummary(null,$guest,[],'text','standard_paragraph','text'),'SUMMARY_QUALITY_FAILED');
    failure(fn()=>$article->storeSummary(null,$guest,['thematic_paragraphs'=>[['content'=>['nested']]]],'text','standard_paragraph','text'),'SUMMARY_QUALITY_FAILED');
    echo "TOTAL: $checks checks passed\n";
} finally {
    foreach ($jobIds as $id) { $db->prepare('DELETE FROM summary_jobs WHERE job_id=? AND guest_token=?')->execute([$id,$guest]); }
    foreach ($resultIds as $id) { $db->prepare('DELETE FROM summaries WHERE id=? AND guest_token=?')->execute([$id,$guest]); }
}
