<?php
session_start();
require 'database.php';
require 'app.php';
require_login();

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// CSRF token for all cart/checkout POSTs (add, update, remove, discount,
// checkout). Generated once per session and echoed into every mutating
// form below; verified before any of those actions run.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (!empty($_GET['ajax']) && $_GET['ajax'] == '1');

$message = "";
$ajax_response = null;

define('TAX_RATE', 0.08);

function cart_totals() {
    $subtotal = 0;
    foreach ($_SESSION['cart'] as $item) {
        $subtotal += $item['price'] * $item['quantity'];
    }
    $discount = (float) ($_SESSION['discount'] ?? 0);
    $discounted = max(0, $subtotal - $discount);
    $tax = round($discounted * TAX_RATE, 2);
    $total = round($discounted + $tax, 2);
    return [
        'subtotal' => $subtotal,
        'discount' => $discount,
        'tax'      => $tax,
        'total'    => $total,
    ];
}

function render_cart_panel_html() {
    $totals = cart_totals();
    $csrf = h($_SESSION['csrf_token']);
    ob_start(); ?>
        <h2>Cart <span id="cart-count-badge" class="cart-badge"><?= count($_SESSION['cart']) ?></span></h2>
        <p id="cart-feedback" class="muted" style="margin-top:-6px; min-height:18px;" aria-live="polite"></p>
        <form method="POST" id="cart-update-form">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="product_id" class="remove-id" value="">
            <table>
                <thead><tr><th>Item</th><th style="width:70px;">Qty</th><th>Total</th><th></th></tr></thead>
                <tbody>
                <?php if (!$_SESSION['cart']): ?>
                    <tr><td colspan="4" class="muted">Cart is empty.</td></tr>
                <?php endif; ?>
                <?php foreach ($_SESSION['cart'] as $item): ?>
                    <tr>
                        <td>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <?php if (!empty($item['image_url'])): ?>
                                    <img src="<?= h($item['image_url']) ?>" alt=""
                                         style="width:36px; height:36px; object-fit:cover; border-radius:5px; border:1px solid #e5e7eb; flex-shrink:0;">
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight:bold; font-size:13px;"><?= h($item['name']) ?></div>
                                    <div class="muted" style="font-size:12px;">₱<?= money($item['price']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td><input type="number" min="1" max="<?= h($item['stock']) ?>" name="qty[<?= h($item['id']) ?>]" value="<?= h($item['quantity']) ?>" data-price="<?= h($item['price']) ?>" title="Max available: <?= h($item['stock']) ?>" style="width:60px;padding:6px;"></td>
                        <td class="line-total">₱<?= money($item['price'] * $item['quantity']) ?></td>
                        <td>
                            <button type="submit" name="remove" value="<?= h($item['id']) ?>" class="danger"
                                    style="padding:5px 8px; font-size:12px;">✕</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="actions" style="margin-top:12px;">
                <button type="button" id="cancel-transaction-btn" class="danger">
                    Cancel Transaction
                </button>
            </div>
        </form>

        <form method="POST" style="margin-top:14px; display:flex; gap:8px; align-items:flex-end;">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="apply_discount">
            <div style="flex:1;">
                <label for="discount">Discount (₱)</label>
                <input id="discount" name="discount" type="number" step="0.01" min="0" value="<?= money($totals['discount']) ?>">
            </div>
            <button type="submit" class="secondary">Apply</button>
        </form>

        <hr>
        <p><strong>Subtotal:</strong> ₱<?= money($totals['subtotal']) ?></p>
        <p><strong>Discount:</strong> &minus;₱<?= money($totals['discount']) ?></p>
        <p><strong>Tax (<?= (TAX_RATE * 100) ?>%):</strong> ₱<?= money($totals['tax']) ?></p>
        <p style="font-size:18px;"><strong>Total:</strong> ₱<?= money($totals['total']) ?></p>

        <form method="POST" id="checkout-form">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="checkout">
            <label for="payment_method">Payment Method</label>
            <select id="payment_method" name="payment_method">
                <option value="cash"   <?= (($_SESSION['payment_method_retry'] ?? '') === 'cash')   ? 'selected' : '' ?>>Cash</option>
                <option value="credit" <?= (($_SESSION['payment_method_retry'] ?? '') === 'credit') ? 'selected' : '' ?>>Credit Card</option>
                <option value="debit"  <?= (($_SESSION['payment_method_retry'] ?? '') === 'debit')  ? 'selected' : '' ?>>Debit Card</option>
            </select>

            <?php if (!empty($_SESSION['payment_retry'])): ?>
                <!--
                    On a retry, "Amount Paid" holds the running total already
                    collected and is locked so the cashier can't accidentally
                    add new cash into it (which used to silently double-count
                    against the separate "Additional Amount" field below).
                    New cash goes into extra_payment only.
                -->
                <label for="amount_paid" style="margin-top:12px;">Amount Paid So Far (₱)</label>
                <input id="amount_paid" name="amount_paid" type="number" step="0.01" min="0"
                       data-total="<?= number_format($totals['total'], 2, '.', '') ?>"
                       value="<?= money($_SESSION['amount_paid_so_far'] ?? 0) ?>"
                       readonly
                       style="background:#f1f5f9; cursor:not-allowed;">
            <?php else: ?>
                <label for="amount_paid" style="margin-top:12px;">Amount Paid (₱)</label>
                <input id="amount_paid" name="amount_paid" type="number" step="0.01" min="0" required
                       data-total="<?= number_format($totals['total'], 2, '.', '') ?>"
                       value=""
                       placeholder="0.00">
            <?php endif; ?>

            <p id="change_due_display" style="font-size:16px; margin:10px 0 0;"><strong>Change:</strong> ₱0.00</p>

            <?php if (!empty($_SESSION['payment_retry'])): ?>
            <div style="background:#fef9c3; border:1px solid #fde047; border-radius:6px; padding:12px; margin-top:12px;">
                <strong style="color:#854d0e;">💳 Add Additional Payment</strong>
                <p style="font-size:13px; color:#854d0e; margin:4px 0 8px;">
                    Already paid: <strong>₱<?= money($_SESSION['amount_paid_so_far'] ?? 0) ?></strong>
                    &nbsp;|&nbsp; Still needed: <strong>₱<?= money(max(0, $totals['total'] - ($_SESSION['amount_paid_so_far'] ?? 0))) ?></strong>
                </p>
                <label for="extra_payment" style="font-size:13px;">Additional Amount (₱)</label>
                <input id="extra_payment" name="extra_payment" type="number" step="0.01" min="0"
                       value="<?= money(max(0, $totals['total'] - ($_SESSION['amount_paid_so_far'] ?? 0))) ?>"
                       style="margin-bottom:0;">
            </div>
            <?php else: ?>
                <input type="hidden" name="extra_payment" value="0">
            <?php endif; ?>

            <div class="quick-cash-buttons" style="display:flex; gap:8px; flex-wrap:wrap; margin:10px 0 0;">
                <?php foreach ([5, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100] as $cash): ?>
                    <button type="button" class="quick-cash-btn" data-amount="<?= $cash ?>"
                            style="flex:1; min-width:70px; padding:10px; border:none; border-radius:8px; background:#1d4ed8; color:#fff; cursor:pointer;">
                        ₱<?= $cash ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <p class="muted" style="font-size:12px; margin-top:4px;">
                <?= !empty($_SESSION['payment_retry']) ? 'Quick-cash buttons add to Additional Amount above.' : 'Quick-cash buttons add to Amount Paid above.' ?>
            </p>

            <button type="submit" style="margin-top:12px; width:100%;" <?= !$_SESSION['cart'] ? 'disabled' : '' ?>>
                <?= !empty($_SESSION['payment_retry']) ? '💳 Retry Payment' : 'Process Payment' ?>
            </button>
        </form>
    <?php
    return ob_get_clean();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrf_valid = isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token']);

    if (!$csrf_valid) {
        $message = "<div class='alert error'>Your session could not be verified. Please refresh the page and try again.</div>";
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success'    => false,
                'message'    => 'Session could not be verified. Please refresh the page and try again.',
                'cart_count' => count($_SESSION['cart']),
                'cart_html'  => render_cart_panel_html(),
            ]);
            exit;
        }
    } else {

    $action = $_POST['action'] ?? '';
    if (isset($_POST['remove'])) {
        $action = 'remove';
    }

    if ($action === 'add') {
        $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        $stmt = $conn->prepare("SELECT id, name, price, stock, image_url FROM products WHERE id = ? AND is_active = 1");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product && (int) $product['stock'] > 0) {
            $current_qty = $_SESSION['cart'][$product_id]['quantity'] ?? 0;
            if ($current_qty + 1 <= (int) $product['stock']) {
                $_SESSION['cart'][$product_id] = [
                    'id'        => $product['id'],
                    'name'      => $product['name'],
                    'price'     => (float) $product['price'],
                    'quantity'  => $current_qty + 1,
                    'stock'     => (int) $product['stock'],
                    'image_url' => $product['image_url'] ?? '',
                ];
                $ajax_response = [
                    'success' => true,
                    'message' => 'Added to cart.',
                    'cart_count' => count($_SESSION['cart']),
                    'cart_html' => render_cart_panel_html(),
                ];
            } else {
                $message = "<div class='alert error'>Not enough stock for that product.</div>";
                $ajax_response = [
                    'success' => false,
                    'message' => 'Not enough stock for that product.',
                    'cart_count' => count($_SESSION['cart']),
                ];
            }
        } else {
            $ajax_response = [
                'success' => false,
                'message' => 'Unable to add this product to cart.',
                'cart_count' => count($_SESSION['cart']),
                'cart_html' => render_cart_panel_html(),
            ];
        }
    }

    if ($action === 'update') {
        $capped = false;
        foreach ($_POST['qty'] ?? [] as $product_id => $qty) {
            $product_id = (int) $product_id;
            $qty = max(1, (int) $qty);
            if (isset($_SESSION['cart'][$product_id])) {
                $stock = (int) $_SESSION['cart'][$product_id]['stock'];
                if ($qty > $stock) {
                    $capped = true;
                }
                $_SESSION['cart'][$product_id]['quantity'] = min($qty, $stock);
            }
        }
        if ($is_ajax) {
            $ajax_response = [
                'success' => true,
                'message' => $capped ? 'Quantity adjusted to available stock.' : 'Cart updated.',
                'cart_count' => count($_SESSION['cart']),
                'cart_html' => render_cart_panel_html(),
            ];
        }
    }

    if ($action === 'remove') {
        $product_id = filter_input(INPUT_POST, 'remove', FILTER_VALIDATE_INT);
        if ($product_id && isset($_SESSION['cart'][$product_id])) {
            unset($_SESSION['cart'][$product_id]);
        }
        if ($is_ajax) {
            $ajax_response = [
                'success' => true,
                'message' => 'Item removed.',
                'cart_count' => count($_SESSION['cart']),
                'cart_html' => render_cart_panel_html(),
            ];
        }
    }

    if ($action === 'apply_discount') {
        $discount = filter_input(INPUT_POST, 'discount', FILTER_VALIDATE_FLOAT);
        $_SESSION['discount'] = ($discount !== false && $discount >= 0) ? $discount : 0;
    }

    if ($action === 'clear') {
        $_SESSION['cart'] = [];
        $_SESSION['discount'] = 0;
        unset($_SESSION['payment_retry'], $_SESSION['payment_method_retry'], $_SESSION['amount_paid_so_far']);
        if ($is_ajax) {
            $ajax_response = [
                'success' => true,
                'message' => 'Cart cleared.',
                'cart_count' => count($_SESSION['cart']),
                'cart_html' => render_cart_panel_html(),
            ];
        }
    }

    if ($action === 'checkout') {
        $payment_method  = $_POST['payment_method'] ?? 'cash';
        $amount_paid     = filter_input(INPUT_POST, 'amount_paid', FILTER_VALIDATE_FLOAT);
        $extra_payment   = filter_input(INPUT_POST, 'extra_payment', FILTER_VALIDATE_FLOAT) ?: 0;
        $totals          = cart_totals();

        $total_paid = ($amount_paid !== false ? $amount_paid : 0) + $extra_payment;

        if (!$_SESSION['cart']) {
            $message = "<div class='alert error'>Cart is empty.</div>";

        } elseif (!in_array($payment_method, ['cash', 'credit', 'debit'], true)) {
            $message = "<div class='alert error'>Please select a valid payment method.</div>";

        } elseif ($amount_paid === false || $amount_paid < 0) {
            $message = "<div class='alert error'>Please enter a valid amount paid.</div>";
            $_SESSION['payment_retry'] = true;

        } elseif ($total_paid < $totals['total']) {
            $still_needed = round($totals['total'] - $total_paid, 2);
            $message = "<div class='alert error'>
                ⚠️ Amount is insufficient. Still need <strong>₱" . number_format($still_needed, 2) . "</strong> more.
                Please add an additional payment below.
            </div>";
            $_SESSION['payment_retry']       = true;
            $_SESSION['payment_method_retry'] = $payment_method;
            $_SESSION['amount_paid_so_far']  = $total_paid;

        } else {
            try {
                $conn->beginTransaction();
                $code       = 'TXN' . date('ymdHis') . random_int(10, 99);
                $change_due = round($total_paid - $totals['total'], 2);

                $stmt = $conn->prepare("INSERT INTO transactions (transaction_code, user_id, subtotal, discount, tax, total, payment_method, amount_paid, change_due, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')");
                $stmt->execute([$code, $_SESSION['user_id'], $totals['subtotal'], $totals['discount'], $totals['tax'], $totals['total'], $payment_method, $total_paid, $change_due]);
                $transaction_id = (int) $conn->lastInsertId();

                $item_stmt  = $conn->prepare("INSERT INTO transaction_items (transaction_id, product_id, product_name, price, quantity, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
                $stock_stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");

                $sold_product_ids = [];
                foreach ($_SESSION['cart'] as $item) {
                    $line_total = round($item['price'] * $item['quantity'], 2);
                    $item_stmt->execute([$transaction_id, $item['id'], $item['name'], $item['price'], $item['quantity'], $line_total]);
                    $stock_stmt->execute([$item['quantity'], $item['id'], $item['quantity']]);
                    if ($stock_stmt->rowCount() !== 1) {
                        throw new Exception('Stock update failed — insufficient stock.');
                    }
                    $sold_product_ids[] = $item['id'];
                }

                $conn->commit();

                foreach ($sold_product_ids as $sold_product_id) {
                    sync_low_stock_notification_by_product($sold_product_id);
                }

                $_SESSION['cart']                = [];
                $_SESSION['discount']            = 0;
                $_SESSION['payment_retry']       = false;
                $_SESSION['payment_method_retry'] = '';
                $_SESSION['amount_paid_so_far']   = 0;

                header("Location: receipt.php?id=" . $transaction_id);
                exit;

            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $message = "<div class='alert error'>
                    ❌ Payment could not be processed. Please try again.<br>
                    <small>" . h($e->getMessage()) . "</small>
                </div>";
                $_SESSION['payment_retry'] = true;
            }
        }
    }

    } // end csrf_valid
}

