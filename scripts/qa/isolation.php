<?php
declare(strict_types=1);
// Must run before any test bootstrap can connect or mutate.
function qaConnection(): PDO {
    $name=(string)getenv('DB_NAME');$user=(string)getenv('DB_USERNAME');
    if (!preg_match('/\Aqa_light_[a-f0-9]{8}\z/',$name) || !preg_match('/\Aqa_light_[a-f0-9]{8}\z/',$user)
        || getenv('DB_HOST')!=='127.0.0.1' || getenv('DB_PORT')!=='3307') throw new RuntimeException('Restricted XAMPP QA configuration required.');
    $db=new PDO('mysql:host=127.0.0.1;port=3307;dbname='.$name.';charset=utf8mb4',$user,(string)getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if($db->query('SELECT DATABASE()')->fetchColumn()!==$name)throw new RuntimeException('Test database identity mismatch.');
    // Prove these credentials cannot read the application, before any writes.
    try{$db->query('SELECT id FROM ai_summarizer.users LIMIT 1');}catch(PDOException $e){if(in_array((int)($e->errorInfo[1]??0),[1044,1142],true))return $db;throw $e;}
    throw new RuntimeException('Test credentials have access to the application database.');
}
qaConnection();
