
<?php
require_once 'includes/auth.php';
requireLogin();
requireRole(['admin', 'storekeeper']);

require_once 'includes/db.php';

$error = '';
$success = isset($_GET['success']) && $_GET['success'] === '1';

$selectedProductId = '';
$quantity = '';
$unitBuyingPrice = '';
$notes = '';

/*
|--------------------------------------------------------------------------
| Process received stock
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $selectedProductId = $_POST['product_id'] ?? '';
    $quantity = trim($_POST['quantity'] ?? '');
    $unitBuyingPrice = trim($_POST['unit_buying_price'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (
        filter_var($selectedProductId, FILTER_VALIDATE_INT) === false ||
        (int)$selectedProductId < 1
    ) {
        $error = 'Please select a valid product.';

    } elseif (
        filter_var($quantity, FILTER_VALIDATE_INT) === false ||
        (int)$quantity < 1 ||
        (int)$quantity > 2147483647
    ) {
        $error = 'Please enter a valid quantity greater than zero.';

    } elseif (
        !is_numeric($unitBuyingPrice) ||
        !is_finite((float)$unitBuyingPrice) ||
        (float)$unitBuyingPrice <= 0 ||
        (float)$unitBuyingPrice > 99999999.99
    ) {
        $error = 'Please enter a valid unit buying price greater than zero.';

    } elseif (strlen($notes) > 255) {
        $error = 'Notes must not exceed 255 characters.';

    } else {

        $productId = (int)$selectedProductId;
        $receivedQuantity = (int)$quantity;
        $buyingPrice = round((float)$unitBuyingPrice, 2);
        $totalCost = round($receivedQuantity * $buyingPrice, 2);

        if ($totalCost > 9999999999.99) {
            $error = 'The total purchase cost is too large.';
        } else {

            try {

                $userId = $_SESSION['user_id'] ?? null;

                if (!$userId) {
                    throw new RuntimeException(
                        'Your session has expired. Please log in again.'
                    );
                }

                $pdo->beginTransaction();

                // Lock product record before changing stock.
                $stmt = $pdo->prepare(
                    'SELECT id, product_name, quantity
                     FROM products
                     WHERE id = ?
                     FOR UPDATE'
                );

                $stmt->execute([$productId]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$product) {
                    throw new RuntimeException(
                        'The selected product was not found.'
                    );
                }

                $previousQuantity = (int)$product['quantity'];

                if ($receivedQuantity > 2147483647 - $previousQuantity) {
                    throw new RuntimeException(
                        'The new stock quantity is too large.'
                    );
                }

                $newQuantity = $previousQuantity + $receivedQuantity;

                // Update stock quantity and latest buying price.
                $updateStmt = $pdo->prepare(
                    'UPDATE products
                     SET quantity = ?,
                         buying_price = ?
                     WHERE id = ?'
                );

                $updateStmt->execute([
                    $newQuantity,
                    $buyingPrice,
                    $productId
                ]);

                // Save the delivery in stock movement history.
                $movementStmt = $pdo->prepare(
                    'INSERT INTO stock_movements
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
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                $movementNotes = $notes !== ''
                    ? $notes
                    : 'New stock received';

                $movementStmt->execute([
                    $productId,
                    $userId,
                    'stock_in',
                    $receivedQuantity,
                    $previousQuantity,
                    $newQuantity,
                    $movementNotes,
                    $buyingPrice,
                    $totalCost
                ]);

                // Record activity when the activity function is available.
                if (function_exists('logActivity')) {
                    logActivity(
                        'Stock received',
                        $product['product_name'] .
                        ': ' . $receivedQuantity .
                        ' units received at KSh ' .
                        number_format($buyingPrice, 2) .
                        ' per unit. Total cost KSh ' .
                        number_format($totalCost, 2)
                    );
                }

                $pdo->commit();

                header('Location: stock_in.php?success=1');
                exit;

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('Stock-in error: ' . $e->getMessage());

                if ($e instanceof RuntimeException) {
                    $error = $e->getMessage();
                } else {
                    $error = 'Unable to receive stock. Please try again.';
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load products
|--------------------------------------------------------------------------
*/
try {

    $stmt = $pdo->query(
        'SELECT id, product_name, product_code, quantity, buying_price
         FROM products
         ORDER BY product_name ASC'
    );

    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    error_log('Loading products failed: ' . $e->getMessage());

    $products = [];
    $error = 'Unable to load products. Please check your database.';
}

