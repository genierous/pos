<?php
session_start();
require 'database.php';
require 'app.php';
require_admin();

$notifications = get_low_stock_notifications();

page_header('Low Stock Notifications', 'Products that need restocking soon.', 'bi-exclamation-triangle');
?>

<section class="panel">
    <h2>Low Stock Alerts</h2>
    <?php if (!$notifications): ?>
        <div class="alert success">No active low-stock notifications. All products are above threshold.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Current Stock</th>
                    <th>Threshold</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($notifications as $notif): ?>
                    <tr>
                        <td><?= h($notif['product_name'] ?: 'Unknown product') ?></td>
                        <td><?= h($notif['current_stock']) ?></td>
                        <td><?= h($notif['threshold']) ?></td>
                        <td><?= h($notif['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php page_footer(); ?>