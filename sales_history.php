
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
$isCashier = ($role === 'cashier');

$pageTitle = 'Sales History';
$activePage = 'sales_history';

function historyEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function historyValidDate(string $value, string $fallback): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return ($date && $date->format('Y-m-d') === $value)
        ? $value
        : $fallback;
}

$startDate = historyValidDate(
    $_GET['start_date'] ?? date('Y-m-01'),
    date('Y-m-01')
);

$endDate = historyValidDate(
    $_GET['end_date'] ?? date('Y-m-d'),
    date('Y-m-d')
);

if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$search = trim($_GET['search'] ?? '');
$paymentMethod = $_GET['payment_method'] ?? '';

if (!in_array($paymentMethod, ['cash', 'mpesa'], true)) {
    $paymentMethod = '';
}

/* Build sales history filters */
$where = "
    WHERE s.sale_date >= ?
    AND s.sale_date < DATE_ADD(?, INTERVAL 1 DAY)
";

$params = [$startDate, $endDate];

if ($isCashier) {
    $where .= " AND s.user_id = ?";
    $params[] = $userId;
}

if ($search !== '') {
    $where .= "
        AND (
            s.receipt_number LIKE ?
            OR c.full_name LIKE ?
            OR u.full_name LIKE ?
        )
    ";

    $term = '%' . $search . '%';

    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($paymentMethod !== '') {
    $where .= " AND s.payment_method = ?";
    $params[] = $paymentMethod;
}

/* Fetch sales history */
$stmt = $pdo->prepare("
    SELECT
        s.id,
        s.receipt_number,
        s.sale_date,
        s.total_amount,
        s.amount_paid,
        s.balance,
        s.payment_method,
        s.mpesa_reference,
        c.full_name AS customer_name,
        u.full_name AS cashier_name
    FROM sales s
    LEFT JOIN customers c ON c.id = s.customer_id
    LEFT JOIN users u ON u.id = s.user_id
    $where
    ORDER BY s.sale_date DESC, s.id DESC
");

$stmt->execute($params);
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Calculate totals */
$totalSales = 0;
$totalPaid = 0;
$totalBalance = 0;

foreach ($sales as $sale) {
    $totalSales += (float)$sale['total_amount'];
    $totalPaid += (float)$sale['amount_paid'];
    $totalBalance += (float)$sale['balance'];
}

/* Export filtered sales to CSV / Excel */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    $filename = 'sales_history_' . $startDate . '_to_' . $endDate . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // Helps Microsoft Excel display text correctly.
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'Receipt Number',
        'Date',
        'Customer',
        'Cashier',
        'Payment Method',
        'M-Pesa Reference',
        'Total Amount (KSh)',
        'Amount Paid (KSh)',
        'Outstanding Balance (KSh)'
    ]);

    foreach ($sales as $sale) {

        $customerName = $sale['customer_name'] ?: 'Walk-in Customer';
        $cashierName = $sale['cashier_name'] ?? 'Unknown';

        // Protect spreadsheet text fields from formula execution.
        $safeText = static function ($value): string {
            $value = (string)$value;

            if ($value !== '' && preg_match('/^[=+@\\-\\t\\r]/', $value)) {
                return "'" . $value;
            }

            return $value;
        };

        fputcsv($output, [
            $safeText($sale['receipt_number']),
            $safeText($sale['sale_date']),
            $safeText($customerName),
            $safeText($cashierName),
            strtoupper((string)$sale['payment_method']),
            $safeText($sale['mpesa_reference'] ?: ''),
            number_format((float)$sale['total_amount'], 2, '.', ''),
            number_format((float)$sale['amount_paid'], 2, '.', ''),
            number_format((float)$sale['balance'], 2, '.', '')
        ]);
    }

    fclose($output);
    exit;
}

require_once 'includes/header.php';
?>

<style>
.history-hero {
    background: #172b4d;
    color: white;
    padding: 25px;
    border-radius: 12px;
    margin-bottom: 22px;
}

