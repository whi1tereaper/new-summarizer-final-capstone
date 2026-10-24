<?php
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../../app/src/Support/config.php';require __DIR__.'/../../app/src/Database.php';
$db=App\Src\Database::getConfiguredMySqlConnection();$report=['read_only'=>true,'counts'=>[]];
foreach(['users','summaries','feedback','student_applications','student_enrollment_registry'] as $table)$report['counts'][$table]=(int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
echo json_encode($report,JSON_PRETTY_PRINT).PHP_EOL;
