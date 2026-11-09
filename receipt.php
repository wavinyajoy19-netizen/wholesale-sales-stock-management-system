
<?php
require_once 'includes/auth.php';
requireLogin();

requireRole(['admin', 'accountant', 'cashier']);

require_once 'includes/db.php';

$role = strtolower(trim($_SESSION['role'] ?? ''));
$userId = (int)($_SESSION['user_id'] ?? 0);

// Get sale ID.
$saleId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$saleId || $saleId < 1) {
    http_response_code(400);
    exit('Invalid receipt number.');
}

// Retrieve sale, cashier and customer details.
$stmt = $pdo->prepare("
    SELECT
        s.id,
        s.receipt_number,
        s.user_id,
        s.total_amount,
        s.sale_date,
        s.payment_method,
        s.mpesa_reference,
        s.amount_paid,
        s.balance,
        u.full_name AS cashier_name,
        c.full_name AS customer_name,
        c.phone AS customer_phone,
        c.customer_type
    FROM sales s
    LEFT JOIN users u ON u.id = s.user_id
    LEFT JOIN customers c ON c.id = s.customer_id
    WHERE s.id = ?
    LIMIT 1
");

$stmt->execute([$saleId]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
    http_response_code(404);
    exit('Receipt not found.');
}

// Cashiers can only view their own receipts.
if ($role === 'cashier' && (int)$sale['user_id'] !== $userId) {
    http_response_code(403);
    exit('Access denied. You can only view receipts for your own sales.');
}

// Retrieve products included in the sale.
$stmt = $pdo->prepare("
    SELECT
        p.product_name,
        si.quantity,
        si.unit_price,
        si.subtotal
    FROM sale_items si
    LEFT JOIN products p ON p.id = si.product_id
    WHERE si.sale_id = ?
    ORDER BY si.id ASC
");

$stmt->execute([$saleId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

function receiptEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$paymentMethod = strtolower(
    trim((string)($sale['payment_method'] ?? 'cash'))
);

$mpesaReference = trim((string)($sale['mpesa_reference'] ?? ''));

$customerName = $sale['customer_name'] ?? 'Walk-in Customer';
$customerType = $sale['customer_type'] ?? 'walk-in';
$customerPhone = $sale['customer_phone'] ?? '';

$totalAmount = (float)$sale['total_amount'];
$amountPaid = (float)$sale['amount_paid'];
$balance = (float)$sale['balance'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Receipt - <?= receiptEscape($sale['receipt_number']) ?></title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #eef2f7;
            margin: 0;
            padding: 30px 15px;
            color: #263445;
        }

        .receipt {
            max-width: 700px;
            margin: auto;
            background: #fff;
            padding: 35px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .receipt-header {
            text-align: center;
            padding-bottom: 22px;
            border-bottom: 2px dashed #cbd5e1;
        }

        .receipt-header h1 {
            margin: 0;
            font-size: 27px;
            color: #163b65;
            letter-spacing: 1px;
        }

        .business-details {
            margin-top: 8px;
            color: #64748b;
            font-size: 13px;
            line-height: 1.6;
        }

        .receipt-header h2 {
            margin: 20px 0 0;
            font-size: 20px;
            color: #2563eb;
            letter-spacing: 2px;
        }

        .section-title {
            font-size: 15px;
            font-weight: bold;
            color: #163b65;
            margin: 22px 0 10px;
            padding-bottom: 7px;
            border-bottom: 1px solid #e2e8f0;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 25px;
            line-height: 1.6;
            font-size: 14px;
        }

        .detail-label {
            display: block;
            color: #64748b;
            font-size: 12px;
        }

        .detail-value {
            font-weight: bold;
            overflow-wrap: anywhere;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            font-size: 13px;
        }

        th {
            background: #163b65;
            color: white;
            padding: 12px 8px;
            text-align: left;
        }

        td {
            padding: 12px 8px;
            border-bottom: 1px solid #e2e8f0;
        }

        .number {
            text-align: right;
        }

        .payment-details {
            margin-top: 22px;
            padding: 18px;
            background: #f8fafc;
            border-radius: 8px;
        }

        .payment-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 8px 0;
            font-size: 14px;
        }

        .payment-total {
            border-top: 2px solid #cbd5e1;
            margin-top: 8px;
            padding-top: 14px;
            font-size: 19px;
            font-weight: bold;
            color: #163b65;
        }

        .balance-row {
            color: #b45309;
            font-weight: bold;
        }

        .paid-status {
            text-align: center;
            margin-top: 15px;
            padding: 10px;
            border-radius: 6px;
            background: #dcfce7;
            color: #166534;
            font-weight: bold;
        }

        .balance-status {
            background: #fef3c7;
            color: #92400e;
        }

        .thank-you {
            text-align: center;
            margin-top: 28px;
            padding-top: 20px;
            border-top: 2px dashed #cbd5e1;
        }

        .thank-you h3 {
            color: #163b65;
            margin-bottom: 7px;
        }

        .thank-you p {
            color: #64748b;
            font-size: 13px;
        }

        .actions {
            text-align: center;
            margin: 22px auto;
        }

        .actions button,
        .actions a {
            display: inline-block;
            padding: 12px 22px;
            margin: 5px;
            border: none;
            border-radius: 6px;
            color: white;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
        }

        .print-button {
            background: #2563eb;
        }

        .back-button {
            background: #64748b;
        }

        @media (max-width: 550px) {
            .receipt {
                padding: 20px 14px;
            }

            .details-grid {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 11px;
            }

            th, td {
                padding: 9px 5px;
            }
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }

            .receipt {
                max-width: none;
                box-shadow: none;
                border-radius: 0;
                padding: 10px;
            }

            .actions {
                display: none;
            }

            @page {
                margin: 12mm;
            }
        }
    </style>
</head>

<body>

<div class="receipt">

    <div class="receipt-header">
        <h1>WHOLESALE SALES</h1>

        <div class="business-details">
            <p>Wholesale Sales and Stock Management System</p>
            <p>Business contact details to be added</p>
        </div>

        <h2>SALES RECEIPT</h2>
    </div>

    <h3 class="section-title">Transaction Information</h3>

    <div class="details-grid">

        <div>
            <span class="detail-label">Receipt Number</span>
            <span class="detail-value">
                <?= receiptEscape($sale['receipt_number']) ?>
            </span>
        </div>

        <div>
            <span class="detail-label">Date and Time</span>
            <span class="detail-value">
                <?= receiptEscape(date(
                    'd M Y, h:i A',
                    strtotime($sale['sale_date'])
                )) ?>
            </span>
        </div>

        <div>
            <span class="detail-label">Cashier</span>
            <span class="detail-value">
                <?= receiptEscape($sale['cashier_name'] ?? 'Unknown') ?>
            </span>
        </div>

        <div>
            <span class="detail-label">Payment Method</span>
            <span class="detail-value">
                <?= receiptEscape(strtoupper($paymentMethod)) ?>
            </span>
        </div>

        <?php if ($paymentMethod === 'mpesa' && $mpesaReference !== ''): ?>
            <div>
                <span class="detail-label">M-Pesa Transaction Reference</span>
                <span class="detail-value">
                    <?= receiptEscape($mpesaReference) ?>
                </span>
            </div>
        <?php endif; ?>

    </div>

    <h3 class="section-title">Customer Information</h3>

    <div class="details-grid">

        <div>
            <span class="detail-label">Customer Name</span>
            <span class="detail-value">
                <?= receiptEscape($customerName) ?>
            </span>
        </div>

        <div>
            <span class="detail-label">Customer Category</span>
            <span class="detail-value">
                <?= receiptEscape(ucfirst($customerType)) ?>
            </span>
        </div>

        <div>
            <span class="detail-label">Phone Number</span>
            <span class="detail-value">
                <?= receiptEscape($customerPhone ?: 'Not provided') ?>
            </span>
        </div>

    </div>

    <h3 class="section-title">Items Purchased</h3>

    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th class="number">Qty</th>
                <th class="number">Unit Price</th>
                <th class="number">Subtotal</th>
            </tr>
        </thead>

        <tbody>
            <?php if (empty($items)): ?>
                <tr>
                    <td colspan="4" style="text-align:center;">
                        No items found for this receipt.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <?= receiptEscape(
                                $item['product_name'] ?? 'Product unavailable'
                            ) ?>
                        </td>

                        <td class="number">
                            <?= number_format((int)$item['quantity']) ?>
                        </td>

                        <td class="number">
                            <?= number_format((float)$item['unit_price'], 2) ?>
                        </td>

                        <td class="number">
                            <?= number_format((float)$item['subtotal'], 2) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <h3 class="section-title">Payment Summary</h3>

    <div class="payment-details">

        <div class="payment-row payment-total">
            <span>Total Amount</span>
            <span>KSh <?= number_format($totalAmount, 2) ?></span>
        </div>

        <div class="payment-row">
            <span>Amount Paid</span>
            <strong>KSh <?= number_format($amountPaid, 2) ?></strong>
        </div>

        <div class="payment-row balance-row">
            <span>Outstanding Balance</span>
            <span>KSh <?= number_format($balance, 2) ?></span>
        </div>

    </div>

    <?php if ($balance <= 0): ?>
        <div class="paid-status">
            PAYMENT COMPLETE
        </div>
    <?php else: ?>
        <div class="paid-status balance-status">
            PARTIAL PAYMENT - BALANCE OUTSTANDING
        </div>
    <?php endif; ?>

    <div class="thank-you">
        <h3>Thank You for Shopping With Us!</h3>
        <p>We appreciate your business.</p>
        <p>Please keep this receipt for your records.</p>
    </div>

</div>

<div class="actions">
    <button class="print-button" onclick="window.print()">
        Print Receipt
    </button>

    <a class="back-button" href="sales.php">
        Back to Sales
    </a>
</div>

</body>
</html>
