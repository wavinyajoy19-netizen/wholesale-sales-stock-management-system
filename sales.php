
<?php
require_once 'includes/auth.php';
requireLogin();

$role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($role, ['admin', 'cashier'], true)) {
    http_response_code(403);
    exit('Access denied. Only administrators and cashiers can record sales.');
}

require_once 'includes/db.php';

$error = '';

$selectedCustomer = $_POST['customer_id'] ?? '';
$selectedPayment = $_POST['payment_method'] ?? 'cash';
$selectedAmountPaid = $_POST['amount_paid'] ?? '';
$selectedMpesaReference = trim($_POST['mpesa_reference'] ?? '');

$selectedLines = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedProducts = $_POST['product_id'] ?? [];
    $postedQuantities = $_POST['quantity'] ?? [];

    if (is_array($postedProducts) && is_array($postedQuantities)) {
        foreach ($postedProducts as $index => $productId) {
            $selectedLines[] = [
                'product_id' => $productId,
                'quantity' => $postedQuantities[$index] ?? 1
            ];
        }
    }
}

if (count($selectedLines) === 0) {
    $selectedLines = [['product_id' => '', 'quantity' => 1]];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerInput = trim((string)($_POST['customer_id'] ?? ''));
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    $amountPaidInput = trim((string)($_POST['amount_paid'] ?? ''));
    $mpesaReference = strtoupper(trim((string)($_POST['mpesa_reference'] ?? '')));

    $isWalkIn = ($customerInput === 'walk-in');
    $customerId = null;

    if (!$isWalkIn) {
        $customerId = filter_var($customerInput, FILTER_VALIDATE_INT);
    }

    $validatedLines = [];
    $seenProducts = [];

    if (!$isWalkIn && ($customerId === false || !$customerId || $customerId < 1)) {
        $error = 'Please select a registered customer or Walk-in Customer.';
    } elseif (!in_array($paymentMethod, ['cash', 'mpesa'], true)) {
        $error = 'Please select a valid payment method.';
    } elseif ($amountPaidInput === '' || !is_numeric($amountPaidInput)) {
        $error = 'Please enter a valid amount paid.';
    } elseif (!is_finite((float)$amountPaidInput) || (float)$amountPaidInput < 0) {
        $error = 'Amount paid cannot be negative.';
    } elseif ($paymentMethod === 'mpesa' && $mpesaReference === '') {
        $error = 'Please enter the M-Pesa transaction reference.';
    } elseif (
        $paymentMethod === 'mpesa' &&
        !preg_match('/^[A-Z0-9]{6,20}$/', $mpesaReference)
    ) {
        $error = 'Enter a valid M-Pesa transaction reference (6–20 letters or numbers).';
    } elseif (count($selectedLines) === 0) {
        $error = 'Please add at least one product.';
    } else {
        foreach ($selectedLines as $line) {
            $productId = filter_var($line['product_id'], FILTER_VALIDATE_INT);
            $quantity = filter_var($line['quantity'], FILTER_VALIDATE_INT);

            if (
                $productId === false || !$productId ||
                $quantity === false || !$quantity || $quantity < 1
            ) {
                $error = 'Please select a product and enter a valid quantity for every row.';
                break;
            }

            if (isset($seenProducts[$productId])) {
                $error = 'A product has been selected more than once. Please combine its quantity into one row.';
                break;
            }

            $seenProducts[$productId] = true;

            $validatedLines[] = [
                'product_id' => $productId,
                'quantity' => $quantity
            ];
        }
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();

            // Handle registered customers and walk-in customers.
            if ($isWalkIn) {
                $customerType = 'walk-in';
            } else {
                $stmt = $pdo->prepare(
                    "SELECT id, full_name, customer_type
                     FROM customers
                     WHERE id = ?
                     FOR UPDATE"
                );

                $stmt->execute([$customerId]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$customer) {
                    throw new RuntimeException('The selected customer was not found.');
                }

                $customerType = strtolower(trim((string)$customer['customer_type']));
            }

            $total = 0;
            $saleItems = [];

            // Lock products in sorted order to reduce deadlock risk.
            $productIds = array_column($validatedLines, 'product_id');
            sort($productIds, SORT_NUMERIC);

            $lockedProducts = [];

            foreach ($productIds as $productId) {
                $stmt = $pdo->prepare(
                    "SELECT id, product_name, product_code, quantity,
                            buying_price, selling_price,
                            retailer_price, trader_price
                     FROM products
                     WHERE id = ?
                     FOR UPDATE"
                );

                $stmt->execute([$productId]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$product) {
                    throw new RuntimeException('One of the selected products was not found.');
                }

                $lockedProducts[$productId] = $product;
            }

            // Calculate prices and check stock.
            foreach ($validatedLines as $line) {
                $productId = $line['product_id'];
                $quantity = $line['quantity'];
                $product = $lockedProducts[$productId];

                $availableStock = (int)$product['quantity'];

                if ($availableStock < $quantity) {
                    throw new RuntimeException(
                        $product['product_name'] .
                        ': Insufficient stock. Available quantity: ' .
                        $availableStock
                    );
                }

                if ($customerType === 'retailer') {
                    $unitPrice = (float)$product['retailer_price'];
                } elseif ($customerType === 'trader') {
                    $unitPrice = (float)$product['trader_price'];
                } else {
                    $unitPrice = (float)$product['selling_price'];
                }

                if (!is_finite($unitPrice) || $unitPrice <= 0) {
                    throw new RuntimeException(
                        'The selling price for ' . $product['product_name'] .
                        ' must be greater than zero.'
                    );
                }

                $buyingPrice = (float)$product['buying_price'];
                $subtotal = round($unitPrice * $quantity, 2);

                $total += $subtotal;

                $saleItems[] = [
                    'product_id' => $productId,
                    'product_name' => $product['product_name'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                    'buying_price' => $buyingPrice
                ];
            }

            $total = round($total, 2);
            $amountPaid = round((float)$amountPaidInput, 2);

            if ($amountPaid > $total) {
                throw new RuntimeException(
                    'Amount paid cannot be greater than the total sale amount.'
                );
            }

            $balance = round($total - $amountPaid, 2);

            $savedMpesaReference = ($paymentMethod === 'mpesa')
                ? $mpesaReference
                : null;

            // Generate receipt number.
            $receiptNumber = 'RCP-' . date('YmdHis') . '-' .
                strtoupper(bin2hex(random_bytes(3)));

            // Save sale.
            $stmt = $pdo->prepare(
                "INSERT INTO sales
                    (receipt_number, user_id, total_amount,
                     customer_id, payment_method, mpesa_reference,
                     amount_paid, balance)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $stmt->execute([
                $receiptNumber,
                $_SESSION['user_id'],
                $total,
                $customerId,
                $paymentMethod,
                $savedMpesaReference,
                $amountPaid,
                $balance
            ]);

            $saleId = (int)$pdo->lastInsertId();

            // Prepare sale item and stock statements.
            $itemStmt = $pdo->prepare(
                "INSERT INTO sale_items
                    (sale_id, product_id, quantity, unit_price,
                     subtotal, buying_price_at_sale)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            $stockStmt = $pdo->prepare(
                "UPDATE products
                 SET quantity = quantity - ?
                 WHERE id = ? AND quantity >= ?"
            );

            $movementStmt = $pdo->prepare(
                "INSERT INTO stock_movements
                    (product_id, movement_type, quantity, notes, user_id)
                 VALUES (?, 'stock_out', ?, ?, ?)"
            );

            // Save items and deduct stock.
            foreach ($saleItems as $item) {
                $itemStmt->execute([
                    $saleId,
                    $item['product_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['subtotal'],
                    $item['buying_price']
                ]);

                $stockStmt->execute([
                    $item['quantity'],
                    $item['product_id'],
                    $item['quantity']
                ]);

                if ($stockStmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Stock could not be updated for ' .
                        $item['product_name'] . '. Please try again.'
                    );
                }

                $movementStmt->execute([
                    $item['product_id'],
                    $item['quantity'],
                    'Sale receipt: ' . $receiptNumber,
                    $_SESSION['user_id']
                ]);
            }

            $pdo->commit();

            header('Location: receipt.php?id=' . $saleId);
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException) {
                $error = $e->getMessage();
            } else {
                error_log('Sales error: ' . $e->getMessage());
                $error = 'The sale could not be completed. Please check your database setup and try again.';
            }
        }
    }
}

// Load products.
$stmt = $pdo->query(
    "SELECT id, product_name, product_code, quantity,
            selling_price, retailer_price, trader_price
     FROM products
     ORDER BY product_name ASC"
);

$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load customers.
$stmt = $pdo->query(
    "SELECT id, full_name, phone, customer_type
     FROM customers
     ORDER BY full_name ASC"
);

$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

function salesEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'Record Sales';
require_once 'includes/header.php';
?>

<style>
/* Main sales page */
.sales-page {
    max-width: 1200px;
    margin: 0 auto;
    padding-bottom: 35px;
}

.sales-heading {
    background: #102b50;
    color: #ffffff;
    padding: 26px 30px;
    border-radius: 12px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    flex-wrap: wrap;
}

.sales-heading h2 {
    color: #ffffff;
    margin: 0 0 8px;
    font-size: 25px;
}

.sales-heading p {
    color: #dce7f5;
    margin: 0;
    font-size: 14px;
}

.sales-heading .sales-user {
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.2);
    padding: 10px 16px;
    border-radius: 8px;
    font-size: 13px;
}

