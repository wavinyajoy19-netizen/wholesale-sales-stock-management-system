
<?php
require_once 'includes/auth.php';
requireLogin();

requireRole(['admin', 'accountant', 'cashier']);

require_once 'includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role = $_SESSION['role'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);

$isAdmin = ($role === 'admin');
$isAccountant = ($role === 'accountant');
$isCashier = ($role === 'cashier');

$canViewFinancials = ($isAdmin || $isAccountant);

$search = trim($_GET['search'] ?? '');
$filterPayment = $_GET['payment_method'] ?? '';

$pageTitle = 'Sales and Financial Reports';
$activePage = 'reports';

function reportEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function reportValidDate(string $value, string $fallback): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return ($date && $date->format('Y-m-d') === $value)
        ? $value
        : $fallback;
}

$startDate = reportValidDate(
    $_GET['start_date'] ?? date('Y-m-01'),
    date('Y-m-01')
);

$endDate = reportValidDate(
    $_GET['end_date'] ?? date('Y-m-d'),
    date('Y-m-d')
);

if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

if (!in_array($filterPayment, ['cash', 'mpesa'], true)) {
    $filterPayment = '';
}

/*
 * Cashiers can only see their own sales.
 */
$salesCondition = '';
$salesParams = [$startDate, $endDate];

if ($isCashier) {
    $salesCondition = ' AND user_id = ?';
    $salesParams[] = $userId;
}

/*
 * Sales summary.
 */
$stmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS transaction_count,
        COALESCE(SUM(total_amount), 0) AS total_revenue
     FROM sales
     WHERE sale_date >= ?
       AND sale_date < DATE_ADD(?, INTERVAL 1 DAY)
       $salesCondition"
);

$stmt->execute($salesParams);
$salesSummary = $stmt->fetch(PDO::FETCH_ASSOC);

$totalRevenue = (float)($salesSummary['total_revenue'] ?? 0);
$transactionCount = (int)($salesSummary['transaction_count'] ?? 0);

/*
 * Financial information: Admin and Accountant only.
 */
$estimatedCost = 0;
$estimatedGrossProfit = 0;
$totalExpenses = 0;
$expenseCount = 0;
$estimatedNetProfit = 0;
$expenseCategories = [];

if ($canViewFinancials) {

    /*
     * Use the buying price saved at the time of sale.
     * Fall back to the current product buying price for older records
     * that do not have a saved historical buying price.
     */
    $stmt = $pdo->prepare(
        "SELECT COALESCE(
            SUM(
                si.quantity * COALESCE(
                    NULLIF(si.buying_price_at_sale, 0),
                    p.buying_price,
                    0
                )
            ), 0
         )
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         LEFT JOIN products p ON p.id = si.product_id
         WHERE s.sale_date >= ?
           AND s.sale_date < DATE_ADD(?, INTERVAL 1 DAY)"
    );

    $stmt->execute([$startDate, $endDate]);
    $estimatedCost = (float)$stmt->fetchColumn();

    $estimatedGrossProfit = $totalRevenue - $estimatedCost;

    /*
     * Expenses summary.
     */
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(amount), 0) AS total_expenses,
            COUNT(*) AS expense_count
         FROM expenses
         WHERE expense_date BETWEEN ? AND ?"
    );

    $stmt->execute([$startDate, $endDate]);
    $expenseSummary = $stmt->fetch(PDO::FETCH_ASSOC);

    $totalExpenses = (float)($expenseSummary['total_expenses'] ?? 0);
    $expenseCount = (int)($expenseSummary['expense_count'] ?? 0);

    $estimatedNetProfit = $estimatedGrossProfit - $totalExpenses;

    /*
     * Expenses by category.
     */
    $stmt = $pdo->prepare(
        "SELECT
            category,
            COALESCE(SUM(amount), 0) AS total
         FROM expenses
         WHERE expense_date BETWEEN ? AND ?
         GROUP BY category
         ORDER BY total DESC"
    );

    $stmt->execute([$startDate, $endDate]);
    $expenseCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
 * Daily sales report.
 */
$stmt = $pdo->prepare(
    "SELECT
        DATE(sale_date) AS report_day,
        COUNT(*) AS transaction_count,
        COALESCE(SUM(total_amount), 0) AS revenue
     FROM sales
     WHERE sale_date >= ?
       AND sale_date < DATE_ADD(?, INTERVAL 1 DAY)
       $salesCondition
     GROUP BY DATE(sale_date)
     ORDER BY report_day DESC"
);

$stmt->execute($salesParams);
$dailyReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
 * Detailed sales transaction history.
 * Search by receipt number, customer name or cashier name.
 * Filter by Cash or M-Pesa.
 */
$historyCondition = '';
$historyParams = [$startDate, $endDate];

