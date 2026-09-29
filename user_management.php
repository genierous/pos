<?php
session_start();
require 'database.php';
require 'app.php';
require_admin();

$message     = "";
$edit_user   = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id        = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $role      = in_array($_POST['role'] ?? '', ['admin', 'cashier']) ? $_POST['role'] : 'cashier';
        $password  = $_POST['password'] ?? '';

        if ($username === '' || $email === '' || $full_name === '') {
            $message = "<div class='alert error'>Please fill in all required fields.</div>";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "<div class='alert error'>Please enter a valid email address.</div>";
        } elseif (!$id && $password === '') {
            $message = "<div class='alert error'>Password is required when creating a new user.</div>";
        } elseif ($password !== '' && strlen($password) < 6) {
            $message = "<div class='alert error'>Password must be at least 6 characters.</div>";
        } else {
            try {
                if ($id) {
                    if ($password !== '') {
                        $hashed = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $conn->prepare("UPDATE users SET username=?, email=?, full_name=?, role=?, password=? WHERE id=?");
                        $stmt->execute([$username, $email, $full_name, $role, $hashed, $id]);
                    } else {
                        $stmt = $conn->prepare("UPDATE users SET username=?, email=?, full_name=?, role=? WHERE id=?");
                        $stmt->execute([$username, $email, $full_name, $role, $id]);
                    }
                    $message = "<div class='alert success'>User updated successfully.</div>";
                } else {
                    $hashed = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $conn->prepare("INSERT INTO users (username, email, full_name, role, password) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$username, $email, $full_name, $role, $hashed]);
                    $message = "<div class='alert success'>User added successfully.</div>";
                }
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    $message = "<div class='alert error'>Username or email already exists. Please use a different one.</div>";
                } else {
                    $message = "<div class='alert error'>Database save failure. Could not save user. Please try again.</div>";
                }
            }
        }
    }

    if ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id && $id !== (int) $_SESSION['user_id']) {
            try {
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $message = "<div class='alert success'>User deleted.</div>";
            } catch (PDOException $e) {
                $message = "<div class='alert error'>Cannot delete this user — they may have associated transactions.</div>";
            }
        } elseif ($id === (int) $_SESSION['user_id']) {
            $message = "<div class='alert error'>You cannot delete your own account.</div>";
        }
    }
}

$edit_id = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
if ($edit_id) {
    $stmt = $conn->prepare("SELECT id, username, email, full_name, role FROM users WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_user = $stmt->fetch(PDO::FETCH_ASSOC);
}

try {
    $users = $conn->query("SELECT u.id, u.username, u.email, u.full_name, u.role, u.created_at, COUNT(t.id) AS txn_count FROM users u LEFT JOIN transactions t ON t.user_id = u.id GROUP BY u.id ORDER BY u.role, u.full_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users   = [];
    $message = "<div class='alert error'>Database retrieval failure. Could not load users.</div>";
}

page_header('User Management', 'Manage admin and cashier accounts.', 'bi-people');
?>
<?= $message ?>

<section class="panel">
    <h2><?= $edit_user ? 'Edit User' : 'Add User' ?></h2>
    <form method="POST" class="form-grid">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= h($edit_user['id'] ?? '') ?>">

        <div>
            <label for="full_name">Full Name</label>
            <input id="full_name" name="full_name" value="<?= h($edit_user['full_name'] ?? '') ?>" required placeholder="Juan dela Cruz">
        </div>
        <div>
            <label for="username">Username</label>
            <input id="username" name="username" value="<?= h($edit_user['username'] ?? '') ?>" required placeholder="juandc">
        </div>
        <div>
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="<?= h($edit_user['email'] ?? '') ?>" required placeholder="juan@pos.com">
        </div>
        <div>
            <label for="role">Role</label>
            <select id="role" name="role">
                <option value="cashier" <?= (($edit_user['role'] ?? 'cashier') === 'cashier') ? 'selected' : '' ?>>Cashier</option>
                <option value="admin"   <?= (($edit_user['role'] ?? '') === 'admin')   ? 'selected' : '' ?>>Admin</option>
            </select>
        </div>
        <div>
            <label for="password">Password <?= $edit_user ? '(leave blank to keep current)' : '' ?></label>
            <input id="password" name="password" type="password" placeholder="<?= $edit_user ? 'Leave blank to keep' : 'Min 6 characters' ?>" <?= $edit_user ? '' : 'required' ?> minlength="6">
        </div>
        <div style="display:flex; gap:8px; align-items:flex-end;">
            <button type="submit"><?= $edit_user ? 'Save Changes' : 'Add User' ?></button>
            <?php if ($edit_user): ?>
                <a class="button secondary" href="user_management.php">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="panel" style="margin-top:18px;">
    <h2>Users</h2>
    <table>
        <thead>
            <tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Transactions</th><th>Created</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if (!$users): ?>
            <tr><td colspan="7">No users found.</td></tr>
        <?php endif; ?>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><?= h($user['full_name']) ?></td>
                <td><?= h($user['username']) ?></td>
                <td><?= h($user['email']) ?></td>
                <td>
                    <span class="<?= $user['role'] === 'admin' ? 'badge-role-admin' : 'badge-role-cashier' ?>">
                        <?= ucfirst(h($user['role'])) ?>
                    </span>
                </td>
                <td><?= h($user['txn_count']) ?></td>
                <td><?= h(substr($user['created_at'], 0, 10)) ?></td>
                <td>
                    <div class="actions">
                        <a class="button secondary" href="user_management.php?edit=<?= h($user['id']) ?>">Edit</a>
                        <?php if ($user['id'] !== (int) $_SESSION['user_id']): ?>
                            <form method="POST" onsubmit="return confirm('Delete user <?= h(addslashes($user['full_name'])) ?>? This cannot be undone.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= h($user['id']) ?>">
                                <button class="danger" type="submit">Delete</button>
                            </form>
                        <?php else: ?>
                            <span class="muted" style="font-size:12px;">(you)</span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php page_footer(); ?>