/* Sections */
.sales-section {
    background: #ffffff;
    border: 1px solid #dce3ed;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 22px;
    box-shadow: 0 3px 12px rgba(16,43,80,0.04);
}

.sales-section-title {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 22px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e5eaf1;
}

.sales-section-title .section-number {
    width: 35px;
    height: 35px;
    border-radius: 9px;
    background: #eaf1fb;
    color: #174b86;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    flex-shrink: 0;
}

.sales-section-title h3 {
    color: #102b50;
    margin: 0;
    font-size: 18px;
}

.sales-section-title p {
    margin: 4px 0 0;
    color: #718096;
    font-size: 13px;
}

/* Separate field boxes */
.sales-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}

.sales-field {
    background: #ffffff;
    border: 1px solid #dce3ed;
    border-radius: 9px;
    padding: 15px;
    min-width: 0;
}

.sales-field label {
    display: block;
    color: #102b50;
    font-weight: 700;
    font-size: 13px;
    margin-bottom: 10px;
}

.sales-field input,
.sales-field select {
    width: 100%;
    min-height: 44px;
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    background: #ffffff;
    color: #263445;
    font-size: 14px;
    outline: none;
}

.sales-field input:focus,
.sales-field select:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.10);
}

.sales-field .form-hint {
    color: #718096;
    font-size: 12px;
    margin: 9px 0 0;
    line-height: 1.5;
}

