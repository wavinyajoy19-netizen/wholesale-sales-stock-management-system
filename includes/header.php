
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = $pageTitle ?? 'Dashboard';

$fullName = $_SESSION['full_name']
    ?? $_SESSION['username']
    ?? 'User';

$userRole = $_SESSION['role'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | Wholesale System</title>

    <!-- Main Stylesheets -->
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/dashboard.css">

    <!-- Font Awesome Icons -->
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
    >
</head>

<body class="dashboard-page">

<div class="app-shell">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="app-main">

        <header class="topbar">

            <div>
                <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p>Wholesale Sales &amp; Stock Management</p>
            </div>

            <div class="user-profile">

                <div class="user-avatar">
                    <?= htmlspecialchars(
                        strtoupper(substr($fullName, 0, 1)),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </div>

                <div class="user-details">
                    <strong>
                        <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>
                    </strong>

                    <span>
                        <?= htmlspecialchars(ucfirst((string)$userRole), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>

            </div>

        </header>

        <div class="dashboard-content">
