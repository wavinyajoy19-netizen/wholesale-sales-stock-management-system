<?php
require_once 'includes/auth.php';
requireLogin();
require_once 'includes/db.php';

function dashboardEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function moneyKsh($value): string
{
    return 'KSh ' . number_format((float)$value, 2);
}

$role = $_SESSION['role'] ?? '';
$isAdmin = ($role === 'admin');
$fullName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
$firstName = explode(' ', trim($fullName))[0] ?: 'User';

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$todayStart = $today . ' 00:00:00';
$tomorrowStart = $tomorrow . ' 00:00:00';

/* PRODUCT SUMMARY */
$totalProducts = (int)$pdo->query(
    "SELECT COUNT(*) FROM products"
)->fetchColumn();

$stockSummary = $pdo->query("
    SELECT
        COALESCE(SUM(CASE
            WHEN quantity > 0 AND quantity <= reorder_level
            THEN 1 ELSE 0 END), 0) AS low_stock,
        COALESCE(SUM(CASE
            WHEN quantity <= 0 THEN 1 ELSE 0 END), 0) AS out_stock
    FROM products
")->fetch(PDO::FETCH_ASSOC) ?: [];

$lowStockCount = (int)($stockSummary['low_stock'] ?? 0);
$outOfStockCount = (int)($stockSummary['out_stock'] ?? 0);

/* CUSTOMER COUNT */
$totalCustomers = 0;

try {
    $totalCustomers = (int)$pdo->query(
        "SELECT COUNT(*) FROM customers"
    )->fetchColumn();
} catch (PDOException $e) {
    error_log('Customer count: ' . $e->getMessage());
}

/* TODAY'S SALES */
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS total_transactions,
           COALESCE(SUM(total_amount), 0) AS total_sales
    FROM sales
    WHERE sale_date >= ? AND sale_date < ?
");
$stmt->execute([$todayStart, $tomorrowStart]);
$salesSummary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalTransactions = (int)($salesSummary['total_transactions'] ?? 0);
$totalSales = (float)($salesSummary['total_sales'] ?? 0);

/* SALES BY CUSTOMER TYPE */
$customerTypeSales = [
    'retailer' => ['revenue' => 0.0, 'transactions' => 0],
    'trader'   => ['revenue' => 0.0, 'transactions' => 0],
    'walk-in'  => ['revenue' => 0.0, 'transactions' => 0],
    'other'    => ['revenue' => 0.0, 'transactions' => 0],
];

try {
    $stmt = $pdo->prepare("
        SELECT
            CASE
                WHEN LOWER(TRIM(COALESCE(c.customer_type, ''))) = 'retailer'
                    THEN 'retailer'
                WHEN LOWER(TRIM(COALESCE(c.customer_type, ''))) = 'trader'
                    THEN 'trader'
                WHEN s.customer_id IS NULL
                    OR LOWER(TRIM(COALESCE(c.customer_type, ''))) = 'walk-in'
                    THEN 'walk-in'
                ELSE 'other'
            END AS customer_group,
            COUNT(*) AS transactions,
            COALESCE(SUM(s.total_amount), 0) AS revenue
        FROM sales s
        LEFT JOIN customers c ON c.id = s.customer_id
        WHERE s.sale_date >= ? AND s.sale_date < ?
        GROUP BY customer_group
    ");

    $stmt->execute([$todayStart, $tomorrowStart]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $group = $row['customer_group'];

        if (isset($customerTypeSales[$group])) {
            $customerTypeSales[$group]['revenue'] = (float)$row['revenue'];
            $customerTypeSales[$group]['transactions'] = (int)$row['transactions'];
        }
    }
} catch (PDOException $e) {
    error_log('Customer type summary: ' . $e->getMessage());
}

/* PAYMENT SUMMARY */
$cashSales = 0.0;
$mpesaSales = 0.0;
$otherPaymentSales = 0.0;

try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE
                WHEN LOWER(TRIM(payment_method)) = 'cash'
                THEN total_amount ELSE 0 END), 0) AS cash_total,
            COALESCE(SUM(CASE
                WHEN LOWER(TRIM(payment_method)) = 'mpesa'
                THEN total_amount ELSE 0 END), 0) AS mpesa_total,
            COALESCE(SUM(CASE
                WHEN payment_method IS NULL
                  OR LOWER(TRIM(payment_method)) NOT IN ('cash', 'mpesa')
                THEN total_amount ELSE 0 END), 0) AS other_total
        FROM sales
        WHERE sale_date >= ? AND sale_date < ?
    ");

    $stmt->execute([$todayStart, $tomorrowStart]);
    $paymentSummary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $cashSales = (float)($paymentSummary['cash_total'] ?? 0);
    $mpesaSales = (float)($paymentSummary['mpesa_total'] ?? 0);
    $otherPaymentSales = (float)($paymentSummary['other_total'] ?? 0);
} catch (PDOException $e) {
    error_log('Payment summary: ' . $e->getMessage());
}

