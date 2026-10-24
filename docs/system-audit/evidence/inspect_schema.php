<?php
// Audit-only metadata reader. No application bootstrap, row data, DDL, or DML.
require_once dirname(__DIR__, 3) . '/app/src/Support/Env.php';
use App\Src\Support\Env;
Env::load();
try {
    $schema = Env::requireString('DB_NAME');
    $dsn = 'mysql:host=' . Env::requireString('DB_HOST') . ';port=' . Env::requireString('DB_PORT') . ';dbname=' . $schema . ';charset=' . Env::requireString('DB_CHARSET');
    $pdo = new PDO($dsn, Env::requireString('DB_USERNAME'), Env::requireString('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    $queries = [
      'tables' => 'SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
      'columns' => 'SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION',
      'indexes' => 'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
      'foreign_keys' => 'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
      'checks' => 'SELECT TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? ORDER BY TABLE_NAME, CONSTRAINT_NAME'
    ];
    $result=['status'=>'VERIFIED metadata read only','server_version'=>$pdo->query('SELECT VERSION()')->fetchColumn(),'schema_label'=>'configured application database','captured_at'=>date(DATE_ATOM),'note'=>'No application row values, credentials, or private user information are included.'];
    foreach($queries as $key=>$sql){$statement=$pdo->prepare($sql);$statement->execute([$schema]);$result[$key]=$statement->fetchAll();}
    file_put_contents(__DIR__.'/live-schema.json', json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    echo json_encode(['status'=>$result['status'],'tables'=>count($result['tables']),'columns'=>count($result['columns']),'foreign_keys'=>count($result['foreign_keys']),'server_version'=>$result['server_version']]);
} catch(Throwable $e){
    // Suppress driver details because connection messages can reveal deployment identifiers.
    file_put_contents(__DIR__.'/live-schema.json',json_encode(['status'=>'UNAVAILABLE EVIDENCE','reason'=>'Direct metadata connection or query failed. No application bootstrap was executed.','error_class'=>get_class($e)],JSON_PRETTY_PRINT));
    echo 'Direct metadata read unavailable; credentials and driver details suppressed.';
    exit(1);
}
