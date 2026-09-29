<?php
// Confirmation now happens client-side (see the SweetAlert2 modal wired
// up in app.php's page_footer()) before the user ever lands here, so
// this page no longer shows its own "are you sure?" step — it just
// ends the session and sends them to the login screen.
session_start();
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

session_destroy();
header('Location: login_page.php');
exit;