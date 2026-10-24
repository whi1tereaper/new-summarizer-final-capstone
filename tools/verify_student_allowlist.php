<?php
declare(strict_types=1);
// Read-only local acceptance check. The roster is private STDIN, never source code.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../app/src/Support/config.php';
require_once __DIR__.'/../app/src/Services/StudentEnrollmentRegistryService.php';
use App\Src\Services\StudentEnrollmentRegistryService as Registry;
use App\Src\Services\CampusAccessService as Access;
try{
    $options=getopt('',['baseline:','report:']);
    $parsed=Registry::parse(STDIN);
    if($parsed['total']!==38||$parsed['invalid']||$parsed['duplicates']||count($parsed['ids'])!==38)throw new InvalidArgumentException('Supply the exact 38 unique initial IDs through private standard input.');
    $db=App\Src\Database::getInstance()->getConnection();
    if(!in_array(config('database.host'),['127.0.0.1','localhost'],true)||(int)config('database.port')!==3307||$db->query('SELECT DATABASE()')->fetchColumn()!=='ai_summarizer')throw new RuntimeException('Only the existing local production database may be inspected.');
    $checks=[];$check=static function(string $name,bool $ok)use(&$checks):void{$checks[]=['name'=>$name,'passed'=>$ok];};
    $sorted=$parsed['ids'];sort($sorted,SORT_STRING);
    $policy=$db->query('SELECT * FROM student_registry_policy WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $check('initial authoritative list digest matches the private requested roster',$policy&&hash_equals($policy['initial_allowlist_sha256'],hash('sha256',implode("\n",$sorted))));
    $q=$db->prepare('SELECT normalized_student_number,eligibility_status,eligible_until_epoch,claim_conflict FROM student_enrollment_registry WHERE normalized_student_number IN ('.implode(',',array_fill(0,38,'?')).')');$q->execute($parsed['ids']);$initial=$q->fetchAll(PDO::FETCH_ASSOC);
    $eligible=array_filter($initial,fn($r)=>$r['eligibility_status']==='active'&&(int)$r['eligible_until_epoch']>time()&&(int)$r['claim_conflict']===0);
    $check('all 38 exact initial IDs remain active unexpired and conflict-free',count($initial)===38&&count($eligible)===38);
    $check('all 20 migrations are recorded',(int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()===20&&(int)$db->query("SELECT COUNT(*) FROM schema_migrations WHERE migration='020_registry_only_allowlist.sql'")->fetchColumn()===1);
    $check('privacy contact protection key and operator authorization remain configured',App\Src\Services\StudentVerificationService::configurationError()===null);
    $counts=[];foreach(['users','summaries','summary_artifacts','feedback','feedback_comments','student_applications','student_enrollment_registry'] as $table)$counts[$table]=(int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
    $access=new Access($db);$approved=0;$admins=0;$restricted=0;
    foreach($db->query('SELECT id,role,active FROM users')->fetchAll(PDO::FETCH_ASSOC) as $user){
        $continued=(int)$user['active']===1&&$access->continuedAccess((int)$user['id']);
        if($user['role']==='admin'&&(int)$user['active']===1){$admins++;$check('administrator #'.(int)$user['id'].' retains existing access',$continued);}
        elseif($continued)$approved++;else $restricted++;
    }
    $unchanged=[];
    if(isset($options['baseline'])){
        $baseline=json_decode((string)file_get_contents($options['baseline']),true,512,JSON_THROW_ON_ERROR)['before'];
        foreach(['users','summaries','summary_artifacts','feedback','feedback_comments','documents','document_summaries','evaluation_runs','evaluation_outputs','evaluation_metrics'] as $table){
            $rows=$db->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);$hashes=array_map(fn($row)=>hash('sha256',json_encode($row,JSON_INVALID_UTF8_SUBSTITUTE)),$rows);sort($hashes);
            $unchanged[$table]=count($rows)===$baseline[$table]['rows']&&hash_equals($baseline[$table]['sha256'],hash('sha256',implode('',$hashes)));
            $check('original '.$table.' rows remain byte-for-byte identical',$unchanged[$table]);
        }
    }
    $report=['checked_utc'=>gmdate('c'),'php_cli'=>PHP_VERSION,'database_engine'=>$db->getAttribute(PDO::ATTR_DRIVER_NAME),'port'=>(int)config('database.port'),'initial_ids_present'=>count($initial),'initial_ids_active'=>count($eligible),'counts'=>$counts,'approved_student_accounts'=>$approved,'restricted_nonadministrator_accounts'=>$restricted,'administrators_exempt'=>$admins,'active_summary_jobs'=>(int)$db->query("SELECT COUNT(*) FROM summary_jobs WHERE state IN ('queued','processing')")->fetchColumn(),'reconciliation'=>json_decode($policy['latest_reconciliation_json']??'{}',true),'checks'=>$checks];
    $report['passed']=count(array_filter($checks,fn($r)=>$r['passed']));$report['failed']=count($checks)-$report['passed'];
    $json=json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
    if(isset($options['report']))file_put_contents($options['report'],$json);
    echo $json;exit($report['failed']?1:0);
}catch(Throwable $error){fwrite(STDERR,'Read-only allowlist validation failed: '.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine().'. No database writes were performed.'.PHP_EOL);exit(1);}
