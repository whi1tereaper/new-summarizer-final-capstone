<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/isolation.php';
require_once __DIR__.'/../../app/src/Support/config.php';
require_once __DIR__.'/../../app/src/Database.php';
$folder=realpath($argv[1]??'');if(!$folder||str_contains(strtolower(str_replace('\\','/',$folder)),'/htdocs/'))throw new RuntimeException('Private fixture folder required.');
$db=App\Src\Database::getInstance()->getConnection();
$users=[];
foreach(['active'=>'user','inactive'=>'user','other_admin'=>'admin','quoted'=>'user'] as $key=>$role){
    $username=$key==='quoted'?"qa_actions_quote_'\"<test>":'qa_actions_'.$key;$active=$key==='inactive'?0:1;
    $existing=$db->prepare('SELECT id FROM users WHERE username=?');$existing->execute([$username]);$existingId=$existing->fetchColumn();
    if($existingId!==false){$users[$key]=['id'=>(int)$existingId,'username'=>$username,'role'=>$role,'active'=>$active];continue;}
    $db->prepare('INSERT INTO users(username,email,password_hash,role,active,terms_accepted,terms_accepted_at)VALUES(?,?,?,?,?,1,NOW())')->execute([$username,$username.'@example.invalid',password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT),$role,$active]);
    $users[$key]=['id'=>(int)$db->lastInsertId(),'username'=>$username,'role'=>$role,'active'=>$active];
}
$q=$db->prepare('SELECT id,username,role,active FROM users WHERE username=?');$q->execute(['qa_admin']);$users['self']=$q->fetch(PDO::FETCH_ASSOC);
file_put_contents($folder.'/admin-actions-fixtures.json',json_encode(['users'=>$users,'synthetic'=>true],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo json_encode(['synthetic_users'=>array_keys($users)],JSON_PRETTY_PRINT);
