<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../app/src/Support/config.php';
require_once __DIR__ . '/../app/src/Services/EvaluationService.php';
use App\Src\Database;
use App\Src\Services\EvaluationService;

$options=getopt('',['file:','actor:','version:','queue','real-only','synthetic-only','help']);
if (isset($options['help']) || !isset($options['file'],$options['actor'])) {
    echo "php scripts/evaluation_import.php --file path/to/corpus.json --actor ADMIN_ID [--version UNIQUE_VERSION] [--queue] [--real-only|--synthetic-only]\nCorpus: dataset_version, dataset_split, documents[] (or synthetic challenge cases[]). --queue generates all four modes and four systems offline.\n";
    exit(isset($options['help']) ? 0 : 1);
}
try {
    $actor=filter_var($options['actor'],FILTER_VALIDATE_INT);
    $db=Database::getInstance()->getConnection();
    $check=$db->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND active=1"); $check->execute([$actor]);
    if (!$actor || !$check->fetchColumn()) throw new InvalidArgumentException('Actor must be an active administrator account.');
    $file=realpath((string)$options['file']);
    if (!$file || !is_file($file) || filesize($file)>20000000) throw new InvalidArgumentException('Provide a local JSON corpus under 20 MB.');
    $corpus=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($corpus)) throw new InvalidArgumentException('Corpus must be a JSON object.');
    if (!isset($corpus['documents']) && is_array($corpus['cases'] ?? null)) {
        $corpus['documents']=array_map(static fn($case)=>array_merge($case,['title'=>$case['title'] ?? $case['id'] ?? 'Synthetic challenge','synthetic'=>true,'source_provenance'=>$corpus['provenance'] ?? 'Synthetic engineering challenge.','profile'=>$case['profile'] ?? 'general']),$corpus['cases']);
        $corpus['purpose']=$corpus['purpose'] ?? $corpus['provenance'] ?? 'Synthetic engineering challenge corpus.';
    }
    if (!is_array($corpus['documents'] ?? null)) throw new InvalidArgumentException('Corpus requires a documents array.');
    if (isset($options['real-only'],$options['synthetic-only'])) throw new InvalidArgumentException('Choose only one corpus filter.');
    if (isset($options['real-only'])) $corpus['documents']=array_values(array_filter($corpus['documents'],static fn($doc)=>($doc['synthetic'] ?? null)===false));
    if (isset($options['synthetic-only'])) $corpus['documents']=array_values(array_filter($corpus['documents'],static fn($doc)=>($doc['synthetic'] ?? null)===true));
    if (!$corpus['documents']) throw new InvalidArgumentException('No documents match the selected corpus filter.');
    $service=new EvaluationService($db); $db->beginTransaction();
    $dataset=$service->createDataset(['version'=>$options['version'] ?? $corpus['dataset_version'] ?? '', 'name'=>$corpus['name'] ?? $corpus['dataset_version'] ?? 'Imported corpus','notes'=>$corpus['purpose'] ?? '', 'target_count'=>max(1,count($corpus['documents']))],$actor);
    foreach ($corpus['documents'] as $doc) {
        $id=$service->addDocument($dataset,array_merge($doc,['dataset_split'=>$doc['dataset_split'] ?? $corpus['dataset_split'] ?? 'development','provenance'=>$doc['provenance'] ?? $doc['source_provenance'] ?? '', 'source_reference'=>$doc['source_url'] ?? $doc['source_reference'] ?? '']),$actor);
        foreach (($doc['references'] ?? []) as $reference) $service->addReference($id,$reference,$actor);
        foreach (($doc['content_units'] ?? []) as $unit) $service->addContentUnit($id,is_string($unit) ? ['text'=>$unit] : $unit,$actor);
        if (!empty($doc['critical_facts']) || !empty($doc['expected_facts'])) $service->addChallengeCase($id,['category'=>$doc['category'] ?? 'challenge','expected_facts'=>$doc['critical_facts'] ?? $doc['expected_facts'],'prohibited_distortions'=>$doc['prohibited_distortions'] ?? []],$actor);
    }
    $run=null;
    if (isset($options['queue'])) $run=$service->queueRun($dataset,['name'=>($corpus['dataset_version'] ?? 'Imported').' validation','dataset_split'=>$corpus['dataset_split'] ?? 'development','systems'=>EvaluationService::SYSTEMS],$actor);
    $db->commit(); echo "Imported dataset {$dataset}.".($run ? " Queued run {$run}." : '')."\n";
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR,'Import failed: '.$e->getMessage()."\n"); exit(1);
}
