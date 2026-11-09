
<?php
require_once 'includes/auth.php';
requireAdmin();

require_once 'includes/db.php';

// Escape output before displaying it in HTML.
function activityEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Read filter values.
$search = trim($_GET['search'] ?? '');
$actionFilter = trim($_GET['action'] ?? '');
$fromDate = trim($_GET['from'] ?? '');
$toDate = trim($_GET['to'] ?? '');

$conditions = [];
$params = [];

// Search employee name, username, action and details.
if ($search !== '') {
    $conditions[] = "(
        u.full_name LIKE ?
        OR u.username LIKE ?
        OR al.action LIKE ?
        OR al.details LIKE ?
    )";

    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

// Filter by activity type.
if ($actionFilter !== '') {
    $conditions[] = "al.action = ?";
    $params[] = $actionFilter;
}

// Filter by starting date.
if ($fromDate !== '') {
    $date = DateTime::createFromFormat('Y-m-d', $fromDate);

    if ($date && $date->format('Y-m-d') === $fromDate) {
        $conditions[] = "DATE(al.created_at) >= ?";
        $params[] = $fromDate;
    } else {
        $fromDate = '';
    }
}

// Filter by ending date.
if ($toDate !== '') {
    $date = DateTime::createFromFormat('Y-m-d', $toDate);

    if ($date && $date->format('Y-m-d') === $toDate) {
        $conditions[] = "DATE(al.created_at) <= ?";
        $params[] = $toDate;
    } else {
        $toDate = '';
    }
}

$sql = "
    SELECT
        al.id,
        al.user_id,
        al.action,
        al.details,
        al.created_at,
        u.full_name,
        u.username,
        u.role
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
";

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY al.created_at DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get the available activity types for the filter.
$actionStmt = $pdo->query(
    "SELECT DISTINCT action FROM activity_logs ORDER BY action ASC"
);
$availableActions = $actionStmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Activity Logs</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 25px;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: auto;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }

        h2 {
            margin: 0;
        }

        .back-link {
            background: #333;
            color: white;
            padding: 10px 15px;
            text-decoration: none;
            border-radius: 5px;
        }

        .filter-box {
            background: white;
            padding: 18px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: end;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        input, select {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        button, .reset-link {
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            background: #176b45;
            color: white;
            text-decoration: none;
            cursor: pointer;
        }

        .reset-link {
            background: #666;
        }

        .table-box {
            background: white;
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #176b45;
            color: white;
        }

        tr:hover {
            background: #f5f5f5;
        }

        .count {
            margin-bottom: 12px;
            font-weight: bold;
        }

        .empty {
            text-align: center;
            padding: 25px;
            color: #666;
        }
    </style>
</head>

<body>
<div class="container">

    <div class="top-bar">
        <h2>Employee Activity Logs</h2>
        <a class="back-link" href="dashboard.php">Back to Dashboard</a>
    </div>

    <div class="filter-box">
        <form method="GET" class="filter-form">

            <div class="field">
                <label for="search">Search employee or activity</label>
                <input
                    type="text"
                    id="search"
                    name="search"
                    placeholder="Name, username or activity"
                    value="<?php echo activityEscape($search); ?>"
                >
            </div>

            <div class="field">
                <label for="action">Activity type</label>
                <select name="action" id="action">
                    <option value="">All activities</option>

                    <?php foreach ($availableActions as $availableAction): ?>
                        <option
                            value="<?php echo activityEscape($availableAction); ?>"
                            <?php echo $actionFilter === $availableAction ? 'selected' : ''; ?>
                        >
                            <?php echo activityEscape($availableAction); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="from">From date</label>
                <input
                    type="date"
                    id="from"
                    name="from"
                    value="<?php echo activityEscape($fromDate); ?>"
                >
            </div>

            <div class="field">
                <label for="to">To date</label>
                <input
                    type="date"
                    id="to"
                    name="to"
                    value="<?php echo activityEscape($toDate); ?>"
                >
            </div>

            <button type="submit">Filter</button>
            <a href="activity_logs.php" class="reset-link">Clear</a>

        </form>
    </div>

    <div class="table-box">

        <p class="count">
            Activities found: <?php echo count($activities); ?>
            (showing a maximum of 200 records)
        </p>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Employee</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Activity</th>
                    <th>Details</th>
                    <th>Date and Time</th>
                </tr>
            </thead>

            <tbody>
            <?php if (!empty($activities)): ?>

                <?php foreach ($activities as $activity): ?>
                    <tr>
                        <td><?php echo activityEscape($activity['id']); ?></td>

                        <td>
                            <?php
                            echo activityEscape(
                                $activity['full_name'] ?? 'Unknown employee'
                            );
                            ?>
                        </td>

                        <td>
                            <?php
                            echo activityEscape(
                                $activity['username'] ?? 'N/A'
                            );
                            ?>
                        </td>

                        <td>
                            <?php
                            echo activityEscape(
                                ucfirst($activity['role'] ?? 'Unknown')
                            );
                            ?>
                        </td>

                        <td><?php echo activityEscape($activity['action']); ?></td>

                        <td><?php echo activityEscape($activity['details']); ?></td>

                        <td><?php echo activityEscape($activity['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="7" class="empty">
                        No activity records found.
                    </td>
                </tr>

            <?php endif; ?>
            </tbody>
        </table>

    </div>

</div>
</body>
</html>
