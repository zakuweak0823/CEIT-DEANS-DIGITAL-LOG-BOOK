<?php
// Last-resort password reset for the server owner: php tools/reset_admin_password.php <username>
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/password_reset_support.php';

$username = trim((string)($argv[1] ?? ''));
if ($username === '') {
    fwrite(STDERR, "Usage: php tools/reset_admin_password.php <username>\n");
    exit(1);
}
if (!ensure_password_reset_schema($conn)) {
    fwrite(STDERR, "Password recovery storage could not be prepared. Nothing was changed.\n");
    exit(1);
}

$stmt = safe_query($conn, "SELECT user_id, role FROM users WHERE username = ? AND role IN ('admin', 'staff') LIMIT 1", 's', [$username]);
$user = null;
if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    $user = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
}
if (!$user) {
    fwrite(STDERR, "No administrator account named \"$username\" was found.\n");
    exit(1);
}

// Typed input is visible on screen, so run this only on a private console.
fwrite(STDOUT, "New password for \"$username\" (at least " . ADMIN_PASSWORD_MIN_LENGTH . " characters): ");
$password = rtrim((string)fgets(STDIN), "\r\n");
fwrite(STDOUT, 'Repeat the password: ');
$repeat = rtrim((string)fgets(STDIN), "\r\n");

if (preg_match_all('/./us', $password) < ADMIN_PASSWORD_MIN_LENGTH) {
    fwrite(STDERR, 'The password must have at least ' . ADMIN_PASSWORD_MIN_LENGTH . " characters. Nothing was changed.\n");
    exit(1);
}
if ($password !== $repeat) {
    fwrite(STDERR, "The passwords do not match. Nothing was changed.\n");
    exit(1);
}

try {
    $conn->begin_transaction();
    $update = safe_query($conn, 'UPDATE users SET password = ? WHERE user_id = ?', 'si', [password_hash($password, PASSWORD_DEFAULT), (int)$user['user_id']]);
    if ($update === false || mysqli_stmt_affected_rows($update) !== 1) {
        if ($update !== false) {
            mysqli_stmt_close($update);
        }
        throw new RuntimeException('The password could not be saved.');
    }
    mysqli_stmt_close($update);
    $tokens = safe_query($conn, 'DELETE FROM mobile_api_tokens WHERE user_id = ?', 'i', [(int)$user['user_id']]);
    if ($tokens === false) {
        throw new RuntimeException('Mobile sessions could not be revoked.');
    }
    mysqli_stmt_close($tokens);
    password_reset_invalidate_user_requests($conn, (int)$user['user_id']);
    $conn->commit();
    record_audit_event($conn, 'password_reset_by_cli', $username);
} catch (Throwable $error) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        error_log('Command-line password reset rollback failed: ' . $rollbackError->getMessage());
    }
    error_log('Command-line password reset failed: ' . $error->getMessage());
    fwrite(STDERR, "The password could not be saved. See the PHP error log.\n");
    exit(1);
}

fwrite(STDOUT, "Password updated for \"$username\". Existing sessions are signed out.\n");
