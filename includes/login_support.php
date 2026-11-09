<?php
const LOGIN_MAX_FAILURES = 5;
const LOGIN_COOLDOWN_MINUTES = 15;

function ensure_login_cooldown_schema($conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS login_cooldowns (
        user_id INT NOT NULL PRIMARY KEY,
        failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
        locked_until DATETIME DEFAULT NULL,
        CONSTRAINT fk_login_cooldowns_user FOREIGN KEY (user_id)
            REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function authenticate_admin_login($conn, string $username, string $password): array
{
    ensure_login_cooldown_schema($conn);
    $conn->begin_transaction();
    try {
        $stmt = safe_query($conn, 'SELECT user_id, username, password, role FROM users WHERE username = ? FOR UPDATE', 's', [$username]);
        if ($stmt === false) throw new RuntimeException('Login account lookup failed.');
        $result = mysqli_stmt_get_result($stmt);
        if ($result === false) throw new RuntimeException('Login account result could not be read.');
        $user = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        if (!$user || !in_array($user['role'], ['admin', 'staff'], true)) {
            $conn->commit();
            return ['status' => 'invalid'];
        }

        $id = (int)$user['user_id'];
        $stmt = safe_query($conn, 'INSERT INTO login_cooldowns (user_id) VALUES (?) ON DUPLICATE KEY UPDATE user_id = user_id', 'i', [$id]);
        if ($stmt === false) throw new RuntimeException('Login cooldown could not be prepared.');
        mysqli_stmt_close($stmt);
        $stmt = safe_query($conn, 'SELECT failures, locked_until IS NOT NULL AND locked_until > NOW() AS locked, locked_until IS NOT NULL AND locked_until <= NOW() AS expired FROM login_cooldowns WHERE user_id = ?', 'i', [$id]);
        if ($stmt === false) throw new RuntimeException('Login cooldown could not be read.');
        $result = mysqli_stmt_get_result($stmt);
        if ($result === false || !($state = mysqli_fetch_assoc($result))) throw new RuntimeException('Login cooldown state is unavailable.');
        mysqli_stmt_close($stmt);
        if ((int)$state['locked'] === 1) {
            $conn->commit();
            return ['status' => 'locked'];
        }
        $failures = (int)$state['expired'] === 1 ? 0 : (int)$state['failures'];
        $hash = (string)$user['password'];
        $legacy = (password_get_info($hash)['algo'] === null);
        $valid = $hash !== '' && (password_verify($password, $hash) || ($legacy && hash_equals($hash, $password)));
        if (!$valid) {
            $failures++;
            $locked = $failures >= LOGIN_MAX_FAILURES;
            $query = $locked
                ? 'UPDATE login_cooldowns SET failures = ?, locked_until = DATE_ADD(NOW(), INTERVAL ' . LOGIN_COOLDOWN_MINUTES . ' MINUTE) WHERE user_id = ?'
                : 'UPDATE login_cooldowns SET failures = ?, locked_until = NULL WHERE user_id = ?';
            $stmt = safe_query($conn, $query, 'ii', [$failures, $id]);
            if ($stmt === false) throw new RuntimeException('Login failure could not be recorded.');
            mysqli_stmt_close($stmt);
            $conn->commit();
            return ['status' => $locked ? 'locked' : 'invalid'];
        }

        if ($legacy) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($hash === false) throw new RuntimeException('Password hashing failed.');
            $stmt = safe_query($conn, 'UPDATE users SET password = ? WHERE user_id = ?', 'si', [$hash, $id]);
            if ($stmt === false) throw new RuntimeException('Legacy password could not be secured.');
            mysqli_stmt_close($stmt);
            $user['password'] = $hash;
        }
        $stmt = safe_query($conn, 'DELETE FROM login_cooldowns WHERE user_id = ?', 'i', [$id]);
        if ($stmt === false) throw new RuntimeException('Login cooldown could not be cleared.');
        mysqli_stmt_close($stmt);
        $conn->commit();
        return ['status' => 'success', 'user' => $user];
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
