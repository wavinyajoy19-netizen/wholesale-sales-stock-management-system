
<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? '';

$menuItems = [
    ['label' => 'Dashboard', 'url' => 'dashboard.php', 'roles' => ['admin', 'cashier']],
    ['label' => 'Accountant Dashboard', 'url' => 'accountant_dashboard.php', 'roles' => ['accountant']],
    ['label' => 'Storekeeper Dashboard', 'url' => 'storekeeper_dashboard.php', 'roles' => ['storekeeper']],
    ['label' => 'Products & Stock', 'url' => 'products.php', 'roles' => ['admin', 'cashier', 'storekeeper']],
    ['label' => 'Suppliers', 'url' => 'suppliers.php', 'roles' => ['admin', 'accountant', 'storekeeper']],
    ['label' => 'Purchase Orders', 'url' => 'purchase_orders.php', 'roles' => ['admin', 'storekeeper']],
    ['label' => 'Receive Deliveries', 'url' => 'receive_deliveries.php', 'roles' => ['admin', 'storekeeper']],
    ['label' => 'Supplier Payments', 'url' => 'supplier_payments.php', 'roles' => ['admin', 'accountant']],
    ['label' => 'Supplier Payment Reports', 'url' => 'supplier_payment_reports.php', 'roles' => ['admin', 'accountant']],
    ['label' => 'Receive Stock', 'url' => 'stock_in.php', 'roles' => ['admin', 'storekeeper']],
    ['label' => 'Stock History', 'url' => 'stock_history.php', 'roles' => ['admin', 'storekeeper']],
    ['label' => 'Record Sales', 'url' => 'sales.php', 'roles' => ['admin', 'cashier']],
    ['label' => 'Sales History', 'url' => 'sales_history.php', 'roles' => ['admin', 'accountant', 'cashier']],
    ['label' => 'Sales Analytics', 'url' => 'sales_analytics.php', 'roles' => ['admin', 'accountant']],
    ['label' => 'Customers', 'url' => 'customers.php', 'roles' => ['admin', 'cashier']],
    ['label' => 'Expenses', 'url' => 'expenses.php', 'roles' => ['admin', 'accountant']],
    ['label' => 'Reports', 'url' => 'reports.php', 'roles' => ['admin', 'accountant', 'cashier']],
    ['label' => 'Manage Users', 'url' => 'users.php', 'roles' => ['admin']]
];
?>

<aside class="app-sidebar">

    <div class="app-brand">
        <h2>Wholesale System</h2>
    </div>

    <nav class="sidebar-nav">

        <?php foreach ($menuItems as $item): ?>

            <?php if (in_array($role, $item['roles'], true)): ?>

                <a
                    href="<?php echo htmlspecialchars($item['url']); ?>"
                    class="sidebar-link <?php echo ($currentPage === $item['url']) ? 'active' : ''; ?>"
                >
                    <span class="sidebar-label">
                        <?php echo htmlspecialchars($item['label']); ?>
                    </span>
                </a>

            <?php endif; ?>

        <?php endforeach; ?>

    </nav>

    <div class="sidebar-bottom">
        <a href="logout.php" class="sidebar-link">
            <span class="sidebar-label">Logout</span>
        </a>
    </div>

</aside>
