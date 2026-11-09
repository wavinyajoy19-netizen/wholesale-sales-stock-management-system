
<?php
require_once 'includes/auth.php';
requireRole(['admin', 'accountant']);

require_once 'includes/db.php';

$pageTitle = 'Supplier Payment Reports';
$activePage = 'supplier_payment_reports';

function reportEscape($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$error = '';

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$supplierId = $_GET['supplier_id'] ?? '';

/*
|--------------------------------------------------------------------------
| Validate filters
|--------------------------------------------------------------------------
*/

foreach (['startDate', 'endDate'] as $dateVariable) {
    $dateValue = $$dateVariable;
    $dateObject = DateTime::createFromFormat('Y-m-d', $dateValue);

    if (
        !$dateObject ||
        $dateObject->format('Y-m-d') !== $dateValue
    ) {
        $error = 'Please select valid report dates.';
    }
}

if ($startDate > $endDate) {
    $error = 'The start date cannot be later than the end date.';
}

if ($supplierId !== '' && (
    !ctype_digit((string)$supplierId) ||
    (int)$supplierId < 1
)) {
    $error = 'Please select a valid supplier.';
}

$supplierId = $supplierId === '' ? '' : (int)$supplierId;

$summary = [
    'order_count' => 0,
    'total_purchases' => 0,
    'total_paid' => 0,
    'outstanding' => 0
];

$paymentSummary = [
    'cash_total' => 0,
    'mpesa_total' => 0,
    'payment_count' => 0,
    'period_total' => 0
];

$orders = [];
$payments = [];
$suppliers = [];

try {

    /*
    |--------------------------------------------------------------------------
    | Load suppliers for filter
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->query("
        SELECT id, supplier_name
        FROM suppliers
        ORDER BY supplier_name ASC
    ");

    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$error) {

        /*
        |--------------------------------------------------------------------------
        | Purchase orders summary
        |--------------------------------------------------------------------------
        | Purchases are filtered using order date.
        | Payments and balances are calculated using all payments made
        | against those purchase orders.
        */

        $orderSql = "
            SELECT
                po.id,
                po.order_number,
                po.order_date,
                po.total_amount,
                po.status,
                s.supplier_name,
                COALESCE(
                    (
                        SELECT SUM(sp.amount_paid)
                        FROM supplier_payments sp
                        WHERE sp.purchase_order_id = po.id
                    ), 0
                ) AS amount_paid

            FROM purchase_orders po

            INNER JOIN suppliers s
                ON s.id = po.supplier_id

            WHERE po.order_date BETWEEN ? AND ?
              AND po.status != 'Cancelled'
        ";

        $orderParams = [$startDate, $endDate];

        if ($supplierId !== '') {
            $orderSql .= " AND po.supplier_id = ?";
            $orderParams[] = $supplierId;
        }

        $orderSql .= " ORDER BY po.order_date DESC, po.id DESC";

        $stmt = $pdo->prepare($orderSql);
        $stmt->execute($orderParams);

        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($orders as $order) {

            $orderAmount = (float)$order['total_amount'];
            $amountPaid = (float)$order['amount_paid'];

            $summary['order_count']++;
            $summary['total_purchases'] += $orderAmount;
            $summary['total_paid'] += $amountPaid;
            $summary['outstanding'] += max(0, $orderAmount - $amountPaid);
        }

        /*
        |--------------------------------------------------------------------------
        | Payments summary
        |--------------------------------------------------------------------------
        | Payments are filtered using payment date.
        */

        $paymentSql = "
            SELECT
                COUNT(sp.id) AS payment_count,

                COALESCE(
                    SUM(sp.amount_paid), 0
                ) AS period_total,

                COALESCE(
                    SUM(
                        CASE
                            WHEN sp.payment_method = 'Cash'
                            THEN sp.amount_paid
                            ELSE 0
                        END
                    ), 0
                ) AS cash_total,

                COALESCE(
                    SUM(
                        CASE
                            WHEN sp.payment_method = 'M-Pesa'
                            THEN sp.amount_paid
                            ELSE 0
                        END
                    ), 0
                ) AS mpesa_total

            FROM supplier_payments sp

            INNER JOIN purchase_orders po
                ON po.id = sp.purchase_order_id

            WHERE sp.payment_date BETWEEN ? AND ?
        ";

        $paymentParams = [$startDate, $endDate];

        if ($supplierId !== '') {
            $paymentSql .= " AND po.supplier_id = ?";
            $paymentParams[] = $supplierId;
        }

        $stmt = $pdo->prepare($paymentSql);
        $stmt->execute($paymentParams);

        $paymentSummary = $stmt->fetch(PDO::FETCH_ASSOC);

        /*
        |--------------------------------------------------------------------------
        | Detailed payment history
        |--------------------------------------------------------------------------
        */

        $historySql = "
            SELECT
                sp.id,
                sp.payment_date,
                sp.amount_paid,
                sp.payment_method,
                sp.transaction_reference,
                sp.notes,
                po.order_number,
                s.supplier_name,
                u.full_name

            FROM supplier_payments sp

            INNER JOIN purchase_orders po
                ON po.id = sp.purchase_order_id

            INNER JOIN suppliers s
                ON s.id = po.supplier_id

            INNER JOIN users u
                ON u.id = sp.user_id

            WHERE sp.payment_date BETWEEN ? AND ?
        ";

        $historyParams = [$startDate, $endDate];

        if ($supplierId !== '') {
            $historySql .= " AND po.supplier_id = ?";
            $historyParams[] = $supplierId;
        }

        $historySql .= "
            ORDER BY sp.payment_date DESC, sp.id DESC
        ";

        $stmt = $pdo->prepare($historySql);
        $stmt->execute($historyParams);

        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {

    error_log('Supplier payment report error: ' . $e->getMessage());

    $error = 'Unable to load supplier payment reports. Please check your database and try again.';
}

require_once 'includes/header.php';
?>

<style>
.supplier-report-page {
    padding: 25px;
    background: #ffffff;
}

.supplier-report-page .page-heading {
    color: #102a56;
    margin-bottom: 22px;
}

.supplier-report-page .report-card {
    background: #ffffff;
    border: 1px solid #dce3ed;
    border-radius: 10px;
    padding: 22px;
    margin-bottom: 22px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.supplier-report-page h3 {
    color: #102a56;
    margin-top: 0;
    margin-bottom: 18px;
}

.supplier-report-page .filter-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18px;
    align-items: end;
}

.supplier-report-page .form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.supplier-report-page label {
    color: #102a56;
    font-weight: 600;
}

.supplier-report-page input,
.supplier-report-page select {
    width: 100%;
    padding: 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    background: #ffffff;
    box-sizing: border-box;
}

.supplier-report-page .primary-btn {
    background: #2563eb;
    color: #ffffff;
    border: none;
    padding: 12px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
}

.supplier-report-page .primary-btn:hover {
    background: #1d4ed8;
}

.supplier-report-page .summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 15px;
    margin-bottom: 22px;
}

.supplier-report-page .summary-box {
    background: #f1f5f9;
    padding: 18px;
    border-radius: 8px;
}

.supplier-report-page .summary-box span {
    display: block;
    color: #475569;
    margin-bottom: 10px;
}

.supplier-report-page .summary-box strong {
    display: block;
    color: #102a56;
    font-size: 19px;
}

.supplier-report-page .method-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 15px;
    margin-bottom: 22px;
}

