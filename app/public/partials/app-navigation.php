<?php
// Presentation only: every destination retains its existing server-side guard.
// Keep public/authentication screens and contextual result/processing views separate.
return [
    'pages' => [
        'summarizer.php', 'history.php', 'profile.php', 'dashboard.php',
        'admin_dashboard.php', 'analytics.php', 'admin_audit_logs.php',
        'admin_student_registry.php',
    ],
    'groups' => [
        'Workspace' => [
            ['label' => 'Summarize', 'href' => 'summarizer.php', 'icon' => 'document', 'access' => 'all'],
            ['label' => 'History', 'href' => 'history.php', 'icon' => 'history', 'access' => 'account'],
            ['label' => 'Analytics', 'href' => 'analytics.php', 'icon' => 'chart', 'access' => 'admin'],
        ],
        'Management' => [
            ['label' => 'Admin Dashboard', 'href' => 'admin_dashboard.php', 'icon' => 'dashboard', 'access' => 'admin'],
            ['label' => 'Student IDs', 'href' => 'admin_student_registry.php', 'icon' => 'users', 'access' => 'admin'],
            ['label' => 'Audit Logs', 'href' => 'admin_audit_logs.php', 'icon' => 'list', 'access' => 'admin'],
            ['label' => 'Storage', 'href' => 'admin_dashboard.php#storage-maintenance', 'icon' => 'storage', 'access' => 'admin'],
        ],
        'Account' => [
            ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => 'dashboard', 'access' => 'user'],
        ],
    ],
];
