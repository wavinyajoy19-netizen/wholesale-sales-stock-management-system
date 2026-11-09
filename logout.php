
<?php

// Load authentication, database and session setup.
require_once 'includes/auth.php';

// Load the activity logging function.
require_once 'includes/activity.php';

// Record the logout before clearing the session.
logActivity('Logout', 'User signed out successfully.');

// Clear all session variables.
$_SESSION = [];

// Remove the session cookie from the browser.
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

// Destroy the session on the server.
session_destroy();

// Redirect the user to the login page.
header('Location: index.php');
exit;
