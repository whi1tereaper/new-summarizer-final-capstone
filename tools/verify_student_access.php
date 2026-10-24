<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../app/src/Support/config.php';
require_once __DIR__.'/../app/src/Database.php';
require_once __DIR__.'/../app/src/Services/StudentVerificationService.php';
try{
 $db=\App\Src\Database::getInstance()->getConnection();$checks=[];
 foreach(['student_applications','student_review_events','student_trial_links','summary_access_subjects','summary_access_reservations','student_registry_gate','student_registry_batches','student_enrollment_registry','student_registry_events'] as $table){
  $q=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);$checks[$table]=(int)$q->fetchColumn()===1;
 }
 echo json_encode(['driver'=>'mysql','read_only'=>true,'tables'=>$checks,'privacy_contact_configured'=>filter_var(config('student_access.privacy_contact',''),FILTER_VALIDATE_EMAIL)!==false,'student_key_configured'=>strlen((string)base64_decode(config('student_access.number_secret',''),true))===32,'enrollment_processing_authorized'=>config('student_access.enrollment_authorized',false),'student_registration_configured'=>!in_array(false,$checks,true)&&\App\Src\Services\StudentVerificationService::configurationError()===null,'note'=>'Quota/concurrency probes run only via scripts/qa/run.py on an isolated database.'],JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'Local student-access schema is unavailable; no data was changed.'.PHP_EOL);exit(1);}