/*
|--------------------------------------------------------------------------
| Load recent stock deliveries
|--------------------------------------------------------------------------
*/
$historyError = '';

try {

    $historyStmt = $pdo->query(
        "SELECT
            sm.id,
            sm.quantity,
            sm.previous_quantity,
            sm.new_quantity,
            sm.notes,
            sm.unit_buying_price,
            sm.total_cost,
            sm.movement_date,
            p.product_name,
            p.product_code,
            u.full_name
         FROM stock_movements sm
         INNER JOIN products p ON sm.product_id = p.id
         INNER JOIN users u ON sm.user_id = u.id
         WHERE sm.movement_type = 'stock_in'
         ORDER BY sm.movement_date DESC, sm.id DESC
         LIMIT 10"
    );

    $recentDeliveries = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    error_log('Loading stock history failed: ' . $e->getMessage());

    $recentDeliveries = [];
    $historyError = 'Unable to load recent stock deliveries.';
}

$pageTitle = 'Receive Stock';
$activePage = 'stock_in';

require_once 'includes/header.php';
?>

<style>
/* =========================================
   RECEIVE STOCK PAGE DESIGN
   ========================================= */

.stock-container {
    width: 100%;
    max-width: 1500px;
    margin: 0 auto;
    padding-bottom: 25px;
}

.stock-heading {
    margin-bottom: 24px;
}

.stock-heading h2 {
    margin: 0 0 8px;
    font-size: 25px;
    font-weight: 700;
    color: #102a43;
}

.stock-heading p {
    margin: 0;
    color: #64748b;
    line-height: 1.6;
}

/* Main form panel */
.stock-panel {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 28px;
    box-shadow: 0 3px 12px rgba(16, 42, 67, 0.04);
}

/* Four separate field boxes */
.stock-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
    align-items: stretch;
}

.stock-form-group {
    min-width: 0;
    padding: 18px;
    margin: 0;
    background: #ffffff;
    border: 1px solid #dce4ed;
    border-radius: 10px;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.stock-form-group:focus-within {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.08);
}

.stock-form-group label {
    display: block;
    margin-bottom: 12px;
    color: #102a43;
    font-size: 14px;
    font-weight: 700;
}

.stock-form-group input,
.stock-form-group select,
.stock-form-group textarea {
    display: block;
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
    height: 44px;
    padding: 11px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    background: #ffffff;
    color: #243447;
    font-size: 14px;
    outline: none;
}

.stock-form-group input:focus,
.stock-form-group select:focus,
.stock-form-group textarea:focus {
    border-color: #2563eb;
}

.stock-form-group textarea {
    min-height: 44px;
    resize: vertical;
}

.stock-form-group small {
    display: block;
    margin-top: 8px;
    color: #64748b;
    font-size: 12px;
}

/* Current stock information */
.stock-info {
    margin-top: 12px;
    padding: 12px;
    background: #eff6ff;
    color: #1e40af;
    border-radius: 7px;
    font-size: 13px;
    line-height: 1.7;
}

/* Stock calculation preview */
.stock-preview {
    margin-top: 20px;
    padding: 20px;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    color: #102a43;
    line-height: 2;
}

.stock-preview strong {
    font-size: 16px;
    color: #102a43;
}

.stock-preview .total-cost {
    font-size: 24px;
    color: #2563eb;
}

.stock-preview hr {
    border: none;
    border-top: 1px solid #dce4ed;
    margin: 12px 0;
}

/* Submit button */
.stock-form-actions {
    margin-top: 22px;
}

