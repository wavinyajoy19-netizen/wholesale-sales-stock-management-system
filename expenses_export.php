
<?php
require_once 'includes/auth.php';
requireAdmin();
require_once 'includes/db.php';

function exportEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function exportDateIsValid(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

$today = date('Y-m-d');
$startDate = (string)($_GET['start_date'] ?? $today);
$endDate = (string)($_GET['end_date'] ?? $today);
$search = trim((string)($_GET['search'] ?? ''));

if (
    !exportDateIsValid($startDate) ||
    !exportDateIsValid($endDate) ||
    $startDate > $endDate ||
    strlen($search) > 150
) {
    http_response_code(400);
    exit('Invalid date range or search value.');
}

$sql = 'SELECT e.expense_date, e.expense_name, e.category,
               e.amount, e.description, u.full_name AS recorded_by_name
        FROM expenses e
        LEFT JOIN users u ON e.recorded_by = u.id
        WHERE e.expense_date BETWEEN ? AND ?';
$params = [$startDate, $endDate];

if ($search !== '') {
    $sql .= ' AND (e.expense_name LIKE ? OR e.description LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$sql .= ' ORDER BY e.expense_date DESC, e.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="expenses_report.csv"');

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, [
    'Expense Date', 'Expense Name', 'Category',
    'Amount (KSh)', 'Description', 'Recorded By'
]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Prevent spreadsheet formula execution from text fields.
    foreach (['expense_name', 'category', 'description', 'recorded_by_name'] as $field) {
        if (isset($row[$field]) && preg_match('/^[\s]*[=+\-@]/', (string)$row[$field])) {
            $row[$field] = "'" . $row[$field];
        }
    }

    fputcsv($output, [
        $row['expense_date'],
        $row['expense_name'],
        $row['category'],
        $row['amount'],
        $row['description'],
        $row['recorded_by_name']
    ]);
}

fclose($output);
exit;
