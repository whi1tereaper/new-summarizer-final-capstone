<?php
// Historical records remain in MySQL; manual approval is no longer an action.
require_once __DIR__.'/../src/whitereaper.php';
require_once __DIR__.'/../src/Services/StudentVerificationService.php';
header('Cache-Control: no-store');
try{(new App\Src\Services\StudentVerificationService())->requireAdmin((int)($_SESSION['user_id']??0));}catch(Throwable $error){http_response_code(403);exit('Access denied.');}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(410);exit('Manual student approval is retired. Use Student ID Management.');}
header('Location: admin_student_registry.php#reconciliation',true,303);exit;