.stock-button {
    display: inline-block;
    padding: 12px 25px;
    border: none;
    border-radius: 7px;
    background: #2563eb;
    color: #ffffff;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
}

.stock-button:hover {
    background: #1d4ed8;
}

.stock-button:disabled {
    background: #94a3b8;
    cursor: not-allowed;
}

/* Alerts */
.stock-alert {
    padding: 13px 16px;
    margin-bottom: 20px;
    border-radius: 7px;
    line-height: 1.5;
}

.stock-alert-success {
    background: #dcfce7;
    border: 1px solid #86efac;
    color: #166534;
}

.stock-alert-error {
    background: #fee2e2;
    border: 1px solid #fca5a5;
    color: #991b1b;
}

.stock-empty {
    padding: 15px;
    background: #fff7ed;
    border: 1px solid #fed7aa;
    color: #9a3412;
    border-radius: 7px;
    margin-bottom: 20px;
}

/* Delivery history */
.stock-history-heading {
    margin: 30px 0 15px;
}

.stock-history-heading h3 {
    margin: 0 0 6px;
    color: #102a43;
    font-size: 21px;
    font-weight: 700;
}

.stock-history-heading p {
    margin: 0;
    color: #64748b;
}

.stock-table-wrapper {
    width: 100%;
    overflow-x: auto;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
}

.stock-table {
    width: 100%;
    min-width: 1100px;
    border-collapse: collapse;
}

.stock-table th,
.stock-table td {
    padding: 13px 14px;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
    font-size: 13px;
}

.stock-table th {
    background: #102a43;
    color: #ffffff;
    font-weight: 600;
    white-space: nowrap;
}

.stock-table td {
    color: #475569;
}

.stock-table tr:last-child td {
    border-bottom: none;
}

.stock-quantity {
    font-weight: 700;
    color: #166534 !important;
}

.stock-note {
    max-width: 180px;
    overflow-wrap: anywhere;
}

.stock-no-records {
    padding: 22px;
    text-align: center;
    color: #64748b;
}

/* Responsive layout */
@media (max-width: 650px) {
    .stock-form-grid {
        grid-template-columns: 1fr;
    }

    .stock-panel {
        padding: 16px;
    }

    .stock-heading h2 {
        font-size: 22px;
    }

    .stock-button {
        width: 100%;
    }
}
</style>

