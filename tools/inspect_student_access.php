<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__.'/../app/src/Support/config.php';
require_once __DIR__.'/../app/src/Database.php';
$db=App\Src\Database::getInstance()->getConnection();
$driver=$db->getAttribute(PDO::ATTR_DRIVER_NAME);
$audit=['recorded_utc'=>gmdate('c'),'driver'=>$driver,'read_only'=>true,'columns'=>[]];
foreach(['users','guest_sessions','summaries','summary_artifacts','summary_jobs','documents','document_summaries','student_applications'] as $table) {
        try{$audit['columns'][$table]=$db->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);}
        catch(PDOException $e){if($e->getCode()!=='42S02')throw $e;$audit['columns'][$table]=[];}
}
$audit['account_counts_by_role']=$db->query('SELECT role,COUNT(*) AS accounts FROM users GROUP BY role')->fetchAll(PDO::FETCH_ASSOC);
$root=dirname(__DIR__);$audit['nlp_sha256']=[];
foreach(['python-engine/article_engine','python-engine/summarizer_core'] as $directory) {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory)) as $file) {
        if($file->isFile() && in_array($file->getExtension(),['py','json'],true))
            $audit['nlp_sha256'][str_replace('\\','/',substr($file->getPathname(),strlen($root)+1))]=hash_file('sha256',$file->getPathname());
    }
}
if(in_array('--save-baseline',$argv,true)) {
    $folder=$root.'/docs/student-number-access';if(!is_dir($folder))mkdir($folder,0755,true);
    if(file_exists($folder.'/baseline.json'))throw new RuntimeException('Baseline already exists.');
    file_put_contents($folder.'/baseline.json',json_encode($audit,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    echo json_encode(['driver'=>$driver,'baseline_saved'=>true,'tables_inspected'=>count($audit['columns']),'account_counts_by_role'=>$audit['account_counts_by_role']])."\n";
}else echo json_encode($audit,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