.history-hero h2 {
    margin: 0 0 8px;
    font-size: 25px;
}

.history-hero p {
    margin: 0;
    color: #dbeafe;
}

.history-summary {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 22px;
}

.history-card,
.history-panel {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
}

.history-card span {
    display: block;
    color: #64748b;
    font-size: 14px;
    margin-bottom: 10px;
}

.history-card strong {
    color: #172b4d;
    font-size: 23px;
}

.history-panel {
    margin-bottom: 22px;
}

.history-panel h3 {
    color: #172b4d;
    margin-top: 0;
    margin-bottom: 18px;
}

.history-filters {
    display: grid;
    grid-template-columns: repeat(5, minmax(130px, 1fr));
    gap: 14px;
    align-items: end;
}

.history-field label {
    display: block;
    color: #334155;
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 7px;
}

.history-field input,
.history-field select {
    width: 100%;
    box-sizing: border-box;
    padding: 11px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    background: white;
}

.history-button {
    display: inline-block;
    border: none;
    background: #2563eb;
    color: white;
    padding: 11px 16px;
    border-radius: 7px;
    text-decoration: none;
    cursor: pointer;
    font-weight: 600;
    text-align: center;
}

.history-button:hover {
    background: #1d4ed8;
}

.history-button.secondary {
    background: #64748b;
}

.history-table-wrap {
    overflow-x: auto;
}

.history-table {
    width: 100%;
    min-width: 1150px;
    border-collapse: collapse;
}

.history-table th,
.history-table td {
    padding: 12px 10px;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
    font-size: 13px;
}

.history-table th {
    background: #f1f5f9;
    color: #172b4d;
    white-space: nowrap;
}

.history-table td {
    color: #334155;
}

.payment-label {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}

.payment-cash {
    background: #dcfce7;
    color: #166534;
}

.payment-mpesa {
    background: #dbeafe;
    color: #1e40af;
}

.balance-due {
    color: #dc2626 !important;
    font-weight: 700;
}

.balance-clear {
    color: #15803d !important;
    font-weight: 700;
}

.history-empty {
    text-align: center !important;
    padding: 30px !important;
    color: #64748b !important;
}

.history-count {
    color: #64748b;
    margin-bottom: 15px;
    font-size: 14px;
}

