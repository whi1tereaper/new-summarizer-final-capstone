<?php
declare(strict_types=1);
// Read-only source/rollback proof, independent of the application's database.
if(PHP_SAPI!=='cli')exit(1);
$folder=realpath($argv[1]??'');$local=realpath((string)getenv('LOCALAPPDATA'));
$key=static fn($p)=>strtolower(str_replace('\\','/',(string)$p));
if(!$folder||!$local||!str_starts_with($key($folder),$key($local).'/light-account-claim-'))throw new RuntimeException('Use the private account-claim baseline folder.');
$root=dirname(__DIR__,2);
function sourceHashes(string $dir): array {
    $out=[];$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));
    foreach($iterator as $file){if($file->isFile())$out[str_replace('\\','/',substr($file->getPathname(),strlen($dir)+1))]=hash_file('sha256',$file->getPathname());}
    ksort($out);return $out;
}
$before=sourceHashes($folder.'/public-snapshot');$after=sourceHashes($root.'/app/public');
$meta=json_decode((string)file_get_contents($folder.'/backup.json'),true,512,JSON_THROW_ON_ERROR);
$checks=[['name'=>'all public frontend files byte-identical to the pre-change snapshot','passed'=>$before===$after],['name'=>'private rollback SQL size and SHA-256 verified','passed'=>filesize($meta['path'])===$meta['bytes']&&hash_equals($meta['sha256'],hash_file('sha256',$meta['path']))],['name'=>'baseline full SQL restore was verified in isolation','passed'=>$meta['restored_all_tables_identical']===true]];
$failed=count(array_filter($checks,fn($c)=>!$c['passed']));
$report=['public_files_compared'=>count($before),'frontend_manifest_sha256'=>hash('sha256',json_encode($before)),'backup_bytes'=>$meta['bytes'],'backup_sha256'=>$meta['sha256'],'passed'=>count($checks)-$failed,'failed'=>$failed,'checks'=>$checks];
$json=json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
file_put_contents($root.'/docs/qa/student-account-claim/source-rollback-integrity.json',$json);echo $json;exit($failed?1:0);
