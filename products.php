
<?php
require_once 'includes/auth.php';
requireLogin();

require_once 'includes/db.php';

$message = '';
$error = '';

$role = $_SESSION['role'] ?? '';

$isAdmin = ($role === 'admin');
$isAccountant = ($role === 'accountant');

$canViewBuyingPrice = ($isAdmin || $isAccountant);
$canManageProducts = $isAdmin;

/*
|--------------------------------------------------------------------------
| ADD OR EDIT PRODUCTS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$canManageProducts) {
        http_response_code(403);
        exit('Access denied. Administrator permission is required.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {

        $productName = trim($_POST['product_name'] ?? '');
        $productCode = trim($_POST['product_code'] ?? '');

        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        $buyingPrice = filter_var($_POST['buying_price'] ?? null, FILTER_VALIDATE_FLOAT);
        $sellingPrice = filter_var($_POST['selling_price'] ?? null, FILTER_VALIDATE_FLOAT);
        $retailerPrice = filter_var($_POST['retailer_price'] ?? null, FILTER_VALIDATE_FLOAT);
        $traderPrice = filter_var($_POST['trader_price'] ?? null, FILTER_VALIDATE_FLOAT);
        $reorderLevel = filter_var($_POST['reorder_level'] ?? null, FILTER_VALIDATE_INT);
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);

        if (
            $productName === '' ||
            $productCode === '' ||
            $quantity === false || $quantity === null || $quantity < 0 ||
            $buyingPrice === false || $buyingPrice === null || $buyingPrice < 0 ||
            $sellingPrice === false || $sellingPrice === null || $sellingPrice < 0 ||
            $retailerPrice === false || $retailerPrice === null || $retailerPrice < 0 ||
            $traderPrice === false || $traderPrice === null || $traderPrice < 0 ||
            $reorderLevel === false || $reorderLevel === null || $reorderLevel < 0
        ) {
            $error = 'Please enter valid product details. Prices, quantity, and reorder level cannot be negative.';

        } elseif ($action === 'edit' && (!$productId || $productId < 1)) {

            $error = 'Invalid product selected for editing.';

        } else {

            try {

                if ($action === 'add') {

                    $stmt = $pdo->prepare(
                        "INSERT INTO products
                        (
                            product_name,
                            product_code,
                            quantity,
                            buying_price,
                            selling_price,
                            retailer_price,
                            trader_price,
                            reorder_level
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );

                    $stmt->execute([
                        $productName,
                        $productCode,
                        $quantity,
                        $buyingPrice,
                        $sellingPrice,
                        $retailerPrice,
                        $traderPrice,
                        $reorderLevel
                    ]);

                    $message = 'Product added successfully.';

                } else {

                    $stmt = $pdo->prepare(
                        "UPDATE products
                         SET product_name = ?,
                             product_code = ?,
                             quantity = ?,
                             buying_price = ?,
                             selling_price = ?,
                             retailer_price = ?,
                             trader_price = ?,
                             reorder_level = ?
                         WHERE id = ?"
                    );

                    $stmt->execute([
                        $productName,
                        $productCode,
                        $quantity,
                        $buyingPrice,
                        $sellingPrice,
                        $retailerPrice,
                        $traderPrice,
                        $reorderLevel,
                        $productId
                    ]);

                    $message = 'Product updated successfully.';
                }

            } catch (PDOException $e) {

                if ($e->getCode() === '23000') {
                    $error = 'That product code already exists. Please use a different code.';
                } else {
                    error_log($e->getMessage());
                    $error = 'The product could not be saved. Please check the database structure.';
                }
            }
        }

    } else {
        $error = 'Invalid action.';
    }
}

/*
|--------------------------------------------------------------------------
| SEARCH AND DISPLAY PRODUCTS
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');

try {

    $productColumns = "
        id,
        product_name,
        product_code,
        quantity,
        selling_price,
        retailer_price,
        trader_price,
        reorder_level
    ";

    if ($canViewBuyingPrice) {
        $productColumns .= ", buying_price";
    }

    if ($search !== '') {

        $stmt = $pdo->prepare(
            "SELECT $productColumns
             FROM products
             WHERE product_name LIKE ? OR product_code LIKE ?
             ORDER BY product_name ASC"
        );

        $searchTerm = '%' . $search . '%';
        $stmt->execute([$searchTerm, $searchTerm]);

    } else {

        $stmt = $pdo->query(
            "SELECT $productColumns
             FROM products
             ORDER BY product_name ASC"
        );
    }

    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $summaryStmt = $pdo->query(
        "SELECT
            COUNT(*) AS total_products,
            COALESCE(SUM(quantity), 0) AS total_units,
            COALESCE(SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock,
            COALESCE(SUM(CASE WHEN quantity > 0 AND quantity <= reorder_level
                              THEN 1 ELSE 0 END), 0) AS low_stock
         FROM products"
    );

    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log($e->getMessage());
    exit('Unable to load products. Please check your database connection and products table.');
}

function productEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'Products & Stock';
require_once 'includes/header.php';
?>

<style>
/* Product page form layout only */

.products-page .product-form-card {
    margin-bottom: 25px;
}

