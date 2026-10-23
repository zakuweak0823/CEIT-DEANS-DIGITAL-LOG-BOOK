<?php
// Last-resort password reset for the server owner: php tools/reset_admin_password.php <username>
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';

$username = trim((string)($argv[1] ?? ''));
if ($username === '') {
    fwrite(STDERR, "Usage: php tools/reset_admin_password.php <username>\n");
    exit(1);
}

$stmt = safe_query($conn, "SELECT user_id, role FROM users WHERE username = ? AND role IN ('admin', 'super_admin') LIMIT 1", 's', [$username]);
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

$update = safe_query($conn, 'UPDATE users SET password = ? WHERE user_id = ?', 'si', [password_hash($password, PASSWORD_DEFAULT), (int)$user['user_id']]);
if ($update === false) {
    fwrite(STDERR, "The password could not be saved. See the PHP error log.\n");
    exit(1);
}
mysqli_stmt_close($update);

// Sign the account out of every mobile device and clear any pending recovery codes.
foreach (['DELETE FROM mobile_api_tokens WHERE user_id = ?', 'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'] as $cleanup) {
    try {
        $statement = safe_query($conn, $cleanup, 'i', [(int)$user['user_id']]);
        if ($statement !== false) {
            mysqli_stmt_close($statement);
        }
    } catch (mysqli_sql_exception $error) {
        // The recovery table only exists after the first use of SMS recovery.
    }
}

fwrite(STDOUT, "Password updated for \"$username\". Existing sessions are signed out.\n");
