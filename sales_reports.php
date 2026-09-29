<?php
session_start();
require 'database.php';
require 'app.php';
require_admin();

$from = trim($_GET['from'] ?? date('Y-m-01'));
$to   = trim($_GET['to']   ?? date('Y-m-d'));
$search_receipt = trim($_GET['search_receipt'] ?? '');
$search_date    = trim($_GET['search_date'] ?? '');
$search_cashier = trim($_GET['search_cashier'] ?? '');
$search_payment = trim($_GET['search_payment'] ?? '');
$search_item    = trim($_GET['search_item'] ?? '');
$search_total   = trim($_GET['search_total'] ?? '');
$search_active  = $search_receipt !== '' || $search_date !== '' || $search_cashier !== '' || $search_payment !== '' || $search_item !== '' || $search_total !== '';

$db_error = false;
$summary       = ['count' => 0, 'total' => 0, 'average_sale' => 0];
$transactions  = [];
$top_products  = [];
$payment_breakdown = [];
$search_details = null;
$search_items   = [];

try {
    if ($search_active) {
        $query = "SELECT t.id, t.transaction_code, u.username, u.full_name, t.subtotal, t.discount, t.tax, t.total, t.payment_method, t.amount_paid, t.change_due, t.created_at FROM transactions t LEFT JOIN users u ON u.id = t.user_id WHERE t.status='completed'";
        $params = [];
        $conditions = [];

        if ($search_receipt !== '') {
            $conditions[] = 't.transaction_code LIKE ?';
            $params[] = '%' . $search_receipt . '%';
        }

        if ($search_date !== '') {
            $conditions[] = 'DATE(t.created_at) = ?';
            $params[] = $search_date;
        }

        if ($search_cashier !== '') {
            $conditions[] = '(u.full_name LIKE ? OR u.username LIKE ?)';
            $params[] = '%' . $search_cashier . '%';
            $params[] = '%' . $search_cashier . '%';
        }

        if ($search_payment !== '') {
            $conditions[] = 't.payment_method = ?';
            $params[] = $search_payment;
        }

        if ($search_item !== '') {
            $conditions[] = 'EXISTS (SELECT 1 FROM transaction_items ti WHERE ti.transaction_id = t.id AND ti.product_name LIKE ?)';
            $params[] = '%' . $search_item . '%';
        }

        if ($search_total !== '') {
            $conditions[] = 't.total = ?';
            $params[] = (float) $search_total;
        }

        if ($conditions) {
            $query .= ' AND ' . implode(' AND ', $conditions);
        }

        $query .= ' ORDER BY t.created_at DESC';
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summary = [
            'count' => count($transactions),
            'total' => array_sum(array_map(static function ($txn) { return (float) $txn['total']; }, $transactions)),
            'average_sale' => count($transactions) ? (array_sum(array_map(static function ($txn) { return (float) $txn['total']; }, $transactions)) / count($transactions)) : 0,
        ];
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS count, COALESCE(SUM(total),0) AS total, COALESCE(AVG(total),0) AS average_sale FROM transactions WHERE status='completed' AND DATE(created_at) BETWEEN ? AND ?");
        $stmt->execute([$from, $to]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("SELECT t.id, t.transaction_code, u.username, u.full_name, t.subtotal, t.discount, t.tax, t.total, t.payment_method, t.amount_paid, t.change_due, t.created_at FROM transactions t LEFT JOIN users u ON u.id = t.user_id WHERE t.status='completed' AND DATE(t.created_at) BETWEEN ? AND ? ORDER BY t.created_at DESC");
        $stmt->execute([$from, $to]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("SELECT ti.product_name, SUM(ti.quantity) AS qty, SUM(ti.subtotal) AS total FROM transaction_items ti INNER JOIN transactions t ON t.id = ti.transaction_id WHERE t.status='completed' AND DATE(t.created_at) BETWEEN ? AND ? GROUP BY ti.product_name ORDER BY qty DESC LIMIT 10");
        $stmt->execute([$from, $to]);
        $top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("SELECT payment_method, COUNT(*) AS count, COALESCE(SUM(total),0) AS total FROM transactions WHERE status='completed' AND DATE(created_at) BETWEEN ? AND ? GROUP BY payment_method");
        $stmt->execute([$from, $to]);
        $payment_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($search_active && count($transactions) === 1) {
        $detail_id = (int) $transactions[0]['id'];
        $detail_stmt = $conn->prepare("SELECT t.*, u.full_name, u.username FROM transactions t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ? AND t.status = 'completed'");
        $detail_stmt->execute([$detail_id]);
        $search_details = $detail_stmt->fetch(PDO::FETCH_ASSOC);

        $items_stmt = $conn->prepare("SELECT product_name, quantity, price, subtotal FROM transaction_items WHERE transaction_id = ? ORDER BY id");
        $items_stmt->execute([$detail_id]);
        $search_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $db_error = true;
}

page_header('Sales Reports', 'Review sales performance and trends.', 'bi-graph-up');
?>

<?php if ($db_error): ?>
    <div class="alert error">
        Database retrieval failure. Could not load report data.
        <a href="sales_reports.php?from=<?= h($from) ?>&to=<?= h($to) ?>" class="button secondary" style="margin-left:12px;">Retry</a>
    </div>
<?php endif; ?>

<section class="panel">
    <form method="GET" class="sales-search-form">
        <div class="search-row">
            <div class="search-field">
                <label for="search_receipt">Receipt Number</label>
                <input id="search_receipt" name="search_receipt" type="text" value="<?= h($search_receipt) ?>" placeholder="TXN...">
            </div>
            <div class="search-field">
                <label for="search_date">Date</label>
                <input id="search_date" name="search_date" type="date" value="<?= h($search_date) ?>">
            </div>
            <div class="search-field">
                <label for="search_cashier">Cashier Name</label>
                <input id="search_cashier" name="search_cashier" type="text" value="<?= h($search_cashier) ?>" placeholder="Cashier name">
            </div>
            <div class="search-field">
                <label for="search_payment">Payment Method</label>
                <select id="search_payment" name="search_payment">
                    <option value="">Any</option>
                    <option value="cash" <?= $search_payment === 'cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="credit" <?= $search_payment === 'credit' ? 'selected' : '' ?>>Credit</option>
                    <option value="debit" <?= $search_payment === 'debit' ? 'selected' : '' ?>>Debit</option>
                </select>
            </div>
            <div class="search-field">
                <label for="search_item">Item Name</label>
                <input id="search_item" name="search_item" type="text" value="<?= h($search_item) ?>" placeholder="Item name">
            </div>
            <div class="search-field">
                <label for="search_total">Total Amount</label>
                <input id="search_total" name="search_total" type="number" step="0.01" value="<?= h($search_total) ?>" placeholder="0.00">
            </div>
        </div>

        <div class="search-row search-row-secondary">
            <div class="search-field">
                <label for="from">From</label>
                <input id="from" name="from" type="date" value="<?= h($from) ?>">
            </div>
            <div class="search-field">
                <label for="to">To</label>
                <input id="to" name="to" type="date" value="<?= h($to) ?>">
            </div>
            <div class="search-actions">
                <button type="submit">Search Transactions</button>
                <a class="button secondary" href="sales_reports.php">Clear</a>
                <button type="button" class="secondary" onclick="window.print()">Print Report</button>
            </div>
        </div>
    </form>
</section>

<?php if (!$db_error): ?>

<?php if ($search_active): ?>
<section class="panel" style="margin-top:18px;">
    <h2>Search Results</h2>
    <?php if ($search_details): ?>
        <div class="search-detail" style="margin-top:12px; padding:16px; border:1px solid #e5e7eb; border-radius:12px; background:#eff4ff;">
            <h3 style="margin-bottom:10px;">Transaction Details</h3>
            <p><strong>Receipt No.:</strong> <?= h($search_details['transaction_code']) ?></p>
            <p><strong>Date:</strong> <?= h($search_details['created_at']) ?></p>
            <p><strong>Cashier:</strong> <?= h($search_details['full_name'] ?: $search_details['username']) ?></p>
            <p><strong>Payment:</strong> <?= h(ucfirst($search_details['payment_method'])) ?></p>
            <p><strong>Subtotal:</strong> ₱<?= money($search_details['subtotal']) ?></p>
            <p><strong>Discount:</strong> ₱<?= money($search_details['discount']) ?></p>
            <p><strong>Tax:</strong> ₱<?= money($search_details['tax']) ?></p>
            <p><strong>Total:</strong> ₱<?= money($search_details['total']) ?></p>
            <p><strong>Amount Paid:</strong> ₱<?= money($search_details['amount_paid']) ?></p>
            <p><strong>Change:</strong> ₱<?= money($search_details['change_due']) ?></p>
            <div class="search-actions" style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
                <a class="button secondary" href="receipt.php?id=<?= h($search_details['id']) ?>" target="_blank" rel="noopener">View Receipt</a>
                <button type="button" class="button secondary" onclick="var win=window.open('receipt.php?id=<?= h($search_details['id']) ?>','_blank'); setTimeout(function(){win.print();}, 800);">Reprint Receipt</button>
            </div>
            <h4 style="margin-top:16px;">Items</h4>
            <table style="margin-top:8px;">
                <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ($search_items as $item): ?>
                    <tr>
                        <td><?= h($item['product_name']) ?></td>
                        <td><?= h($item['quantity']) ?></td>
                        <td>₱<?= money($item['price']) ?></td>
                        <td>₱<?= money($item['subtotal']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p style="margin-top:12px;">No matching transaction found. Try another receipt number, date, cashier, payment method, item, or total.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="grid cards" style="margin-top:18px;">
    <div class="card">Transactions<strong><?= h($summary['count']) ?></strong></div>
    <div class="card">Total Sales<strong>₱<?= money($summary['total']) ?></strong></div>
    <div class="card">Average Sale<strong>₱<?= money($summary['average_sale']) ?></strong></div>
</section>

<?php if ($payment_breakdown): ?>
<section class="panel" style="margin-top:18px;">
    <h2>Payment Method Breakdown</h2>
    <table>
        <thead><tr><th>Method</th><th>Transactions</th><th>Total</th></tr></thead>
        <tbody>
        <?php foreach ($payment_breakdown as $pb): ?>
            <tr>
                <td><?= h(ucfirst($pb['payment_method'])) ?></td>
                <td><?= h($pb['count']) ?></td>
                <td>₱<?= money($pb['total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<section class="panel" style="margin-top:18px;">
    <h2>Top Products</h2>
    <table>
        <thead><tr><th>Product</th><th>Qty Sold</th><th>Total</th></tr></thead>
        <tbody>
        <?php if (!$top_products): ?>
            <tr><td colspan="3">No report data found for this date range.</td></tr>
        <?php endif; ?>
        <?php foreach ($top_products as $product): ?>
            <tr>
                <td><?= h($product['product_name']) ?></td>
                <td><?= h($product['qty']) ?></td>
                <td>₱<?= money($product['total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="panel" style="margin-top:18px;">
    <h2>Transactions</h2>
    <table>
        <thead><tr><th>Code</th><th>Cashier</th><th>Subtotal</th><th>Discount</th><th>Tax</th><th>Total</th><th>Payment</th><th>Paid</th><th>Change</th><th>Date</th><th>Receipt</th></tr></thead>
        <tbody>
        <?php if (!$transactions): ?>
            <tr><td colspan="11">No transactions found.</td></tr>
        <?php endif; ?>
        <?php foreach ($transactions as $txn): ?>
            <tr>
                <td><?= h($txn['transaction_code']) ?></td>
                <td><?= h($txn['username']) ?></td>
                <td>₱<?= money($txn['subtotal']) ?></td>
                <td>₱<?= money($txn['discount']) ?></td>
                <td>₱<?= money($txn['tax']) ?></td>
                <td>₱<?= money($txn['total']) ?></td>
                <td><?= h(ucfirst($txn['payment_method'])) ?></td>
                <td>₱<?= money($txn['amount_paid']) ?></td>
                <td>₱<?= money($txn['change_due']) ?></td>
                <td><?= h($txn['created_at']) ?></td>
                <td>
                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                        <a class="button secondary" href="receipt.php?id=<?= h($txn['id']) ?>" target="_blank" rel="noopener">View</a>
                        <button type="button" class="button secondary" onclick="var win=window.open('receipt.php?id=<?= h($txn['id']) ?>','_blank'); setTimeout(function(){win.print();}, 800);">Reprint</button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php endif; ?>

<style>
.sales-search-form {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.sales-search-form .search-row {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 12px;
    align-items: end;
}
.sales-search-form .search-row-secondary {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 2.2fr);
}
.sales-search-form .search-field {
    min-width: 0;
}
.sales-search-form .search-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    justify-content: flex-start;
    padding-top: 2px;
}
.sales-search-form .search-actions .button,
.sales-search-form .search-actions button {
    flex: 1 1 140px;
    min-width: 140px;
}

@media (max-width: 1100px) {
    .sales-search-form .search-row {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .sales-search-form .search-row-secondary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .sales-search-form .search-actions {
        grid-column: 1 / -1;
    }
}

@media (max-width: 700px) {
    .sales-search-form .search-row,
    .sales-search-form .search-row-secondary {
        grid-template-columns: 1fr;
    }
    .sales-search-form .search-actions {
        flex-direction: column;
        align-items: stretch;
    }
    .sales-search-form .search-actions .button,
    .sales-search-form .search-actions button {
        width: 100%;
        min-width: 0;
    }
}

@media print {
    .sidebar, .topbar, form, .no-print { display: none !important; }
    .shell { display: block; }
    .main { padding: 0; }
    body { background: #fff; }
}
</style>
<?php page_footer(); ?>