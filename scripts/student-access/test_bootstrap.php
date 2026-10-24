<?php
declare(strict_types=1);
// Test-only fixed loopback databases; never load an application connection string.
if(PHP_SAPI!=='cli' && !(PHP_SAPI==='cli-server' && getenv('LIGHT_STUDENT_FIXTURE')==='isolated-local-only' && in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true))) {http_response_code(403);exit;}
require_once __DIR__.'/../qa/isolation.php';
require_once __DIR__.'/../../app/src/Support/config.php';
require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../../app/src/Services/CampusAccessService.php';
require_once __DIR__.'/../../app/src/Services/StudentVerificationService.php';
require_once __DIR__.'/../../app/src/Services/StudentRetentionService.php';
function fixtureConnection(string $driver): PDO {
 if($driver!=='mysql')throw new RuntimeException('Only isolated XAMPP MySQL tests are supported.');
 return qaConnection();
}
function injectFixture(PDO $db): void {
 $reflect=new ReflectionClass(\App\Src\Database::class);$instance=$reflect->newInstanceWithoutConstructor();
 foreach(['pdo'=>$db] as $property=>$value){$p=$reflect->getProperty($property);$p->setAccessible(true);$p->setValue($instance,$value);}
 $p=$reflect->getProperty('instance');$p->setAccessible(true);$p->setValue(null,$instance);
}
function fixtureSchema(PDO $db): void {
 $id='INT AUTO_INCREMENT PRIMARY KEY';
 $db->exec("CREATE TABLE IF NOT EXISTS users(id $id,username VARCHAR(50) NOT NULL UNIQUE,email VARCHAR(100) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,role VARCHAR(20) NOT NULL DEFAULT 'user',active SMALLINT NOT NULL DEFAULT 1,terms_accepted SMALLINT NOT NULL DEFAULT 0,terms_accepted_at TIMESTAMP NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
 $db->exec(file_get_contents(__DIR__.'/../../database/migrations/017_student_number_access.sql'));
 $db->exec("CREATE TABLE IF NOT EXISTS documents(id $id,content TEXT NOT NULL,user_id INT NULL,guest_token VARCHAR(64) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
 $db->exec("CREATE TABLE IF NOT EXISTS document_summaries(id $id,document_id INT NOT NULL,profile VARCHAR(32) NOT NULL,length VARCHAR(32) NOT NULL,summary TEXT NOT NULL,word_count INT NOT NULL,source_word_count INT NOT NULL,compression_ratio DECIMAL(5,2),estimated_reading_time_seconds INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE(document_id,profile,length))");
 $db->exec("CREATE TABLE IF NOT EXISTS summaries(id $id,user_id INT NULL,guest_token VARCHAR(64) NULL,share_token VARCHAR(64) UNIQUE,article_title VARCHAR(255),input_type VARCHAR(20),summary_style VARCHAR(50),summary_length VARCHAR(32),article_category VARCHAR(60),original_word_count INT,summary_word_count INT,processing_time DECIMAL(12,4),status VARCHAR(20),nutshell_text TEXT NULL,nutshell_word_count INT NULL,nutshell_generated_at TIMESTAMP NULL)");
 $db->exec("CREATE TABLE IF NOT EXISTS summary_artifacts(id $id,summary_id INT NOT NULL,original_text TEXT,generated_summary TEXT)");
 $db->exec("CREATE TABLE IF NOT EXISTS summary_jobs(job_id VARCHAR(64) PRIMARY KEY,user_id INT NULL,guest_token VARCHAR(64),owner_key VARCHAR(100),request_token VARCHAR(100),state VARCHAR(20),payload TEXT,result_id INT NULL,worker_pid INT NULL,error_message TEXT NULL,error_details TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,completed_at TIMESTAMP NULL,started_at TIMESTAMP NULL,heartbeat_at TIMESTAMP NULL)");
 $db->exec("CREATE TABLE IF NOT EXISTS guest_sessions(guest_token VARCHAR(64) PRIMARY KEY,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,expires_at TIMESTAMP NOT NULL)");
 $db->exec("CREATE TABLE IF NOT EXISTS feedback(id $id,summary_id INT,user_id INT NULL,guest_token VARCHAR(64),rating INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
 $db->exec("CREATE TABLE IF NOT EXISTS rate_limits(id $id,action_type VARCHAR(30),identifier VARCHAR(255),attempts INT,window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE(action_type,identifier))");
 $db->exec("CREATE TABLE IF NOT EXISTS nutshell_generations(id $id,summary_id INT NULL,user_id INT NOT NULL,input_type VARCHAR(20),nutshell_text TEXT NULL,status VARCHAR(20),failure_reason VARCHAR(80) NULL,analysis_mode VARCHAR(30),output_format VARCHAR(30),word_count INT DEFAULT 0,source_word_count INT DEFAULT 0,primary_summary_word_count INT DEFAULT 0,compression_ratio DECIMAL(8,4),processing_time_ms INT,algorithm_version VARCHAR(100),idempotency_key VARCHAR(64) UNIQUE,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
}
function fixtureVisitor(\App\Src\Services\CampusAccessService $access): string {$_SESSION=[];$_COOKIE=[];return $access->visitorToken();}
/** Explicit historical fixture, not a registration bypass in application code. */
function fixtureLegacyPending(PDO $db,string $username,string $email,string $hash,string $number,string $visitor): array {
 qaConnection(); // Recheck the restricted database boundary before fixture writes.
 $number=\App\Src\Services\StudentVerificationService::normalizeNumber($number);
 $db->beginTransaction();
 try {
  \App\Src\Services\StudentEnrollmentRegistryService::lockMutation($db);
  $user=(new \App\Src\Repositories\UserRepository($db))->createPendingTermsAccount($username,$email,$hash);
  $now=time();
  $db->prepare("INSERT INTO student_applications(application_ref,user_id,student_number,normalized_student_number,student_number_fingerprint,campus,status,submitted_epoch,created_epoch,updated_epoch,details_delete_epoch,terms_version,privacy_version,consent_epoch) VALUES(?,?,?,?,?,'NEUST_PENARANDA','pending',?,?,?,?,?,?,?)")
   ->execute([bin2hex(random_bytes(16)),$user['id'],$number,$number,\App\Src\Services\StudentVerificationService::fingerprint($number),$now,$now,$now,$now+259200,'terms-registry-2026-10-v2','privacy-registry-2026-10-v2',$now]);
  $appId=(int)$db->lastInsertId();
  $db->prepare("INSERT INTO student_review_events(application_id,actor_user_id,action,new_status,student_message,created_epoch) VALUES(?,?,'submitted','pending',?,?)")
   ->execute([$appId,$user['id'],'Synthetic historical unverified account.',$now]);
  $db->prepare('UPDATE users SET terms_accepted=1,terms_accepted_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$user['id']]);
  (new \App\Src\Services\CampusAccessService($db))->trialSubject((int)$user['id'],$visitor);
  $db->commit();$user['terms_accepted']=1;return $user;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