.supplier-report-page .table-wrapper {
    overflow-x: auto;
}

.supplier-report-page table {
    width: 100%;
    border-collapse: collapse;
    min-width: 750px;
}

.supplier-report-page th,
.supplier-report-page td {
    padding: 12px;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
}

.supplier-report-page th {
    background: #102a56;
    color: #ffffff;
}

.supplier-report-page .balance {
    color: #b45309;
    font-weight: 700;
}

.supplier-report-page .paid {
    color: #15803d;
    font-weight: 700;
}

.supplier-report-page .error {
    background: #fee2e2;
    color: #991b1b;
    padding: 13px;
    border-radius: 6px;
    margin-bottom: 18px;
}

.supplier-report-page .report-note {
    color: #64748b;
    font-size: 13px;
    margin-top: 10px;
}

.supplier-report-page .print-btn {
    background: #102a56;
    color: #ffffff;
    border: none;
    padding: 11px 18px;
    border-radius: 6px;
    cursor: pointer;
    margin-bottom: 18px;
}

@media (max-width: 900px) {
    .supplier-report-page .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .supplier-report-page .filter-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 600px) {
    .supplier-report-page .summary-grid,
    .supplier-report-page .method-grid,
    .supplier-report-page .filter-grid {
        grid-template-columns: 1fr;
    }

    .supplier-report-page {
        padding: 15px;
    }
}

@media print {
    .supplier-report-page .filter-card,
    .supplier-report-page .print-btn,
    .app-sidebar,
    .app-header,
    .sidebar {
        display: none !important;
    }

    .supplier-report-page {
        padding: 0;
    }

    .supplier-report-page .report-card {
        box-shadow: none;
        break-inside: avoid;
    }
}
</style>

