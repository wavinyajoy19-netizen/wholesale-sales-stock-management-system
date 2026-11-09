
<?php

// Start the session securely.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// Load the database connection.
require_once __DIR__ . '/db.php';

/**
 * Ensure the user is logged in and their account is active.
 */
function requireLogin(): void
{
    global $pdo;

    if (empty($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT is_active FROM users WHERE id = ? LIMIT 1"
    );

    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || (int)$user['is_active'] !== 1) {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        header('Location: index.php?account=inactive');
        exit;
    }
}

/**
 * Ensure the logged-in user is an administrator.
 */
function requireAdmin(): void
{
    requireLogin();

    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Access denied. Administrator permission is required.');
    }
}

/**
 * Ensure the logged-in user is a storekeeper.
 */
function requireStorekeeper(): void
{
    requireLogin();

    if (($_SESSION['role'] ?? '') !== 'storekeeper') {
        http_response_code(403);
        exit('Access denied. Storekeeper permission is required.');
    }
}

/**
 * Ensure the user has one of the permitted roles.
 */
function requireRole(array $allowedRoles): void
{
    requireLogin();

    $currentRole = $_SESSION['role'] ?? '';

    if (!in_array($currentRole, $allowedRoles, true)) {
        http_response_code(403);
        exit('Access denied. You do not have permission to access this page.');
    }
}
