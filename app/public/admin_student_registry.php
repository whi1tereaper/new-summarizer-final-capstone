<?php
require_once __DIR__.'/../src/whitereaper.php';
require_once __DIR__.'/../src/Utils/validation.php';
require_once __DIR__.'/../src/Services/AdminSecurityService.php';
require_once __DIR__.'/../src/Services/StudentEnrollmentRegistryService.php';
require_once __DIR__.'/../src/Services/RateLimiter.php';
use App\Src\Services\StudentEnrollmentRegistryService as Registry;
use App\Src\Services\StudentVerificationService as Verification;
use App\Src\Services\AdminSecurityService;
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
$actor=(int)($_SESSION['user_id']??0);$service=new Registry();
try{(new Verification())->requireAdmin($actor);if(!AdminSecurityService::isVerifiedAdmin())throw new RuntimeException();}catch(Throwable $e){http_response_code(403);exit('Access denied.');}
$escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$error='';$notice=(string)($_SESSION['registry_notice']??'');unset($_SESSION['registry_notice']);
$batch=null;$rows=[];$imports=[];$conflicts=[];$registryStats=[];$reconciliation=['epoch'=>null,'summary'=>[]];
$status=is_string($_GET['status']??null)?$_GET['status']:'';$page=max(1,min(10000,(int)($_GET['page']??1)));$historyPage=max(1,min(10000,(int)($_GET['history_page']??1)));
try{
 if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
  if(!is_string($_POST['csrf_token']??null)||!verifyCsrfToken($_POST['csrf_token']))throw new App\Src\Support\ProcessingError('ACCESS_DENIED');
  $action=is_string($_POST['registry_action']??null)?$_POST['registry_action']:'';
  if($action==='search'){
   $search=is_string($_POST['search']??null)?trim($_POST['search']):'';
   if($search!=='')Verification::registrationNumber($search);
   $_SESSION['registry_search']=$search;$_SESSION['registry_search_at']=time();
  }else{
   $limiter=new App\Src\Services\RateLimiter();$key=in_array($action,['status','reconcile'],true)?'registry_status':'registry_import';
   if($limiter->isLimited($key,(string)$actor))throw new App\Src\Support\ProcessingError('RATE_LIMITED');
   $limiter->recordAttempt($key,(string)$actor);
   if($action==='preview'||$action==='import'){
    // Legacy import actions are deliberately preview-only; no confirmation bypass.
    $upload=$_FILES['registry_file']??null;$stream=null;$source='paste';
    try{
     if(is_array($upload)&&($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
      if($upload['error']!==UPLOAD_ERR_OK||!is_string($upload['tmp_name']??null)||!is_uploaded_file($upload['tmp_name'])||filesize($upload['tmp_name'])>Registry::MAX_BYTES)throw new InvalidArgumentException('Upload a CSV or TXT file no larger than 1 MiB.');
      if(trim((string)($_POST['student_ids']??''))!=='')throw new InvalidArgumentException('Choose either pasted IDs or a file, not both.');
      $source=strtolower(pathinfo((string)$upload['name'],PATHINFO_EXTENSION));$mime=(new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
      if(!in_array($source,['csv','txt'],true)||!in_array($mime,['text/plain','text/csv','application/csv','application/vnd.ms-excel'],true))throw new InvalidArgumentException('Only plain-text CSV/TXT lists are supported.');
      $stream=fopen($upload['tmp_name'],'rb');
     }else{
      $text=is_string($_POST['student_ids']??null)?$_POST['student_ids']:'';
      if(strlen($text)>Registry::MAX_BYTES)throw new InvalidArgumentException('Paste at most 1 MiB of Student IDs.');
      $stream=fopen('php://temp/maxmemory:1048576','w+');fwrite($stream,$text);rewind($stream);
     }
     $result=$service->preview($actor,$stream,$source);$_SESSION['registry_batch']=$result['batch_ref'];
     $_SESSION['registry_notice']='Preview created. Review valid IDs, duplicates and errors, then confirm to save.';
    }finally{if(is_resource($stream))fclose($stream);}
   }elseif($action==='confirm'){
    if(($_POST['confirm_import']??'')!=='1')throw new InvalidArgumentException('Confirm the reviewed import before saving.');
    $result=$service->confirm($actor,(string)($_POST['batch_ref']??''));$_SESSION['registry_batch']=$result['batch_ref'];
    $_SESSION['registry_notice']='Import complete: '.(int)$result['inserted_count'].' inserted, '.(int)$result['skipped_count'].' duplicates skipped, '.(int)$result['invalid_count'].' invalid entries excluded.';
   }elseif($action==='status'){
    if(($_POST['confirm_status']??'')!=='1')throw new InvalidArgumentException('Confirm the eligibility change.');
    $service->changeStatus($actor,(string)($_POST['registry_ref']??''),(int)($_POST['version']??0),(string)($_POST['eligibility_status']??''),(string)($_POST['reason']??''),$_POST);
    $_SESSION['registry_notice']='Eligibility saved. Active exact matches are approved automatically; inactive or revoked IDs cannot grant access. Account history is preserved.';
   }elseif($action==='reconcile'){
    if(($_POST['confirm_reconcile']??'')!=='1')throw new InvalidArgumentException('Confirm account reconciliation.');
    $service->reconcileAccounts($actor);$_SESSION['registry_notice']='Existing accounts reconciled against the active Student ID database. No passwords or summaries were changed.';
   }else throw new InvalidArgumentException('Invalid registry action.');
   rotateCsrfToken();
  }
  header('Location: admin_student_registry.php',true,303);exit;
 }
}catch(InvalidArgumentException $e){$error=$e->getMessage();http_response_code(422);}
catch(Throwable $e){$failure=App\Src\Support\ProcessingError::fromThrowable($e);$error=$failure->getMessage();http_response_code($failure->httpStatus);}
try{
 if((int)($_SESSION['registry_search_at']??0)<time()-900)unset($_SESSION['registry_search'],$_SESSION['registry_search_at']);
 $search=(string)($_SESSION['registry_search']??'');$registryStats=$service->stats($actor);$rows=$service->entries($actor,$search,$status,$page);
 $imports=$service->recent($actor,'batches',$historyPage);$conflicts=$service->recent($actor,'conflicts',$historyPage);$reconciliation=$service->reconciliationSummary($actor);
 if(isset($_SESSION['registry_batch']))$batch=$service->batch($actor,(string)$_SESSION['registry_batch']);
}catch(Throwable $e){$error='Student ID Management is unavailable. Check migration 020 and the private configuration.';http_response_code(503);}
?>
<!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Student ID Management — LIGHT</title><link rel="stylesheet" href="assets/css/design-tokens.css"><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/student-access.css"></head><body>
<?php require __DIR__.'/partials/site-nav.php'; ?><main class="student-shell registry-manager"><section class="student-access">
<a href="admin_dashboard.php#student-enrollment-registry">← Admin Dashboard</a><h1>Student ID Management</h1>
<p>Manage the approved NEUST Peñaranda Off-Campus Student ID database. An active, unclaimed match approves registration immediately. No student photos, documents, OTP or manual approval are required. List eligibility does not independently establish rightful ownership.</p>
<?php if($error): ?><div class="student-alert" role="alert"><?= $escape($error) ?></div><?php endif; ?><?php if($notice): ?><div class="student-alert" role="status"><?= $escape($notice) ?></div><?php endif; ?>
<?php require __DIR__.'/partials/registry-statistics.php'; ?>
<p class="registry-form-note">Registry totals retain inactive/revoked historical records. Only active, unexpired, conflict-free entries are eligible. Claimed IDs include disputed claims; registered accounts include retained restricted student accounts. Imports authorize IDs but never create new accounts. Rejected registrations count server-recorded validation and registry/account conflicts.</p>
<section class="registry-section" aria-labelledby="add-student-ids"><h2 id="add-student-ids">Add Student IDs</h2>
<div class="student-grid"><form method="post" data-registry-busy><input type="hidden" name="csrf_token" value="<?= $escape(generateCsrfToken()) ?>"><input type="hidden" name="registry_action" value="preview"><label for="single-student-id">Individual full Student ID</label><input id="single-student-id" name="student_ids" maxlength="40" placeholder="PEN2023-02071" required autocomplete="off" spellcheck="false"><button type="submit">Preview Single ID</button><span data-registry-progress role="status"></span></form>
<div><h3>Import guidance</h3><p>Only insert administrator-authorized IDs. Preserve the PEN prefix, separators and leading zeros. Students never populate this registry themselves.</p><p class="registry-form-note">Use UTF-8 CSV/TXT containing IDs only. Optional first line: <code>student_id</code>. Maximum 10,000 entries, 1 MiB, 4,096 bytes per line. Preview expires after 15 minutes.</p></div></div>
<form method="post" enctype="multipart/form-data" id="registry-import-form" data-registry-busy><input type="hidden" name="csrf_token" value="<?= $escape(generateCsrfToken()) ?>"><input type="hidden" name="registry_action" value="preview"><label for="student-ids">Bulk paste — one per line or comma-separated</label><textarea id="student-ids" name="student_ids" rows="7" aria-describedby="registry-detected"></textarea><p id="registry-detected" role="status">0 IDs detected (server validation is authoritative)</p><label for="registry-file">Or upload CSV / TXT</label><input type="file" id="registry-file" name="registry_file" accept=".csv,.txt,text/plain,text/csv"><button type="submit">Preview Import</button><span data-registry-progress role="status"></span></form></section>
<?php if($batch): $isPreview=$batch['state']==='preview'; ?><section id="registry-preview" class="registry-section student-alert"><h2><?= $isPreview?'Import Preview':'Import Results' ?></h2>
<dl class="student-grid"><?php foreach(['total_count'=>'IDs processed','inserted_count'=>'Inserted','skipped_count'=>'Existing / duplicate IDs','invalid_count'=>'Invalid rows','duplicate_count'=>'In-batch duplicates','approved_count'=>'Accounts automatically approved','conflict_count'=>'Claim conflicts'] as $field=>$label): ?><div><dt><?= $label ?></dt><dd data-count="<?= $field ?>"><?= (int)$batch[$field] ?></dd></div><?php endforeach; ?></dl>
<?php if($batch['sample_full']): ?><p>First <?= count($batch['sample_full']) ?> valid IDs: <span class="registry-id"><?= $escape(implode(', ',$batch['sample_full'])) ?></span></p><?php endif; ?>
<?php foreach(json_decode($batch['issues_json']??'[]',true) as $issue): ?><p>Line <?= (int)$issue['line'] ?>: <?= $escape($issue['issue']) ?></p><?php endforeach; ?>
<?php if($isPreview): ?><p>No IDs have been approved by this preview. Confirmation imports valid unique rows only; existing inactive/revoked IDs are not reactivated by reimport.</p><form method="post" id="registry-confirm-form" data-registry-busy><input type="hidden" name="csrf_token" value="<?= $escape(generateCsrfToken()) ?>"><input type="hidden" name="registry_action" value="confirm"><input type="hidden" name="batch_ref" value="<?= $escape($batch['batch_ref']) ?>"><label><input type="checkbox" name="confirm_import" value="1" required> I reviewed this authorized list and confirm the import.</label><button type="submit">Confirm Import</button><span data-registry-progress role="status"></span></form><?php else: ?><p>Import state: <?= $escape($batch['state']) ?>. <?= (int)$batch['eligible_until_epoch']>=Registry::PERMANENT_EPOCH?'Permanent eligibility unless an administrator changes the record.':'' ?></p><?php endif; ?></section><?php endif; ?>
<section class="registry-section" aria-labelledby="registry-entries"><h2 id="registry-entries">Approved Student ID database</h2><div class="student-grid"><form method="post"><input type="hidden" name="csrf_token" value="<?= $escape(generateCsrfToken()) ?>"><input type="hidden" name="registry_action" value="search"><label for="registry-search">Exact Student ID — private search</label><input id="registry-search" name="search" maxlength="40" value="<?= $escape($search??'') ?>" placeholder="PEN2023-02071" autocomplete="off"><button type="submit">Search / Clear</button></form><form method="get"><label for="registry-filter">Eligibility status</label><select id="registry-filter" name="status"><?php foreach([''=>'All statuses','active'=>'Active','inactive'=>'Inactive','revoked'=>'Revoked','expired'=>'Expired'] as $value=>$label): ?><option value="<?= $value ?>" <?= $value===$status?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select><button type="submit">Apply Filter</button></form></div>
<div class="student-table-wrap"><table><thead><tr><th scope="col">Student ID</th><th scope="col">Status</th><th scope="col">Account Status</th><th scope="col">Action</th></tr></thead><tbody>
<?php foreach($rows as $row): $conflicted=(int)$row['claim_conflict']===1; $eligible=!$conflicted&&$row['eligibility_status']==='active'&&(int)$row['eligible_until_epoch']>time(); ?><tr><td class="registry-id"><?= $escape($row['normalized_student_number']) ?></td><td><span class="registry-badge"><?= $escape($row['eligibility_status']) ?><?= (int)$row['eligible_until_epoch']<=time()?' / expired':'' ?></span></td><td><?php if($conflicted): ?>Conflicting claims — restricted<?php elseif($row['claimed_user_id']===null): ?>Unclaimed<?php else: ?><?= !(int)$row['account_active']?'Account inactive':($eligible&&$row['account_verification_status']==='verified'?'Registered — approved':'Registered — restricted') ?><small> · Account #<?= (int)$row['claimed_user_id'] ?></small><?php endif; ?></td><td><details><summary>Manage</summary><form method="post" data-registry-busy><input type="hidden" name="csrf_token" value="<?= $escape(generateCsrfToken()) ?>"><input type="hidden" name="registry_action" value="status"><input type="hidden" name="registry_ref" value="<?= $escape($row['registry_ref']) ?>"><input type="hidden" name="version" value="<?= (int)$row['version'] ?>"><label>Status<select name="eligibility_status"><option value="active">Active</option><option value="inactive">Inactive</option><option value="revoked">Revoked</option></select></label><label>Renewal period (optional)<input name="verification_period" maxlength="120"></label><label>Renewal end date (optional)<input type="date" name="eligible_until"></label><label>Reason<input name="reason" minlength="5" maxlength="500" required></label><label><input type="checkbox" name="confirm_status" value="1" required> Confirm eligibility and account-access changes.</label><button type="submit">Save Eligibility</button><span data-registry-progress role="status"></span></form></details></td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="4">No matching Student IDs.</td></tr><?php endif; ?></tbody></table></div><p class="registry-pagination">Page <?= $page ?> · 25 per page <a href="?status=<?= urlencode($status) ?>&amp;page=<?= max(1,$page-1) ?>">Previous</a><a href="?status=<?= urlencode($status) ?>&amp;page=<?= $page+1 ?>">Next</a></p></section>
<section class="registry-section" id="reconciliation"><h2>Existing-account reconciliation</h2><?php if($reconciliation['epoch']): ?><p>Last run: <?= $escape(date('Y-m-d H:i',(int)$reconciliation['epoch'])) ?></p><dl class="student-grid"><?php foreach($reconciliation['summary'] as $key=>$value): ?><div><dt><?= $escape(ucfirst(str_replace('_',' ',$key))) ?></dt><dd><?= (int)$value ?></dd></div><?php endforeach; ?></dl><?php else: ?><p>No reconciliation has run yet. Initialize the authoritative allowlist before release.</p><?php endif; ?><p>Exact active matches approve automatically. Unlisted or conflicting accounts remain restricted; administrator access, passwords and summary history are preserved. Historical evidence remains in the database; manual identity approval is retired.</p><form method="post" data-registry-busy><input type="hidden" name="csrf_token" value="<?= $escape(generateCsrfToken()) ?>"><input type="hidden" name="registry_action" value="reconcile"><label><input type="checkbox" name="confirm_reconcile" value="1" required> Reconcile existing accounts using current registry eligibility.</label><button type="submit">Reconcile Existing Accounts</button><span data-registry-progress role="status"></span></form></section>
<section class="registry-section"><h2>Import History</h2><div class="student-table-wrap"><table><thead><tr><th scope="col">Created / source</th><th scope="col">State</th><th scope="col">Processed / inserted / skipped / invalid</th><th scope="col">Approvals / conflicts</th></tr></thead><tbody><?php foreach($imports as $item): ?><tr><td><?= $escape(date('Y-m-d H:i',(int)$item['created_epoch'])) ?> / <?= $escape($item['source']) ?></td><td><?= $escape($item['state']) ?></td><td><?= (int)$item['total_count'] ?> / <?= (int)$item['inserted_count'] ?> / <?= (int)$item['skipped_count'] ?> / <?= (int)$item['invalid_count'] ?></td><td><?= (int)$item['approved_count'] ?> / <?= (int)$item['conflict_count'] ?></td></tr><?php endforeach; ?><?php if(!$imports): ?><tr><td colspan="4">No imports recorded.</td></tr><?php endif; ?></tbody></table></div>
<h3>Claim conflicts and restrictions</h3><?php if(!$conflicts): ?><p>No conflicts or restrictions recorded.</p><?php endif; ?><?php foreach($conflicts as $conflict): ?><div class="student-alert"><?= $escape(date('Y-m-d H:i',(int)$conflict['created_epoch'])) ?> · <?= $escape(str_replace('_',' ',$conflict['action'])) ?><?php if($conflict['affected_user_id']): ?> · Account #<?= (int)$conflict['affected_user_id'] ?><?php endif; ?><p><?= $escape($conflict['detail']) ?></p></div><?php endforeach; ?><p class="registry-pagination">History page <?= $historyPage ?><a href="?history_page=<?= max(1,$historyPage-1) ?>">Previous History</a><a href="?history_page=<?= $historyPage+1 ?>">Next History</a></p></section>
</section></main><script src="assets/js/student-registry.js" defer></script></body></html>
