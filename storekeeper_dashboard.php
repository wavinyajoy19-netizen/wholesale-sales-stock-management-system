
<?php
require_once 'includes/auth.php';
requireStorekeeper();
require_once 'includes/db.php';

$pageTitle = 'Storekeeper Dashboard';
$activePage = 'storekeeper_dashboard';

function storekeeperEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Product totals and stock alerts.
$totalProducts = (int)$pdo->query(
    "SELECT COUNT(*) FROM products"
)->fetchColumn();

$lowStock = (int)$pdo->query(
    "SELECT COUNT(*) FROM products
     WHERE quantity > 0 AND quantity <= reorder_level"
)->fetchColumn();

$outOfStock = (int)$pdo->query(
    "SELECT COUNT(*) FROM products WHERE quantity <= 0"
)->fetchColumn();

// Products requiring attention.
$stmt = $pdo->query(
    "SELECT product_name, product_code, quantity, reorder_level
     FROM products
     WHERE quantity <= reorder_level
     ORDER BY quantity ASC, product_name ASC
     LIMIT 10"
);
$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent stock movements.
$movements = [];
try {
    $stmt = $pdo->query(
        "SELECT * FROM stock_movements ORDER BY id DESC LIMIT 8"
    );
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Stock movement query failed: ' . $e->getMessage());
}

require_once 'includes/header.php';
?>

<div class="page-heading">
    <div>
        <h1>Storekeeper Dashboard</h1>
        <p>Monitor inventory, stock alerts and recent stock activity.</p>
    </div>
</div>

<section class="dashboard-card">
    <h2>
        Welcome,
        <?= storekeeperEscape(
            $_SESSION['full_name']
            ?? $_SESSION['username']
            ?? 'Storekeeper'
        ) ?>
    </h2>
    <p>Use this dashboard to monitor stock and review inventory activity.</p>
</section>

<div class="dashboard-grid">
    <section class="dashboard-card">
        <h3>Total Products</h3>
        <p class="number"><?= $totalProducts ?></p>
    </section>

    <section class="dashboard-card">
        <h3>Low-Stock Products</h3>
        <p class="number orange"><?= $lowStock ?></p>
    </section>

    <section class="dashboard-card">
        <h3>Out-of-Stock Products</h3>
        <p class="number red"><?= $outOfStock ?></p>
    </section>
</div>

<section class="dashboard-card">
    <h2>Stock Management</h2>
    <div class="form-actions">
        <a class="btn-secondary" href="products.php">View Products</a>
        <a class="btn-secondary" href="stock_in.php">Record Stock In</a>
        <a class="btn-secondary" href="stock_history.php">Stock History</a>
    </div>
</section>

<section class="dashboard-card">
    <h2>Stock Alerts</h2>

    <?php if (!$alerts): ?>
        <p>No low-stock or out-of-stock products currently.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Product Code</th>
                        <th>Quantity</th>
                        <th>Reorder Level</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($alerts as $item): ?>
                    <tr>
                        <td><?= storekeeperEscape($item['product_name']) ?></td>
                        <td><?= storekeeperEscape($item['product_code']) ?></td>
                        <td><?= (int)$item['quantity'] ?></td>
                        <td><?= (int)$item['reorder_level'] ?></td>
                        <td>
                            <?php if ((int)$item['quantity'] <= 0): ?>
                                <span class="status-badge">Out of stock</span>
                            <?php else: ?>
                                <span class="status-badge">Low stock</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="dashboard-card">
    <h2>Recent Stock Movements</h2>

    <?php if (!$movements): ?>
        <p>No stock movement records are currently available.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <?php foreach (array_keys($movements[0]) as $column): ?>
                            <th>
                                <?= storekeeperEscape(
                                    ucwords(str_replace('_', ' ', $column))
                                ) ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movements as $movement): ?>
                        <tr>
                            <?php foreach ($movement as $value): ?>
                                <td><?= storekeeperEscape($value) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require_once 'includes/footer.php'; ?>
