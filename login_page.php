<?php
session_start();
include 'database.php';

$message = "";

if (isset($_GET['logout'])) {
    header("Location: logout.php");
    exit;
}

if (isset($_SESSION['user_id']) && $_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: dashboard.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $account  = trim($_POST['account'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($account === '' || $password === '') {
        $message = "<div class='alert error'>Please enter your username/email and password.</div>";
    } else {
        $stmt = $conn->prepare("SELECT id, username, email, password, full_name, role FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$account, $account]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];
            header("Location: dashboard.php");
            exit;
        }

        $message = "<div class='alert error'>❌ Invalid username/email or password.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quickserve - Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        :root {
            --navy: #16234a;
            --blue: #1d4ed8;
            --blue-soft: #dbeafe;
            --ink: #1f2937;
            --ink-soft: #6b7280;
            --border-soft: #e5e7eb;
            --radius-md: 12px;
            --radius-lg: 18px;
            --shadow-lg: 0 10px 34px rgba(15,23,42,0.20);
            --font-brand: 'Sora', 'Segoe UI', Arial, sans-serif;
            --font-body: 'Inter', 'Segoe UI', Roboto, Arial, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            font-family: var(--font-body);
            color: var(--ink);
            background: linear-gradient(135deg, var(--navy), var(--blue));
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .auth-page {
            display: grid;
            grid-template-columns: 1fr 1fr;
            max-width: 900px;
            width: 100%;
            min-height: 500px;
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }

        .left-panel {
            background: var(--navy);
            padding: 48px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            color: #fff;
            position: relative;
            overflow: hidden;
        }
        .left-panel i.bi-cup-hot-fill {
            position: absolute;
            font-size: 220px;
            bottom: -50px; right: -40px;
            opacity: 0.08;
        }
        .left-panel .brand-header { display: flex; align-items: center; gap: 14px; margin-bottom: 18px; }
        .left-panel .logo {
            font-size: 26px; width: 58px; height: 58px;
            display: grid; place-items: center;
            background: rgba(255,255,255,0.12); border-radius: 16px;
        }
        .left-panel h2 { font-family: var(--font-brand); font-size: 32px; font-weight: 800; line-height: 1.1; }
        .left-panel p { font-size: 14px; opacity: 0.85; line-height: 1.7; }
        .features { margin-top: 26px; display: flex; flex-direction: column; gap: 10px; }
        .feature { display: flex; align-items: center; gap: 10px; font-size: 13px; font-weight: 500; }
        .feature i { font-size: 15px; color: #93c5fd; }

        .right-panel { background: #fff; padding: 48px 40px; display: flex; flex-direction: column; justify-content: center; }
        .right-panel h3 { font-family: var(--font-brand); font-size: 25px; font-weight: 700; color: var(--navy); margin-bottom: 6px; }
        .right-panel .sub { font-size: 13px; color: var(--ink-soft); margin-bottom: 26px; }

        .alert { padding: 12px 15px; border-radius: var(--radius-md); font-size: 13px; font-weight: 500; margin-bottom: 18px; }
        .error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }

        .field { margin-bottom: 18px; }
        .field label { display: block; font-size: 13px; font-weight: 600; color: var(--navy); margin-bottom: 6px; }
        .field input {
            width: 100%; padding: 12px 15px; border: 1px solid var(--border-soft); border-radius: var(--radius-md);
            font-size: 14px; font-family: var(--font-body); color: var(--ink); background: #f8fafc;
            transition: border-color 0.18s, box-shadow 0.18s;
        }
        .field input:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(29,78,216,0.15); background: #fff; }

        .login-btn {
            width: 100%; padding: 13px; border: 0; border-radius: var(--radius-md);
            background: var(--blue); color: #fff; font-size: 15px; font-weight: 700;
            font-family: var(--font-body); cursor: pointer; transition: background 0.18s;
            box-shadow: 0 4px 16px rgba(29,78,216,0.30); margin-bottom: 16px;
        }
        .login-btn:hover { background: var(--navy); }
        .forgot { text-align: center; font-size: 13px; }
        .forgot a { color: var(--blue); font-weight: 600; text-decoration: none; }
        .forgot a:hover { text-decoration: underline; }

        @media (max-width: 680px) {
            .auth-page { grid-template-columns: 1fr; }
            .left-panel { display: none; }
        }
    </style>
</head>
<body>
    <div class="auth-page">
        <div class="left-panel">
            <div class="brand-header">
                <div class="logo"><i class="bi bi-cup-hot-fill"></i></div>
                <h2>Quickserve</h2>
            </div>
            <p>Fast, simple, and organized food sales management.</p>
            <div class="features">
                <div class="feature"><i class="bi bi-check-circle-fill"></i> Real-time sales & inventory</div>
                <div class="feature"><i class="bi bi-check-circle-fill"></i> Printable receipts</div>
                <div class="feature"><i class="bi bi-check-circle-fill"></i> Low stock alerts</div>
            </div>
            <i class="bi bi-cup-hot-fill"></i>
        </div>

        <div class="right-panel">
            <h3>Sign In</h3>
            <p class="sub">Enter your credentials to access the system.</p>

            <?= $message ?>

            <form method="POST">
                <div class="field">
                    <label for="account">Username or Email</label>
                    <input type="text" id="account" name="account" placeholder="admin or admin@pos.com" required autofocus>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="••••••••" required>
                </div>
                <button type="submit" class="login-btn">Login <i class="bi bi-arrow-right"></i></button>
            </form>
        </div>
    </div>
</body>
</html>