
<?php
require_once 'includes/auth.php';
requireLogin();
requireRole(['admin', 'cashier']);

require_once 'includes/db.php';

$pageTitle = 'Customer Management';

$message = '';
$error = '';

function customerEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Register or update customer
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    $name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $type = $_POST['customer_type'] ?? 'walk-in';
    $address = trim($_POST['address'] ?? '');
    $id = filter_var($_POST['customer_id'] ?? null, FILTER_VALIDATE_INT);

    if ($name === '' || mb_strlen($name) > 100) {
        $error = 'Enter a customer name of up to 100 characters.';
    } elseif (mb_strlen($phone) > 20 || mb_strlen($address) > 255) {
        $error = 'The phone number or address is too long.';
    } elseif (!in_array($type, ['retailer', 'trader', 'walk-in'], true)) {
        $error = 'Please select a valid customer category.';
    } elseif (
        !in_array($action, ['add', 'edit'], true) ||
        ($action === 'edit' && (!$id || $id < 1))
    ) {
        $error = 'Invalid customer operation.';
    } else {
        try {
            if ($action === 'edit') {
                $stmt = $pdo->prepare(
                    "UPDATE customers
                     SET full_name = ?, phone = ?, customer_type = ?, address = ?
                     WHERE id = ?"
                );
                $stmt->execute([
                    $name,
                    $phone ?: null,
                    $type,
                    $address ?: null,
                    $id
                ]);
                $message = 'Customer updated successfully!';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO customers
                     (full_name, phone, customer_type, address)
                     VALUES (?, ?, ?, ?)"
                );
                $stmt->execute([
                    $name,
                    $phone ?: null,
                    $type,
                    $address ?: null
                ]);
                $message = 'Customer registered successfully!';
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'Unable to save customer. Check your customers table columns.';
        }
    }
}

// Find customer to edit
$editCustomer = null;
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

if ($editId && $editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$editId]);
    $editCustomer = $stmt->fetch();

    if (!$editCustomer) {
        $error = 'Customer not found.';
    }
}

// Search customer records
$search = trim($_GET['search'] ?? '');

if ($search !== '') {
    $stmt = $pdo->prepare(
        "SELECT * FROM customers
         WHERE full_name LIKE ? OR phone LIKE ?
         ORDER BY full_name"
    );
    $term = '%' . $search . '%';
    $stmt->execute([$term, $term]);
} else {
    $stmt = $pdo->query("SELECT * FROM customers ORDER BY full_name");
}

$customers = $stmt->fetchAll();

require_once 'includes/header.php';
?>

<div class="page-heading">
    <div>
        <h1>Customer Management</h1>
        <p>Register customers and manage retailer, trader, and walk-in records.</p>
    </div>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-success" role="status">
        <?= customerEscape($message) ?>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-error" role="alert">
        <?= customerEscape($error) ?>
    </div>
<?php endif; ?>

<div class="dashboard-grid">
    <section class="dashboard-card">
        <h2><?= $editCustomer ? 'Edit Customer' : 'Register New Customer' ?></h2>

        <form method="POST"
              action="customers.php<?= $editCustomer ? '?edit=' . (int)$editCustomer['id'] : '' ?>">

            <input type="hidden" name="action"
                   value="<?= $editCustomer ? 'edit' : 'add' ?>">

            <?php if ($editCustomer): ?>
                <input type="hidden" name="customer_id"
                       value="<?= (int)$editCustomer['id'] ?>">
            <?php endif; ?>

            <label for="full_name">Customer Name</label>
            <input type="text" id="full_name" name="full_name"
                   maxlength="100"
                   value="<?= customerEscape($editCustomer['full_name'] ?? '') ?>"
                   required>

            <label for="phone">Phone Number</label>
            <input type="tel" id="phone" name="phone" maxlength="20"
                   value="<?= customerEscape($editCustomer['phone'] ?? '') ?>"
                   placeholder="e.g. 0712345678">

            <label for="customer_type">Customer Category</label>
            <select id="customer_type" name="customer_type" required>
                <option value="retailer"
                    <?= ($editCustomer['customer_type'] ?? '') === 'retailer' ? 'selected' : '' ?>>
                    Retailer
                </option>
                <option value="trader"
                    <?= ($editCustomer['customer_type'] ?? '') === 'trader' ? 'selected' : '' ?>>
                    Trader
                </option>
                <option value="walk-in"
                    <?= ($editCustomer['customer_type'] ?? 'walk-in') === 'walk-in' ? 'selected' : '' ?>>
                    Walk-in
                </option>
            </select>

            <label for="address">Address (Optional)</label>
            <input type="text" id="address" name="address" maxlength="255"
                   value="<?= customerEscape($editCustomer['address'] ?? '') ?>">

            <div class="form-actions">
                <button type="submit">
                    <?= $editCustomer ? 'Save Customer Changes' : 'Register Customer' ?>
                </button>

                <?php if ($editCustomer): ?>
                    <a class="btn-secondary" href="customers.php">Cancel Editing</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="dashboard-card">
        <h2>Find Customers</h2>

        <form method="GET" action="customers.php">
            <label for="search">Search by name or phone number</label>
            <input type="text" id="search" name="search"
                   value="<?= customerEscape($search) ?>"
                   placeholder="Enter name or phone">

            <div class="form-actions">
                <button type="submit">Search Customers</button>
                <a class="btn-secondary" href="customers.php">Show All</a>
            </div>
        </form>
    </section>
</div>

<section class="dashboard-card">
    <h2>Registered Customers</h2>
    <p>Total results: <strong><?= count($customers) ?></strong></p>

    <?php if (!$customers): ?>
        <p>
            <?= $search !== '' ? 'No matching customers found.' : 'No customers registered yet.' ?>
        </p>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Category</th>
                        <th>Address</th>
                        <th>Date Registered</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td><?= (int)$customer['id'] ?></td>
                        <td><?= customerEscape($customer['full_name']) ?></td>
                        <td><?= customerEscape($customer['phone'] ?? '') ?></td>
                        <td>
                            <span class="status-badge">
                                <?= customerEscape(ucfirst($customer['customer_type'])) ?>
                            </span>
                        </td>
                        <td><?= customerEscape($customer['address'] ?? '') ?></td>
                        <td><?= customerEscape($customer['created_at'] ?? '') ?></td>
                        <td>
                            <a href="customers.php?edit=<?= (int)$customer['id'] ?>"
                               class="action-link">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require_once 'includes/footer.php'; ?>
