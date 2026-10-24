<?php
declare(strict_types=1);
// Restores only into an ephemeral QA database, never into the live application.
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../../app/src/Support/config.php';
require_once __DIR__.'/../../app/src/Database.php';
$folder=realpath($argv[1]??'');$local=realpath((string)getenv('LOCALAPPDATA'));
$pathKey=static fn($path)=>strtolower(str_replace('\\','/',(string)$path));
if(!$folder||!$local||!str_starts_with($pathKey($folder),$pathKey($local).'/light-allowlist-restore-'))throw new RuntimeException('A private, operator-only LOCALAPPDATA test folder is required.');
$root=dirname(__DIR__,2);$meta=json_decode((string)file_get_contents($argv[2]??$root.'/docs/qa/backup.json'),true,512,JSON_THROW_ON_ERROR);
$reportFile=$argv[3]??$root.'/docs/qa/allowlist-replacement/restored-baseline-integrity.json';
$reportParent=realpath(dirname($reportFile));
if(!$reportParent||!str_starts_with($pathKey($reportParent),$pathKey(realpath($root.'/docs/qa')).'/'))throw new RuntimeException('Integrity reports must stay inside a QA report directory.');
$backup=realpath($meta['path']??'');
if(!$backup||str_contains($pathKey($backup),'/htdocs/')||!hash_equals($meta['sha256'],hash_file('sha256',$backup)))throw new RuntimeException('Private baseline backup integrity failed.');
$live=App\Src\Database::getConfiguredMySqlConnection();
if($live->query('SELECT DATABASE()')->fetchColumn()!=='ai_summarizer'||(int)config('database.port')!==3307)throw new RuntimeException('Only the existing local XAMPP database may be compared.');
$target='qa_light_restore_'.bin2hex(random_bytes(4));$created=false;$defaults=$folder.'/mysql.cnf';
try{
    if(!preg_match('/\Aqa_light_restore_[a-f0-9]{8}\z/',$target))throw new RuntimeException('Unsafe restore scope.');
    $live->exec('CREATE DATABASE `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
    $quote=static fn($value)=>'"'.addcslashes((string)$value,"\\\"").'"';
    file_put_contents($defaults,"[client]\nhost=127.0.0.1\nport=3307\nuser=".$quote(config('database.username'))."\npassword=".$quote(config('database.password'))."\ndefault-character-set=utf8mb4\n");chmod($defaults,0600);
    $pipes=[];$process=proc_open(['C:/xampp/mysql/bin/mysql.exe','--defaults-extra-file='.$defaults,$target],[0=>['file',$backup,'r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
    foreach($pipes as $pipe){stream_get_contents($pipe);fclose($pipe);}if(proc_close($process)!==0)throw new RuntimeException('Baseline could not be restored into isolation.');
    $restored=new PDO('mysql:host=127.0.0.1;port=3307;dbname='.$target.';charset=utf8mb4',config('database.username'),config('database.password'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $checks=[];
    foreach(['users','summaries','summary_artifacts','feedback','feedback_comments','documents','document_summaries','nutshell_generations','evaluation_runs','evaluation_outputs','evaluation_metrics','evaluation_assignments','evaluation_ratings','evaluation_references','evaluation_documents','evaluation_datasets','evaluation_errors','evaluation_content_units','evaluation_content_unit_reviews','evaluation_challenge_cases'] as $table){
        $original=$restored->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);$current=$live->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        $hash=static fn($row)=>hash('sha256',json_encode($row,JSON_INVALID_UTF8_SUBSTITUTE));$old=array_map($hash,$original);$now=array_map($hash,$current);
        $detail=['table'=>$table,'original_rows'=>count($old),'current_rows'=>count($now),'passed'=>array_diff($old,$now)===[]];
        if($table==='summaries'){
            $currentIds=array_column($current,'id');$missing=array_filter($original,fn($row)=>!in_array($row['id'],$currentIds,true));
            $detail['missing_rows']=count($missing);$detail['missing_account_owned']=count(array_filter($missing,fn($row)=>$row['user_id']!==null));
            $detail['original_account_owned_rows']=count(array_filter($original,fn($row)=>$row['user_id']!==null));
            $detail['account_owned_rows_preserved']=array_diff(array_map($hash,array_filter($original,fn($row)=>$row['user_id']!==null)),$now)===[];
            $detail['missing_guest_owned']=count($missing)-$detail['missing_account_owned'];
            $detail['missing_legacy_guest']=count(array_filter($missing,fn($row)=>$row['user_id']===null&&empty($row['guest_token'])));
            $detail['missing_older_than_guest_ttl']=count(array_filter($missing,fn($row)=>strtotime($row['created_at'])<time()-86400));
            $detail['missing_matches_existing_guest_cleanup']=0;
            foreach($missing as $row){
                if($row['user_id']!==null)continue;
                if(empty($row['guest_token'])){$detail['missing_matches_existing_guest_cleanup']+=(strtotime($row['created_at'])<time()-86400);continue;}
                $q=$restored->prepare('SELECT expires_at FROM guest_sessions WHERE guest_token=?');$q->execute([$row['guest_token']]);$expiry=$q->fetchColumn();
                $detail['missing_matches_existing_guest_cleanup']+=($expiry&&strtotime($expiry)<=time());
            }
        }
        $checks[]=$detail;
    }
    $failed=count(array_filter($checks,fn($r)=>!$r['passed']));
    $report=['backup_sha256'=>$meta['sha256'],'restored_only_into_isolation'=>true,'allows_legitimate_new_live_rows'=>true,'passed'=>count($checks)-$failed,'failed'=>$failed,'checks'=>$checks];
    file_put_contents($reportFile,json_encode($report,JSON_PRETTY_PRINT));
    echo json_encode($report,JSON_PRETTY_PRINT).PHP_EOL;
}finally{
    if(is_file($defaults))unlink($defaults);
    if($created)$live->exec('DROP DATABASE `'.$target.'`');
}
exit($failed?1:0);
