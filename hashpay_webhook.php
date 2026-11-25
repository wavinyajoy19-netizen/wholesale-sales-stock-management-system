
<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/hashpay_config.php';

header('Content-Type: text/plain; charset=utf-8');

// Read the original request body.
$rawBody = file_get_contents('php://input');

$signature = $_SERVER['HTTP_X_HASHPAY_SIGNATURE'] ?? '';

$expectedSignature = 'sha256=' . hash_hmac(
    'sha256',
    $rawBody,
    HASHPAY_WEBHOOK_SECRET
);

// Verify HashPay's signature.
if (!hash_equals($expectedSignature, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}

// Decode the payment notification.
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    http_response_code(400);
    exit('Invalid JSON');
}

// Confirm that this is a successful payment for our account.
if (
    ($payload['event'] ?? '') !== 'payment.success' ||
    (string)($payload['ResponseCode'] ?? '') !== '0' ||
    ($payload['AccountID'] ?? '') !== HASHPAY_ACCOUNT_ID
) {
    http_response_code(200);
    exit('Event ignored');
}

$reference = trim((string)($payload['TransactionReference'] ?? ''));
$transactionId = trim((string)($payload['TransactionID'] ?? ''));
$transactionReceipt = trim((string)($payload['TransactionReceipt'] ?? ''));
$checkoutId = trim((string)($payload['CheckoutRequestID'] ?? ''));
$transactionAmount = $payload['TransactionAmount'] ?? null;

if (
    $reference === '' ||
    $transactionId === '' ||
    $transactionReceipt === '' ||
    !is_numeric($transactionAmount)
) {
    http_response_code(400);
    exit('Missing payment information');
}

// Compare money using cents to avoid decimal rounding errors.
function amountInCents($amount): int {
    return (int) round((float)$amount * 100);
}

try {
    $pdo->beginTransaction();

    // Find and lock the pending sale.
    $stmt = $pdo->prepare(
        "SELECT * FROM hashpay_pending_sales
         WHERE reference = ?
         FOR UPDATE"
    );
    $stmt->execute([$reference]);
    $pendingSale = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pendingSale) {
        $pdo->commit();
        http_response_code(200);
        exit('Pending sale not found');
    }

    // Prevent duplicate processing.
    if (in_array($pendingSale['status'], ['paid', 'paid_needs_review'], true)) {
        $pdo->commit();
        http_response_code(200);
        exit('Payment already processed');
    }

    // Save verified payment details if the amount is incorrect.
    if (
        amountInCents($transactionAmount) !==
        amountInCents($pendingSale['total_amount'])
    ) {
        $stmt = $pdo->prepare(
            "UPDATE hashpay_pending_sales
             SET status = 'paid_needs_review',
                 transaction_id = ?,
                 transaction_receipt = ?,
                 checkout_id = ?,
                 paid_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([
            $transactionId,
            $transactionReceipt,
            $checkoutId,
            $pendingSale['id']
        ]);

        $pdo->commit();
        http_response_code(200);
        exit('Payment requires amount review');
    }

    // Load the products in a consistent order and lock their stock.
    $stmt = $pdo->prepare(
        "SELECT * FROM hashpay_pending_items
         WHERE pending_sale_id = ?
         ORDER BY product_id ASC"
    );
    $stmt->execute([$pendingSale['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        throw new Exception('Pending sale has no items');
    }

    $products = [];

    foreach ($items as $item) {
        $stmt = $pdo->prepare(
            "SELECT id, product_name, quantity
             FROM products
             WHERE id = ?
             FOR UPDATE"
        );
        $stmt->execute([$item['product_id']]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product || (int)$product['quantity'] < (int)$item['quantity']) {
            $stmt = $pdo->prepare(
                "UPDATE hashpay_pending_sales
                 SET status = 'paid_needs_review',
                     transaction_id = ?,
                     transaction_receipt = ?,
                     checkout_id = ?,
                     paid_at = NOW()
                 WHERE id = ?"
            );
            $stmt->execute([
                $transactionId,
                $transactionReceipt,
                $checkoutId,
                $pendingSale['id']
            ]);

            $pdo->commit();
            http_response_code(200);
            exit('Payment received but stock requires review');
        }

        $products[$item['product_id']] = $product;
    }

    // Generate a receipt number.
    $receiptNumber = 'HP-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));

    // Record the completed sale.
    $stmt = $pdo->prepare(
        "INSERT INTO sales
         (receipt_number, user_id, total_amount, customer_id,
          payment_method, mpesa_reference, amount_paid, balance)
         VALUES (?, ?, ?, ?, 'hashpay', ?, ?, 0)"
    );

    $stmt->execute([
        $receiptNumber,
        $pendingSale['user_id'],
        $pendingSale['total_amount'],
        $pendingSale['customer_id'],
        $transactionReceipt,
        $pendingSale['total_amount']
    ]);

    $saleId = (int)$pdo->lastInsertId();

    // Record sale items and deduct stock.
    foreach ($items as $item) {
        $product = $products[$item['product_id']];

        $quantity = (int)$item['quantity'];
        $previousQuantity = (int)$product['quantity'];
        $newQuantity = $previousQuantity - $quantity;
        $unitPrice = (float)$item['unit_price'];
        $buyingPrice = (float)$item['buying_price_at_sale'];
        $subtotal = (float)$item['subtotal'];

        $stmt = $pdo->prepare(
            "INSERT INTO sale_items
             (sale_id, product_id, quantity, unit_price,
              subtotal, buying_price_at_sale)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $saleId,
            $item['product_id'],
            $quantity,
            $unitPrice,
            $subtotal,
            $buyingPrice
        ]);

        $stmt = $pdo->prepare(
            "UPDATE products SET quantity = ? WHERE id = ?"
        );
        $stmt->execute([
            $newQuantity,
            $item['product_id']
        ]);

        // Record the stock movement.
        $stmt = $pdo->prepare(
            "INSERT INTO stock_movements
             (product_id, movement_type, quantity,
              previous_quantity, new_quantity, notes,
              user_id, unit_buying_price, total_cost)
             VALUES (?, 'stock_out', ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $item['product_id'],
            $quantity,
            $previousQuantity,
            $newQuantity,
            'HashPay sale ' . $receiptNumber,
            $pendingSale['user_id'],
            $buyingPrice,
            $buyingPrice * $quantity
        ]);
    }

    // Mark the pending sale as paid.
    $stmt = $pdo->prepare(
        "UPDATE hashpay_pending_sales
         SET status = 'paid',
             transaction_id = ?,
             transaction_receipt = ?,
             checkout_id = ?,
             paid_at = NOW()
         WHERE id = ?"
    );

    $stmt->execute([
        $transactionId,
        $transactionReceipt,
        $checkoutId,
        $pendingSale['id']
    ]);

    $pdo->commit();

    http_response_code(200);
    exit('Payment processed successfully');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('HashPay webhook error: ' . $e->getMessage());

    http_response_code(500);
    exit('Webhook processing failed');
}
