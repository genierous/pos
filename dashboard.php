<?php
session_start();
require 'database.php';
require 'app.php';
require_login();

$product_count      = (int)   $conn->query("SELECT COUNT(*) FROM products WHERE is_active = 1")->fetchColumn();
$stock_count        = (int)   $conn->query("SELECT COALESCE(SUM(stock),0) FROM products WHERE is_active = 1")->fetchColumn();
$sales_total        = (float) $conn->query("SELECT COALESCE(SUM(total),0) FROM transactions WHERE status = 'completed'")->fetchColumn();
$transaction_count  = (int)   $conn->query("SELECT COUNT(*) FROM transactions WHERE status = 'completed'")->fetchColumn();

$recent = $conn->query("SELECT transaction_code, total, payment_method, created_at FROM transactions WHERE status = 'completed' ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

$is_admin_view = is_admin();

// The extra KPIs and the analytics charts mirror the wider "insights" role
// admins already get elsewhere in the app (Sales Reports, Low Stock Alerts
// are admin-only nav items), so they're admin-only here too rather than
// shown to cashiers.
$low_stock_count    = 0;
$staff_count        = 0;
$category_labels    = [];
$category_values    = [];
$trend_labels       = [];
$trend_values       = [];
$payment_labels     = [];
$payment_values     = [];

if ($is_admin_view) {
    $low_stock_count = low_stock_notification_count();
    $staff_count     = (int) $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();

    // Sales by Category — pie chart
    $stmt = $conn->query("
        SELECT COALESCE(c.name, 'Uncategorized') AS category_name, COALESCE(SUM(ti.subtotal), 0) AS total
        FROM transaction_items ti
        INNER JOIN transactions t ON t.id = ti.transaction_id AND t.status = 'completed'
        INNER JOIN products p ON p.id = ti.product_id
        LEFT JOIN categories c ON c.id = p.category_id
        GROUP BY category_name
        ORDER BY total DESC
    ");
    $category_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $category_labels = array_column($category_rows, 'category_name');
    $category_values = array_map('floatval', array_column($category_rows, 'total'));

    // Sales Trend (Last 6 Months) — line chart
    $months = [];
    for ($i = 5; $i >= 0; $i--) {
        $months[date('Y-m', strtotime("-{$i} months"))] = 0.0;
    }
    $stmt = $conn->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, SUM(total) AS total
        FROM transactions
        WHERE status = 'completed' AND created_at >= ?
        GROUP BY ym
    ");
    $stmt->execute([date('Y-m-01', strtotime('-5 months'))]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (array_key_exists($row['ym'], $months)) {
            $months[$row['ym']] = (float) $row['total'];
        }
    }
    foreach ($months as $ym => $total) {
        $trend_labels[] = date('M Y', strtotime($ym . '-01'));
        $trend_values[] = $total;
    }

    // Payment Methods — bar chart
    $stmt = $conn->query("
        SELECT payment_method, COUNT(*) AS count
        FROM transactions
        WHERE status = 'completed'
        GROUP BY payment_method
    ");
    $payment_rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $payment_labels = array_map('ucfirst', array_column($payment_rows, 'payment_method'));
    $payment_values = array_map('intval', array_column($payment_rows, 'count'));
}

page_header('Dashboard', "Today's overview of sales, stock, and activity.", 'bi-speedometer2');

if (isset($_GET['denied'])): ?>
    <div class="alert error">Access denied. You do not have permission to view that page.</div>
<?php endif;

$kpis = [
    ['icon' => 'bi-box-seam',  'label' => 'ACTIVE PRODUCTS',   'value' => $product_count],
    ['icon' => 'bi-boxes',     'label' => 'TOTAL STOCK',       'value' => $stock_count],
    ['icon' => 'bi-receipt',   'label' => 'COMPLETED SALES',   'value' => $transaction_count],
    ['icon' => 'bi-cash-coin', 'label' => 'REVENUE',           'value' => '₱' . money($sales_total)],
];
if ($is_admin_view) {
    $kpis[] = ['icon' => 'bi-exclamation-triangle', 'label' => 'LOW STOCK ALERTS', 'value' => $low_stock_count];
    $kpis[] = ['icon' => 'bi-people',               'label' => 'ACTIVE STAFF',     'value' => $staff_count];
}
?>

<div class="grid cards mb-4">
    <?php foreach ($kpis as $index => $kpi): $accent = pos_kpi_accent($index); ?>
        <div class="kpi-card">
            <div class="kpi-icon <?= h($accent) ?>"><i class="bi <?= h($kpi['icon']) ?>"></i></div>
            <small><?= h($kpi['label']) ?></small>
            <h3><?= h($kpi['value']) ?></h3>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-4">
            <a class="button" href="sales_transaction.php"><i class="bi bi-plus-lg"></i> New Sale</a>
            <?php if ($is_admin_view): ?>
                <a class="button secondary" href="product_management.php"><i class="bi bi-box-seam"></i> Manage Products</a>
                <a class="button secondary" href="sales_reports.php"><i class="bi bi-graph-up"></i> View Reports</a>
                <a class="button secondary" href="user_management.php"><i class="bi bi-people"></i> Manage Users</a>
            <?php endif; ?>
        </div>
        <div class="chart-card-title"><i class="bi bi-clock-history"></i> Recent Transactions</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr><th>Code</th><th>Total</th><th>Payment</th><th>Date</th></tr>
                </thead>
                <tbody>
                <?php if (!$recent): ?>
                    <tr><td colspan="4" class="text-center text-muted">No transactions yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td><?= h($row['transaction_code']) ?></td>
                        <td>₱<?= money($row['total']) ?></td>
                        <td><?= h(ucfirst($row['payment_method'])) ?></td>
                        <td><?= h($row['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($is_admin_view): ?>
<div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); margin-top: 18px;">
    <div class="panel">
        <div class="chart-card-title"><i class="bi bi-pie-chart"></i> Sales by Category</div>
        <?php if ($category_labels): ?>
            <canvas id="categoryChart" height="230"></canvas>
        <?php else: ?>
            <p class="muted">No category sales data yet.</p>
        <?php endif; ?>
    </div>
    <div class="panel">
        <div class="chart-card-title"><i class="bi bi-graph-up"></i> Sales Trend (Last 6 Months)</div>
        <canvas id="trendChart" height="230"></canvas>
    </div>
    <div class="panel">
        <div class="chart-card-title"><i class="bi bi-bar-chart"></i> Payment Methods</div>
        <?php if ($payment_labels): ?>
            <canvas id="paymentChart" height="230"></canvas>
        <?php else: ?>
            <p class="muted">No payment data yet.</p>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') {
        return; // Chart.js CDN didn't load — charts just stay blank rather than throwing.
    }

    var palette = ['#1d4ed8', '#059669', '#ea580c', '#dc2626', '#7c3aed', '#0d9488'];

    var categoryLabels = <?= json_encode($category_labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var categoryValues = <?= json_encode($category_values, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var trendLabels     = <?= json_encode($trend_labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var trendValues      = <?= json_encode($trend_values, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var paymentLabels   = <?= json_encode($payment_labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var paymentValues    = <?= json_encode($payment_values, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    var categoryCanvas = document.getElementById('categoryChart');
    if (categoryCanvas && categoryLabels.length) {
        new Chart(categoryCanvas, {
            type: 'pie',
            data: {
                labels: categoryLabels,
                datasets: [{
                    data: categoryValues,
                    backgroundColor: categoryLabels.map(function (_, i) { return palette[i % palette.length]; }),
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    var trendCanvas = document.getElementById('trendChart');
    if (trendCanvas) {
        new Chart(trendCanvas, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: 'Revenue (₱)',
                    data: trendValues,
                    borderColor: '#1d4ed8',
                    backgroundColor: 'rgba(29,78,216,0.12)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }

    var paymentCanvas = document.getElementById('paymentChart');
    if (paymentCanvas && paymentLabels.length) {
        new Chart(paymentCanvas, {
            type: 'bar',
            data: {
                labels: paymentLabels,
                datasets: [{
                    label: 'Transactions',
                    data: paymentValues,
                    backgroundColor: paymentLabels.map(function (_, i) { return palette[(i + 1) % palette.length]; }),
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }
});
</script>
<?php endif; ?>

<?php page_footer(); ?>