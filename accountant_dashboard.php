
<?php
require_once 'includes/auth.php';
requireLogin();

// Only accountants may access this portal.
if (($_SESSION['role'] ?? '') !== 'accountant') {
    http_response_code(403);
    exit('Access denied. Accountant permission is required.');
}

require_once 'includes/db.php';

$pageTitle = 'Accountant Dashboard';

function accountantEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function accountantMoney($value): string
{
    return 'KSh ' . number_format((float)$value, 2);
}

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$monthStart = date('Y-m-01');

$todaySales = 0.0;
$monthSales = 0.0;
$todayExpenses = 0.0;
$monthExpenses = 0.0;
$todayTransactions = 0;
$cashSales = 0.0;
$mpesaSales = 0.0;
$financialError = '';

try {
    // Today's and monthly sales.
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(
                CASE WHEN sale_date >= ? AND sale_date < ?
                THEN total_amount ELSE 0 END
            ), 0) AS today_sales,

            COALESCE(SUM(
                CASE WHEN sale_date >= ? AND sale_date < ?
                THEN total_amount ELSE 0 END
            ), 0) AS month_sales,

            COALESCE(SUM(
                CASE WHEN sale_date >= ? AND sale_date < ?
                THEN 1 ELSE 0 END
            ), 0) AS today_transactions,

            COALESCE(SUM(
                CASE WHEN sale_date >= ? AND sale_date < ?
                    AND LOWER(TRIM(COALESCE(payment_method, ''))) = 'cash'
                THEN total_amount ELSE 0 END
            ), 0) AS cash_sales,

            COALESCE(SUM(
                CASE WHEN sale_date >= ? AND sale_date < ?
                    AND LOWER(TRIM(COALESCE(payment_method, ''))) = 'mpesa'
                THEN total_amount ELSE 0 END
            ), 0) AS mpesa_sales

        FROM sales
    ");

    $stmt->execute([
        $today, $tomorrow,
        $monthStart, $tomorrow,
        $today, $tomorrow,
        $today, $tomorrow,
        $today, $tomorrow
    ]);

    $sales = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $todaySales = (float)($sales['today_sales'] ?? 0);
    $monthSales = (float)($sales['month_sales'] ?? 0);
    $todayTransactions = (int)($sales['today_transactions'] ?? 0);
    $cashSales = (float)($sales['cash_sales'] ?? 0);
    $mpesaSales = (float)($sales['mpesa_sales'] ?? 0);

    // Today's and monthly expenses.
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(
                CASE WHEN expense_date = ?
                THEN amount ELSE 0 END
            ), 0) AS today_expenses,

            COALESCE(SUM(
                CASE WHEN expense_date >= ? AND expense_date < ?
                THEN amount ELSE 0 END
            ), 0) AS month_expenses

        FROM expenses
    ");

    $stmt->execute([$today, $monthStart, $tomorrow]);
    $expenses = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $todayExpenses = (float)($expenses['today_expenses'] ?? 0);
    $monthExpenses = (float)($expenses['month_expenses'] ?? 0);

} catch (PDOException $e) {
    error_log('Accountant dashboard error: ' . $e->getMessage());
    $financialError = 'Some financial totals could not be loaded. Please check your database tables and column names.';
}

$todayBalance = $todaySales - $todayExpenses;
$monthBalance = $monthSales - $monthExpenses;

// Load the shared sidebar and page layout.
require_once 'includes/header.php';
?>

<section class="welcome-banner">
    <div>
        <h2>Welcome, <?= accountantEscape(
            $_SESSION['full_name']
            ?? $_SESSION['username']
            ?? 'Accountant'
        ) ?>!</h2>
        <p>Review your wholesale business's financial performance.</p>
    </div>

    <div class="today-badge">
        <?= accountantEscape(date('l, d F Y')) ?>
    </div>
</section>

<?php if ($financialError !== ''): ?>
    <div class="alert alert-error" role="alert">
        <?= accountantEscape($financialError) ?>
    </div>
<?php endif; ?>