$category_id = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT);
$search      = trim($_GET['search'] ?? '');

$categories = $conn->query("SELECT id, name, icon FROM categories ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

$sql    = "SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.is_active = 1";
$params = [];
if ($category_id) {
    $sql      .= " AND p.category_id = ?";
    $params[]  = $category_id;
}
if ($search !== '') {
    $sql      .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
    $params[]  = "%{$search}%";
    $params[]  = "%{$search}%";
}
$sql .= " ORDER BY p.name";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totals   = cart_totals();
$csrf     = h($_SESSION['csrf_token']);

$cat_icons = [];
foreach ($categories as $c) {
    $cat_icons[$c['id']] = $c['icon'] ?? '🍽️';
}

if ($is_ajax && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($action ?? ''), ['add', 'update', 'remove', 'clear'], true)) {
    header('Content-Type: application/json');
    echo json_encode($ajax_response ?? ['success' => false, 'message' => 'Unable to update cart.', 'cart_count' => count($_SESSION['cart']), 'cart_html' => render_cart_panel_html()]);
    exit;
}

if ($is_ajax) {
    header('Content-Type: text/html; charset=utf-8');
    ob_start();
    foreach ($products as $product): ?>
        <form method="POST" class="product-card">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="product_id" value="<?= h($product['id']) ?>">

            <?php if (!empty($product['image_url'])): ?>
                <img src="<?= h($product['image_url']) ?>" alt="<?= h($product['name']) ?>">
            <?php else: ?>
                <div class="img-placeholder">
                    <?= h($cat_icons[$product['category_id']] ?? '🍽️') ?>
                </div>
            <?php endif; ?>

            <div class="card-body">
                <p class="card-name"><?= h($product['name']) ?></p>
                <p class="card-cat"><?= h($product['category_name'] ?? '') ?></p>
                <p class="card-price">₱<?= money($product['price']) ?>
                    <span class="card-stock">&nbsp;· <?= h($product['stock']) ?> left</span>
                </p>
                <button type="submit" <?= ((int) $product['stock'] <= 0) ? 'disabled' : '' ?>>
                    <?= ((int) $product['stock'] <= 0) ? 'Out of Stock' : '+ Add to Cart' ?>
                </button>
            </div>
        </form>
    <?php endforeach; ?>
    <?php if (!$products): ?>
        <p class="muted">No products found.</p>
    <?php endif; ?>
    <?php
    echo ob_get_clean();
    exit;
}

