<?php
declare(strict_types=1);
namespace App\Src\Services;

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/SummarizerPromptTemplate.php';

use App\Src\Database;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Evaluation repository. Web callers MUST pass EvaluationAccess before calling management methods. */
final class EvaluationService
{
    public const VERSION = '1.1.0';
    public const MODES = SummarizerPromptTemplate::ALLOWED_LENGTHS;
    public const PROFILES = SummarizerPromptTemplate::ALLOWED_PROFILES;
    public const SYSTEMS = ['proposed', 'hybrid_llm', 'lead_n', 'tfidf', 'textrank'];
    public const ABLATIONS = ['title_similarity', 'section_weighting', 'sentence_position', 'paragraph_position', 'profile_weighting', 'article_type_relevance', 'redundancy_filtering', 'qualifier_protection', 'negation_protection', 'numerical_fact_protection'];
    public const CRITERIA = ['relevance', 'factual_consistency', 'coverage', 'coherence', 'readability', 'conciseness', 'non_redundancy'];
    public const ERROR_CATEGORIES = ['important_information_omitted', 'irrelevant_information_included', 'duplicated_information', 'sentence_fragment', 'coherence_failure', 'incorrect_section_prioritization', 'numerical_value_altered', 'numerical_value_omitted', 'negation_lost', 'qualifier_lost', 'named_entity_error', 'preprocessing_ocr_issue', 'excessive_compression', 'insufficient_compression', 'baseline_anomaly', 'unknown_other'];
    public const EXPECTED_METRICS = ['rouge_1', 'rouge_2', 'rouge_l', 'bertscore', 'compression_ratio', 'compression_percentage', 'redundancy', 'critical_fact_preservation', 'factual_consistency', 'content_unit_coverage', 'challenge'];
    private PDO $db;
    private ?int $packetLimit = null;

