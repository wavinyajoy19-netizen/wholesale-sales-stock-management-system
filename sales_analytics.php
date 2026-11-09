
<?php
require_once 'includes/auth.php';
requireLogin();

requireRole(['admin', 'accountant']);

require_once 'includes/db.php';

$pageTitle = 'Sales Analytics';

function analyticsEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* Date filters */
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

function validAnalyticsDate($date, $fallback): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return ($d && $d->format('Y-m-d') === $date)
        ? $date
        : $fallback;
}

$startDate = validAnalyticsDate($startDate, date('Y-m-01'));
$endDate = validAnalyticsDate($endDate, date('Y-m-d'));

if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

/* Overall sales summary */
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS transactions,
        COALESCE(SUM(total_amount), 0) AS total_sales,
        COALESCE(SUM(amount_paid), 0) AS total_paid,
        COALESCE(SUM(balance), 0) AS total_balance
    FROM sales
    WHERE sale_date >= ?
    AND sale_date < DATE_ADD(?, INTERVAL 1 DAY)
");

$stmt->execute([$startDate, $endDate]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

/* Profit summary */
$stmt = $pdo->prepare("
    SELECT
        COALESCE(
            SUM(
                si.subtotal -
                (si.quantity * COALESCE(si.buying_price_at_sale, 0))
            ), 0
        ) AS total_profit
    FROM sale_items si
    INNER JOIN sales s ON s.id = si.sale_id
    WHERE s.sale_date >= ?
    AND s.sale_date < DATE_ADD(?, INTERVAL 1 DAY)
");

$stmt->execute([$startDate, $endDate]);
$totalProfit = (float)$stmt->fetchColumn();

/* Daily sales */
$stmt = $pdo->prepare("
    SELECT
        DATE(sale_date) AS sale_day,
        COUNT(*) AS transactions,
        SUM(total_amount) AS total
    FROM sales
    WHERE sale_date >= ?
    AND sale_date < DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY DATE(sale_date)
    ORDER BY sale_day ASC
");

$stmt->execute([$startDate, $endDate]);
$dailySales = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Payment method summary */
$stmt = $pdo->prepare("
    SELECT
        payment_method,
        COUNT(*) AS transactions,
        COALESCE(SUM(total_amount), 0) AS total
    FROM sales
    WHERE sale_date >= ?
    AND sale_date < DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY payment_method
");

$stmt->execute([$startDate, $endDate]);
$paymentSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Best-selling products */
$stmt = $pdo->prepare("
    SELECT
        p.product_name,
        SUM(si.quantity) AS units_sold,
        SUM(si.subtotal) AS revenue,
        SUM(
            si.subtotal -
            (si.quantity * COALESCE(si.buying_price_at_sale, 0))
        ) AS profit
    FROM sale_items si
    INNER JOIN sales s ON s.id = si.sale_id
    INNER JOIN products p ON p.id = si.product_id
    WHERE s.sale_date >= ?
    AND s.sale_date < DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY p.id, p.product_name
    ORDER BY units_sold DESC
    LIMIT 10
");

$stmt->execute([$startDate, $endDate]);
$bestProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Weekly and monthly summaries */
$stmt = $pdo->prepare("
    SELECT
        YEAR(sale_date) AS sale_year,
        WEEK(sale_date, 1) AS sale_week,
        SUM(total_amount) AS total
    FROM sales
    WHERE sale_date >= ?
    AND sale_date < DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY YEAR(sale_date), WEEK(sale_date, 1)
    ORDER BY sale_year, sale_week
");

$stmt->execute([$startDate, $endDate]);
$weeklySales = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT
        DATE_FORMAT(sale_date, '%Y-%m') AS sale_month,
        SUM(total_amount) AS total
    FROM sales
    WHERE sale_date >= ?
    AND sale_date < DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY DATE_FORMAT(sale_date, '%Y-%m')
    ORDER BY sale_month
");

$stmt->execute([$startDate, $endDate]);
$monthlySales = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<style>
.analytics-hero {
    background: #172b4d;
    color: white;
    padding: 25px;
    border-radius: 12px;
    margin-bottom: 22px;
}

.analytics-hero h2 {
    margin: 0 0 8px;
}

.analytics-hero p {
    margin: 0;
    color: #dbeafe;
}

.analytics-filters,
.analytics-panel,
.analytics-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
}

.analytics-filters {
    margin-bottom: 22px;
}

.analytics-filters form {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: end;
}

.analytics-field {
    flex: 1;
    min-width: 170px;
}

.analytics-field label {
    display: block;
    margin-bottom: 7px;
    font-weight: 600;
    color: #334155;
}

.analytics-field input {
    width: 100%;
    padding: 11px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    box-sizing: border-box;
}

.analytics-button {
    background: #2563eb;
    color: white;
    border: none;
    padding: 12px 18px;
    border-radius: 7px;
    text-decoration: none;
    cursor: pointer;
    font-weight: 600;
}

.analytics-button:hover {
    background: #1d4ed8;
}

.analytics-cards {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 15px;
    margin-bottom: 22px;
}

.analytics-card span {
    display: block;
    color: #64748b;
    font-size: 13px;
    margin-bottom: 12px;
}

.analytics-card strong {
    color: #172b4d;
    font-size: 21px;
}

.analytics-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 22px;
}

.analytics-panel {
    margin-bottom: 22px;
}

.analytics-panel h3 {
    margin-top: 0;
    color: #172b4d;
}

.analytics-table-wrap {
    overflow-x: auto;
}

.analytics-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 550px;
}

.analytics-table th,
.analytics-table td {
    text-align: left;
    padding: 12px;
    border-bottom: 1px solid #e2e8f0;
}

.analytics-table th {
    background: #f1f5f9;
    color: #172b4d;
}

.analytics-empty {
    text-align: center;
    color: #64748b;
    padding: 25px !important;
}

.payment-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 13px 0;
    border-bottom: 1px solid #e2e8f0;
}

@media(max-width: 1100px) {
    .analytics-cards {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media(max-width: 700px) {
    .analytics-grid {
        grid-template-columns: 1fr;
    }

    .analytics-cards {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="analytics-hero">
    <h2>Sales Analytics Dashboard</h2>
    <p>Monitor sales performance, revenue, profit and payment trends.</p>
</div>

<section class="analytics-filters">

    <h3>Filter Analytics by Date</h3>

    <form method="GET">

        <div class="analytics-field">
            <label>Start Date</label>
            <input type="date"
                   name="start_date"
                   value="<?= analyticsEscape($startDate) ?>">
        </div>

        <div class="analytics-field">
            <label>End Date</label>
            <input type="date"
                   name="end_date"
                   value="<?= analyticsEscape($endDate) ?>">
        </div>

        <button type="submit" class="analytics-button">
            Apply Filter
        </button>

        <a href="sales_analytics.php" class="analytics-button">
            Reset
        </a>

    </form>

</section>

<section class="analytics-cards">

    <div class="analytics-card">
        <span>Total Transactions</span>
        <strong>
            <?= number_format((int)$summary['transactions']) ?>
        </strong>
    </div>

    <div class="analytics-card">
        <span>Total Sales Revenue</span>
        <strong>
            KSh <?= number_format((float)$summary['total_sales'], 2) ?>
        </strong>
    </div>

    <div class="analytics-card">
        <span>Amount Collected</span>
        <strong>
            KSh <?= number_format((float)$summary['total_paid'], 2) ?>
        </strong>
    </div>

    <div class="analytics-card">
        <span>Outstanding Balance</span>
        <strong>
            KSh <?= number_format((float)$summary['total_balance'], 2) ?>
        </strong>
    </div>

    <div class="analytics-card">
        <span>Estimated Profit</span>
        <strong>
            KSh <?= number_format($totalProfit, 2) ?>
        </strong>
    </div>

</section>

<div class="analytics-grid">

    <section class="analytics-panel">

        <h3>Payment Method Summary</h3>

        <?php if (!$paymentSummary): ?>

            <p>No payment records found.</p>

        <?php else: ?>

            <?php foreach ($paymentSummary as $payment): ?>

                <div class="payment-row">

                    <strong>
                        <?= strtoupper(analyticsEscape($payment['payment_method'])) ?>
                    </strong>

                    <span>
                        KSh <?= number_format((float)$payment['total'], 2) ?>
                    </span>

                </div>

                <p>
                    <?= number_format((int)$payment['transactions']) ?>
                    transaction(s)
                </p>

            <?php endforeach; ?>

        <?php endif; ?>

    </section>

    <section class="analytics-panel">

        <h3>Weekly Sales Summary</h3>

        <div class="analytics-table-wrap">
            <table class="analytics-table">

                <thead>
                    <tr>
                        <th>Year</th>
                        <th>Week</th>
                        <th>Total Sales</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (!$weeklySales): ?>

                        <tr>
                            <td colspan="3" class="analytics-empty">
                                No weekly sales found.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($weeklySales as $week): ?>

                            <tr>
                                <td><?= analyticsEscape($week['sale_year']) ?></td>
                                <td><?= analyticsEscape($week['sale_week']) ?></td>
                                <td>
                                    KSh <?= number_format((float)$week['total'], 2) ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>
        </div>

    </section>

</div>

<section class="analytics-panel">

    <h3>Daily Sales Summary</h3>

    <div class="analytics-table-wrap">

        <table class="analytics-table">

            <thead>
                <tr>
                    <th>Date</th>
                    <th>Transactions</th>
                    <th>Total Sales</th>
                </tr>
            </thead>

            <tbody>

                <?php if (!$dailySales): ?>

                    <tr>
                        <td colspan="3" class="analytics-empty">
                            No daily sales found.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($dailySales as $day): ?>

                        <tr>
                            <td><?= analyticsEscape($day['sale_day']) ?></td>
                            <td><?= number_format((int)$day['transactions']) ?></td>
                            <td>
                                KSh <?= number_format((float)$day['total'], 2) ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>

<section class="analytics-panel">

    <h3>Monthly Sales Summary</h3>

    <div class="analytics-table-wrap">

        <table class="analytics-table">

            <thead>
                <tr>
                    <th>Month</th>
                    <th>Total Sales</th>
                </tr>
            </thead>

            <tbody>

                <?php if (!$monthlySales): ?>

                    <tr>
                        <td colspan="2" class="analytics-empty">
                            No monthly sales found.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($monthlySales as $month): ?>

                        <tr>
                            <td><?= analyticsEscape($month['sale_month']) ?></td>
                            <td>
                                KSh <?= number_format((float)$month['total'], 2) ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>

<section class="analytics-panel">

    <h3>Top 10 Best-Selling Products</h3>

    <div class="analytics-table-wrap">

        <table class="analytics-table">

            <thead>
                <tr>
                    <th>Product</th>
                    <th>Units Sold</th>
                    <th>Revenue</th>
                    <th>Profit</th>
                </tr>
            </thead>

            <tbody>

                <?php if (!$bestProducts): ?>

                    <tr>
                        <td colspan="4" class="analytics-empty">
                            No product sales found.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($bestProducts as $product): ?>

                        <tr>
                            <td><?= analyticsEscape($product['product_name']) ?></td>
                            <td><?= number_format((int)$product['units_sold']) ?></td>
                            <td>
                                KSh <?= number_format((float)$product['revenue'], 2) ?>
                            </td>
                            <td>
                                KSh <?= number_format((float)$product['profit'], 2) ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>

<?php require_once 'includes/footer.php'; ?>