page_header('Sales Transaction', 'Ring up items and process a payment.', 'bi-receipt');
?>
<?= $message ?>

<style>
.product-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(15,23,42,0.06);
    cursor: pointer;
    transition: box-shadow 0.15s, transform 0.15s;
    text-align: left;
}
.product-card:hover { box-shadow: 0 6px 18px rgba(74,20,140,0.13); transform: translateY(-2px); }
.product-card img {
    width: 100%;
    height: 120px;
    object-fit: cover;
    display: block;
}
.product-card .img-placeholder {
    width: 100%;
    height: 120px;
    background: linear-gradient(135deg, #ede9fe 0%, #f3f6fb 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 40px;
}
.product-card .card-body {
    padding: 10px 12px 12px;
}
.product-card .card-name { font-weight: bold; font-size: 14px; margin: 0 0 2px; }
.product-card .card-cat  { font-size: 12px; color: #6b7280; margin: 0 0 4px; }
.product-card .card-price { font-weight: bold; color: #16234a; font-size: 15px; }
.product-card .card-stock { font-size: 12px; color: #6b7280; }
.product-card button {
    width: 100%; margin-top: 8px; padding: 8px; font-size: 13px;
    background: #16234a; color: #fff; border: 0; border-radius: 6px; cursor: pointer; font-weight: bold;
}
.product-card button:disabled { background: #cbd5e1; cursor: not-allowed; }
.product-card button:hover:not(:disabled) { background: #7B1FA2; }

.cat-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.cat-tab {
    padding: 6px 14px; border-radius: 20px; border: 1px solid #e5e7eb;
    background: #fff; color: #374151; font-size: 13px; cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 4px;
}
.cat-tab.active, .cat-tab:hover { background: #16234a; color: #fff; border-color: #16234a; }

.product-grid-new {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 12px;
}
</style>

<div class="two-col">
    <section class="panel">
        <form method="GET" class="form-grid" style="margin-bottom:12px;" id="product-search-form">
            <div style="flex:1;">
                <label for="search">Search Product</label>
                <input id="search" name="search" value="<?= h($search) ?>" placeholder="Name or SKU" autocomplete="off">
            </div>
            <input type="hidden" name="category" value="<?= h($category_id ?? '') ?>">
        </form>

        <div class="cat-tabs">
            <a href="sales_transaction.php<?= $search ? '?search=' . urlencode($search) : '' ?>"
               class="cat-tab <?= !$category_id ? 'active' : '' ?>">🍽️ All</a>
            <?php foreach ($categories as $cat): ?>
                <?php if ($cat['name'] === 'All Menu') continue; ?>
                <a href="sales_transaction.php?category=<?= h($cat['id']) ?><?= $search ? '&search=' . urlencode($search) : '' ?>"
                   class="cat-tab <?= $category_id == $cat['id'] ? 'active' : '' ?>">
                    <?= h($cat['icon'] ?? '') ?> <?= h($cat['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="product-grid-new">
            <?php foreach ($products as $product): ?>
                <form method="POST" class="product-card">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?= h($product['id']) ?>">

                    <?php if (!empty($product['image_url'])): ?>
                        <img src="<?= h($product['image_url']) ?>" alt="<?= h($product['name']) ?>">
                    <?php else: ?>
                        <div class="img-placeholder">
                            <?= h($cat_icons[$product['category_id']] ?? '🍽️') ?>
                        </div>
                    <?php endif; ?>

                    <div class="card-body">
                        <p class="card-name"><?= h($product['name']) ?></p>
                        <p class="card-cat"><?= h($product['category_name'] ?? '') ?></p>
                        <p class="card-price">₱<?= money($product['price']) ?>
                            <span class="card-stock">&nbsp;· <?= h($product['stock']) ?> left</span>
                        </p>
                        <button type="submit" <?= ((int) $product['stock'] <= 0) ? 'disabled' : '' ?>>
                            <?= ((int) $product['stock'] <= 0) ? 'Out of Stock' : '+ Add to Cart' ?>
                        </button>
                    </div>
                </form>
            <?php endforeach; ?>
            <?php if (!$products): ?>
                <p class="muted">No products found.</p>
            <?php endif; ?>
        </div>
    </section>

    <aside class="panel" id="cart-panel">
        <?= render_cart_panel_html() ?>
    </aside>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
var TAX_RATE = <?= json_encode(TAX_RATE) ?>; // single source of truth, mirrors the PHP constant

document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('product-search-form');
    var searchInput = document.getElementById('search');
    var productGrid = document.querySelector('.product-grid-new');
    var cartPanel = document.getElementById('cart-panel');
    var cartBadge = document.getElementById('cart-count-badge');
    var cartFeedback = document.getElementById('cart-feedback');

    // Quick-cash buttons target extra_payment during a retry (amount_paid
    // is locked then) and amount_paid otherwise. Previously they always
    // wrote into amount_paid, which double-counted against extra_payment
    // once a retry was in progress.
    var bindQuickCashButtons = function () {
        document.querySelectorAll('.quick-cash-btn').forEach(function (button) {
            button.removeEventListener('click', button._quickCashHandler);
            var handler = function () {
                var extraInput = document.getElementById('extra_payment');
                var amountPaidInput = document.getElementById('amount_paid');
                var isRetry = extraInput && !extraInput.matches('input[type="hidden"]');
                var targetInput = isRetry ? extraInput : amountPaidInput;

                if (targetInput) {
                    var currentValue = parseFloat(targetInput.value) || 0;
                    var addAmount = parseFloat(button.dataset.amount) || 0;
                    targetInput.value = (currentValue + addAmount).toFixed(2);
                    targetInput.focus();
                    computeChangeDisplay();
                }
            };
            button._quickCashHandler = handler;
            button.addEventListener('click', handler);
        });
    };

    var computeChangeDisplay = function () {
        var amountPaidInput = document.getElementById('amount_paid');
        var extraInput = document.getElementById('extra_payment');
        var changeDisplay = document.getElementById('change_due_display');
        if (!amountPaidInput || !changeDisplay) {
            return;
        }
        var totalValue = parseFloat(amountPaidInput.dataset.total) || 0;
        var amountPaid = parseFloat(amountPaidInput.value) || 0;
        var extraPaid = extraInput ? (parseFloat(extraInput.value) || 0) : 0;
        var totalPaid = amountPaid + extraPaid;

        if (totalPaid <= 0 || totalPaid < totalValue) {
            changeDisplay.textContent = 'Change: ₱0.00';
            return;
        }
        var change = Math.max(0, totalPaid - totalValue);
        changeDisplay.textContent = 'Change: ₱' + change.toFixed(2);
    };

    var bindAmountPaidChange = function () {
        var amountPaidInput = document.getElementById('amount_paid');
        var extraInput = document.getElementById('extra_payment');
        [amountPaidInput, extraInput].forEach(function (input) {
            if (!input) {
                return;
            }
            input.removeEventListener('input', computeChangeDisplay);
            input.removeEventListener('change', computeChangeDisplay);
            input.addEventListener('input', computeChangeDisplay);
            input.addEventListener('change', computeChangeDisplay);
        });
        computeChangeDisplay();
    };

    // Guards the checkout/retry-payment button against double submission
    // (slow network + an impatient second tap used to be able to fire two
    // "checkout" POSTs). Re-bound every time the cart panel HTML is
    // replaced, since the form itself gets re-rendered.
    var bindCheckoutFormGuard = function () {
        var checkoutForm = document.getElementById('checkout-form');
        if (!checkoutForm) {
            return;
        }
        checkoutForm.removeEventListener('submit', checkoutForm._guardHandler);
        var handler = function (event) {
            var submitBtn = checkoutForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                if (submitBtn.dataset.submitted === '1') {
                    event.preventDefault();
                    return;
                }
                submitBtn.dataset.submitted = '1';
                submitBtn.disabled = true;
                submitBtn.textContent = 'Processing…';
            }
        };
        checkoutForm._guardHandler = handler;
        checkoutForm.addEventListener('submit', handler);
    };

    if (form && searchInput && productGrid) {
        var timer;
        var refreshProducts = function (term) {
            var params = new URLSearchParams();
            params.set('search', term);
            var categoryValue = form.querySelector('input[name="category"]').value;
            if (categoryValue) {
                params.set('category', categoryValue);
            }
            params.set('ajax', '1');

            fetch(window.location.pathname + '?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (response) {
                return response.text();
            })
            .then(function (html) {
                productGrid.innerHTML = html;
            })
            .catch(function () {
                productGrid.innerHTML = '<p class="muted">Unable to refresh products.</p>';
            });
        };

        searchInput.addEventListener('input', function () {
            var term = this.value.trim();
            clearTimeout(timer);
            timer = setTimeout(function () {
                refreshProducts(term);
            }, 150);
        });

        if (searchInput.value.trim()) {
            refreshProducts(searchInput.value.trim());
        }
    }

    document.querySelectorAll('.product-card').forEach(function (cardForm) {
        cardForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var formData = new FormData(this);
            var submitButton = this.querySelector('button[type="submit"]');
            var originalText = submitButton ? submitButton.textContent : '';

            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Adding...';
            }

            fetch(window.location.pathname, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data && data.success) {
                    if (cartPanel && data.cart_html) {
                        cartPanel.innerHTML = data.cart_html;
                        cartBadge = cartPanel.querySelector('#cart-count-badge');
                        cartFeedback = cartPanel.querySelector('#cart-feedback');
                    }
                    if (cartBadge) {
                        cartBadge.textContent = data.cart_count;
                    }
                    if (cartFeedback) {
                        cartFeedback.textContent = data.message;
                    }
                    if (submitButton) {
                        submitButton.textContent = 'Added!';
                        window.setTimeout(function () {
                            submitButton.textContent = originalText;
                            submitButton.disabled = false;
                        }, 800);
                    }
                } else {
                    if (cartPanel && data.cart_html) {
                        cartPanel.innerHTML = data.cart_html;
                        cartBadge = cartPanel.querySelector('#cart-count-badge');
                        cartFeedback = cartPanel.querySelector('#cart-feedback');
                    }
                    if (cartFeedback) {
                        cartFeedback.textContent = data.message || 'Unable to add product.';
                    }
                    if (submitButton) {
                        submitButton.textContent = originalText;
                        submitButton.disabled = false;
                    }
                }
                bindQuantityInputs();
                bindQuickCashButtons();
                bindAmountPaidChange();
                bindCheckoutFormGuard();
            })
            .catch(function () {
                if (cartFeedback) {
                    cartFeedback.textContent = 'Unable to add product right now.';
                }
                if (submitButton) {
                    submitButton.textContent = originalText;
                    submitButton.disabled = false;
                }
            });
        });
    });

    var cancelTransactionButton = document.getElementById('cancel-transaction-btn');
    if (cancelTransactionButton) {
        cancelTransactionButton.addEventListener('click', function (event) {
            event.preventDefault();
            Swal.fire({
                title: 'Cancel Transaction',
                text: 'Are you sure you want to cancel this transaction? All items currently in the cart will be removed.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Cancel Transaction',
                cancelButtonText: 'No, Keep Transaction',
                reverseButtons: true,
                confirmButtonColor: '#1d4ed8',
                cancelButtonColor: '#64748b',
                customClass: {
                    popup: 'swal-theme-popup',
                    confirmButton: 'swal-theme-confirm',
                    cancelButton: 'swal-theme-cancel'
                }
            }).then(function (result) {
                if (result.isConfirmed) {
                    var form = document.getElementById('cart-update-form');
                    if (form) {
                        var actionInput = form.querySelector('input[name="action"]');
                        if (actionInput) {
                            actionInput.value = 'clear';
                        }
                        var clearEvent = new Event('submit', { bubbles: true, cancelable: true });
                        form.dispatchEvent(clearEvent);
                    }
                }
            });
        });
    }

    var cartUpdateTimer = null;
    var submitCartUpdate = function (form) {
        if (!form) {
            return;
        }
        var formData = new FormData(form);
        fetch(window.location.pathname, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (response) {
            return response.json();
        })
        .then(function (data) {
            refreshCartPanel(data);
        })
        .catch(function () {
            if (cartFeedback) {
                cartFeedback.textContent = 'Unable to update cart right now.';
            }
        });
    };

    var scheduleCartUpdate = function (form) {
        if (!form) {
            return;
        }
        window.clearTimeout(cartUpdateTimer);
        cartUpdateTimer = window.setTimeout(function () {
            submitCartUpdate(form);
        }, 300);
    };

    var refreshCartPanel = function (data) {
        if (cartPanel && data.cart_html) {
            cartPanel.innerHTML = data.cart_html;
            cartBadge = cartPanel.querySelector('#cart-count-badge');
            cartFeedback = cartPanel.querySelector('#cart-feedback');
        }
        if (cartFeedback) {
            cartFeedback.textContent = data.message || '';
        }
        bindQuantityInputs();
        bindQuickCashButtons();
        bindAmountPaidChange();
        bindCheckoutFormGuard();
    };

    var lastCartSubmitter = null;
    if (cartPanel) {
        cartPanel.addEventListener('click', function (event) {
            var button = event.target.closest('button[type="submit"], input[type="submit"]');
            if (button && button.form && button.form.id === 'cart-update-form') {
                lastCartSubmitter = button;
            }
        });

        cartPanel.addEventListener('submit', function (event) {
            var form = event.target.closest('#cart-update-form');
            if (!form) {
                return;
            }
            event.preventDefault();

            var submitter = event.submitter || lastCartSubmitter;
            var formData = new FormData(form);
            if (submitter && submitter.name) {
                formData.set(submitter.name, submitter.value);
            }
            lastCartSubmitter = null;

            fetch(window.location.pathname, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                refreshCartPanel(data);
            })
            .catch(function () {
                if (cartFeedback) {
                    cartFeedback.textContent = 'Unable to update cart right now.';
                }
            });
        });
    }

    var bindQuantityInputs = function () {
        document.querySelectorAll('#cart-panel input[name^="qty["]').forEach(function (qtyInput) {
            var recalc = function () {
                var row = qtyInput.closest('tr');
                var unitPrice = parseFloat(qtyInput.dataset.price) || 0;
                if (!unitPrice) {
                    var priceText = row.querySelector('.muted') ? row.querySelector('.muted').textContent : '';
                    unitPrice = parseFloat(priceText.replace(/[^0-9\.]/g, '')) || 0;
                }
                // Clamp client-side to the item's available stock (the
                // input's own max attribute) so the on-screen line total
                // never shows a quantity higher than what will actually be
                // saved once the debounced server update lands.
                var maxQty = parseInt(qtyInput.max, 10);
                var qty = Math.max(1, parseInt(qtyInput.value, 10) || 1);
                if (!isNaN(maxQty) && maxQty > 0 && qty > maxQty) {
                    qty = maxQty;
                }
                qtyInput.value = qty;
                var totalCell = row.querySelector('.line-total');
                if (totalCell) {
                    totalCell.textContent = '₱' + (unitPrice * qty).toFixed(2);
                }
                updateCartSummary();
            };

            var debounced = function () {
                recalc();
                var form = qtyInput.closest('#cart-update-form');
                scheduleCartUpdate(form);
            };

            qtyInput.addEventListener('input', debounced);
            qtyInput.addEventListener('change', debounced);
        });
    };

    var updateCartSummary = function () {
        var subtotal = 0;
        document.querySelectorAll('#cart-panel tbody tr').forEach(function (row) {
            var totalCell = row.querySelector('td:nth-child(3)');
            if (totalCell) {
                var line = parseFloat(totalCell.textContent.replace(/[^0-9\.]/g, '')) || 0;
                subtotal += line;
            }
        });
        var discountNode = document.querySelector('#cart-panel input[name="discount"]');
        var discount = discountNode ? parseFloat(discountNode.value) || 0 : 0;
        var tax = parseFloat((Math.max(0, subtotal - discount) * TAX_RATE).toFixed(2));
        var total = parseFloat((Math.max(0, subtotal - discount) + tax).toFixed(2));
        var taxPct = Math.round(TAX_RATE * 100);
        cartPanel.querySelectorAll('p').forEach(function (p) {
            if (p.textContent.trim().startsWith('Subtotal:')) {
                p.innerHTML = '<strong>Subtotal:</strong> ₱' + subtotal.toFixed(2);
            }
            if (p.textContent.trim().startsWith('Discount:')) {
                p.innerHTML = '<strong>Discount:</strong> &minus;₱' + discount.toFixed(2);
            }
            if (p.textContent.trim().startsWith('Tax (')) {
                p.innerHTML = '<strong>Tax (' + taxPct + '%):</strong> ₱' + tax.toFixed(2);
            }
            if (p.textContent.trim().startsWith('Total:')) {
                p.innerHTML = '<strong>Total:</strong> ₱' + total.toFixed(2);
            }
        });
    };

    bindQuantityInputs();
    bindQuickCashButtons();
    bindAmountPaidChange();
    bindCheckoutFormGuard();

});
</script>
<?php page_footer(); ?>