<div class="section-heading">
    <h2>Today's Financial Summary</h2>
    <span>Amounts in Kenyan shillings</span>
</div>

<section class="stats-grid">
    <div class="stat-card sales">
        <div class="stat-top">
            <span class="stat-label">Sales Today</span>
            <span class="stat-icon">↗</span>
        </div>
        <div class="stat-number">
            <?= accountantMoney($todaySales) ?>
        </div>
        <div class="stat-note">
            <?= number_format($todayTransactions) ?> transaction(s)
        </div>
    </div>

    <div class="stat-card expense">
        <div class="stat-top">
            <span class="stat-label">Expenses Today</span>
            <span class="stat-icon">↘</span>
        </div>
        <div class="stat-number">
            <?= accountantMoney($todayExpenses) ?>
        </div>
        <div class="stat-note">Expenses recorded today</div>
    </div>

    <div class="stat-card profit">
        <div class="stat-top">
            <span class="stat-label">Sales Minus Expenses</span>
            <span class="stat-icon">=</span>
        </div>
        <div class="stat-number">
            <?= accountantMoney($todayBalance) ?>
        </div>
        <div class="stat-note">Today’s difference, not final profit</div>
    </div>
</section>

<div class="section-heading">
    <h2>Payment Collections Today</h2>
    <span>Recorded payment methods</span>
</div>

<section class="stats-grid">
    <div class="stat-card cash-payment">
        <div class="stat-top">
            <span class="stat-label">Cash Received</span>
            <span class="stat-icon">KSh</span>
        </div>
        <div class="stat-number">
            <?= accountantMoney($cashSales) ?>
        </div>
        <div class="stat-note">Sales recorded as cash</div>
    </div>

    <div class="stat-card mpesa-payment">
        <div class="stat-top">
            <span class="stat-label">M-Pesa Received</span>
            <span class="stat-icon">M</span>
        </div>
        <div class="stat-number">
            <?= accountantMoney($mpesaSales) ?>
        </div>
        <div class="stat-note">Sales recorded as M-Pesa</div>
    </div>
</section>

<div class="section-heading">
    <h2><?= accountantEscape(date('F Y')) ?> Financial Summary</h2>
    <span>From the first day of this month to today</span>
</div>

<section class="stats-grid">
    <div class="stat-card sales">
        <div class="stat-top">
            <span class="stat-label">Sales This Month</span>
            <span class="stat-icon">↗</span>
        </div>
        <div class="stat-number">
            <?= accountantMoney($monthSales) ?>
        </div>
        <div class="stat-note">Recorded monthly sales</div>
    </div>

    <div class="stat-card expense">
        <div class="stat-top">
            <span class="stat-label">Expenses This Month</span>
            <span class="stat-icon">↘</span>
        </div>
        <div class="stat-number">
            <?= accountantMoney($monthExpenses) ?>
        </div>
        <div class="stat-note">Recorded monthly expenses</div>
    </div>

    <div class="stat-card profit">
        <div class="stat-top">
            <span class="stat-label">Sales Minus Expenses</span>
            <span class="stat-icon">=</span>
        </div>
        <div class="stat-number">
            <?= accountantMoney($monthBalance) ?>
        </div>
        <div class="stat-note">Monthly difference, not final profit</div>
    </div>
</section>

<section class="content-panel">
    <div class="panel-header">
        <h2>Accountant Tasks</h2>
    </div>

    <div class="quick-actions">
        <a class="action-link" href="reports.php">
            <span class="action-icon">▤</span>
            <span>
                <strong>Financial Reports</strong>
                <small>Review business reports</small>
            </span>
        </a>

        <a class="action-link" href="expenses.php">
            <span class="action-icon">↘</span>
            <span>
                <strong>Manage Expenses</strong>
                <small>Review and record business expenses</small>
            </span>
        </a>
    </div>

    <p class="note">
        Sales minus expenses is not the same as net profit. A complete
        profit calculation must also account for the cost of goods sold
        and any other applicable costs.
    </p>
</section>

<?php require_once 'includes/footer.php'; ?>
