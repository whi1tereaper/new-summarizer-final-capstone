<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../app/src/Support/config.php';
require_once __DIR__.'/../app/src/Services/StudentRetentionService.php';
try {echo json_encode((new \App\Src\Services\StudentRetentionService())->run(in_array('--apply',$argv,true)),JSON_PRETTY_PRINT).PHP_EOL;}
catch(Throwable $e){fwrite(STDERR,'Retention failed; inspect protected diagnostics. No personal details are printed.'.PHP_EOL);exit(1);}
