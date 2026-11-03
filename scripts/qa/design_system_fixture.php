<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/isolation.php';
require_once __DIR__ . '/../../app/src/Support/config.php';
require_once __DIR__ . '/../../app/src/Database.php';
require_once __DIR__ . '/../../app/src/Services/EvaluationService.php';
$folder = realpath($argv[1] ?? '');
if (!$folder || str_contains(strtolower(str_replace('\\','/',$folder)), '/htdocs/')) throw new RuntimeException('Private fixture folder required.');
if (($argv[2] ?? '') === 'clear-drafts') {
    $sessions=json_decode((string)file_get_contents($folder.'/sessions.json'),true,512,JSON_THROW_ON_ERROR);
    foreach($sessions as $sessionId){session_save_path($folder);session_name('LIGHT_QA_SESSID');session_id($sessionId);session_start();foreach(['summarizer_form_state','summarizer_form_saved_at','flash_failure','error','success'] as $key)unset($_SESSION[$key]);session_write_close();}
    echo "Isolated session drafts and validation messages cleared.\n";exit;
}
$db = App\Src\Database::getInstance()->getConnection();
$users = [];
foreach (['admin'=>'qa_admin','user'=>'qa_existing'] as $role=>$username) {
    $q=$db->prepare('SELECT id,username,email,role FROM users WHERE username=?'); $q->execute([$username]); $users[$role]=$q->fetch(PDO::FETCH_ASSOC);
}
$source = "The campus library tested a quiet study area during a six-week pilot. Students reported easier access to reference materials and more predictable study hours. Staff measured attendance each weekday and recorded maintenance needs.\n\nThe pilot included eighty volunteer students from three departments. Attendance rose by twelve percent during the trial, although the short observation period does not establish a lasting improvement. The library recommended a longer trial before extending weekend opening hours.";
$summary = 'A six-week campus library pilot improved reported access to study materials and recorded a twelve percent attendance rise among eighty volunteers. Staff recommended a longer trial before extending weekend hours because the short study cannot establish lasting effects.';
$q=$db->prepare('INSERT INTO summaries (user_id,share_token,article_title,author_name,input_type,summary_style,status) VALUES (?,?,?,?,?,?,?)');
$share=bin2hex(random_bytes(32)); $q->execute([$users['user']['id'],$share,'Campus library study area pilot','Synthetic design-system QA fixture','text','standard_paragraph','completed']); $summaryId=(int)$db->lastInsertId();
$db->prepare('INSERT INTO summary_artifacts(summary_id,original_text,generated_summary)VALUES(?,?,?)')->execute([$summaryId,$source,$summary]);
$q=$db->prepare('INSERT INTO feedback(summary_id,user_id,rating,name,email,comment)VALUES(?,?,?,?,?,?)');$q->execute([$summaryId,$users['user']['id'],4,'QA reviewer','qa@example.invalid','Synthetic fixture: clear, concise wording.']);
$q=$db->prepare('INSERT INTO nutshell_generations(summary_id,user_id,nutshell_text,source_word_count,word_count,created_at)VALUES(?,?,?,?,?,UTC_TIMESTAMP())');
try {$q->execute([$summaryId,$users['user']['id'],'A campus library pilot needs a longer evaluation before expanding opening hours.',str_word_count($source),14]);} catch (PDOException $e) { /* Optional older Nutshell variants are not needed for rendering the main fixture. */ }
$service = new App\Src\Services\EvaluationService($db);
$dataset=$service->createDataset(['version'=>'design-system-qa-'.bin2hex(random_bytes(4)),'name'=>'Synthetic design-system QA dataset'],(int)$users['admin']['id']);
$document=$service->addDocument($dataset,['title'=>'Synthetic library study','source_text'=>$source,'provenance'=>'Synthetic browser rendering fixture, not research evidence.'],(int)$users['admin']['id']);
$run=$service->queueRun($dataset,['name'=>'Synthetic visual QA report','modes'=>['brief'],'systems'=>['proposed']],(int)$users['admin']['id']);
$service->startRun($run); foreach($service->pendingOutputs($run) as $output)$service->persistOutput((int)$output['id'],['status'=>'completed','summary'=>$summary,'metrics'=>[]]);$service->finishRun($run);
$service->saveReport($run,['version'=>'synthetic-visual-fixture','automatic'=>[],'comparisons'=>[],'methodology'=>[],'warnings'=>['Synthetic rendering fixture. No evaluation scores were generated.']]);
$sessions=[];
foreach (['admin','user','processing'] as $role) {
    $user=$users[$role==='admin'?'admin':'user'];
    session_save_path($folder);session_name('LIGHT_QA_SESSID');session_id(bin2hex(random_bytes(16)));session_start();
    $_SESSION=['user_id'=>(int)$user['id'],'username'=>$user['username'],'email'=>$user['email'],'role'=>$user['role'],'terms_accepted'=>1,'terms_accepted_at'=>'2026-10-27 00:00:00','admin_challenge_verified'=>true];
    if($role==='processing')$_SESSION['pending_summary']=['job_id'=>'design-system-controlled-pending','source_type'=>'text','original_text'=>$source,'document_title'=>'Synthetic processing fixture','output_format'=>'paragraph','analysis_mode'=>'general','summary_depth'=>'balanced'];
    $sessions[$role]=session_id();session_write_close();
}
file_put_contents($folder.'/sessions.json',json_encode($sessions,JSON_THROW_ON_ERROR));
file_put_contents($folder.'/fixtures.json',json_encode(['summary_id'=>$summaryId,'share'=>$share,'run_id'=>$run,'source'=>$source,'synthetic'=>true],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo json_encode(['synthetic_summary_id'=>$summaryId,'synthetic_evaluation_run_id'=>$run,'sessions_created'=>array_keys($sessions)],JSON_PRETTY_PRINT);