if ($isCashier) {
    $historyCondition .= ' AND s.user_id = ?';
    $historyParams[] = $userId;
}

if ($search !== '') {
    $historyCondition .= "
        AND (
            s.receipt_number LIKE ?
            OR c.full_name LIKE ?
            OR u.full_name LIKE ?
        )";

    $searchTerm = '%' . $search . '%';

    $historyParams[] = $searchTerm;
    $historyParams[] = $searchTerm;
    $historyParams[] = $searchTerm;
}

if ($filterPayment !== '') {
    $historyCondition .= ' AND s.payment_method = ?';
    $historyParams[] = $filterPayment;
}

$stmt = $pdo->prepare(
    "SELECT
        s.id,
        s.receipt_number,
        s.sale_date,
        s.total_amount,
        s.payment_method,
        c.full_name AS customer_name,
        u.full_name AS cashier_name,
        COUNT(si.id) AS item_lines,
        COALESCE(SUM(si.quantity), 0) AS units_sold
     FROM sales s
     LEFT JOIN users u ON u.id = s.user_id
     LEFT JOIN customers c ON c.id = s.customer_id
     LEFT JOIN sale_items si ON si.sale_id = s.id
     WHERE s.sale_date >= ?
       AND s.sale_date < DATE_ADD(?, INTERVAL 1 DAY)
       $historyCondition
     GROUP BY
        s.id,
        s.receipt_number,
        s.sale_date,
        s.total_amount,
        s.payment_method,
        c.full_name,
        u.full_name
     ORDER BY s.sale_date DESC, s.id DESC"
);

$stmt->execute($historyParams);
$salesRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
 * CSV export.
 */
if (($_GET['export'] ?? '') === 'csv') {

    $filename = 'sales_report_' . $startDate . '_to_' . $endDate . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, ['WHOLESALE SALES AND STOCK MANAGEMENT SYSTEM']);
    fputcsv($output, ['Sales Report']);
    fputcsv($output, ['Start Date', $startDate]);
    fputcsv($output, ['End Date', $endDate]);
    fputcsv($output, ['Search', $search]);
    fputcsv($output, ['Payment Method', $filterPayment ?: 'All']);

    if ($isCashier) {
        fputcsv($output, ['Cashier', $_SESSION['full_name'] ?? 'Cashier']);
    }

    fputcsv($output, []);

    fputcsv($output, [
        'Total Sales Revenue (KSh)',
        number_format($totalRevenue, 2, '.', '')
    ]);

    fputcsv($output, ['Transactions', $transactionCount]);

    if ($canViewFinancials) {
        fputcsv($output, [
            'Estimated Cost of Goods (KSh)',
            number_format($estimatedCost, 2, '.', '')
        ]);

        fputcsv($output, [
            'Estimated Gross Profit (KSh)',
            number_format($estimatedGrossProfit, 2, '.', '')
        ]);

        fputcsv($output, [
            'Total Expenses (KSh)',
            number_format($totalExpenses, 2, '.', '')
        ]);

        fputcsv($output, [
            'Estimated Net Profit (KSh)',
            number_format($estimatedNetProfit, 2, '.', '')
        ]);
    }

    fputcsv($output, []);

    fputcsv($output, [
        'Receipt Number',
        'Sale Date',
        'Customer',
        'Cashier',
        'Payment Method',
        'Item Lines',
        'Units Sold',
        'Total Amount (KSh)'
    ]);

    foreach ($salesRows as $sale) {
        fputcsv($output, [
            $sale['receipt_number'],
            $sale['sale_date'],
            $sale['customer_name'] ?? 'Walk-in',
            $sale['cashier_name'] ?? 'Unknown',
            strtoupper($sale['payment_method'] ?? 'Unknown'),
            $sale['item_lines'],
            $sale['units_sold'],
            number_format((float)$sale['total_amount'], 2, '.', '')
        ]);
    }

    if ($canViewFinancials) {
        fputcsv($output, []);
        fputcsv($output, ['Expense Category', 'Total (KSh)']);

        foreach ($expenseCategories as $category) {
            fputcsv($output, [
                $category['category'],
                number_format((float)$category['total'], 2, '.', '')
            ]);
        }
    }

    fclose($output);
    exit;
}

require_once 'includes/header.php';
?>

<style>
.report-filters,
.report-panel,
.report-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
}

.report-filters {
    margin-bottom: 22px;
}

.report-filters form {
    display: flex;
    align-items: end;
    gap: 14px;
    flex-wrap: wrap;
}

.report-filters label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    font-size: 14px;
}

.report-filters input,
.report-filters select {
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
}

.report-button {
    display: inline-block;
    padding: 10px 14px;
    border: none;
    border-radius: 7px;
    background: #2563eb;
    color: #fff;
    text-decoration: none;
    cursor: pointer;
    font-size: 14px;
}

