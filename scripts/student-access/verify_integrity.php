<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$root=dirname(__DIR__,2);$baseline=json_decode(file_get_contents($root.'/docs/student-number-access/baseline.json'),true,512,JSON_THROW_ON_ERROR);
$manifest=json_decode(file_get_contents($root.'/docs/research/final-validation/artifact-manifest.json'),true,512,JSON_THROW_ON_ERROR);
$mismatches=[];
foreach($baseline['nlp_sha256']+$manifest['sha256'] as $path=>$hash)if(!is_file($root.'/'.$path)||!hash_equals($hash,hash_file('sha256',$root.'/'.$path)))$mismatches[]=$path;
require_once $root.'/app/src/Support/config.php';
$routing=(bool)config('summarizer.automatic_article_routing',false);
$result=['recorded_utc'=>gmdate('c'),'nlp_files_checked'=>count($baseline['nlp_sha256']),'historical_research_files_checked'=>count($manifest['sha256']),'mismatches'=>$mismatches,'automatic_article_routing'=>$routing,'note'=>'Protected historical production PHP snapshots are not rewritten to include this unrelated access-control integration.'];
if(in_array('--report',$argv,true))file_put_contents($root.'/docs/student-number-access/integrity.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;exit($mismatches||$routing?1:0);
