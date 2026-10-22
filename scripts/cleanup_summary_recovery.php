<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../app/src/whitereaper.php';
require_once __DIR__ . '/../app/src/Services/SummaryJobService.php';
(new \App\Src\Services\SummaryJobService())->purgeExpiredPayloads();
echo "Expired summary recovery payloads and retained upload files cleaned.\n";
