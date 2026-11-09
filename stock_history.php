
<?php
require_once 'includes/auth.php';

// Only administrators and storekeepers can view stock history.
requireRole(['admin', 'storekeeper']);

require_once 'includes/db.php';

/*
|--------------------------------------------------------------------------
| Escape output safely
|--------------------------------------------------------------------------
*/
function historyEscape($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$error = '';
$movements = [];

/*
|--------------------------------------------------------------------------
| Retrieve stock movement records
|--------------------------------------------------------------------------
*/
try {
    $sql = "
        SELECT
            sm.id,
            p.product_name,
            p.product_code,
            u.full_name,
            sm.movement_type,
            sm.quantity,
            sm.previous_quantity,
            sm.new_quantity,
            sm.notes,
            sm.movement_date
        FROM stock_movements sm
        LEFT JOIN products p ON p.id = sm.product_id
        LEFT JOIN users u ON u.id = sm.user_id
        ORDER BY sm.movement_date DESC, sm.id DESC
    ";

    $stmt = $pdo->query($sql);
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('Stock movement history error: ' . $e->getMessage());
    $error = 'Unable to load stock movement history. Please check your database.';
}

/*
|--------------------------------------------------------------------------
| Shared dashboard layout
|--------------------------------------------------------------------------
*/
$pageTitle = 'Stock Movement History';
$activePage = 'stock_movements';

require_once 'includes/header.php';
?>

<style>
.history-container {
    width: 100%;
    max-width: 1400px;
    margin: 0 auto;
}

.history-heading {
    margin-bottom: 22px;
}

.history-heading h2 {
    margin: 0 0 8px;
    color: #1f2937;
    font-size: 25px;
}

.history-heading p {
    margin: 0;
    color: #6b7280;
    line-height: 1.6;
}

.history-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 20px;
}

.history-summary-card {
    padding: 18px 22px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    min-width: 190px;
}

.history-summary-card span {
    display: block;
    margin-bottom: 8px;
    color: #6b7280;
    font-size: 14px;
}

.history-summary-card strong {
    color: #111827;
    font-size: 25px;
}

.history-panel {
    padding: 20px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04);
}

.history-table-wrapper {
    width: 100%;
    overflow-x: auto;
}

.history-table {
    width: 100%;
    min-width: 1000px;
    border-collapse: collapse;
    background: #fff;
}

.history-table th,
.history-table td {
    padding: 13px 14px;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
    vertical-align: middle;
    font-size: 14px;
}

.history-table th {
    background: #f3f4f6;
    color: #374151;
    font-weight: 600;
    white-space: nowrap;
}

.history-table tbody tr:hover {
    background: #f9fafb;
}

.history-table td.notes-cell {
    min-width: 180px;
    max-width: 300px;
    white-space: normal;
    overflow-wrap: anywhere;
}

.movement-badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

.movement-stock-in {
    background: #dcfce7;
    color: #166534;
}

.movement-stock-out {
    background: #fee2e2;
    color: #991b1b;
}

.movement-adjustment {
    background: #fef3c7;
    color: #92400e;
}

.movement-other {
    background: #e5e7eb;
    color: #374151;
}

.history-alert {
    padding: 14px 16px;
    margin-bottom: 20px;
    border-radius: 8px;
    line-height: 1.5;
}

.history-alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
}

.history-empty {
    padding: 30px 20px;
    text-align: center;
    background: #f9fafb;
    border-radius: 8px;
    color: #6b7280;
}

.history-empty strong {
    display: block;
    margin-bottom: 8px;
    color: #374151;
    font-size: 17px;
}

.history-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 20px;
}

.history-actions a {
    display: inline-block;
    padding: 10px 14px;
    background: #2563eb;
    color: #fff;
    text-decoration: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
}

.history-actions a:hover {
    background: #1d4ed8;
}

.history-date {
    white-space: nowrap;
}

@media (max-width: 600px) {
    .history-panel {
        padding: 12px;
    }

    .history-heading h2 {
        font-size: 22px;
    }

    .history-summary-card {
        width: 100%;
        box-sizing: border-box;
    }
}
</style>

<div class="history-container">

    <div class="history-heading">
        <h2>Stock Movement History</h2>
        <p>
            Track stock received, stock issued, and stock adjustments.
            Review previous quantities, updated quantities, and who recorded each movement.
        </p>
    </div>

    <div class="history-actions">
        <a href="stock_in.php">Receive Stock</a>
        <a href="products.php">Manage Products</a>
    </div>

    <?php if ($error !== ''): ?>

        <div class="history-alert history-alert-error">
            <?= historyEscape($error) ?>
        </div>

    <?php else: ?>

        <div class="history-summary">
            <div class="history-summary-card">
                <span>Total stock movement records</span>
                <strong><?= count($movements) ?></strong>
            </div>
        </div>

        <div class="history-panel">

            <?php if (empty($movements)): ?>

                <div class="history-empty">
                    <strong>No stock movement records yet</strong>
                    Stock history will appear here after stock is received,
                    sold, or adjusted.
                </div>

            <?php else: ?>

                <div class="history-table-wrapper">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Product</th>
                                <th>Product Code</th>
                                <th>Movement Type</th>
                                <th>Quantity</th>
                                <th>Previous Stock</th>
                                <th>New Stock</th>
                                <th>Recorded By</th>
                                <th>Notes</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($movements as $movement): ?>

                                <?php
                                $type = (string)($movement['movement_type'] ?? '');

                                switch ($type) {
                                    case 'stock_in':
                                        $typeClass = 'movement-stock-in';
                                        break;

                                    case 'stock_out':
                                        $typeClass = 'movement-stock-out';
                                        break;

                                    case 'adjustment':
                                        $typeClass = 'movement-adjustment';
                                        break;

                                    default:
                                        $typeClass = 'movement-other';
                                        break;
                                }

                                $displayType = ucwords(
                                    str_replace('_', ' ', $type)
                                );
                                ?>

                                <tr>
                                    <td class="history-date">
                                        <?= historyEscape($movement['movement_date']) ?>
                                    </td>

                                    <td>
                                        <?= historyEscape(
                                            $movement['product_name'] ?? 'Deleted product'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= historyEscape(
                                            $movement['product_code'] ?? '-'
                                        ) ?>
                                    </td>

                                    <td>
                                        <span class="movement-badge <?= historyEscape($typeClass) ?>">
                                            <?= historyEscape($displayType) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= historyEscape($movement['quantity']) ?>
                                    </td>

                                    <td>
                                        <?= historyEscape($movement['previous_quantity']) ?>
                                    </td>

                                    <td>
                                        <?= historyEscape($movement['new_quantity']) ?>
                                    </td>

                                    <td>
                                        <?= historyEscape(
                                            $movement['full_name'] ?? 'Unknown user'
                                        ) ?>
                                    </td>

                                    <td class="notes-cell">
                                        <?= historyEscape(
                                            $movement['notes'] ?: '-'
                                        ) ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>

        </div>

    <?php endif; ?>

</div>

<?php require_once 'includes/footer.php'; ?>
