
<?php
require_once 'includes/auth.php';
requireLogin();

require_once 'includes/db.php';

$message = '';
$error = '';

$role = $_SESSION['role'] ?? '';
$canManageSuppliers = ($role === 'admin');

/*
|--------------------------------------------------------------------------
| ADD OR EDIT SUPPLIERS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$canManageSuppliers) {
        http_response_code(403);
        exit('Access denied. Administrator permission is required.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {

        $supplierName = trim($_POST['supplier_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        $supplierId = filter_var(
            $_POST['supplier_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($supplierName === '' || $phone === '') {

            $error = 'Supplier name and phone number are required.';

        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $error = 'Please enter a valid email address.';

        } elseif ($action === 'edit' && (!$supplierId || $supplierId < 1)) {

            $error = 'Invalid supplier selected for editing.';

        } else {

            try {

                if ($action === 'add') {

                    $stmt = $pdo->prepare(
                        "INSERT INTO suppliers
                        (supplier_name, phone, email, address)
                        VALUES (?, ?, ?, ?)"
                    );

                    $stmt->execute([
                        $supplierName,
                        $phone,
                        $email !== '' ? $email : null,
                        $address !== '' ? $address : null
                    ]);

                    $message = 'Supplier registered successfully.';

                } else {

                    $stmt = $pdo->prepare(
                        "UPDATE suppliers
                         SET supplier_name = ?,
                             phone = ?,
                             email = ?,
                             address = ?
                         WHERE id = ?"
                    );

                    $stmt->execute([
                        $supplierName,
                        $phone,
                        $email !== '' ? $email : null,
                        $address !== '' ? $address : null,
                        $supplierId
                    ]);

                    $message = 'Supplier details updated successfully.';
                }

            } catch (PDOException $e) {

                error_log($e->getMessage());
                $error = 'Unable to save supplier details. Please try again.';
            }
        }

    } else {

        $error = 'Invalid action.';
    }
}

/*
|--------------------------------------------------------------------------
| SEARCH AND DISPLAY SUPPLIERS
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');

try {

    if ($search !== '') {

        $stmt = $pdo->prepare(
            "SELECT *
             FROM suppliers
             WHERE supplier_name LIKE ?
                OR phone LIKE ?
                OR email LIKE ?
                OR address LIKE ?
             ORDER BY supplier_name ASC"
        );

        $searchTerm = '%' . $search . '%';

        $stmt->execute([
            $searchTerm,
            $searchTerm,
            $searchTerm,
            $searchTerm
        ]);

    } else {

        $stmt = $pdo->query(
            "SELECT *
             FROM suppliers
             ORDER BY supplier_name ASC"
        );
    }

    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $summaryStmt = $pdo->query(
        "SELECT COUNT(*) AS total_suppliers
         FROM suppliers"
    );

    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log($e->getMessage());
    exit('Unable to load suppliers. Please check your database connection and suppliers table.');
}

function supplierEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'Suppliers Management';
require_once 'includes/header.php';
?>

<style>

/* SUPPLIERS PAGE DESIGN */

.suppliers-page .supplier-form-card {
    margin-bottom: 25px;
}

.suppliers-page .supplier-fields-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin-top: 20px;
}

.suppliers-page .supplier-field-box {
    background: #f8fafc;
    border: 1px solid #dce4ed;
    border-radius: 10px;
    padding: 15px;
    min-width: 0;
}

.suppliers-page .supplier-field-box:focus-within {
    border-color: #2878d0;
    box-shadow: 0 0 0 3px rgba(40, 120, 208, 0.10);
}

.suppliers-page .supplier-field-box label {
    display: block;
    font-weight: 600;
    font-size: 13px;
    color: #243b53;
    margin-bottom: 10px;
}

.suppliers-page .supplier-field-box input,
.suppliers-page .supplier-field-box textarea {
    width: 100%;
    min-width: 0;
    padding: 11px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    background: #ffffff;
    color: #243447;
    font-size: 14px;
    outline: none;
    box-sizing: border-box;
}

.suppliers-page .supplier-field-box input {
    height: 42px;
}

.suppliers-page .supplier-field-box textarea {
    min-height: 85px;
    resize: vertical;
}

.suppliers-page .supplier-field-box input:focus,
.suppliers-page .supplier-field-box textarea:focus {
    border-color: #2878d0;
}

.suppliers-page .supplier-form-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 22px;
}

.suppliers-page .supplier-form-note {
    margin-top: 15px;
    font-size: 12px;
    color: #64748b;
}

.suppliers-page .supplier-summary {
    margin-bottom: 22px;
}

@media (max-width: 650px) {
    .suppliers-page .supplier-fields-grid {
        grid-template-columns: 1fr;
    }
}

</style>

