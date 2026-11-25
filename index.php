
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
$success = '';

// CSRF protection.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check whether any user accounts exist.
try {
    $userCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM users"
    )->fetchColumn();

    $setupMode = ($userCount === 0);

} catch (PDOException $e) {
    die("Database error. Please check that the users table exists.");
}

// Process form submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify form token.
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $error = "Invalid request. Please refresh the page and try again.";

    } elseif (($_POST['action'] ?? '') === 'create_admin') {

        // Check again before allowing first administrator creation.
        $userCount = (int)$pdo->query(
            "SELECT COUNT(*) FROM users"
        )->fetchColumn();

        if ($userCount > 0) {

            $error = "Administrator setup is already complete.";

        } else {

            $fullName = trim($_POST['full_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if ($fullName === '' || $username === '' || $password === '') {

                $error = "Please fill in all required fields.";

            } elseif (strlen($username) < 3 || strlen($username) > 50) {

                $error = "Username must be between 3 and 50 characters.";

            } elseif (strlen($password) < 8) {

                $error = "Password must contain at least 8 characters.";

            } elseif ($password !== $confirmPassword) {

                $error = "The passwords do not match.";

            } else {

                try {

                    $hashedPassword = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    $stmt = $pdo->prepare(
                        "INSERT INTO users
                        (full_name, username, password, role, is_active)
                        VALUES (?, ?, ?, 'admin', 1)"
                    );

                    $stmt->execute([
                        $fullName,
                        $username,
                        $hashedPassword
                    ]);

                    // Rotate token after successful setup.
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    header("Location: index.php?setup=success");
                    exit;

                } catch (PDOException $e) {

                    if ($e->getCode() === '23000') {
                        $error = "That username is already in use.";
                    } else {
                        $error = "Unable to create administrator. Please check your database structure.";
                    }
                }
            }
        }

    } else {

        // NORMAL LOGIN PROCESS.

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

            if ((int)$user['is_active'] !== 1) {

                $error = "Your account is inactive. Please contact the administrator.";

            } else {

                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];

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
}

// Show success message after first admin creation.
if (isset($_GET['setup']) && $_GET['setup'] === 'success') {
    $success = "Administrator account created successfully. You can now sign in.";
}

// Refresh setup status.
$userCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM users"
)->fetchColumn();

$setupMode = ($userCount === 0);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#102a43">

    <title>
        <?= $setupMode ? 'Create Administrator' : 'Sign In' ?>
        | Wholesale Management System
    </title>

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

            <?php if ($setupMode): ?>

                <p class="login-eyebrow">INITIAL SYSTEM SETUP</p>

                <h2>Create Administrator Account</h2>

                <p class="login-subtitle">
                    Create the first administrator account to get started.
                </p>

            <?php else: ?>

                <p class="login-eyebrow">WELCOME BACK</p>

                <h2>Sign in to your account</h2>

                <p class="login-subtitle">
                    Enter your credentials to access your workspace.
                </p>

            <?php endif; ?>

            <?php if ($error !== ''): ?>

                <div class="login-error" role="alert">
                    <span aria-hidden="true">&#9888;</span>
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>

            <?php endif; ?>

            <?php if ($success !== ''): ?>

                <div class="login-success" role="status">
                    <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
                </div>

            <?php endif; ?>

            <?php if ($setupMode): ?>

                <!-- FIRST ADMINISTRATOR REGISTRATION -->

                <form method="POST" action="index.php" class="login-form">

                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                    <input type="hidden" name="action" value="create_admin">

                    <div class="form-group">
                        <label for="full_name">Full Name</label>

                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            placeholder="Enter your full name"
                            autocomplete="name"
                            maxlength="100"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="username">Username</label>

                        <input
                            type="text"
                            id="username"
                            name="username"
                            placeholder="Create a username"
                            autocomplete="username"
                            minlength="3"
                            maxlength="50"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Create a password (minimum 8 characters)"
                            autocomplete="new-password"
                            minlength="8"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>

                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            placeholder="Enter password again"
                            autocomplete="new-password"
                            minlength="8"
                            required
                        >
                    </div>

                    <button type="submit" class="login-button">
                        Create Administrator
                        <span aria-hidden="true">&rarr;</span>
                    </button>

                </form>

                <div class="login-security-note">
                    &#128274;
                    Only the first administrator can be created here.
                </div>

            <?php else: ?>

                <!-- NORMAL LOGIN FORM -->

                <form method="POST" action="index.php" class="login-form">

                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                    <input type="hidden" name="action" value="login">

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

                        <label for="password">Password</label>

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
                    &#128274;
                    Secure access for authorised users only.
                </div>

            <?php endif; ?>

            <p class="login-copyright">
                &copy; <?= date('Y') ?> Wholesale Management System
            </p>

        </div>

    </section>

</main>

<script>

const passwordInput = document.getElementById('password');
const togglePassword = document.getElementById('togglePassword');

if (passwordInput && togglePassword) {

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

}

</script>

</body>
</html>
