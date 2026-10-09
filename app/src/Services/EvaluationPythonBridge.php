<?php
declare(strict_types=1);
namespace App\Src\Services;

require_once __DIR__ . '/EvaluationService.php';

/** One bounded CLI-only Python process per worker; BERTScore models remain cached in memory. */
final class EvaluationPythonBridge
{
    private mixed $process = null;
    private array $pipes = [];
    private string $stdout = '';
    private string $stderr = '';
    private ?string $binary;
    private array $outputFiles = [];

    public function __construct(?string $binary = null) { $this->binary = $binary; }

    public function request(string $action, array $payload = [], int $timeoutSeconds = 300): array
    {
        if (PHP_SAPI !== 'cli') throw new \RuntimeException('Evaluation computation is available only to the offline CLI worker.');
        if (!in_array($action, ['capabilities','evaluate-item','statistics'], true)) throw new \InvalidArgumentException('Unsupported evaluation action.');
        foreach ($this->outputFiles as $file) {
            clearstatcache(true,$file);
            if (is_file($file) && filesize($file)>100000000) { $this->close(); break; }
        }
        if (!is_resource($this->process)) $this->start();
        $line = EvaluationService::json(['action'=>$action,'payload'=>$payload]) . "\n";
        if (strlen($line)>50000000) throw new \RuntimeException('Evaluation worker payload exceeds 50 MB.');
        $written = 0;
        $deadline = hrtime(true) + max(1,min($timeoutSeconds,3600))*1000000000;
        while ($written<strlen($line)) {
            $bytes = @fwrite($this->pipes[0], substr($line,$written,65536));
            if ($bytes===false || $bytes===0) { $this->close(); throw new \RuntimeException('Evaluation worker input pipe closed.'); }
            $written += $bytes;
        }
        fflush($this->pipes[0]);
        while (true) {
            $this->stdout .= stream_get_contents($this->pipes[1]);
            $this->stderr = substr($this->stderr . stream_get_contents($this->pipes[2]), -16000);
            if (strlen($this->stdout)>50000000) { $this->close(); throw new \RuntimeException('Evaluation worker response exceeds 50 MB.'); }
            $newline = strpos($this->stdout,"\n");
            if ($newline!==false) {
                $response=substr($this->stdout,0,$newline); $this->stdout=substr($this->stdout,$newline+1);
                $decoded=json_decode($response,true,512,JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) throw new \RuntimeException('Evaluation worker returned an invalid response.');
                return $decoded;
            }
            $status=proc_get_status($this->process);
            if (!$status['running']) { $this->close(); throw new \RuntimeException('Evaluation worker exited before returning a result. Check its dependencies and memory limits.'); }
            if (hrtime(true)>$deadline) { $this->close(); throw new \RuntimeException('Evaluation worker timed out. Other outputs can continue in a fresh process.'); }
            usleep(20000);
        }
    }

    private function start(): void
    {
        $root=dirname(__DIR__,3);
        $configured=$this->binary ?? config('python.bin');
        $candidates=array_filter([$configured,$root.'/.venv311/Scripts/python.exe',$root.'/.venv/Scripts/python.exe',$root.'/.venv/bin/python','python']);
        $binary=null;
        foreach ($candidates as $candidate) {
            if (str_contains($candidate,'/') || str_contains($candidate,'\\')) { if (is_file($candidate)) { $binary=$candidate; break; } }
            else { $binary=$candidate; break; }
        }
        if ($binary===null) throw new \RuntimeException('No Python executable is available for evaluation.');
        $environment=array_merge(getenv(),['PYTHONIOENCODING'=>'utf-8','PYTHONDONTWRITEBYTECODE'=>'1','OMP_NUM_THREADS'=>'1','MKL_NUM_THREADS'=>'1','OPENBLAS_NUM_THREADS'=>'1','TOKENIZERS_PARALLELISM'=>'false','HF_HOME'=>$root.'/storage/models/huggingface','HF_HUB_OFFLINE'=>getenv('EVALUATION_ALLOW_MODEL_DOWNLOAD')==='1' ? '0' : '1']);
        // Windows PHP cannot reliably make process pipes nonblocking. Regular-file tails
        // let the parent enforce deadlines while the same Python process caches its model.
        $temporary=$root.'/storage/tmp';
        if (!is_dir($temporary) && !mkdir($temporary,0700,true) && !is_dir($temporary)) throw new \RuntimeException('Evaluation temporary storage is unavailable.');
        $this->outputFiles=[tempnam($temporary,'eval_out_'),tempnam($temporary,'eval_err_')];
        foreach ($this->outputFiles as $file) if ($file===false) throw new \RuntimeException('Evaluation temporary output could not be created.');
        $this->process=@proc_open([$binary,'-u',$root.'/python-engine/evaluation_cli.py','stream'],[0=>['pipe','r'],1=>['file',$this->outputFiles[0],'ab'],2=>['file',$this->outputFiles[1],'ab']],$this->pipes,$root.'/python-engine',$environment,['bypass_shell'=>true,'create_no_window'=>true]);
        if (!is_resource($this->process)) throw new \RuntimeException('Unable to start the evaluation worker process.');
        $this->pipes[1]=fopen($this->outputFiles[0],'rb'); $this->pipes[2]=fopen($this->outputFiles[1],'rb');
        $this->stdout=''; $this->stderr='';
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            if (isset($this->pipes[0]) && is_resource($this->pipes[0])) fclose($this->pipes[0]);
            $status=proc_get_status($this->process);
            if ($status['running']) proc_terminate($this->process);
        }
        foreach ([1,2] as $i) if (isset($this->pipes[$i]) && is_resource($this->pipes[$i])) fclose($this->pipes[$i]);
        if (is_resource($this->process)) proc_close($this->process);
        foreach ($this->outputFiles as $file) if (is_string($file) && is_file($file)) unlink($file);
        $this->process=null; $this->pipes=[]; $this->outputFiles=[];
    }
    public function __destruct() { $this->close(); }
}
