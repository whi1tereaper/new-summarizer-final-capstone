<?php
declare(strict_types=1);
// Local operator maintenance only. No HTTP route or application-role override.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__.'/../app/src/Support/config.php';
require_once __DIR__.'/../app/src/Database.php';
require_once __DIR__.'/../app/src/Services/StudentVerificationService.php';

/** Correct one explicitly approved legacy PEN- prefix typo, never perform fuzzy matching. */
function repairStudentIdLink(PDO $db,array $input,bool $apply=false,?string $backupDirectory=null): array
{
    $verification=new App\Src\Services\StudentVerificationService($db);
    if ($setup=$verification::configurationError()) throw new InvalidArgumentException($setup);
    $user=filter_var($input['user_id']??null,FILTER_VALIDATE_INT);
    $version=filter_var($input['expected_version']??null,FILTER_VALIDATE_INT);
    if (!$user||$user<1||!$version||$version<1) throw new InvalidArgumentException('Specify the existing account and expected application version.');
    $old=$verification::normalizeNumber(is_string($input['old_student_number']??null)?$input['old_student_number']:'');
    $number=$verification::normalizeNumber(is_string($input['student_number']??null)?$input['student_number']:'');
    if (!str_starts_with($old,'PEN-')||$number!=='PEN'.substr($old,4)) throw new InvalidArgumentException('Only an explicitly confirmed extra-hyphen correction after PEN is supported.');
    $reason=trim(is_string($input['reason']??null)?$input['reason']:'');
    if (($input['operator_approved']??false)!==true||strlen($reason)<10||strlen($reason)>500) throw new InvalidArgumentException('Explicit operator approval and a maintenance reason are required.');
    if ($db->inTransaction()) throw new LogicException('Repair requires its own transaction.');
    $db->beginTransaction();
    try {
        App\Src\Services\StudentEnrollmentRegistryService::lockMutation($db);
        $q=$db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$q->execute([$user]);$account=$q->fetch(PDO::FETCH_ASSOC);
        if (!$account||(int)$account['active']!==1||$account['role']==='admin') throw new InvalidArgumentException('Repair requires an existing active non-administrator account.');
        $q=$db->prepare('SELECT * FROM student_applications WHERE user_id=? FOR UPDATE');$q->execute([$user]);$app=$q->fetch(PDO::FETCH_ASSOC);
        if (!$app) throw new InvalidArgumentException('An existing student application is required.');
        $fingerprint=$verification::fingerprint($number);
        $q=$db->prepare("SELECT * FROM student_enrollment_registry WHERE normalized_student_number=? AND student_number_fingerprint=? AND eligibility_status='active' AND eligible_until_epoch>? FOR UPDATE");
        $q->execute([$number,$fingerprint,time()]);$registry=$q->fetch(PDO::FETCH_ASSOC);
        if (!$registry) throw new InvalidArgumentException('The exact corrected ID must already be active and unexpired in the trusted registry.');
        $q=$db->prepare('SELECT user_id FROM student_applications WHERE student_number_fingerprint=? OR normalized_student_number=?');$q->execute([$fingerprint,$number]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $owner) if ((int)$owner!==$user) throw new InvalidArgumentException('The corrected ID is already claimed; ownership cannot be transferred by this repair.');
        $access=new App\Src\Services\CampusAccessService($db);
        if ($app['normalized_student_number']===$number&&$app['student_number_fingerprint']===$fingerprint&&$app['status']==='verified'&&$access->continuedAccess($user)) {
            $db->rollBack();return ['changed'=>false,'already_repaired'=>true,'user_id'=>$user,'status'=>'verified','continued_access'=>true];
        }
        if ((int)$app['version']!==$version||$app['normalized_student_number']!==$old||!hash_equals($verification::fingerprint($old),(string)$app['student_number_fingerprint'])) throw new InvalidArgumentException('The original application changed; inspect it again before repairing.');
        if (!in_array($app['status'],['pending','resubmission_required'],true)||$app['approval_method']!==null||(int)$app['details_delete_epoch']<=time()||$app['details_erased_epoch']!==null) throw new InvalidArgumentException('Only a current, unapproved legacy application can be repaired.');
        $plan=['user_id'=>$user,'application_id'=>(int)$app['id'],'previous_status'=>$app['status'],'expected_version'=>$version,'eligible_registry_match'=>true];
        if (!$apply) { $db->rollBack();return $plan+['dry_run'=>true,'changed'=>false]; }

        // A dedicated private directory inherits the operator-only ACL established by the caller.
        $private=$backupDirectory!==null?realpath($backupDirectory):false;
        $local=realpath((string)getenv('LOCALAPPDATA'));
        $normalizePath=static fn(string $path)=>strtolower(str_replace('\\','/',rtrim($path,'/\\')));
        if (!$private||!$local||!is_dir($private)||!is_writable($private)||!str_starts_with($normalizePath($private),$normalizePath($local).'/')) throw new InvalidArgumentException('A writable private backup directory under LOCALAPPDATA is required.');
        $backup=$private.DIRECTORY_SEPARATOR.'student-link-before-'.bin2hex(random_bytes(8)).'.json';
        $payload=json_encode(['created_utc'=>gmdate('c'),'user_id'=>$user,'application'=>$app,'user_row_sha256'=>hash('sha256',json_encode($account,JSON_THROW_ON_ERROR)),'reason'=>$reason],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        $file=fopen($backup,'x');if (!$file) throw new RuntimeException('Private backup could not be created.');
        try { if (fwrite($file,$payload)!==strlen($payload)||!fflush($file)) throw new RuntimeException('Private backup write failed.'); } finally { fclose($file); }
        chmod($backup,0600);
        if (!hash_equals(hash('sha256',$payload),hash_file('sha256',$backup))) throw new RuntimeException('Private backup verification failed.');

        $now=time();
        $q=$db->prepare('UPDATE student_applications SET student_number=?,normalized_student_number=?,student_number_fingerprint=?,updated_epoch=?,version=version+1 WHERE id=? AND user_id=? AND version=?');
        $q->execute([$number,$number,$fingerprint,$now,$app['id'],$user,$version]);
        if ($q->rowCount()!==1) throw new RuntimeException('Application changed during repair.');
        // Do not impersonate a logged-in administrator or a student. This is an
        // explicitly approved local maintenance action with its own provenance.
        $audit=json_encode(['source'=>'operator_authorized_cli','operator'=>(string)getenv('USERNAME'),'reason'=>$reason,'previous_keyed_identity'=>$app['student_number_fingerprint']],JSON_THROW_ON_ERROR);
        $db->prepare("INSERT INTO student_review_events(application_id,actor_user_id,action,previous_status,new_status,notes,student_message,created_epoch) VALUES(?,NULL,'operator_id_correction',?,?,?,?,?)")
            ->execute([$app['id'],$app['status'],$app['status'],$audit,'An authorized system operator corrected your student-number link. Your eligibility is checked against the approved enrollment registry.',$now]);
        // Use the real centralized approval path, not a direct verified flag.
        if (!(new App\Src\Services\StudentEnrollmentRegistryService($db))->approveSubmission($user)) throw new RuntimeException('Registry approval failed; repair was rolled back.');
        $db->prepare("INSERT INTO student_registry_events(actor_user_id,registry_id,affected_user_id,action,identity_fingerprint,detail,created_epoch) VALUES(NULL,?,?,'operator_id_correction',?,?,?)")
            ->execute([$registry['id'],$user,$fingerprint,$audit,$now]);
        $q=$db->prepare('SELECT * FROM users WHERE id=?');$q->execute([$user]);
        $accountUnchanged=$q->fetch(PDO::FETCH_ASSOC)===$account;
        $lookup=(new App\Src\Repositories\UserRepository($db))->findActiveByCredential($number);
        if (!$accountUnchanged||!$lookup||(int)$lookup['id']!==$user||!$access->continuedAccess($user)) throw new RuntimeException('Post-repair identity or account-preservation check failed.');
        $after=$verification->forUser($user);
        $result=$plan+['changed'=>true,'status'=>$after['status'],'approval_method'=>$after['approval_method'],'new_version'=>(int)$after['version'],'student_id_login_resolves_same_account'=>true,'account_and_password_unchanged'=>$accountUnchanged,'continued_access'=>true,'backup'=>$backup,'backup_sha256'=>hash_file('sha256',$backup)];
        $db->commit();return $result;
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack();throw $error; }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===realpath(__FILE__)) {
    try {
        $options=getopt('',['apply','backup-dir:']);
        $input=json_decode(stream_get_contents(STDIN,4097),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($input)) throw new InvalidArgumentException('Provide the bounded repair request as JSON on standard input.');
        $host=(string)config('database.host');$name=(string)config('database.name');
        if (!in_array($host,['127.0.0.1','localhost'],true)||(int)config('database.port')!==3307||($name!=='ai_summarizer'&&!preg_match('/\Aqa_light_[a-f0-9]{8}\z/',$name))) throw new InvalidArgumentException('This tool is scoped to the local XAMPP application or isolated QA database.');
        echo json_encode(repairStudentIdLink(App\Src\Database::getInstance()->getConnection(),$input,isset($options['apply']),$options['backup-dir']??null),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR,($error instanceof InvalidArgumentException?$error->getMessage():'Repair failed safely; no partial transaction was retained. Error type: '.get_class($error)).PHP_EOL);exit(1);
    }
}