.report-button.secondary {
    background: #64748b;
}

.report-button.green {
    background: #15803d;
}

.report-cards {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}

.report-card span {
    display: block;
    color: #64748b;
    font-size: 14px;
    margin-bottom: 9px;
}

.report-card strong {
    display: block;
    font-size: 24px;
    color: #172033;
    overflow-wrap: anywhere;
}

.report-card small {
    display: block;
    color: #64748b;
    margin-top: 8px;
}

.report-panel {
    margin-bottom: 22px;
}

.report-panel h2 {
    margin-top: 0;
    font-size: 19px;
}

.report-table-wrap {
    overflow-x: auto;
}

.report-table {
    width: 100%;
    min-width: 750px;
    border-collapse: collapse;
}

.report-table th,
.report-table td {
    padding: 12px 10px;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
}

.report-table th {
    background: #f8fafc;
    color: #475569;
    font-size: 13px;
}

.report-note {
    background: #eff6ff;
    color: #1e40af;
    padding: 12px 14px;
    border-radius: 8px;
    margin-bottom: 22px;
    font-size: 14px;
}

.report-positive {
    color: #15803d !important;
}

.report-negative {
    color: #dc2626 !important;
}

.report-empty {
    text-align: center !important;
    padding: 24px !important;
    color: #64748b;
}

.transaction-filters {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: 12px;
    margin-bottom: 18px;
}

.transaction-filters label {
    display: block;
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 6px;
}

.transaction-filters input,
.transaction-filters select {
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
}

