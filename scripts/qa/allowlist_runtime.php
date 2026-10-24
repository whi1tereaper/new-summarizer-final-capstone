<?php
// Accessible only through the isolated runner's loopback Apache Alias.
if(!preg_match('/\Aqa_light_[a-f0-9]{8}\z/',(string)getenv('DB_NAME'))){http_response_code(403);exit;}
require __DIR__.'/isolation.php';
header('Cache-Control: no-store');header('Content-Type: application/json');
echo json_encode(['php_version'=>PHP_VERSION,'sapi'=>PHP_SAPI,'database'=>qaConnection()->query('SELECT DATABASE()')->fetchColumn(),'production_access_denied'=>true]);
