<?php
session_start();
require 'database.php';
require 'app.php';
require_admin();

$message      = "";
$edit_product = null;

function handle_image_upload($current_image_url = '') {
    if (!isset($_FILES['product_image']) || $_FILES['product_image']['error'] === UPLOAD_ERR_NO_FILE) {
        return $current_image_url;
    }
    $file = $_FILES['product_image'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'Image upload failed. Please try again.'];
    }
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed_types)) {
        return ['error' => 'Only JPG, PNG, GIF, or WEBP images are allowed.'];
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return ['error' => 'Image must be under 2MB.'];
    }
    $ext        = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename   = 'product_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
    $upload_dir = 'images/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
        return ['error' => 'Could not save image. Check folder permissions.'];
    }
    return $upload_dir . $filename;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id            = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $category_id   = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
        $sku           = trim($_POST['sku'] ?? '');
        $name          = trim($_POST['name'] ?? '');
        $price         = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
        $stock         = filter_input(INPUT_POST, 'stock', FILTER_VALIDATE_INT);
        $is_active     = isset($_POST['is_active']) ? 1 : 0;
        $current_image = trim($_POST['current_image_url'] ?? '');

        if ($name === '' || $price === false || $price < 0 || $stock === false || $stock < 0) {
            $message = "<div class='alert error'>Please enter a valid product name, price, and stock.</div>";
        } else {
            $image_url = handle_image_upload($current_image);
            if (is_array($image_url) && isset($image_url['error'])) {
                $message = "<div class='alert error'>" . h($image_url['error']) . "</div>";
            } else {
                try {
                    if ($id) {
                        $stmt = $conn->prepare("UPDATE products SET category_id=?, sku=?, name=?, price=?, stock=?, is_active=?, image_url=? WHERE id=?");
                        $stmt->execute([$category_id ?: null, $sku ?: null, $name, $price, $stock, $is_active, $image_url, $id]);
                        sync_low_stock_notification_by_product($id, $stock);
                        header("Location: product_management.php?saved=updated#product-list"); exit;
                    } else {
                        $stmt = $conn->prepare("INSERT INTO products (category_id, sku, name, price, stock, is_active, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$category_id ?: null, $sku ?: null, $name, $price, $stock, $is_active, $image_url]);
                        $new_id = (int) $conn->lastInsertId();
                        sync_low_stock_notification_by_product($new_id, $stock);
                        header("Location: product_management.php?saved=added#product-list"); exit;
                    }
                } catch (PDOException $e) {
                    $message = "<div class='alert error'>Database save failure. Could not save product. Please try again.</div>";
                }
            }
        }
    }

    if ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            try {
                $stmt = $conn->prepare("UPDATE products SET is_active = 0 WHERE id = ?");
                $stmt->execute([$id]);
                header("Location: product_management.php?saved=removed#product-list"); exit;
            } catch (PDOException $e) {
                $message = "<div class='alert error'>Database save failure. Could not remove product.</div>";
            }
        }
    }
}

$edit_id = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
if ($edit_id) {
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_product = $stmt->fetch(PDO::FETCH_ASSOC);
}

$search        = trim($_GET['search'] ?? '');
$filter_cat    = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT);
$filter_status = $_GET['status'] ?? '';
$search_found  = true; // default

// Shared query string used to preserve the active search/category/status
// filters when linking back into this same page (e.g. from the Edit
// button). Previously only `search` was carried over, so editing a
// product from a filtered list silently dropped the category/status
// filter on return.
$filter_query_parts = [];
if ($search !== '') {
    $filter_query_parts[] = 'search=' . urlencode($search);
}
if ($filter_cat) {
    $filter_query_parts[] = 'category=' . urlencode($filter_cat);
}
if ($filter_status !== '') {
    $filter_query_parts[] = 'status=' . urlencode($filter_status);
}
$filter_query_string = $filter_query_parts ? '&' . implode('&', $filter_query_parts) : '';

