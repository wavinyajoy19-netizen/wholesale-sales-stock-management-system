
<?php
require_once 'includes/auth.php';
requireRole(['admin', 'accountant']);

require_once 'includes/db.php';

$pageTitle = 'Supplier Payments';
$activePage = 'supplier_payments';

$message = '';
$error = '';

function paymentEscape($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| Record supplier payment
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {

    $orderId = filter_var($_POST['purchase_order_id'] ?? '', FILTER_VALIDATE_INT);
    $amount = trim($_POST['amount_paid'] ?? '');
    $method = trim($_POST['payment_method'] ?? '');
    $reference = trim($_POST['transaction_reference'] ?? '');
    $paymentDate = trim($_POST['payment_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    try {

        if (!$orderId || $orderId < 1) {
            throw new RuntimeException('Please select a purchase order.');
        }

        if (!is_numeric($amount) || (float)$amount <= 0) {
            throw new RuntimeException('Enter a valid payment amount.');
        }

        if (!in_array($method, ['Cash', 'M-Pesa'], true)) {
            throw new RuntimeException('Please select a valid payment method.');
        }

        if ($method === 'M-Pesa' && $reference === '') {
            throw new RuntimeException('Enter the M-Pesa transaction reference.');
        }

        if ($paymentDate === '') {
            throw new RuntimeException('Please select the payment date.');
        }

        $dateObject = DateTime::createFromFormat('Y-m-d', $paymentDate);

        if (!$dateObject || $dateObject->format('Y-m-d') !== $paymentDate) {
            throw new RuntimeException('Enter a valid payment date.');
        }

        if (strlen($reference) > 100) {
            throw new RuntimeException('Transaction reference is too long.');
        }

        if (strlen($notes) > 5000) {
            throw new RuntimeException('Payment notes are too long.');
        }

        $amount = round((float)$amount, 2);

        $pdo->beginTransaction();

        // Lock the purchase order to prevent simultaneous overpayments.
        $stmt = $pdo->prepare("
            SELECT id, order_number, total_amount, status
            FROM purchase_orders
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->execute([$orderId]);

        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new RuntimeException('The selected purchase order was not found.');
        }

        if ($order['status'] === 'Cancelled') {
            throw new RuntimeException('Payments cannot be recorded against a cancelled order.');
        }

        // Calculate payments already made.
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount_paid), 0)
            FROM supplier_payments
            WHERE purchase_order_id = ?
        ");
        $stmt->execute([$orderId]);

        $amountAlreadyPaid = (float)$stmt->fetchColumn();

        $orderAmount = (float)$order['total_amount'];
        $balance = round($orderAmount - $amountAlreadyPaid, 2);

        if ($balance <= 0) {
            throw new RuntimeException('This purchase order has already been fully paid.');
        }

        if ($amount > $balance + 0.001) {
            throw new RuntimeException(
                'Payment exceeds the outstanding balance of KSh ' .
                number_format($balance, 2) . '.'
            );
        }

        // Save payment.
        $stmt = $pdo->prepare("
            INSERT INTO supplier_payments (
                purchase_order_id,
                amount_paid,
                payment_method,
                transaction_reference,
                payment_date,
                notes,
                user_id
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $orderId,
            $amount,
            $method,
            $reference !== '' ? $reference : null,
            $paymentDate,
            $notes !== '' ? $notes : null,
            $_SESSION['user_id']
        ]);

        if (function_exists('logActivity')) {
            logActivity(
                'Supplier payment recorded',
                'Payment of KSh ' . number_format($amount, 2) .
                ' recorded for purchase order ' . $order['order_number']
            );
        }

        $pdo->commit();

        header('Location: supplier_payments.php?order_id=' . $orderId . '&success=1');
        exit;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e instanceof RuntimeException) {
            $error = $e->getMessage();
        } else {
            error_log('Supplier payment error: ' . $e->getMessage());
            $error = 'Unable to record the payment. Please check your details and try again.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Success message
|--------------------------------------------------------------------------
*/

if (isset($_GET['success']) && $_GET['success'] === '1') {
    $message = 'Supplier payment recorded successfully.';
}

$selectedOrderId = isset($_GET['order_id'])
    ? (int)$_GET['order_id']
    : (int)($_POST['purchase_order_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Load purchase orders and payment balances
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        po.id,
        po.order_number,
        po.order_date,
        po.total_amount,
        po.status,
        s.supplier_name,
        COALESCE(
            (SELECT SUM(sp.amount_paid)
             FROM supplier_payments sp
             WHERE sp.purchase_order_id = po.id),
            0
        ) AS amount_paid
    FROM purchase_orders po
    INNER JOIN suppliers s ON s.id = po.supplier_id
    WHERE po.status != 'Cancelled'
    ORDER BY po.id DESC
");

$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Load selected purchase order
|--------------------------------------------------------------------------
*/

$selectedOrder = null;
$payments = [];

if ($selectedOrderId > 0) {

    $stmt = $pdo->prepare("
        SELECT
            po.*,
            s.supplier_name,
            COALESCE(
                (SELECT SUM(sp.amount_paid)
                 FROM supplier_payments sp
                 WHERE sp.purchase_order_id = po.id),
                0
            ) AS amount_paid
        FROM purchase_orders po
        INNER JOIN suppliers s ON s.id = po.supplier_id
        WHERE po.id = ?
    ");

    $stmt->execute([$selectedOrderId]);
    $selectedOrder = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedOrder) {

        $stmt = $pdo->prepare("
            SELECT
                sp.*,
                u.full_name
            FROM supplier_payments sp
            INNER JOIN users u ON u.id = sp.user_id
            WHERE sp.purchase_order_id = ?
            ORDER BY sp.payment_date DESC, sp.id DESC
        ");

        $stmt->execute([$selectedOrderId]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

require_once 'includes/header.php';
?>

<style>
.supplier-payments-page {
    padding: 25px;
    background: #ffffff;
}

.supplier-payments-page .page-heading {
    color: #102a56;
    margin-bottom: 22px;
}

.supplier-payments-page .payment-card {
    background: #ffffff;
    border: 1px solid #dce3ed;
    border-radius: 10px;
    padding: 22px;
    margin-bottom: 22px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.supplier-payments-page h3 {
    color: #102a56;
    margin-top: 0;
    margin-bottom: 18px;
}

.supplier-payments-page .form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.supplier-payments-page .form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.supplier-payments-page label {
    color: #102a56;
    font-weight: 600;
}

.supplier-payments-page input,
.supplier-payments-page select,
.supplier-payments-page textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    background: #ffffff;
    color: #1e293b;
    box-sizing: border-box;
}

.supplier-payments-page input:focus,
.supplier-payments-page select:focus,
.supplier-payments-page textarea:focus {
    outline: none;
    border-color: #2563eb;
}

.supplier-payments-page .full-width {
    grid-column: 1 / -1;
}

.supplier-payments-page .primary-btn {
    background: #2563eb;
    color: #ffffff;
    border: none;
    padding: 12px 22px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
}

.supplier-payments-page .primary-btn:hover {
    background: #1d4ed8;
}

.supplier-payments-page .message {
    background: #dcfce7;
    color: #166534;
    padding: 13px;
    border-radius: 6px;
    margin-bottom: 18px;
}

.supplier-payments-page .error {
    background: #fee2e2;
    color: #991b1b;
    padding: 13px;
    border-radius: 6px;
    margin-bottom: 18px;
}

.supplier-payments-page .summary-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.supplier-payments-page .summary-box {
    background: #f1f5f9;
    padding: 17px;
    border-radius: 8px;
}

.supplier-payments-page .summary-box span {
    display: block;
    color: #475569;
    margin-bottom: 8px;
}

.supplier-payments-page .summary-box strong {
    color: #102a56;
    font-size: 19px;
}

.supplier-payments-page .table-wrapper {
    overflow-x: auto;
}

.supplier-payments-page table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}

.supplier-payments-page th,
.supplier-payments-page td {
    padding: 12px;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
}

.supplier-payments-page th {
    background: #102a56;
    color: #ffffff;
}

.supplier-payments-page .order-link {
    color: #2563eb;
    font-weight: 600;
    text-decoration: none;
}

.supplier-payments-page .order-link:hover {
    text-decoration: underline;
}

.supplier-payments-page .balance {
    color: #b45309;
    font-weight: 700;
}

.supplier-payments-page .paid {
    color: #15803d;
    font-weight: 700;
}

@media (max-width: 700px) {
    .supplier-payments-page .form-grid,
    .supplier-payments-page .summary-grid {
        grid-template-columns: 1fr;
    }

    .supplier-payments-page {
        padding: 15px;
    }
}
</style>

<div class="supplier-payments-page">

    <h2 class="page-heading">Supplier Payment Management</h2>

    <?php if ($message): ?>
        <div class="message">
            <?php echo paymentEscape($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error">
            <?php echo paymentEscape($error); ?>
        </div>
    <?php endif; ?>

    <div class="payment-card">

        <h3>Purchase Orders and Outstanding Balances</h3>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Order Number</th>
                        <th>Supplier</th>
                        <th>Order Date</th>
                        <th>Order Amount</th>
                        <th>Amount Paid</th>
                        <th>Balance</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($orders)): ?>

                    <tr>
                        <td colspan="7">No purchase orders available.</td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($orders as $order): ?>

                        <?php
                        $balance = round(
                            (float)$order['total_amount'] -
                            (float)$order['amount_paid'],
                            2
                        );
                        ?>

                        <tr>
                            <td><?php echo paymentEscape($order['order_number']); ?></td>

                            <td><?php echo paymentEscape($order['supplier_name']); ?></td>

                            <td><?php echo paymentEscape($order['order_date']); ?></td>

                            <td>KSh <?php echo number_format((float)$order['total_amount'], 2); ?></td>

                            <td>KSh <?php echo number_format((float)$order['amount_paid'], 2); ?></td>

                            <td class="<?php echo $balance <= 0 ? 'paid' : 'balance'; ?>">
                                KSh <?php echo number_format(max(0, $balance), 2); ?>
                            </td>

                            <td>
                                <a
                                    class="order-link"
                                    href="supplier_payments.php?order_id=<?php echo (int)$order['id']; ?>"
                                >
                                    View / Pay
                                </a>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>
        </div>

    </div>

    <?php if ($selectedOrder): ?>

        <?php
        $orderAmount = (float)$selectedOrder['total_amount'];
        $amountPaid = (float)$selectedOrder['amount_paid'];
        $outstanding = round($orderAmount - $amountPaid, 2);
        ?>

        <div class="payment-card">

            <h3>Selected Purchase Order</h3>

            <p>
                <strong>Order Number:</strong>
                <?php echo paymentEscape($selectedOrder['order_number']); ?>
            </p>

            <p>
                <strong>Supplier:</strong>
                <?php echo paymentEscape($selectedOrder['supplier_name']); ?>
            </p>

            <div class="summary-grid">

                <div class="summary-box">
                    <span>Order Amount</span>
                    <strong>KSh <?php echo number_format($orderAmount, 2); ?></strong>
                </div>

                <div class="summary-box">
                    <span>Total Paid</span>
                    <strong>KSh <?php echo number_format($amountPaid, 2); ?></strong>
                </div>

                <div class="summary-box">
                    <span>Outstanding Balance</span>
                    <strong>KSh <?php echo number_format(max(0, $outstanding), 2); ?></strong>
                </div>

            </div>

            <?php if ($outstanding > 0 && $selectedOrder['status'] !== 'Cancelled'): ?>

                <h3>Record Supplier Payment</h3>

                <form method="POST" action="supplier_payments.php?order_id=<?php echo (int)$selectedOrder['id']; ?>">

                    <input
                        type="hidden"
                        name="purchase_order_id"
                        value="<?php echo (int)$selectedOrder['id']; ?>"
                    >

                    <div class="form-grid">

                        <div class="form-group">
                            <label>Amount Paid (KSh)</label>
                            <input
                                type="number"
                                name="amount_paid"
                                min="0.01"
                                max="<?php echo paymentEscape(number_format($outstanding, 2, '.', '')); ?>"
                                step="0.01"
                                placeholder="Enter amount paid"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label>Payment Method</label>
                            <select name="payment_method" id="payment_method" required>
                                <option value="">Select payment method</option>
                                <option value="Cash">Cash</option>
                                <option value="M-Pesa">M-Pesa</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>M-Pesa Transaction Reference</label>
                            <input
                                type="text"
                                name="transaction_reference"
                                id="transaction_reference"
                                maxlength="100"
                                placeholder="Enter M-Pesa code if applicable"
                            >
                        </div>

                        <div class="form-group">
                            <label>Payment Date</label>
                            <input
                                type="date"
                                name="payment_date"
                                value="<?php echo date('Y-m-d'); ?>"
                                required
                            >
                        </div>

                        <div class="form-group full-width">
                            <label>Payment Notes</label>
                            <textarea
                                name="notes"
                                rows="3"
                                placeholder="Optional payment details"
                            ></textarea>
                        </div>

                        <div class="form-group full-width">
                            <button
                                type="submit"
                                name="record_payment"
                                class="primary-btn"
                            >
                                Record Payment
                            </button>
                        </div>

                    </div>

                </form>

            <?php else: ?>

                <p class="paid">
                    This purchase order has been fully paid or is not available for payment.
                </p>

            <?php endif; ?>

        </div>

        <div class="payment-card">

            <h3>Payment History</h3>

            <div class="table-wrapper">

                <table>

                    <thead>
                        <tr>
                            <th>Payment Date</th>
                            <th>Amount Paid</th>
                            <th>Method</th>
                            <th>Transaction Reference</th>
                            <th>Recorded By</th>
                            <th>Notes</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($payments)): ?>

                        <tr>
                            <td colspan="6">No payments have been recorded for this order.</td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($payments as $payment): ?>

                            <tr>
                                <td><?php echo paymentEscape($payment['payment_date']); ?></td>

                                <td>
                                    KSh <?php echo number_format((float)$payment['amount_paid'], 2); ?>
                                </td>

                                <td><?php echo paymentEscape($payment['payment_method']); ?></td>

                                <td><?php echo paymentEscape($payment['transaction_reference'] ?: '-'); ?></td>

                                <td><?php echo paymentEscape($payment['full_name']); ?></td>

                                <td><?php echo paymentEscape($payment['notes'] ?: '-'); ?></td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    <?php endif; ?>

</div>

<script>
const paymentMethod = document.getElementById('payment_method');
const transactionReference = document.getElementById('transaction_reference');

if (paymentMethod && transactionReference) {
    paymentMethod.addEventListener('change', function () {
        transactionReference.required = this.value === 'M-Pesa';
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
