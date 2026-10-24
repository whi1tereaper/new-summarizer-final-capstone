<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Services/AdminSecurityService.php';

use App\Src\Database;
use App\Src\Services\AdminSecurityService;

AdminSecurityService::requireVerifiedAdmin('login.php?context=admin');

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$filterAction = $_GET['action'] ?? '';
$filterAdminId = $_GET['admin_id'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';

$where = [];
$params = [];

if ($filterAction !== '') {
    $where[] = "a.action = :action";
    $params['action'] = $filterAction;
}
if ($filterAdminId !== '') {
    $where[] = "a.admin_id = :admin_id";
    $params['admin_id'] = $filterAdminId;
}
if ($filterDateFrom !== '') {
    $where[] = "a.created_at >= :date_from";
    $params['date_from'] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo !== '') {
    $where[] = "a.created_at <= :date_to";
    $params['date_to'] = $filterDateTo . ' 23:59:59';
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$logs = [];
$totalRows = 0;
$totalPages = 1;
$dbError = null;

try {
    $pdo = Database::getInstance()->getConnection();

    // Check if table exists securely
    $tableExists = $pdo->query("SHOW TABLES LIKE 'admin_audit_logs'")->rowCount()>0;

    if ($tableExists) {
        $countSql = "SELECT COUNT(*) FROM admin_audit_logs a $whereClause";
        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($params);
        $totalRows = (int)$stmtCount->fetchColumn();
        $totalPages = max(1, ceil($totalRows / $perPage));

        $sql = "
            SELECT a.*, u.username, u.email 
            FROM admin_audit_logs a
            LEFT JOIN users u ON a.admin_id = u.id
            $whereClause 
            ORDER BY a.created_at DESC 
            LIMIT :limit OFFSET :offset
        ";

        // Bind params safely
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue(":$key", $val);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get unique actions for filter dropdown
        $actionsStmt = $pdo->query("SELECT DISTINCT action FROM admin_audit_logs ORDER BY action ASC");
        $allActions = $actionsStmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $dbError = "Audit log table is currently missing. Waiting for the first log to be generated.";
        $allActions = [];
    }
} catch (\Throwable $e) {
    error_log("Admin audit logs viewer error: " . $e->getMessage());
    $dbError = "An error occurred while fetching audit logs.";
    $allActions = [];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Audit Logs</title>
    <script src="assets/js/theme-toggle.js?v=2"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Outfit:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="assets/css/theme-toggle.css?v=2">
    <style>
        body {
            background: var(--color-bg);
            color: var(--color-text);
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
        }

        .admin-logs-page {
            max-width: 1440px;
            margin: 0 auto;
            padding: 20px 20px 80px;
        }

        .admin-logs-wrapper {
            max-width: 1440px;
            margin: 0 auto;
            padding: 0;
            background: transparent;
            border: 0;
            font-family: 'Inter', sans-serif;
        }

        .admin-logs-header {
            margin: 0;
            padding-top: 8px;
            padding-bottom: 18px;
            margin-bottom: 18px;
            border-bottom: 2px solid var(--color-accent);
        }

        .admin-logs-header h1 {
            margin: 0;
            font-family: 'Outfit', 'Inter', sans-serif;
            font-weight: 700;
            letter-spacing: -0.08em;
            color: #000;
            font-size: clamp(2rem, 5.5vw, 6rem);
            line-height: 0.74;
            text-transform: none;
        }

        [data-theme="dark"] .admin-logs-header h1 {
            color: #fff;
        }

        .admin-logs-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 18px 0 0;
            color: var(--color-primary-dark);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            text-transform: none;
            letter-spacing: 0.01em;
        }

        .filter-panel {
            background: transparent;
            border: 1px solid var(--color-border);
            padding: 18px 16px 14px;
            margin-top: 10px;
            display: grid;
            gap: 12px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: minmax(180px, 1.1fr) minmax(150px, 0.9fr) minmax(320px, 1.8fr) auto;
            gap: 12px;
            align-items: end;
            margin: 0;
        }

        .filter-form > * {
            min-width: 0;
        }

        .filter-form label {
            display: flex;
            flex-direction: column;
            gap: 8px;
            color: var(--color-text-muted);
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .filter-form input,
        .filter-form select,
        .filter-form button,
        .filter-form .clear-link {
            min-height: 42px;
            padding: 8px 12px;
            border-radius: 4px;
            font: inherit;
            box-sizing: border-box;
            margin-top: 0;
        }

        .date-input-wrap {
            position: relative;
            width: 100%;
        }

        .date-input-wrap::after {
            content: "";
            position: absolute;
            right: 14px;
            top: 50%;
            width: 18px;
            height: 18px;
            transform: translateY(-50%);
            pointer-events: none;
            opacity: 0.8;
            background-color: var(--color-text-secondary);
            -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='5' width='18' height='16' rx='2'/%3E%3Cpath d='M8 3v4M16 3v4M3 10h18'/%3E%3C/svg%3E") center / contain no-repeat;
            mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='5' width='18' height='16' rx='2'/%3E%3Cpath d='M8 3v4M16 3v4M3 10h18'/%3E%3C/svg%3E") center / contain no-repeat;
        }

        .filter-form input,
        .filter-form select {
            min-width: 160px;
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            color: var(--color-text);
        }

        .filter-form .date-input {
            padding-right: 42px;
            background-color: var(--color-surface);
            background-repeat: no-repeat;
            background-position: right 14px center;
            background-size: 18px 18px;
        }

        .date-range {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            align-items: end;
            gap: 10px;
            min-width: 0;
        }

        .date-range-field {
            display: grid;
            gap: 4px;
            min-width: 0;
        }

        .date-range-field label {
            color: var(--color-text-muted);
            font-size: 0.65rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .date-range .date-input-wrap,
        .date-range .date-input {
            min-width: 0;
        }

        .filter-form input[type="date"]::-webkit-calendar-picker-indicator {
            opacity: 0;
            position: absolute;
            right: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .filter-form button {
            background: var(--color-accent);
            color: var(--color-on-accent);
            border: 1px solid var(--color-accent);
            cursor: pointer;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .filter-form .clear-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--color-border);
            background: transparent;
            color: var(--color-text);
            text-decoration: none;
        }

        .filter-form button:focus-visible,
        .filter-form input:focus-visible,
        .filter-form select:focus-visible,
        .filter-form .clear-link:focus-visible {
            outline: 2px solid var(--color-focus);
            outline-offset: 2px;
        }

        .filter-compact {
            display: flex;
            align-items: end;
            gap: 12px;
            flex-wrap: wrap;
        }

        .filter-date {
            min-width: 150px;
        }

        table {
            width: 100%;
            min-width: 1280px;
            border-collapse: collapse;
            font-size: 0.9em;
            margin-top: 22px;
            table-layout: fixed;
            background: transparent;
        }

        th, td {
            border: 1px solid var(--color-border);
            padding: 18px 12px;
            text-align: left;
            vertical-align: top;
        }

        td {
            overflow-wrap: anywhere;
        }

        th {
            background: var(--color-bg-secondary);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--color-text-muted);
        }

        tbody tr:nth-child(even) {
            background: rgba(255,255,255,0.14);
        }

        .table-wrap {
            display: block;
            max-width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin-top: 18px;
            border: 1px solid var(--color-border);
            background: rgba(255,255,255,0.02);
        }

        .pagination {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .pagination a {
            padding: 8px 12px;
            border: 1px solid var(--color-primary-dark);
            text-decoration: none;
            color: var(--color-primary-dark);
        }

        .pagination a.active {
            background: var(--color-primary-dark);
            color: var(--color-on-accent);
        }

        @media (max-width: 900px) {
            .filter-form {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filter-form .date-range {
                grid-column: 1 / -1;
            }

            .filter-form button {
                justify-self: start;
            }
        }

        @media (max-width: 640px) {
            .admin-logs-page {
                padding-left: 14px;
                padding-right: 14px;
            }

            .filter-form {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                align-items: stretch;
            }

            .filter-form > * {
                width: 100%;
                min-width: 0;
            }

            .filter-form .date-range {
                grid-column: auto;
            }

            .filter-form button {
                width: 100%;
            }

            .date-range {
                width: 100%;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filter-compact {
                display: grid;
                grid-template-columns: 1fr 1fr;
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .admin-logs-page {
                padding: 16px 12px 48px;
            }

            .admin-logs-header {
                padding-bottom: 14px;
                margin-bottom: 14px;
            }

            .admin-logs-header h1 {
                font-size: clamp(1.8rem, 9vw, 2.6rem);
                line-height: 0.95;
                letter-spacing: -0.06em;
            }

            th, td {
                padding: 12px 10px;
            }
        }
    </style>
</head>
<body style="background:var(--color-bg); color:var(--color-text); margin:0; padding:0;">
    <?php require __DIR__ . '/partials/site-nav.php'; ?>
    <main class="admin-logs-page">
        <div class="admin-logs-wrapper">
            <header class="admin-logs-header">
                <h1>Admin Audit Logs</h1>
            </header>

        <?php if ($dbError): ?>
            <div style="color:var(--color-danger); background:var(--color-danger-soft); border:1px solid var(--color-danger); padding:15px; margin-bottom:20px;">
                <?= htmlspecialchars($dbError) ?>
            </div>
        <?php else: ?>

            <form method="GET" class="filter-form">
                <select name="action" aria-label="Filter by action" style="width: 100%; background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border); border-radius: 6px; padding: 14px 16px; font-size: 1rem; appearance: none; background-image: linear-gradient(45deg, transparent 50%, var(--color-text-secondary) 50%), linear-gradient(135deg, var(--color-text-secondary) 50%, transparent 50%); background-position: calc(100% - 18px) calc(50% - 3px), calc(100% - 12px) calc(50% - 3px); background-size: 6px 6px, 6px 6px; background-repeat: no-repeat;">
                    <option value="">All Actions</option>
                    <?php foreach ($allActions as $act): ?>
                        <option value="<?= htmlspecialchars($act) ?>" <?= $filterAction === $act ? 'selected' : '' ?>>
                            <?= htmlspecialchars($act) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="admin_id" placeholder="Admin ID" value="<?= htmlspecialchars($filterAdminId) ?>" style="width: 100%; background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border); border-radius: 6px; padding: 14px 16px; font-size: 1rem;">
                <div class="date-range">
                    <div class="date-range-field">
                        <label for="date-from">Start date</label>
                        <div class="date-input-wrap"><input id="date-from" class="date-input" type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>" title="From Date" style="width: 100%; background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border); border-radius: 6px; padding: 14px 16px; font-size: 1rem;"></div>
                    </div>
                    <div class="date-range-field">
                        <label for="date-to">End date</label>
                        <div class="date-input-wrap"><input id="date-to" class="date-input" type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>" title="To Date" style="width: 100%; background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border); border-radius: 6px; padding: 14px 16px; font-size: 1rem;"></div>
                    </div>
                </div>
                <button type="submit" style="padding: 14px 22px; min-width: 110px; background: var(--color-accent); border: 1px solid var(--color-accent); color: var(--color-on-accent); border-radius: 6px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;">Filter</button>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Admin</th>
                            <th>Action</th>
                            <th>Target</th>
                            <th>IP / User Agent</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= (int)$log['id'] ?></td>
                            <td style="white-space:nowrap;"><?= htmlspecialchars($log['created_at']) ?></td>
                            <td>
                                <?php if ($log['admin_id']): ?>
                                    ID: <?= (int)$log['admin_id'] ?><br>
                                    <small><?= htmlspecialchars($log['username'] ?? 'Unknown') ?></small>
                                <?php else: ?>
                                    <em>System / CLI</em>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= htmlspecialchars($log['action']) ?></strong></td>
                            <td>
                                <?php if ($log['entity_type'] !== null || $log['entity_id'] !== null): ?>
                                    <?= htmlspecialchars($log['entity_type'] ?? 'Entity') ?>
                                    <?php if ($log['entity_id'] !== null): ?> #<?= (int)$log['entity_id'] ?><?php endif; ?>
                                <?php else: ?>
                                    <em>&mdash;</em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small>IP: <?= htmlspecialchars($log['ip_address'] ?? '') ?></small><br>
                                <small style="color:var(--color-text-muted);"><?= htmlspecialchars(mb_strimwidth($log['user_agent'] ?? '', 0, 50, '...')) ?></small>
                            </td>
                            <td style="max-width:300px; word-wrap:break-word;">
                                <small><?= htmlspecialchars($log['details'] ?? '') ?></small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:20px;">No logs found matching criteria.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&action=<?= urlencode($filterAction) ?>&admin_id=<?= urlencode($filterAdminId) ?>&date_from=<?= urlencode($filterDateFrom) ?>&date_to=<?= urlencode($filterDateTo) ?>">Previous</a>
                    <?php endif; ?>
                    
                    <a href="#" class="active">Page <?= $page ?> of <?= $totalPages ?></a>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>&action=<?= urlencode($filterAction) ?>&admin_id=<?= urlencode($filterAdminId) ?>&date_from=<?= urlencode($filterDateFrom) ?>&date_to=<?= urlencode($filterDateTo) ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>
        </div>
    </main>
    <?php require __DIR__ . '/partials/site-footer.php'; ?>
</body>
</html>