@media (max-width: 850px) {
    .report-cards {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 550px) {
    .report-cards {
        grid-template-columns: 1fr;
    }

    .report-filters form > div,
    .transaction-filters > div {
        width: 100%;
    }

    .report-filters input,
    .report-filters select,
    .transaction-filters input,
    .transaction-filters select {
        width: 100%;
        box-sizing: border-box;
    }
}

@media print {
    .sidebar,
    .topbar,
    .report-filters,
    .transaction-filters,
    .app-footer {
        display: none !important;
    }

    .app-main {
        margin: 0 !important;
    }

    .report-panel,
    .report-card {
        break-inside: avoid;
        border: 1px solid #ddd;
    }

    body {
        background: white !important;
    }
}
</style>

<section class="report-filters">
    <h2>Filter Report Period</h2>

    <form method="GET" action="reports.php">
        <div>
            <label for="start_date">From Date</label>
            <input type="date"
                   id="start_date"
                   name="start_date"
                   value="<?= reportEscape($startDate) ?>"
                   required>
        </div>

        <div>
            <label for="end_date">To Date</label>
            <input type="date"
                   id="end_date"
                   name="end_date"
                   value="<?= reportEscape($endDate) ?>"
                   required>
        </div>

        <div>
            <button type="submit" class="report-button">
                Generate Report
            </button>
        </div>

        <div>
            <a class="report-button green"
               href="reports.php?start_date=<?= urlencode($startDate) ?>&amp;end_date=<?= urlencode($endDate) ?>&amp;search=<?= urlencode($search) ?>&amp;payment_method=<?= urlencode($filterPayment) ?>&amp;export=csv">
                Export CSV
            </a>
        </div>

        <div>
            <button type="button"
                    class="report-button secondary"
                    onclick="window.print()">
                Print Report
            </button>
        </div>
    </form>
</section>

<div class="report-note">
    Report period:
    <strong><?= reportEscape($startDate) ?></strong>
    to
    <strong><?= reportEscape($endDate) ?></strong>.

    <?php if ($isCashier): ?>
        This report displays only your own sales transactions.
    <?php else: ?>
        Profit figures use saved historical buying prices where available.
        Older records may use current buying prices.
    <?php endif; ?>
</div>

<section class="report-cards">
    <div class="report-card">
        <span><?= $isCashier ? 'Your Total Sales' : 'Total Sales Revenue' ?></span>
        <strong>KSh <?= number_format($totalRevenue, 2) ?></strong>
        <small>Sales recorded during the selected period</small>
    </div>

    <div class="report-card">
        <span><?= $isCashier ? 'Your Transactions' : 'Number of Transactions' ?></span>
        <strong><?= number_format($transactionCount) ?></strong>
        <small>Completed sales records</small>
    </div>

    <?php if ($canViewFinancials): ?>
        <div class="report-card">
            <span>Estimated Cost of Goods Sold</span>
            <strong>KSh <?= number_format($estimatedCost, 2) ?></strong>
            <small>Based on saved buying prices where available</small>
        </div>

        <div class="report-card">
            <span>Estimated Gross Profit</span>
            <strong class="<?= $estimatedGrossProfit < 0 ? 'report-negative' : 'report-positive' ?>">
                KSh <?= number_format($estimatedGrossProfit, 2) ?>
            </strong>
            <small>Revenue minus estimated cost of goods</small>
        </div>

        <div class="report-card">
            <span>Total Expenses</span>
            <strong>KSh <?= number_format($totalExpenses, 2) ?></strong>
            <small><?= number_format($expenseCount) ?> expense records</small>
        </div>

        <div class="report-card">
            <span>Estimated Net Profit</span>
            <strong class="<?= $estimatedNetProfit < 0 ? 'report-negative' : 'report-positive' ?>">
                KSh <?= number_format($estimatedNetProfit, 2) ?>
            </strong>
            <small>Gross profit minus recorded expenses</small>
        </div>
    <?php endif; ?>
</section>

<section class="report-panel">
    <h2><?= $isCashier ? 'Your Daily Sales Summary' : 'Daily Sales Summary' ?></h2>

    <div class="report-table-wrap">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Transactions</th>
                    <th>Revenue (KSh)</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$dailyReports): ?>
                    <tr>
                        <td colspan="3" class="report-empty">
                            No sales were recorded during this period.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($dailyReports as $day): ?>
                        <tr>
                            <td><?= reportEscape($day['report_day']) ?></td>
                            <td><?= number_format((int)$day['transaction_count']) ?></td>
                            <td><?= number_format((float)$day['revenue'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="report-panel">
    <h2><?= $isCashier ? 'Your Sales Transactions' : 'Sales Transactions' ?></h2>

    <form method="GET" action="reports.php" class="transaction-filters">

        <input type="hidden" name="start_date"
               value="<?= reportEscape($startDate) ?>">

        <input type="hidden" name="end_date"
               value="<?= reportEscape($endDate) ?>">

        <div>
            <label for="search">Search Transactions</label>
            <input type="text"
                   id="search"
                   name="search"
                   placeholder="Receipt, customer or cashier"
                   value="<?= reportEscape($search) ?>">
        </div>

        <div>
            <label for="payment_method">Payment Method</label>
            <select name="payment_method" id="payment_method">
                <option value="">All Payment Methods</option>
                <option value="cash" <?= $filterPayment === 'cash' ? 'selected' : '' ?>>
                    Cash
                </option>
                <option value="mpesa" <?= $filterPayment === 'mpesa' ? 'selected' : '' ?>>
                    M-Pesa
                </option>
            </select>
        </div>

        <div>
            <button type="submit" class="report-button">
                Search
            </button>
        </div>

        <div>
            <a class="report-button secondary"
               href="reports.php?start_date=<?= urlencode($startDate) ?>&amp;end_date=<?= urlencode($endDate) ?>">
                Clear Filters
            </a>
        </div>
    </form>

    <div class="report-table-wrap">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Receipt Number</th>
                    <th>Date</th>
                    <th>Customer</th>

                    <?php if (!$isCashier): ?>
                        <th>Cashier</th>
                    <?php endif; ?>

                    <th>Payment Method</th>
                    <th>Item Lines</th>
                    <th>Units Sold</th>
                    <th>Total (KSh)</th>
                    <th>Receipt</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$salesRows): ?>
                    <tr>
                        <td colspan="<?= $isCashier ? 8 : 9 ?>" class="report-empty">
                            No transactions found for these filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($salesRows as $sale): ?>
                        <tr>
                            <td><?= reportEscape($sale['receipt_number']) ?></td>

                            <td><?= reportEscape($sale['sale_date']) ?></td>

                            <td>
                                <?= reportEscape($sale['customer_name'] ?: 'Walk-in') ?>
                            </td>

                            <?php if (!$isCashier): ?>
                                <td>
                                    <?= reportEscape($sale['cashier_name'] ?? 'Unknown') ?>
                                </td>
                            <?php endif; ?>

                            <td>
                                <?= reportEscape(strtoupper($sale['payment_method'] ?? 'Unknown')) ?>
                            </td>

                            <td><?= number_format((int)$sale['item_lines']) ?></td>

                            <td><?= number_format((int)$sale['units_sold']) ?></td>

                            <td><?= number_format((float)$sale['total_amount'], 2) ?></td>

                            <td>
                                <a class="report-button"
                                   href="receipt.php?id=<?= (int)$sale['id'] ?>"
                                   target="_blank">
                                    View Receipt
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($canViewFinancials): ?>
    <section class="report-panel">
        <h2>Expenses by Category</h2>

        <div class="report-table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Total Expenses (KSh)</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$expenseCategories): ?>
                        <tr>
                            <td colspan="2" class="report-empty">
                                No expenses recorded during this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($expenseCategories as $category): ?>
                            <tr>
                                <td><?= reportEscape($category['category']) ?></td>
                                <td><?= number_format((float)$category['total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
