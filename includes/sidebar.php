
<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? '';

$menuItems = [

    [
        'label' => 'Dashboard',
        'url' => 'dashboard.php',
        'icon' => '🏠',
        'roles' => ['admin', 'cashier']
    ],

    [
        'label' => 'Accountant Dashboard',
        'url' => 'accountant_dashboard.php',
        'icon' => '📊',
        'roles' => ['accountant']
    ],

    [
        'label' => 'Storekeeper Dashboard',
        'url' => 'storekeeper_dashboard.php',
        'icon' => '📦',
        'roles' => ['storekeeper']
    ],

    [
        'label' => 'Products & Stock',
        'url' => 'products.php',
        'icon' => '🛒',
        'roles' => ['admin', 'cashier', 'storekeeper']
    ],

    [
        'label' => 'Suppliers',
        'url' => 'suppliers.php',
        'icon' => '🚚',
        'roles' => ['admin', 'accountant', 'storekeeper']
    ],

    [
        'label' => 'Purchase Orders',
        'url' => 'purchase_orders.php',
        'icon' => '📋',
        'roles' => ['admin', 'storekeeper']
    ],

    [
        'label' => 'Receive Deliveries',
        'url' => 'receive_deliveries.php',
        'icon' => '📦',
        'roles' => ['admin', 'storekeeper']
    ],

    [
        'label' => 'Supplier Payments',
        'url' => 'supplier_payments.php',
        'icon' => '💳',
        'roles' => ['admin', 'accountant']
    ],

    [
        'label' => 'Supplier Payment Reports',
        'url' => 'supplier_payment_reports.php',
        'icon' => '📑',
        'roles' => ['admin', 'accountant']
    ],

    [
        'label' => 'Receive Stock',
        'url' => 'stock_in.php',
        'icon' => '➕',
        'roles' => ['admin', 'storekeeper']
    ],

    [
        'label' => 'Stock History',
        'url' => 'stock_history.php',
        'icon' => '📜',
        'roles' => ['admin', 'storekeeper']
    ],

    [
        'label' => 'Record Sales',
        'url' => 'sales.php',
        'icon' => '💰',
        'roles' => ['admin', 'cashier']
    ],

    [
        'label' => 'Sales History',
        'url' => 'sales_history.php',
        'icon' => '📋',
        'roles' => ['admin', 'accountant', 'cashier']
    ],

    [
        'label' => 'Sales Analytics',
        'url' => 'sales_analytics.php',
        'icon' => '📊',
        'roles' => ['admin', 'accountant']
    ],

    [
        'label' => 'Customers',
        'url' => 'customers.php',
        'icon' => '👥',
        'roles' => ['admin', 'cashier']
    ],

    [
        'label' => 'Expenses',
        'url' => 'expenses.php',
        'icon' => '💳',
        'roles' => ['admin', 'accountant']
    ],

    [
        'label' => 'Reports',
        'url' => 'reports.php',
        'icon' => '📈',
        'roles' => ['admin', 'accountant', 'cashier']
    ],

    [
        'label' => 'Manage Users',
        'url' => 'users.php',
        'icon' => '⚙️',
        'roles' => ['admin']
    ],

];

$homePage = 'index.php';

if ($role === 'admin' || $role === 'cashier') {
    $homePage = 'dashboard.php';
} elseif ($role === 'accountant') {
    $homePage = 'accountant_dashboard.php';
} elseif ($role === 'storekeeper') {
    $homePage = 'storekeeper_dashboard.php';
}
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
                    <span class="sidebar-icon">
                        <?php echo $item['icon']; ?>
                    </span>

                    <span class="sidebar-label">
                        <?php echo htmlspecialchars($item['label']); ?>
                    </span>
                </a>

            <?php endif; ?>

        <?php endforeach; ?>

    </nav>

    <div class="sidebar-bottom">

        <a href="logout.php" class="sidebar-link">
            <span class="sidebar-icon">🚪</span>
            <span class="sidebar-label">Logout</span>
        </a>

    </div>

</aside>
