<?php
// SMS one-time-code password recovery for administrator accounts.
require_once __DIR__ . '/sms_service.php';
require_once __DIR__ . '/email_service.php';
require_once __DIR__ . '/account_support.php';

const PASSWORD_RESET_CODE_MINUTES = 10;
const PASSWORD_RESET_MAX_ATTEMPTS = 5;
const PASSWORD_RESET_COOLDOWN_SECONDS = 60;
const PASSWORD_RESET_MAX_PER_USER_HOUR = 3;
const PASSWORD_RESET_MAX_PER_IP_HOUR = 10;

function ensure_password_reset_schema($conn): bool
{
    try {
        $conn->query('ALTER TABLE users ADD COLUMN IF NOT EXISTS recovery_phone VARCHAR(20) DEFAULT NULL');
        // Every request is recorded, even for unknown usernames, so throttling cannot reveal which accounts exist.
        $conn->query("CREATE TABLE IF NOT EXISTS password_resets (
            reset_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            username_key CHAR(64) NOT NULL,
            request_ip VARCHAR(45) NOT NULL DEFAULT '',
            code_salt CHAR(32) DEFAULT NULL,
            code_hash CHAR(64) DEFAULT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_resets_user (user_id, used_at, expires_at),
            INDEX idx_password_resets_key (username_key, created_at),
            INDEX idx_password_resets_ip (request_ip, created_at),
            CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id)
                REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (mysqli_sql_exception $error) {
        error_log('Password recovery storage could not be prepared: ' . $error->getMessage());
        return false;
    }
}

function password_reset_sms_available($conn): bool
{
    return sms_delivery_enabled() && get_philsms_api_token($conn) !== '';
}

function password_reset_username_key(string $username): string
{
    return hash('sha256', strtolower(trim($username)));
}

function password_reset_client_ip(): string
{
    // Only the socket address is trusted; forwarded headers can be forged by the client.
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function password_reset_mask_phone(?string $phone): string
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    return strlen($digits) >= 4 ? str_repeat('•', max(0, strlen($digits) - 4)) . substr($digits, -4) : '';
}

// Returns 'ok', 'cooldown' or 'limited'.
function password_reset_throttle_state($conn, string $username): string
{
    $key = password_reset_username_key($username);
    $ip = password_reset_client_ip();
    $recent = (int)safe_scalar($conn, 'SELECT COUNT(*) FROM password_resets WHERE username_key = ? AND created_at > (NOW() - INTERVAL ' . PASSWORD_RESET_COOLDOWN_SECONDS . ' SECOND)', 's', [$key]);
    $perUser = (int)safe_scalar($conn, 'SELECT COUNT(*) FROM password_resets WHERE username_key = ? AND created_at > (NOW() - INTERVAL 1 HOUR)', 's', [$key]);
    $perIp = (int)safe_scalar($conn, 'SELECT COUNT(*) FROM password_resets WHERE request_ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)', 's', [$ip]);
    if ($perUser >= PASSWORD_RESET_MAX_PER_USER_HOUR || $perIp >= PASSWORD_RESET_MAX_PER_IP_HOUR) {
        return 'limited';
    }
    return $recent > 0 ? 'cooldown' : 'ok';
}

// Records the request and, for an eligible account, creates a code. Returns ['code', 'phone'] or null.
function password_reset_issue($conn, string $username): ?array
{
    $key = password_reset_username_key($username);
    $ip = password_reset_client_ip();
    // Expiry is computed by the database so it always matches the NOW() used when the code is checked.
    $expiryMinutes = (int)PASSWORD_RESET_CODE_MINUTES;

    $stmt = safe_query($conn, 'SELECT user_id, recovery_phone FROM users WHERE username = ? AND role IN (\'admin\', \'staff\') LIMIT 1', 's', [$username]);
    $user = null;
    if ($stmt !== false) {
        $result = mysqli_stmt_get_result($stmt);
        $user = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
    }
    $eligible = $user && is_valid_contact_number((string)$user['recovery_phone']);

    if (!$eligible) {
        $log = safe_query($conn, "INSERT INTO password_resets (user_id, username_key, request_ip, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL $expiryMinutes MINUTE)", 'iss', [$user ? (int)$user['user_id'] : null, $key, $ip]);
        if ($log !== false) {
            mysqli_stmt_close($log);
        }
        return null;
    }

    // Only the newest code stays valid.
    $old = safe_query($conn, 'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', 'i', [(int)$user['user_id']]);
    if ($old !== false) {
        mysqli_stmt_close($old);
    }
    $code = (string)random_int(100000, 999999);
    $salt = bin2hex(random_bytes(16));
    $insert = safe_query(
        $conn,
        "INSERT INTO password_resets (user_id, username_key, request_ip, code_salt, code_hash, expires_at) VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL $expiryMinutes MINUTE)",
        'issss',
        [(int)$user['user_id'], $key, $ip, $salt, hash('sha256', $salt . $code)]
    );
    if ($insert === false) {
        return null;
    }
    mysqli_stmt_close($insert);
    return ['code' => $code, 'phone' => (string)$user['recovery_phone']];
}

function password_reset_email_issue($conn, string $username): ?array
{
    $stmt = safe_query(
        $conn,
        "SELECT user_id, username, email FROM users WHERE username = ? AND role IN ('admin', 'staff') LIMIT 1",
        's',
        [$username]
    );
    if ($stmt === false) {
        return null;
    }
    $result = mysqli_stmt_get_result($stmt);
    $account = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if (!$account || !filter_var((string)$account['email'], FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $requestLog = safe_query(
        $conn,
        'INSERT INTO password_resets (user_id, username_key, request_ip, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL 30 MINUTE)',
        'iss',
        [(int)$account['user_id'], password_reset_username_key($username), password_reset_client_ip()]
    );
    if ($requestLog === false) {
        error_log('Email password recovery request could not be recorded.');
        return null;
    }
    mysqli_stmt_close($requestLog);
    return [
        'token' => password_reset_link_issue($conn, (int)$account['user_id'], 'recovery'),
        'email' => (string)$account['email'],
        'username' => (string)$account['username'],
    ];
}

function password_reset_email_send($conn, array $issue, string $purpose): bool
{
    try {
        $sent = logbook_send_password_link(
            (string)$issue['email'],
            (string)$issue['username'],
            (string)$issue['token'],
            $purpose
        );
    } catch (Throwable $error) {
        error_log('Password recovery email could not be prepared: ' . $error->getMessage());
        $sent = false;
    }
    if (!$sent) {
        error_log('Password recovery email could not be sent.');
    } else {
        record_audit_event($conn, 'password_link_sent', (string)$issue['username'], $purpose);
    }
    return $sent;
}

function password_reset_send_code($conn, string $phone, string $code): bool
{
    $result = send_logged_sms(
        $conn,
        $phone,
        'CEIT Digital Logbook: your password reset code is ' . $code . '. It expires in ' . PASSWORD_RESET_CODE_MINUTES . ' minutes. Never share this code.',
        null,
        '[Security] Password reset code (hidden)'
    );
    if (empty($result['success'])) {
        error_log('Password reset code could not be sent: ' . ($result['message'] ?? 'unknown error'));
    }
    return !empty($result['success']);
}

function password_reset_notify_changed($conn, string $phone): void
{
    send_logged_sms(
        $conn,
        $phone,
        'CEIT Digital Logbook: your password was just changed. If this was not you, contact your system administrator immediately.',
        null,
        '[Security] Password changed notice'
    );
}

// Checks a submitted code. Each check consumes one attempt, so codes cannot be guessed.
function password_reset_verify($conn, string $username, string $code): ?array
{
    $stmt = safe_query(
        $conn,
        'SELECT r.reset_id, r.user_id, r.code_salt, r.code_hash, u.recovery_phone, u.username
         FROM password_resets r JOIN users u ON u.user_id = r.user_id
         WHERE u.username = ? AND r.code_hash IS NOT NULL AND r.used_at IS NULL
           AND r.expires_at > NOW() AND r.attempts < ' . PASSWORD_RESET_MAX_ATTEMPTS . '
         ORDER BY r.reset_id DESC LIMIT 1',
        's',
        [$username]
    );
    $row = null;
    if ($stmt !== false) {
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
    }
    if (!$row) {
        // Spend comparable time so an unknown account looks like a wrong code.
        hash_equals(hash('sha256', 'none' . $code), hash('sha256', 'code'));
        return null;
    }

    $claim = safe_query(
        $conn,
        'UPDATE password_resets SET attempts = attempts + 1 WHERE reset_id = ? AND used_at IS NULL AND attempts < ' . PASSWORD_RESET_MAX_ATTEMPTS,
        'i',
        [(int)$row['reset_id']]
    );
    if ($claim === false) {
        return null;
    }
    $claimed = mysqli_stmt_affected_rows($claim) === 1;
    mysqli_stmt_close($claim);
    if (!$claimed || !hash_equals((string)$row['code_hash'], hash('sha256', $row['code_salt'] . $code))) {
        return null;
    }
    return $row;
}

function password_reset_complete($conn, array $reset, string $newPassword): bool
{
    try {
        $conn->begin_transaction();
        $use = safe_query($conn, 'UPDATE password_resets SET used_at = NOW() WHERE reset_id = ? AND used_at IS NULL', 'i', [(int)$reset['reset_id']]);
        if ($use === false) {
            throw new RuntimeException('The reset code could not be consumed.');
        }
        $consumed = mysqli_stmt_affected_rows($use) === 1;
        mysqli_stmt_close($use);
        if (!$consumed) {
            throw new RuntimeException('The reset code was already used.');
        }

        $update = safe_query($conn, 'UPDATE users SET password = ? WHERE user_id = ?', 'si', [password_hash($newPassword, PASSWORD_DEFAULT), (int)$reset['user_id']]);
        if ($update === false) {
            throw new RuntimeException('The password could not be saved.');
        }
        mysqli_stmt_close($update);

        // Changing the hash already ends browser sessions; this also signs the mobile app out.
        $tokens = safe_query($conn, 'DELETE FROM mobile_api_tokens WHERE user_id = ?', 'i', [(int)$reset['user_id']]);
        if ($tokens === false) {
            throw new RuntimeException('Mobile sessions could not be revoked.');
        }
        mysqli_stmt_close($tokens);
        password_reset_invalidate_user_requests($conn, (int)$reset['user_id']);
        $conn->commit();
        record_audit_event($conn, 'password_reset_completed', (string)$reset['username'], 'SMS verification');
        return true;
    } catch (Throwable $error) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log('Password recovery rollback failed: ' . $rollbackError->getMessage());
        }
        error_log('Password recovery failed: ' . $error->getMessage());
        return false;
    }
}

function password_reset_purge($conn): void
{
    $stmt = safe_query($conn, 'DELETE FROM password_resets WHERE created_at < (NOW() - INTERVAL 2 DAY)');
    if ($stmt !== false) {
        mysqli_stmt_close($stmt);
    }
}

// Sends the redirect immediately so slower work (SMS delivery) cannot be timed to tell accounts apart.
function password_reset_finish_response(string $location): void
{
    ignore_user_abort(true);
    set_time_limit(30);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Location: ' . $location);
    header('Connection: close');
    header('Content-Length: 0');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}
