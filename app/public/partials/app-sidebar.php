<?php
// Identity is refreshed by whitereaper.php; do not query other accounts here.
$navUserId ??= $_SESSION['user_id'] ?? null;
$navUsername ??= $_SESSION['username'] ?? 'GUEST';
$navIsAdmin ??= !empty($navUserId) && (($_SESSION['role'] ?? 'user') === 'admin');
$navCurrentPage ??= basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$appNavigation ??= require __DIR__ . '/app-navigation.php';

$sidebarSignedIn = (int)$navUserId > 0;
$sidebarName = $sidebarSignedIn ? (string)$navUsername : 'Guest';
$sidebarRole = $sidebarSignedIn ? ($navIsAdmin ? 'Administrator' : 'User') : 'Trial workspace';
$sidebarEscape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$sidebarNameParts = preg_split('/[\s_.-]+/u', trim($sidebarName), -1, PREG_SPLIT_NO_EMPTY) ?: [$sidebarName];
$sidebarInitials = mb_strtoupper(mb_substr($sidebarNameParts[0], 0, 1) . (count($sidebarNameParts) > 1 ? mb_substr(end($sidebarNameParts), 0, 1) : ''), 'UTF-8');
$sidebarIcon = static function (string $name): string {
    $paths = [
        'document' => '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8l-5-5Z"/><path d="M14 3v5h5M9 12h6m-6 4h6"/>',
        'history' => '<path d="M3 11a9 9 0 1 1 2.7 7M3 4v7h7M12 7v5l3 2"/>',
        'chart' => '<path d="M4 3v17h17M9 15v-4m5 4V7m5 8v-6"/>',
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 5"/>',
        'list' => '<path d="M8 5h13M8 12h13M8 19h13M3 5h.01M3 12h.01M3 19h.01"/>',
        'storage' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v7c0 4 16 4 16 0V5M4 12v7c0 4 16 4 16 0v-7"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
        'chevron' => '<path d="m8 10 4 4 4-4"/>',
        'collapse' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18m7-13-4 4 4 4"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'logout' => '<path d="M9 4H4v16h5m5-13 5 5-5 5M8 12h13"/>',
        'login' => '<path d="M15 4h5v16h-5m-5-13 5 5-5 5M3 12h12"/>',
    ];
    return '<svg class="sidebar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['document']) . '</svg>';
};
$sidebarCanShow = static fn(array $item): bool => match ($item['access']) {
    'admin' => $navIsAdmin,
    'account' => $sidebarSignedIn,
    'user' => $sidebarSignedIn && !$navIsAdmin,
    default => true,
};
?>
<script>
// Run before page content is parsed so a saved collapsed layout cannot flash wide.
document.documentElement.dataset.appNavigation = 'sidebar';
try { document.documentElement.dataset.sidebarCollapsed = ['true', '1'].includes(localStorage.getItem('depended-sidebar-collapsed') || localStorage.getItem('dee-sidebar-collapsed') || localStorage.getItem('light-sidebar-collapsed')) ? 'true' : 'false'; } catch (_) { document.documentElement.dataset.sidebarCollapsed = 'false'; }
</script>
<link rel="stylesheet" href="assets/css/app-sidebar.css?v=<?= filemtime(__DIR__ . '/../assets/css/app-sidebar.css') ?>">
<script src="assets/js/logout-confirmation.js?v=<?= filemtime(__DIR__ . '/../assets/js/logout-confirmation.js') ?>" defer></script>
<script src="assets/js/app-sidebar.js?v=<?= filemtime(__DIR__ . '/../assets/js/app-sidebar.js') ?>" defer></script>

<header class="sidebar-mobile-header">
    <button type="button" id="sidebar-open" class="btn btn--ghost btn--icon sidebar-icon-button" aria-label="Open navigation" aria-controls="app-sidebar" aria-expanded="false"><?= $sidebarIcon('menu') ?></button>
    <a class="sidebar-brand" href="index.php" aria-label="DEPENDED home">DEPENDED</a>
