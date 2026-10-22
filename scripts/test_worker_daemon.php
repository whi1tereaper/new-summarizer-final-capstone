<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/src/whitereaper.php';
require_once __DIR__ . '/../app/src/Services/LocalPythonBridge.php';

use App\Src\Services\LocalPythonBridge;

$bridge = new LocalPythonBridge();
$text = "Active Commuting and Self-Reported Wellbeing Among Office Workers\n\nThis study examined whether commuting by bicycle or on foot is associated with higher self-reported wellbeing among office workers. A cross-sectional survey was distributed to employees of 14 firms in two mid-sized cities between March and June 2025. A total of 1,186 valid responses were analyzed. Linear regression models adjusted for age, sex, income, and leisure-time exercise. Active commuters reported a mean WHO-5 score of 64.2, compared with 57.9 for car commuters. After adjustment, active commuting remained associated with a 4.1-point higher wellbeing score. Active commuting was associated with modestly higher wellbeing among office workers.";

echo "Calling LocalPythonBridge::summarize()...\n";
$start = microtime(true);
$result = $bridge->summarize([
    'text' => $text,
    'analysis_mode' => 'academic',
    'summary_length' => 'balanced',
    'output_format' => 'paragraph',
]);
$elapsed = round(microtime(true) - $start, 3);

echo "Success! Elapsed time: {$elapsed}s\n";
echo "Sentences: " . count($result['sentences'] ?? []) . "\n";
echo "Quality strategy: " . ($result['source_metadata']['quality_selection']['selected_strategy'] ?? 'none') . "\n";
echo "Daemon elapsed: " . ($result['source_metadata']['daemon_elapsed_seconds'] ?? 'daemon_active') . "\n";
