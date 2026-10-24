<?php
declare(strict_types=1);
namespace App\Src\Services;

require_once __DIR__.'/StudentVerificationService.php';
use App\Src\Database;
use PDO;

/** The only writer of trusted enrollment records. Application submissions never populate it. */
final class StudentEnrollmentRegistryService
{
    public const MAX_BYTES=1048576;
    public const MAX_IDS=10000;
    public const DEFAULT_PERIOD='Permanent';
    public const PERMANENT_EPOCH=4102415999;
    private PDO $db;
    public function __construct(?PDO $db=null){$this->db=$db??Database::getInstance()->getConnection();}
    public static function lockMutation(PDO $db): void
    {
        if(!$db->inTransaction())throw new \LogicException('A registry mutation requires a transaction.');
        if(!$db->query('SELECT id FROM student_registry_gate WHERE id=1 FOR UPDATE')->fetchColumn())throw new \RuntimeException('Registry migration is required.');
    }
    private function admin(int $actor,bool $lock=false): void{(new StudentVerificationService($this->db))->requireAdmin($actor,$lock);}
    private function audit(?int $actor,?int $registry,?int $batch,?int $user,string $action,?string $fingerprint=null,?string $detail=null): void
    {
        $this->db->prepare('INSERT INTO student_registry_events (actor_user_id,registry_id,batch_id,affected_user_id,action,identity_fingerprint,detail,created_epoch) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$actor,$registry,$batch,$user,$action,$fingerprint,$detail,time()]);
    }
    /** One canonical full-ID eligibility/claim lookup under the mutation gate. */
    public function registrationMatch(string $number): array
    {
        if(!$this->db->inTransaction())throw new \LogicException('Registry matching must share the account transaction.');
        $number=StudentVerificationService::normalizeNumber($number);
        $q=$this->db->prepare("SELECT r.*,a.user_id AS owner_by_key,b.user_id AS owner_by_registry,c.user_id AS owner_by_number FROM student_enrollment_registry r LEFT JOIN student_applications a ON a.student_number_fingerprint=r.student_number_fingerprint LEFT JOIN student_applications b ON b.registry_id=r.id LEFT JOIN student_applications c ON c.normalized_student_number=r.normalized_student_number WHERE r.normalized_student_number=? AND r.eligibility_status='active' AND r.eligible_until_epoch>? FOR UPDATE");
        $q->execute([$number,time()]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new \InvalidArgumentException(StudentVerificationService::UNKNOWN_ID);
        $owners=array_values(array_unique(array_filter([$row['owner_by_key'],$row['owner_by_registry'],$row['owner_by_number']],static fn($v)=>$v!==null)));
        $row['claimed_user_id']=count($owners)===1?(int)$owners[0]:null;$row['claim_conflict']=(int)($row['claim_conflict']??0)===1||count($owners)>1;
        return $row;
    }
    public function recordRegistrationRejection(?string $number,string $reason): void
    {
        $fingerprint=null;
        if($number!==null){try{$fingerprint=StudentVerificationService::fingerprint(StudentVerificationService::normalizeNumber($number));}catch(\InvalidArgumentException $ignored){}}
        $allowed=['unknown_or_inactive','account_details_unavailable','unavailable','validation_failed'];
        $this->audit(null,null,null,null,'registration_rejected',$fingerprint,in_array($reason,$allowed,true)?$reason:'unavailable');
    }
    public function recordRegistrationCreated(int $user,array $match): void
    {
        if(!$this->db->inTransaction())throw new \LogicException('Registration audit must share the account transaction.');
        $this->audit(null,(int)$match['id'],null,$user,'registration_created',$match['student_number_fingerprint'],'Student-created account activated by an eligible registry match.');
    }
    /** Bounded streaming parser. CSV is IDs only, not an arbitrary spreadsheet. */
    public static function parse($stream): array
    {
        $ids=[];$issues=[];$total=0;$invalid=0;$duplicates=0;$bytes=0;$lineNo=0;
        while(($line=fgets($stream,4098))!==false){
            $lineNo++;$bytes+=strlen($line);
            if($bytes>self::MAX_BYTES||strlen($line)>4096)throw new \InvalidArgumentException('Import exceeds the 1 MiB or 4,096-byte line limit.');
            if($lineNo===1)$line=preg_replace('/^\xEF\xBB\xBF/','',$line);
            if(!mb_check_encoding($line,'UTF-8')||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$line))throw new \InvalidArgumentException('Use a UTF-8 text file containing student IDs only.');
            $line=trim($line);if($line==='')continue;
            // Reject broken quoting, embedded newlines and spreadsheet expressions before parsing.
            if(!preg_match('/\A(?:"(?:[^"]|"")*"|[^",]*)(?:,(?:"(?:[^"]|"")*"|[^",]*))*\z/D',$line))throw new \InvalidArgumentException('Malformed CSV quoting. Use one ID per line or simple comma-separated IDs.');
            $cells=str_getcsv($line,',','"','');
            if($lineNo===1&&count($cells)===1&&strtolower(trim($cells[0]))==='student_id')continue;
            foreach($cells as $raw){
                if(++$total>self::MAX_IDS)throw new \InvalidArgumentException('Import at most 10,000 IDs per batch.');
                try{$number=StudentVerificationService::normalizeNumber((string)$raw);}
                catch(\InvalidArgumentException $e){$invalid++;if(count($issues)<20)$issues[]=['line'=>$lineNo,'issue'=>'Invalid or empty ID'];continue;}
                if(isset($ids['id:'.$number])){$duplicates++;if(count($issues)<20)$issues[]=['line'=>$lineNo,'issue'=>'Duplicate: '.StudentVerificationService::masked($number)];}
                else $ids['id:'.$number]=$number;
            }
        }
        if(!$total)throw new \InvalidArgumentException('Enter at least one student ID.');
        // Prefix keys prevent PHP from converting all-numeric identifiers to integer keys.
        return ['ids'=>array_values($ids),'total'=>$total,'invalid'=>$invalid,'duplicates'=>$duplicates,'issues'=>$issues];
    }
    public function preview(int $actor,$stream,string $source,string $period='',string $until=''): array
    {
        $this->admin($actor);
        if($error=StudentVerificationService::configurationError())throw new \InvalidArgumentException($error);
        if(!in_array($source,['paste','csv','txt'],true))throw new \InvalidArgumentException('Unsupported import source.');
        $period=trim($period);
        if($period===''){$period=self::DEFAULT_PERIOD;}
        if(mb_strlen($period)>120)throw new \InvalidArgumentException('Enrollment period must not exceed 120 characters.');
        $until=trim($until);
        if($until===''){
            $untilEpoch=self::PERMANENT_EPOCH;
        }else{
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$until,new \DateTimeZone('Asia/Manila'));
            if(!$date||$date->format('Y-m-d')!==$until||$date->setTime(23,59,59)->getTimestamp()<=time())throw new \InvalidArgumentException('Provide a future eligibility end date.');
            $untilEpoch=$date->setTime(23,59,59)->getTimestamp();
        }
        $parsed=self::parse($stream);$existing=0;
        foreach(array_chunk($parsed['ids'],500) as $chunk){$q=$this->db->prepare('SELECT COUNT(*) FROM student_enrollment_registry WHERE normalized_student_number IN ('.implode(',',array_fill(0,count($chunk),'?')).')');$q->execute($chunk);$existing+=(int)$q->fetchColumn();}
        $ref=bin2hex(random_bytes(16));
        $this->db->prepare("UPDATE student_registry_batches SET preview_payload=NULL,state='expired' WHERE state='preview' AND preview_expires_epoch<=?")->execute([time()]);
        $this->db->prepare("INSERT INTO student_registry_batches (batch_ref,actor_user_id,source,verification_period,eligible_until_epoch,created_epoch,preview_expires_epoch,total_count,invalid_count,duplicate_count,skipped_count,preview_payload,issues_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$ref,$actor,$source,$period,$untilEpoch,time(),time()+900,$parsed['total'],$parsed['invalid'],$parsed['duplicates'],$existing+$parsed['duplicates'],json_encode($parsed['ids'],JSON_THROW_ON_ERROR),json_encode($parsed['issues'],JSON_THROW_ON_ERROR)]);
        return $this->batch($actor,$ref);
    }
    public function import(int $actor,$stream,string $source,string $period='',string $until=''): array
    {
        $preview=$this->preview($actor,$stream,$source,$period,$until);
        return $this->confirm($actor,$preview['batch_ref']);
    }
    public function batch(int $actor,string $ref): ?array
    {
        $this->admin($actor);$q=$this->db->prepare('SELECT * FROM student_registry_batches WHERE batch_ref=? AND actor_user_id=?');$q->execute([$ref,$actor]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r)return null;
        $payload=$r['preview_expires_epoch']>time()&&$r['state']==='preview'?json_decode($r['preview_payload']??'[]',true):[];
        $r['sample_full']=array_slice($payload,0,20);$r['sample']=array_map([StudentVerificationService::class,'masked'],$r['sample_full']);unset($r['preview_payload']);return $r;
    }
    public function confirm(int $actor,string $ref): array
    {
        $this->admin($actor);$this->db->beginTransaction();
        try{
            self::lockMutation($this->db);$this->admin($actor,true);
            $q=$this->db->prepare('SELECT * FROM student_registry_batches WHERE batch_ref=? AND actor_user_id=? FOR UPDATE');$q->execute([$ref,$actor]);$batch=$q->fetch(PDO::FETCH_ASSOC);
            if(!$batch)throw new \InvalidArgumentException('Import preview not found.');
            if($batch['state']==='committed'){$this->db->commit();return $this->batch($actor,$ref);}
            if($batch['state']!=='preview'||(int)$batch['preview_expires_epoch']<=time()||(int)$batch['eligible_until_epoch']<=time())throw new \InvalidArgumentException('Preview expired. Create a new preview.');
            $inserted=0;$skipped=(int)$batch['duplicate_count'];$approved=0;$conflicts=0;
            $find=$this->db->prepare('SELECT * FROM student_enrollment_registry WHERE normalized_student_number=?');
            $insert=$this->db->prepare('INSERT INTO student_enrollment_registry (registry_ref,normalized_student_number,student_number_fingerprint,verification_period,eligible_until_epoch,import_batch_id,imported_by,created_epoch,updated_epoch) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach(json_decode($batch['preview_payload'],true,512,JSON_THROW_ON_ERROR) as $number){
                $find->execute([$number]);$r=$find->fetch(PDO::FETCH_ASSOC);
                if($r)$skipped++;
                else{
                    $insert->execute([bin2hex(random_bytes(16)),$number,StudentVerificationService::fingerprint($number),$batch['verification_period'],$batch['eligible_until_epoch'],$batch['id'],$actor,time(),time()]);$inserted++;
                    $find->execute([$number]);$r=$find->fetch(PDO::FETCH_ASSOC);
                }
                $outcome=$this->reconcile($r,$actor,(int)$batch['id']);
                $approved+=($outcome==='approved');$conflicts+=($outcome==='conflict');
            }
            $this->db->prepare("UPDATE student_registry_batches SET state='committed',committed_epoch=?,inserted_count=?,skipped_count=?,approved_count=?,conflict_count=?,preview_payload=NULL WHERE id=?")
                ->execute([time(),$inserted,$skipped,$approved,$conflicts,$batch['id']]);
            $this->audit($actor,null,(int)$batch['id'],null,'import_committed',null,'Trusted administrator import; preview payload erased.');
            $this->db->commit();return $this->batch($actor,$ref);
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function reconcile(array $registry,?int $actor,?int $batch): string
    {
        $q=$this->db->prepare('SELECT user_id FROM student_applications WHERE student_number_fingerprint=? OR registry_id=? OR normalized_student_number=? OR UPPER(TRIM(student_number))=?');$q->execute([$registry['student_number_fingerprint'],$registry['id'],$registry['normalized_student_number'],$registry['normalized_student_number']]);$owners=array_values(array_unique($q->fetchAll(PDO::FETCH_COLUMN)));
        if(!$owners)return 'none';
        if(count($owners)>1){$this->db->prepare('UPDATE student_enrollment_registry SET claim_conflict=1,updated_epoch=?,version=version+1 WHERE id=? AND claim_conflict=0')->execute([time(),$registry['id']]);foreach($owners as $owner){$q=$this->db->prepare("UPDATE student_applications a JOIN users u ON u.id=a.user_id SET a.status='suspended',a.updated_epoch=?,a.version=a.version+1 WHERE a.user_id=? AND a.status<>'suspended' AND u.role<>'admin'");$q->execute([time(),$owner]);if($q->rowCount())$this->audit($actor,(int)$registry['id'],$batch,(int)$owner,'match_conflict',$registry['student_number_fingerprint'],'Conflicting historical claims; accounts and history preserved.');}return 'conflict';}
        if((int)($registry['claim_conflict']??0)===1)return 'conflict';
        $owner=$owners[0];
        $q=$this->db->prepare('SELECT id,role,active FROM users WHERE id=? FOR UPDATE');$q->execute([$owner]);$user=$q->fetch(PDO::FETCH_ASSOC);
        $q=$this->db->prepare('SELECT * FROM student_applications WHERE user_id=? FOR UPDATE');$q->execute([$owner]);$app=$q->fetch(PDO::FETCH_ASSOC);
        if($registry['eligibility_status']!=='active'||(int)$registry['eligible_until_epoch']<=time()||!$user||(int)$user['active']!==1||$user['role']==='admin')return 'restricted';
        $canonical=null;
        if($app['normalized_student_number']!==null){try{$canonical=StudentVerificationService::normalizeNumber($app['normalized_student_number']);}catch(\InvalidArgumentException $ignored){}}
        elseif($app['student_number']!==null){try{$canonical=StudentVerificationService::normalizeNumber($app['student_number']);}catch(\InvalidArgumentException $ignored){}}
        elseif($app['student_number_fingerprint']===$registry['student_number_fingerprint'])$canonical=$registry['normalized_student_number'];
        if($canonical!==$registry['normalized_student_number']){
            $this->audit($actor,(int)$registry['id'],$batch,(int)$owner,'match_conflict',$registry['student_number_fingerprint'],'Historical identifier does not exactly match this registry record.');return 'conflict';
        }
        if($app['status']==='verified'&&$app['approval_method']==='registry_match'&&(int)$app['registry_id']===(int)$registry['id']&&(int)$app['expires_epoch']===(int)$registry['eligible_until_epoch']&&$app['verification_period']===$registry['verification_period'])return 'unchanged';
        $this->approve($app,$registry,$actor,$batch);return 'approved';
    }
    private function approve(array $app,array $registry,?int $actor,?int $batch): void
    {
        $this->db->prepare("UPDATE student_applications SET status='verified',approval_method='registry_match',registry_id=?,student_number_fingerprint=?,evidence_kind='registry_match',verification_period=?,expires_epoch=?,reviewed_by=?,reviewed_epoch=?,updated_epoch=?,version=version+1 WHERE id=?")
            ->execute([$registry['id'],$registry['student_number_fingerprint'],$registry['verification_period'],$registry['eligible_until_epoch'],$actor,time(),time(),$app['id']]);
        $this->db->prepare("INSERT INTO student_review_events (application_id,actor_user_id,action,previous_status,new_status,student_message,created_epoch) VALUES (?,?,'registry_match',?,'verified',?,?)")
            ->execute([$app['id'],$actor,$app['status'],'Your student account has been verified against the administrator-approved enrollment registry.',time()]);
        $this->audit($actor,(int)$registry['id'],$batch,(int)$app['user_id'],'registry_match',$registry['student_number_fingerprint']);
    }
    /** Called only after the shared mutation gate, user and application locks. */
    public function approveSubmission(int $user,?array $match=null): bool
    {
        if(!$this->db->inTransaction())throw new \LogicException('Approval must share the registration transaction.');
        $q=$this->db->prepare('SELECT * FROM student_applications WHERE user_id=? FOR UPDATE');$q->execute([$user]);$app=$q->fetch(PDO::FETCH_ASSOC);
        if(!$app)return false;
        if((new CampusAccessService($this->db))->account($user,true)['role']==='admin')return false;
        if($match===null){try{$match=$this->registrationMatch((string)$app['normalized_student_number']);}catch(\InvalidArgumentException $e){return false;}}
        if($match['claim_conflict']||($match['claimed_user_id']!==null&&(int)$match['claimed_user_id']!==$user)||$match['normalized_student_number']!==$app['normalized_student_number']||$match['student_number_fingerprint']!==$app['student_number_fingerprint']||$match['eligibility_status']!=='active'||(int)$match['eligible_until_epoch']<=time())return false;
        $this->approve($app,$match,null,null);return true;
    }
    /** Public failures remain generic; administrator-only conflict history uses an HMAC, not the supplied ID. */
    public function recordClaimConflict(string $normalized): void
    {
        $fingerprint=StudentVerificationService::fingerprint($normalized);
        $q=$this->db->prepare('SELECT user_id,registry_id FROM student_applications WHERE student_number_fingerprint=?');$q->execute([$fingerprint]);$app=$q->fetch(PDO::FETCH_ASSOC);
        $this->audit(null,isset($app['registry_id'])?(int)$app['registry_id']:null,null,isset($app['user_id'])?(int)$app['user_id']:null,'duplicate_claim',$fingerprint,'Duplicate application claim refused; use account recovery or authorized administrator assistance.');
    }
    public function changeStatus(int $actor,string $ref,int $version,string $status,string $reason,array $renewal=[]): void
    {
        $this->admin($actor);$reason=trim($reason);
        if(!in_array($status,['active','inactive','revoked'],true)||mb_strlen($reason)<5||mb_strlen($reason)>500)throw new \InvalidArgumentException('Choose a status and provide a reason of 5–500 characters.');
        $this->db->beginTransaction();
        try{
            self::lockMutation($this->db);$this->admin($actor,true);
            $q=$this->db->prepare('SELECT * FROM student_enrollment_registry WHERE registry_ref=?');$q->execute([$ref]);$r=$q->fetch(PDO::FETCH_ASSOC);
            if(!$r||(int)$r['version']!==$version)throw new \InvalidArgumentException('Entry changed. Reload before updating.');
            $period=$r['verification_period'];$expiry=(int)$r['eligible_until_epoch'];
            if($status==='active'&&trim((string)($renewal['eligible_until']??''))!==''){
                $date=(string)$renewal['eligible_until'];$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('Asia/Manila'));$period=trim((string)($renewal['verification_period']??''));
                if(!$parsed||$parsed->format('Y-m-d')!==$date||$parsed->setTime(23,59,59)->getTimestamp()<=time()||$period===''||mb_strlen($period)>120)throw new \InvalidArgumentException('Renewal requires a campus-confirmed period and future eligibility date.');
                $expiry=$parsed->setTime(23,59,59)->getTimestamp();
            }
            if($status==='active'&&$expiry<=time())throw new \InvalidArgumentException('Provide a campus-confirmed period and future end date to renew expired eligibility.');
            if($status!=='active'){
                $q=$this->db->prepare('SELECT user_id FROM student_applications WHERE student_number_fingerprint=?');$q->execute([$r['student_number_fingerprint']]);$owner=$q->fetchColumn();
                if($owner){
                    $q=$this->db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$owner]);
                    $q=$this->db->prepare('SELECT * FROM student_applications WHERE user_id=? FOR UPDATE');$q->execute([$owner]);$app=$q->fetch(PDO::FETCH_ASSOC);
                    if($app&&$app['status']==='verified'){
                        $this->db->prepare("UPDATE student_applications SET status='suspended',updated_epoch=?,version=version+1 WHERE id=?")->execute([time(),$app['id']]);
                        $this->db->prepare("INSERT INTO student_review_events (application_id,actor_user_id,action,previous_status,new_status,student_message,created_epoch) VALUES (?,?,'registry_suspended','verified','suspended',?,?)")->execute([$app['id'],$actor,'Your student access is suspended because your enrollment registry eligibility changed. Contact the campus administrator.',time()]);
                    }
                }
            }
            $this->db->prepare('UPDATE student_enrollment_registry SET eligibility_status=?,verification_period=?,eligible_until_epoch=?,status_changed_by=?,status_changed_epoch=?,status_reason=?,updated_epoch=?,version=version+1 WHERE id=?')->execute([$status,$period,$expiry,$actor,time(),$reason,time(),$r['id']]);
            $this->audit($actor,(int)$r['id'],null,isset($owner)&&$owner?(int)$owner:null,'status_'.$status,$r['student_number_fingerprint'],'Administrator changed eligibility.');
            if($status==='active'){$q=$this->db->prepare('SELECT * FROM student_enrollment_registry WHERE id=?');$q->execute([$r['id']]);$this->reconcile($q->fetch(PDO::FETCH_ASSOC),$actor,null);}
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function stats(int $actor): array
    {
        $this->admin($actor);
        return ['total'=>(int)$this->db->query('SELECT COUNT(*) FROM student_enrollment_registry')->fetchColumn(),
            'claimed'=>(int)$this->db->query('SELECT COUNT(*) FROM student_enrollment_registry r WHERE r.claim_conflict=1 OR EXISTS(SELECT 1 FROM student_applications a WHERE a.registry_id=r.id OR a.student_number_fingerprint=r.student_number_fingerprint OR a.normalized_student_number=r.normalized_student_number)')->fetchColumn(),
            'active'=>(int)$this->db->query("SELECT COUNT(*) FROM student_enrollment_registry WHERE eligibility_status='active' AND claim_conflict=0 AND eligible_until_epoch>".time())->fetchColumn(),
            'accounts'=>(int)$this->db->query("SELECT COUNT(*) FROM student_applications a JOIN users u ON u.id=a.user_id WHERE u.role<>'admin'")->fetchColumn(),
            'recent'=>(int)$this->db->query('SELECT COUNT(*) FROM student_enrollment_registry WHERE created_epoch>'.(time()-604800))->fetchColumn(),
            'unclaimed'=>(int)$this->db->query('SELECT COUNT(*) FROM student_enrollment_registry r WHERE r.claim_conflict=0 AND NOT EXISTS(SELECT 1 FROM student_applications a WHERE a.registry_id=r.id OR a.student_number_fingerprint=r.student_number_fingerprint OR a.normalized_student_number=r.normalized_student_number)')->fetchColumn(),
            'rejected'=>(int)$this->db->query("SELECT COUNT(*) FROM student_registry_events WHERE action='registration_rejected'")->fetchColumn()];
    }
    private function authorizePolicyMaintenance(?int $actor): void
    {
        if($actor!==null)$this->admin($actor);
        elseif(PHP_SAPI!=='cli')throw new \App\Src\Support\ProcessingError('ACCESS_DENIED');
        if($error=StudentVerificationService::configurationError())throw new \InvalidArgumentException($error);
    }
    /** One-time operator/admin baseline. Later imports/revocations survive repeat execution. */
    public function initializeAllowlist(array $numbers,?int $actor=null): array
    {
        $this->authorizePolicyMaintenance($actor);
        $normalized=array_map([StudentVerificationService::class,'normalizeNumber'],$numbers);
        if(count($normalized)!==38||count(array_unique($normalized))!==38)throw new \InvalidArgumentException('The initial authoritative list must contain exactly 38 unique full Student IDs.');
        sort($normalized,SORT_STRING);$digest=hash('sha256',implode("\n",$normalized));
        $this->db->beginTransaction();
        try{
            self::lockMutation($this->db);if($actor!==null)$this->admin($actor,true);
            $policy=$this->db->query('SELECT * FROM student_registry_policy WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
            if(!$policy)throw new \RuntimeException('Apply migration 020 before initializing the allowlist.');
            if($policy['initial_applied_epoch']!==null){
                if(!hash_equals($policy['initial_allowlist_sha256'],$digest))throw new \InvalidArgumentException('The initial list is already established. Add new authorized IDs through administrator imports.');
                $summary=$this->reconcileAllLocked($actor);$this->db->commit();return ['already_initialized'=>true,'initial_count'=>38,'reconciliation'=>$summary];
            }
            $now=time();$ref=bin2hex(random_bytes(16));
            $this->db->prepare("INSERT INTO student_registry_batches(batch_ref,actor_user_id,source,state,verification_period,eligible_until_epoch,created_epoch,preview_expires_epoch,committed_epoch,total_count) VALUES(?,?,'txt','committed',?,?,?,?,?,38)")
                ->execute([$ref,$actor,'Initial authorized Student ID allowlist',self::PERMANENT_EPOCH,$now,$now,$now]);$batch=(int)$this->db->lastInsertId();
            $inserted=0;$existing=0;$excluded=0;$allowed=array_fill_keys($normalized,true);
            $find=$this->db->prepare('SELECT * FROM student_enrollment_registry WHERE normalized_student_number=? FOR UPDATE');
            foreach($normalized as $number){
                $find->execute([$number]);$row=$find->fetch(PDO::FETCH_ASSOC);$fingerprint=StudentVerificationService::fingerprint($number);
                if($row){
                    if(!hash_equals($fingerprint,$row['student_number_fingerprint']))throw new \RuntimeException('Protected registry identity mismatch; do not change the configured key.');
                    $existing++;
                    if($row['eligibility_status']!=='active'||(int)$row['eligible_until_epoch']<self::PERMANENT_EPOCH)$this->db->prepare("UPDATE student_enrollment_registry SET eligibility_status='active',eligible_until_epoch=?,verification_period=?,status_changed_by=?,status_changed_epoch=?,status_reason=?,updated_epoch=?,version=version+1 WHERE id=?")->execute([self::PERMANENT_EPOCH,'Initial authorized Student ID allowlist',$actor,$now,'Operator-approved initial authoritative allowlist',$now,$row['id']]);
                }else{
                    $this->db->prepare('INSERT INTO student_enrollment_registry(registry_ref,normalized_student_number,student_number_fingerprint,verification_period,eligible_until_epoch,import_batch_id,imported_by,created_epoch,updated_epoch) VALUES(?,?,?,?,?,?,?,?,?)')->execute([bin2hex(random_bytes(16)),$number,$fingerprint,'Initial authorized Student ID allowlist',self::PERMANENT_EPOCH,$batch,$actor,$now,$now]);$inserted++;
                }
            }
            // Keyset batches keep memory bounded even if an older registry is large.
            $last=0;
            do{
                $q=$this->db->prepare('SELECT id,normalized_student_number,student_number_fingerprint,eligibility_status FROM student_enrollment_registry WHERE id>? ORDER BY id LIMIT 500');$q->execute([$last]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
                foreach($rows as $row){$last=(int)$row['id'];if(isset($allowed[$row['normalized_student_number']])||$row['eligibility_status']!=='active')continue;
                    $this->db->prepare("UPDATE student_enrollment_registry SET eligibility_status='inactive',status_changed_by=?,status_changed_epoch=?,status_reason=?,updated_epoch=?,version=version+1 WHERE id=?")->execute([$actor,$now,'Excluded by initial authoritative allowlist replacement',$now,$row['id']]);
                    $this->audit($actor,(int)$row['id'],$batch,null,'initial_allowlist_excluded',$row['student_number_fingerprint'],'Historical record preserved; not in the initial authoritative list.');$excluded++;
                }
            }while(count($rows)===500);
            $summary=$this->reconcileAllLocked($actor);
            $this->db->prepare('UPDATE student_registry_batches SET inserted_count=?,skipped_count=?,approved_count=?,conflict_count=? WHERE id=?')->execute([$inserted,$existing,$summary['approved'],$summary['conflicts'],$batch]);
            $this->db->prepare('UPDATE student_registry_policy SET initial_allowlist_sha256=?,initial_applied_epoch=?,initialized_by=?,initialized_source=? WHERE id=1')->execute([$digest,$now,$actor,$actor===null?'operator_authorized_cli':'administrator']);
            $this->audit($actor,null,$batch,null,'initial_allowlist_initialized',null,'38 initial IDs reconciled; subsequent authorized administrator imports remain supported.');
            $this->db->commit();return ['already_initialized'=>false,'initial_count'=>38,'inserted'=>$inserted,'existing'=>$existing,'excluded'=>$excluded,'reconciliation'=>$summary];
        }catch(\Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }
    public function reconcileAccounts(?int $actor): array
    {
        $this->authorizePolicyMaintenance($actor);$this->db->beginTransaction();
        try{self::lockMutation($this->db);if($actor!==null)$this->admin($actor,true);$summary=$this->reconcileAllLocked($actor);$this->db->commit();return $summary;}
        catch(\Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }
    private function reconcileAllLocked(?int $actor): array
    {
        $summary=['checked'=>0,'approved'=>0,'already_verified'=>0,'restricted'=>0,'conflicts'=>0,'administrators_exempt'=>(int)$this->db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn(),'accounts_without_student_id'=>(int)$this->db->query("SELECT COUNT(*) FROM users u WHERE u.role<>'admin' AND NOT EXISTS(SELECT 1 FROM student_applications a WHERE a.user_id=u.id)")->fetchColumn()];
        $last=0;$find=$this->db->prepare('SELECT * FROM student_enrollment_registry WHERE normalized_student_number=?');$byKey=$this->db->prepare('SELECT * FROM student_enrollment_registry WHERE student_number_fingerprint=?');
        do{
            $q=$this->db->prepare('SELECT a.*,u.role,u.active FROM student_applications a JOIN users u ON u.id=a.user_id WHERE a.user_id>? ORDER BY a.user_id LIMIT 500');$q->execute([$last]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as $app){
                $last=(int)$app['user_id'];if($app['role']==='admin')continue;$summary['checked']++;$registry=null;
                $raw=$app['normalized_student_number']??$app['student_number'];
                if($raw!==null){try{$number=StudentVerificationService::normalizeNumber($raw);$find->execute([$number]);$registry=$find->fetch(PDO::FETCH_ASSOC);}catch(\InvalidArgumentException $ignored){}}
                elseif($app['student_number_fingerprint']!==null){$byKey->execute([$app['student_number_fingerprint']]);$registry=$byKey->fetch(PDO::FETCH_ASSOC);}
                if($registry&&$registry['eligibility_status']==='active'&&(int)$registry['eligible_until_epoch']>time()&&(int)$app['active']===1){
                    $outcome=$this->reconcile($registry,$actor,null);
                    if($outcome==='approved')$summary['approved']++;elseif($outcome==='unchanged')$summary['already_verified']++;else $summary[$outcome==='conflict'?'conflicts':'restricted']++;
                }else{
                    $summary['restricted']++;
                    if($app['status']!=='suspended'){
                        $this->db->prepare("UPDATE student_applications SET status='suspended',updated_epoch=?,version=version+1 WHERE id=?")->execute([time(),$app['id']]);
                        $this->db->prepare("INSERT INTO student_review_events(application_id,actor_user_id,action,previous_status,new_status,student_message,created_epoch) VALUES(?,?,'registry_reconciled',?,'suspended',?,?)")->execute([$app['id'],$actor,$app['status'],'Student access is restricted because there is no active approved-registry match. Your account and summaries are preserved.',time()]);
                        $this->audit($actor,$registry?(int)$registry['id']:null,null,(int)$app['user_id'],'reconciliation_restricted',$app['student_number_fingerprint'],'No eligible registry match; historical account and evidence preserved.');
                    }
                }
            }
        }while(count($rows)===500);
        $this->db->prepare('UPDATE student_registry_policy SET latest_reconciliation_epoch=?,latest_reconciliation_json=? WHERE id=1')->execute([time(),json_encode($summary,JSON_THROW_ON_ERROR)]);
        return $summary;
    }
    public function reconciliationSummary(int $actor): array
    {
        $this->admin($actor);$row=$this->db->query('SELECT latest_reconciliation_epoch,latest_reconciliation_json FROM student_registry_policy WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        return ['epoch'=>$row['latest_reconciliation_epoch']??null,'summary'=>json_decode($row['latest_reconciliation_json']??'{}',true)];
    }
    public function entries(int $actor,string $search='',string $status='',int $page=1): array
    {
        $this->admin($actor);$where=[];$params=[];
        if($search!==''){$where[]='r.normalized_student_number=?';$params[]=StudentVerificationService::registrationNumber($search);}
        if(in_array($status,['active','inactive','revoked'],true)){$where[]='r.eligibility_status=?';$params[]=$status;}
        if($status==='expired'){$where[]='r.eligible_until_epoch<=?';$params[]=time();}
        $q=$this->db->prepare('SELECT r.*,a.user_id AS claimed_user_id,a.status AS account_verification_status,u.active AS account_active FROM student_enrollment_registry r LEFT JOIN student_applications a ON a.registry_id=r.id LEFT JOIN users u ON u.id=a.user_id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY r.id DESC LIMIT 25 OFFSET '.((max(1,min(10000,$page))-1)*25));$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function recent(int $actor,string $kind,int $page=1): array
    {
        $this->admin($actor);$offset=(max(1,min(10000,$page))-1)*25;
        if($kind==='batches')$q=$this->db->query('SELECT batch_ref,source,state,created_epoch,total_count,inserted_count,skipped_count,invalid_count,approved_count,conflict_count FROM student_registry_batches ORDER BY id DESC LIMIT 25 OFFSET '.$offset);
        else $q=$this->db->query("SELECT actor_user_id,affected_user_id,action,detail,created_epoch FROM student_registry_events WHERE action IN ('match_conflict','duplicate_claim','reconciliation_restricted') ORDER BY id DESC LIMIT 25 OFFSET ".$offset);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
