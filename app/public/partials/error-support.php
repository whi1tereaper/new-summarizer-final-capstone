<?php
// Only the public message registry is sent to the browser; operational diagnostics stay in logs.
$lightErrorCatalog = json_decode((string)file_get_contents(__DIR__ . '/../../config/error_catalog.json'), true) ?: [];
?>
<link rel="stylesheet" href="assets/css/light-errors.css?v=<?= filemtime(__DIR__ . '/../assets/css/light-errors.css') ?>">
<script>window.lightErrorCatalog = <?= json_encode($lightErrorCatalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; window.lightDraftScope = <?= json_encode(substr(hash('sha256', 'light-draft|' . session_id() . '|' . (string)($_SESSION['user_id'] ?? 'guest')), 0, 24)) ?>;</script>
<script src="assets/js/light-errors.js?v=<?= filemtime(__DIR__ . '/../assets/js/light-errors.js') ?>"></script>
