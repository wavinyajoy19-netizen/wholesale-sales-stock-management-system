
<?php
session_start();

require_once 'includes/db.php';
require_once 'includes/activity.php';

// Redirect users who are already logged in.
if (!empty($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = '';

// Process login form.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare(
        "SELECT id, full_name, username, password, role, is_active
         FROM users
         WHERE username = ?
         LIMIT 1"
    );

    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {

        // Check whether the employee account is active.
        if ((int)$user['is_active'] !== 1) {

            $error = "Your account is inactive. Please contact the administrator.";

        } else {

            // Regenerate session ID for security.
            session_regenerate_id(true);

            // Store employee information in the session.
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // Record successful login activity.
            logActivity(
                'Login',
                'User signed in successfully.'
            );

            header("Location: dashboard.php");
            exit;
        }

    } else {

        $error = "Invalid username or password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#102a43">

    <title>Sign In | Wholesale Management System</title>

    <link rel="stylesheet" href="css/style.css">
</head>

<body class="login-page">

    <main class="login-layout">

        <section class="login-brand-panel">

            <div class="brand-content">

                <div class="brand-logo" aria-hidden="true">W</div>

                <p class="brand-label">
                    WHOLESALE BUSINESS SOLUTIONS
                </p>

                <h1>
                    Manage your business with confidence.
                </h1>

                <p class="brand-description">
                    Manage your stock, record sales, track expenses
                    and monitor your business performance in one place.
                </p>

                <div class="brand-features">

                    <div class="brand-feature">
                        <span class="feature-icon">&#10003;</span>
                        Stock and inventory management
                    </div>

                    <div class="brand-feature">
                        <span class="feature-icon">&#10003;</span>
                        Sales and receipt management
                    </div>

                    <div class="brand-feature">
                        <span class="feature-icon">&#10003;</span>
                        Reports and expense tracking
                    </div>

                </div>

            </div>

            <p class="brand-footer">
                Wholesale Sales and Stock Management System
            </p>

        </section>

        <section class="login-form-panel">

            <div class="login-card">

                <div class="mobile-brand-logo" aria-hidden="true">W</div>

                <p class="login-eyebrow">WELCOME BACK</p>

                <h2>Sign in to your account</h2>

                <p class="login-subtitle">
                    Enter your credentials to access your workspace.
                </p>

                <?php if ($error !== ''): ?>

                    <div class="login-error" role="alert">

                        <span aria-hidden="true">&#9888;</span>

                        <?= htmlspecialchars(
                            $error,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                    </div>

                <?php endif; ?>

                <?php if (isset($_GET['account']) && $_GET['account'] === 'inactive'): ?>

                    <div class="login-error" role="alert">
                        Your account is inactive. Please contact the administrator.
                    </div>

                <?php endif; ?>

                <form method="POST" action="index.php" class="login-form">

                    <div class="form-group">

                        <label for="username">Username</label>

                        <input
                            type="text"
                            id="username"
                            name="username"
                            placeholder="Enter your username"
                            autocomplete="username"
                            required
                            autofocus
                        >

                    </div>

                    <div class="form-group">

                        <div class="password-label-row">

                            <label for="password">Password</label>

                        </div>

                        <div class="password-input-wrap">

                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="Enter your password"
                                autocomplete="current-password"
                                required
                            >

                            <button
                                type="button"
                                class="toggle-password"
                                id="togglePassword"
                                aria-label="Show password"
                                aria-pressed="false"
                            >
                                Show
                            </button>

                        </div>

                    </div>

                    <button type="submit" class="login-button">

                        Sign In

                        <span aria-hidden="true">&rarr;</span>

                    </button>

                </form>

                <div class="login-security-note">

                    <span aria-hidden="true">&#128274;</span>

                    Secure access for authorised users only.

                </div>

                <p class="login-copyright">

                    &copy; <?= date('Y') ?> Wholesale Management System

                </p>

            </div>

        </section>

    </main>

    <script>

        const passwordInput = document.getElementById('password');

        const togglePassword = document.getElementById('togglePassword');

        togglePassword.addEventListener('click', function () {

            const isPassword = passwordInput.type === 'password';

            passwordInput.type = isPassword ? 'text' : 'password';

            togglePassword.textContent = isPassword ? 'Hide' : 'Show';

            togglePassword.setAttribute(
                'aria-label',
                isPassword ? 'Hide password' : 'Show password'
            );

            togglePassword.setAttribute(
                'aria-pressed',
                isPassword ? 'true' : 'false'
            );

        });

    </script>

</body>
</html>
