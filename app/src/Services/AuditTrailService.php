<?php
declare(strict_types=1);
namespace App\Src\Services;

require_once __DIR__.'/../Database.php';
require_once __DIR__.'/../Support/ProcessingError.php';

use App\Src\Database;
use App\Src\Support\ProcessingError;
use PDO;

/** Read-only union of existing ledgers; never copies or rewrites historical events. */
final class AuditTrailService
{
    public const SOURCES=['admin'=>'Administration','registry'=>'Student ID registry'];
    private PDO $db;
    public function __construct(?PDO $db=null){$this->db=$db??Database::getInstance()->getConnection();}

    public static function filters(array $input): array
    {
        $filters=[];
        foreach(['source','action','admin_id','date_from','date_to','page'] as $name){
            $value=$input[$name]??'';
            if(!is_string($value)&&!is_int($value))throw new \InvalidArgumentException('Use a single value for each filter.');
            $value=trim((string)$value);
            if(!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>100||preg_match('/[\x00-\x1f\x7f]/',$value))throw new \InvalidArgumentException('A filter value is invalid or too long.');
            $filters[$name]=$value;
        }
        if($filters['source']!==''&&!isset(self::SOURCES[$filters['source']]))throw new \InvalidArgumentException('Choose an available audit source.');
        if($filters['admin_id']!==''&&filter_var($filters['admin_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]])===false)throw new \InvalidArgumentException('Actor ID must be a positive account number.');
        foreach(['date_from','date_to'] as $name){
            if($filters[$name]==='')continue;
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$filters[$name]);
            if(!$date||$date->format('Y-m-d')!==$filters[$name])throw new \InvalidArgumentException('Enter a valid date in YYYY-MM-DD format.');
        }
        if($filters['date_from']!==''&&$filters['date_to']!==''&&$filters['date_from']>$filters['date_to'])throw new \InvalidArgumentException('The start date must not be after the end date.');
        $filters['page']=$filters['page']===''?1:filter_var($filters['page'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
        if($filters['page']===false)throw new \InvalidArgumentException('Choose a valid page number.');
        return $filters;
    }

    public function browse(int $actor,array $input): array
    {
        // Service authorization also protects non-page callers; roles in requests never count.
        $q=$this->db->prepare("SELECT 1 FROM users WHERE id=? AND role='admin' AND active=1");$q->execute([$actor]);
        if(!$q->fetchColumn())throw new ProcessingError('ACCESS_DENIED');
        $filters=self::filters($input);
        $tables=$this->db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('admin_audit_logs','student_registry_events')")->fetchAll(PDO::FETCH_COLUMN);
        $available=[];
        if(in_array('admin_audit_logs',$tables,true))$available[]='admin';
        if(in_array('student_registry_events',$tables,true))$available[]='registry';
        if(!$available)throw new \RuntimeException('Audit ledgers unavailable.');
        $warnings=[];
        foreach(self::SOURCES as $key=>$label)if(!in_array($key,$available,true))$warnings[]=$label.' events are unavailable. Check the existing database migrations; this view may be incomplete.';
        $selected=array_values(array_filter($available,static fn($source)=>$filters['source']===''||$filters['source']===$source));
        $parts=[];$parameters=[];$counts=['admin'=>0,'registry'=>0];
        foreach($selected as $source){
            [$where,$bindings]=$this->where($source,$filters);$parameters+=$bindings;
            $table=$source==='admin'?'admin_audit_logs a':'student_registry_events a';
            $q=$this->db->prepare('SELECT COUNT(*) FROM '.$table.$where);$q->execute($bindings);$counts[$source]=(int)$q->fetchColumn();
            if($source==='admin')$parts[]="SELECT 'admin' AS source,a.id AS event_id,a.admin_id AS actor_id,a.action,a.entity_type,a.entity_id,NULL AS affected_user_id,a.ip_address,a.user_agent,a.details,UNIX_TIMESTAMP(a.created_at) AS event_epoch FROM admin_audit_logs a".$where;
            else $parts[]="SELECT 'registry' AS source,a.id AS event_id,a.actor_user_id AS actor_id,a.action,CASE WHEN a.registry_id IS NOT NULL THEN 'student_registry' WHEN a.batch_id IS NOT NULL THEN 'registry_import' WHEN a.affected_user_id IS NOT NULL THEN 'user' ELSE NULL END AS entity_type,COALESCE(a.registry_id,a.batch_id,a.affected_user_id) AS entity_id,a.affected_user_id,NULL AS ip_address,NULL AS user_agent,a.detail AS details,a.created_epoch AS event_epoch FROM student_registry_events a".$where;
        }
        $total=array_sum($counts);$pages=max(1,(int)ceil($total/50));$page=min((int)$filters['page'],$pages);$filters['page']=$page;$rows=[];
        if($parts){
            $q=$this->db->prepare('SELECT events.*,u.username AS actor_name FROM ('.implode(' UNION ALL ',$parts).') events LEFT JOIN users u ON u.id=events.actor_id ORDER BY events.event_epoch DESC,events.source ASC,events.event_id DESC LIMIT 50 OFFSET '.(($page-1)*50));
            $q->execute($parameters);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        }
        $actionParts=[];
        foreach($available as $source)$actionParts[]='SELECT DISTINCT action FROM '.($source==='admin'?'admin_audit_logs':'student_registry_events');
        $actions=$this->db->query('SELECT DISTINCT action FROM ('.implode(' UNION ALL ',$actionParts).') codes ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
        return ['rows'=>$rows,'total'=>$total,'counts'=>$counts,'page'=>$page,'pages'=>$pages,'per_page'=>50,'actions'=>$actions,'filters'=>$filters,'warnings'=>$warnings];
    }

    private function where(string $source,array $filters): array
    {
        $where=[];$parameters=[];$prefix=$source.'_';
        foreach(['action'=>'a.action','admin_id'=>$source==='admin'?'a.admin_id':'a.actor_user_id'] as $key=>$column){
            if($filters[$key]==='')continue;
            $where[]=$column.'=:'.$prefix.$key;$parameters[$prefix.$key]=$filters[$key];
        }
        foreach(['date_from'=>'>=','date_to'=>'<'] as $key=>$operator){
            if($filters[$key]==='')continue;
            $date=new \DateTimeImmutable($filters[$key].' 00:00:00');
            if($key==='date_to')$date=$date->modify('+1 day');
            // DATETIME uses the connection timezone; epoch records do not. Filter
            // both against the same application-local date boundary/instant.
            $where[]=($source==='admin'?'a.created_at':'a.created_epoch').$operator.($source==='admin'?'FROM_UNIXTIME(:'.$prefix.$key.')':':'.$prefix.$key);
            $parameters[$prefix.$key]=$date->getTimestamp();
        }
        return [$where?' WHERE '.implode(' AND ',$where):'',$parameters];
    }

    public static function label(string $action,string $details=''): string
    {
        // Decode historical ambiguity only in presentation; original rows stay unchanged.
        if($action==='admin_deleted_user'){
            if(str_starts_with($details,'Deactivated user:'))return 'Account deactivated (legacy)';
            if(str_starts_with($details,'Hard deleted user:'))return 'Account permanently deleted (legacy)';
            return 'Account removal (legacy)';
        }
        return [
            'admin_user_activated'=>'Account activated','admin_user_deactivated'=>'Account deactivated','admin_user_deleted'=>'Account permanently deleted',
            'admin_status_change_failed'=>'Account status change failed','admin_action_denied'=>'Admin action denied','admin_delete_denied'=>'Account removal denied','admin_delete_failed'=>'Account removal failed',
            'admin_bulk_deleted_records'=>'Orphaned uploads cleaned','admin_deleted_upload'=>'Uploads deleted',
            'import_committed'=>'Student IDs imported','registration_created'=>'Student account registered','placeholder_claimed'=>'Existing student account claimed',
            'registration_rejected'=>'Registration rejected','registry_match'=>'Student access activated','duplicate_claim'=>'Duplicate Student ID claim blocked','match_conflict'=>'Student ID claim conflict',
            'status_active'=>'Student ID eligibility activated','status_inactive'=>'Student ID eligibility deactivated','status_revoked'=>'Student ID eligibility revoked',
            'initial_allowlist_initialized'=>'Initial Student ID registry established','initial_allowlist_excluded'=>'Historical Student ID excluded','reconciliation_restricted'=>'Student account access restricted',
        ][$action]??ucfirst(str_replace('_',' ',$action));
    }
}
