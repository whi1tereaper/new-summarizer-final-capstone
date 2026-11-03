<?php
declare(strict_types=1);
require __DIR__.'/../student-access/claim_fixture.php';
$db=fixtureConnection('mysql');injectFixture($db);$action=$argv[1]??'';
if($action==='setup-http'){
    claimImport($db,'PEN2092-448002');claimFixture($db,'PEN2092-448002',true);echo json_encode(['ready'=>true]);exit;
}
if($action!=='inspect')exit(1);
$number=App\Src\Services\StudentVerificationService::normalizeNumber($argv[2]??'');
$q=$db->prepare('SELECT user_id FROM student_applications WHERE student_number_fingerprint=?');$q->execute([App\Src\Services\StudentVerificationService::fingerprint($number)]);$id=$q->fetchColumn();
if($id===false)throw new RuntimeException('Synthetic placeholder not found.');
$rows=claimSnapshot($db,(int)$id);$u=$rows['user'][0];$app=$rows['app'][0];$q=$db->prepare("SELECT COUNT(*) FROM student_registry_events WHERE affected_user_id=? AND action='placeholder_claimed'");$q->execute([$id]);
$record=['id'=>(int)$id,'username'=>$u['username'],'email'=>$u['email'],'active'=>$u['active'],'role'=>$u['role'],'terms'=>$u['terms_accepted'],'state'=>$app['account_claim_state'],'status'=>$app['status'],'method'=>$app['approval_method'],'registry_id'=>$app['registry_id'],'application_id'=>$app['id'],'application_ref'=>$app['application_ref'],'created_at'=>$u['created_at'],'password_valid'=>password_verify('QaStrong123!',$u['password_hash']),'password_hashed'=>password_get_info($u['password_hash'])['algoName']!=='unknown','credential_digest'=>hash('sha256',json_encode($rows['user'])),'claim_events'=>(int)$q->fetchColumn(),'users'=>(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()];
foreach(['summaries','artifacts','feedback','trial'] as $table)$record[$table]=['count'=>count($rows[$table]),'sha256'=>hash('sha256',json_encode($rows[$table]))];
echo json_encode($record,JSON_THROW_ON_ERROR);