.customer-field {
    max-width: 100%;
}

/* Product rows */
.product-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 180px 95px;
    gap: 15px;
    align-items: end;
    padding: 17px;
    border: 1px solid #dce3ed;
    border-radius: 10px;
    margin-bottom: 14px;
    background: #fbfcfe;
}

.product-row .sales-field {
    margin: 0;
}

.product-row .remove-product {
    margin-bottom: 0;
    min-height: 44px;
}

/* Buttons */
.sales-btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    gap: 7px;
    min-height: 42px;
    padding: 10px 18px;
    border: none;
    border-radius: 7px;
    background: #2563eb;
    color: #ffffff !important;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none !important;
    cursor: pointer;
    transition: background 0.2s;
}

.sales-btn:hover {
    background: #174bba;
}

.sales-btn-secondary {
    background: #eaf1fb;
    color: #174b86 !important;
    border: 1px solid #cbd8eb;
}

.sales-btn-secondary:hover {
    background: #dce8f8;
}

.sales-btn-danger {
    background: #fff0f0;
    color: #c62828 !important;
    border: 1px solid #f3cccc;
}

.sales-btn-danger:hover {
    background: #ffe0e0;
}

.sales-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.manage-customer-link {
    margin-top: 12px;
}

/* Payment section */
.payment-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}

#mpesa_reference_group {
    display: none;
}

/* Summary */
.sale-summary {
    background: #102b50;
    color: #ffffff;
    border-radius: 12px;
    padding: 23px;
    margin-top: 22px;
}

.sale-summary h3 {
    color: #ffffff;
    font-size: 18px;
    margin: 0 0 17px;
}

.sale-summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    padding: 11px 0;
    border-bottom: 1px solid rgba(255,255,255,0.15);
    color: #e3ebf6;
    font-size: 14px;
}

