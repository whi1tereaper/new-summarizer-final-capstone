<?php
// Synthetic fixtures only: in-memory SQLite and files under this documentation directory.
// No application bootstrap, production connection, emails, network calls or workers.
$root=dirname(__DIR__,3);
require_once $root.'/app/src/Controllers/DocumentSummaryController.php';
require_once $root.'/app/src/Services/BarkTtsService.php';
$results=[];
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE documents (id INTEGER PRIMARY KEY, content TEXT, user_id INTEGER, guest_token TEXT, created_at TEXT)');
$pdo->exec("INSERT INTO documents VALUES (1,'AUDIT_SYNTHETIC_OTHER_OWNER_CONTENT',101,NULL,'2026-10-17 00:00:00')");
$bridge=(new ReflectionClass(App\Src\Services\LocalPythonBridge::class))->newInstanceWithoutConstructor();
$service=new App\Src\Services\DocumentSummaryService($pdo,$bridge);
$controller=new App\Src\Controllers\DocumentSummaryController($service);
$_SESSION=['user_id'=>202,'role'=>'user'];
ob_start();$controller->dispatch('GET','/api/documents/1');$reply=json_decode(ob_get_clean(),true);
$results[]=['id'=>'BACKEND-FIXTURE-01','procedure'=>'Dispatch GET for synthetic document belonging to user101 while session user202 is active; in-memory SQLite only.','expected'=>'Ownership denial/404; no source content.','actual_http'=>$controller->getLastStatusCode(),'other_owner_content_returned'=>($reply['content']??'')==='AUDIT_SYNTHETIC_OTHER_OWNER_CONTENT','status'=>($reply['content']??'')==='AUDIT_SYNTHETIC_OTHER_OWNER_CONTENT'?'FAIL':'PASS','limitation'=>'Tests actual controller/service with synthetic DB. Does not prove deployed rewrite availability.'];
$_SESSION=[];
ob_start();$controller->dispatch('GET','/api/documents/1');$reply=json_decode(ob_get_clean(),true);
$results[]=['id'=>'BACKEND-FIXTURE-02','procedure'=>'Same document lookup with no authenticated identity; synthetic in-memory DB.','expected'=>'Access denied; no source content.','actual_http'=>$controller->getLastStatusCode(),'other_owner_content_returned'=>($reply['content']??'')==='AUDIT_SYNTHETIC_OTHER_OWNER_CONTENT','status'=>($reply['content']??'')==='AUDIT_SYNTHETIC_OTHER_OWNER_CONTENT'?'FAIL':'PASS'];
$tts=(new ReflectionClass(App\Src\Services\BarkTtsService::class))->newInstanceWithoutConstructor();
$property=(new ReflectionClass($tts))->getProperty('audioOutputDir');$property->setAccessible(true);$property->setValue($tts,__DIR__);
$fileName=str_repeat('a',64).'.wav';$fixturePath=__DIR__.DIRECTORY_SEPARATOR.$fileName;
file_put_contents($fixturePath,str_repeat('x',1000));
try{$reply=$tts->fetchAudioStream($fileName,'bytes=-100');$results[]=['id'=>'BACKEND-FIXTURE-03','procedure'=>'Request final100bytes of a synthetic1000byte audio cache file.','expected_http'=>206,'expected_length'=>100,'actual_http'=>$reply['status'],'actual_length'=>strlen($reply['body']),'status'=>$reply['status']===206&&strlen($reply['body'])===100?'PASS':'FAIL'];}finally{unlink($fixturePath);}
file_put_contents(__DIR__.'/backend-isolated-checks.json',json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
