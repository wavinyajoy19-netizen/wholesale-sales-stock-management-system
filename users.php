
<?php
require_once 'includes/auth.php';
requireAdmin();

require_once 'includes/db.php';

$message = '';
$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escapeUserValue($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| Handle form submissions
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {
        $error = 'Security check failed. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        /*
        |--------------------------------------------------------------------------
        | Activate or deactivate an account
        |--------------------------------------------------------------------------
        */
        if ($action === 'toggle_status') {
            $userId = filter_var(
                $_POST['user_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $newStatus = filter_var(
                $_POST['new_status'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$userId || !in_array($newStatus, [0, 1], true)) {
                $error = 'Invalid account status request.';
            } elseif ((int)$userId === (int)$_SESSION['user_id']) {
                $error = 'You cannot deactivate your own administrator account.';
            } else {
                try {
                    $check = $pdo->prepare(
                        'SELECT id, full_name, role, is_active
                         FROM users
                         WHERE id = ?
                         LIMIT 1'
                    );
                    $check->execute([$userId]);
                    $targetUser = $check->fetch(PDO::FETCH_ASSOC);

                    if (!$targetUser) {
                        $error = 'The selected user account was not found.';
                    } elseif ((int)$targetUser['is_active'] === $newStatus) {
                        $error = 'The account already has that status.';
                    } else {
                        // Do not allow the last active administrator to be deactivated.
                        if (
                            $newStatus === 0 &&
                            $targetUser['role'] === 'admin'
                        ) {
                            $adminCheck = $pdo->query(
                                "SELECT COUNT(*)
                                 FROM users
                                 WHERE role = 'admin'
                                 AND is_active = 1"
                            );

                            $activeAdmins = (int)$adminCheck->fetchColumn();

                            if ($activeAdmins <= 1) {
                                $error = 'You cannot deactivate the last active administrator.';
                            }
                        }

                        if ($error === '') {
                            $update = $pdo->prepare(
                                'UPDATE users
                                 SET is_active = ?
                                 WHERE id = ?'
                            );

                            $update->execute([$newStatus, $userId]);

                            $message = $newStatus === 1
                                ? $targetUser['full_name'] . ' account activated successfully.'
                                : $targetUser['full_name'] . ' account deactivated successfully.';

                            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        }
                    }
                } catch (PDOException $e) {
                    error_log('Account status update error: ' . $e->getMessage());
                    $error = 'Unable to update the account status. Please try again.';
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Create user account
        |--------------------------------------------------------------------------
        */
        elseif ($action === 'create_user') {
            $fullName = trim($_POST['full_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            $role = $_POST['role'] ?? '';

            $allowedRoles = ['cashier', 'storekeeper', 'accountant'];

            if (
                $fullName === '' ||
                $username === '' ||
                $password === '' ||
                $confirmPassword === '' ||
                $role === ''
            ) {
                $error = 'Please fill in all required fields.';
            } elseif (strlen($fullName) > 100) {
                $error = 'Full name must not exceed 100 characters.';
            } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $username)) {
                $error = 'Username must be 3–30 characters and contain only letters, numbers, dots, underscores or hyphens.';
            } elseif (!in_array($role, $allowedRoles, true)) {
                $error = 'Please select a valid user role.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must contain at least 8 characters.';
            } elseif ($password !== $confirmPassword) {
                $error = 'The passwords do not match.';
            } else {
                try {
                    $check = $pdo->prepare(
                        'SELECT id FROM users WHERE username = ?'
                    );
                    $check->execute([$username]);

                    if ($check->fetch()) {
                        $error = 'That username already exists. Choose another one.';
                    } else {
                        $hashedPassword = password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );

                        $stmt = $pdo->prepare(
                            'INSERT INTO users
                                (full_name, username, password, role, is_active)
                             VALUES (?, ?, ?, ?, 1)'
                        );

                        $stmt->execute([
                            $fullName,
                            $username,
                            $hashedPassword,
                            $role
                        ]);

                        $message = ucfirst($role) . ' account created successfully.';

                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    }
                } catch (PDOException $e) {
                    error_log('User creation error: ' . $e->getMessage());

                    if ($e->getCode() === '23000') {
                        $error = 'That username already exists. Choose another one.';
                    } else {
                        $error = 'Unable to create the account. Please try again.';
                    }
                }
            }
        } else {
            $error = 'Invalid form submission.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Retrieve users without password hashes
|--------------------------------------------------------------------------
*/
try {
    $stmt = $pdo->query(
        'SELECT id, full_name, username, role, is_active, created_at
         FROM users
         ORDER BY id DESC'
    );

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Loading users error: ' . $e->getMessage());
    $users = [];
    $error = 'Unable to retrieve user accounts. Please check the database.';
}

$pageTitle = 'Manage Users';
$activePage = 'users';

require_once 'includes/header.php';
?>

<style>
.users-container {
    max-width: 1250px;
    margin: 0 auto;
}

.users-heading {
    margin-bottom: 22px;
}

.users-heading h2 {
    margin: 0 0 8px;
    color: #1f2937;
    font-size: 25px;
}

.users-heading p {
    margin: 0;
    color: #6b7280;
    line-height: 1.6;
}

.users-panel {
    margin-bottom: 25px;
    padding: 24px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04);
}

.users-panel h3 {
    margin: 0 0 20px;
    color: #1f2937;
    font-size: 19px;
}

.users-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.users-form-group {
    margin-bottom: 4px;
}

.users-form-group label {
    display: block;
    margin-bottom: 8px;
    color: #374151;
    font-weight: 600;
}

.users-form-group input,
.users-form-group select {
    width: 100%;
    box-sizing: border-box;
    padding: 12px 13px;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    font-size: 15px;
    background: #fff;
}

.users-form-group input:focus,
.users-form-group select:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
}

.users-form-help {
    display: block;
    margin-top: 6px;
    color: #6b7280;
    font-size: 12px;
    line-height: 1.5;
}

.users-submit {
    margin-top: 20px;
    padding: 12px 20px;
    border: none;
    border-radius: 7px;
    background: #2563eb;
    color: #fff;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
}

.users-submit:hover {
    background: #1d4ed8;
}

.users-alert {
    padding: 13px 16px;
    margin-bottom: 20px;
    border-radius: 7px;
    line-height: 1.5;
}

.users-alert-success {
    background: #dcfce7;
    border: 1px solid #86efac;
    color: #166534;
}

.users-alert-error {
    background: #fee2e2;
    border: 1px solid #fca5a5;
    color: #991b1b;
}

.users-table-wrapper {
    overflow-x: auto;
}

.users-table {
    width: 100%;
    min-width: 850px;
    border-collapse: collapse;
}

.users-table th,
.users-table td {
    padding: 13px 14px;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
    font-size: 14px;
}

.users-table th {
    background: #f3f4f6;
    color: #374151;
    white-space: nowrap;
}

.users-table tbody tr:hover {
    background: #f9fafb;
}

.user-role,
.user-status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}

.user-role {
    background: #e5e7eb;
    color: #374151;
}

.role-admin {
    background: #dbeafe;
    color: #1d4ed8;
}

.role-cashier {
    background: #dcfce7;
    color: #166534;
}

.role-storekeeper {
    background: #fef3c7;
    color: #92400e;
}

.role-accountant {
    background: #f3e8ff;
    color: #7e22ce;
}

.status-active {
    background: #dcfce7;
    color: #166534;
}

.status-inactive {
    background: #fee2e2;
    color: #991b1b;
}

.user-action-button {
    padding: 8px 12px;
    border: none;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
}

.deactivate-button {
    background: #fee2e2;
    color: #991b1b;
}

.deactivate-button:hover {
    background: #fecaca;
}

.activate-button {
    background: #dcfce7;
    color: #166534;
}

.activate-button:hover {
    background: #bbf7d0;
}

.users-empty {
    padding: 25px;
    text-align: center;
    background: #f9fafb;
    border-radius: 8px;
    color: #6b7280;
}

@media (max-width: 650px) {
    .users-form-grid {
        grid-template-columns: 1fr;
    }

    .users-panel {
        padding: 17px;
    }

    .users-submit {
        width: 100%;
    }
}
</style>

<div class="users-container">

    <div class="users-heading">
        <h2>User Account Management</h2>
        <p>
            Create system accounts, review registered users, and manage employee account access.
        </p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="users-alert users-alert-success">
            <?= escapeUserValue($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="users-alert users-alert-error">
            <?= escapeUserValue($error) ?>
        </div>
    <?php endif; ?>

    <div class="users-panel">
        <h3>Create User Account</h3>

        <form method="POST" action="users.php">
            <input type="hidden" name="csrf_token"
                   value="<?= escapeUserValue($_SESSION['csrf_token']) ?>">

            <input type="hidden" name="action" value="create_user">

            <div class="users-form-grid">

                <div class="users-form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name"
                           maxlength="100" autocomplete="name" required>
                </div>

                <div class="users-form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username"
                           minlength="3" maxlength="30"
                           pattern="[a-zA-Z0-9_.-]{3,30}"
                           autocomplete="username" required>
                    <small class="users-form-help">
                        Use 3–30 letters, numbers, dots, underscores or hyphens.
                    </small>
                </div>

                <div class="users-form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password"
                           minlength="8" autocomplete="new-password" required>
                    <small class="users-form-help">
                        Use at least 8 characters.
                    </small>
                </div>

                <div class="users-form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password"
                           name="confirm_password" minlength="8"
                           autocomplete="new-password" required>
                </div>

                <div class="users-form-group">
                    <label for="role">User Role</label>
                    <select id="role" name="role" required>
                        <option value="">-- Select a role --</option>
                        <option value="cashier">Cashier</option>
                        <option value="storekeeper">Storekeeper</option>
                        <option value="accountant">Accountant</option>
                    </select>
                    <small class="users-form-help">
                        Administrator accounts cannot be created from this form.
                    </small>
                </div>

            </div>

            <button type="submit" class="users-submit">
                Create User Account
            </button>
        </form>
    </div>

    <div class="users-panel">
        <h3>Registered User Accounts (<?= count($users) ?>)</h3>

        <?php if (empty($users)): ?>

            <div class="users-empty">
                No user accounts were found.
            </div>

        <?php else: ?>

            <div class="users-table-wrapper">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Full Name</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Account Status</th>
                            <th>Date Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <?php
                            $roleClass = in_array(
                                $user['role'],
                                ['admin', 'cashier', 'storekeeper', 'accountant'],
                                true
                            ) ? 'role-' . $user['role'] : '';

                            $isActive = (int)$user['is_active'] === 1;
                            $isCurrentUser = (int)$user['id'] === (int)$_SESSION['user_id'];
                            ?>

                            <tr>
                                <td><?= (int)$user['id'] ?></td>

                                <td><?= escapeUserValue($user['full_name']) ?></td>

                                <td><?= escapeUserValue($user['username']) ?></td>

                                <td>
                                    <span class="user-role <?= escapeUserValue($roleClass) ?>">
                                        <?= escapeUserValue(ucfirst($user['role'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($isActive): ?>
                                        <span class="user-status status-active">Active</span>
                                    <?php else: ?>
                                        <span class="user-status status-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>

                                <td><?= escapeUserValue($user['created_at']) ?></td>

                                <td>
                                    <?php if ($isCurrentUser): ?>

                                        <span class="users-form-help">Current account</span>

                                    <?php else: ?>

                                        <form method="POST" action="users.php"
                                              onsubmit="return confirm('Are you sure you want to <?= $isActive ? 'deactivate' : 'activate' ?> this account?');">

                                            <input type="hidden" name="csrf_token"
                                                   value="<?= escapeUserValue($_SESSION['csrf_token']) ?>">

                                            <input type="hidden" name="action"
                                                   value="toggle_status">

                                            <input type="hidden" name="user_id"
                                                   value="<?= (int)$user['id'] ?>">

                                            <input type="hidden" name="new_status"
                                                   value="<?= $isActive ? 0 : 1 ?>">

                                            <?php if ($isActive): ?>
                                                <button type="submit"
                                                        class="user-action-button deactivate-button">
                                                    Deactivate
                                                </button>
                                            <?php else: ?>
                                                <button type="submit"
                                                        class="user-action-button activate-button">
                                                    Activate
                                                </button>
                                            <?php endif; ?>

                                        </form>

                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </div>

</div>

<?php require_once 'includes/footer.php'; ?>