$recordedPaymentTotal = $cashSales + $mpesaSales;
$cashPercentage = $totalSales > 0
    ? min(100, max(0, ($cashSales / $totalSales) * 100)) : 0;
$mpesaPercentage = $totalSales > 0
    ? min(100, max(0, ($mpesaSales / $totalSales) * 100)) : 0;

/* TODAY'S EXPENSES */
$todayExpenses = 0.0;

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM expenses
        WHERE expense_date = ?
    ");
    $stmt->execute([$today]);
    $todayExpenses = (float)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('Expense summary: ' . $e->getMessage());
}

/* COST OF GOODS SOLD */
$todayCogs = 0.0;

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(
            SUM(si.quantity * COALESCE(si.buying_price_at_sale, p.buying_price, 0)),
            0
        )
        FROM sale_items si
        INNER JOIN sales s ON s.id = si.sale_id
        LEFT JOIN products p ON p.id = si.product_id
        WHERE s.sale_date >= ? AND s.sale_date < ?
    ");

    $stmt->execute([$todayStart, $tomorrowStart]);
    $todayCogs = (float)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('Cost of goods sold: ' . $e->getMessage());
}

$todayGrossProfit = $totalSales - $todayCogs;
$todayNetProfit = $todayGrossProfit - $todayExpenses;

/* LAST SEVEN DAYS SALES AND EXPENSES */
$chartDays = [];
$chartSales = [];
$chartExpenses = [];

for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $chartDays[$day] = date('D d M', strtotime($day));
    $chartSales[$day] = 0.0;
    $chartExpenses[$day] = 0.0;
}

$chartStart = date('Y-m-d', strtotime('-6 days'));

try {
    $stmt = $pdo->prepare("
        SELECT DATE(sale_date) AS report_day,
               COALESCE(SUM(total_amount), 0) AS day_total
        FROM sales
        WHERE sale_date >= ? AND sale_date < ?
        GROUP BY DATE(sale_date)
    ");

    $stmt->execute([
        $chartStart . ' 00:00:00',
        $tomorrowStart
    ]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($chartSales[$row['report_day']])) {
            $chartSales[$row['report_day']] = (float)$row['day_total'];
        }
    }
} catch (PDOException $e) {
    error_log('Sales chart: ' . $e->getMessage());
}

try {
    $stmt = $pdo->prepare("
        SELECT expense_date AS report_day,
               COALESCE(SUM(amount), 0) AS day_total
        FROM expenses
        WHERE expense_date >= ? AND expense_date < ?
        GROUP BY expense_date
    ");

    $stmt->execute([$chartStart, $tomorrow]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($chartExpenses[$row['report_day']])) {
            $chartExpenses[$row['report_day']] = (float)$row['day_total'];
        }
    }
} catch (PDOException $e) {
    error_log('Expense chart: ' . $e->getMessage());
}

$chartMax = max(1, max($chartSales ?: [0]), max($chartExpenses ?: [0]));

