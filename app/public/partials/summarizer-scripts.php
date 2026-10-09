<script>window.appViewer = <?= json_encode(['requiresRegistrationForNutshell' => !$canUseRegisteredFeatures, 'registerUrl' => 'register.php'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="assets/js/index.js?v=<?= filemtime(__DIR__ . '/../assets/js/index.js') ?>"></script>

