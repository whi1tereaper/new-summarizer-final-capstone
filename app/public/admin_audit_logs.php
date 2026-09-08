<?php
require_once __DIR__ . '/../src/whitereaper.php';
require_once __DIR__ . '/../src/Database.php';

use App\Src\Database;

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php?context=admin');
    exit;
}

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
    $where[] = "action = :action";
    $params['action'] = $filterAction;
}
if ($filterAdminId !== '') {
    $where[] = "admin_id = :admin_id";
    $params['admin_id'] = $filterAdminId;
}
if ($filterDateFrom !== '') {
    $where[] = "created_at >= :date_from";
    $params['date_from'] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo !== '') {
    $where[] = "created_at <= :date_to";
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
    $tableExists = $pdo->query("SHOW TABLES LIKE 'admin_audit_logs'")->rowCount() > 0;

    if ($tableExists) {
        $countSql = "SELECT COUNT(*) FROM admin_audit_logs $whereClause";
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Audit Logs</title>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;600&family=VT323&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .admin-logs-wrapper {
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background: #fff;
            border: 1px solid var(--color-border);
            font-family: 'Inter', sans-serif;
        }
        .filter-form {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f9f7fc;
            border: 1px solid var(--color-border);
            flex-wrap: wrap;
        }
        .filter-form input, .filter-form select, .filter-form button {
            padding: 8px 12px;
            border: 1px solid var(--color-border);
            font-family: inherit;
        }
        .filter-form button {
            background: var(--color-primary-dark);
            color: #fff;
            cursor: pointer;
            border: none;
            font-weight: 600;
            text-transform: uppercase;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9em;
        }
        th, td {
            border: 1px solid var(--color-border);
            padding: 10px;
            text-align: left;
        }
        th {
            background: #f3e8ff;
            font-weight: 600;
        }
        tr:nth-child(even) {
            background: #faf5fa;
        }
        .pagination {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: center;
        }
        .pagination a {
            padding: 8px 12px;
            border: 1px solid var(--color-primary-dark);
            text-decoration: none;
            color: var(--color-primary-dark);
        }
        .pagination a.active {
            background: var(--color-primary-dark);
            color: #fff;
        }
    </style>
</head>
<body style="background:#f4f4f5; margin:0; padding:0;">
    <div class="admin-logs-wrapper">
        <h2 style="font-family:'Merriweather',serif; color:var(--color-primary-dark); margin-top:0;">Admin Audit Logs</h2>

        <div style="margin-bottom: 20px;">
            <a href="admin_dashboard.php" style="color:var(--color-primary-dark); text-decoration:none;">&larr; Back to Dashboard</a>
        </div>

        <?php if ($dbError): ?>
            <div style="color:#cf222e; background:#ffebe9; border:1px solid #ff818266; padding:15px; margin-bottom:20px;">
                <?= htmlspecialchars($dbError) ?>
            </div>
        <?php else: ?>

            <form method="GET" class="filter-form">
                <select name="action">
                    <option value="">All Actions</option>
                    <?php foreach ($allActions as $act): ?>
                        <option value="<?= htmlspecialchars($act) ?>" <?= $filterAction === $act ? 'selected' : '' ?>>
                            <?= htmlspecialchars($act) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="admin_id" placeholder="Admin ID" value="<?= htmlspecialchars($filterAdminId) ?>">
                <input type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>" title="From Date">
                <input type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>" title="To Date">
                <button type="submit">Filter</button>
                <a href="admin_audit_logs.php" style="padding:8px 12px; border:1px solid var(--color-border); background:#fff; text-decoration:none; color:inherit;">Clear</a>
            </form>

            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Admin</th>
                            <th>Action</th>
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
                                <small>IP: <?= htmlspecialchars($log['ip_address'] ?? '') ?></small><br>
                                <small style="color:#666;"><?= htmlspecialchars(mb_strimwidth($log['user_agent'] ?? '', 0, 50, '...')) ?></small>
                            </td>
                            <td style="max-width:300px; word-wrap:break-word;">
                                <small><?= htmlspecialchars($log['details'] ?? '') ?></small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding:20px;">No logs found matching criteria.</td>
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
</body>
</html>
