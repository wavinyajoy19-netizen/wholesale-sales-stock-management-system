
<?php
require_once 'includes/auth.php';
requireRole(['admin', 'storekeeper']);

require_once 'includes/db.php';

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| Helper function
|--------------------------------------------------------------------------
*/
function poEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| Load suppliers and products
|--------------------------------------------------------------------------
*/
$suppliers = $pdo->query("
    SELECT id, supplier_name
    FROM suppliers
    ORDER BY supplier_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$products = $pdo->query("
    SELECT id, product_name, product_code, buying_price, quantity
    FROM products
    ORDER BY product_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Create Purchase Order
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_order'])) {

    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $orderDate = $_POST['order_date'] ?? '';
    $expectedDate = $_POST['expected_delivery_date'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    $productIds = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity_ordered'] ?? [];
    $prices = $_POST['unit_buying_price'] ?? [];

    try {

        if ($supplierId <= 0) {
            throw new Exception('Please select a supplier.');
        }

        if (!DateTime::createFromFormat('Y-m-d', $orderDate) ||
            DateTime::createFromFormat('Y-m-d', $orderDate)->format('Y-m-d') !== $orderDate) {
            throw new Exception('Please enter a valid order date.');
        }

        if ($expectedDate !== '') {
            $expectedDateObject = DateTime::createFromFormat('Y-m-d', $expectedDate);

            if (!$expectedDateObject ||
                $expectedDateObject->format('Y-m-d') !== $expectedDate) {
                throw new Exception('Please enter a valid expected delivery date.');
            }
        }

        if (!is_array($productIds) || count($productIds) === 0) {
            throw new Exception('Please add at least one product.');
        }

        if (count($productIds) !== count($quantities) ||
            count($productIds) !== count($prices)) {
            throw new Exception('Please check the product details.');
        }

        // Confirm that the supplier exists.
        $supplierCheck = $pdo->prepare(
            "SELECT id FROM suppliers WHERE id = ? LIMIT 1"
        );
        $supplierCheck->execute([$supplierId]);

        if (!$supplierCheck->fetch()) {
            throw new Exception('The selected supplier does not exist.');
        }

        $orderItems = [];
        $totalAmount = 0;
        $usedProducts = [];

        foreach ($productIds as $index => $productId) {

            $productId = (int)$productId;
            $quantity = filter_var(
                $quantities[$index] ?? null,
                FILTER_VALIDATE_INT
            );

            $priceInput = trim((string)($prices[$index] ?? ''));

            if ($productId <= 0) {
                throw new Exception('Please select a product in every row.');
            }

            if ($quantity === false || $quantity <= 0) {
                throw new Exception('Order quantities must be greater than zero.');
            }

            if ($priceInput === '' || !is_numeric($priceInput) ||
                !is_finite((float)$priceInput) || (float)$priceInput < 0) {
                throw new Exception('Enter a valid buying price for every product.');
            }

            $price = round((float)$priceInput, 2);

            if (isset($usedProducts[$productId])) {
                throw new Exception(
                    'A product has been selected more than once. Please use one row per product.'
                );
            }

            $usedProducts[$productId] = true;

            $productCheck = $pdo->prepare(
                "SELECT id FROM products WHERE id = ? LIMIT 1"
            );
            $productCheck->execute([$productId]);

            if (!$productCheck->fetch()) {
                throw new Exception('One of the selected products does not exist.');
            }

            $subtotal = round($quantity * $price, 2);
            $totalAmount += $subtotal;

            $orderItems[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'price' => $price,
                'subtotal' => $subtotal
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Save order and its products in one transaction
        |--------------------------------------------------------------------------
        */
        $pdo->beginTransaction();

        $orderNumber = 'PO-' . date('Ymd') . '-' .
            strtoupper(bin2hex(random_bytes(3)));

        $insertOrder = $pdo->prepare("
            INSERT INTO purchase_orders
            (
                order_number,
                supplier_id,
                user_id,
                order_date,
                expected_delivery_date,
                total_amount,
                status,
                notes
            )
            VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?)
        ");

        $insertOrder->execute([
            $orderNumber,
            $supplierId,
            $_SESSION['user_id'],
            $orderDate,
            $expectedDate !== '' ? $expectedDate : null,
            round($totalAmount, 2),
            $notes !== '' ? $notes : null
        ]);

        $purchaseOrderId = (int)$pdo->lastInsertId();

        $insertItem = $pdo->prepare("
            INSERT INTO purchase_order_items
            (
                purchase_order_id,
                product_id,
                quantity_ordered,
                quantity_received,
                unit_buying_price,
                subtotal
            )
            VALUES (?, ?, ?, 0, ?, ?)
        ");

        foreach ($orderItems as $item) {
            $insertItem->execute([
                $purchaseOrderId,
                $item['product_id'],
                $item['quantity'],
                $item['price'],
                $item['subtotal']
            ]);
        }

        $pdo->commit();

        header('Location: purchase_orders.php?success=1');
        exit;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e instanceof PDOException && $e->getCode() === '23000') {
            $error = 'The purchase order could not be saved because of a database conflict. Please try again.';
        } else {
            $error = $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Success message
|--------------------------------------------------------------------------
*/
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $message = 'Purchase order created successfully. Its status is Pending.';
}

/*
|--------------------------------------------------------------------------
| Load existing purchase orders
|--------------------------------------------------------------------------
*/
$orders = $pdo->query("
    SELECT
        po.id,
        po.order_number,
        po.order_date,
        po.expected_delivery_date,
        po.total_amount,
        po.status,
        s.supplier_name,
        u.full_name
    FROM purchase_orders po
    INNER JOIN suppliers s ON po.supplier_id = s.id
    INNER JOIN users u ON po.user_id = u.id
    ORDER BY po.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<style>
.purchase-page {
    width: 100%;
    max-width: 1500px;
    margin: 0 auto;
    padding: 25px 30px;
    color: #263449;
}

.purchase-page .page-heading {
    color: #102b50;
    font-size: 27px;
    font-weight: 700;
    margin-bottom: 7px;
}

.purchase-page .page-description {
    color: #64748b;
    margin-bottom: 25px;
}

.purchase-page .purchase-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 3px 12px rgba(15, 35, 65, 0.05);
}

.purchase-page .section-heading {
    color: #102b50;
    font-size: 19px;
    font-weight: 700;
    margin-bottom: 20px;
}

.purchase-page .form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}

.purchase-page .field-box {
    display: flex;
    flex-direction: column;
    gap: 9px;
}

.purchase-page label {
    color: #102b50;
    font-weight: 600;
    font-size: 14px;
}

.purchase-page input,
.purchase-page select,
.purchase-page textarea {
    width: 100%;
    padding: 12px 13px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    background: #ffffff;
    color: #263449;
    font-size: 14px;
    outline: none;
    box-sizing: border-box;
}

.purchase-page input:focus,
.purchase-page select:focus,
.purchase-page textarea:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
}

.purchase-page textarea {
    min-height: 85px;
    resize: vertical;
}

.purchase-page .full-width {
    grid-column: 1 / -1;
}

.purchase-page .product-row {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
    gap: 13px;
    align-items: end;
    padding: 16px;
    border: 1px solid #dbe3ed;
    background: #f8fafc;
    border-radius: 8px;
    margin-bottom: 13px;
}

.purchase-page .product-field {
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
}

.purchase-page .btn {
    border: none;
    border-radius: 7px;
    padding: 11px 18px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
}

.purchase-page .btn-primary {
    background: #2563eb;
    color: #ffffff;
}

.purchase-page .btn-primary:hover {
    background: #1d4ed8;
}

.purchase-page .btn-secondary {
    background: #e2e8f0;
    color: #102b50;
}

.purchase-page .btn-danger {
    background: #dc2626;
    color: #ffffff;
    padding: 11px 13px;
}

.purchase-page .form-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 20px;
}

.purchase-page .total-display {
    margin-top: 15px;
    text-align: right;
    color: #102b50;
    font-size: 20px;
    font-weight: 700;
}

.purchase-page .message {
    padding: 13px 16px;
    border-radius: 7px;
    margin-bottom: 20px;
}

.purchase-page .success-message {
    background: #dcfce7;
    color: #166534;
}

.purchase-page .error-message {
    background: #fee2e2;
    color: #991b1b;
}

.purchase-page .table-wrapper {
    overflow-x: auto;
}

.purchase-page table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

.purchase-page th {
    background: #102b50;
    color: #ffffff;
    padding: 13px;
    text-align: left;
    font-size: 13px;
}

.purchase-page td {
    padding: 13px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 13px;
}

.purchase-page tr:hover td {
    background: #f8fafc;
}

.purchase-page .status {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}

.purchase-page .status-pending {
    background: #fef3c7;
    color: #92400e;
}

.purchase-page .status-partial {
    background: #dbeafe;
    color: #1e40af;
}

.purchase-page .status-received {
    background: #dcfce7;
    color: #166534;
}

.purchase-page .status-cancelled {
    background: #fee2e2;
    color: #991b1b;
}

.purchase-page .empty-message {
    text-align: center;
    padding: 25px;
    color: #64748b;
}

@media (max-width: 800px) {
    .purchase-page {
        padding: 18px 12px;
    }

    .purchase-page .form-grid {
        grid-template-columns: 1fr;
    }

    .purchase-page .product-row {
        grid-template-columns: 1fr 1fr;
    }

    .purchase-page .product-field:first-child {
        grid-column: 1 / -1;
    }

    .purchase-page .full-width {
        grid-column: auto;
    }
}

@media (max-width: 480px) {
    .purchase-page .product-row {
        grid-template-columns: 1fr;
    }

    .purchase-page .product-field:first-child {
        grid-column: auto;
    }
}
</style>

<div class="purchase-page">

    <h1 class="page-heading">Purchase Orders</h1>

    <p class="page-description">
        Create supplier orders and track goods expected for delivery.
    </p>

    <?php if ($message): ?>
        <div class="message success-message">
            <?= poEscape($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message error-message">
            <?= poEscape($error) ?>
        </div>
    <?php endif; ?>

    <div class="purchase-card">

        <h2 class="section-heading">Create New Purchase Order</h2>

        <?php if (empty($suppliers)): ?>

            <div class="message error-message">
                No suppliers have been registered. Please register a supplier first.
            </div>

        <?php elseif (empty($products)): ?>

            <div class="message error-message">
                No products are available. Please register products first.
            </div>

        <?php else: ?>

            <form method="POST" action="purchase_orders.php" id="purchaseOrderForm">

                <div class="form-grid">

                    <div class="field-box">
                        <label for="supplier_id">Select Supplier</label>

                        <select name="supplier_id" id="supplier_id" required>
                            <option value="">Choose a supplier</option>

                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?= (int)$supplier['id'] ?>">
                                    <?= poEscape($supplier['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field-box">
                        <label for="order_date">Order Date</label>

                        <input
                            type="date"
                            name="order_date"
                            id="order_date"
                            value="<?= date('Y-m-d') ?>"
                            required
                        >
                    </div>

                    <div class="field-box">
                        <label for="expected_delivery_date">
                            Expected Delivery Date
                        </label>

                        <input
                            type="date"
                            name="expected_delivery_date"
                            id="expected_delivery_date"
                        >
                    </div>

                    <div class="field-box">
                        <label for="notes">Order Notes</label>

                        <textarea
                            name="notes"
                            id="notes"
                            placeholder="Enter any additional order information..."
                        ></textarea>
                    </div>

                </div>

                <div style="margin-top:30px;">

                    <h3 class="section-heading">Products to Order</h3>

                    <div id="productRows">

                        <div class="product-row">

                            <div class="product-field">
                                <label>Select Product</label>

                                <select name="product_id[]" required>
                                    <option value="">Choose a product</option>

                                    <?php foreach ($products as $product): ?>
                                        <option value="<?= (int)$product['id'] ?>">
                                            <?= poEscape($product['product_name']) ?>
                                            <?php if ($product['product_code']): ?>
                                                (<?= poEscape($product['product_code']) ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="product-field">
                                <label>Quantity to Order</label>

                                <input
                                    type="number"
                                    name="quantity_ordered[]"
                                    min="1"
                                    step="1"
                                    placeholder="Enter quantity"
                                    required
                                >
                            </div>

                            <div class="product-field">
                                <label>Unit Buying Price (KSh)</label>

                                <input
                                    type="number"
                                    name="unit_buying_price[]"
                                    min="0"
                                    step="0.01"
                                    placeholder="Enter price"
                                    required
                                >
                            </div>

                            <div class="product-field">
                                <label>Action</label>

                                <button
                                    type="button"
                                    class="btn btn-danger remove-product"
                                    disabled
                                >
                                    Remove
                                </button>
                            </div>

                        </div>

                    </div>

                    <button
                        type="button"
                        class="btn btn-secondary"
                        id="addProduct"
                    >
                        + Add Another Product
                    </button>

                    <div class="total-display">
                        Estimated Order Total:
                        KSh <span id="estimatedTotal">0.00</span>
                    </div>

                </div>

                <div class="form-actions">

                    <button
                        type="submit"
                        name="create_order"
                        value="1"
                        class="btn btn-primary"
                    >
                        Create Purchase Order
                    </button>

                    <button
                        type="reset"
                        class="btn btn-secondary"
                        id="resetOrder"
                    >
                        Clear Form
                    </button>

                </div>

            </form>

        <?php endif; ?>

    </div>

    <div class="purchase-card">

        <h2 class="section-heading">Purchase Order Records</h2>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>PO Number</th>
                        <th>Supplier</th>
                        <th>Order Date</th>
                        <th>Expected Delivery</th>
                        <th>Total Amount (KSh)</th>
                        <th>Created By</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($orders)): ?>

                        <tr>
                            <td colspan="7" class="empty-message">
                                No purchase orders have been created yet.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($orders as $order): ?>

                            <?php
                            $statusClass = 'status-pending';

                            if ($order['status'] === 'Partially Received') {
                                $statusClass = 'status-partial';
                            } elseif ($order['status'] === 'Received') {
                                $statusClass = 'status-received';
                            } elseif ($order['status'] === 'Cancelled') {
                                $statusClass = 'status-cancelled';
                            }
                            ?>

                            <tr>
                                <td>
                                    <strong><?= poEscape($order['order_number']) ?></strong>
                                </td>

                                <td><?= poEscape($order['supplier_name']) ?></td>

                                <td><?= poEscape($order['order_date']) ?></td>

                                <td>
                                    <?= $order['expected_delivery_date']
                                        ? poEscape($order['expected_delivery_date'])
                                        : 'Not specified' ?>
                                </td>

                                <td>
                                    <?= number_format((float)$order['total_amount'], 2) ?>
                                </td>

                                <td><?= poEscape($order['full_name']) ?></td>

                                <td>
                                    <span class="status <?= $statusClass ?>">
                                        <?= poEscape($order['status']) ?>
                                    </span>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const productRows = document.getElementById('productRows');
    const addProductButton = document.getElementById('addProduct');
    const estimatedTotal = document.getElementById('estimatedTotal');
    const resetButton = document.getElementById('resetOrder');

    if (!productRows || !addProductButton) {
        return;
    }

    function updateTotal() {

        let total = 0;

        productRows.querySelectorAll('.product-row').forEach(function (row) {

            const quantity = parseFloat(
                row.querySelector('[name="quantity_ordered[]"]').value
            ) || 0;

            const price = parseFloat(
                row.querySelector('[name="unit_buying_price[]"]').value
            ) || 0;

            total += quantity * price;
        });

        estimatedTotal.textContent = total.toLocaleString(
            'en-KE',
            {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }
        );
    }

    function updateRemoveButtons() {

        const rows = productRows.querySelectorAll('.product-row');

        rows.forEach(function (row) {
            const removeButton = row.querySelector('.remove-product');
            removeButton.disabled = rows.length === 1;
        });
    }

    addProductButton.addEventListener('click', function () {

        const firstRow = productRows.querySelector('.product-row');
        const newRow = firstRow.cloneNode(true);

        newRow.querySelectorAll('select, input').forEach(function (field) {
            field.value = '';
        });

        productRows.appendChild(newRow);

        updateRemoveButtons();
        updateTotal();
    });

    productRows.addEventListener('click', function (event) {

        if (event.target.classList.contains('remove-product')) {

            const rows = productRows.querySelectorAll('.product-row');

            if (rows.length > 1) {
                event.target.closest('.product-row').remove();
            }

            updateRemoveButtons();
            updateTotal();
        }
    });

    productRows.addEventListener('input', updateTotal);
    productRows.addEventListener('change', updateTotal);

    if (resetButton) {
        resetButton.addEventListener('click', function () {

            setTimeout(function () {
                updateRemoveButtons();
                updateTotal();
            }, 0);
        });
    }

    updateRemoveButtons();
    updateTotal();

});
</script>

<?php require_once 'includes/footer.php'; ?>
