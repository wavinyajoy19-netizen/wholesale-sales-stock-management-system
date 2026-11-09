
<?php
require_once 'includes/auth.php';
requireRole(['admin', 'storekeeper']);

require_once 'includes/db.php';

$pageTitle = 'Receive Supplier Deliveries';
$activePage = 'receive_deliveries';

$error = '';
$message = '';

$selectedOrderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Helper function
|--------------------------------------------------------------------------
*/
function deliveryEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| Process delivery receiving
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receive_delivery'])) {

    $selectedOrderId = (int)($_POST['order_id'] ?? 0);
    $receivedQuantities = $_POST['received_qty'] ?? [];
    $deliveryNotes = trim($_POST['delivery_notes'] ?? '');

    try {

        if ($selectedOrderId <= 0) {
            throw new RuntimeException('Please select a valid purchase order.');
        }

        if (!is_array($receivedQuantities)) {
            throw new RuntimeException('Invalid delivery quantities.');
        }

        if (strlen($deliveryNotes) > 255) {
            throw new RuntimeException('Delivery notes must not exceed 255 characters.');
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);

        if ($userId <= 0) {
            throw new RuntimeException('Your session has expired. Please log in again.');
        }

        $pdo->beginTransaction();

        /*
        |--------------------------------------------------------------------------
        | Lock the purchase order
        |--------------------------------------------------------------------------
        */
        $orderStmt = $pdo->prepare("
            SELECT id, order_number, status
            FROM purchase_orders
            WHERE id = ?
            FOR UPDATE
        ");

        $orderStmt->execute([$selectedOrderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new RuntimeException('The selected purchase order was not found.');
        }

        if (!in_array($order['status'], ['Pending', 'Partially Received'], true)) {
            throw new RuntimeException(
                'This purchase order cannot receive more deliveries.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Lock purchase order items
        |--------------------------------------------------------------------------
        */
        $itemsStmt = $pdo->prepare("
            SELECT
                id,
                product_id,
                quantity_ordered,
                quantity_received,
                unit_buying_price
            FROM purchase_order_items
            WHERE purchase_order_id = ?
            ORDER BY id ASC
            FOR UPDATE
        ");

        $itemsStmt->execute([$selectedOrderId]);
        $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($orderItems)) {
            throw new RuntimeException(
                'This purchase order has no products.'
            );
        }

        $itemsById = [];

        foreach ($orderItems as $item) {
            $itemsById[(int)$item['id']] = $item;
        }

        /*
        |--------------------------------------------------------------------------
        | Validate submitted item IDs
        |--------------------------------------------------------------------------
        */
        foreach ($receivedQuantities as $itemId => $submittedQuantity) {

            if (!ctype_digit((string)$itemId) ||
                !isset($itemsById[(int)$itemId])) {

                throw new RuntimeException(
                    'An invalid purchase order product was submitted.'
                );
            }
        }

        $receivedCount = 0;
        $deliveryTotalCost = 0;

        /*
        |--------------------------------------------------------------------------
        | Process each delivered product
        |--------------------------------------------------------------------------
        */
        foreach ($orderItems as $item) {

            $itemId = (int)$item['id'];

            $orderedQuantity = (int)$item['quantity_ordered'];
            $previouslyReceived = (int)$item['quantity_received'];

            $remainingQuantity = $orderedQuantity - $previouslyReceived;

            $submittedQuantity = $receivedQuantities[$itemId] ?? '0';

            if ($submittedQuantity === '') {
                $submittedQuantity = '0';
            }

            $newReceivedQuantity = filter_var(
                $submittedQuantity,
                FILTER_VALIDATE_INT
            );

            if ($newReceivedQuantity === false || $newReceivedQuantity < 0) {
                throw new RuntimeException(
                    'Please enter valid delivery quantities.'
                );
            }

            if ($newReceivedQuantity === 0) {
                continue;
            }

            if ($newReceivedQuantity > $remainingQuantity) {
                throw new RuntimeException(
                    'The delivered quantity cannot exceed the outstanding quantity for one of the products.'
                );
            }

            $productId = (int)$item['product_id'];
            $buyingPrice = round((float)$item['unit_buying_price'], 2);

            $totalCost = round($newReceivedQuantity * $buyingPrice, 2);

            if ($totalCost > 9999999999.99) {
                throw new RuntimeException(
                    'The delivery purchase cost is too large.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Lock the product stock record
            |--------------------------------------------------------------------------
            */
            $productStmt = $pdo->prepare("
                SELECT id, product_name, quantity
                FROM products
                WHERE id = ?
                FOR UPDATE
            ");

            $productStmt->execute([$productId]);
            $product = $productStmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                throw new RuntimeException(
                    'A product in this purchase order could not be found.'
                );
            }

            $previousStock = (int)$product['quantity'];

            if ($newReceivedQuantity > 2147483647 - $previousStock) {
                throw new RuntimeException(
                    'The new stock quantity is too large.'
                );
            }

            $newStock = $previousStock + $newReceivedQuantity;

            /*
            |--------------------------------------------------------------------------
            | Update product stock and buying price
            |--------------------------------------------------------------------------
            */
            $updateProduct = $pdo->prepare("
                UPDATE products
                SET quantity = ?,
                    buying_price = ?
                WHERE id = ?
            ");

            $updateProduct->execute([
                $newStock,
                $buyingPrice,
                $productId
            ]);

            /*
            |--------------------------------------------------------------------------
            | Record stock movement
            |--------------------------------------------------------------------------
            */
            $movementNotes = 'Purchase order ' . $order['order_number'];

            if ($deliveryNotes !== '') {
                $movementNotes .= ' - ' . $deliveryNotes;
            } else {
                $movementNotes .= ' - Supplier delivery received';
            }

            $movementStmt = $pdo->prepare("
                INSERT INTO stock_movements
                (
                    product_id,
                    user_id,
                    movement_type,
                    quantity,
                    previous_quantity,
                    new_quantity,
                    notes,
                    unit_buying_price,
                    total_cost
                )
                VALUES (?, ?, 'stock_in', ?, ?, ?, ?, ?, ?)
            ");

            $movementStmt->execute([
                $productId,
                $userId,
                $newReceivedQuantity,
                $previousStock,
                $newStock,
                $movementNotes,
                $buyingPrice,
                $totalCost
            ]);

            /*
            |--------------------------------------------------------------------------
            | Update quantity received on purchase order item
            |--------------------------------------------------------------------------
            */
            $updatedReceivedQuantity =
                $previouslyReceived + $newReceivedQuantity;

            $updateItem = $pdo->prepare("
                UPDATE purchase_order_items
                SET quantity_received = ?
                WHERE id = ?
                  AND purchase_order_id = ?
            ");

            $updateItem->execute([
                $updatedReceivedQuantity,
                $itemId,
                $selectedOrderId
            ]);

            $receivedCount += $newReceivedQuantity;
            $deliveryTotalCost += $totalCost;

        }

        if ($receivedCount === 0) {
            throw new RuntimeException(
                'Enter at least one quantity delivered before submitting.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Determine the new purchase order status
        |--------------------------------------------------------------------------
        */
        $statusStmt = $pdo->prepare("
            SELECT
                SUM(quantity_ordered) AS total_ordered,
                SUM(quantity_received) AS total_received
            FROM purchase_order_items
            WHERE purchase_order_id = ?
        ");

        $statusStmt->execute([$selectedOrderId]);
        $totals = $statusStmt->fetch(PDO::FETCH_ASSOC);

        $totalOrdered = (int)$totals['total_ordered'];
        $totalReceived = (int)$totals['total_received'];

        if ($totalReceived >= $totalOrdered) {
            $newStatus = 'Received';
        } elseif ($totalReceived > 0) {
            $newStatus = 'Partially Received';
        } else {
            $newStatus = 'Pending';
        }

        $updateOrder = $pdo->prepare("
            UPDATE purchase_orders
            SET status = ?
            WHERE id = ?
        ");

        $updateOrder->execute([
            $newStatus,
            $selectedOrderId
        ]);

        /*
        |--------------------------------------------------------------------------
        | Record activity
        |--------------------------------------------------------------------------
        */
        if (function_exists('logActivity')) {

            logActivity(
                'Purchase order delivery received',
                $order['order_number'] .
                ': ' . $receivedCount .
                ' units received. Delivery value KSh ' .
                number_format($deliveryTotalCost, 2) .
                '. New status: ' . $newStatus
            );
        }

        $pdo->commit();

        header(
            'Location: receive_deliveries.php?order_id=' .
            $selectedOrderId . '&success=1'
        );

        exit;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Purchase delivery error: ' . $e->getMessage());

        if ($e instanceof RuntimeException) {
            $error = $e->getMessage();
        } else {
            $error = 'Unable to receive this delivery. Please try again.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Success message
|--------------------------------------------------------------------------
*/
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $message = 'Supplier delivery received successfully. Product stock has been updated.';
}

/*
|--------------------------------------------------------------------------
| Load purchase orders
|--------------------------------------------------------------------------
*/
$ordersStmt = $pdo->query("
    SELECT
        po.id,
        po.order_number,
        po.order_date,
        po.expected_delivery_date,
        po.total_amount,
        po.status,
        s.supplier_name
    FROM purchase_orders po
    INNER JOIN suppliers s ON po.supplier_id = s.id
    ORDER BY po.id DESC
");

$allOrders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

$availableOrders = array_filter($allOrders, function ($order) {
    return in_array(
        $order['status'],
        ['Pending', 'Partially Received'],
        true
    );
});

/*
|--------------------------------------------------------------------------
| Load selected purchase order
|--------------------------------------------------------------------------
*/
$selectedOrder = null;
$selectedItems = [];

if ($selectedOrderId > 0) {

    $selectedOrderStmt = $pdo->prepare("
        SELECT
            po.*,
            s.supplier_name,
            s.phone,
            s.address
        FROM purchase_orders po
        INNER JOIN suppliers s ON po.supplier_id = s.id
        WHERE po.id = ?
        LIMIT 1
    ");

    $selectedOrderStmt->execute([$selectedOrderId]);
    $selectedOrder = $selectedOrderStmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedOrder) {

        $selectedItemsStmt = $pdo->prepare("
            SELECT
                poi.id,
                poi.product_id,
                poi.quantity_ordered,
                poi.quantity_received,
                poi.unit_buying_price,
                poi.subtotal,
                p.product_name,
                p.product_code
            FROM purchase_order_items poi
            INNER JOIN products p ON poi.product_id = p.id
            WHERE poi.purchase_order_id = ?
            ORDER BY poi.id ASC
        ");

        $selectedItemsStmt->execute([$selectedOrderId]);
        $selectedItems = $selectedItemsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

require_once 'includes/header.php';
?>

<style>
.delivery-page {
    width: 100%;
    max-width: 1500px;
    margin: 0 auto;
    padding: 25px 30px;
    color: #263449;
}

.delivery-page .page-heading {
    color: #102b50;
    font-size: 27px;
    font-weight: 700;
    margin-bottom: 7px;
}

.delivery-page .page-description {
    color: #64748b;
    margin-bottom: 25px;
}

.delivery-page .delivery-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 3px 12px rgba(15, 35, 65, 0.05);
}

.delivery-page .section-heading {
    color: #102b50;
    font-size: 19px;
    font-weight: 700;
    margin-bottom: 18px;
}

.delivery-page .message {
    padding: 13px 16px;
    border-radius: 7px;
    margin-bottom: 20px;
}

.delivery-page .success-message {
    background: #dcfce7;
    color: #166534;
}

.delivery-page .error-message {
    background: #fee2e2;
    color: #991b1b;
}

.delivery-page .order-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 15px;
}

.delivery-page .order-option {
    display: block;
    padding: 17px;
    border: 1px solid #dbe3ed;
    border-radius: 9px;
    text-decoration: none;
    color: #263449;
    background: #ffffff;
    transition: 0.2s;
}

.delivery-page .order-option:hover,
.delivery-page .order-option.selected {
    border-color: #2563eb;
    background: #eff6ff;
}

.delivery-page .order-option strong {
    display: block;
    color: #102b50;
    font-size: 15px;
    margin-bottom: 7px;
}

.delivery-page .order-option span {
    display: block;
    color: #64748b;
    font-size: 13px;
    margin-top: 5px;
}

.delivery-page .status {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}

.delivery-page .status-pending {
    background: #fef3c7;
    color: #92400e;
}

.delivery-page .status-partial {
    background: #dbeafe;
    color: #1e40af;
}

.delivery-page .status-received {
    background: #dcfce7;
    color: #166534;
}

.delivery-page .details-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 15px;
}

.delivery-page .detail-box {
    padding: 15px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
}

.delivery-page .detail-box label {
    display: block;
    color: #64748b;
    font-size: 12px;
    margin-bottom: 7px;
}

.delivery-page .detail-box strong {
    color: #102b50;
    font-size: 15px;
}

.delivery-page .table-wrapper {
    width: 100%;
    overflow-x: auto;
}

.delivery-page table {
    width: 100%;
    min-width: 850px;
    border-collapse: collapse;
}

.delivery-page th {
    background: #102b50;
    color: #ffffff;
    padding: 13px;
    text-align: left;
    font-size: 13px;
}

.delivery-page td {
    padding: 13px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 13px;
}

.delivery-page input,
.delivery-page textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 11px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    background: #ffffff;
    color: #263449;
    font-size: 14px;
}

.delivery-page input:focus,
.delivery-page textarea:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
}

.delivery-page .quantity-input {
    max-width: 130px;
}

.delivery-page .notes-box {
    margin-top: 20px;
}

.delivery-page .notes-box label {
    display: block;
    color: #102b50;
    font-weight: 600;
    margin-bottom: 9px;
}

.delivery-page textarea {
    min-height: 75px;
    resize: vertical;
}

.delivery-page .btn {
    display: inline-block;
    padding: 12px 20px;
    border: none;
    border-radius: 7px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
}

.delivery-page .btn-primary {
    background: #2563eb;
    color: #ffffff;
}

.delivery-page .btn-primary:hover {
    background: #1d4ed8;
}

.delivery-page .btn-secondary {
    background: #e2e8f0;
    color: #102b50;
}

.delivery-page .form-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 20px;
}

.delivery-page .empty-message {
    padding: 20px;
    text-align: center;
    color: #64748b;
}

.delivery-page .completed-message {
    padding: 15px;
    background: #dcfce7;
    color: #166534;
    border-radius: 8px;
    margin-top: 15px;
}

.delivery-page .warning-message {
    padding: 15px;
    background: #fef3c7;
    color: #92400e;
    border-radius: 8px;
    margin-top: 15px;
}

@media (max-width: 750px) {
    .delivery-page {
        padding: 18px 12px;
    }

    .delivery-page .order-list,
    .delivery-page .details-grid {
        grid-template-columns: 1fr;
    }

    .delivery-page .delivery-card {
        padding: 16px;
    }
}
</style>

<div class="delivery-page">

    <h1 class="page-heading">Receive Supplier Deliveries</h1>

    <p class="page-description">
        Select a purchase order and record the actual quantities delivered
        by the supplier.
    </p>

    <?php if ($message): ?>
        <div class="message success-message">
            <?= deliveryEscape($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message error-message">
            <?= deliveryEscape($error) ?>
        </div>
    <?php endif; ?>

    <!-- AVAILABLE PURCHASE ORDERS -->
    <div class="delivery-card">

        <h2 class="section-heading">
            Select Purchase Order
        </h2>

        <?php if (empty($availableOrders)): ?>

            <div class="empty-message">
                There are no pending or partially received purchase orders.
                Create a purchase order first.
            </div>

        <?php else: ?>

            <div class="order-list">

                <?php foreach ($availableOrders as $order): ?>

                    <?php
                    $statusClass = $order['status'] === 'Partially Received'
                        ? 'status-partial'
                        : 'status-pending';
                    ?>

                    <a
                        href="receive_deliveries.php?order_id=<?= (int)$order['id'] ?>"
                        class="order-option <?= $selectedOrderId === (int)$order['id'] ? 'selected' : '' ?>"
                    >

                        <strong>
                            <?= deliveryEscape($order['order_number']) ?>
                        </strong>

                        <span>
                            Supplier:
                            <?= deliveryEscape($order['supplier_name']) ?>
                        </span>

                        <span>
                            Order Date:
                            <?= deliveryEscape($order['order_date']) ?>
                        </span>

                        <span>
                            Order Amount:
                            KSh <?= number_format((float)$order['total_amount'], 2) ?>
                        </span>

                        <span>
                            Status:
                            <span class="status <?= $statusClass ?>">
                                <?= deliveryEscape($order['status']) ?>
                            </span>
                        </span>

                    </a>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

    <!-- SELECTED PURCHASE ORDER -->
    <?php if ($selectedOrder): ?>

        <div class="delivery-card">

            <h2 class="section-heading">
                Purchase Order Details
            </h2>

            <div class="details-grid">

                <div class="detail-box">
                    <label>Purchase Order Number</label>
                    <strong>
                        <?= deliveryEscape($selectedOrder['order_number']) ?>
                    </strong>
                </div>

                <div class="detail-box">
                    <label>Supplier</label>
                    <strong>
                        <?= deliveryEscape($selectedOrder['supplier_name']) ?>
                    </strong>
                </div>

                <div class="detail-box">
                    <label>Supplier Phone</label>
                    <strong>
                        <?= deliveryEscape($selectedOrder['phone'] ?? 'Not provided') ?>
                    </strong>
                </div>

                <div class="detail-box">
                    <label>Expected Delivery Date</label>
                    <strong>
                        <?= deliveryEscape(
                            $selectedOrder['expected_delivery_date'] ?: 'Not specified'
                        ) ?>
                    </strong>
                </div>

                <div class="detail-box">
                    <label>Order Total</label>
                    <strong>
                        KSh <?= number_format(
                            (float)$selectedOrder['total_amount'],
                            2
                        ) ?>
                    </strong>
                </div>

                <div class="detail-box">
                    <label>Current Status</label>
                    <strong>
                        <?= deliveryEscape($selectedOrder['status']) ?>
                    </strong>
                </div>

            </div>

        </div>

        <!-- DELIVERY ITEMS -->
        <div class="delivery-card">

            <h2 class="section-heading">
                Record Delivered Products
            </h2>

            <?php if (empty($selectedItems)): ?>

                <div class="empty-message">
                    This purchase order has no products.
                </div>

            <?php else: ?>

                <?php
                $hasOutstanding = false;

                foreach ($selectedItems as $item) {
                    if ((int)$item['quantity_received'] <
                        (int)$item['quantity_ordered']) {
                        $hasOutstanding = true;
                        break;
                    }
                }
                ?>

                <?php if ($selectedOrder['status'] === 'Cancelled'): ?>

                    <div class="warning-message">
                        This purchase order has been cancelled and cannot receive deliveries.
                    </div>

                <?php elseif ($selectedOrder['status'] === 'Received' || !$hasOutstanding): ?>

                    <div class="completed-message">
                        All products in this purchase order have been received.
                    </div>

                <?php else: ?>

                    <form method="POST" action="receive_deliveries.php">

                        <input
                            type="hidden"
                            name="order_id"
                            value="<?= (int)$selectedOrder['id'] ?>"
                        >

                        <div class="table-wrapper">

                            <table>

                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Ordered</th>
                                        <th>Previously Received</th>
                                        <th>Outstanding</th>
                                        <th>Unit Buying Price</th>
                                        <th>Quantity Delivered Now</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php foreach ($selectedItems as $item): ?>

                                        <?php
                                        $ordered = (int)$item['quantity_ordered'];
                                        $previouslyReceived = (int)$item['quantity_received'];
                                        $outstanding = $ordered - $previouslyReceived;
                                        ?>

                                        <tr>

                                            <td>
                                                <strong>
                                                    <?= deliveryEscape($item['product_name']) ?>
                                                </strong>

                                                <br>

                                                <small>
                                                    <?= deliveryEscape($item['product_code'] ?? '') ?>
                                                </small>
                                            </td>

                                            <td>
                                                <?= number_format($ordered) ?>
                                            </td>

                                            <td>
                                                <?= number_format($previouslyReceived) ?>
                                            </td>

                                            <td>
                                                <strong>
                                                    <?= number_format($outstanding) ?>
                                                </strong>
                                            </td>

                                            <td>
                                                KSh <?= number_format(
                                                    (float)$item['unit_buying_price'],
                                                    2
                                                ) ?>
                                            </td>

                                            <td>

                                                <?php if ($outstanding > 0): ?>

                                                    <input
                                                        type="number"
                                                        class="quantity-input"
                                                        name="received_qty[<?= (int)$item['id'] ?>]"
                                                        min="0"
                                                        max="<?= $outstanding ?>"
                                                        step="1"
                                                        value="0"
                                                        required
                                                    >

                                                <?php else: ?>

                                                    <span class="status status-received">
                                                        Fully Received
                                                    </span>

                                                <?php endif; ?>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                        <div class="notes-box">

                            <label for="delivery_notes">
                                Delivery Notes (Optional)
                            </label>

                            <textarea
                                name="delivery_notes"
                                id="delivery_notes"
                                maxlength="255"
                                placeholder="Enter delivery notes, e.g. supplier delivered remaining goods..."
                            ></textarea>

                        </div>

                        <div class="form-actions">

                            <button
                                type="submit"
                                name="receive_delivery"
                                value="1"
                                class="btn btn-primary"
                            >
                                Confirm Delivery and Update Stock
                            </button>

                            <a
                                href="receive_deliveries.php"
                                class="btn btn-secondary"
                            >
                                Back to Orders
                            </a>

                        </div>

                    </form>

                <?php endif; ?>

            <?php endif; ?>

        </div>

    <?php elseif ($selectedOrderId > 0): ?>

        <div class="delivery-card">
            <div class="message error-message">
                The selected purchase order could not be found.
            </div>
        </div>

    <?php endif; ?>

</div>

<?php require_once 'includes/footer.php'; ?>