.sale-summary-row strong {
    color: #ffffff;
}

.sale-summary-total {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    padding-top: 18px;
    margin-top: 8px;
    font-size: 16px;
    font-weight: 700;
    color: #ffffff;
}

.sale-summary-total strong {
    font-size: 23px;
    color: #ffffff;
}

/* Form actions */
.sales-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 22px;
}

.sales-alert {
    padding: 14px 17px;
    border-radius: 8px;
    margin-bottom: 20px;
    background: #fff0f0;
    color: #a32121;
    border: 1px solid #f0caca;
}

/* Responsive */
@media (max-width: 700px) {
    .sales-grid,
    .payment-grid {
        grid-template-columns: 1fr;
    }

    .product-row {
        grid-template-columns: 1fr;
    }

    .sales-heading {
        padding: 20px;
    }

    .sales-section {
        padding: 17px;
    }

    .sales-actions {
        flex-direction: column;
    }

    .sales-actions .sales-btn {
        width: 100%;
    }
}
</style>

<div class="sales-page">

    <section class="sales-heading">
        <div>
            <h2>Record New Sale</h2>
            <p>Create a customer sale, record payment and update stock automatically.</p>
        </div>

        <div class="sales-user">
            Logged in as: <strong><?= salesEscape(ucfirst($role)) ?></strong>
        </div>
    </section>

    <?php if ($error !== ''): ?>
        <div class="sales-alert">
            <strong>Sale Error:</strong>
            <?= salesEscape($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="sales.php" class="app-form">

        <!-- CUSTOMER SECTION -->
        <section class="sales-section">

            <div class="sales-section-title">
                <div class="section-number">01</div>
                <div>
                    <h3>Customer Information</h3>
                    <p>Select the customer making the purchase.</p>
                </div>
            </div>

            <div class="sales-grid">
                <div class="sales-field customer-field">
                    <label for="customer_id">Select Customer</label>

                    <select name="customer_id" id="customer_id" required>
                        <option value="">-- Choose Customer --</option>

                        <option
                            value="walk-in"
                            data-type="walk-in"
                            <?= (string)$selectedCustomer === 'walk-in' ? 'selected' : '' ?>
                        >
                            Walk-in Customer (No registration required)
                        </option>

                        <?php foreach ($customers as $customer): ?>
                            <option
                                value="<?= (int)$customer['id'] ?>"
                                data-type="<?= salesEscape(strtolower($customer['customer_type'])) ?>"
                                <?= (string)$selectedCustomer === (string)$customer['id'] ? 'selected' : '' ?>
                            >
                                <?= salesEscape($customer['full_name']) ?>
                                (<?= salesEscape($customer['customer_type']) ?>)
                                <?= !empty($customer['phone']) ? ' - ' . salesEscape($customer['phone']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <p class="form-hint">
                        Choose a registered customer or select Walk-in Customer for an unregistered buyer.
                    </p>

                    <a href="customers.php" class="sales-btn sales-btn-secondary manage-customer-link">
                        + Manage Customers
                    </a>
                </div>
            </div>

        </section>

        <!-- PRODUCTS SECTION -->
        <section class="sales-section">

            <div class="sales-section-title">
                <div class="section-number">02</div>
                <div>
                    <h3>Products and Quantities</h3>
                    <p>Select the products being purchased and enter their quantities.</p>
                </div>
            </div>

            <div id="product_rows">

                <?php foreach ($selectedLines as $line): ?>
                    <div class="product-row">

                        <div class="sales-field">
                            <label>Product Name</label>

                            <select name="product_id[]" class="product-select" required>
                                <option value="">-- Select Product --</option>

                                <?php foreach ($products as $product): ?>
                                    <option
                                        value="<?= (int)$product['id'] ?>"
                                        data-stock="<?= (int)$product['quantity'] ?>"
                                        data-selling="<?= (float)$product['selling_price'] ?>"
                                        data-retailer="<?= (float)$product['retailer_price'] ?>"
                                        data-trader="<?= (float)$product['trader_price'] ?>"
                                        <?= (string)$line['product_id'] === (string)$product['id'] ? 'selected' : '' ?>
                                    >
                                        <?= salesEscape($product['product_name']) ?>
                                        (<?= salesEscape($product['product_code']) ?>)
                                        - Stock: <?= (int)$product['quantity'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sales-field">
                            <label>Units / Quantity</label>

                            <input
                                type="number"
                                name="quantity[]"
                                class="quantity-input"
                                min="1"
                                step="1"
                                value="<?= salesEscape($line['quantity']) ?>"
                                required
                            >
                        </div>

                        <button type="button" class="sales-btn sales-btn-danger remove-product">
                            Remove
                        </button>

                    </div>
                <?php endforeach; ?>

            </div>

            <button type="button" id="add_product" class="sales-btn sales-btn-secondary">
                + Add Another Product
            </button>

        </section>

        <!-- PAYMENT SECTION -->
        <section class="sales-section">

            <div class="sales-section-title">
                <div class="section-number">03</div>
                <div>
                    <h3>Payment Information</h3>
                    <p>Choose the payment method and record the amount received.</p>
                </div>
            </div>

            <div class="payment-grid">

                <div class="sales-field">
                    <label for="payment_method">Payment Method</label>

                    <select name="payment_method" id="payment_method" required>
                        <option value="cash" <?= $selectedPayment === 'cash' ? 'selected' : '' ?>>
                            Cash
                        </option>

                        <option value="mpesa" <?= $selectedPayment === 'mpesa' ? 'selected' : '' ?>>
                            M-Pesa
                        </option>
                    </select>

                    <p class="form-hint">
                        Select how the customer has paid.
                    </p>
                </div>

                <div class="sales-field">
                    <label for="amount_paid">Amount Paid (KSh)</label>

                    <input
                        type="number"
                        name="amount_paid"
                        id="amount_paid"
                        min="0"
                        step="0.01"
                        value="<?= salesEscape($selectedAmountPaid) ?>"
                        placeholder="Enter amount received"
                        required
                    >

                    <p class="form-hint">
                        Enter the full payment or a smaller amount for partial payment.
                    </p>
                </div>

                <div class="sales-field" id="mpesa_reference_group">
                    <label for="mpesa_reference">M-Pesa Transaction Reference</label>

                    <input
                        type="text"
                        name="mpesa_reference"
                        id="mpesa_reference"
                        maxlength="20"
                        minlength="6"
                        pattern="[A-Za-z0-9]{6,20}"
                        value="<?= salesEscape($selectedMpesaReference) ?>"
                        placeholder="Example: QWE123ABC"
                        autocomplete="off"
                    >

                    <p class="form-hint">
                        Enter the transaction code from the M-Pesa confirmation message.
                    </p>
                </div>

            </div>

        </section>

        <!-- SALE SUMMARY -->
        <section class="sale-summary">

            <h3>Sale Summary</h3>

            <div class="sale-summary-row">
                <span>Number of products</span>
                <strong id="product_count">0</strong>
            </div>

            <div class="sale-summary-row">
                <span>Total quantity</span>
                <strong id="total_quantity">0</strong>
            </div>

            <div class="sale-summary-row">
                <span>Total sale amount</span>
                <strong id="total_amount">KSh 0.00</strong>
            </div>

            <div class="sale-summary-row">
                <span>Amount paid</span>
                <strong id="preview_paid">KSh 0.00</strong>
            </div>

            <div class="sale-summary-total">
                <span>Outstanding Balance</span>
                <strong id="preview_balance">KSh 0.00</strong>
            </div>

        </section>

        <!-- ACTION BUTTONS -->
        <div class="sales-actions">

            <a href="dashboard.php" class="sales-btn sales-btn-secondary">
                Cancel
            </a>

            <button
                type="submit"
                class="sales-btn"
                <?= count($products) === 0 ? 'disabled' : '' ?>
            >
                Complete Sale and Generate Receipt
            </button>

        </div>

    </form>

</div>

<!-- PRODUCT TEMPLATE FOR ADDING EXTRA ROWS -->
<template id="product_template">

    <div class="product-row">

        <div class="sales-field">
            <label>Product Name</label>

            <select name="product_id[]" class="product-select" required>
                <option value="">-- Select Product --</option>

                <?php foreach ($products as $product): ?>
                    <option
                        value="<?= (int)$product['id'] ?>"
                        data-stock="<?= (int)$product['quantity'] ?>"
                        data-selling="<?= (float)$product['selling_price'] ?>"
                        data-retailer="<?= (float)$product['retailer_price'] ?>"
                        data-trader="<?= (float)$product['trader_price'] ?>"
                    >
                        <?= salesEscape($product['product_name']) ?>
                        (<?= salesEscape($product['product_code']) ?>)
                        - Stock: <?= (int)$product['quantity'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="sales-field">
            <label>Units / Quantity</label>

            <input
                type="number"
                name="quantity[]"
                class="quantity-input"
                min="1"
                step="1"
                value="1"
                required
            >
        </div>

        <button type="button" class="sales-btn sales-btn-danger remove-product">
            Remove
        </button>

    </div>

</template>

<script>
const customerSelect = document.getElementById('customer_id');
const paymentSelect = document.getElementById('payment_method');
const amountPaidInput = document.getElementById('amount_paid');

const mpesaGroup = document.getElementById('mpesa_reference_group');
const mpesaReferenceInput = document.getElementById('mpesa_reference');

const productRows = document.getElementById('product_rows');
const productTemplate = document.getElementById('product_template');
const addProductButton = document.getElementById('add_product');

const productCountDisplay = document.getElementById('product_count');
const totalQuantityDisplay = document.getElementById('total_quantity');
const totalDisplay = document.getElementById('total_amount');
const paidDisplay = document.getElementById('preview_paid');
const balanceDisplay = document.getElementById('preview_balance');

function money(value) {
    return 'KSh ' + value.toLocaleString('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function updatePaymentFields() {
    const isMpesa = paymentSelect.value === 'mpesa';

    mpesaGroup.style.display = isMpesa ? 'block' : 'none';
    mpesaReferenceInput.required = isMpesa;

    if (!isMpesa) {
        mpesaReferenceInput.value = '';
    }
}

function updateSalePreview() {
    const customerOption = customerSelect.options[customerSelect.selectedIndex];
    const customerType = customerOption ? customerOption.dataset.type : '';

    let total = 0;
    let totalQuantity = 0;
    let productCount = 0;

    document.querySelectorAll('.product-row').forEach(row => {
        const productSelect = row.querySelector('.product-select');
        const quantityInput = row.querySelector('.quantity-input');

        const option = productSelect.options[productSelect.selectedIndex];

        if (!option || !option.value) {
            return;
        }

        let price = 0;

        if (customerType === 'retailer') {
            price = Number(option.dataset.retailer || 0);
        } else if (customerType === 'trader') {
            price = Number(option.dataset.trader || 0);
        } else {
            price = Number(option.dataset.selling || 0);
        }

        const quantity = Number(quantityInput.value || 0);

        total += price * quantity;
        totalQuantity += quantity;
        productCount++;
    });

    total = Math.round(total * 100) / 100;

    const paid = Number(amountPaidInput.value || 0);
    const balance = Math.round((total - paid) * 100) / 100;

    productCountDisplay.textContent = productCount;
    totalQuantityDisplay.textContent = totalQuantity;
    totalDisplay.textContent = money(total);
    paidDisplay.textContent = money(paid);
    balanceDisplay.textContent = money(Math.max(0, balance));

    amountPaidInput.max = total.toFixed(2);
}

function addProductRow() {
    const newRow = productTemplate.content.cloneNode(true);
    productRows.appendChild(newRow);
    updateSalePreview();
}

addProductButton.addEventListener('click', addProductRow);

productRows.addEventListener('change', function(event) {
    if (
        event.target.classList.contains('product-select') ||
        event.target.classList.contains('quantity-input')
    ) {
        updateSalePreview();
    }
});

productRows.addEventListener('input', function(event) {
    if (event.target.classList.contains('quantity-input')) {
        updateSalePreview();
    }
});

productRows.addEventListener('click', function(event) {
    if (event.target.classList.contains('remove-product')) {
        const rows = productRows.querySelectorAll('.product-row');

        if (rows.length > 1) {
            event.target.closest('.product-row').remove();
            updateSalePreview();
        } else {
            alert('At least one product row is required.');
        }
    }
});

customerSelect.addEventListener('change', updateSalePreview);
amountPaidInput.addEventListener('input', updateSalePreview);
paymentSelect.addEventListener('change', updatePaymentFields);

updatePaymentFields();
updateSalePreview();
</script>

<?php require_once 'includes/footer.php'; ?>
