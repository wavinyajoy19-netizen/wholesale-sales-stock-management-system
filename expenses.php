
<?php
require_once 'includes/auth.php';
requireLogin();
requireRole(['admin', 'accountant']);

require_once 'includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'Expenses Management';
$activePage = 'expenses';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$errors = [];
$successMessage = '';

$categories = [
    'Rent',
    'Transport',
    'Electricity',
    'Water',
    'Salaries',
    'Repairs',
    'Packaging',
    'Other'
];

function expenseEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function validateExpenseInput(array $categories): array
{
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $amount = $_POST['amount'] ?? '';
    $expenseDate = $_POST['expense_date'] ?? date('Y-m-d');

    if ($description === '') {
        throw new InvalidArgumentException('Enter the expense description.');
    }

    if (!in_array($category, $categories, true)) {
        throw new InvalidArgumentException('Select a valid expense category.');
    }

    if (!is_numeric($amount) || (float)$amount <= 0) {
        throw new InvalidArgumentException('Enter an amount greater than zero.');
    }

    $dateObject = DateTime::createFromFormat('!Y-m-d', $expenseDate);

    if (!$dateObject || $dateObject->format('Y-m-d') !== $expenseDate) {
        throw new InvalidArgumentException('Select a valid expense date.');
    }

    return [
        'description' => $description,
        'category' => $category,
        'amount' => round((float)$amount, 2),
        'expense_date' => $expenseDate
    ];
}

function verifyExpenseCsrf(string $csrfToken): void
{
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)) {
        throw new RuntimeException(
            'Your session has expired. Refresh the page and try again.'
        );
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyExpenseCsrf($csrfToken);

        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $data = validateExpenseInput($categories);

            $stmt = $pdo->prepare(
                'INSERT INTO expenses
                (description, category, amount, expense_date)
                VALUES (?, ?, ?, ?)'
            );

            $stmt->execute([
                $data['description'],
                $data['category'],
                $data['amount'],
                $data['expense_date']
            ]);

            $_SESSION['expense_flash'] = 'Expense recorded successfully.';
            header('Location: expenses.php');
            exit;
        }

        if ($action === 'update') {
            $expenseId = filter_input(
                INPUT_POST,
                'expense_id',
                FILTER_VALIDATE_INT
            );

            if (!$expenseId || $expenseId < 1) {
                throw new InvalidArgumentException('Invalid expense selected.');
            }

            $data = validateExpenseInput($categories);

            $stmt = $pdo->prepare(
                'UPDATE expenses
                 SET description = ?, category = ?, amount = ?, expense_date = ?
                 WHERE id = ?'
            );

            $stmt->execute([
                $data['description'],
                $data['category'],
                $data['amount'],
                $data['expense_date'],
                $expenseId
            ]);

            $_SESSION['expense_flash'] = 'Expense updated successfully.';
            header('Location: expenses.php');
            exit;
        }

        if ($action === 'delete') {
            $expenseId = filter_input(
                INPUT_POST,
                'expense_id',
                FILTER_VALIDATE_INT
            );

            if (!$expenseId || $expenseId < 1) {
                throw new InvalidArgumentException('Invalid expense selected.');
            }

            $stmt = $pdo->prepare('DELETE FROM expenses WHERE id = ?');
            $stmt->execute([$expenseId]);

            $_SESSION['expense_flash'] = 'Expense deleted successfully.';
            header('Location: expenses.php');
            exit;
        }

        throw new InvalidArgumentException('Invalid form action.');
    }
} catch (Throwable $e) {
    if ($e instanceof PDOException) {
        $errors[] = 'A database error occurred. Check that the expenses table has the required columns.';
    } else {
        $errors[] = $e->getMessage();
    }
}