.products-page .product-fields-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-top: 20px;
}

.products-page .product-field-box {
    background: #f8fafc;
    border: 1px solid #dce4ed;
    border-radius: 10px;
    padding: 15px;
    min-width: 0;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.products-page .product-field-box:focus-within {
    border-color: #2878d0;
    box-shadow: 0 0 0 3px rgba(40, 120, 208, 0.10);
}

.products-page .product-field-box label {
    display: block;
    font-weight: 600;
    font-size: 13px;
    color: #243b53;
    margin-bottom: 10px;
}

.products-page .product-field-box input {
    width: 100%;
    min-width: 0;
    height: 42px;
    padding: 10px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    background: #ffffff;
    color: #243447;
    font-size: 14px;
    outline: none;
}

.products-page .product-field-box input:focus {
    border-color: #2878d0;
}

.products-page .product-form-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 22px;
}

.products-page .product-form-actions button {
    cursor: pointer;
}

.products-page .product-form-note {
    margin-top: 15px;
    font-size: 12px;
    color: #64748b;
}

@media (max-width: 1100px) {
    .products-page .product-fields-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 550px) {
    .products-page .product-fields-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="products-page">

<!-- PAGE HEADING -->

<div class="page-heading">
    <div>
        <h2>Products &amp; Stock Management</h2>
        <p>Manage products, monitor inventory and check stock levels.</p>
    </div>
</div>

<!-- NOTIFICATIONS -->

<?php if ($message !== ''): ?>
    <div class="alert alert-success">
        <?= productEscape($message) ?>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger">
        <?= productEscape($error) ?>
    </div>
<?php endif; ?>

<!-- STOCK SUMMARY -->

<div class="stats-grid">

    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Total Products</span>
            <span class="stat-icon">📦</span>
        </div>
        <div class="stat-number">
            <?= (int) ($summary['total_products'] ?? 0) ?>
        </div>
        <p class="stat-note">Registered products</p>
    </div>

    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Total Units</span>
            <span class="stat-icon">🛒</span>
        </div>
        <div class="stat-number">
            <?= (int) ($summary['total_units'] ?? 0) ?>
        </div>
        <p class="stat-note">Units currently in stock</p>
    </div>

    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Low Stock</span>
            <span class="stat-icon">⚠️</span>
        </div>
        <div class="stat-number">
            <?= (int) ($summary['low_stock'] ?? 0) ?>
        </div>
        <p class="stat-note">Products needing restocking</p>
    </div>

    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">Out of Stock</span>
            <span class="stat-icon">❌</span>
        </div>
        <div class="stat-number">
            <?= (int) ($summary['out_of_stock'] ?? 0) ?>
        </div>
        <p class="stat-note">Products with zero quantity</p>
    </div>

</div>

<!-- ADD NEW PRODUCT -->

<?php if ($canManageProducts): ?>

<section class="dashboard-card product-form-card" id="product-form">

    <div class="panel-header">
        <div>
            <h3 id="form-title">Add New Product</h3>
            <p>Enter product details, stock quantity and selling prices.</p>
        </div>
    </div>

    <form method="POST" action="products.php" class="dashboard-form" id="productForm">

        <input type="hidden" name="action" id="action" value="add">
        <input type="hidden" name="product_id" id="product_id" value="">

        <div class="product-fields-grid">

            <div class="product-field-box">
                <label for="product_name">Product Name</label>
                <input type="text" name="product_name" id="product_name"
                       placeholder="Enter product name" required>
            </div>

            <div class="product-field-box">
                <label for="product_code">Product Code</label>
                <input type="text" name="product_code" id="product_code"
                       placeholder="Enter product code" required>
            </div>

            <div class="product-field-box">
                <label for="quantity">Quantity</label>
                <input type="number" name="quantity" id="quantity"
                       min="0" step="1" placeholder="Enter quantity" required>
            </div>

            <div class="product-field-box">
                <label for="buying_price">Buying Price (KES)</label>
                <input type="number" name="buying_price" id="buying_price"
                       min="0" step="0.01" placeholder="Enter buying price" required>
            </div>

            <div class="product-field-box">
                <label for="selling_price">Walk-in Selling Price (KES)</label>
                <input type="number" name="selling_price" id="selling_price"
                       min="0" step="0.01" placeholder="Enter walk-in price" required>
            </div>

            <div class="product-field-box">
                <label for="retailer_price">Retailer Price (KES)</label>
                <input type="number" name="retailer_price" id="retailer_price"
                       min="0" step="0.01" placeholder="Enter retailer price" required>
            </div>

            <div class="product-field-box">
                <label for="trader_price">Trader Price (KES)</label>
                <input type="number" name="trader_price" id="trader_price"
                       min="0" step="0.01" placeholder="Enter trader price" required>
            </div>

            <div class="product-field-box">
                <label for="reorder_level">Reorder Level</label>
                <input type="number" name="reorder_level" id="reorder_level"
                       min="0" value="10" required>
            </div>

        </div>

        <div class="product-form-actions">

            <button type="submit" id="submit-button" class="action-link">
                Save Product
            </button>

            <button type="button" class="action-link"
                    onclick="resetProductForm()">
                Clear Form
            </button>

        </div>

        <p class="product-form-note">
            Only administrators can add products or change product details and prices.
        </p>

    </form>

</section>

<?php endif; ?>

<!-- PRODUCT LIST -->

<section class="content-panel">

    <div class="panel-header">
        <div>
            <h3>Product List</h3>
            <p>View and search all registered products.</p>
        </div>
    </div>

    <form method="GET" action="products.php" class="dashboard-form">

        <div class="search-form">

            <input
                type="text"
                name="search"
                placeholder="Search by product name or code"
                value="<?= productEscape($search) ?>"
            >

            <button type="submit" class="action-link">
                Search
            </button>

            <a href="products.php" class="action-link">
                Clear Search
            </a>

        </div>

    </form>

    <div class="table-container">

        <table class="dashboard-table">

            <thead>
                <tr>
                    <th>#</th>
                    <th>Product Name</th>
                    <th>Product Code</th>
                    <th>Quantity</th>

                    <?php if ($canViewBuyingPrice): ?>
                        <th>Buying Price</th>
                    <?php endif; ?>

                    <th>Walk-in Price</th>
                    <th>Retailer Price</th>
                    <th>Trader Price</th>
                    <th>Stock Status</th>

                    <?php if ($canManageProducts): ?>
                        <th>Action</th>
                    <?php endif; ?>
                </tr>
            </thead>

            <tbody>

                <?php if (count($products) > 0): ?>

                    <?php foreach ($products as $index => $product): ?>

                        <?php
                        $quantityValue = (int) $product['quantity'];
                        $reorderValue = (int) $product['reorder_level'];

                        if ($quantityValue <= 0) {
                            $statusText = 'Out of Stock';
                            $statusClass = 'status-out';
                        } elseif ($quantityValue <= $reorderValue) {
                            $statusText = 'Low Stock';
                            $statusClass = 'status-low';
                        } else {
                            $statusText = 'In Stock';
                            $statusClass = 'status-in';
                        }
                        ?>

                        <tr>

                            <td><?= $index + 1 ?></td>

                            <td>
                                <strong><?= productEscape($product['product_name']) ?></strong>
                            </td>

                            <td><?= productEscape($product['product_code']) ?></td>

                            <td><?= $quantityValue ?></td>

                            <?php if ($canViewBuyingPrice): ?>
                                <td>
                                    KES <?= number_format((float) $product['buying_price'], 2) ?>
                                </td>
                            <?php endif; ?>

                            <td>
                                KES <?= number_format((float) $product['selling_price'], 2) ?>
                            </td>

                            <td>
                                KES <?= number_format((float) $product['retailer_price'], 2) ?>
                            </td>

                            <td>
                                KES <?= number_format((float) $product['trader_price'], 2) ?>
                            </td>

                            <td>
                                <span class="status-pill <?= productEscape($statusClass) ?>">
                                    <?= productEscape($statusText) ?>
                                </span>
                            </td>

                            <?php if ($canManageProducts): ?>

                                <td>
                                    <button
                                        type="button"
                                        class="action-link"
                                        onclick='editProduct(<?= json_encode([
                                            'id' => (int) $product['id'],
                                            'product_name' => $product['product_name'],
                                            'product_code' => $product['product_code'],
                                            'quantity' => (int) $product['quantity'],
                                            'buying_price' => (float) $product['buying_price'],
                                            'selling_price' => (float) $product['selling_price'],
                                            'retailer_price' => (float) $product['retailer_price'],
                                            'trader_price' => (float) $product['trader_price'],
                                            'reorder_level' => (int) $product['reorder_level']
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'
                                    >
                                        Edit
                                    </button>
                                </td>

                            <?php endif; ?>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td
                            colspan="<?= 8 + ($canViewBuyingPrice ? 1 : 0) + ($canManageProducts ? 1 : 0) ?>"
                            class="empty-message"
                        >
                            No products found.
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>

</div>

<!-- FORM JAVASCRIPT -->

<?php if ($canManageProducts): ?>

<script>

function editProduct(product) {

    document.getElementById('action').value = 'edit';
    document.getElementById('product_id').value = product.id;

    document.getElementById('product_name').value = product.product_name;
    document.getElementById('product_code').value = product.product_code;
    document.getElementById('quantity').value = product.quantity;

    document.getElementById('buying_price').value = product.buying_price;
    document.getElementById('selling_price').value = product.selling_price;
    document.getElementById('retailer_price').value = product.retailer_price;
    document.getElementById('trader_price').value = product.trader_price;

    document.getElementById('reorder_level').value = product.reorder_level;

    document.getElementById('form-title').textContent = 'Edit Product';
    document.getElementById('submit-button').textContent = 'Update Product';

    document.getElementById('product-form').scrollIntoView({
        behavior: 'smooth',
        block: 'start'
    });
}

function resetProductForm() {

    document.getElementById('productForm').reset();

    document.getElementById('action').value = 'add';
    document.getElementById('product_id').value = '';

    document.getElementById('form-title').textContent = 'Add New Product';
    document.getElementById('submit-button').textContent = 'Save Product';
}

</script>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
