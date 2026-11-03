<?php
// Include before page styles so every route paints with the saved/system theme.
$designSystemAssets = __DIR__ . '/../assets/';
$favIconSvg = file_exists($designSystemAssets . 'images/depended-mark.svg')
    ? 'assets/images/depended-mark.svg?v=' . filemtime($designSystemAssets . 'images/depended-mark.svg')
    : (file_exists($designSystemAssets . 'images/dee-mark.svg')
        ? 'assets/images/dee-mark.svg?v=' . filemtime($designSystemAssets . 'images/dee-mark.svg')
        : 'assets/images/light-mark.svg?v=' . filemtime($designSystemAssets . 'images/light-mark.svg'));
?>
<script src="assets/js/theme-toggle.js?v=<?= filemtime($designSystemAssets . 'js/theme-toggle.js') ?>"></script>
<link rel="icon" type="image/svg+xml" href="<?= $favIconSvg ?>">
<link rel="manifest" href="site.webmanifest">
<link rel="stylesheet" href="assets/css/design-tokens.css?v=<?= filemtime($designSystemAssets . 'css/design-tokens.css') ?>">
<link rel="stylesheet" href="assets/css/components.css?v=<?= filemtime($designSystemAssets . 'css/components.css') ?>">
<link rel="stylesheet" href="assets/css/theme-toggle.css?v=<?= filemtime($designSystemAssets . 'css/theme-toggle.css') ?>">
<link rel="stylesheet" href="assets/css/site-footer.css?v=<?= filemtime($designSystemAssets . 'css/site-footer.css') ?>">
<script src="assets/js/components.js?v=<?= filemtime($designSystemAssets . 'js/components.js') ?>" defer></script>