if (!empty($_SESSION['expense_flash'])) {
    $successMessage = $_SESSION['expense_flash'];
    unset($_SESSION['expense_flash']);
}

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$search = trim($_GET['search'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');

$validStart = DateTime::createFromFormat('!Y-m-d', $startDate);
$validEnd = DateTime::createFromFormat('!Y-m-d', $endDate);

if (!$validStart || $validStart->format('Y-m-d') !== $startDate) {
    $startDate = date('Y-m-01');
}

if (!$validEnd || $validEnd->format('Y-m-d') !== $endDate) {
    $endDate = date('Y-m-d');
}

if (!in_array($categoryFilter, $categories, true)) {
    $categoryFilter = '';
}

$editExpense = null;

if (isset($_GET['edit'])) {
    $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

    if ($editId && $editId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM expenses WHERE id = ?');
        $stmt->execute([$editId]);
        $editExpense = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

$where = ['expense_date BETWEEN ? AND ?'];
$params = [$startDate, $endDate];

if ($search !== '') {
    $where[] = 'description LIKE ?';
    $params[] = '%' . $search . '%';
}

if ($categoryFilter !== '') {
    $where[] = 'category = ?';
    $params[] = $categoryFilter;
}

$sql = 'SELECT * FROM expenses WHERE ' . implode(' AND ', $where)
     . ' ORDER BY expense_date DESC, id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    'SELECT COALESCE(SUM(amount), 0)
     FROM expenses
     WHERE expense_date BETWEEN ? AND ?'
);
$stmt->execute([$startDate, $endDate]);
$totalExpenses = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    'SELECT category, COALESCE(SUM(amount), 0) AS total
     FROM expenses
     WHERE expense_date BETWEEN ? AND ?
     GROUP BY category
     ORDER BY total DESC'
);
$stmt->execute([$startDate, $endDate]);
$categoryTotals = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<style>
.expense-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 24px;
}
.expense-card, .expense-panel {
    background: var(--card-bg, #fff);
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
}
.expense-card span {
    display: block;
    color: #64748b;
    font-size: 14px;
    margin-bottom: 8px;
}
.expense-card strong {
    font-size: 25px;
    color: #172033;
}
.expense-layout {
    display: grid;
    grid-template-columns: minmax(260px, 0.85fr) minmax(0, 2fr);
    gap: 22px;
    align-items: start;
}
.expense-panel h2 {
    margin-top: 0;
    margin-bottom: 18px;
    font-size: 19px;
}
.expense-form label, .filter-form label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    font-size: 14px;
}
.expense-form input, .expense-form select,
.filter-form input, .filter-form select {
    width: 100%;
    padding: 10px 12px;
    margin-bottom: 14px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    box-sizing: border-box;
}
.expense-button {
    display: inline-block;
    padding: 10px 14px;
    border: 0;
    border-radius: 7px;
    background: #2563eb;
    color: white;
    text-decoration: none;
    cursor: pointer;
    font-size: 14px;
}
.expense-button.secondary { background: #64748b; }
.expense-button.danger { background: #dc2626; }
.expense-button.success { background: #15803d; }
.expense-actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
    align-items: center;
}
.expense-actions form { margin: 0; }
.expense-actions button { padding: 7px 10px; }
.expense-alert {
    padding: 13px 16px;
    margin-bottom: 18px;
    border-radius: 8px;
}
.expense-alert.success {
    background: #dcfce7;
    color: #166534;
}
.expense-alert.error {
    background: #fee2e2;
    color: #991b1b;
}
.filter-form {
    display: grid;
    grid-template-columns: repeat(4, minmax(120px, 1fr));
    gap: 12px;
    align-items: end;
}
.filter-form input, .filter-form select { margin-bottom: 0; }
.filter-form .filter-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 0;
}
.expense-table-wrap { overflow-x: auto; }
.expense-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}
.expense-table th, .expense-table td {
    text-align: left;
    padding: 12px 10px;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
}
.expense-table th {
    background: #f8fafc;
    color: #475569;
    font-size: 13px;
}
.category-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 16px;
}
.category-chip {
    padding: 10px 12px;
    background: #f1f5f9;
    border-radius: 8px;
    font-size: 13px;
}
@media (max-width: 900px) {
    .expense-layout { grid-template-columns: 1fr; }
    .filter-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 600px) {
    .expense-grid { grid-template-columns: 1fr; }
    .filter-form { grid-template-columns: 1fr; }
}
@media print {
    .sidebar, .topbar, .expense-form-panel, .filter-panel,
    .expense-actions, .app-footer { display: none !important; }
    .app-main { margin: 0 !important; }
    .expense-panel, .expense-card { border: 0; }
    body { background: white !important; }
}
</style>

<?php if ($successMessage !== ''): ?>
    <div class="expense-alert success">
        <?= expenseEscape($successMessage) ?>
    </div>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="expense-alert error">
        <?= expenseEscape($error) ?>
    </div>
<?php endforeach; ?>

<div class="expense-grid">
    <div class="expense-card">
        <span>Total expenses for selected dates</span>
        <strong>KSh <?= number_format($totalExpenses, 2) ?></strong>
    </div>

    <div class="expense-card">
        <span>Number of matching records</span>
        <strong><?= count($expenses) ?></strong>
    </div>

    <div class="expense-card">
        <span>Selected period</span>
        <strong style="font-size:16px">
            <?= expenseEscape($startDate) ?> to <?= expenseEscape($endDate) ?>
        </strong>
    </div>
</div>

