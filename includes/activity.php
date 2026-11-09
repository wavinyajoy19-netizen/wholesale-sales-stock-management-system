
<?php

/**
 * Record an employee activity in the activity_logs table.
 *
 * @param string $action  The activity performed.
 * @param string $details Additional information about the activity.
 */
function logActivity(string $action, string $details = ''): void
{
    global $pdo;

    // Do not interrupt normal system operations if logging fails.
    if (!isset($pdo) || !isset($_SESSION['user_id'])) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO activity_logs (user_id, action, details)
             VALUES (?, ?, ?)"
        );

        $stmt->execute([
            $_SESSION['user_id'],
            $action,
            $details
        ]);

    } catch (PDOException $e) {
        error_log('Activity logging error: ' . $e->getMessage());
    }
}
