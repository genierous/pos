<?php
session_start();
require 'database.php';
require 'app.php';
require_login();

$transaction_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$transaction_id) {
    header("Location: sales_transaction.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT t.*, u.full_name, u.username
    FROM transactions t
    LEFT JOIN users u ON u.id = t.user_id
    WHERE t.id = ? AND t.status = 'completed'
");
$stmt->execute([$transaction_id]);
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transaction) {
    header("Location: sales_transaction.php");
    exit;
}

$stmt = $conn->prepare("SELECT * FROM transaction_items WHERE transaction_id = ? ORDER BY id");
$stmt->execute([$transaction_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$receipt_number = (string) $transaction['transaction_code'];

function code39_barcode($value) {
    $patterns = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];

    $value = strtoupper($value);
    $value = preg_replace('/[^0-9A-Z .\-\/+$%]/', '', $value);
    $encoded = '*' . $value . '*';
    $narrow = 2;
    $wide = 6;
    $height = 72;
    $quiet_zone = 24;
    $x = $quiet_zone;
    $bars = '';

    foreach (str_split($encoded) as $character) {
        if (!isset($patterns[$character])) {
            continue;
        }

        $pattern = $patterns[$character];
        for ($i = 0; $i < 9; $i++) {
            $width = ($pattern[$i] === 'w') ? $wide : $narrow;
            if ($i % 2 === 0) {
                $bars .= '<rect x="' . $x . '" y="0" width="' . $width . '" height="' . $height . '"></rect>';
            }
            $x += $width;
        }
        $x += $narrow;
    }

    $total_width = $x + $quiet_zone;

    return '<svg class="barcode" viewBox="0 0 ' . $total_width . ' ' . $height . '" role="img" aria-label="Barcode ' . h($value) . '" xmlns="http://www.w3.org/2000/svg">'
        . '<rect width="' . $total_width . '" height="' . $height . '" fill="#fff"></rect>'
        . '<g fill="#000" shape-rendering="crispEdges">' . $bars . '</g>'
        . '</svg>';
}

page_header('Receipt', 'Transaction receipt.', 'bi-receipt-cutoff');
?>
<section class="panel receipt-wrap">
    <div class="actions no-print" style="margin-bottom:16px;">
        <button type="button" onclick="window.print()">Print Receipt</button>
        <a class="button secondary" href="sales_transaction.php">New Sale</a>
        <a class="button secondary" href="sales_reports.php">Sales Reports</a>
    </div>

    <div class="receipt">
        <h2>Quickserve</h2>
        <p class="muted">Transaction Receipt</p>
        <?php echo code39_barcode($receipt_number); ?>
        <p class="barcode-text"><?php echo h($receipt_number); ?></p>

        <table>
            <tbody>
                <tr><th>Receipt No.</th><td><?php echo h($receipt_number); ?></td></tr>
                <tr><th>Date</th><td><?php echo h($transaction['created_at']); ?></td></tr>
                <tr><th>Cashier</th><td><?php echo h($transaction['full_name'] ?: $transaction['username']); ?></td></tr>
                <tr><th>Payment</th><td><?php echo h(ucfirst($transaction['payment_method'])); ?></td></tr>
            </tbody>
        </table>

        <h3>Items</h3>
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?php echo h($item['product_name']); ?></td>
                    <td><?php echo h($item['quantity']); ?></td>
                    <td>PHP <?php echo money($item['price']); ?></td>
                    <td>PHP <?php echo money($item['subtotal']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="totals">
            <p><span>Subtotal</span><strong>PHP <?php echo money($transaction['subtotal']); ?></strong></p>
            <p><span>Discount</span><strong>PHP <?php echo money($transaction['discount']); ?></strong></p>
            <p><span>Tax</span><strong>PHP <?php echo money($transaction['tax']); ?></strong></p>
            <p class="grand"><span>Total</span><strong>PHP <?php echo money($transaction['total']); ?></strong></p>
            <p><span>Amount Paid</span><strong>PHP <?php echo money($transaction['amount_paid']); ?></strong></p>
            <p><span>Change</span><strong>PHP <?php echo money($transaction['change_due']); ?></strong></p>
        </div>

        <p class="thanks">Thank you for your purchase.</p>
    </div>
</section>

<style>
    .receipt-wrap { max-width: 760px; }
    .receipt { max-width: 520px; margin: 0 auto; background: #fff; color: #111827; }
    .receipt h2, .receipt h3, .thanks { text-align: center; }
    .receipt h2 { margin-bottom: 4px; }
    .receipt .muted { text-align: center; margin-top: 0; }
    .barcode {
        display: block;
        width: 100%;
        max-width: 480px;
        height: 72px;
        margin: 12px auto 6px;
        background: #fff;
    }
    .barcode-text {
        margin: 0 0 12px;
        text-align: center;
        font-family: "Courier New", monospace;
        font-size: 13px;
        letter-spacing: 0;
    }
    .totals { margin-top: 18px; border-top: 1px solid #e5e7eb; padding-top: 12px; }
    .totals p { display: flex; justify-content: space-between; margin: 8px 0; }
    .totals .grand { font-size: 18px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
    .thanks { margin-top: 22px; font-weight: bold; }

    @media print {
        body { background: #fff; }
        .sidebar, .topbar, .no-print { display: none !important; }
        .shell { display: block; }
        .main { padding: 0; }
        .panel { border: 0; box-shadow: none; padding: 0; }
        .receipt { width: 80mm; max-width: 80mm; margin: 0; font-size: 12px; }
        .barcode { height: 56px; }
        table { font-size: 12px; }
        th, td { padding: 6px 4px; }
    }
</style>
<?php page_footer(); ?>