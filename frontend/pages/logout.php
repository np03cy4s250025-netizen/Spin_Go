<?php
// frontend/pages/logout.php

require_once '../../backend/config/session.php';

// ── Security: only allow POST with a valid CSRF token ──────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed. Use the logout button.');
}

csrf_verify();

// 1. Wipe every session variable instantly
$_SESSION = [];

// 2. Delete the session cookie from the browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// 3. Destroy the server-side session data
session_destroy();

// 4. Prevent the browser from caching this response
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// 5. Redirect to login
header('Location: login.php?logout=1');
exit;

