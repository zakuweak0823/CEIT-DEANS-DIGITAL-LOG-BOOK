<?php
if (session_status() === PHP_SESSION_NONE) {
    // Apply safer cookie settings before opening the session used by admin actions.
    $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isSecure,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function verify_csrf_request()
{
    // Read-only navigation does not need a token; every form mutation must provide one.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $expectedToken = $_SESSION['csrf_token'] ?? '';
        $submittedToken = $_POST['csrf_token'] ?? '';
        if ($expectedToken === '' || !is_string($submittedToken) || !hash_equals($expectedToken, $submittedToken)) {
            http_response_code(403);
            exit('Invalid request token. Refresh the page and try again.');
        }
    }
}

function ensure_admin()
{
    if (empty($_SESSION['admin'])) {
        header('Location: login');
        exit;
    }
    verify_csrf_request();
}
function csrf_token()
{
    // Reuse one unpredictable token for this session's rendered forms.
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function sanitize($value)
{
    // Escape at the HTML output boundary to prevent stored text becoming markup.
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function show_alert($message, $type = 'success')
{
    $type = $type === 'danger' ? 'danger' : 'success';
    return '<div class="alert alert-' . $type . '" role="alert">' . sanitize($message) . '</div>';
}