<div class="expense-layout">
    <section class="expense-panel expense-form-panel">
        <h2><?= $editExpense ? 'Edit Expense' : 'Record New Expense' ?></h2>

        <form method="POST" action="expenses.php" class="expense-form">
            <input type="hidden" name="csrf_token"
                   value="<?= expenseEscape($csrfToken) ?>">

            <input type="hidden" name="action"
                   value="<?= $editExpense ? 'update' : 'add' ?>">

            <?php if ($editExpense): ?>
                <input type="hidden" name="expense_id"
                       value="<?= (int)$editExpense['id'] ?>">
            <?php endif; ?>

            <label for="description">Expense Description</label>
            <input type="text" id="description" name="description"
                   maxlength="255" required
                   value="<?= expenseEscape($editExpense['description'] ?? '') ?>"
                   placeholder="e.g. Shop rent">

            <label for="category">Category</label>
            <select id="category" name="category" required>
                <option value="">Select category</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= expenseEscape($category) ?>"
                        <?= (($editExpense['category'] ?? '') === $category)
                            ? 'selected' : '' ?>>
                        <?= expenseEscape($category) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="amount">Amount (KSh)</label>
            <input type="number" id="amount" name="amount"
                   min="0.01" step="0.01" required
                   value="<?= expenseEscape($editExpense['amount'] ?? '') ?>"
                   placeholder="Enter amount">

            <label for="expense_date">Expense Date</label>
            <input type="date" id="expense_date" name="expense_date"
                   required value="<?= expenseEscape(
                       $editExpense['expense_date'] ?? date('Y-m-d')
                   ) ?>">

            <button type="submit" class="expense-button">
                <?= $editExpense ? 'Update Expense' : 'Save Expense' ?>
            </button>

            <?php if ($editExpense): ?>
                <a href="expenses.php" class="expense-button secondary">
                    Cancel
                </a>
            <?php endif; ?>
        </form>
    </section>

    <section class="expense-panel">
        <h2>Expenses by Category</h2>

        <?php if (!$categoryTotals): ?>
            <p>No expenses recorded for this period.</p>
        <?php else: ?>
            <div class="category-summary">
                <?php foreach ($categoryTotals as $row): ?>
                    <div class="category-chip">
                        <strong><?= expenseEscape($row['category']) ?></strong><br>
                        KSh <?= number_format((float)$row['total'], 2) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<section class="expense-panel filter-panel" style="margin-top:22px">
    <h2>Search and Filter Expenses</h2>

    <form method="GET" action="expenses.php" class="filter-form">
        <div>
            <label for="start_date">From Date</label>
            <input type="date" id="start_date" name="start_date"
                   value="<?= expenseEscape($startDate) ?>">
        </div>

        <div>
            <label for="end_date">To Date</label>
            <input type="date" id="end_date" name="end_date"
                   value="<?= expenseEscape($endDate) ?>">
        </div>

        <div>
            <label for="search">Description</label>
            <input type="search" id="search" name="search"
                   value="<?= expenseEscape($search) ?>"
                   placeholder="Search description">
        </div>

        <div>
            <label for="category_filter">Category</label>
            <select id="category_filter" name="category">
                <option value="">All categories</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= expenseEscape($category) ?>"
                        <?= $categoryFilter === $category ? 'selected' : '' ?>>
                        <?= expenseEscape($category) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-buttons">
            <button type="submit" class="expense-button">Apply Filters</button>
            <a href="expenses.php" class="expense-button secondary">Reset</a>
            <button type="button" class="expense-button success"
                    onclick="window.print()">Print</button>
        </div>
    </form>
</section>

<section class="expense-panel" style="margin-top:22px">
    <h2>Expense Records</h2>

    <div class="expense-table-wrap">
        <table class="expense-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Description</th>
                    <th>Category</th>
                    <th>Amount (KSh)</th>
                    <th class="expense-actions">Actions</th>
                </tr>
            </thead>

            <tbody>
            <?php if (!$expenses): ?>
                <tr>
                    <td colspan="5">No expenses found for the selected filters.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($expenses as $expense): ?>
                    <tr>
                        <td><?= expenseEscape($expense['expense_date']) ?></td>
                        <td><?= expenseEscape($expense['description']) ?></td>
                        <td><?= expenseEscape($expense['category']) ?></td>
                        <td><?= number_format((float)$expense['amount'], 2) ?></td>
                        <td>
                            <div class="expense-actions">
                                <a class="expense-button secondary"
                                   href="expenses.php?edit=<?= (int)$expense['id'] ?>">
                                    Edit
                                </a>

                                <form method="POST" action="expenses.php"
                                      onsubmit="return confirm('Are you sure you want to delete this expense?');">
                                    <input type="hidden" name="csrf_token"
                                           value="<?= expenseEscape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="expense_id"
                                           value="<?= (int)$expense['id'] ?>">
                                    <button type="submit"
                                            class="expense-button danger">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>

            <?php if ($expenses): ?>
                <tfoot>
                    <tr>
                        <th colspan="3">Total of displayed records</th>
                        <th colspan="2">
                            KSh <?php
                            $displayedTotal = array_sum(
                                array_map(
                                    fn($item) => (float)$item['amount'],
                                    $expenses
                                )
                            );
                            echo number_format($displayedTotal, 2);
                            ?>
                        </th>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>
