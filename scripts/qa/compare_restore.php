<?php
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../../app/src/Support/config.php';
$folder=realpath($argv[1]??'');if(!$folder||!str_starts_with(basename($folder),'light-qa-'))throw new RuntimeException('Private QA folder required.');
$cfg=json_decode(file_get_contents($folder.'/config.json'),true);$name=$cfg['restore'];if(!preg_match('/\Aqa_light_restore_[a-f0-9]{8}\z/',$name))throw new RuntimeException('Invalid restore target.');
$db=new PDO('mysql:host=127.0.0.1;port=3307;dbname='.$name.';charset=utf8mb4',config('database.username'),config('database.password'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach($cfg['before'] as $table=>$expected){
 $rows=$db->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);$hashes=array_map(fn($row)=>hash('sha256',json_encode($row,JSON_INVALID_UTF8_SUBSTITUTE)),$rows);sort($hashes);$actual=['rows'=>count($rows),'sha256'=>hash('sha256',implode('',$hashes))];
 if($actual!==$expected)echo json_encode(['table'=>$table,'before'=>$expected,'restored'=>$actual]).PHP_EOL;
}
