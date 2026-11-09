<?php
function audit_account_username($conn, int $userId): string
{
    $stmt = safe_query($conn, 'SELECT username FROM users WHERE user_id = ? LIMIT 1', 'i', [$userId]);
    if ($stmt === false) {
        error_log('Audit account name could not be resolved for user ID ' . $userId . '.');
        return 'Account #' . $userId;
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ? (string)$row['username'] : 'Account #' . $userId;
}

function audit_action_label(string $action, string $details = ''): string
{
    if ($action === 'password_link_sent') {
        return $details === 'setup' ? 'Sent account setup link' : 'Sent password recovery link';
    }
    if ($action === 'password_link_completed') {
        return $details === 'setup' ? 'Set password from account setup link' : 'Reset password using email link';
    }

    $labels = [
        'login_success' => 'Signed in successfully',
        'logout' => 'Signed out',
        'staff_account_created' => 'Created Staff account',
        'staff_password_reset_by_admin' => 'Reset Staff password',
        'admin_password_changed' => 'Changed own password',
        'staff_email_changed' => 'Updated Staff recovery email',
        'admin_email_changed' => 'Updated own recovery email',
        'staff_recovery_phone_changed' => 'Updated Staff SMS recovery phone',
        'admin_recovery_phone_changed' => 'Updated own SMS recovery phone',
        'staff_account_removed' => 'Removed Staff account',
        'password_reset_code_sent' => 'Sent SMS password recovery code',
        'password_reset_completed' => 'Reset password using SMS recovery code',
        'password_reset_by_cli' => 'Reset password using server maintenance tool',
    ];
    if (isset($labels[$action])) {
        return $labels[$action];
    }
    return ucfirst(str_replace(['_', '-'], ' ', $action));
}

function audit_details_label(string $action, string $details): string
{
    switch ($action) {
        case 'login_success':
            return in_array($details, ['admin', 'staff'], true)
                ? 'Account role: ' . ucfirst($details) . '.'
                : 'Web sign-in completed.';
        case 'logout':
            return 'Web session ended.';
        case 'staff_account_created':
            $emailProvided = stripos($details, 'email provided: yes') !== false;
            return 'Role: Staff. Recovery email: ' . ($emailProvided ? 'configured.' : 'not configured.');
        case 'staff_password_reset_by_admin':
        case 'admin_password_changed':
        case 'password_reset_by_cli':
        case 'password_reset_completed':
        case 'password_link_completed':
            return 'Password updated; other web sessions, mobile sessions, and unused recovery links were revoked.';
        case 'staff_email_changed':
        case 'admin_email_changed':
            return strcasecmp($details, 'Removed') === 0
                ? 'Recovery email address removed.'
                : 'Recovery email address added or updated.';
        case 'staff_recovery_phone_changed':
        case 'admin_recovery_phone_changed':
            return strcasecmp($details, 'Removed') === 0
                ? 'SMS recovery phone removed.'
                : 'SMS recovery phone added or updated.';
        case 'staff_account_removed':
            return 'Staff account removed from the system.';
        case 'password_link_sent':
            return $details === 'setup'
                ? 'A one-time account setup link was delivered by email.'
                : 'A one-time password recovery link was delivered by email.';
        case 'password_reset_code_sent':
            return 'A one-time password recovery code was sent by SMS.';
        default:
            return trim($details) !== '' ? $details : '—';
    }
}

function audit_actor_label(?string $actor, string $action): string
{
    if ($actor !== null && trim($actor) !== '') {
        return $actor;
    }
    if (in_array($action, [
        'password_link_sent',
        'password_link_completed',
        'password_reset_code_sent',
        'password_reset_completed',
    ], true)) {
        return 'Self-service recovery';
    }
    return $action === 'password_reset_by_cli' ? 'Server administrator' : 'System';
}

function audit_ip_label(string $ipAddress): string
{
    return $ipAddress !== '' ? $ipAddress : '—';
}

function audit_status_event_label(string $kind): string
{
    $labels = [
        'status' => 'Status updated',
        'scan' => 'Receipt confirmed',
    ];
    return $labels[$kind] ?? ucfirst(str_replace(['_', '-'], ' ', $kind));
}

function record_audit_event($conn, string $action, ?string $target = null, string $details = ''): bool
{
    $actor = isset($_SESSION['admin']) && is_string($_SESSION['admin'])
        ? $_SESSION['admin']
        : null;
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $stmt = safe_query(
        $conn,
        'INSERT INTO audit_logs (actor, action, target, details, ip_address) VALUES (?, ?, ?, ?, ?)',
        'sssss',
        [$actor, $action, $target, $details, $ip]
    );
    if ($stmt === false) {
        error_log('Audit event could not be recorded: ' . $action);
        return false;
    }
    mysqli_stmt_close($stmt);
    return true;
}