<div class="supplier-report-page">

    <h2 class="page-heading">Supplier Payment Reports</h2>

    <?php if ($error): ?>

        <div class="error">
            <?php echo reportEscape($error); ?>
        </div>

    <?php else: ?>

        <!-- REPORT FILTERS -->

        <div class="report-card filter-card">

            <h3>Filter Reports</h3>

            <form method="GET" action="supplier_payment_reports.php">

                <div class="filter-grid">

                    <div class="form-group">
                        <label>Start Date</label>
                        <input
                            type="date"
                            name="start_date"
                            value="<?php echo reportEscape($startDate); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>End Date</label>
                        <input
                            type="date"
                            name="end_date"
                            value="<?php echo reportEscape($endDate); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Supplier</label>

                        <select name="supplier_id">

                            <option value="">All Suppliers</option>

                            <?php foreach ($suppliers as $supplier): ?>

                                <option
                                    value="<?php echo (int)$supplier['id']; ?>"
                                    <?php echo ((string)$supplierId === (string)$supplier['id']) ? 'selected' : ''; ?>
                                >
                                    <?php echo reportEscape($supplier['supplier_name']); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="primary-btn">
                            Generate Report
                        </button>
                    </div>

                </div>

            </form>

        </div>

        <!-- PURCHASE SUMMARY -->

        <div class="report-card">

            <h3>Purchase and Outstanding Summary</h3>

            <div class="summary-grid">

                <div class="summary-box">
                    <span>Purchase Orders</span>
                    <strong>
                        <?php echo number_format($summary['order_count']); ?>
                    </strong>
                </div>

                <div class="summary-box">
                    <span>Total Purchases</span>
                    <strong>
                        KSh <?php echo number_format($summary['total_purchases'], 2); ?>
                    </strong>
                </div>

                <div class="summary-box">
                    <span>Total Paid to Date</span>
                    <strong>
                        KSh <?php echo number_format($summary['total_paid'], 2); ?>
                    </strong>
                </div>

                <div class="summary-box">
                    <span>Outstanding Balance</span>
                    <strong class="balance">
                        KSh <?php echo number_format($summary['outstanding'], 2); ?>
                    </strong>
                </div>

            </div>

            <p class="report-note">
                Purchase totals are based on purchase order dates.
                Amount paid and outstanding balances include all payments
                made against the purchase orders in this report.
            </p>

        </div>

        <!-- PAYMENT METHOD SUMMARY -->

        <div class="report-card">

            <h3>Payments by Method</h3>

            <div class="method-grid">

                <div class="summary-box">
                    <span>Cash Payments</span>
                    <strong>
                        KSh <?php echo number_format((float)$paymentSummary['cash_total'], 2); ?>
                    </strong>
                </div>

                <div class="summary-box">
                    <span>M-Pesa Payments</span>
                    <strong>
                        KSh <?php echo number_format((float)$paymentSummary['mpesa_total'], 2); ?>
                    </strong>
                </div>

                <div class="summary-box">
                    <span>Payments in Selected Period</span>
                    <strong>
                        KSh <?php echo number_format((float)$paymentSummary['period_total'], 2); ?>
                    </strong>
                </div>

            </div>

            <p class="report-note">
                Payment method totals are based on payment dates within
                the selected reporting period.
                Number of payments:
                <?php echo number_format((int)$paymentSummary['payment_count']); ?>
            </p>

        </div>

        <!-- PURCHASE ORDER DETAILS -->

        <div class="report-card">

            <h3>Purchase Order Details</h3>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Order Number</th>
                            <th>Supplier</th>
                            <th>Order Date</th>
                            <th>Order Amount</th>
                            <th>Paid to Date</th>
                            <th>Outstanding</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($orders)): ?>

                        <tr>
                            <td colspan="7">No purchase orders found for the selected filters.</td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($orders as $order): ?>

                            <?php
                            $balance = max(
                                0,
                                (float)$order['total_amount'] -
                                (float)$order['amount_paid']
                            );
                            ?>

                            <tr>

                                <td><?php echo reportEscape($order['order_number']); ?></td>

                                <td><?php echo reportEscape($order['supplier_name']); ?></td>

                                <td><?php echo reportEscape($order['order_date']); ?></td>

                                <td>
                                    KSh <?php echo number_format((float)$order['total_amount'], 2); ?>
                                </td>

                                <td>
                                    KSh <?php echo number_format((float)$order['amount_paid'], 2); ?>
                                </td>

                                <td class="<?php echo $balance <= 0 ? 'paid' : 'balance'; ?>">
                                    KSh <?php echo number_format($balance, 2); ?>
                                </td>

                                <td><?php echo reportEscape($order['status']); ?></td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

        <!-- PAYMENT HISTORY -->

        <div class="report-card">

            <h3>Supplier Payment History</h3>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Payment Date</th>
                            <th>Supplier</th>
                            <th>Order Number</th>
                            <th>Amount Paid</th>
                            <th>Method</th>
                            <th>Transaction Reference</th>
                            <th>Recorded By</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($payments)): ?>

                        <tr>
                            <td colspan="7">No supplier payments found for the selected period.</td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($payments as $payment): ?>

                            <tr>

                                <td><?php echo reportEscape($payment['payment_date']); ?></td>

                                <td><?php echo reportEscape($payment['supplier_name']); ?></td>

                                <td><?php echo reportEscape($payment['order_number']); ?></td>

                                <td>
                                    KSh <?php echo number_format((float)$payment['amount_paid'], 2); ?>
                                </td>

                                <td><?php echo reportEscape($payment['payment_method']); ?></td>

                                <td>
                                    <?php echo reportEscape($payment['transaction_reference'] ?: '-'); ?>
                                </td>

                                <td><?php echo reportEscape($payment['full_name']); ?></td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

        <button
            type="button"
            class="print-btn"
            onclick="window.print()"
        >
            Print Supplier Payment Report
        </button>

    <?php endif; ?>

</div>

<?php require_once 'includes/footer.php'; ?>