    public function __construct(?PDO $db = null) { $this->db = $db ?? Database::getInstance()->getConnection(); }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function decode(?string $value): array
    {
        if ($value === null || $value === '') return [];
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded) && ($decoded['__evaluation_encoding'] ?? null)==='gzip-base64-v1') {
            $compressed=base64_decode((string)($decoded['data'] ?? ''),true);
            $uncompressed=$compressed===false ? false : gzdecode($compressed,50000000);
            if ($uncompressed===false || !hash_equals((string)($decoded['sha256'] ?? ''),hash('sha256',$uncompressed))) throw new RuntimeException('Stored evaluation JSON failed its integrity check.');
            $decoded=json_decode($uncompressed,true,512,JSON_THROW_ON_ERROR);
        }
        return is_array($decoded) ? $decoded : [];
    }

    /** Lossless encoding keeps large snapshots/reports below common 1 MB MySQL packet limits. */
    private static function storageJson(array $value): string
    {
        $json=self::json($value);
        if (strlen($json)<=200000 || !function_exists('gzencode')) return $json;
        $compressed=gzencode($json,6);
        if ($compressed===false) return $json;
        $encoded=self::json(['__evaluation_encoding'=>'gzip-base64-v1','sha256'=>hash('sha256',$json),'data'=>base64_encode($compressed)]);
        return strlen($encoded)<strlen($json) ? $encoded : $json;
    }

    public static function canonicalHash(array $data): string
    {
        $sort = static function (array $value) use (&$sort): array {
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            foreach ($value as &$item) if (is_array($item)) $item = $sort($item);
            return $value;
        };
        return hash('sha256', self::json($sort($data)));
    }

    public static function codeFingerprint(): string
    {
        $root = dirname(__DIR__, 3) . '/python-engine';
        $files = array_merge(glob($root . '/summarizer_core/*.py') ?: [], glob($root . '/evaluation/*.py') ?: [], glob($root . '/*.py') ?: []);
        sort($files, SORT_STRING);
        $hashes = [];
        foreach ($files as $file) if (is_file($file)) $hashes[substr($file, strlen($root) + 1)] = hash_file('sha256', $file);
        return self::canonicalHash($hashes);
    }

    public static function countWords(string $text): int
    {
        // unicode-words-v1, identical to evaluation/text.py: decimal/compound words remain intact.
        return preg_match_all("/[\\p{L}\\p{N}_]+(?:[.'’,-][\\p{L}\\p{N}_]+)*%?/u", $text) ?: 0;
    }

    private static function text(mixed $value, string $label, int $max, bool $required = true): string
    {
        if (!is_string($value)) throw new InvalidArgumentException($label . ' must be text.');
        $value = trim($value);
        if (($required && $value === '') || strlen($value) > $max || !preg_match('//u', $value)) throw new InvalidArgumentException($label . ' is empty, too long, or invalid UTF-8.');
        return $value;
    }

    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    private function row(string $sql, array $params = []): ?array { return $this->rows($sql, $params)[0] ?? null; }
    private function execute(string $sql, array $params = []): void
    {
        $bytes=strlen($sql)+128;
        foreach ($params as $param) if (is_string($param)) $bytes+=strlen($param)+16;
        if ($bytes>200000) {
            $this->packetLimit ??= (int)$this->db->query('SELECT @@max_allowed_packet')->fetchColumn();
            if ($bytes>$this->packetLimit-16384) throw new DomainException('This research record exceeds the database packet limit. Increase MySQL max_allowed_packet or select a smaller dataset. No record was partially saved.');
        }
        $stmt = $this->db->prepare($sql); $stmt->execute($params);
    }
    private function transaction(callable $work): mixed
    {
        // Savepoints allow integration fixtures to roll back without altering application records.
        $nested = $this->db->inTransaction();
        if ($nested) $this->db->exec('SAVEPOINT evaluation_operation'); else $this->db->beginTransaction();
        try {
            $result = $work();
            if ($nested) $this->db->exec('RELEASE SAVEPOINT evaluation_operation'); else $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($nested) $this->db->exec('ROLLBACK TO SAVEPOINT evaluation_operation'); elseif ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function audit(int $actor, string $action, string $type, int $id, mixed $previous = null, mixed $replacement = null, ?string $reason = null): void
    {
        $this->execute('INSERT INTO evaluation_audit_log (actor_id,action,entity_type,entity_id,previous_json,replacement_json,reason) VALUES (?,?,?,?,?,?,?)', [$actor ?: null, $action, $type, $id, $previous === null ? null : self::json($previous), $replacement === null ? null : self::json($replacement), $reason]);
    }

    private function editableDataset(int $id): array
    {
        $dataset = $this->row('SELECT * FROM evaluation_datasets WHERE id = ? FOR UPDATE', [$id]);
        if (!$dataset) throw new InvalidArgumentException('Dataset was not found.');
        if ($dataset['sealed_at'] !== null) throw new DomainException('This dataset version is sealed. Create a new version to change its documents or annotations.');
        return $dataset;
    }

    private function editableDocument(int $id): array
    {
        $document = $this->getDocument($id);
        if (!$document) throw new InvalidArgumentException('Document was not found.');
        $this->editableDataset((int)$document['dataset_id']);
        return $document;
    }

    public function createDataset(array $data, int $actorId): int
    {
        $version = self::text($data['version'] ?? '', 'Dataset version', 100);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $version)) throw new InvalidArgumentException('Version may contain letters, numbers, dots, underscores and hyphens.');
        $name = self::text($data['name'] ?? $version, 'Dataset name', 255);
        $notes = self::text($data['notes'] ?? '', 'Notes', 10000, false);
        $target = filter_var($data['target_count'] ?? 48, FILTER_VALIDATE_INT);
        if ($target === false || $target < 1 || $target > 500) throw new InvalidArgumentException('Target count must be between 1 and 500.');
        return $this->transaction(function () use ($version, $name, $notes, $target, $actorId) {
            $this->execute('INSERT INTO evaluation_datasets (version,name,notes,target_count,created_by) VALUES (?,?,?,?,?)', [$version, $name, $notes, $target, $actorId]);
            $id = (int)$this->db->lastInsertId(); $this->audit($actorId, 'dataset_created', 'dataset', $id); return $id;
        });
    }

    public function addDocument(int $datasetId, array $data, int $actorId): int
    {
        $title = self::text($data['title'] ?? '', 'Title', 255);
        $source = self::text($data['source_text'] ?? '', 'Source text', 200000);
        $category = self::text($data['category'] ?? 'general', 'Category', 100);
        $profile = $data['profile'] ?? 'general'; $split = $data['dataset_split'] ?? 'development';
        if (!in_array($profile, self::PROFILES, true) || !in_array($split, ['development', 'validation', 'test'], true)) throw new InvalidArgumentException('Invalid document profile or dataset split.');
        $provenance = self::text($data['provenance'] ?? '', 'Source provenance', 5000);
        $notes = self::text($data['notes'] ?? '', 'Notes', 10000, false);
        $reference = self::text($data['source_reference'] ?? '', 'Source reference', 500, false);
        return $this->transaction(function () use ($datasetId, $title, $source, $category, $profile, $split, $provenance, $notes, $reference, $data, $actorId) {
            $this->editableDataset($datasetId);
            $count = $this->row('SELECT COUNT(*) AS n FROM evaluation_documents WHERE dataset_id = ?', [$datasetId]);
            if ((int)$count['n'] >= 500) throw new DomainException('A dataset may contain at most 500 documents.');
            $this->execute('INSERT INTO evaluation_documents (dataset_id,title,category,source_text,source_hash,source_reference,original_word_count,profile,dataset_split,provenance,notes,active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [$datasetId, $title, $category, $source, hash('sha256', $source), $reference, self::countWords($source), $profile, $split, $provenance, $notes, (int)($data['active'] ?? 1)]);
            $id = (int)$this->db->lastInsertId(); $this->audit($actorId, 'document_added', 'document', $id); return $id;
        });
    }

    public function addReference(int $documentId, array $data, int $actorId): int
    {
        $text = self::text($data['text'] ?? $data['reference_text'] ?? '', 'Reference text', 50000);
        $version = self::text($data['version'] ?? $data['reference_version'] ?? '', 'Reference version', 100);
        $author = self::text($data['author_identifier'] ?? $data['author_code'] ?? '', 'Author identifier', 100, false);
        $status = $data['review_status'] ?? 'draft';
        if (!in_array($status, ['draft', 'approved', 'rejected'], true)) throw new InvalidArgumentException('Invalid reference review status.');
        return $this->transaction(function () use ($documentId, $text, $version, $author, $status, $actorId) {
            $this->editableDocument($documentId);
            $this->execute('INSERT INTO evaluation_references (document_id,reference_text,reference_version,author_code,review_status,created_by) VALUES (?,?,?,?,?,?)', [$documentId, $text, $version, $author, $status, $actorId]);
            $id = (int)$this->db->lastInsertId(); $this->audit($actorId, 'reference_added', 'reference', $id); return $id;
        });
    }

    public function addContentUnit(int $documentId, array $data, int $actorId): int
    {
        $text = self::text($data['text'] ?? $data['unit_text'] ?? '', 'Content unit', 5000);
        $type = self::text($data['type'] ?? $data['unit_type'] ?? 'essential_information', 'Content unit type', 100);
        return $this->transaction(function () use ($documentId, $text, $type, $actorId) {
            $this->editableDocument($documentId);
            $this->execute('INSERT INTO evaluation_content_units (document_id,unit_text,unit_type,created_by) VALUES (?,?,?,?)', [$documentId, $text, $type, $actorId]);
            $id = (int)$this->db->lastInsertId(); $this->audit($actorId, 'content_unit_added', 'content_unit', $id); return $id;
        });
    }

    public function addChallengeCase(int $documentId, array $data, int $actorId): int
    {
        $category = self::text($data['category'] ?? '', 'Challenge category', 100);
        $facts = $data['expected_facts'] ?? []; $prohibited = $data['prohibited_distortions'] ?? [];
        if (!is_array($facts) || !$facts || !is_array($prohibited) || count($facts) > 100 || count($prohibited) > 100) throw new InvalidArgumentException('Provide 1–100 expected facts and at most 100 prohibited distortions.');
        foreach ($facts as $fact) self::text(is_array($fact) ? ($fact['text'] ?? '') : $fact, 'Expected fact', 2000);
        foreach ($prohibited as $distortion) self::text($distortion, 'Prohibited distortion', 2000);
        return $this->transaction(function () use ($documentId, $category, $facts, $prohibited, $actorId, $data) {
            $this->editableDocument($documentId);
            $this->execute('INSERT INTO evaluation_challenge_cases (document_id,category,expected_facts_json,prohibited_distortions_json,notes) VALUES (?,?,?,?,?)', [$documentId, $category, self::json($facts), self::json($prohibited), self::text($data['notes'] ?? '', 'Notes', 10000, false)]);
            $id = (int)$this->db->lastInsertId(); $this->audit($actorId, 'challenge_added', 'challenge', $id); return $id;
        });
    }

    public function listDatasets(): array { return $this->rows('SELECT d.*, (SELECT COUNT(*) FROM evaluation_documents x WHERE x.dataset_id=d.id) AS document_count FROM evaluation_datasets d ORDER BY d.id DESC'); }
    public function listDocuments(int $datasetId): array { return $this->rows('SELECT id,dataset_id,title,category,original_word_count,profile,dataset_split,provenance,notes,active,created_at FROM evaluation_documents WHERE dataset_id=? ORDER BY id', [$datasetId]); }
    public function getDocument(int $id): ?array
    {
        $doc = $this->row('SELECT d.*,s.sealed_at AS dataset_sealed_at FROM evaluation_documents d JOIN evaluation_datasets s ON s.id=d.dataset_id WHERE d.id=?', [$id]);
        if (!$doc) return null;
        $doc['references'] = $this->rows('SELECT * FROM evaluation_references WHERE document_id=? ORDER BY id', [$id]);
        $doc['content_units'] = $this->rows('SELECT * FROM evaluation_content_units WHERE document_id=? ORDER BY id', [$id]);
        $doc['challenge_cases'] = $this->rows('SELECT * FROM evaluation_challenge_cases WHERE document_id=? ORDER BY id', [$id]);
        return $doc;
    }
    public function setDatasetActive(int $id, bool $active, int $actorId): void
    {
        $this->transaction(function () use ($id, $active, $actorId) {
            $old = $this->row('SELECT active FROM evaluation_datasets WHERE id=? FOR UPDATE', [$id]);
            if (!$old) throw new InvalidArgumentException('Dataset was not found.');
            $this->execute('UPDATE evaluation_datasets SET active=? WHERE id=?', [(int)$active, $id]);
            $this->audit($actorId, 'dataset_activity_changed', 'dataset', $id, $old, ['active' => $active]);
        });
    }
    public function setDocumentActive(int $id, bool $active, int $actorId): void
    {
        $this->transaction(function () use ($id,$active,$actorId) {
            $old=$this->editableDocument($id);
            $this->execute('UPDATE evaluation_documents SET active=? WHERE id=?',[(int)$active,$id]);
            $this->audit($actorId,'document_activity_changed','document',$id,['active'=>$old['active']],['active'=>$active]);
        });
    }

    public function queueRun(int $datasetId, array $config, int $actorId): int
    {
        $modes = array_values(array_unique($config['modes'] ?? self::MODES));
        $systems = array_values(array_unique($config['systems'] ?? ['proposed']));
        $ablations = array_values(array_unique($config['ablations'] ?? []));
        if (!$modes || !$systems || array_diff($modes, self::MODES) || array_diff($systems, self::SYSTEMS) || array_diff($ablations, self::ABLATIONS)) throw new InvalidArgumentException('Select supported modes, systems and ablations.');
        if (in_array('hybrid_llm', $systems, true) && !config('summarizer.llm_available', false)) throw new InvalidArgumentException('Configure an allowed language-model endpoint and model before queuing the hybrid system.');
        if ($ablations && !in_array('proposed', $systems, true)) throw new InvalidArgumentException('Ablations require the full proposed system comparison.');
        $split = $config['dataset_split'] ?? 'development';
        if (!in_array($split, ['development', 'validation', 'test'], true)) throw new InvalidArgumentException('Select a single dataset split for this run.');
        $seed = filter_var($config['seed'] ?? 20261002, FILTER_VALIDATE_INT);
        if ($seed === false || $seed < 0 || $seed > 2147483647) throw new InvalidArgumentException('Seed must be an integer between 0 and 2147483647.');
        $normalized = ['modes' => $modes, 'systems' => $systems, 'ablations' => $ablations, 'dataset_split' => $split, 'seed' => $seed, 'bertscore' => (bool)($config['bertscore'] ?? false), 'code_fingerprint' => self::codeFingerprint(), 'evaluation_version' => self::VERSION];
        return $this->transaction(function () use ($datasetId, $config, $normalized, $actorId) {
            // Serializes the small enqueue window across datasets, independently of long worker locks.
            $this->rows('SELECT id FROM evaluation_datasets ORDER BY id FOR UPDATE');
            $dataset = $this->row('SELECT * FROM evaluation_datasets WHERE id=?', [$datasetId]);
            if (!$dataset || !$dataset['active']) throw new InvalidArgumentException('Select an active dataset.');
            $pending = $this->row("SELECT COUNT(*) AS n FROM evaluation_runs WHERE status IN ('queued','running')");
            if ((int)$pending['n'] >= 3) throw new DomainException('Three runs are already queued or running. Wait for a worker to finish.');
            $documents = $this->rows('SELECT id FROM evaluation_documents WHERE dataset_id=? AND dataset_split=? AND active=1 ORDER BY id', [$datasetId, $normalized['dataset_split']]);
            if (!$documents) throw new InvalidArgumentException('This dataset has no active documents in the selected split.');
            $snapshot = [];
            foreach ($documents as $doc) {
                $source=$this->getDocument((int)$doc['id']);
                unset($source['dataset_sealed_at']); // Dataset lifecycle metadata is not source content.
                $snapshot[]=$source;
            }
            $snapshotJson = self::json($snapshot);
            if (strlen($snapshotJson) > 20000000) throw new InvalidArgumentException('Selected dataset snapshot exceeds the 20 MB run limit. Use a smaller version.');
            $total = count($snapshot) * count($normalized['modes']) * (count($normalized['systems']) + count($normalized['ablations']));
            if ($total > 10000) throw new InvalidArgumentException('A run may contain at most 10,000 outputs.');
            $this->execute('UPDATE evaluation_datasets SET sealed_at=COALESCE(sealed_at,UTC_TIMESTAMP()) WHERE id=?', [$datasetId]);
            $this->execute('INSERT INTO evaluation_runs (run_uuid,name,dataset_id,dataset_version,dataset_split,configuration_hash,configuration_json,dataset_snapshot_json,snapshot_hash,documents_total,outputs_total,created_by,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [bin2hex(random_bytes(16)), self::text($config['name'] ?? ($dataset['version'] . ' evaluation'), 'Run name', 255), $datasetId, $dataset['version'], $normalized['dataset_split'], self::canonicalHash($normalized), self::json($normalized), self::storageJson($snapshot), hash('sha256', $snapshotJson), count($snapshot), $total, $actorId, self::text($config['notes'] ?? '', 'Notes', 10000, false)]);
            $id = (int)$this->db->lastInsertId();
            foreach ($snapshot as $doc) foreach ($normalized['modes'] as $mode) {
                foreach ($normalized['systems'] as $system) $this->insertOutput($id, $doc, $mode, $system, '');
                foreach ($normalized['ablations'] as $ablation) $this->insertOutput($id, $doc, $mode, 'proposed', $ablation);
            }
            $this->audit($actorId, 'run_queued_dataset_sealed', 'run', $id, null, $normalized);
            return $id;
        });
    }

    private function insertOutput(int $id, array $doc, string $mode, string $system, string $ablation): void
    {
        $this->execute('INSERT INTO evaluation_outputs (run_id,document_id,profile,mode,system_name,ablation) VALUES (?,?,?,?,?,?)', [$id, $doc['id'], $doc['profile'], $mode, $system, $ablation]);
    }

    public function listRuns(): array { return $this->rows('SELECT id,run_uuid,name,dataset_version,dataset_split,status,documents_total,documents_processed,outputs_total,generated_summaries,failed_summaries,report_pending,error_message,created_at,started_at,completed_at FROM evaluation_runs ORDER BY id DESC LIMIT 200'); }
    public function getRun(int $id): ?array
    {
        $run = $this->row('SELECT * FROM evaluation_runs WHERE id=?', [$id]);
        if (!$run) return null;
        $run['configuration'] = self::decode($run['configuration_json']);
        $run['snapshot'] = self::decode($run['dataset_snapshot_json']);
        $run['provenance'] = self::decode($run['provenance_json']);
        return $run;
    }
    public function getOutputs(int $runId): array
    {
        return $this->rows('SELECT o.*,d.title,d.category,d.dataset_split FROM evaluation_outputs o JOIN evaluation_documents d ON d.id=o.document_id WHERE o.run_id=? ORDER BY o.document_id,o.mode,o.id', [$runId]);
    }
    public function getOutputDetail(int $id): ?array
    {
        $out = $this->row('SELECT * FROM evaluation_outputs WHERE id=?', [$id]);
        if (!$out) return null;
        $run = $this->getRun((int)$out['run_id']);
        $doc = $this->snapshotDocument($run, (int)$out['document_id']);
        $out['document'] = $doc; $out['source_text'] = $doc['source_text']; $out['title'] = $doc['title'];
        $out['references'] = $doc['references']; $out['content_units'] = $doc['content_units'];
        $out['metrics'] = $this->getMetrics($id);
        $out['ratings'] = $this->rows('SELECT r.*,a.evaluator_code FROM evaluation_ratings r JOIN evaluation_assignments a ON a.id=r.assignment_id WHERE a.output_id=? ORDER BY a.evaluator_code', [$id]);
        $out['errors'] = $this->rows('SELECT id,category,notes,created_at FROM evaluation_errors WHERE output_id=? ORDER BY id', [$id]);
        $out['content_unit_reviews'] = $this->rows('SELECT unit_id,represented,notes,created_at FROM evaluation_content_unit_reviews WHERE output_id=?', [$id]);
        $out['warnings'] = self::decode($out['warnings_json']); $out['timings'] = self::decode($out['timings_json']);
        return $out;
    }
    public function getMetrics(int $outputId): array
    {
        $metrics = $this->rows('SELECT metric_name AS name,metric_version AS version,status,metric_value AS value,precision_value AS `precision`,recall_value AS recall,f1_value AS f1,details_json,error_message AS error FROM evaluation_metrics WHERE output_id=? ORDER BY id', [$outputId]);
        foreach ($metrics as &$metric) { $metric['details'] = self::decode($metric['details_json']); unset($metric['details_json']); }
        return $metrics;
    }
    private function snapshotDocument(?array $run, int $documentId): array
    {
        foreach (($run['snapshot'] ?? []) as $doc) if ((int)$doc['id'] === $documentId) return $doc;
        throw new RuntimeException('Immutable source snapshot is missing.');
    }

    public function cancelRun(int $id, int $actorId): void
    {
        $this->transaction(function () use ($id, $actorId) {
            $run = $this->row('SELECT status FROM evaluation_runs WHERE id=? FOR UPDATE', [$id]);
            if (!$run || !in_array($run['status'], ['queued','running'], true)) throw new InvalidArgumentException('Only queued or running evaluations can be cancelled.');
            $this->execute("UPDATE evaluation_runs SET status='cancelled',completed_at=UTC_TIMESTAMP(),report_pending=1 WHERE id=?", [$id]);
            $this->audit($actorId, 'run_cancelled', 'run', $id, $run);
        });
    }
    public function resumeRun(int $id, int $actorId): void
    {
        $this->transaction(function () use ($id, $actorId) {
            $run = $this->row('SELECT status,heartbeat_at FROM evaluation_runs WHERE id=? FOR UPDATE', [$id]);
            if (!$run || !in_array($run['status'], ['cancelled','failed','running'], true)) throw new InvalidArgumentException('This run has no interrupted work to resume.');
            if ($run['status'] === 'running' && $run['heartbeat_at'] !== null && strtotime($run['heartbeat_at'] . ' UTC') > time() - 3600) throw new DomainException('The worker may still be active. Wait until its heartbeat is stale.');
            $pending = $this->row("SELECT COUNT(*) AS n FROM evaluation_outputs WHERE run_id=? AND status='queued'", [$id]);
            if (!(int)$pending['n']) throw new DomainException('No pending outputs remain. Create a new run to retry failed outputs.');
            $this->execute("UPDATE evaluation_runs SET status='queued',completed_at=NULL,error_message=NULL WHERE id=?", [$id]);
            $this->audit($actorId, 'run_resumed_pending_only', 'run', $id, $run);
        });
    }

    public static function randomizedOrder(array $ids, string $seed): array
    {
        usort($ids, static fn($a,$b) => strcmp(hash_hmac('sha256', (string)$a, $seed), hash_hmac('sha256', (string)$b, $seed)) ?: ($a <=> $b));
        return $ids;
    }
    public function listEvaluatorCandidates(): array
    {
        return $this->rows("SELECT id AS user_id,CONCAT('Account ',id) AS pseudonym FROM users WHERE active=1 ORDER BY id LIMIT 1000");
    }
    public function createAssignments(int $runId, array $userIds, int $actorId, array $documentIds = []): int
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (!$userIds || count($userIds) > 30 || min($userIds) < 1) throw new InvalidArgumentException('Choose 1–30 active evaluators.');
        return $this->transaction(function () use ($runId,$userIds,$actorId,$documentIds) {
            $run = $this->row('SELECT status FROM evaluation_runs WHERE id=? FOR UPDATE', [$runId]);
            if (!$run || !in_array($run['status'], ['completed','completed_with_errors'], true)) throw new InvalidArgumentException('Assign outputs after the run completes.');
            $outputs = $this->rows("SELECT id,document_id FROM evaluation_outputs WHERE run_id=? AND status IN ('completed','completed_with_errors') AND summary_text IS NOT NULL AND summary_text<>'' ORDER BY id", [$runId]);
            if ($documentIds) $outputs = array_values(array_filter($outputs, static fn($o) => in_array((int)$o['document_id'], array_map('intval', $documentIds), true)));
            if (!$outputs) throw new InvalidArgumentException('No valid outputs match the selection.');
            $inserted = 0;
            foreach ($userIds as $userId) {
                if (!$this->row('SELECT id FROM users WHERE id=? AND active=1', [$userId])) throw new InvalidArgumentException('An evaluator account is unavailable.');
                $existing = $this->row('SELECT evaluator_code,randomization_seed FROM evaluation_assignments WHERE run_id=? AND evaluator_user_id=? LIMIT 1', [$runId,$userId]);
                $seed = $existing['randomization_seed'] ?? bin2hex(random_bytes(32));
                $n = $this->row('SELECT COUNT(DISTINCT evaluator_user_id) AS n FROM evaluation_assignments WHERE run_id=?', [$runId]);
                $code = $existing['evaluator_code'] ?? ('E' . str_pad((string)((int)$n['n'] + 1), 2, '0', STR_PAD_LEFT));
                $ordered = self::randomizedOrder(array_column($outputs, 'id'), $seed);
                foreach ($ordered as $position => $outputId) {
                    if ($this->row('SELECT id FROM evaluation_assignments WHERE output_id=? AND evaluator_user_id=?', [$outputId,$userId])) continue;
                    $this->execute('INSERT INTO evaluation_assignments (assignment_token,run_id,output_id,evaluator_user_id,evaluator_code,blind_label,presentation_order,randomization_seed) VALUES (?,?,?,?,?,?,?,?)', [bin2hex(random_bytes(24)),$runId,$outputId,$userId,$code,'Summary ' . ($position + 1),$position + 1,$seed]);
                    $inserted++;
                }
            }
            $this->execute('UPDATE evaluation_runs SET report_pending=1 WHERE id=?', [$runId]);
            $this->audit($actorId, 'assignments_created', 'run', $runId, null, ['count'=>$inserted,'evaluators'=>count($userIds)]);
            return $inserted;
        });
    }
    public function listAssignments(int $userId): array
    {
        return $this->rows('SELECT a.assignment_token,a.blind_label,a.presentation_order,a.status,d.title FROM evaluation_assignments a JOIN evaluation_outputs o ON o.id=a.output_id JOIN evaluation_documents d ON d.id=o.document_id JOIN users u ON u.id=a.evaluator_user_id AND u.active=1 WHERE a.evaluator_user_id=? ORDER BY a.run_id,a.presentation_order', [$userId]);
    }
    public function getBlindAssignment(string $token, int $userId): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/D', $token) || $userId < 1) return null;
        $assignment = $this->row('SELECT a.*,o.summary_text,o.document_id FROM evaluation_assignments a JOIN evaluation_outputs o ON o.id=a.output_id JOIN users u ON u.id=a.evaluator_user_id AND u.active=1 WHERE a.assignment_token=? AND a.evaluator_user_id=?', [$token,$userId]);
        if (!$assignment) return null;
        $doc = $this->snapshotDocument($this->getRun((int)$assignment['run_id']), (int)$assignment['document_id']);
        // Deliberate allowlist: no internal output/run IDs, metric scores, system names, profiles or references.
        return ['assignment_token'=>$assignment['assignment_token'],'blind_label'=>$assignment['blind_label'],'presentation_order'=>(int)$assignment['presentation_order'],'status'=>$assignment['status'],'title'=>$doc['title'],'source_text'=>$doc['source_text'],'summary_text'=>$assignment['summary_text'],'content_units'=>array_map(static fn($u)=>['id'=>$u['id'],'text'=>$u['unit_text']],$doc['content_units'])];
    }
    public static function validateRatings(array $ratings): array
    {
        $validated = [];
        foreach (array_merge(self::CRITERIA,['overall_usefulness']) as $criterion) {
            $value = $ratings[$criterion] ?? null;
            if ($criterion === 'overall_usefulness' && ($value === null || $value === '')) { $validated[$criterion]=null; continue; }
            if (!(is_int($value) || (is_string($value) && preg_match('/^[1-5]$/D',$value))) || (int)$value < 1 || (int)$value > 5) throw new InvalidArgumentException('Each required criterion needs an integer rating from 1 to 5.');
            $validated[$criterion]=(int)$value;
        }
        return $validated;
    }
    public function submitRating(string $token, int $userId, array $ratings, string $comment = ''): int
    {
        $validated = self::validateRatings($ratings); $comment = self::text($comment,'Comments',5000,false);
        if (!preg_match('/^[a-f0-9]{48}$/D',$token)) throw new InvalidArgumentException('Assignment was not found.');
        return $this->transaction(function () use ($token,$userId,$validated,$comment) {
            $a = $this->row('SELECT a.* FROM evaluation_assignments a JOIN users u ON u.id=a.evaluator_user_id AND u.active=1 WHERE assignment_token=? AND evaluator_user_id=? FOR UPDATE',[$token,$userId]);
            if (!$a) throw new DomainException('Assignment was not found.',403);
            if ($a['status'] !== 'assigned') throw new DomainException('This assessment has already been submitted and is locked.',409);
            $this->execute('INSERT INTO evaluation_ratings (assignment_id,relevance,factual_consistency,coverage,coherence,readability,conciseness,non_redundancy,overall_usefulness,comments) VALUES (?,?,?,?,?,?,?,?,?,?)', array_merge([(int)$a['id']],array_values($validated),[$comment]));
            $id=(int)$this->db->lastInsertId();
            $this->execute("UPDATE evaluation_assignments SET status='submitted',submitted_at=UTC_TIMESTAMP() WHERE id=?",[$a['id']]);
            $this->execute('UPDATE evaluation_runs SET report_pending=1 WHERE id=?',[$a['run_id']]);
            $this->audit($userId,'rating_submitted_locked','rating',$id);
            return $id;
        });
    }
    public function correctRating(int $ratingId, array $ratings, string $reason, int $actorId): void
    {
        $validated=self::validateRatings($ratings); $reason=self::text($reason,'Correction reason',5000);
        $this->transaction(function () use ($ratingId,$validated,$reason,$actorId) {
            $old=$this->row('SELECT r.*,a.run_id FROM evaluation_ratings r JOIN evaluation_assignments a ON a.id=r.assignment_id WHERE r.id=? FOR UPDATE',[$ratingId]);
            if (!$old) throw new InvalidArgumentException('Submitted rating was not found.');
            $this->audit($actorId,'rating_admin_correction','rating',$ratingId,$old,$validated,$reason);
            $this->execute('UPDATE evaluation_ratings SET relevance=?,factual_consistency=?,coverage=?,coherence=?,readability=?,conciseness=?,non_redundancy=?,overall_usefulness=?,corrected_at=UTC_TIMESTAMP() WHERE id=?',array_merge(array_values($validated),[$ratingId]));
            $this->execute('UPDATE evaluation_runs SET report_pending=1 WHERE id=?',[$old['run_id']]);
        });
    }
    public function getAssignmentsForRun(int $runId): array
    {
        return $this->rows('SELECT a.id,a.evaluator_code,a.blind_label,a.status,a.output_id,r.id AS rating_id,r.relevance,r.factual_consistency,r.coverage,r.coherence,r.readability,r.conciseness,r.non_redundancy,r.overall_usefulness,r.comments,r.corrected_at FROM evaluation_assignments a LEFT JOIN evaluation_ratings r ON r.assignment_id=a.id WHERE a.run_id=? ORDER BY a.evaluator_code,a.presentation_order',[$runId]);
    }
    public function classifyError(int $outputId, string $category, string $notes, int $actorId): int
    {
        if (!in_array($category,self::ERROR_CATEGORIES,true)) throw new InvalidArgumentException('Unknown error category.');
        $notes=self::text($notes,'Error notes',10000,false);
        return $this->transaction(function () use ($outputId,$category,$notes,$actorId) {
            $out=$this->row('SELECT run_id FROM evaluation_outputs WHERE id=?',[$outputId]);
            if (!$out) throw new InvalidArgumentException('Output was not found.');
            $this->execute('INSERT INTO evaluation_errors (output_id,category,notes,reviewer_id) VALUES (?,?,?,?)',[$outputId,$category,$notes,$actorId]);
            $id=(int)$this->db->lastInsertId(); $this->audit($actorId,'error_classified','error',$id); return $id;
        });
    }
    public function verifyContentUnit(int $outputId, int $unitId, bool $represented, string $notes, int $actorId): void
    {
        $notes=self::text($notes,'Verification notes',5000,false);
        $this->transaction(function () use ($outputId,$unitId,$represented,$notes,$actorId) {
            $out=$this->getOutputDetail($outputId);
            if (!$out || !in_array($unitId,array_map('intval',array_column($out['content_units'],'id')),true)) throw new InvalidArgumentException('Content unit does not belong to this output snapshot.');
            $old=$this->row('SELECT represented,notes FROM evaluation_content_unit_reviews WHERE output_id=? AND unit_id=? FOR UPDATE',[$outputId,$unitId]);
            $this->execute('INSERT INTO evaluation_content_unit_reviews (output_id,unit_id,represented,notes,reviewer_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE represented=VALUES(represented),notes=VALUES(notes),reviewer_id=VALUES(reviewer_id),created_at=UTC_TIMESTAMP()',[$outputId,$unitId,(int)$represented,$notes,$actorId]);
            $this->audit($actorId,'content_unit_verified','output',$outputId,$old,['unit_id'=>$unitId,'represented'=>$represented,'notes'=>$notes]);
            $this->execute('UPDATE evaluation_runs SET report_pending=1 WHERE id=?',[$out['run_id']]);
        });
    }

    public function getRunReport(int $runId): array
    {
        $run=$this->row('SELECT report_json,report_pending,report_error,outputs_total,generated_summaries,failed_summaries FROM evaluation_runs WHERE id=?',[$runId]);
        if (!$run) throw new InvalidArgumentException('Run was not found.');
        return ['pending'=>(bool)$run['report_pending'],'error'=>$run['report_error'],'outputs_total'=>(int)$run['outputs_total'],'generated_summaries'=>(int)$run['generated_summaries'],'failed_summaries'=>(int)$run['failed_summaries'],'statistics'=>self::decode($run['report_json'])];
    }

    public function exportRun(int $runId): array
    {
        $run=$this->getRun($runId);
        if (!$run) throw new InvalidArgumentException('Run was not found.');
        $safeRun=array_intersect_key($run,array_flip(['id','run_uuid','name','dataset_version','dataset_split','summarizer_version','evaluation_version','configuration_hash','snapshot_hash','status','documents_total','documents_processed','outputs_total','generated_summaries','failed_summaries','created_at','started_at','completed_at','configuration','provenance']));
        $documents=[];
        foreach ($run['snapshot'] as $doc) {
            $safe=array_intersect_key($doc,array_flip(['id','title','category','source_hash','original_word_count','profile','dataset_split','provenance']));
            $safe['references']=array_map(static fn($r)=>['id'=>$r['id'],'text'=>$r['reference_text'],'version'=>$r['reference_version'],'review_status'=>$r['review_status']],$doc['references']);
            $safe['content_units']=array_map(static fn($u)=>['id'=>$u['id'],'text'=>$u['unit_text'],'type'=>$u['unit_type']],$doc['content_units']);
            $documents[]=$safe;
        }
        $outputs=[];
        foreach ($this->getOutputs($runId) as $out) {
            $safe=array_intersect_key($out,array_flip(['id','document_id','profile','mode','system_name','ablation','status','summary_text','word_count','source_word_count','target_words','processing_seconds','configuration_hash','generated_at','error_message']));
            foreach (['timings','configuration','provenance','warnings'] as $field) $safe[$field]=self::decode($out[$field.'_json']);
            $safe['metrics']=$this->getMetrics((int)$out['id']); $outputs[]=$safe;
        }
        $ratings=$this->getAssignmentsForRun($runId);
        foreach ($ratings as &$rating) { unset($rating['id'],$rating['comments'],$rating['rating_id'],$rating['blind_label']); }
        return ['export_version'=>self::VERSION,'run'=>$safeRun,'documents'=>$documents,'outputs'=>$outputs,'ratings'=>$ratings,'errors'=>$this->rows('SELECT e.output_id,e.category,e.notes,e.created_at FROM evaluation_errors e JOIN evaluation_outputs o ON o.id=e.output_id WHERE o.run_id=?',[$runId]),'report'=>$this->getRunReport($runId),'privacy_note'=>'Evaluator identities, reference authors, free-text rating comments, private source references and audit actor IDs are excluded. Researcher-authored error notes must be reviewed before public publication.'];
    }

    /** Offline worker methods. No HTTP handler calls these methods. */
    public function nextRun(?int $runId = null): ?array
    {
        if ($runId !== null) return $this->getRun($runId);
        $row=$this->row("SELECT id FROM evaluation_runs WHERE status='queued' OR (report_pending=1 AND status IN ('completed','completed_with_errors','cancelled','failed')) ORDER BY CASE WHEN status='queued' THEN 0 ELSE 1 END,id LIMIT 1");
        return $row ? $this->getRun((int)$row['id']) : null;
    }
    public function startRun(int $runId): void
    {
        $this->execute("UPDATE evaluation_runs SET status='running',started_at=COALESCE(started_at,UTC_TIMESTAMP()),heartbeat_at=UTC_TIMESTAMP() WHERE id=? AND status='queued'",[$runId]);
    }
    public function pendingOutputs(int $runId): array { return $this->rows("SELECT * FROM evaluation_outputs WHERE run_id=? AND status='queued' ORDER BY document_id,mode,CASE WHEN system_name='proposed' AND ablation='' THEN 0 ELSE 1 END,id",[$runId]); }
    public function isCancelled(int $runId): bool { return ($this->row('SELECT status FROM evaluation_runs WHERE id=?',[$runId])['status'] ?? '') === 'cancelled'; }
    public function workerPayload(array $run,array $output): array
    {
        $doc=$this->snapshotDocument($run,(int)$output['document_id']);
        $references=[];
        foreach ($doc['references'] as $r) if ($r['review_status']==='approved') $references[]=['id'=>$r['id'],'text'=>$r['reference_text'],'version'=>$r['reference_version']];
        $challenges=[];
        foreach ($doc['challenge_cases'] as $c) $challenges[]=['id'=>$c['id'],'category'=>$c['category'],'expected_facts'=>self::decode($c['expected_facts_json']),'prohibited_distortions'=>self::decode($c['prohibited_distortions_json'])];
        $payload=['text'=>$doc['source_text'],'title'=>$doc['title'],'profile'=>$output['profile'],'mode'=>$output['mode'],'system'=>$output['system_name'],'ablation'=>$output['ablation'] ?: null,'references'=>$references,'content_units'=>array_map(static fn($u)=>['id'=>$u['id'],'text'=>$u['unit_text']],$doc['content_units']),'challenge_cases'=>$challenges,'options'=>['bertscore'=>$run['configuration']['bertscore'],'seed'=>$run['configuration']['seed']]];
        if ($output['system_name']!=='proposed' || $output['ablation']!=='') {
            $budget=$this->row("SELECT word_count FROM evaluation_outputs WHERE run_id=? AND document_id=? AND mode=? AND system_name='proposed' AND ablation='' AND status IN ('completed','completed_with_errors')",[$run['id'],$doc['id'],$output['mode']]);
            if ($budget && (int)$budget['word_count']>0) {
                $payload['target_words']=(int)$budget['word_count'];
                $payload['target_budget_origin']='proposed_output_actual_word_count';
            }
        }
        return $payload;
    }
    public function persistOutput(int $outputId,array $result): void
    {
        $this->transaction(function () use ($outputId,$result) {
            $out=$this->row('SELECT * FROM evaluation_outputs WHERE id=? FOR UPDATE',[$outputId]);
            if (!$out || $out['status']!=='queued') throw new RuntimeException('Output is already recorded; create a new run to regenerate it.');
            $status=$result['status'] ?? 'failed';
            if (!in_array($status,['completed','completed_with_errors','failed'],true)) throw new RuntimeException('Worker returned an invalid output status.');
            $summary=isset($result['summary']) && is_string($result['summary']) ? trim($result['summary']) : '';
            if ($status!=='failed' && $summary==='') throw new RuntimeException('Worker returned an empty generated summary.');
            $wordCount=$summary!=='' ? self::countWords($summary) : null;
            if (isset($result['word_count']) && (!is_int($result['word_count']) || $result['word_count']!==$wordCount)) throw new RuntimeException('Worker word count does not match unicode-words-v1 tokenization.');
            $metrics=$result['metrics'] ?? [];
            if (!is_array($metrics)) throw new RuntimeException('Worker metric payload is invalid.');
            $seen=[];
            foreach ($metrics as $metric) {
                $name=self::text($metric['name'] ?? '', 'Metric name',100);
                if (isset($seen[$name])) throw new RuntimeException('Worker returned a duplicate metric.');
                $seen[$name]=true;
                $metricStatus=$metric['status'] ?? 'error';
                if (!in_array($metricStatus,['ok','unavailable','not_applicable','error'],true)) throw new RuntimeException('Worker returned an invalid metric status.');
                $value=self::finiteMetric($metric['value'] ?? null);
                if ($metricStatus==='ok' && $value===null) throw new RuntimeException('Available metric has no finite value.');
                if ($metricStatus!=='ok') $value=null;
                $this->execute('INSERT INTO evaluation_metrics (output_id,metric_name,metric_version,status,metric_value,precision_value,recall_value,f1_value,details_json,error_message) VALUES (?,?,?,?,?,?,?,?,?,?)',[$outputId,$name,(string)($metric['version'] ?? 'unspecified'),$metricStatus,$value,self::finiteMetric($metric['precision'] ?? null),self::finiteMetric($metric['recall'] ?? null),self::finiteMetric($metric['f1'] ?? null),self::json($metric['details'] ?? []),isset($metric['error']) ? substr((string)$metric['error'],0,5000) : null]);
            }
            foreach (array_diff(self::EXPECTED_METRICS,array_keys($seen)) as $name) $this->execute('INSERT INTO evaluation_metrics (output_id,metric_name,metric_version,status,error_message) VALUES (?,?,?,?,?)',[$outputId,$name,self::VERSION,'unavailable','Metric was not returned by the worker.']);
            $this->execute('UPDATE evaluation_outputs SET status=?,summary_text=?,word_count=?,source_word_count=?,target_words=?,processing_seconds=?,timings_json=?,configuration_hash=?,configuration_json=?,provenance_json=?,warnings_json=?,error_message=?,generated_at=UTC_TIMESTAMP() WHERE id=?',[$status,$summary ?: null,$wordCount,isset($result['source_word_count']) ? max(0,(int)$result['source_word_count']) : null,isset($result['target_words']) ? max(0,(int)$result['target_words']) : null,self::finiteMetric($result['timings']['total_seconds'] ?? null),self::json($result['timings'] ?? []),$result['configuration_hash'] ?? null,self::json($result['configuration'] ?? []),self::json($result['provenance'] ?? []),self::json($result['warnings'] ?? []),isset($result['error']) ? substr((string)$result['error'],0,5000) : null,$outputId]);
            $this->execute('UPDATE evaluation_runs SET heartbeat_at=UTC_TIMESTAMP(),summarizer_version=?,provenance_json=COALESCE(provenance_json,?),report_pending=1 WHERE id=?',[(string)($result['summarizer_version'] ?? 'unavailable'),self::json($result['provenance'] ?? []),$out['run_id']]);
            $this->updateProgress((int)$out['run_id']);
        });
    }
    private static function finiteMetric(mixed $value): ?float
    {
        if ($value===null) return null;
        if ((!is_float($value) && !is_int($value) && !is_numeric($value)) || !is_finite((float)$value)) throw new RuntimeException('Metric values must be finite numbers or null.');
        return (float)$value;
    }
    public function failOutput(int $outputId,string $error): void
    {
        $this->persistOutput($outputId,['status'=>'failed','error'=>substr($error,0,5000),'metrics'=>[]]);
    }
    private function updateProgress(int $runId): void
    {
        $counts=$this->row("SELECT SUM(status IN ('completed','completed_with_errors')) AS good,SUM(status='failed') AS bad FROM evaluation_outputs WHERE run_id=?",[$runId]);
        $docs=$this->row("SELECT COUNT(*) AS n FROM (SELECT document_id FROM evaluation_outputs WHERE run_id=? GROUP BY document_id HAVING SUM(status='queued')=0) done",[$runId]);
        $this->execute('UPDATE evaluation_runs SET generated_summaries=?,failed_summaries=?,documents_processed=? WHERE id=?',[(int)$counts['good'],(int)$counts['bad'],(int)$docs['n'],$runId]);
    }
    public function finishRun(int $runId,?string $error=null): void
    {
        $this->updateProgress($runId);
        if ($this->isCancelled($runId)) return;
        $counts=$this->row("SELECT SUM(status='queued') AS pending,SUM(status IN ('failed','completed_with_errors')) AS errors FROM evaluation_outputs WHERE run_id=?",[$runId]);
        $status=$error!==null ? 'failed' : ((int)$counts['pending']>0 ? 'failed' : ((int)$counts['errors']>0 ? 'completed_with_errors' : 'completed'));
        $this->execute('UPDATE evaluation_runs SET status=?,error_message=?,completed_at=UTC_TIMESTAMP(),heartbeat_at=UTC_TIMESTAMP(),report_pending=1 WHERE id=?',[$status,$error,$runId]);
    }
    public function statisticsPayload(int $runId): array
    {
        $run=$this->getRun($runId);
        if (!$run) throw new InvalidArgumentException('Run was not found.');
        $metricRows=[]; $ratingRows=[];
        $observedVersions=[];
        foreach ($this->rows("SELECT DISTINCT m.metric_name,m.metric_version FROM evaluation_metrics m JOIN evaluation_outputs o ON o.id=m.output_id WHERE o.run_id=? AND (m.error_message IS NULL OR m.error_message<>'Metric was not returned by the worker.')",[$runId]) as $observed) $observedVersions[$observed['metric_name']][]=$observed['metric_version'];
        $missingVersion=static function (string $name) use ($observedVersions): string {
            $versions=$observedVersions[$name] ?? [];
            return count($versions)===1 ? $versions[0] : 'unknown-unavailable';
        };
        foreach ($this->getOutputs($runId) as $out) {
            $system=$out['system_name'] . ($out['ablation']!=='' ? '_without_'.$out['ablation'] : '');
            $base=['document_id'=>$out['document_id'],'output_id'=>$out['id'],'system'=>$system,'mode'=>$out['mode'],'dataset_split'=>$run['dataset_split']];
            $metrics=$this->getMetrics((int)$out['id']);
            $seen=[];
            foreach ($metrics as $m) {
                $version=($m['error']==='Metric was not returned by the worker.') ? $missingVersion($m['name']) : $m['version'];
                $metricRows[]=array_merge($base,['metric'=>$m['name'],'version'=>$version,'value'=>$m['value']===null ? null : (float)$m['value'],'status'=>$m['status']]);
                $seen[]=$m['name'];
            }
            foreach (array_diff(self::EXPECTED_METRICS,$seen) as $name) $metricRows[]=array_merge($base,['metric'=>$name,'version'=>$missingVersion($name),'value'=>null,'status'=>'unavailable']);
            foreach (['processing_seconds','word_count'] as $name) {
                $valid=$out[$name]!==null && in_array($out['status'],['completed','completed_with_errors'],true);
                $metricRows[]=array_merge($base,['metric'=>$name,'version'=>$name==='word_count' ? 'unicode-words-v1' : 'python-perf-counter-v1','value'=>$valid ? (float)$out[$name] : null,'status'=>$valid ? 'ok' : 'unavailable']);
            }
            $doc=$this->snapshotDocument($run,(int)$out['document_id']);
            $reviews=$this->rows('SELECT represented FROM evaluation_content_unit_reviews WHERE output_id=?',[$out['id']]);
            $expected=count($doc['content_units']);
            $complete=$expected>0 && count($reviews)===$expected;
            $metricRows[]=array_merge($base,['metric'=>'human_verified_content_coverage','value'=>$complete ? array_sum(array_column($reviews,'represented'))/$expected : null,'status'=>$complete ? 'ok' : ($expected ? 'unavailable':'not_applicable')]);
            $ratings=$this->rows('SELECT a.evaluator_code,r.* FROM evaluation_assignments a LEFT JOIN evaluation_ratings r ON r.assignment_id=a.id WHERE a.output_id=?',[$out['id']]);
            foreach ($ratings as $rating) foreach (array_merge(self::CRITERIA,['overall_usefulness']) as $criterion) $ratingRows[]=array_merge($base,['evaluator_id'=>$rating['evaluator_code'],'criterion'=>$criterion,'rating'=>$rating[$criterion]===null ? null : (int)$rating[$criterion]]);
        }
        return ['metric_rows'=>$metricRows,'rating_rows'=>$ratingRows,'seed'=>$run['configuration']['seed'],'bootstrap_samples'=>2000];
    }
    public function saveReport(int $runId,array $report,?string $inputHash=null): void
    {
        $this->transaction(function () use ($runId,$report,$inputHash) {
            $previous=$this->row('SELECT report_json FROM evaluation_runs WHERE id=? FOR UPDATE',[$runId]);
            if (!$previous) throw new InvalidArgumentException('Run was not found.');
            $changed=$inputHash!==null && !hash_equals($inputHash,self::canonicalHash($this->statisticsPayload($runId)));
            $storedReport=self::storageJson($report);
            // Preserve the prior complete version once. The new full version is on the run;
            // retaining its hash here avoids sending two large reports in one SQL packet.
            $replacement=['report_sha256'=>hash('sha256',self::json($report)),'report_version'=>$report['version'] ?? null,'input_hash'=>$inputHash];
            $this->execute('INSERT INTO evaluation_audit_log (actor_id,action,entity_type,entity_id,previous_json,replacement_json,reason) VALUES (NULL,?,?,?,?,?,?)',['offline_report_revision','run',$runId,$previous['report_json'],self::json($replacement),'Recomputed from persisted observations. Prior full report retained; replacement hash identifies the new report.']);
            $this->execute('UPDATE evaluation_runs SET report_json=?,report_pending=?,report_error=NULL WHERE id=?',[$storedReport,(int)$changed,$runId]);
        });
    }
    public function reportFailure(int $runId,string $error): void { $this->execute('UPDATE evaluation_runs SET report_error=?,report_pending=0 WHERE id=?',[substr($error,0,5000),$runId]); }
}
