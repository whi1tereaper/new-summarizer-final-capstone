<?php
require_once __DIR__.'/../src/whitereaper.php';
require_once __DIR__.'/../src/Services/CampusAccessService.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{$s=new \App\Src\Services\CampusAccessService();echo json_encode($s->status(isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:null,$s->visitorToken()));}
catch(\Throwable $e){$f=\App\Src\Support\ProcessingError::fromThrowable($e,'ACCESS_SETUP_REQUIRED');http_response_code($f->httpStatus);echo json_encode(['error'=>$f->getMessage(),'failure'=>$f->toArray()]);}
