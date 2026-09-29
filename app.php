<?php
function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money($value) {
    return number_format((float) $value, 2);
}

function require_login() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        header("Location: login_page.php");
        exit;
    }
}

function current_user_role() {
    return $_SESSION['role'] ?? '';
}

function is_admin() {
    return current_user_role() === 'admin';
}

define('LOW_STOCK_THRESHOLD', 10);

function require_admin() {
    require_login();
    if (!is_admin()) {
        header("Location: dashboard.php?denied=1");
        exit;
    }
}

function initialize_low_stock_notifications_table() {
    global $conn;
    if (!isset($conn)) {
        return;
    }
    $conn->exec("CREATE TABLE IF NOT EXISTS low_stock_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        current_stock INT NOT NULL,
        threshold INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME DEFAULT NULL,
        INDEX (product_id),
        INDEX (resolved_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

function low_stock_notification_count() {
    global $conn;
    if (!isset($conn)) {
        return 0;
    }
    initialize_low_stock_notifications_table();
    return (int) $conn->query("SELECT COUNT(*) FROM low_stock_notifications WHERE resolved_at IS NULL")->fetchColumn();
}

function get_low_stock_notifications() {
    global $conn;
    if (!isset($conn)) {
        return [];
    }
    initialize_low_stock_notifications_table();
    $stmt = $conn->query("SELECT n.product_id, n.current_stock, n.threshold, n.created_at, p.name AS product_name
        FROM low_stock_notifications n
        LEFT JOIN products p ON p.id = n.product_id
        WHERE n.resolved_at IS NULL
        ORDER BY n.created_at DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function sync_low_stock_notification_by_product($product_id, $stock = null) {
    global $conn;
    if (!isset($conn) || !$product_id) {
        return;
    }

    if ($stock === null) {
        $stmt = $conn->prepare("SELECT stock FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $stock = $stmt->fetchColumn();
        if ($stock === false) {
            return;
        }
    }

    $stock = (int) $stock;
    $threshold = LOW_STOCK_THRESHOLD;

    if ($stock <= $threshold) {
        $stmt = $conn->prepare("SELECT id FROM low_stock_notifications WHERE product_id = ? AND resolved_at IS NULL LIMIT 1");
        $stmt->execute([$product_id]);
        if ($stmt->fetchColumn()) {
            $update = $conn->prepare("UPDATE low_stock_notifications SET current_stock = ?, threshold = ? WHERE product_id = ? AND resolved_at IS NULL");
            $update->execute([$stock, $threshold, $product_id]);
        } else {
            $insert = $conn->prepare("INSERT INTO low_stock_notifications (product_id, current_stock, threshold) VALUES (?, ?, ?)");
            $insert->execute([$product_id, $stock, $threshold]);
        }
    } else {
        $resolve = $conn->prepare("UPDATE low_stock_notifications SET resolved_at = NOW() WHERE product_id = ? AND resolved_at IS NULL");
        $resolve->execute([$product_id]);
    }
}

function current_user_name() {
    return $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
}

/**
 * Renders a nav link for the sidebar, marking it active against the current file.
 */
function pos_nav_item($url, $icon, $label, $current, $badge = 0) {
    $active = (basename($url) === $current) ? 'active' : '';
    $badgeHtml = $badge > 0 ? ' <span class="badge bg-warning text-dark">' . (int) $badge . '</span>' : '';
    echo '<a href="' . h($url) . '" class="nav-link ' . $active . '"><i class="bi ' . h($icon) . '"></i> <span>' . h($label) . '</span>' . $badgeHtml . '</a>';
}

/**
 * Cycles through the KPI accent palette by index (0-based).
 */
function pos_kpi_accent($index) {
    $accents = ['blue', 'mint', 'orange', 'red', 'purple', 'teal'];
    return $accents[$index % count($accents)];
}

function page_header($title, $subtitle = '', $icon = 'bi-speedometer2') {
    $name    = h(current_user_name());
    $role    = h(current_user_role());
    $initial = h(strtoupper(substr(current_user_name(), 0, 1)));
    $current = basename($_SERVER['PHP_SELF']);
    $low_stock_count = is_admin() ? low_stock_notification_count() : 0;

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS - {$title}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        /* ============================================
           POS — "Sky Glass" Theme
           Merged directly into the page shell so there
           is a single source of truth for styling (no
           separate stylesheet competing with this block).
           ============================================ */
        :root {
            --navy: #16234a;
            --navy-deep: #0c1630;
            --navy-mid: #1c2f5e;
            --blue: #1d4ed8;
            --blue-light: #3b82f6;
            --blue-soft: #dbeafe;
            --mint: #059669;
            --mint-soft: #d1fae5;
            --orange: #ea580c;
            --orange-soft: #ffedd5;
            --red: #dc2626;
            --red-soft: #fee2e2;
            --purple: #7c3aed;
            --purple-soft: #ede9fe;
            --teal: #0d9488;
            --teal-soft: #ccfbf1;
            --sidebar-bg: rgba(255, 255, 255, 0.66);
            --content-bg: #f4f6fb;
            --ink: #1f2937;
            --ink-soft: #6b7280;
            --ink-faint: #8996ad;
            --border-soft: #e5e7eb;
            --glass: rgba(255, 255, 255, 0.62);
            --glass-border: rgba(255, 255, 255, 0.75);
            --sidebar-width: 260px;
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --shadow-sm: 0 1px 3px rgba(15,23,42,0.06);
            --shadow-md: 0 4px 16px rgba(15,23,42,0.07);
            --shadow-lg: 0 10px 30px rgba(15,23,42,0.14);
            --shadow-glow: 0 0 0 1px rgba(29,78,216,0.10), 0 14px 34px rgba(29,78,216,0.20);
            --font-brand: 'Sora', 'Segoe UI', Arial, sans-serif;
            --font-body: 'Inter', 'Segoe UI', Roboto, Arial, sans-serif;
        }
        * { box-sizing: border-box; }
        * { scrollbar-color: rgba(29,78,216,0.35) transparent; }
        *::-webkit-scrollbar-thumb { background: rgba(29,78,216,0.35); }
        body {
            font-family: var(--font-body); color: var(--ink); -webkit-font-smoothing: antialiased;
            background:
                radial-gradient(1100px 600px at 6% -10%, rgba(59,130,246,0.14), transparent 55%),
                radial-gradient(900px 520px at 100% 0%, rgba(29,78,216,0.10), transparent 50%),
                linear-gradient(180deg, #f4f7fd 0%, #eef2fb 45%, #f7f9fd 100%);
            background-attachment: fixed;
        }
        a { color: var(--blue); }
        a:hover { color: var(--navy); }
        h1, h2, h3 { font-family: var(--font-brand); }

        @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes dropIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes shimmer { 0% { transform: translateX(-120%); } 55%, 100% { transform: translateX(120%); } }
        @keyframes pulseSoft { 0%, 100% { box-shadow: 0 0 0 0 rgba(217,119,6,0.22); } 50% { box-shadow: 0 0 0 5px rgba(217,119,6,0); } }

        /* Navbar */
        .app-navbar {
            background: linear-gradient(100deg, var(--navy-deep) 0%, var(--navy) 55%, var(--blue) 130%);
            backdrop-filter: blur(18px) saturate(150%);
            -webkit-backdrop-filter: blur(18px) saturate(150%);
            position: sticky; top: 0; z-index: 1030;
            border-bottom: 1px solid rgba(255,255,255,0.12);
            box-shadow: 0 4px 24px rgba(8,26,66,0.28);
            min-height: 64px;
            padding: 0 22px;
            display: flex; align-items: center; justify-content: space-between; gap: 14px;
        }
        .navbar-left { display: flex; align-items: center; gap: 14px; }
        #sidebarToggle {
            background: rgba(255,255,255,0.20); border: 1px solid rgba(255,255,255,0.16); color: #fff; font-size: 22px;
            width: 40px; height: 40px; border-radius: var(--radius-sm);
            display: inline-flex; align-items: center; justify-content: center; cursor: pointer;
            transition: background .18s ease;
        }
        #sidebarToggle:hover { background: rgba(255,255,255,0.32); }
        .navbar-brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 1.2rem; text-decoration: none; color: #fff; font-family: var(--font-brand); }
        .navbar-brand:hover { color: #fff; }
        .navbar-brand .brand-icon {
            width: 34px; height: 34px; border-radius: 9px; background: rgba(255,255,255,0.16);
            display: flex; align-items: center; justify-content: center; font-size: 17px; color: #fff;
        }
        .navbar-right { display: flex; align-items: center; gap: 16px; }
        .navbar-right .role-badge {
            font-weight: 600; font-size: .68rem; letter-spacing: .4px; text-transform: uppercase;
            background: rgba(255,255,255,0.20); border: 1px solid rgba(255,255,255,0.16); color: #fff; padding: 6px 14px; border-radius: 999px;
            backdrop-filter: blur(6px);
        }
        .notification-bell {
            position: relative; color: #fff; font-size: 19px; text-decoration: none; display: inline-flex;
            align-items: center; justify-content: center; opacity: .9;
            width: 36px; height: 36px; border-radius: 50%; transition: background .18s ease, opacity .18s ease;
        }
        .notification-bell:hover { opacity: 1; background: rgba(255,255,255,0.20); }
        .user-dropdown { position: relative; }
        .user-dropdown-btn {
            display: flex; align-items: center; gap: 9px;
            background: rgba(255,255,255,0.20); border: 1px solid rgba(255,255,255,0.16); border-radius: 999px;
            padding: 6px 16px 6px 6px;
            color: #fff; cursor: pointer; font-family: var(--font-body); font-weight: 500;
            transition: background .18s ease;
        }
        .user-dropdown-btn:hover, .user-dropdown-btn:focus, .user-dropdown-btn[aria-expanded="true"] { background: rgba(255,255,255,0.32); }
        .user-avatar {
            width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, var(--purple), #a855f7);
            box-shadow: 0 2px 8px rgba(124,58,237,0.35);
            display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; color: #fff;
            flex-shrink: 0;
        }
        /*
            IMPORTANT: position/display/z-index are defined explicitly here
            instead of being left to Bootstrap's CDN stylesheet alone. If
            that CDN request is ever slow, blocked, or fails, this menu
            still stays hidden and correctly positioned — only the ".show"
            class (added by Bootstrap's JS on click, which doesn't depend
            on the CSS file) reveals it. Without this, a failed CSS load
            causes the menu to render as a full-width, always-visible block
            that overlaps the navbar and gets clipped at the viewport edge.

            NOTE: this static positioning only actually takes effect because
            the toggle button below uses data-bs-display="static". Without
            that attribute, Bootstrap's dropdown plugin hands positioning to
            Popper.js, which writes its own inline position/transform onto
            this element at click time — and an inline style always wins
            over these rules, even though they're more specific than
            Bootstrap's defaults. That was silently overriding top/right/left
            here and made the menu render stretched across the navbar
            instead of tucked under the avatar button.
        */
        .app-navbar .dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            left: auto;
            z-index: 1050;
            display: none;
            min-width: 200px;
            border: 1px solid var(--glass-border); border-radius: var(--radius-md); box-shadow: var(--shadow-lg); margin-top: 10px;
            background: rgba(255,255,255,0.96);
            backdrop-filter: blur(20px) saturate(160%);
            -webkit-backdrop-filter: blur(20px) saturate(160%);
            animation: dropIn .18s ease both;
            overflow: hidden;
            padding: 6px;
        }
        .app-navbar .dropdown-menu.show { display: block; }
        .app-navbar .dropdown-menu::before,
        .app-navbar .dropdown-menu::after { display: none !important; content: none !important; }
        .app-navbar .dropdown-item {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 12px; font-size: .9rem; border-radius: var(--radius-sm); margin: 0; width: 100%;
            color: var(--ink); transition: background .15s ease, color .15s ease;
            background: none; border: 0; text-align: left;
        }
        .app-navbar .dropdown-item i { font-size: 1rem; width: 18px; text-align: center; }
        .app-navbar .dropdown-item:hover, .app-navbar .dropdown-item:focus { background: var(--blue-soft); color: var(--navy); }
        .app-navbar .dropdown-item.text-danger { color: var(--red); }
        .app-navbar .dropdown-item.text-danger:hover { background: var(--red-soft); color: var(--red); }
        .app-navbar .dropdown-divider { margin: 4px 8px; border-top: 1px solid var(--border-soft); }

        /* Shell / Sidebar */
        .app-wrapper { display: flex; min-height: calc(100vh - 64px); }
        .app-sidebar {
            width: var(--sidebar-width); background: var(--sidebar-bg);
            backdrop-filter: blur(20px) saturate(160%);
            -webkit-backdrop-filter: blur(20px) saturate(160%);
            border-right: 1px solid var(--glass-border);
            min-height: calc(100vh - 64px); flex-shrink: 0; overflow-y: auto;
            transition: margin-left .25s cubic-bezier(.4,0,.2,1);
        }
        .sidebar-group-label {
            font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
            color: var(--ink-faint); padding: 16px 16px 6px;
        }
        .sidebar-group-label:first-child { padding-top: 18px; }
        .app-sidebar .nav-link {
            color: #374151; padding: 11px 14px; border-radius: var(--radius-md); margin: 3px 10px;
            font-size: .93rem; font-weight: 500; display: flex; align-items: center; gap: 11px;
            transition: background .18s ease, color .18s ease, transform .18s ease;
        }
        .app-sidebar .nav-link i { width: 20px; text-align: center; font-size: 1.05rem; flex-shrink: 0; color: #6b7280; }
        .app-sidebar .nav-link:hover { background: rgba(29,78,216,0.09); color: var(--navy); transform: translateX(3px); }
        .app-sidebar .nav-link.active { background: linear-gradient(135deg, var(--blue), var(--navy)); color: #fff; box-shadow: 0 6px 16px rgba(29,78,216,0.35); font-weight: 600; }
        .app-sidebar .nav-link.active i { color: #fff; }
        .app-sidebar .nav-link .badge.bg-warning { animation: pulseSoft 2.4s ease-in-out infinite; }
        .sidebar-footer { border-top: 1px solid var(--glass-border); padding: 14px 16px; font-size: .78rem; color: var(--ink-faint); display: flex; align-items: center; gap: 10px; }

        .app-sidebar.collapsed { margin-left: calc(var(--sidebar-width) * -1); }
        @media (max-width: 900px) {
            .app-sidebar { position: fixed; left: 0; top: 64px; z-index: 1020; box-shadow: 2px 0 16px rgba(0,0,0,0.15); margin-left: calc(var(--sidebar-width) * -1); background: rgba(255,255,255,0.95); }
            .app-sidebar.show { margin-left: 0; }
            .app-sidebar.collapsed { margin-left: calc(var(--sidebar-width) * -1); }
        }

        /* Content */
        .main, .app-content { flex-grow: 1; padding: 30px; width: 100%; min-width: 0; animation: fadeUp .35s ease; }
        .emp-page-header { margin-bottom: 1.75rem; animation: fadeUp .4s ease both; }
        .emp-page-header h1 { font-size: 1.6rem; font-weight: 700; margin: 0 0 4px; color: var(--navy); }
        .emp-page-header p { font-size: .92rem; color: var(--ink-soft); margin: 0; }
        .emp-page-icon { display: none; }

        /* Cards / Panel (legacy class names kept so existing pages re-skin automatically) */
        .grid { display: grid; gap: 18px; }
        .cards { grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
        .card, .panel {
            background: var(--glass); border-radius: var(--radius-lg); box-shadow: var(--shadow-md);
            border: 1px solid var(--glass-border); padding: 22px;
            backdrop-filter: blur(16px) saturate(160%);
            -webkit-backdrop-filter: blur(16px) saturate(160%);
            transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
            animation: fadeUp .4s ease both;
            position: relative;
        }
        .card:hover, .panel:hover { transform: translateY(-3px); border-color: rgba(29,78,216,0.30); box-shadow: var(--shadow-glow); }
        .card strong { display: block; font-size: 28px; font-weight: 700; margin-top: 10px; color: var(--navy); font-family: var(--font-brand); }
        .panel h2 {
            font-size: 16px; font-weight: 700; color: var(--navy); margin-bottom: 16px;
            padding-bottom: 12px; border-bottom: 1px solid var(--glass-border); font-family: var(--font-brand);
        }
        .grid.cards > *:nth-child(1) { animation-delay: .02s; }
        .grid.cards > *:nth-child(2) { animation-delay: .08s; }
        .grid.cards > *:nth-child(3) { animation-delay: .14s; }
        .grid.cards > *:nth-child(4) { animation-delay: .20s; }
        .grid.cards > *:nth-child(5) { animation-delay: .26s; }
        .grid.cards > *:nth-child(6) { animation-delay: .32s; }

        /* KPI stat cards */
        .stat-card, .kpi-card {
            background: var(--glass); border-radius: var(--radius-lg); box-shadow: var(--shadow-md);
            border: 1px solid var(--glass-border); padding: 20px 22px; position: relative; overflow: hidden;
            backdrop-filter: blur(16px) saturate(160%);
            -webkit-backdrop-filter: blur(16px) saturate(160%);
            transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
            animation: fadeUp .4s ease both;
        }
        .stat-card:hover, .kpi-card:hover { transform: translateY(-3px); border-color: rgba(29,78,216,0.30); box-shadow: var(--shadow-glow); }
        .stat-card::before, .kpi-card::before {
            content: ""; position: absolute; inset: 0;
            background: linear-gradient(120deg, transparent 30%, rgba(59,130,246,0.10) 50%, transparent 70%);
            transform: translateX(-100%); animation: shimmer 3.6s ease-in-out infinite; pointer-events: none;
        }
        .kpi-card small, .stat-card small { display: block; font-size: .72rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--ink-soft); }
        .kpi-card h3, .stat-card h3 { font-family: var(--font-brand); font-weight: 700; font-size: 1.7rem; margin: 10px 0 0; color: var(--navy); }
        .kpi-icon {
            position: absolute; top: 18px; right: 18px; width: 42px; height: 42px; border-radius: 11px;
            display: flex; align-items: center; justify-content: center; font-size: 1.15rem;
        }
        .kpi-icon.blue   { background: rgba(29,78,216,0.12); color: var(--blue); }
        .kpi-icon.mint   { background: var(--mint-soft);     color: var(--mint); }
        .kpi-icon.orange { background: var(--orange-soft);   color: var(--orange); }
        .kpi-icon.red    { background: var(--red-soft);      color: var(--red); }
        .kpi-icon.purple { background: var(--purple-soft);   color: var(--purple); }
        .kpi-icon.teal   { background: var(--teal-soft);     color: var(--teal); }
        /* legacy gradient classes fall back to flat navy */
        .bg-grad-blue, .bg-grad-green, .bg-grad-orange, .bg-grad-purple { background: var(--navy) !important; }

        .chart-card-title { display: flex; align-items: center; gap: 10px; font-weight: 700; color: var(--navy); font-family: var(--font-brand); font-size: 1rem; margin-bottom: 16px; }
        .chart-card-title i { color: var(--blue); }

        /* Table */
        table { width: 100%; border-collapse: collapse; border-radius: var(--radius-lg); overflow: hidden; }
        th { background: rgba(29,78,216,0.08); color: var(--navy); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 12px 14px; white-space: nowrap; }
        td { padding: 12px 14px; border-bottom: 1px solid rgba(15,23,42,0.06); font-size: 14px; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(29,78,216,0.06); }

        /* Forms */
        label { display: block; margin-bottom: 5px; font-weight: 600; font-size: 13px; color: var(--navy); }
        input, select {
            width: 100%; padding: 9px 12px; border: 1px solid rgba(15,23,42,0.12); border-radius: var(--radius-sm);
            font-size: 14px; font-family: var(--font-body); color: var(--ink); background: rgba(255,255,255,0.85);
            transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
        }
        input:focus, select:focus { outline: none; background: #fff; border-color: var(--blue-light); box-shadow: 0 0 0 .2rem rgba(59,130,246,0.20); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; align-items: end; }

        /* Buttons */
        .button, button {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 9px 18px; border: 0; border-radius: var(--radius-sm);
            background: linear-gradient(135deg, var(--blue-light), var(--blue)); color: #fff; font-weight: 600; font-size: 14px;
            font-family: var(--font-body); cursor: pointer; transition: background .15s ease, transform .15s ease, box-shadow .15s ease;
            text-decoration: none;
            box-shadow: 0 4px 14px rgba(29,78,216,0.30);
        }
        .button:hover, button:hover { background: linear-gradient(135deg, var(--blue), var(--navy)); transform: translateY(-1px); box-shadow: 0 8px 20px rgba(29,78,216,0.38); }
        button:active { transform: translateY(1px); }
        .button.secondary, button.secondary { background: rgba(15,23,42,0.06); color: var(--navy); border: 1px solid rgba(15,23,42,0.10); box-shadow: none; }
        .button.secondary:hover, button.secondary:hover { background: rgba(29,78,216,0.08); }
        .button.danger, button.danger { background: linear-gradient(135deg, #ef4444, var(--red)); box-shadow: 0 4px 14px rgba(220,38,38,0.28); }
        .button.danger:hover, button.danger:hover { background: #b91c1c; }
        button:disabled { background: rgba(15,23,42,0.10) !important; box-shadow: none !important; cursor: not-allowed; }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }

        /* Alerts */
        .alert { padding: 12px 16px; margin-bottom: 18px; border-radius: var(--radius-md); font-weight: 500; font-size: 14px; box-shadow: var(--shadow-sm); display: flex; align-items: center; gap: 8px; animation: fadeUp .3s ease both; }
        .success { background: var(--mint-soft); color: #065f46; border-left: 4px solid var(--mint); }
        .error { background: var(--red-soft); color: #991b1b; border-left: 4px solid var(--red); }
        .muted { color: var(--ink-soft); font-size: 13px; }

        /* Status / role badges */
        .badge-status-active { background: var(--mint); color: #fff; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 600; }
        .badge-status-inactive { background: var(--red); color: #fff; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 600; }
        .badge-role-admin { background: linear-gradient(135deg, var(--navy), var(--navy-deep)); color: #fff; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 600; }
        .badge-role-cashier { background: rgba(29,78,216,0.12); color: var(--blue); padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 600; }

        .two-col { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 20px; align-items: start; }
        @media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }

        /* Footer */
        footer { background: rgba(255,255,255,0.7) !important; backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); color: var(--ink-soft) !important; }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: .001ms !important; animation-iteration-count: 1 !important; transition-duration: .001ms !important; }
        }

        /* SweetAlert2 logout modal — matches "Sky Glass" card style */
        .swal-theme-popup { border-radius: 16px !important; padding: 26px 28px 24px !important; }
        .swal-theme-title {
            text-align: left !important; color: var(--navy) !important; font-family: var(--font-brand) !important;
            font-weight: 700 !important; font-size: 1.3rem !important; margin: 0 0 4px !important; padding: 0 !important;
        }
        .swal-theme-close { color: #9ca3af !important; font-size: 1.1rem !important; top: 22px !important; right: 20px !important; }
        .swal-logout-icon { font-size: 42px; color: #dc2626; margin: 6px 0 10px; }
        .swal-logout-text { color: #374151; font-size: .98rem; margin: 0 0 6px; }
        .swal-theme-confirm, .swal-theme-cancel {
            border-radius: 8px !important; font-weight: 600 !important; padding: 10px 22px !important; box-shadow: none !important;
        }
    </style>
</head>
<body>
<nav class="app-navbar">
    <div class="navbar-left">
        <button id="sidebarToggle" title="Toggle sidebar" aria-label="Toggle sidebar"><i class="bi bi-list"></i></button>
        <a class="navbar-brand" href="dashboard.php">
            <span class="brand-icon"><i class="bi bi-cup-hot-fill"></i></span>
            <span>POS</span>
        </a>
    </div>
    <div class="navbar-right">
        <span class="role-badge">{$role}</span>
HTML;

    if (is_admin()) {
        $badge = $low_stock_count > 0 ? '<span class="badge rounded-pill bg-danger" style="position:absolute;top:-6px;right:-8px;font-size:.6rem;">' . h($low_stock_count) . '</span>' : '';
        echo <<<HTML
        <a href="low_stock_notifications.php" class="notification-bell" title="Low stock alerts">
            <i class="bi bi-bell"></i>{$badge}
        </a>
HTML;
    }

    echo <<<HTML
        <div class="dropdown user-dropdown">
            <button class="user-dropdown-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                <span class="user-avatar">{$initial}</span>
                <span class="d-none d-sm-inline">{$name}</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item text-danger" href="logout.php" id="logoutLink"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="app-wrapper">
    <aside class="app-sidebar" id="appSidebar">
        <div class="nav flex-column p-2">
            <div class="sidebar-group-label">Overview</div>
HTML;

    pos_nav_item('dashboard.php', 'bi-speedometer2', 'Dashboard', $current);

    echo '<div class="sidebar-group-label">Sales</div>';
    pos_nav_item('sales_transaction.php', 'bi-receipt', 'New Sale', $current);

    if (is_admin()) {
        echo '<div class="sidebar-group-label">Inventory</div>';
        pos_nav_item('product_management.php', 'bi-box-seam', 'Product Management', $current);
        pos_nav_item('low_stock_notifications.php', 'bi-exclamation-triangle', 'Low Stock Alerts', $current, $low_stock_count);

        echo '<div class="sidebar-group-label">Insights</div>';
        pos_nav_item('sales_reports.php', 'bi-graph-up', 'Sales Reports', $current);

        echo '<div class="sidebar-group-label">Administration</div>';
        pos_nav_item('user_management.php', 'bi-people', 'User Management', $current);
    }

    echo <<<HTML
        </div>
        <div class="sidebar-footer">
            <i class="bi bi-cup-hot"></i>
            <span>{$role} account</span>
        </div>
    </aside>
    <main class="main app-content">
HTML;

    if ($title) {
        echo '<div class="emp-page-header"><h1>' . h($title) . '</h1>';
        if ($subtitle) {
            echo '<p>' . h($subtitle) . '</p>';
        }
        echo '</div>';
    }
}

function page_footer() {
    echo <<<HTML
    </main>
</div>
<footer class="text-center text-muted small py-3 border-top" style="background: #fff;">
    <i class="bi bi-cup-hot"></i> &copy; 2026 POS
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.getElementById('appSidebar');
    var toggleBtn = document.getElementById('sidebarToggle');

    // Logout confirmation as an in-page modal instead of navigating
    // straight to logout.php. logout.php itself now destroys the
    // session immediately with no confirmation step of its own — all
    // confirmation happens here, before we ever visit that URL.
    var logoutLink = document.getElementById('logoutLink');
    if (logoutLink && typeof Swal !== 'undefined') {
        logoutLink.addEventListener('click', function (event) {
            event.preventDefault();
            var targetHref = logoutLink.getAttribute('href');
            Swal.fire({
                title: 'Logout',
                html: '<div class="swal-logout-icon"><i class="bi bi-box-arrow-right"></i></div>' +
                      '<p class="swal-logout-text">Are you sure you want to logout?</p>',
                showCancelButton: true,
                showCloseButton: true,
                confirmButtonText: 'Yes, Logout',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#64748b',
                customClass: {
                    popup: 'swal-theme-popup',
                    title: 'swal-theme-title',
                    closeButton: 'swal-theme-close',
                    confirmButton: 'swal-theme-confirm',
                    cancelButton: 'swal-theme-cancel'
                }
            }).then(function (result) {
                if (result.isConfirmed) {
                    window.location.href = targetHref;
                }
            });
        });
    }
    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function () {
            if (window.innerWidth <= 900) {
                sidebar.classList.toggle('show');
            } else {
                sidebar.classList.toggle('collapsed');
            }
        });
    }

    // Defensive fallback: if Bootstrap's JS bundle itself failed to load
    // (not just the CSS), data-bs-toggle="dropdown" won't do anything.
    // This lightweight handler only kicks in when window.bootstrap is
    // unavailable, so it never double-fires alongside the real thing.
    if (typeof window.bootstrap === 'undefined') {
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (toggle) {
            toggle.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                var menu = toggle.parentElement.querySelector('.dropdown-menu');
                if (!menu) { return; }
                var isOpen = menu.classList.contains('show');
                document.querySelectorAll('.dropdown-menu.show').forEach(function (m) { m.classList.remove('show'); });
                if (!isOpen) {
                    menu.classList.add('show');
                    toggle.setAttribute('aria-expanded', 'true');
                }
            });
        });
        document.addEventListener('click', function () {
            document.querySelectorAll('.dropdown-menu.show').forEach(function (m) { m.classList.remove('show'); });
        });
    }
});
</script>
</body>
</html>
HTML;
}

if (isset($conn)) {
    initialize_low_stock_notifications_table();
}