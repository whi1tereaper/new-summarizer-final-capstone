<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../app/src/Support/config.php';
require_once __DIR__ . '/../app/src/Services/EvaluationService.php';
require_once __DIR__ . '/../app/src/Services/EvaluationPythonBridge.php';

use App\Src\Database;
use App\Src\Services\EvaluationService;
use App\Src\Services\EvaluationPythonBridge;

$options=getopt('',['run:','once','report:','timeout:','help']);
if (isset($options['help'])) {
    echo "Offline evaluation worker\nphp scripts/evaluation_worker.php --once [--run ID] [--timeout 300]\nphp scripts/evaluation_worker.php --report ID\nWithout --once, drain the current queue then exit. One worker per database; no HTTP processing.\n";
    exit(0);
}
$runId=isset($options['run']) ? filter_var($options['run'],FILTER_VALIDATE_INT) : null;
$reportId=isset($options['report']) ? filter_var($options['report'],FILTER_VALIDATE_INT) : null;
$timeout=isset($options['timeout']) ? filter_var($options['timeout'],FILTER_VALIDATE_INT) : 300;
if (($runId!==null && (!$runId || $runId<1)) || ($reportId!==null && (!$reportId || $reportId<1)) || !$timeout || $timeout<1 || $timeout>3600) { fwrite(STDERR,"Invalid positive run ID or timeout (1–3600 seconds).\n"); exit(1); }
$db=Database::getInstance()->getConnection();
$lockName='evaluation_worker_' . substr(hash('sha256',(string)config('database.name')),0,32);
$lock=$db->prepare('SELECT GET_LOCK(?,0)'); $lock->execute([$lockName]);
if ((int)$lock->fetchColumn()!==1) { fwrite(STDERR,"Another evaluation worker owns this database. Try again after it finishes.\n"); exit(2); }
$service=new EvaluationService($db); $bridge=new EvaluationPythonBridge(); $exitCode=0;
$workerCodeFingerprint=EvaluationService::codeFingerprint();
try {
    do {
        $run=$service->nextRun($reportId ?? $runId);
        if (!$run) { if ($runId || $reportId) throw new RuntimeException('Run was not found.'); echo "No queued evaluation work.\n"; break; }
        $id=(int)$run['id'];
        if ($reportId===null && $run['status']==='queued') {
            if (!hash_equals($run['configuration']['code_fingerprint'],EvaluationService::codeFingerprint())) {
                $service->finishRun($id,'Evaluation code changed after this run was queued. Queue a new run with the current code version.');
                fwrite(STDERR,"Run {$id}: queued code fingerprint no longer matches.\n"); $exitCode=1;
            } else {
                $service->startRun($id);
                echo "Run {$id}: processing {$run['outputs_total']} configured outputs.\n";
                $runFailure=null;
                foreach ($service->pendingOutputs($id) as $output) {
                    if ($service->isCancelled($id)) { echo "Run {$id}: cancelled; recorded outputs preserved.\n"; break; }
                    if (!hash_equals($run['configuration']['code_fingerprint'],EvaluationService::codeFingerprint())) {
                        $runFailure='Evaluation code changed during the run. Recorded outputs are preserved; queue a new run with a stable code version.';
                        fwrite(STDERR,"Run {$id}: source code changed; remaining outputs were not generated.\n");
                        $exitCode=1; break;
                    }
                    try {
                        $result=$bridge->request('evaluate-item',$service->workerPayload($run,$output),$timeout);
                        $service->persistOutput((int)$output['id'],$result);
                        echo 'Output '.$output['id'].': '.($result['status'] ?? 'failed')."\n";
                    } catch (Throwable $e) {
                        // An atomic output transaction rolls back all partial metrics before failure is stored.
                        $service->failOutput((int)$output['id'],$e->getMessage());
                        fwrite(STDERR,'Output '.$output['id'].": failed; remaining outputs will continue.\n");
                        $exitCode=1;
                    }
                }
                $service->finishRun($id,$runFailure);
            }
        } elseif ($reportId===null && $run['status']==='running') {
            throw new RuntimeException('Run is marked running. Resume its interrupted pending work from the administrator page first.');
        }
        try {
            if (!hash_equals($workerCodeFingerprint,EvaluationService::codeFingerprint())) throw new RuntimeException('Evaluation source changed after this worker started. Refresh the report using a new worker process.');
            $statisticsPayload=$service->statisticsPayload($id);
            $inputHash=EvaluationService::canonicalHash($statisticsPayload);
            $report=$bridge->request('statistics',$statisticsPayload,$timeout);
            if (!isset($report['automatic'],$report['human'],$report['agreement'])) throw new RuntimeException('Statistics worker returned an invalid report.');
            if (!hash_equals($workerCodeFingerprint,EvaluationService::codeFingerprint())) throw new RuntimeException('Evaluation source changed while statistics were running. Start a fresh report calculation.');
            $report['reproducibility']=['computed_at_utc'=>gmdate('c'),'input_sha256'=>$inputHash,'analysis_code_fingerprint'=>$workerCodeFingerprint];
            $service->saveReport($id,$report,$inputHash); echo "Run {$id}: descriptive report stored.\n";
        } catch (Throwable $e) {
            $service->reportFailure($id,$e->getMessage()); fwrite(STDERR,"Run {$id}: statistics unavailable; output records remain intact.\n"); $exitCode=1;
        }
        if (isset($options['once']) || $runId!==null || $reportId!==null) break;
    } while (true);
} catch (Throwable $e) {
    fwrite(STDERR,'Evaluation worker: '.$e->getMessage()."\n"); $exitCode=1;
} finally {
    $bridge->close();
    try { $release=$db->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lockName]); }
    catch (Throwable $releaseError) {
        // MySQL releases advisory locks when a connection is lost. Never mask the actual
        // worker error with a second uncaught exception during cleanup.
        fwrite(STDERR,"Worker cleanup: database connection closed; its advisory lock is released by MySQL.\n");
        $exitCode=1;
    }
}
exit($exitCode);
