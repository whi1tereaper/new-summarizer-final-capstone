<?php
// Compatibility URL only: student identity forms have been retired.
require_once __DIR__.'/../src/whitereaper.php';
header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(410);exit('Student verification forms are retired. Student ID eligibility is database-managed.');}
header('Location: '.(empty($_SESSION['user_id'])?'login.php':'profile.php'),true,303);exit;