</header>
<div id="sidebar-backdrop" class="sidebar-backdrop" hidden></div>
<aside class="app-sidebar" id="app-sidebar" aria-label="Application navigation">
    <div class="app-sidebar__top">
        <div class="app-sidebar__brand-row">
            <a class="sidebar-brand" href="index.php" aria-label="DEPENDED home"><span class="sidebar-brand__full">DEPENDED</span><span class="sidebar-brand__compact" aria-hidden="true">D</span></a>
            <button type="button" id="sidebar-close" class="btn btn--ghost btn--icon sidebar-icon-button" aria-label="Close navigation"><?= $sidebarIcon('close') ?></button>
        </div>
        <div class="sidebar-account">
            <button type="button" id="sidebar-account-toggle" class="btn btn--ghost sidebar-account__toggle<?= $navCurrentPage === 'profile.php' ? ' is-current' : '' ?>" aria-expanded="false" aria-controls="sidebar-account-menu" aria-label="Account menu for <?= $sidebarEscape($sidebarName) ?>" title="<?= $sidebarEscape($sidebarName . ' — ' . $sidebarRole) ?>">
                <span class="sidebar-avatar" aria-hidden="true"><?= $sidebarSignedIn ? $sidebarEscape($sidebarInitials) : $sidebarIcon('user') ?></span>
                <span class="sidebar-account__identity"><span class="sidebar-account__name"><?= $sidebarEscape($sidebarName) ?></span><span class="sidebar-account__role"><?= $sidebarEscape($sidebarRole) ?></span></span>
                <span class="sidebar-account__chevron"><?= $sidebarIcon('chevron') ?></span>
            </button>
            <div class="sidebar-account__menu" id="sidebar-account-menu" hidden>
                <?php if ($sidebarSignedIn): ?>
                <a class="sidebar-account__action" href="profile.php"<?= $navCurrentPage === 'profile.php' ? ' aria-current="page"' : '' ?>><?= $sidebarIcon('user') ?><span>My Profile</span></a>
                <?php else: ?>
                <a class="sidebar-account__action" href="login.php"><?= $sidebarIcon('login') ?><span>Sign in</span></a>
                <a class="sidebar-account__action" href="register.php"><?= $sidebarIcon('users') ?><span>Create account</span></a>
                <?php endif; ?>
                <?php require __DIR__ . '/theme-toggle-button.php'; ?>
                <?php if ($sidebarSignedIn): ?>
                <a class="sidebar-account__action sidebar-account__logout js-logout-link" href="auth.php?action=logout"><?= $sidebarIcon('logout') ?><span>Logout</span></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <nav class="app-sidebar__navigation" aria-label="Main navigation">
        <?php foreach ($appNavigation['groups'] as $group => $items): ?>
        <?php $visibleItems = array_filter($items, $sidebarCanShow); if (!$visibleItems) continue; ?>
        <div class="sidebar-group">
            <h2 class="sidebar-group__heading"><?= $sidebarEscape($group) ?></h2>
            <ul class="sidebar-group__links">
                <?php foreach ($visibleItems as $item): ?>
                <?php
                    $itemPath = explode('#', (string)$item['href'], 2)[0];
                    // Fragments are synchronized client-side: browsers do not send them to PHP.
                    $selected = $navCurrentPage === $itemPath && !str_contains((string)$item['href'], '#');
                ?>
                <li><a class="sidebar-link<?= $selected ? ' is-active' : '' ?>" data-sidebar-link href="<?= $sidebarEscape($item['href']) ?>" title="<?= $sidebarEscape($item['label']) ?>"<?= $selected ? ' aria-current="page"' : '' ?>><?= $sidebarIcon($item['icon']) ?><span class="sidebar-link__label"><?= $sidebarEscape($item['label']) ?></span></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </nav>
    <div class="app-sidebar__bottom">
        <button type="button" class="btn btn--ghost sidebar-collapse" data-sidebar-collapse aria-controls="app-sidebar" aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar"><?= $sidebarIcon('collapse') ?><span class="sidebar-collapse__label" data-sidebar-collapse-label>Collapse sidebar</span></button>
    </div>
</aside>
