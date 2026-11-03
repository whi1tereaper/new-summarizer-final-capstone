<?php
declare(strict_types=1);
namespace App\Src\Services;
require_once __DIR__.'/../Database.php';
require_once __DIR__.'/../Support/RuntimePaths.php';
require_once __DIR__.'/../Support/ProcessingError.php';
use App\Src\Database;
use App\Src\Support\RuntimePaths;
use App\Src\Support\ProcessingError;
use PDO;

/** Read-only measurements; never invokes cleanup, reads document bodies or returns paths. */
final class StorageMaintenanceService
{
    private const MAX_ENTRIES=20000;
    private const MAX_SCAN_SECONDS=2;
    private PDO $db;
    public function __construct(?PDO $db=null){$this->db=$db??Database::getInstance()->getConnection();}

    public function snapshot(int $actor): array
    {
        clearstatcache();
        $q=$this->db->prepare("SELECT 1 FROM users WHERE id=? AND role='admin' AND active=1");$q->execute([$actor]);
        if(!$q->fetchColumn())throw new ProcessingError('ACCESS_DENIED');
        $warnings=[];$database=['connected'=>true,'summary_records'=>null,'saved_summaries'=>null,'nutshells'=>null,'queued_jobs'=>null,'processing_jobs'=>null,'allocated_bytes'=>null];
        try{$r=$this->db->query("SELECT COUNT(*) AS records,COALESCE(SUM(status='completed'),0) AS completed FROM summaries")->fetch(PDO::FETCH_ASSOC);$database['summary_records']=(int)$r['records'];$database['saved_summaries']=(int)$r['completed'];}catch(\Throwable $e){$warnings[]='Saved-summary statistics are unavailable.';}
        try{$database['nutshells']=(int)$this->db->query("SELECT COUNT(*) FROM nutshell_generations WHERE status='completed'")->fetchColumn();}catch(\Throwable $e){$warnings[]='Nutshell statistics are unavailable.';}
        try{
            $database['queued_jobs']=0;$database['processing_jobs']=0;
            foreach($this->db->query("SELECT state,COUNT(*) AS total FROM summary_jobs WHERE state IN ('queued','processing') GROUP BY state")->fetchAll(PDO::FETCH_ASSOC) as $row)$database[$row['state']==='queued'?'queued_jobs':'processing_jobs']=(int)$row['total'];
        }catch(\Throwable $e){$database['queued_jobs']=null;$database['processing_jobs']=null;$warnings[]='Summary-job statistics are unavailable.';}
        try{$database['allocated_bytes']=(int)$this->db->query('SELECT COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();}catch(\Throwable $e){$warnings[]='Database allocation estimate is unavailable.';}
        $root=RuntimePaths::storageRoot();
        $audio=array_filter([RuntimePaths::audioDirectory(),config('tts.audio_output_dir'),config('tts.output_dir'),config('tts.piper_cache_dir')],static fn($path)=>is_string($path)&&$path!=='');
        $categories=[
            'uploads'=>['Temporary uploads',[RuntimePaths::uploadsDirectory()]],
            'audio'=>['Generated audio / cache',$audio],
            'temporary'=>['Processing temporary files',[RuntimePaths::temporaryDirectory()]],
            'logs'=>['Application logs',[RuntimePaths::logsDirectory()]],
            'models'=>['Storage model cache',[$root.DIRECTORY_SEPARATOR.'models']],
            'backups'=>['Private backups',[$root.DIRECTORY_SEPARATOR.'backups']],
        ];
        $folders=[];$visited=[];$entries=0;$deadline=microtime(true)+self::MAX_SCAN_SECONDS;$totalBytes=0;$totalFiles=0;$complete=true;
        foreach($categories as $key=>[$label,$paths]){
            $folder=['label'=>$label,'files'=>0,'bytes'=>0,'readable'=>true,'writable'=>true,'complete'=>true,'status'=>'ready'];$unique=[];
            foreach($paths as $path){
                $identity=strtolower(str_replace('\\','/',rtrim(realpath($path)?:$path,'/\\')));
                if(isset($unique[$identity]))continue;$unique[$identity]=true;
                $scanned=$this->scan($path,$visited,$entries,$deadline);
                $folder['files']+=$scanned['files'];$folder['bytes']+=$scanned['bytes'];
                $folder['readable']=$folder['readable']&&$scanned['readable'];$folder['writable']=$folder['writable']&&$scanned['writable'];$folder['complete']=$folder['complete']&&$scanned['complete'];
                if($scanned['status']!=='ready')$folder['status']=$scanned['status'];
            }
            if(!$folder['complete']){$complete=false;$warnings[]=$label.' could not be measured completely.';}
            if(!$folder['writable']&&$folder['status']==='ready')$folder['status']='read_only';
            if($folder['status']!=='ready'&&$folder['complete'])$warnings[]=$label.' requires attention.';
            $totalBytes+=$folder['bytes'];$totalFiles+=$folder['files'];$folders[$key]=$folder;
        }
        return ['status'=>$warnings?'warning':'ok','checked_at'=>date(DATE_ATOM),'database'=>$database,
            'runtime'=>['files'=>$totalFiles,'bytes'=>$totalBytes,'complete'=>$complete,'folders'=>$folders],
            'warnings'=>array_values(array_unique($warnings)),
            'scope'=>'Configured runtime folders and storage model/backup folders only; Python environments and external model caches are not included.'];
    }

    private function scan(string $configured,array &$visited,int &$entries,float $deadline): array
    {
        $result=['files'=>0,'bytes'=>0,'readable'=>false,'writable'=>false,'complete'=>false,'status'=>'missing'];
        $root=realpath($configured);
        if($root===false||!is_dir($root))return $result;
        $normalized=strtolower(str_replace('\\','/',rtrim($root,'/\\')));
        $project=strtolower(str_replace('\\','/',RuntimePaths::projectRoot()));
        // Misconfiguration must never trigger a scan of a drive/workspace/home root.
        $userProfile=(string)getenv('USERPROFILE');
        $userProfileRoot=$userProfile===''?false:realpath($userProfile);
        $userProfileRoot=$userProfileRoot===false?null:strtolower(str_replace('\\','/',rtrim($userProfileRoot,'/\\')));
        if($normalized===''||preg_match('~^[a-z]:$~',$normalized)||$normalized===$project||str_starts_with($project.'/', $normalized.'/')||($userProfileRoot!==null&&($normalized===$userProfileRoot||str_starts_with($userProfileRoot.'/', $normalized.'/')))){$result['status']='unsafe_root';return $result;}
        if(is_link($configured)){$result['status']='linked_root';return $result;}
        $result['readable']=is_readable($root);$result['writable']=is_writable($root);
        if(!$result['readable']){$result['status']='unreadable';return $result;}
        $result['complete']=true;$result['status']='ready';$stack=[$root];
        while($stack){
            if($entries>=self::MAX_ENTRIES||microtime(true)>=$deadline){$result['complete']=false;$result['status']='limited';break;}
            $directory=array_pop($stack);$real=realpath($directory);
            if($real===false){$result['complete']=false;$result['status']='limited';continue;}
            $key=strtolower(str_replace('\\','/',rtrim($real,'/\\')));
            if($key!==$normalized&&!str_starts_with($key,$normalized.'/'))continue;
            if(isset($visited['dir:'.$key])){$result['complete']=false;$result['status']='overlap';continue;}$visited['dir:'.$key]=true;
            try{
                foreach(new \FilesystemIterator($real,\FilesystemIterator::SKIP_DOTS) as $entry){
                    if(++$entries>self::MAX_ENTRIES||microtime(true)>=$deadline){$result['complete']=false;$result['status']='limited';break 2;}
                    $name=$entry->getFilename();if(in_array(strtolower($name),['.gitkeep','.htaccess','.ds_store','thumbs.db'],true)||$entry->isLink())continue;
                    $path=$entry->getRealPath();if($path===false){$result['complete']=false;$result['status']='limited';continue;}
                    $fileKey=strtolower(str_replace('\\','/',$path));
                    if(!str_starts_with($fileKey,$normalized.'/'))continue;
                    if($entry->isDir()){$stack[]=$path;continue;}
                    if(!$entry->isFile()||isset($visited['file:'.$fileKey]))continue;
                    $size=$entry->getSize();$visited['file:'.$fileKey]=true;$result['files']++;$result['bytes']+=$size;
                }
            }catch(\Throwable $error){$result['complete']=false;$result['status']='unreadable';}
        }
        return $result;
    }

    public static function formatBytes(?int $bytes): string
    {
        if($bytes===null)return 'Unavailable';
        $units=['B','KiB','MiB','GiB','TiB'];$value=max(0,$bytes);$index=0;
        while($value>=1024&&$index<count($units)-1){$value/=1024;$index++;}
        return number_format($value,$index===0?0:2).' '.$units[$index];
    }
}
