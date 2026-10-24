<?php
require __DIR__.'/test_bootstrap.php';
$db=fixtureConnection($argv[1]);$s=new \App\Src\Services\CampusAccessService($db);$ticket=null;
try{
 $ticket=$s->reserve(null,$argv[2],'race:'.bin2hex(random_bytes(8)));usleep(120000);
 $db->beginTransaction();$s->complete($ticket);$db->commit();echo 'completed';
}catch(\App\Src\Support\ProcessingError $e){if($db->inTransaction())$db->rollBack();echo $e->errorCode;}
catch(Throwable $e){if($db->inTransaction())$db->rollBack();echo 'failure:'.get_class($e).':'.$e->getMessage();exit(1);}
finally{$s->release($ticket);}
