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

    global $conn;
    $stmt = safe_query($conn, 'SELECT role, password FROM users WHERE username = ? LIMIT 1', 's', [$_SESSION['admin']]);
    if ($stmt === false) {
        http_response_code(503);
        exit('Administrator access could not be verified. Try again shortly.');
    }

    $result = mysqli_stmt_get_result($stmt);
    $user = $result === false ? null : mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    if (!$user || !in_array($user['role'], ['admin', 'super_admin'], true)) {
        unset($_SESSION['admin'], $_SESSION['admin_role'], $_SESSION['admin_password_hash']);
        header('Location: login');
        exit;
    }

    if (empty($_SESSION['admin_password_hash'])
        || !hash_equals((string)$user['password'], (string)$_SESSION['admin_password_hash'])
    ) {
        unset($_SESSION['admin'], $_SESSION['admin_role'], $_SESSION['admin_password_hash']);
        header('Location: login');
        exit;
    }

    $_SESSION['admin_role'] = $user['role'];
    verify_csrf_request();
}

function ensure_super_admin()
{
    ensure_admin();
    if (($_SESSION['admin_role'] ?? '') !== 'super_admin') {
        http_response_code(403);
        exit('Super Admin access is required to manage administrator accounts.');
    }
}

function admin_status_badge_class($status): string
{
    $status = strtolower(trim((string)$status));
    if (in_array($status, ['pending', 'queued', 'borrowed'], true)) {
        return 'admin-status-pending';
    }
    if ($status === 'for release') {
        return 'admin-status-in-progress';
    }
    if ($status === 'received') {
        return 'admin-status-received';
    }
    if (in_array($status, ['released', 'returned', 'delivered', 'sent', 'success'], true)) {
        return 'admin-status-complete';
    }
    if (in_array($status, ['failed', 'not returned', 'overdue'], true)) {
        return 'admin-status-error';
    }
    return 'admin-status-neutral';
}

function admin_password_meets_minimum_length($password): bool
{
    return is_string($password) && preg_match_all('/./us', $password) >= ADMIN_PASSWORD_MIN_LENGTH;
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
