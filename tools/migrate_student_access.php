<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../app/src/Support/config.php';
require_once __DIR__.'/../app/src/Database.php';
$apply=in_array('--apply',$argv,true);$file=__DIR__.'/../database/migrations/017_student_number_access.sql';
try{
 $db=\App\Src\Database::getConfiguredMySqlConnection();
 if(!$apply){echo json_encode(['driver'=>'mysql','apply'=>false,'migration'=>basename($file),'sha256'=>hash_file('sha256',$file),'note'=>'Review a verified backup before applying; no SQL was executed.'],JSON_PRETTY_PRINT).PHP_EOL;exit;}
 // MySQL DDL implicitly commits. CREATE IF NOT EXISTS is repeatable, not transactional.
 $db->exec(file_get_contents($file));
 foreach(['student_applications','student_review_events','student_trial_links','summary_access_subjects','summary_access_reservations'] as $table)$db->query('SELECT 1 FROM '.$table.' LIMIT 1');
 echo json_encode(['driver'=>'mysql','apply'=>true,'migration'=>basename($file),'note'=>'Access tables present. Existing accounts were not reset; migration ledger is unchanged.']).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'MySQL migration failed; DDL may have partially applied. Inspect schema before retrying; no rollback is claimed.'.PHP_EOL);exit(1);}
