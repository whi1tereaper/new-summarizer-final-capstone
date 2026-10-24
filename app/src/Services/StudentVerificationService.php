<?php
declare(strict_types=1);
namespace App\Src\Services;
require_once __DIR__.'/CampusAccessService.php';
require_once __DIR__.'/StudentEnrollmentRegistryService.php';
require_once __DIR__.'/../Repositories/UserRepository.php';
require_once __DIR__.'/../Utils/validation.php';
use App\Src\Database;
use App\Src\Repositories\UserRepository;
use PDO;

/** Registry-only student onboarding. Historical review records are read-only. */
final class StudentVerificationService
{
    public const TERMS_VERSION='terms-registration-2026-10-v2';
    public const PRIVACY_VERSION='privacy-registration-2026-10-v2';
    public const UNKNOWN_ID='Your Student ID is not included in the approved NEUST Peñaranda Off-Campus student registry. Please verify your Student ID or contact the system administrator.';
    public const UNAVAILABLE_ID='These registration details are unavailable. Sign in to your existing account or contact the system administrator. No other account details can be disclosed.';
    public const STATUSES=['pending','verified','declined','resubmission_required','suspended','expired'];
    public const EVIDENCE=[]; // Historical schema compatibility; no manual approval path.
    private PDO $db;
    public function __construct(?PDO $db=null) { $this->db=$db??Database::getInstance()->getConnection(); }
    public static function configurationError(): ?string
    {
        if(!filter_var(config('student_access.privacy_contact',''),FILTER_VALIDATE_EMAIL))
            return 'Student applications open after the operator publishes a privacy contact email.';
        $key=base64_decode((string)config('student_access.number_secret',''),true);
        if(!is_string($key)||strlen($key)!==32)return 'Student applications are unavailable until the protected student-number key is configured.';
        if(config('student_access.enrollment_authorized',false)!==true)return 'Student registration is closed until the operator confirms authorization to process campus enrollment records.';
        return null;
    }
    public static function normalizeNumber(string $number): string
    {
        $number=strtoupper(trim($number));
        if(strlen($number)>40 || !preg_match('/\APEN[0-9]{4}-[0-9]+\z/D',$number))
            throw new \InvalidArgumentException('Enter your Student ID as PEN followed by the year and number, for example PEN2023-02071. Preserve leading zeros.');
        $pattern=(string)config('student_access.number_pattern','~\A[A-Z0-9][A-Z0-9-]{3,39}\z~D');
        if(@preg_match($pattern,$number)!==1)throw new \InvalidArgumentException('Check the student-number format. A valid format is not enrollment verification.');
        return $number;
    }
    public static function registrationNumber(string $input): string
    {
        $input=strtoupper(trim($input));
        return self::normalizeNumber(str_starts_with($input,'PEN')?$input:'PEN'.$input);
    }
    public static function validateSubmission(array $input): array
    {
        if($error=self::configurationError())throw new \InvalidArgumentException($error);
        $raw=is_string($input['student_number']??null)?trim($input['student_number']):'';
        $number=self::registrationNumber($raw);
        return ['full_name'=>null,'student_number'=>$number,'normalized'=>$number];
    }
    public static function fingerprint(string $normalized): string
    {
        $key=base64_decode((string)config('student_access.number_secret',''),true);
        if(!is_string($key)||strlen($key)!==32)throw new \RuntimeException('Student-number protection is not configured.');
        return hash_hmac('sha256',$normalized,hash_hkdf('sha256',$key,32,'LIGHT student identity duplicate check v1'));
    }
    public static function masked(?string $number): string
    {
        if(!$number)return 'Details erased';
        if(strlen($number)<=8)return substr($number,0,1).str_repeat('•',4).substr($number,-1);
        return substr($number,0,min(3,strlen($number))).str_repeat('•',4).substr($number,-3);
    }
    public static function statusLabel(string $status): string
    {
        // Preserve historical database states without presenting a review waiting step.
        return $status==='pending'?'Not verified':str_replace('_',' ',$status);
    }
    public function register(string $username,string $email,string $hash,array $data,string $visitor): array
    {
        try {
            $validatedEmail=validateEmail($email);
            if(is_string($validatedEmail))throw new \InvalidArgumentException($validatedEmail);
            $email=$validatedEmail['email'];
            if(password_get_info($hash)['algoName']==='unknown')throw new \InvalidArgumentException('A securely hashed password is required.');
            $data=self::validateSubmission(['student_number'=>$data['student_number']??'']);
            $this->db->beginTransaction();
            StudentEnrollmentRegistryService::lockMutation($this->db);
            $registry=new StudentEnrollmentRegistryService($this->db);
            // One full-ID registry/claim lookup. The same locked result is used
            // for approval; no regex, legacy evidence or client status can approve.
            $match=$registry->registrationMatch($data['normalized']);
            if($match['claimed_user_id']!==null||$match['claim_conflict'])throw new \InvalidArgumentException(self::UNAVAILABLE_ID);
            $user=(new UserRepository($this->db))->createPendingTermsAccount($username,$email,$hash);
            $now=time();
            $this->db->prepare("INSERT INTO student_applications(application_ref,user_id,student_number,normalized_student_number,student_number_fingerprint,campus,status,submitted_epoch,created_epoch,updated_epoch,details_delete_epoch,terms_version,privacy_version,consent_epoch) VALUES(?,?,?,?,?,'NEUST_PENARANDA','pending',?,?,?,?,?,?,?)")
                ->execute([bin2hex(random_bytes(16)),$user['id'],$data['normalized'],$data['normalized'],$match['student_number_fingerprint'],$now,$now,$now,$now+259200,self::TERMS_VERSION,self::PRIVACY_VERSION,$now]);
            $this->db->prepare('UPDATE users SET terms_accepted=1,terms_accepted_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$user['id']]);
            if(!$registry->approveSubmission((int)$user['id'],$match))throw new \InvalidArgumentException(self::UNKNOWN_ID);
            $registry->recordRegistrationCreated((int)$user['id'],$match);
            (new CampusAccessService($this->db))->trialSubject((int)$user['id'],$visitor);
            $this->db->commit();$user['terms_accepted']=1;$user['terms_accepted_at']=gmdate('Y-m-d H:i:s');return $user;
        }catch(\Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            try{(new StudentEnrollmentRegistryService($this->db))->recordRegistrationRejection($data['normalized']??null,$e instanceof \PDOException?'account_details_unavailable':($e->getMessage()===self::UNKNOWN_ID?'unknown_or_inactive':'unavailable'));}catch(\Throwable $ignored){error_log('[Student registration] Rejection audit unavailable.');}
            throw $e;
        }
    }
    public function resubmit(int $user,array $input,?int $version,string $visitor): void
    {
        throw new \InvalidArgumentException('Student verification forms are retired. Eligibility is managed only through the administrator-approved Student ID registry.');
    }
    public function forUser(int $id): ?array
    {
        $q=$this->db->prepare('SELECT * FROM student_applications WHERE user_id=?');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
        return $r?$this->visible($r):null;
    }
    private function visible(array $r): array
    {
        $r['effective_status']=$r['status']==='verified' && (int)$r['expires_epoch']<=time()?'expired':$r['status'];
        // Retention is a read barrier too: an overdue maintenance job cannot expose old details.
        if((int)$r['details_delete_epoch']<=time()) {
            foreach(['full_name','student_number','normalized_student_number','evidence_reference','ownership_reference','review_reason'] as $field)$r[$field]=null;
            if($r['effective_status']==='pending')$r['effective_status']='resubmission_required';
        }
        return $r;
    }
    public function requireAdmin(int $actor,bool $lock=false): void
    {
        $r=(new CampusAccessService($this->db))->account($actor,$lock);
        if(!$r||$r['role']!=='admin')throw new \App\Src\Support\ProcessingError('ACCESS_DENIED');
    }
    public function detail(int $actor,string $ref): ?array
    {
        $this->requireAdmin($actor);
        if(!preg_match('/\A[a-f0-9]{32}\z/',$ref))return null;
        $q=$this->db->prepare('SELECT a.*,u.email,u.created_at AS registered_at FROM student_applications a JOIN users u ON u.id=a.user_id WHERE application_ref=?');$q->execute([$ref]);$r=$q->fetch(PDO::FETCH_ASSOC);
        return $r?$this->visible($r):null;
    }
    public function queue(int $actor,string $status='',string $search='',string $sort='newest',int $page=1): array
    {
        $this->requireAdmin($actor);$where=[];$params=[];
        $effective="CASE WHEN a.status='verified' AND a.expires_epoch<=".time()." THEN 'expired' WHEN a.status='pending' AND a.details_delete_epoch<=".time()." THEN 'resubmission_required' ELSE a.status END";
        if(in_array($status,self::STATUSES,true)){$where[]=$effective.'=?';$params[]=$status;}
        if($search!=='') {
            $where[]='(LOWER(a.full_name) LIKE ? OR a.normalized_student_number LIKE ?) AND a.details_delete_epoch>?';
            $term='%'.mb_strtolower(mb_substr($search,0,80)).'%';array_push($params,$term,strtoupper($term),time());
        }
        $q=$this->db->prepare('SELECT a.*,u.email,u.created_at AS registered_at FROM student_applications a JOIN users u ON u.id=a.user_id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY a.submitted_epoch '.($sort==='oldest'?'ASC':'DESC').',a.id DESC LIMIT 25 OFFSET '.((max(1,min(10000,$page))-1)*25));
        $q->execute($params);return array_map(fn($r)=>$this->visible($r),$q->fetchAll(PDO::FETCH_ASSOC));
    }
    public function pendingCount(int $actor): int
    {
        $this->requireAdmin($actor);$q=$this->db->prepare("SELECT COUNT(*) FROM student_applications WHERE status='pending' AND details_delete_epoch>?");$q->execute([time()]);return (int)$q->fetchColumn();
    }
    public function history(int $appId,int $viewer,bool $admin=false): array
    {
        if($admin)$this->requireAdmin($viewer);
        else { $q=$this->db->prepare('SELECT 1 FROM student_applications WHERE id=? AND user_id=?');$q->execute([$appId,$viewer]);if(!$q->fetchColumn())return []; }
        $q=$this->db->prepare('SELECT action,previous_status,new_status,notes,student_message,created_epoch FROM student_review_events WHERE application_id=? AND created_epoch>? ORDER BY id DESC LIMIT 100');$q->execute([$appId,time()-259200]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(!$admin)foreach($rows as &$r)unset($r['notes']);
        return $rows;
    }
    public function review(int $actor,string $ref,int $version,string $action,array $input): void
    {
        $this->requireAdmin($actor);
        throw new \InvalidArgumentException('Manual student approval is retired. Manage eligibility only in Student ID Management.');
    }
}
