<?php
declare(strict_types=1);
namespace App\Src\Services;
require_once __DIR__.'/CampusAccessService.php';
use App\Src\Database;
use PDO;

/** Physical detail erasure; approval and account data are not a three-day lease. */
final class StudentRetentionService
{
    private PDO $db;
    public function __construct(?PDO $db=null){$this->db=$db??Database::getInstance()->getConnection();}
    public function run(bool $apply=false,int $limit=500): array
    {
        $now=time();$limit=max(1,min(5000,$limit));
        $q=$this->db->prepare('SELECT id,user_id FROM student_applications WHERE details_delete_epoch<=? AND details_erased_epoch IS NULL ORDER BY user_id LIMIT '.$limit);$q->execute([$now]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$this->db->prepare('SELECT COUNT(*) FROM student_review_events WHERE created_epoch<=? AND (notes IS NOT NULL OR student_message IS NOT NULL)');$q->execute([$now-259200]);
        $result=['apply'=>$apply,'applications_due_batch'=>count($rows),'review_details_due'=>(int)$q->fetchColumn(),'applications_erased'=>0,'review_details_erased'=>0,'reservations_removed'=>0,'anonymous_subjects_removed'=>0];
        if(!$apply)return $result;
        $q=$this->db->prepare("UPDATE student_registry_batches SET preview_payload=NULL,state='expired' WHERE state='preview' AND preview_expires_epoch<=?");$q->execute([$now]);
        $result['registry_previews_erased']=$q->rowCount();
        foreach($rows as $candidate){
            $this->db->beginTransaction();
            try{
                $q=$this->db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$candidate['user_id']]);
                $q=$this->db->prepare('SELECT * FROM student_applications WHERE id=? FOR UPDATE');$q->execute([$candidate['id']]);$r=$q->fetch(PDO::FETCH_ASSOC);
                if($r && !$r['details_erased_epoch'] && (int)$r['details_delete_epoch']<=$now){
                    $status=$r['status']==='pending'?'resubmission_required':$r['status'];
                    $keep=$r['status']==='verified'||$r['evidence_kind']!==null;
                    $q=$this->db->prepare('UPDATE student_applications SET full_name=NULL,student_number=NULL,normalized_student_number=NULL,evidence_reference=NULL,ownership_reference=NULL,review_reason=NULL,student_number_fingerprint=?,status=?,details_erased_epoch=?,updated_epoch=?,version=version+1 WHERE id=?');
                    $q->execute([$keep?$r['student_number_fingerprint']:null,$status,$now,$now,$r['id']]);$result['applications_erased']+=$q->rowCount();
                }
                $this->db->commit();
            }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        }
        // Preserve only minimal actor/action/status/time audit, never old notes.
        $q=$this->db->prepare('UPDATE student_review_events SET notes=NULL,student_message=NULL WHERE created_epoch<=? AND (notes IS NOT NULL OR student_message IS NOT NULL)');$q->execute([$now-259200]);$result['review_details_erased']=$q->rowCount();
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT subject_key FROM summary_access_subjects WHERE last_activity_epoch<? AND subject_key NOT IN (SELECT subject_key FROM student_trial_links) ORDER BY subject_key LIMIT '.$limit.' FOR UPDATE');$q->execute([$now-CampusAccessService::COOKIE_SECONDS]);
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $key){
                $active=$this->db->prepare("SELECT attempt_id FROM summary_access_reservations WHERE subject_key=? AND state='reserved' AND expires_epoch>? FOR UPDATE");$active->execute([$key,$now]);if($active->fetchColumn())continue;
                $d=$this->db->prepare('DELETE FROM summary_access_reservations WHERE subject_key=?');$d->execute([$key]);$result['reservations_removed']+=$d->rowCount();
                $d=$this->db->prepare('DELETE FROM summary_access_subjects WHERE subject_key=? AND subject_key NOT IN (SELECT subject_key FROM student_trial_links)');$d->execute([$key]);$result['anonymous_subjects_removed']+=$d->rowCount();
            }
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        $q=$this->db->prepare("DELETE FROM summary_access_reservations WHERE created_epoch<? AND (state<>'reserved' OR expires_epoch<=?)");$q->execute([$now-CampusAccessService::COOKIE_SECONDS,$now]);$result['reservations_removed']+=$q->rowCount();
        return $result;
    }
}
