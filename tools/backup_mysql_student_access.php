<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$python=dirname(__DIR__).'/.venv-nlp/Scripts/python.exe';
$process=proc_open([$python,__DIR__.'/../scripts/qa/run.py','--backup-only'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,dirname(__DIR__));
exit(is_resource($process)?proc_close($process):1);
