<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/src/Support/config.php';
echo json_encode(['automatic_article_routing' => config('summarizer.automatic_article_routing', false),
    'summary_engine_version' => config('summarizer.engine_version', 'legacy')], JSON_THROW_ON_ERROR), PHP_EOL;
