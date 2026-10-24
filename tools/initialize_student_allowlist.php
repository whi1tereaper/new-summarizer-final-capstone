<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../app/src/Support/config.php';
require_once __DIR__.'/../app/src/Services/StudentEnrollmentRegistryService.php';
try{
 $apply=in_array('--apply',$argv,true);
 $parsed=App\Src\Services\StudentEnrollmentRegistryService::parse(STDIN);
 if($parsed['total']!==38||$parsed['invalid']!==0||$parsed['duplicates']!==0||count($parsed['ids'])!==38)throw new InvalidArgumentException('Supply exactly the 38 unique authoritative full IDs through standard input.');
 $db=App\Src\Database::getInstance()->getConnection();
 $name=(string)$db->query('SELECT DATABASE()')->fetchColumn();
 if(!in_array(config('database.host'),['127.0.0.1','localhost'],true)||(int)config('database.port')!==3307||($name!=='ai_summarizer'&&!preg_match('/\Aqa_light_[a-f0-9]{8}\z/',$name)))throw new RuntimeException('Only the scoped local XAMPP database is supported.');
 if(!$apply){
  $q=$db->prepare('SELECT COUNT(*) FROM student_enrollment_registry WHERE normalized_student_number IN ('.implode(',',array_fill(0,38,'?')).')');$q->execute($parsed['ids']);
  $policy=$db->query('SELECT initial_applied_epoch FROM student_registry_policy WHERE id=1')->fetch(PDO::FETCH_ASSOC);
  echo json_encode(['dry_run'=>true,'initial_count'=>38,'existing_initial_ids'=>(int)$q->fetchColumn(),'registry_total'=>(int)$db->query('SELECT COUNT(*) FROM student_enrollment_registry')->fetchColumn(),'already_initialized'=>($policy['initial_applied_epoch']??null)!==null],JSON_PRETTY_PRINT).PHP_EOL;
 }else echo json_encode((new App\Src\Services\StudentEnrollmentRegistryService($db))->initializeAllowlist($parsed['ids']),JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,($error instanceof InvalidArgumentException?$error->getMessage():'Allowlist initialization failed safely. Inspect migration/configuration; no partial transaction is retained.').PHP_EOL);exit(1);}