try {
    $categories = $conn->query("SELECT id, name FROM categories ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

    $sql    = "SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE 1=1";
    $params = [];

    if ($search !== '') {
        $sql      .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
        $params[]  = "%{$search}%";
        $params[]  = "%{$search}%";
    }
    if ($filter_cat) {
        $sql      .= " AND p.category_id = ?";
        $params[]  = $filter_cat;
    }
    if ($filter_status === 'active') {
        $sql .= " AND p.is_active = 1";
    } elseif ($filter_status === 'inactive') {
        $sql .= " AND p.is_active = 0";
    }

    $sql .= " ORDER BY p.is_active DESC, p.name";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $search_found = !($search !== '' && empty($products));

} catch (PDOException $e) {
    $categories = [];
    $products   = [];
    $message    = "<div class='alert error'>Database retrieval failure. Could not load products. Please refresh or try again.</div>";
}

page_header('Product Management', 'Add, edit, and manage your product catalog.', 'bi-box-seam');
$saved = $_GET['saved'] ?? '';
if ($saved === 'updated') $message = "<div class='alert success'>✅ Product updated successfully.</div>";
elseif ($saved === 'added')   $message = "<div class='alert success'>✅ Product added successfully.</div>";
elseif ($saved === 'removed') $message = "<div class='alert success'>✅ Product removed from active menu.</div>";
?>
<?= $message ?>

<section class="panel">
    <h2><?= $edit_product ? 'Edit Product' : 'Add Product' ?></h2>
    <form method="POST" enctype="multipart/form-data" class="form-grid">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= h($edit_product['id'] ?? '') ?>">
        <input type="hidden" name="current_image_url" value="<?= h($edit_product['image_url'] ?? '') ?>">

        <div>
            <label for="sku">SKU</label>
            <input id="sku" name="sku" value="<?= h($edit_product['sku'] ?? '') ?>" placeholder="BRG001">
        </div>
        <div>
            <label for="name">Product Name</label>
            <input id="name" name="name" value="<?= h($edit_product['name'] ?? '') ?>" required>
        </div>
        <div>
            <label for="category_id">Category</label>
            <select id="category_id" name="category_id">
                <option value="">No category</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= h($cat['id']) ?>" <?= (($edit_product['category_id'] ?? '') == $cat['id']) ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="price">Price (₱)</label>
            <input id="price" name="price" type="number" step="0.01" min="0" value="<?= h($edit_product['price'] ?? '') ?>" required>
        </div>
        <div>
            <label for="stock">Stock</label>
            <?php
                // New products now start at 0 stock instead of a hardcoded
                // 999 placeholder, so a freshly added item isn't sellable
                // (and doesn't trip a false "in stock" state) until someone
                // deliberately sets a real quantity.
                $stock_value = array_key_exists('stock', (array) $edit_product) ? $edit_product['stock'] : 0;
            ?>
            <input id="stock" name="stock" type="number" min="0" value="<?= h($stock_value) ?>" required>
        </div>

        <div style="grid-column: 1 / -1;">
            <label for="product_image">Product Image (JPG/PNG/WEBP, max 2MB)</label>
            <div style="display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
                <?php if (!empty($edit_product['image_url'])): ?>
                    <img src="<?= h($edit_product['image_url']) ?>" alt="Current image"
                         style="width:80px; height:80px; object-fit:cover; border-radius:8px; border:1px solid #e5e7eb;">
                    <span class="muted" style="font-size:13px;">Current image (upload a new one to replace)</span>
                <?php endif; ?>
                <input id="product_image" name="product_image" type="file" accept="image/*"
                       style="flex:1; min-width:200px;" onchange="previewImage(this)">
                <img id="image_preview" src="" alt="" style="display:none; width:80px; height:80px; object-fit:cover; border-radius:8px; border:1px solid #e5e7eb;">
            </div>
        </div>

        <div>
            <label><input type="checkbox" name="is_active" style="width:auto;" <?= (($edit_product['is_active'] ?? 1) ? 'checked' : '') ?>> Active</label>
            <button type="submit" style="margin-top:8px;"><?= $edit_product ? 'Save Changes' : 'Add Product' ?></button>
            <?php if ($edit_product): ?>
                <a class="button secondary" href="product_management.php<?= $filter_query_string ? '?' . ltrim($filter_query_string, '&') : '' ?>" style="margin-top:8px;">Cancel Edit</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<script>
function previewImage(input) {
    const preview = document.getElementById('image_preview');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { preview.src = e.target.result; preview.style.display = 'block'; };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<a name="product-list" id="product-list"></a>
<section class="panel" style="margin-top:18px;">
    <h2>Product List</h2>

    <form method="GET" class="form-grid" style="margin-bottom:16px;">
        <div>
            <label for="search">Search Product</label>
            <input id="search" name="search" value="<?= h($search) ?>" placeholder="Name or SKU...">
        </div>
        <div>
            <label for="category">Category</label>
            <select id="category" name="category">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= h($cat['id']) ?>" <?= $filter_cat == $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All</option>
                <option value="active"   <?= $filter_status === 'active'   ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div style="display:flex; gap:8px; align-items:flex-end;">
            <button type="submit">Search</button>
            <?php if ($search || $filter_cat || $filter_status): ?>
                <a class="button secondary" href="product_management.php">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($search !== '' && !$search_found): ?>
        <div class="alert error" style="margin-bottom:14px;">
            No products found for "<strong><?= h($search) ?></strong>". Try a different name or SKU.
        </div>
    <?php elseif ($search !== '' && $search_found): ?>
        <div class="alert success" style="margin-bottom:14px;">
            <?= count($products) ?> product(s) found for "<strong><?= h($search) ?></strong>".
        </div>
    <?php endif; ?>

    <table>
        <thead>
            <tr><th>Image</th><th>SKU</th><th>Name</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if (!$products): ?>
            <tr><td colspan="8" style="text-align:center;" class="muted">No products found.</td></tr>
        <?php endif; ?>
        <?php foreach ($products as $product): ?>
            <tr style="<?= !$product['is_active'] ? 'opacity:0.5;' : '' ?>">
                <td>
                    <?php if (!empty($product['image_url'])): ?>
                        <img src="<?= h($product['image_url']) ?>" alt="<?= h($product['name']) ?>"
                             style="width:52px; height:52px; object-fit:cover; border-radius:6px; border:1px solid #e5e7eb;">
                    <?php else: ?>
                        <div style="width:52px; height:52px; background:#f1f5f9; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:22px; border:1px solid #e5e7eb;">🍽️</div>
                    <?php endif; ?>
                </td>
                <td><?= h($product['sku']) ?></td>
                <td><?= h($product['name']) ?></td>
                <td><?= h($product['category_name'] ?? '') ?></td>
                <td>₱<?= money($product['price']) ?></td>
                <td><?= h($product['stock']) ?></td>
                <td><?= $product['is_active'] ? '<span class="badge-status-active">Active</span>' : '<span class="badge-status-inactive">Inactive</span>' ?></td>
                <td>
                    <div class="actions">
                        <a class="button secondary" href="product_management.php?edit=<?= h($product['id']) ?><?= $filter_query_string ?>">Edit</a>
                        <form method="POST" onsubmit="return confirm('Remove this product from active menu?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= h($product['id']) ?>">
                            <button class="danger" type="submit">Remove</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<script>
if (window.location.hash === '#product-list') {
    document.addEventListener('DOMContentLoaded', function() {
        var el = document.getElementById('product-list');
        if (el) { setTimeout(function(){ el.scrollIntoView({behavior:'smooth', block:'start'}); }, 150); }
    });
}
</script>
<?php page_footer(); ?>