<div class="stock-container">

    <div class="stock-heading">
        <h2>Receive Stock</h2>
        <p>
            Record new deliveries, purchase costs and update available
            product quantities automatically.
        </p>
    </div>

    <?php if ($success): ?>
        <div class="stock-alert stock-alert-success">
            Stock received successfully. Stock quantity and buying price have
            been updated.
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="stock-alert stock-alert-error">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if (empty($products)): ?>

        <div class="stock-empty">
            No products are available. Add a product on the Products page
            before recording incoming stock.
        </div>

    <?php else: ?>

        <div class="stock-panel">

            <form method="POST" action="stock_in.php" id="stockForm">

                <div class="stock-form-grid">

                    <!-- BOX 1: SELECT PRODUCT -->
                    <div class="stock-form-group">

                        <label for="product_id">Select Product</label>

                        <select name="product_id" id="product_id" required>
                            <option value="">Choose a product</option>

                            <?php foreach ($products as $product): ?>
                                <option
                                    value="<?= (int)$product['id'] ?>"
                                    data-stock="<?= (int)$product['quantity'] ?>"
                                    data-buying-price="<?= htmlspecialchars(
                                        (string)$product['buying_price'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    <?= (string)$selectedProductId === (string)$product['id']
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= htmlspecialchars(
                                        $product['product_name'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                    (<?= htmlspecialchars(
                                        $product['product_code'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>)
                                </option>
                            <?php endforeach; ?>

                        </select>

                        <div class="stock-info" id="currentStock">
                            Select a product to view its current stock and buying price.
                        </div>

                    </div>

                    <!-- BOX 2: UNITS RECEIVED -->
                    <div class="stock-form-group">

                        <label for="quantity">Units Received</label>

                        <input
                            type="number"
                            name="quantity"
                            id="quantity"
                            min="1"
                            max="2147483647"
                            step="1"
                            value="<?= htmlspecialchars(
                                (string)$quantity,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            placeholder="Enter quantity"
                            required
                        >

                    </div>

                    <!-- BOX 3: UNIT BUYING PRICE -->
                    <div class="stock-form-group">

                        <label for="unit_buying_price">
                            Unit Buying Price (KSh)
                        </label>

                        <input
                            type="number"
                            name="unit_buying_price"
                            id="unit_buying_price"
                            min="0.01"
                            max="99999999.99"
                            step="0.01"
                            value="<?= htmlspecialchars(
                                (string)$unitBuyingPrice,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            placeholder="Enter price"
                            required
                        >

                    </div>

                    <!-- BOX 4: DELIVERY NOTES -->
                    <div class="stock-form-group">

                        <label for="notes">
                            Delivery Notes (Optional)
                        </label>

                        <textarea
                            name="notes"
                            id="notes"
                            maxlength="255"
                            placeholder="Optional notes"
                        ><?= htmlspecialchars(
                            $notes,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?></textarea>

                        <small>Maximum 255 characters.</small>

                    </div>

                </div>

                <!-- STOCK CALCULATION PREVIEW -->
                <div class="stock-preview" id="stockPreview" hidden>

                    <div>
                        Units received:
                        <strong id="previewUnits">0</strong>
                    </div>

                    <div>
                        Unit buying price:
                        <strong id="previewUnitPrice">KSh 0.00</strong>
                    </div>

                    <div>
                        Previous stock:
                        <strong id="previewPreviousStock">0</strong>
                    </div>

                    <div>
                        Expected new stock:
                        <strong id="previewNewStock">0</strong>
                    </div>

                    <hr>

                    <div>
                        Total Purchase Cost:
                        <br>
                        <strong class="total-cost" id="previewTotalCost">
                            KSh 0.00
                        </strong>
                    </div>

                </div>

                <!-- SUBMIT BUTTON -->
                <div class="stock-form-actions">

                    <button
                        type="submit"
                        class="stock-button"
                        id="submitButton"
                    >
                        <span id="buttonText">Receive Stock</span>
                    </button>

                </div>

            </form>

        </div>

    <?php endif; ?>

    <!-- RECENT STOCK DELIVERIES -->
    <div class="stock-history-heading">
        <h3>Recent Stock Deliveries</h3>
        <p>Showing the latest 10 recorded stock deliveries.</p>
    </div>

    <?php if (!empty($historyError)): ?>

        <div class="stock-alert stock-alert-error">
            <?= htmlspecialchars($historyError, ENT_QUOTES, 'UTF-8') ?>
        </div>

    <?php elseif (empty($recentDeliveries)): ?>

        <div class="stock-panel stock-no-records">
            No stock deliveries have been recorded yet.
        </div>

    <?php else: ?>

        <div class="stock-table-wrapper">

            <table class="stock-table">

                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Units Received</th>
                        <th>Unit Buying Price</th>
                        <th>Total Purchase Cost</th>
                        <th>Previous Stock</th>
                        <th>New Stock</th>
                        <th>Received By</th>
                        <th>Date</th>
                        <th>Notes</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($recentDeliveries as $delivery): ?>

                        <tr>

                            <td>
                                <strong>
                                    <?= htmlspecialchars(
                                        $delivery['product_name'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </strong>
                                <br>
                                <small>
                                    <?= htmlspecialchars(
                                        $delivery['product_code'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </small>
                            </td>

                            <td class="stock-quantity">
                                +<?= number_format((int)$delivery['quantity']) ?>
                            </td>

                            <td>
                                KSh <?= number_format(
                                    (float)$delivery['unit_buying_price'],
                                    2
                                ) ?>
                            </td>

                            <td>
                                <strong>
                                    KSh <?= number_format(
                                        (float)$delivery['total_cost'],
                                        2
                                    ) ?>
                                </strong>
                            </td>

                            <td>
                                <?= number_format(
                                    (int)$delivery['previous_quantity']
                                ) ?>
                            </td>

                            <td>
                                <strong>
                                    <?= number_format(
                                        (int)$delivery['new_quantity']
                                    ) ?>
                                </strong>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $delivery['full_name'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                <?= date(
                                    'd M Y, h:i A',
                                    strtotime($delivery['movement_date'])
                                ) ?>
                            </td>

                            <td class="stock-note">
                                <?= htmlspecialchars(
                                    $delivery['notes'] ?? '',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const productSelect = document.getElementById('product_id');
    const quantityInput = document.getElementById('quantity');
    const priceInput = document.getElementById('unit_buying_price');

    const currentStock = document.getElementById('currentStock');
    const stockPreview = document.getElementById('stockPreview');

    const previewUnits = document.getElementById('previewUnits');
    const previewUnitPrice = document.getElementById('previewUnitPrice');
    const previewPreviousStock = document.getElementById('previewPreviousStock');
    const previewNewStock = document.getElementById('previewNewStock');
    const previewTotalCost = document.getElementById('previewTotalCost');

    const stockForm = document.getElementById('stockForm');
    const submitButton = document.getElementById('submitButton');
    const buttonText = document.getElementById('buttonText');

    function formatMoney(value) {
        return 'KSh ' + value.toLocaleString('en-KE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function updateStockPreview() {

        if (!productSelect || !quantityInput || !priceInput) {
            return;
        }

        const selectedOption =
            productSelect.options[productSelect.selectedIndex];

        if (!selectedOption || !selectedOption.value) {
            currentStock.textContent =
                'Select a product to view its current stock and buying price.';

            stockPreview.hidden = true;
            return;
        }

        const stock = parseInt(selectedOption.dataset.stock, 10) || 0;
        const currentBuyingPrice =
            parseFloat(selectedOption.dataset.buyingPrice) || 0;

        currentStock.textContent =
            'Current stock: ' + stock.toLocaleString() +
            ' units | Latest buying price: ' +
            formatMoney(currentBuyingPrice);

        const received = parseInt(quantityInput.value, 10);
        const unitPrice = parseFloat(priceInput.value);

        if (
            quantityInput.value !== '' &&
            priceInput.value !== '' &&
            Number.isInteger(received) &&
            received > 0 &&
            Number.isFinite(unitPrice) &&
            unitPrice > 0 &&
            received <= 2147483647 - stock
        ) {

            const expectedStock = stock + received;
            const totalCost = received * unitPrice;

            previewUnits.textContent = received.toLocaleString();
            previewUnitPrice.textContent = formatMoney(unitPrice);
            previewPreviousStock.textContent = stock.toLocaleString();
            previewNewStock.textContent = expectedStock.toLocaleString();
            previewTotalCost.textContent = formatMoney(totalCost);

            stockPreview.hidden = false;

        } else {
            stockPreview.hidden = true;
        }
    }

    if (productSelect) {
        productSelect.addEventListener('change', function () {

            const selectedOption =
                productSelect.options[productSelect.selectedIndex];

            if (selectedOption && selectedOption.value) {
                priceInput.value =
                    selectedOption.dataset.buyingPrice || '';
            }

            updateStockPreview();
        });
    }

    if (quantityInput) {
        quantityInput.addEventListener('input', updateStockPreview);
    }

    if (priceInput) {
        priceInput.addEventListener('input', updateStockPreview);
    }

    updateStockPreview();

    if (stockForm) {
        stockForm.addEventListener('submit', function () {

            if (!stockForm.checkValidity()) {
                return;
            }

            submitButton.disabled = true;
            buttonText.textContent = 'Processing...';
        });
    }

});
</script>

<?php require_once 'includes/footer.php'; ?>
