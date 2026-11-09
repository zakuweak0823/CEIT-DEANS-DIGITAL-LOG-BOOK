<?php
require_once __DIR__ . '/audit_log.php';

function ensure_account_schema($conn): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $conn->query("CREATE TABLE IF NOT EXISTS app_schema_migrations (
        version VARCHAR(100) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("ALTER TABLE users
        ADD COLUMN IF NOT EXISTS first_name VARCHAR(100) NOT NULL DEFAULT '',
        ADD COLUMN IF NOT EXISTS middle_name VARCHAR(100) NOT NULL DEFAULT '',
        ADD COLUMN IF NOT EXISTS surname VARCHAR(100) NOT NULL DEFAULT '',
        ADD COLUMN IF NOT EXISTS email VARCHAR(254) DEFAULT NULL");
    $emailIndex = $conn->query("SHOW INDEX FROM users WHERE Key_name = 'uq_users_email'");
    if (!$emailIndex || $emailIndex->num_rows === 0) {
        $conn->query('ALTER TABLE users ADD UNIQUE INDEX uq_users_email (email)');
    }
    $conn->query("CREATE TABLE IF NOT EXISTS password_reset_links (
        token_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        purpose VARCHAR(20) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_password_reset_links_user (user_id, purpose, used_at, expires_at),
        CONSTRAINT fk_password_reset_links_user FOREIGN KEY (user_id)
            REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS audit_logs (
        audit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        actor VARCHAR(100) DEFAULT NULL,
        action VARCHAR(80) NOT NULL,
        target VARCHAR(255) DEFAULT NULL,
        details TEXT NOT NULL,
        ip_address VARCHAR(45) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_logs_created_at (created_at),
        INDEX idx_audit_logs_actor (actor, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $lock = $conn->query("SELECT GET_LOCK('ceit_logbook_role_migration_v1', 10)");
    $lockRow = $lock ? $lock->fetch_row() : null;
    if (!$lockRow || (int)$lockRow[0] !== 1) {
        throw new RuntimeException('Could not acquire the account role migration lock.');
    }

    try {
        $result = $conn->query("SELECT version FROM app_schema_migrations WHERE version = 'account-roles-v1'");
        if ($result->num_rows === 0) {
            $conn->begin_transaction();
            try {
                $conn->query("UPDATE users
                    SET role = CASE role
                        WHEN 'super_admin' THEN 'admin'
                        WHEN 'admin' THEN 'staff'
                        ELSE role
                    END
                    WHERE role IN ('admin', 'super_admin')");
                $conn->query("INSERT INTO app_schema_migrations (version) VALUES ('account-roles-v1')");
                $conn->commit();
            } catch (Throwable $error) {
                $conn->rollback();
                throw $error;
            }
        }
    } finally {
        $conn->query("SELECT RELEASE_LOCK('ceit_logbook_role_migration_v1')");
    }

    $ready = true;
}

function account_ascii_name(string $value): string
{
    $converted = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : $value;
    return strtolower((string)preg_replace('/[^A-Za-z0-9]/', '', $converted === false ? $value : $converted));
}

function account_name_initials(string $value): string
{
    $words = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY);
    $initials = '';
    foreach ($words ?: [] as $word) {
        $normalized = account_ascii_name($word);
        if ($normalized !== '') {
            $initials .= substr($normalized, 0, 1);
        }
    }
    return $initials;
}

function account_username_base(string $firstName, string $middleName, string $surname): string
{
    $first = account_name_initials($firstName);
    $middle = account_ascii_name($middleName);
    $last = account_ascii_name($surname);
    if ($first === '' || $last === '') {
        return '';
    }
    $base = $first . ($middle === '' ? '' : substr($middle, 0, 1)) . $last;
    return strlen($base) <= 96 ? $base : '';
}

function account_unique_username($conn, string $base): string
{
    $candidate = $base;
    $suffix = 0;
    while ((int)safe_scalar($conn, 'SELECT COUNT(*) FROM users WHERE username = ?', 's', [$candidate]) > 0) {
        $suffix++;
        $candidate = $base . sprintf('%02d', $suffix);
        if (strlen($candidate) > 100) {
            throw new RuntimeException('No username within the supported length is available for this name.');
        }
    }
    return $candidate;
}

function account_generate_password(): string
{
    return bin2hex(random_bytes(12));
}

function password_reset_link_issue($conn, int $userId, string $purpose): string
{
    if (!in_array($purpose, ['setup', 'recovery'], true)) {
        throw new InvalidArgumentException('Invalid password link purpose.');
    }
    $expireMinutes = $purpose === 'setup' ? 60 : 30;
    $expireMinutes = (int)$expireMinutes;
    $old = safe_query(
        $conn,
        'UPDATE password_reset_links SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL',
        'is',
        [$userId, $purpose]
    );
    if ($old === false) {
        throw new RuntimeException('An earlier password link could not be invalidated.');
    }
    mysqli_stmt_close($old);

    $token = bin2hex(random_bytes(32));
    $insert = safe_query(
        $conn,
        "INSERT INTO password_reset_links (user_id, token_hash, purpose, expires_at)
         VALUES (?, ?, ?, NOW() + INTERVAL {$expireMinutes} MINUTE)",
        'iss',
        [$userId, hash('sha256', $token), $purpose]
    );
    if ($insert === false) {
        throw new RuntimeException('A password link could not be created.');
    }
    mysqli_stmt_close($insert);
    return $token;
}

function password_reset_link_find($conn, string $token, ?string $purpose = null): ?array
{
    if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
        return null;
    }
    $sql = 'SELECT l.token_id, l.user_id, l.purpose, u.username
            FROM password_reset_links l JOIN users u ON u.user_id = l.user_id
            WHERE l.token_hash = ? AND l.used_at IS NULL AND l.expires_at > NOW()';
    $types = 's';
    $params = [hash('sha256', $token)];
    if ($purpose !== null) {
        $sql .= ' AND l.purpose = ?';
        $types .= 's';
        $params[] = $purpose;
    }
    $sql .= ' LIMIT 1';
    $stmt = safe_query($conn, $sql, $types, $params);
    if ($stmt === false) {
        return null;
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function password_reset_invalidate_user_requests($conn, int $userId): void
{
    foreach ([
        'UPDATE password_reset_links SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
        'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
    ] as $query) {
        $stmt = safe_query($conn, $query, 'i', [$userId]);
        if ($stmt === false) {
            throw new RuntimeException('Outstanding password recovery requests could not be revoked.');
        }
        mysqli_stmt_close($stmt);
    }
}

function password_reset_link_complete($conn, array $link, string $password): bool
{
    try {
        $conn->begin_transaction();
        $use = safe_query(
            $conn,
            'UPDATE password_reset_links SET used_at = NOW() WHERE token_id = ? AND used_at IS NULL AND expires_at > NOW()',
            'i',
            [(int)$link['token_id']]
        );
        if ($use === false || mysqli_stmt_affected_rows($use) !== 1) {
            if ($use !== false) {
                mysqli_stmt_close($use);
            }
            throw new RuntimeException('The password link is expired or already used.');
        }
        mysqli_stmt_close($use);

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $update = safe_query($conn, 'UPDATE users SET password = ? WHERE user_id = ?', 'si', [$hash, (int)$link['user_id']]);
        if ($update === false) {
            throw new RuntimeException('The password could not be updated.');
        }
        mysqli_stmt_close($update);
        $tokens = safe_query($conn, 'DELETE FROM mobile_api_tokens WHERE user_id = ?', 'i', [(int)$link['user_id']]);
        if ($tokens === false) {
            throw new RuntimeException('Mobile sessions could not be revoked.');
        }
        mysqli_stmt_close($tokens);
        password_reset_invalidate_user_requests($conn, (int)$link['user_id']);
        $conn->commit();
        record_audit_event($conn, 'password_link_completed', (string)$link['username'], (string)$link['purpose']);
        return true;
    } catch (Throwable $error) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log('Password-link transaction rollback failed: ' . $rollbackError->getMessage());
        }
        error_log('Password link could not update the account password: ' . $error->getMessage());
        return false;
    }
}