/* PRODUCTS REQUIRING RESTOCKING */
$stmt = $pdo->query("
    SELECT product_name, product_code, quantity, reorder_level
    FROM products
    WHERE quantity <= reorder_level
    ORDER BY quantity ASC, product_name ASC
    LIMIT 15
");
$lowStockProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* LOAD SHARED PAGE LAYOUT */
$pageTitle = 'Dashboard';
require 'includes/header.php';
?>

<section class="welcome-banner">
    <div>
        <h2>Welcome back, <?= dashboardEscape($firstName) ?>!</h2>
        <p>Here is your business performance at a glance.</p>
    </div>
    <div class="today-badge">
        📅 <?= dashboardEscape(date('l, d F Y')) ?>
    </div>
</section>

<div class="section-heading">
    <h2>Today's Financial Summary</h2>
    <span>Amounts in Kenyan shillings (KSh)</span>
</div>

<section class="stats-grid">
    <div class="stat-card sales">
        <div class="stat-top">
            <span class="stat-label">Today's Sales Revenue</span>
            <span class="stat-icon">↗</span>
        </div>
        <div class="stat-number"><?= moneyKsh($totalSales) ?></div>
        <div class="stat-note"><?= number_format($totalTransactions) ?> transaction(s) today</div>
    </div>

    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Estimated Cost of Goods Sold</span>
            <span class="stat-icon">▤</span>
        </div>
        <div class="stat-number"><?= moneyKsh($todayCogs) ?></div>
        <div class="stat-note">Estimated cost of products sold today</div>
    </div>

    <div class="stat-card profit">
        <div class="stat-top">
            <span class="stat-label">Estimated Gross Profit</span>
            <span class="stat-icon">↗</span>
        </div>
        <div class="stat-number"><?= moneyKsh($todayGrossProfit) ?></div>
        <div class="stat-note">Sales minus estimated product cost</div>
    </div>

    <div class="stat-card expense">
        <div class="stat-top">
            <span class="stat-label">Today's Expenses</span>
            <span class="stat-icon">↘</span>
        </div>
        <div class="stat-number"><?= moneyKsh($todayExpenses) ?></div>
        <div class="stat-note">Expenses recorded today</div>
    </div>

    <div class="stat-card <?= $todayNetProfit >= 0 ? 'profit' : 'expense' ?>">
        <div class="stat-top">
            <span class="stat-label">Estimated Net Profit / Loss</span>
            <span class="stat-icon">◎</span>
        </div>
        <div class="stat-number"><?= moneyKsh($todayNetProfit) ?></div>
        <div class="stat-note">Gross profit minus today's expenses</div>
    </div>

    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Total Products</span>
            <span class="stat-icon">▣</span>
        </div>
        <div class="stat-number"><?= number_format($totalProducts) ?></div>
        <div class="stat-note">Products in inventory</div>
    </div>
</section>

<p class="note">
    Profit figures are estimates. They depend on the buying costs stored for
    products and sales.
</p>

<div class="section-heading">
    <h2>Today's Sales by Customer Type</h2>
    <span>Revenue and transactions</span>
</div>

<section class="stats-grid">
    <div class="stat-card customers">
        <div class="stat-top">
            <span class="stat-label">Retailer Sales</span>
            <span class="stat-icon">🏪</span>
        </div>
        <div class="stat-number"><?= moneyKsh($customerTypeSales['retailer']['revenue']) ?></div>
        <div class="stat-note"><?= number_format($customerTypeSales['retailer']['transactions']) ?> transaction(s)</div>
    </div>

    <div class="stat-card sales">
        <div class="stat-top">
            <span class="stat-label">Trader Sales</span>
            <span class="stat-icon">📦</span>
        </div>
        <div class="stat-number"><?= moneyKsh($customerTypeSales['trader']['revenue']) ?></div>
        <div class="stat-note"><?= number_format($customerTypeSales['trader']['transactions']) ?> transaction(s)</div>
    </div>

    <div class="stat-card payment-total">
        <div class="stat-top">
            <span class="stat-label">Walk-in Sales</span>
            <span class="stat-icon">🛍️</span>
        </div>
        <div class="stat-number"><?= moneyKsh($customerTypeSales['walk-in']['revenue']) ?></div>
        <div class="stat-note"><?= number_format($customerTypeSales['walk-in']['transactions']) ?> transaction(s)</div>
    </div>
</section>

<?php if ($customerTypeSales['other']['transactions'] > 0): ?>
<p class="note">
    Other customer categories: <?= moneyKsh($customerTypeSales['other']['revenue']) ?>
    across <?= number_format($customerTypeSales['other']['transactions']) ?> transaction(s).
</p>
<?php endif; ?>

<div class="section-heading">
    <h2>Today's Payment Summary</h2>
    <span>Cash and M-Pesa collections</span>
</div>

<section class="stats-grid">
    <div class="stat-card payment-total">
        <div class="stat-top">
            <span class="stat-label">Total Payments Recorded</span>
            <span class="stat-icon">KSh</span>
        </div>
        <div class="stat-number"><?= moneyKsh($recordedPaymentTotal) ?></div>
        <div class="stat-note">Cash plus M-Pesa sales</div>
    </div>

    <div class="stat-card cash-payment">
        <div class="stat-top">
            <span class="stat-label">Cash Received</span>
            <span class="stat-icon">💵</span>
        </div>
        <div class="stat-number"><?= moneyKsh($cashSales) ?></div>
        <div class="stat-note"><?= number_format($cashPercentage, 1) ?>% of today's sales</div>
        <div class="payment-progress">
            <div class="payment-progress-fill" style="width:<?= number_format($cashPercentage, 2, '.', '') ?>%"></div>
        </div>
    </div>

    <div class="stat-card mpesa-payment">
        <div class="stat-top">
            <span class="stat-label">M-Pesa Received</span>
            <span class="stat-icon">📱</span>
        </div>
        <div class="stat-number"><?= moneyKsh($mpesaSales) ?></div>
        <div class="stat-note"><?= number_format($mpesaPercentage, 1) ?>% of today's sales</div>
        <div class="payment-progress">
            <div class="payment-progress-fill" style="width:<?= number_format($mpesaPercentage, 2, '.', '') ?>%"></div>
        </div>
    </div>
</section>

<?php if ($otherPaymentSales > 0): ?>
<p class="note">
    <?= moneyKsh($otherPaymentSales) ?> has another or unrecorded payment method.
</p>
<?php endif; ?>

<section class="chart-panel">
    <div class="panel-header">
        <h2>Sales vs Expenses</h2>
        <small>Last seven days</small>
    </div>

    <div class="chart-body">
        <div class="legend">
            <span>Sales</span>
            <span class="expense-key">Expenses</span>
        </div>

        <div class="chart">
            <?php foreach ($chartDays as $day => $label): ?>
                <?php
                $saleHeight = $chartSales[$day] > 0
                    ? max(3, (int)round(($chartSales[$day] / $chartMax) * 145))
                    : 2;
                $expenseHeight = $chartExpenses[$day] > 0
                    ? max(3, (int)round(($chartExpenses[$day] / $chartMax) * 145))
                    : 2;
                ?>
                <div class="chart-day">
                    <div class="bar-values">
                        <div class="bar"
                             style="height:<?= $saleHeight ?>px"
                             title="Sales: <?= dashboardEscape(moneyKsh($chartSales[$day])) ?>"></div>
                        <div class="bar expense-bar"
                             style="height:<?= $expenseHeight ?>px"
                             title="Expenses: <?= dashboardEscape(moneyKsh($chartExpenses[$day])) ?>"></div>
                    </div>
                    <div class="day-label"><?= dashboardEscape($label) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<div class="section-heading">
    <h2>Stock &amp; Business Overview</h2>
    <span>Current records</span>
</div>

<section class="stats-grid">
    <div class="stat-card low">
        <div class="stat-top">
            <span class="stat-label">Low-stock Products</span>
            <span class="stat-icon">⚠</span>
        </div>
        <div class="stat-number"><?= number_format($lowStockCount) ?></div>
        <div class="stat-note">At or below reorder level</div>
    </div>

    <div class="stat-card out">
        <div class="stat-top">
            <span class="stat-label">Out of Stock</span>
            <span class="stat-icon">!</span>
        </div>
        <div class="stat-number"><?= number_format($outOfStockCount) ?></div>
        <div class="stat-note">Products with no available stock</div>
    </div>

    <div class="stat-card customers">
        <div class="stat-top">
            <span class="stat-label">Registered Customers</span>
            <span class="stat-icon">♙</span>
        </div>
        <div class="stat-number"><?= number_format($totalCustomers) ?></div>
        <div class="stat-note">Customer records in the system</div>
    </div>
</section>

<div class="section-heading">
    <h2>Quick Actions</h2>
    <span>Common tasks</span>
</div>

<section class="quick-actions">
    <a class="action-link" href="sales.php">
        <span class="action-icon">🛒</span>
        <span><strong>Record a Sale</strong><small>Make a new customer sale</small></span>
    </a>

    <a class="action-link" href="stock_in.php">
        <span class="action-icon">＋</span>
        <span><strong>Receive Stock</strong><small>Record newly received stock</small></span>
    </a>

    <a class="action-link" href="products.php">
        <span class="action-icon">▣</span>
        <span><strong>Manage Products</strong><small>Review products and stock</small></span>
    </a>

    <?php if ($isAdmin): ?>
    <a class="action-link" href="customers.php">
        <span class="action-icon">♙</span>
        <span><strong>Manage Customers</strong><small>View customer records</small></span>
    </a>

    <a class="action-link" href="expenses.php">
        <span class="action-icon">↘</span>
        <span><strong>Record Expenses</strong><small>Track business spending</small></span>
    </a>

    <a class="action-link" href="reports.php">
        <span class="action-icon">▤</span>
        <span><strong>View Reports</strong><small>Review business performance</small></span>
    </a>
    <?php endif; ?>
</section>

<section class="content-panel">
    <div class="panel-header">
        <h2>Stock Reorder Alerts</h2>
        <a href="products.php">Manage stock →</a>
    </div>

    <?php if (!$lowStockProducts): ?>
        <div class="empty-message">
            Great! No products currently need restocking.
        </div>
    <?php else: ?>
        <div class="table-container">
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Product Code</th>
                        <th>Quantity Left</th>
                        <th>Reorder Level</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lowStockProducts as $product): ?>
                    <tr>
                        <td><?= dashboardEscape($product['product_name']) ?></td>
                        <td><?= dashboardEscape($product['product_code']) ?></td>
                        <td><?= number_format((int)$product['quantity']) ?></td>
                        <td><?= number_format((int)$product['reorder_level']) ?></td>
                        <td>
                            <?php if ((int)$product['quantity'] <= 0): ?>
                                <span class="status-pill status-out">Out of stock</span>
                            <?php else: ?>
                                <span class="status-pill status-low">Low stock</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require 'includes/footer.php'; ?>

