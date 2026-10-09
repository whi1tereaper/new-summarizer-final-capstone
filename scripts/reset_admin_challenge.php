<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be executed from CLI.');
}

// Kept as a non-mutating compatibility entry point for old operational commands.
echo "Administrator verification has been removed. Sign in with your normal administrator credentials.\n";