<div class="suppliers-page">

    <!-- PAGE HEADING -->

    <div class="page-heading">
        <div>
            <h2>Suppliers Management</h2>
            <p>Register suppliers and manage their contact information.</p>
        </div>
    </div>

    <!-- NOTIFICATIONS -->

    <?php if ($message !== ''): ?>
        <div class="alert alert-success">
            <?= supplierEscape($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger">
            <?= supplierEscape($error) ?>
        </div>
    <?php endif; ?>

    <!-- SUPPLIER SUMMARY -->

    <div class="stats-grid supplier-summary">

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Total Suppliers</span>
                <span class="stat-icon">🏢</span>
            </div>

            <div class="stat-number">
                <?= (int) ($summary['total_suppliers'] ?? 0) ?>
            </div>

            <p class="stat-note">Registered suppliers</p>
        </div>

    </div>

    <!-- ADD OR EDIT SUPPLIER -->

    <?php if ($canManageSuppliers): ?>

    <section class="dashboard-card supplier-form-card" id="supplier-form">

        <div class="panel-header">
            <div>
                <h3 id="form-title">Register New Supplier</h3>
                <p>Enter the supplier's business and contact details.</p>
            </div>
        </div>

        <form method="POST" action="suppliers.php"
              class="dashboard-form" id="supplierForm">

            <input type="hidden" name="action" id="action" value="add">

            <input type="hidden" name="supplier_id"
                   id="supplier_id" value="">

            <div class="supplier-fields-grid">

                <div class="supplier-field-box">
                    <label for="supplier_name">Supplier Name</label>

                    <input
                        type="text"
                        name="supplier_name"
                        id="supplier_name"
                        placeholder="Enter supplier or business name"
                        maxlength="150"
                        required
                    >
                </div>

                <div class="supplier-field-box">
                    <label for="phone">Phone Number</label>

                    <input
                        type="tel"
                        name="phone"
                        id="phone"
                        placeholder="Enter phone number"
                        maxlength="30"
                        required
                    >
                </div>

                <div class="supplier-field-box">
                    <label for="email">Email Address (Optional)</label>

                    <input
                        type="email"
                        name="email"
                        id="email"
                        placeholder="Enter email address"
                        maxlength="150"
                    >
                </div>

                <div class="supplier-field-box">
                    <label for="address">Physical Address (Optional)</label>

                    <textarea
                        name="address"
                        id="address"
                        placeholder="Enter supplier's physical address"
                        maxlength="255"
                    ></textarea>
                </div>

            </div>

            <div class="supplier-form-actions">

                <button type="submit" id="submit-button"
                        class="action-link">
                    Save Supplier
                </button>

                <button type="button" class="action-link"
                        onclick="resetSupplierForm()">
                    Clear Form
                </button>

            </div>

            <p class="supplier-form-note">
                Only administrators can register or edit supplier details.
            </p>

        </form>

    </section>

    <?php endif; ?>

    <!-- SUPPLIER LIST -->

    <section class="content-panel">

        <div class="panel-header">
            <div>
                <h3>Registered Suppliers</h3>
                <p>View and search suppliers registered in the system.</p>
            </div>
        </div>

        <form method="GET" action="suppliers.php"
              class="dashboard-form">

            <div class="search-form">

                <input
                    type="text"
                    name="search"
                    placeholder="Search supplier name, phone or email"
                    value="<?= supplierEscape($search) ?>"
                >

                <button type="submit" class="action-link">
                    Search
                </button>

                <a href="suppliers.php" class="action-link">
                    Clear Search
                </a>

            </div>

        </form>

        <div class="table-container">

            <table class="dashboard-table">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Supplier Name</th>
                        <th>Phone Number</th>
                        <th>Email Address</th>
                        <th>Physical Address</th>
                        <th>Date Registered</th>

                        <?php if ($canManageSuppliers): ?>
                            <th>Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>

                <tbody>

                    <?php if (count($suppliers) > 0): ?>

                        <?php foreach ($suppliers as $index => $supplier): ?>

                            <tr>

                                <td><?= $index + 1 ?></td>

                                <td>
                                    <strong>
                                        <?= supplierEscape($supplier['supplier_name']) ?>
                                    </strong>
                                </td>

                                <td>
                                    <?= supplierEscape($supplier['phone']) ?>
                                </td>

                                <td>
                                    <?= supplierEscape($supplier['email'] ?: 'Not provided') ?>
                                </td>

                                <td>
                                    <?= supplierEscape($supplier['address'] ?: 'Not provided') ?>
                                </td>

                                <td>
                                    <?= supplierEscape($supplier['created_at']) ?>
                                </td>

                                <?php if ($canManageSuppliers): ?>

                                    <td>

                                        <button
                                            type="button"
                                            class="action-link"
                                            onclick='editSupplier(<?= json_encode([
                                                'id' => (int) $supplier['id'],
                                                'supplier_name' => $supplier['supplier_name'],
                                                'phone' => $supplier['phone'],
                                                'email' => $supplier['email'],
                                                'address' => $supplier['address']
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
                            <td colspan="<?= $canManageSuppliers ? 7 : 6 ?>"
                                class="empty-message">
                                No suppliers found.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</div>

<!-- SUPPLIER FORM JAVASCRIPT -->

<?php if ($canManageSuppliers): ?>

<script>

function editSupplier(supplier) {

    document.getElementById('action').value = 'edit';

    document.getElementById('supplier_id').value = supplier.id;

    document.getElementById('supplier_name').value = supplier.supplier_name;

    document.getElementById('phone').value = supplier.phone;

    document.getElementById('email').value = supplier.email || '';

    document.getElementById('address').value = supplier.address || '';

    document.getElementById('form-title').textContent = 'Edit Supplier';

    document.getElementById('submit-button').textContent = 'Update Supplier';

    document.getElementById('supplier-form').scrollIntoView({
        behavior: 'smooth',
        block: 'start'
    });
}

function resetSupplierForm() {

    document.getElementById('supplierForm').reset();

    document.getElementById('action').value = 'add';

    document.getElementById('supplier_id').value = '';

    document.getElementById('form-title').textContent = 'Register New Supplier';

    document.getElementById('submit-button').textContent = 'Save Supplier';
}

</script>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