@media (max-width: 950px) {
    .history-filters {
        grid-template-columns: repeat(2, 1fr);
    }

    .history-summary {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 550px) {
    .history-filters {
        grid-template-columns: 1fr;
    }
}

@media print {
    .sidebar,
    .topbar,
    .history-filters,
    .history-button,
    .app-footer {
        display: none !important;
    }

    .app-main {
        margin: 0 !important;
    }
}
</style>

<div class="history-hero">
    <h2>Sales History</h2>
    <p>View and track recorded sales, customer payments and outstanding balances.</p>
</div>

<section class="history-summary">

    <div class="history-card">
        <span>Total Sales</span>
        <strong>
            KSh <?= number_format($totalSales, 2) ?>
        </strong>
    </div>

    <div class="history-card">
        <span>Total Amount Paid</span>
        <strong>
            KSh <?= number_format($totalPaid, 2) ?>
        </strong>
    </div>

    <div class="history-card">
        <span>Outstanding Balance</span>
        <strong>
            KSh <?= number_format($totalBalance, 2) ?>
        </strong>
    </div>

</section>

<section class="history-panel">

    <h3>Search and Filter Sales</h3>

    <form method="GET" action="sales_history.php"
          class="history-filters">

        <div class="history-field">
            <label for="start_date">From Date</label>
            <input type="date"
                   id="start_date"
                   name="start_date"
                   value="<?= historyEscape($startDate) ?>">
        </div>

        <div class="history-field">
            <label for="end_date">To Date</label>
            <input type="date"
                   id="end_date"
                   name="end_date"
                   value="<?= historyEscape($endDate) ?>">
        </div>

        <div class="history-field">
            <label for="search">Search</label>
            <input type="text"
                   id="search"
                   name="search"
                   placeholder="Receipt, customer or cashier"
                   value="<?= historyEscape($search) ?>">
        </div>

        <div class="history-field">
            <label for="payment_method">Payment Method</label>
            <select name="payment_method" id="payment_method">
                <option value="">All Methods</option>

                <option value="cash"
                    <?= $paymentMethod === 'cash' ? 'selected' : '' ?>>
                    Cash
                </option>

                <option value="mpesa"
                    <?= $paymentMethod === 'mpesa' ? 'selected' : '' ?>>
                    M-Pesa
                </option>
            </select>
        </div>

        <div>
            <button type="submit" class="history-button">
                Search Sales
            </button>

            <a href="sales_history.php"
               class="history-button secondary">
                Clear
            </a>
        </div>

    </form>

</section>

<section class="history-panel">

    <h3>Recorded Sales Transactions</h3>

    <div class="history-count">
        <?= count($sales) ?> transaction(s) found.
    </div>

    <!-- CSV Export Button -->
    <div style="margin-bottom: 16px;">
        <a class="history-button"
           href="sales_history.php?<?= historyEscape(http_build_query([
               'start_date' => $startDate,
               'end_date' => $endDate,
               'search' => $search,
               'payment_method' => $paymentMethod,
               'export' => 'csv'
           ])) ?>">
            Export to CSV / Excel
        </a>
    </div>

    <div class="history-table-wrap">

        <table class="history-table">

            <thead>
                <tr>
                    <th>Receipt Number</th>
                    <th>Date</th>
                    <th>Customer</th>

                    <?php if (!$isCashier): ?>
                        <th>Cashier</th>
                    <?php endif; ?>

                    <th>Payment Method</th>
                    <th>M-Pesa Reference</th>
                    <th>Total (KSh)</th>
                    <th>Amount Paid (KSh)</th>
                    <th>Balance (KSh)</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>

                <?php if (!$sales): ?>

                    <tr>
                        <td colspan="<?= $isCashier ? 9 : 10 ?>"
                            class="history-empty">
                            No sales transactions found.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($sales as $sale): ?>

                        <tr>

                            <td>
                                <?= historyEscape($sale['receipt_number']) ?>
                            </td>

                            <td>
                                <?= historyEscape($sale['sale_date']) ?>
                            </td>

                            <td>
                                <?= historyEscape(
                                    $sale['customer_name'] ?: 'Walk-in Customer'
                                ) ?>
                            </td>

                            <?php if (!$isCashier): ?>
                                <td>
                                    <?= historyEscape(
                                        $sale['cashier_name'] ?? 'Unknown'
                                    ) ?>
                                </td>
                            <?php endif; ?>

                            <td>
                                <?php if ($sale['payment_method'] === 'mpesa'): ?>
                                    <span class="payment-label payment-mpesa">
                                        M-Pesa
                                    </span>
                                <?php else: ?>
                                    <span class="payment-label payment-cash">
                                        Cash
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= historyEscape(
                                    $sale['mpesa_reference'] ?: '-'
                                ) ?>
                            </td>

                            <td>
                                <?= number_format(
                                    (float)$sale['total_amount'], 2
                                ) ?>
                            </td>

                            <td>
                                <?= number_format(
                                    (float)$sale['amount_paid'], 2
                                ) ?>
                            </td>

                            <td class="<?= (float)$sale['balance'] > 0
                                ? 'balance-due'
                                : 'balance-clear' ?>">

                                <?= number_format(
                                    (float)$sale['balance'], 2
                                ) ?>

                            </td>

                            <td>
                                <a class="history-button"
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

    <div style="margin-top: 18px;">
        <button type="button"
                class="history-button secondary"
                onclick="window.print()">
            Print Sales History
        </button>
    </div>

</section>

<?php require_once 'includes/footer.php'; ?>
