<?php
declare(strict_types=1);
namespace App\Src\Services;

require_once __DIR__.'/../Database.php';
require_once __DIR__.'/../Support/ProcessingError.php';
use App\Src\Database;
use App\Src\Support\ProcessingError;
use PDO;

/** One authorization/accounting boundary for every web generation path. */
final class CampusAccessService
{
    public const FREE_LIMIT=3;
    public const COOKIE_NAME='LIGHT_TRIAL';
    public const COOKIE_SECONDS=15552000;
    private PDO $db;

    public function __construct(?PDO $db=null) { $this->db=$db??Database::getInstance()->getConnection(); }
    private function ensureSubject(string $key): void
    {
        $sql='INSERT INTO summary_access_subjects (subject_key,completed_count,request_window_epoch,request_count,created_epoch,last_activity_epoch) VALUES (?,0,?,0,?,?)';
        $sql.=' ON DUPLICATE KEY UPDATE subject_key=VALUES(subject_key)';
        try { $this->db->prepare($sql)->execute([$key,time(),time(),time()]); }
        catch(\PDOException $e) { throw new ProcessingError('ACCESS_SETUP_REQUIRED',$e); }
    }
    public function visitorToken(): string
    {
        $token=$_SESSION['trial_token']??$_COOKIE[self::COOKIE_NAME]??null;
        if(is_string($token) && preg_match('/\A[a-f0-9]{64}\z/',$token)) {
            try { $q=$this->db->prepare('SELECT 1 FROM summary_access_subjects WHERE subject_key=?'); $q->execute([hash('sha256','visitor:'.$token)]); }
            catch(\PDOException $e) { throw new ProcessingError('ACCESS_SETUP_REQUIRED',$e); }
            if(!$q->fetchColumn())$token=null;
        }else $token=null;
        if($token===null)$token=bin2hex(random_bytes(32));
        $this->ensureSubject(hash('sha256','visitor:'.$token));
        $_SESSION['trial_token']=$token;
        if(PHP_SAPI!=='cli' && !headers_sent()) {
            $secure=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')
                || parse_url((string)config('app.url',''),PHP_URL_SCHEME)==='https';
            setcookie(self::COOKIE_NAME,$token,['expires'=>time()+self::COOKIE_SECONDS,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
        }
        return $token;
    }
    public function account(?int $id,bool $lock=false): ?array
    {
        if($id===null)return null;
        $q=$this->db->prepare('SELECT id,role,active FROM users WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);
        $r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r || (int)$r['active']!==1)throw new ProcessingError('ACCESS_DENIED');
        return $r;
    }
    public function continuedAccess(?int $id,bool $lock=false): bool
    {
        $account=$this->account($id,$lock);
        if(!$account)return false;
        if($account['role']==='admin')return true;
        try {
            $q=$this->db->prepare("SELECT a.id FROM student_applications a JOIN student_enrollment_registry r ON r.id=a.registry_id AND r.student_number_fingerprint=a.student_number_fingerprint WHERE a.user_id=? AND a.campus='NEUST_PENARANDA' AND a.status='verified' AND a.approval_method='registry_match' AND r.eligibility_status='active' AND r.claim_conflict=0 AND r.eligible_until_epoch>?".($lock?' FOR UPDATE':''));
            $q->execute([$id,time()]);return $q->fetchColumn()!==false;
        }catch(\PDOException $e){throw new ProcessingError('ACCESS_SETUP_REQUIRED',$e);}
    }
    /** A returning restricted account keeps its original trial subject on any browser. */
    public function trialSubject(?int $id,?string $token): string
    {
        if($id!==null) {
            $q=$this->db->prepare('SELECT subject_key FROM student_trial_links WHERE user_id=?');$q->execute([$id]);
            if($key=$q->fetchColumn())return (string)$key;
        }
        if(!is_string($token)||!preg_match('/\A[a-f0-9]{64}\z/',$token))throw new ProcessingError('AUTH_REQUIRED');
        $key=hash('sha256','visitor:'.$token);$this->ensureSubject($key);
        if($id!==null) {
            $sql='INSERT INTO student_trial_links (user_id,subject_key) VALUES (?,?)';
            $sql.=' ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)';
            $this->db->prepare($sql)->execute([$id,$key]);
            $q=$this->db->prepare('SELECT subject_key FROM student_trial_links WHERE user_id=?');$q->execute([$id]);$key=(string)$q->fetchColumn();
        }
        return $key;
    }
    public function status(?int $id,?string $token): array
    {
        if($this->continuedAccess($id))return ['continued'=>true,'remaining'=>null,'reserved'=>0];
        $key=$this->trialSubject($id,$token);
        $q=$this->db->prepare('SELECT completed_count FROM summary_access_subjects WHERE subject_key=?');$q->execute([$key]);$completed=(int)$q->fetchColumn();
        $q=$this->db->prepare("SELECT COUNT(*) FROM summary_access_reservations WHERE subject_key=? AND state='reserved' AND expires_epoch>?");$q->execute([$key,time()]);
        $this->db->prepare('UPDATE summary_access_subjects SET last_activity_epoch=? WHERE subject_key=?')->execute([time(),$key]);
        return ['continued'=>false,'remaining'=>max(0,3-$completed),'reserved'=>(int)$q->fetchColumn()];
    }
    public function assertCanStart(?int $id,?string $token): void
    {
        $s=$this->status($id,$token);
        if(!$s['continued'] && $s['remaining']===0)throw new ProcessingError('GUEST_LIMIT_REACHED');
        if(!$s['continued'] && $s['reserved']>=$s['remaining'])throw new ProcessingError('ACCESS_BUSY');
    }
    public static function jobReference(string $jobId,int $pid): string { return 'job:'.$jobId.':'.$pid; }

    /** Short transaction: an attempt reserves capacity, never consumes an allowance. */
    public function reserve(?int $id,?string $token,string $requestRef): array
    {
        if(strlen($requestRef)>100)throw new \InvalidArgumentException('Invalid access reference.');
        $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
        try {
            $privileged=$this->continuedAccess($id,true);
            $key=$privileged?hash('sha256','account:'.$id):$this->trialSubject($id,$token);
            $this->ensureSubject($key);
            $q=$this->db->prepare('SELECT * FROM summary_access_subjects WHERE subject_key=? FOR UPDATE');$q->execute([$key]);$s=$q->fetch(PDO::FETCH_ASSOC);
            // A locking read observes current rows even under MySQL REPEATABLE READ.
            $q=$this->db->prepare("SELECT attempt_id FROM summary_access_reservations WHERE subject_key=? AND state='reserved' AND expires_epoch>? FOR UPDATE");$q->execute([$key,time()]);$active=count($q->fetchAll(PDO::FETCH_COLUMN));
            if(!$privileged && (int)$s['completed_count']>=3)throw new ProcessingError('GUEST_LIMIT_REACHED');
            $cap=$privileged?max(1,min(4,(int)config('student_access.concurrent_limit',2))):3-(int)$s['completed_count'];
            if($active>=$cap)throw new ProcessingError('ACCESS_BUSY');
            $sameWindow=(int)$s['request_window_epoch']>time()-60;
            $count=$sameWindow?(int)$s['request_count']:0;
            if($count>=($privileged?20:12))throw new ProcessingError('RATE_LIMITED');
            $this->db->prepare('UPDATE summary_access_subjects SET request_window_epoch=?,request_count=?,last_activity_epoch=? WHERE subject_key=?')
                ->execute([$sameWindow?(int)$s['request_window_epoch']:time(),$count+1,time(),$key]);
            $attempt=bin2hex(random_bytes(32));
            $this->db->prepare("INSERT INTO summary_access_reservations (attempt_id,request_ref,subject_key,user_id,privileged,state,expires_epoch,created_epoch) VALUES (?,?,?,?,?,'reserved',?,?)")
                ->execute([$attempt,$requestRef,$key,$id,(int)$privileged,time()+900,time()]);
            if($own)$this->db->commit();
            return ['attempt_id'=>$attempt,'subject_key'=>$key,'user_id'=>$id,'privileged'=>$privileged];
        }catch(\Throwable $e){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /** Called INSIDE the successful result's transaction; repeats never charge twice. */
    public function complete(array $ticket): void
    {
        if(!$this->db->inTransaction())throw new \LogicException('Result and quota must share one transaction.');
        $eligible=$this->continuedAccess($ticket['user_id'],true);
        if($ticket['privileged']&&!$eligible)throw new ProcessingError('ACCESS_DENIED');
        $q=$this->db->prepare('SELECT completed_count FROM summary_access_subjects WHERE subject_key=? FOR UPDATE');$q->execute([$ticket['subject_key']]);$completed=(int)$q->fetchColumn();
        $q=$this->db->prepare('SELECT * FROM summary_access_reservations WHERE attempt_id=? AND subject_key=? FOR UPDATE');$q->execute([$ticket['attempt_id'],$ticket['subject_key']]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if($r && $r['state']==='completed')return;
        if(!$r||$r['state']!=='reserved'||(int)$r['expires_epoch']<=time()||(bool)$r['privileged']!==$ticket['privileged']||($r['user_id']!==null?(int)$r['user_id']:null)!==$ticket['user_id'])throw new ProcessingError('ACCESS_DENIED');
        if(!$ticket['privileged']) {
            if($completed>=3)throw new ProcessingError('GUEST_LIMIT_REACHED');
            $this->db->prepare('UPDATE summary_access_subjects SET completed_count=completed_count+1,last_activity_epoch=? WHERE subject_key=?')->execute([time(),$ticket['subject_key']]);
        }
        $this->db->prepare("UPDATE summary_access_reservations SET state='completed' WHERE attempt_id=?")->execute([$ticket['attempt_id']]);
    }
    public function release(?array $ticket): void
    {
        if($ticket)$this->db->prepare("UPDATE summary_access_reservations SET state='released' WHERE attempt_id=? AND state='reserved'")->execute([$ticket['attempt_id']]);
    }
    public function releaseRequest(string $reference): void
    {
        $this->db->prepare("UPDATE summary_access_reservations SET state='released' WHERE request_ref=? AND state='reserved'")->execute([$reference]);
    }
}
