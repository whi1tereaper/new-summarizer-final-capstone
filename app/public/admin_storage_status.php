<?php
declare(strict_types=1);
ob_start();
require_once __DIR__.'/../src/Support/ProcessingError.php';
try{
    require_once __DIR__.'/../src/whitereaper.php';
    require_once __DIR__.'/../src/Services/AdminSecurityService.php';
    require_once __DIR__.'/../src/Services/StorageMaintenanceService.php';
    if(!App\Src\Services\AdminSecurityService::isVerifiedAdmin())throw new App\Src\Support\ProcessingError('ACCESS_DENIED');
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){
        http_response_code(405);header('Allow: GET, HEAD');$body=['success'=>false,'message'=>'Read-only endpoint. Use GET.'];
    }else $body=['success'=>true,'snapshot'=>(new App\Src\Services\StorageMaintenanceService())->snapshot((int)$_SESSION['user_id'])];
}catch(Throwable $error){
    $failure=$error instanceof App\Src\Support\ProcessingError?$error:new App\Src\Support\ProcessingError('WORKER_UNAVAILABLE');
    http_response_code($failure->httpStatus);$body=['success'=>false,'failure'=>$failure->toArray()];
    error_log('[Storage status] Unavailable: '.get_class($error));
}
while(ob_get_level())ob_end_clean();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
