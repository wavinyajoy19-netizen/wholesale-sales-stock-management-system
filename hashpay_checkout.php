<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/hashpay_config.php';

$role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($role, ['admin', 'cashier'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$reference = trim((string)($_GET['ref'] ?? ''));

if ($reference === '') {
    http_response_code(400);
    exit('Missing payment reference.');
}

$stmt = $pdo->prepare(
    "SELECT id, reference, total_amount, status
     FROM hashpay_pending_sales
     WHERE reference = ? AND user_id = ?
     LIMIT 1"
);

$stmt->execute([$reference, $_SESSION['user_id']]);
$pending = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pending) {
    http_response_code(404);
    exit('Pending payment was not found.');
}

if ($pending['status'] !== 'pending') {
    $statusMessage = $pending['status'] === 'paid'
        ? 'This payment has already been confirmed.'
        : 'This payment is not currently available for checkout.';
} else {
    $statusMessage = '';
}

function checkoutEscape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>HashPay Checkout</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 20px;
            background: #f1f5f9;
            color: #263445;
            font-family: Arial, sans-serif;
        }

        .checkout {
            width: 100%;
            max-width: 520px;
            padding: 30px;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 8px 30px rgba(16, 43, 80, .10);
        }

        h1 {
            margin: 0 0 8px;
            color: #102b50;
            font-size: 24px;
        }

        .muted {
            color: #64748b;
            line-height: 1.6;
        }

        .amount {
            margin: 24px 0;
            padding: 20px;
            border-radius: 10px;
            background: #102b50;
            color: #fff;
        }

        .amount span {
            display: block;
            color: #dce7f5;
            font-size: 13px;
        }

        .amount strong {
            display: block;
            margin-top: 8px;
            font-size: 30px;
        }

        .reference {
            overflow-wrap: anywhere;
            padding: 12px;
            border: 1px solid #dce3ed;
            border-radius: 8px;
            background: #f8fafc;
            font-size: 13px;
        }

        button, .back {
            display: block;
            width: 100%;
            margin-top: 18px;
            padding: 14px 18px;
            border: 0;
            border-radius: 8px;
            background: #0d9488;
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
        }

        button:disabled {
            opacity: .6;
            cursor: not-allowed;
        }

        #payment_message {
            margin-top: 18px;
            padding: 12px;
            border-radius: 8px;
            background: #eff6ff;
            color: #1e40af;
            line-height: 1.5;
        }
    </style>
</head>

<body>

<main class="checkout">

    <h1>Complete Payment</h1>

    <p class="muted">
        Review the amount below, then continue to the secure HashPay payment window.
    </p>

    <div class="amount">
        <span>Amount to pay</span>

        <strong>
            KSh <?= number_format((float)$pending['total_amount'], 2) ?>
        </strong>
    </div>

    <p class="muted">Payment reference</p>

    <div class="reference">
        <?= checkoutEscape($pending['reference']) ?>
    </div>

    <?php if ($statusMessage !== ''): ?>

        <div id="payment_message">
            <?= checkoutEscape($statusMessage) ?>
        </div>

        <a class="back" href="sales.php">
            Return to Sales
        </a>

    <?php else: ?>

        <button type="button" id="pay_button">
            Pay with M-PESA
        </button>

        <div id="payment_message" role="status">
            Your sale will only be completed after the server receives and verifies HashPay's payment notification.
        </div>

        <a class="back"
           href="sales.php"
           style="background:#eaf1fb;color:#174b86">
            Return to Sales
        </a>

    <?php endif; ?>

</main>

<?php if ($pending['status'] === 'pending'): ?>

<script src="https://pay.hashback.co.ke/hashpay.js"></script>

<script>
(function () {

    const payButton = document.getElementById('pay_button');
    const message = document.getElementById('payment_message');

    if (typeof HashPay === 'undefined') {

        payButton.disabled = true;

        message.textContent =
            'The HashPay payment service could not be loaded. Check your internet connection and refresh this page.';

        return;
    }

    const handler = HashPay.setup({

        account: <?= json_encode(HASHPAY_ACCOUNT_ID) ?>,

        amount: <?= json_encode((float)$pending['total_amount']) ?>,

        reference: <?= json_encode($pending['reference']) ?>,

        onSuccess: function () {

            message.textContent =
                'Payment submitted. Please wait while the server verifies the payment. Do not make another payment. Refresh this page after a short while to check the status.';

            payButton.disabled = true;
        },

        onCancel: function () {

            message.textContent =
                'Payment was cancelled. You can try again using the button.';

            payButton.disabled = false;
        },

        onError: function () {

            message.textContent =
                'The payment window reported an error. Check the payment status before trying again.';

            payButton.disabled = false;
        }

    });

    payButton.addEventListener('click', function () {
        handler.openIframe();
    });

})();
</script>

<?php endif; ?>

</body>
</html>