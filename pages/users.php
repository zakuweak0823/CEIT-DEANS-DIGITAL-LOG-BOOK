<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/password_reset_support.php';
require_once __DIR__ . '/../includes/email_service.php';
ensure_admin_role();
ensure_password_reset_schema($conn);
header('Cache-Control: no-store');

$notice = '';
$noticeType = 'success';
$createdAccountDetails = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create_admin') {
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $middleName = trim((string)($_POST['middle_name'] ?? ''));
        $surname = trim((string)($_POST['surname'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $recoveryPhone = contact_number_input($_POST['recovery_phone'] ?? '');

        if ($firstName === '' || $surname === ''
            || strlen($firstName) > 100 || strlen($middleName) > 100 || strlen($surname) > 100
            || preg_match('/\A[\p{L}][\p{L}\p{M} .\'-]*\z/u', $firstName) !== 1
            || ($middleName !== '' && preg_match('/\A[\p{L}][\p{L}\p{M} .\'-]*\z/u', $middleName) !== 1)
            || preg_match('/\A[\p{L}][\p{L}\p{M} .\'-]*\z/u', $surname) !== 1
        ) {
            $notice = 'Enter a valid first name and surname; middle name is optional.';
            $noticeType = 'danger';
        } elseif (account_username_base($firstName, $middleName, $surname) === '') {
            $notice = 'The name must contain letters that can be used to generate a username.';
            $noticeType = 'danger';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $notice = 'Enter a valid email address or leave it empty.';
            $noticeType = 'danger';
        } elseif ($recoveryPhone !== '' && !is_valid_contact_number($recoveryPhone)) {
            $notice = 'Enter the recovery phone as exactly 11 digits, or leave it empty.';
            $noticeType = 'danger';
        } else {
            try {
                if ($email !== '' && (int)safe_scalar($conn, 'SELECT COUNT(*) FROM users WHERE email = ?', 's', [$email]) > 0) {
                    $notice = 'That email address is already associated with an account.';
                    $noticeType = 'danger';
                    throw new DomainException($notice);
                }

                $username = account_unique_username($conn, account_username_base($firstName, $middleName, $surname));
                $password = account_generate_password();
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = safe_query(
                    $conn,
                    "INSERT INTO users (username, password, role, first_name, middle_name, surname, email, recovery_phone)
                     VALUES (?, ?, 'staff', ?, ?, ?, ?, ?)",
                    'sssssss',
                    [$username, $passwordHash, $firstName, $middleName, $surname, $email !== '' ? $email : null, $recoveryPhone !== '' ? $recoveryPhone : null]
                );
                if ($stmt === false) {
                    $notice = 'The staff account could not be created. Check the server log and try again.';
                    $noticeType = 'danger';
                } else {
                    $userId = (int)mysqli_stmt_insert_id($stmt);
                    mysqli_stmt_close($stmt);
                    record_audit_event($conn, 'staff_account_created', $username, 'Email provided: ' . ($email !== '' ? 'yes' : 'no'));
                    $emailSent = false;
                    if ($email !== '' && logbook_email_available()) {
                        try {
                            $setupToken = password_reset_link_issue($conn, $userId, 'setup');
                            $emailSent = password_reset_email_send($conn, [
                                'token' => $setupToken,
                                'email' => $email,
                                'username' => $username,
                            ], 'setup');
                        } catch (Throwable $error) {
                            error_log('Initial staff password setup link could not be issued: ' . $error->getMessage());
                        }
                        if (!$emailSent) {
                            $invalidate = safe_query($conn, 'UPDATE password_reset_links SET used_at = NOW() WHERE user_id = ? AND purpose = \'setup\' AND used_at IS NULL', 'i', [$userId]);
                            if ($invalidate !== false) {
                                mysqli_stmt_close($invalidate);
                            }
                        }
                    }
                    $createdAccountDetails = [
                        'username' => $username,
                        'password' => $password,
                        'email' => $email,
                        'email_sent' => $emailSent,
                    ];
                    $notice = $emailSent
                        ? 'Staff account created. A password setup link was emailed; give the generated temporary password to the staff member securely.'
                        : 'Staff account created. Email setup was unavailable or failed; give the generated password to the staff member securely.';
                    if ($email !== '' && !$emailSent) {
                        $noticeType = 'warning';
                    }
                }
            } catch (mysqli_sql_exception $error) {
                if ((int)$error->getCode() === 1062) {
                    $notice = 'That username or email address is already in use. Try creating the account again.';
                    $noticeType = 'danger';
                } else {
                    throw $error;
                }
            } catch (DomainException $error) {
                // The duplicate email message is already shown above.
            }
        }
    } elseif ($action === 'reset_admin_password') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');

        if (!$userId || $userId < 1) {
            $notice = 'Select a valid staff account.';
            $noticeType = 'danger';
        } elseif (!admin_password_meets_minimum_length($password)) {
            $notice = 'Use a password with at least ' . ADMIN_PASSWORD_MIN_LENGTH . ' characters.';
            $noticeType = 'danger';
        } elseif ($password !== $passwordConfirmation) {
            $notice = 'The password fields do not match.';
            $noticeType = 'danger';
        } else {
            $lookup = safe_query($conn, "SELECT username FROM users WHERE user_id = ? AND role = 'staff' LIMIT 1", 'i', [$userId]);
            $lookupResult = $lookup === false ? false : mysqli_stmt_get_result($lookup);
            $target = $lookupResult ? mysqli_fetch_assoc($lookupResult) : null;
            if ($lookup !== false) {
                mysqli_stmt_close($lookup);
            }
            if ($lookup === false || $lookupResult === false) {
                $notice = 'The staff account could not be verified. Check the server log and try again.';
                $noticeType = 'danger';
            } elseif (!$target) {
                $notice = 'The staff account was not found or cannot be reset.';
                $noticeType = 'warning';
            } else {
                try {
                    $conn->begin_transaction();
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $update = safe_query($conn, "UPDATE users SET password = ? WHERE user_id = ? AND role = 'staff'", 'si', [$passwordHash, $userId]);
                    if ($update === false) {
                        throw new RuntimeException('The staff password update could not be prepared.');
                    }
                    if (mysqli_stmt_affected_rows($update) !== 1) {
                        mysqli_stmt_close($update);
                        throw new RuntimeException('The staff account changed before the password could be reset.');
                    }
                    mysqli_stmt_close($update);
                    $tokens = safe_query($conn, 'DELETE FROM mobile_api_tokens WHERE user_id = ?', 'i', [$userId]);
                    if ($tokens === false) {
                        throw new RuntimeException('Mobile sessions could not be revoked.');
                    }
                    mysqli_stmt_close($tokens);
                    password_reset_invalidate_user_requests($conn, $userId);
                    $conn->commit();
                    record_audit_event($conn, 'staff_password_reset_by_admin', (string)$target['username']);
                    $notice = 'Staff password reset. Share the new password through a secure channel.';
                } catch (Throwable $error) {
                    try {
                        $conn->rollback();
                    } catch (Throwable $rollbackError) {
                        error_log('Staff password reset rollback failed: ' . $rollbackError->getMessage());
                    }
                    error_log('Staff password reset failed: ' . $error->getMessage());
                    $notice = 'The password could not be reset. Check the server log and try again.';
                    $noticeType = 'danger';
                }
            }
        }
    } elseif ($action === 'change_own_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');
        $stmt = safe_query($conn, "SELECT user_id, password FROM users WHERE username = ? AND role = 'admin' LIMIT 1", 's', [$_SESSION['admin']]);
        if ($stmt === false) {
            $notice = 'Your account could not be verified. Check the server log and try again.';
            $noticeType = 'danger';
        } else {
            $result = mysqli_stmt_get_result($stmt);
            $account = $result === false ? null : mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
            if (!$account || !password_verify($currentPassword, $account['password'])) {
                $notice = 'The current password is incorrect.';
                $noticeType = 'danger';
            } elseif (!admin_password_meets_minimum_length($password)) {
                $notice = 'Use a password with at least ' . ADMIN_PASSWORD_MIN_LENGTH . ' characters.';
                $noticeType = 'danger';
            } elseif ($password !== $passwordConfirmation) {
                $notice = 'The password fields do not match.';
                $noticeType = 'danger';
            } else {
                try {
                    $conn->begin_transaction();
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = safe_query($conn, "UPDATE users SET password = ? WHERE username = ? AND role = 'admin'", 'ss', [$passwordHash, $_SESSION['admin']]);
                    if ($stmt === false || mysqli_stmt_affected_rows($stmt) !== 1) {
                        if ($stmt !== false) {
                            mysqli_stmt_close($stmt);
                        }
                        throw new RuntimeException('The Admin password could not be updated.');
                    }
                    mysqli_stmt_close($stmt);
                    $tokens = safe_query($conn, 'DELETE FROM mobile_api_tokens WHERE user_id = ?', 'i', [(int)$account['user_id']]);
                    if ($tokens === false) {
                        throw new RuntimeException('Mobile sessions could not be revoked.');
                    }
                    mysqli_stmt_close($tokens);
                    password_reset_invalidate_user_requests($conn, (int)$account['user_id']);
                    $conn->commit();
                    $_SESSION['admin_password_hash'] = $passwordHash;
                    session_regenerate_id(true);
                    record_audit_event($conn, 'admin_password_changed', (string)$_SESSION['admin']);
                    $notice = 'Your password has been changed.';
                } catch (Throwable $error) {
                    try {
                        $conn->rollback();
                    } catch (Throwable $rollbackError) {
                        error_log('Admin password change rollback failed: ' . $rollbackError->getMessage());
                    }
                    error_log('Admin password change failed: ' . $error->getMessage());
                    $notice = 'Your password could not be changed. Check the server log and try again.';
                    $noticeType = 'danger';
                }
            }
        }
    } elseif ($action === 'set_staff_email') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $email = trim((string)($_POST['email'] ?? ''));
        if (!$userId || $userId < 1) {
            $notice = 'Select a valid staff account.';
            $noticeType = 'danger';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $notice = 'Enter a valid email address or leave it empty.';
            $noticeType = 'danger';
        } elseif ($email !== '' && (int)safe_scalar($conn, 'SELECT COUNT(*) FROM users WHERE email = ? AND user_id <> ?', 'si', [$email, $userId]) > 0) {
            $notice = 'That email address is already associated with another account.';
            $noticeType = 'danger';
        } else {
            $stmt = safe_query($conn, "UPDATE users SET email = ? WHERE user_id = ? AND role = 'staff'", 'si', [$email !== '' ? $email : null, $userId]);
            if ($stmt === false) {
                $notice = 'The staff email could not be saved. Check the server log and try again.';
                $noticeType = 'danger';
            } else {
                $updated = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);
                if ($updated === 1) {
                    record_audit_event($conn, 'staff_email_changed', audit_account_username($conn, $userId), $email !== '' ? 'Set' : 'Removed');
                }
                $notice = 'Staff email settings saved.';
            }
        }
    } elseif ($action === 'set_own_email') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $email = trim((string)($_POST['email'] ?? ''));
        $stmt = safe_query($conn, "SELECT user_id, password FROM users WHERE username = ? AND role = 'admin' LIMIT 1", 's', [$_SESSION['admin']]);
        $account = null;
        if ($stmt !== false) {
            $result = mysqli_stmt_get_result($stmt);
            $account = $result ? mysqli_fetch_assoc($result) : null;
            mysqli_stmt_close($stmt);
        }
        if (!$account || !password_verify($currentPassword, (string)$account['password'])) {
            $notice = 'The current password is incorrect.';
            $noticeType = 'danger';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $notice = 'Enter a valid email address or leave it empty.';
            $noticeType = 'danger';
        } elseif ($email !== '' && (int)safe_scalar($conn, 'SELECT COUNT(*) FROM users WHERE email = ? AND user_id <> ?', 'si', [$email, (int)$account['user_id']]) > 0) {
            $notice = 'That email address is already associated with another account.';
            $noticeType = 'danger';
        } else {
            $stmt = safe_query($conn, 'UPDATE users SET email = ? WHERE user_id = ?', 'si', [$email !== '' ? $email : null, (int)$account['user_id']]);
            if ($stmt === false) {
                $notice = 'Your email could not be saved. Check the server log and try again.';
                $noticeType = 'danger';
            } else {
                mysqli_stmt_close($stmt);
                record_audit_event($conn, 'admin_email_changed', (string)$_SESSION['admin'], $email !== '' ? 'Set' : 'Removed');
                $notice = 'Your email settings were saved.';
            }
        }
    } elseif ($action === 'set_recovery_phone') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $recoveryPhone = contact_number_input($_POST['recovery_phone'] ?? '');
        if (!$userId || $userId < 1) {
            $notice = 'Select a valid staff account.';
            $noticeType = 'danger';
        } elseif ($recoveryPhone !== '' && !is_valid_contact_number($recoveryPhone)) {
            $notice = 'Enter the recovery phone as exactly 11 digits, or leave it empty to turn SMS recovery off.';
            $noticeType = 'danger';
        } else {
            $stmt = safe_query($conn, "UPDATE users SET recovery_phone = ? WHERE user_id = ? AND role = 'staff'", 'si', [$recoveryPhone !== '' ? $recoveryPhone : null, $userId]);
            if ($stmt === false) {
                $notice = 'The recovery phone could not be saved. Check the server log and try again.';
                $noticeType = 'danger';
            } else {
                mysqli_stmt_close($stmt);
                record_audit_event($conn, 'staff_recovery_phone_changed', audit_account_username($conn, $userId), $recoveryPhone !== '' ? 'Set' : 'Removed');
                $notice = $recoveryPhone !== '' ? 'Recovery phone saved. SMS password recovery is on for this account.' : 'Recovery phone removed. SMS password recovery is off for this account.';
            }
        }
    } elseif ($action === 'set_own_recovery_phone') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $recoveryPhone = contact_number_input($_POST['recovery_phone'] ?? '');
        $stmt = safe_query($conn, "SELECT password FROM users WHERE username = ? AND role = 'admin' LIMIT 1", 's', [$_SESSION['admin']]);
        $account = null;
        if ($stmt !== false) {
            $result = mysqli_stmt_get_result($stmt);
            $account = $result === false ? null : mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
        }
        if (!$account || !password_verify($currentPassword, $account['password'])) {
            $notice = 'The current password is incorrect.';
            $noticeType = 'danger';
        } elseif ($recoveryPhone !== '' && !is_valid_contact_number($recoveryPhone)) {
            $notice = 'Enter the recovery phone as exactly 11 digits, or leave it empty to turn SMS recovery off.';
            $noticeType = 'danger';
        } else {
            $stmt = safe_query($conn, "UPDATE users SET recovery_phone = ? WHERE username = ? AND role = 'admin'", 'ss', [$recoveryPhone !== '' ? $recoveryPhone : null, $_SESSION['admin']]);
            if ($stmt === false) {
                $notice = 'Your recovery phone could not be saved. Check the server log and try again.';
                $noticeType = 'danger';
            } else {
                mysqli_stmt_close($stmt);
                record_audit_event($conn, 'admin_recovery_phone_changed', (string)$_SESSION['admin'], $recoveryPhone !== '' ? 'Added or changed' : 'Removed');
                $notice = $recoveryPhone !== '' ? 'Your recovery phone was saved.' : 'Your recovery phone was removed.';
            }
        }
    } elseif ($action === 'delete_admin') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId || $userId < 1) {
            $notice = 'Select a valid administrator account.';
            $noticeType = 'danger';
        } else {
            $stmt = safe_query($conn, "SELECT username FROM users WHERE user_id = ? AND role = 'staff' AND username <> ? LIMIT 1", 'is', [$userId, $_SESSION['admin']]);
            if ($stmt === false) {
                $notice = 'The staff account could not be removed. Check the server log and try again.';
                $noticeType = 'danger';
            } else {
                $result = mysqli_stmt_get_result($stmt);
                $targetUser = $result ? mysqli_fetch_assoc($result) : null;
                mysqli_stmt_close($stmt);
                $deleted = 0;
                if ($targetUser) {
                    $delete = safe_query($conn, "DELETE FROM users WHERE user_id = ? AND role = 'staff' AND username <> ?", 'is', [$userId, $_SESSION['admin']]);
                    if ($delete !== false) {
                        $deleted = mysqli_stmt_affected_rows($delete);
                        mysqli_stmt_close($delete);
                    }
                }
                if ($deleted === 1) {
                    record_audit_event($conn, 'staff_account_removed', (string)$targetUser['username']);
                }
                $notice = $deleted === 1
                    ? 'Staff account removed.'
                    : 'The staff account was not found or cannot be removed.';
                $noticeType = $deleted === 1 ? 'success' : 'warning';
            }
        }
    } else {
        http_response_code(400);
        $notice = 'Choose a valid account action.';
        $noticeType = 'danger';
    }
}

$users = [];
$stmt = safe_query($conn, 'SELECT user_id, username, role, first_name, middle_name, surname, email, recovery_phone FROM users ORDER BY CASE WHEN role = \'admin\' THEN 0 ELSE 1 END, surname, username');
if ($stmt === false) {
    http_response_code(500);
    exit('Account management could not be loaded. Check the server log and try again.');
}
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $users[] = $row;
}
mysqli_stmt_close($stmt);

$accountTotal = count($users);
$adminTotal = 0;
$recoveryOnTotal = 0;
foreach ($users as $accountRow) {
    $adminTotal += $accountRow['role'] === 'admin' ? 1 : 0;
    $recoveryOnTotal += is_valid_contact_number((string)($accountRow['recovery_phone'] ?? '')) ? 1 : 0;
}
$recoveryOffTotal = $accountTotal - $recoveryOnTotal;
$smsRecoveryReady = password_reset_sms_available($conn);
// A failed "Add staff user account" submit reopens the form with typed values kept.
$reopenAdd = $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string)($_POST['action'] ?? '') === 'create_admin'
    && $noticeType === 'danger';
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="row g-4 admin-accounts-page">
    <div class="col-12">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-2">
            <div>
                <h1 class="h2 mb-1 text-primary">Account management</h1>
                <p class="text-muted mb-0">Create staff accounts, reset passwords, and manage account recovery.</p>
            </div>
        </div>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="col-12"><div class="alert alert-<?php echo sanitize($noticeType); ?>" role="status"><?php echo sanitize($notice); ?></div></div>
    <?php endif; ?>
    <?php if ($createdAccountDetails !== null): ?>
        <div class="col-12">
            <section class="alert alert-info mb-0" aria-label="New staff account credentials">
                <h2 class="h6">Give these initial sign-in details to the staff member securely</h2>
                <p class="mb-1">Username: <code><?php echo sanitize($createdAccountDetails['username']); ?></code></p>
                <p class="mb-1">Generated password: <code><?php echo sanitize($createdAccountDetails['password']); ?></code></p>
                <?php if ($createdAccountDetails['email'] !== ''): ?>
                    <p class="mb-0"><?php echo $createdAccountDetails['email_sent'] ? 'A password setup link was sent to ' : 'Email link could not be sent to '; ?><?php echo sanitize($createdAccountDetails['email']); ?>.</p>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>

    <div class="col-12">
        <div class="accounts-summary" role="list">
            <div class="accounts-summary-item" role="listitem">
                <span class="accounts-summary-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
                <span class="accounts-summary-copy"><strong><?php echo number_format($accountTotal); ?></strong><small>Accounts</small></span>
            </div>
            <div class="accounts-summary-item" role="listitem">
                <span class="accounts-summary-icon"><i class="bi bi-person-gear" aria-hidden="true"></i></span>
                <span class="accounts-summary-copy"><strong><?php echo number_format($adminTotal); ?></strong><small>Admin<?php echo $adminTotal === 1 ? '' : 's'; ?></small></span>
            </div>
            <div class="accounts-summary-item is-good" role="listitem">
                <span class="accounts-summary-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
                <span class="accounts-summary-copy"><strong><?php echo number_format($recoveryOnTotal); ?></strong><small>SMS recovery on</small></span>
            </div>
            <div class="accounts-summary-item<?php echo $recoveryOffTotal > 0 ? ' is-warn' : ''; ?>" role="listitem">
                <span class="accounts-summary-icon"><i class="bi bi-shield-exclamation" aria-hidden="true"></i></span>
                <span class="accounts-summary-copy"><strong><?php echo number_format($recoveryOffTotal); ?></strong><small>No recovery phone</small></span>
            </div>
        </div>
        <?php if (!$smsRecoveryReady): ?>
            <div class="alert alert-warning d-flex align-items-start gap-2 mt-3 mb-0" role="note">
                <i class="bi bi-exclamation-triangle-fill mt-1" aria-hidden="true"></i>
                <span>SMS recovery is switched off because SMS is not set up. Ask the server administrator to enable SMS and configure the PhilSMS token so recovery codes can be sent.</span>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-12">
        <section class="card shadow-sm" aria-labelledby="adminAccountsTitle">
            <div class="card-body">
                <div class="accounts-card-head">
                    <div>
                        <h2 class="h5 card-title text-primary mb-1" id="adminAccountsTitle"><i class="bi bi-people me-2" aria-hidden="true"></i>Accounts</h2>
                        <p class="small text-muted mb-0">Admins manage staff accounts. Staff can recover access by email or SMS when those options are configured.</p>
                    </div>
                    <button class="btn btn-primary accounts-add-button" type="button" data-bs-toggle="modal" data-bs-target="#addAdminModal">
                        <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Add staff user account
                    </button>
                </div>
                <ul class="accounts-list" aria-label="User accounts">
                    <?php foreach ($users as $index => $user): ?>
                        <?php
                        $isAdmin = $user['role'] === 'admin';
                        $isYou = $user['username'] === $_SESSION['admin'];
                        $maskedPhone = password_reset_mask_phone($user['recovery_phone'] ?? '');
                        ?>
                        <li class="accounts-row" style="--row-index: <?php echo (int)$index; ?>">
                            <div class="account-cell">
                                <span class="account-avatar<?php echo $isAdmin ? ' is-super' : ''; ?>" aria-hidden="true"><?php echo sanitize(strtoupper(substr($user['username'], 0, 1))); ?></span>
                                <span class="account-cell-copy">
                                    <strong><?php echo sanitize(trim($user['first_name'] . ' ' . $user['middle_name'] . ' ' . $user['surname']) ?: $user['username']); ?><?php echo $isYou ? ' <span class="account-you">you</span>' : ''; ?></strong>
                                    <span class="small text-muted"><?php echo sanitize($user['username']); ?> · <?php echo sanitize((string)($user['email'] ?? 'No email')); ?></span>
                                    <span class="badge text-bg-<?php echo $isAdmin ? 'success' : 'secondary'; ?>"><?php echo $isAdmin ? 'Admin' : 'Staff'; ?></span>
                                </span>
                            </div>
                            <div class="account-recovery-cell">
                                <span class="account-field-label">SMS recovery</span>
                                <?php if ($maskedPhone !== ''): ?>
                                    <span class="account-recovery is-on"><i class="bi bi-shield-check" aria-hidden="true"></i>On<span class="account-phone"><?php echo sanitize($maskedPhone); ?></span></span>
                                <?php else: ?>
                                    <span class="account-recovery is-off"><i class="bi bi-shield-exclamation" aria-hidden="true"></i>Not set</span>
                                <?php endif; ?>
                            </div>
                            <div class="account-manage">
                                <?php if ($isYou || !$isAdmin): ?>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-primary accounts-manage-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Manage <?php echo sanitize($user['username']); ?>">
                                            Manage<i class="bi bi-chevron-down ms-2" aria-hidden="true"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end accounts-menu">
                                            <?php if ($isYou): ?>
                                                <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#ownPasswordModal"><i class="bi bi-key" aria-hidden="true"></i>Change my password</button></li>
                                                <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#ownEmailModal"><i class="bi bi-envelope" aria-hidden="true"></i>Manage my email</button></li>
                                                <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#ownPhoneModal"><i class="bi bi-phone" aria-hidden="true"></i><?php echo $maskedPhone !== '' ? 'Change my recovery phone' : 'Add my recovery phone'; ?></button></li>
                                            <?php else: ?>
                                                <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#resetPasswordModal" data-user-id="<?php echo (int)$user['user_id']; ?>" data-username="<?php echo sanitize($user['username']); ?>"><i class="bi bi-key" aria-hidden="true"></i>Reset password</button></li>
                                                <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#staffEmailModal" data-user-id="<?php echo (int)$user['user_id']; ?>" data-username="<?php echo sanitize($user['username']); ?>" data-email="<?php echo sanitize((string)($user['email'] ?? '')); ?>"><i class="bi bi-envelope" aria-hidden="true"></i>Manage email</button></li>
                                                <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#recoveryPhoneModal" data-user-id="<?php echo (int)$user['user_id']; ?>" data-username="<?php echo sanitize($user['username']); ?>" data-masked="<?php echo sanitize($maskedPhone); ?>"><i class="bi bi-phone" aria-hidden="true"></i><?php echo $maskedPhone !== '' ? 'Change recovery phone' : 'Add recovery phone'; ?></button></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><button class="dropdown-item is-danger" type="button" data-bs-toggle="modal" data-bs-target="#removeAdminModal" data-user-id="<?php echo (int)$user['user_id']; ?>" data-username="<?php echo sanitize($user['username']); ?>"><i class="bi bi-person-x" aria-hidden="true"></i>Remove account</button></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">Admin account</span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    </div>

</div>

<?php
$modalPhoneField = static function (string $id, string $value = '') {
    ?>
    <label class="form-label" for="<?php echo sanitize($id); ?>">Recovery phone</label>
    <input class="form-control" type="tel" id="<?php echo sanitize($id); ?>" name="recovery_phone" value="<?php echo sanitize($value); ?>" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" placeholder="09123456789" autocomplete="off">
    <div class="form-text">11 digits. Leave empty to turn SMS recovery off.</div>
    <?php
};
?>

<div class="modal fade accounts-modal" id="addAdminModal" tabindex="-1" aria-labelledby="addAdminModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" data-admin-confirm="false" autocomplete="off">
            <div class="modal-header">
                <span class="accounts-modal-icon" aria-hidden="true"><i class="bi bi-person-plus"></i></span>
                <div>
                    <h2 class="modal-title" id="addAdminModalTitle">Add staff user account</h2>
                    <p class="accounts-modal-sub">A username and secure initial password are generated from the staff member's details.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                <input type="hidden" name="action" value="create_admin">
                <div class="mb-3">
                    <label class="form-label" for="newStaffFirstName">First name</label>
                    <input class="form-control" id="newStaffFirstName" name="first_name" value="<?php echo $reopenAdd ? sanitize((string)($_POST['first_name'] ?? '')) : ''; ?>" maxlength="100" autocomplete="given-name" required>
                    <div class="form-text">The username uses the initials of given names, optional middle initial, then surname (e.g. Louise Jayson + Villanueva → ljvillanueva).</div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6">
                        <label class="form-label" for="newStaffMiddleName">Middle name <span class="text-muted">(optional)</span></label>
                        <input class="form-control" id="newStaffMiddleName" name="middle_name" value="<?php echo $reopenAdd ? sanitize((string)($_POST['middle_name'] ?? '')) : ''; ?>" maxlength="100" autocomplete="additional-name">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label" for="newStaffSurname">Surname</label>
                        <input class="form-control" id="newStaffSurname" name="surname" value="<?php echo $reopenAdd ? sanitize((string)($_POST['surname'] ?? '')) : ''; ?>" maxlength="100" autocomplete="family-name" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="newStaffEmail">Email <span class="text-muted">(optional)</span></label>
                    <input class="form-control" type="email" id="newStaffEmail" name="email" value="<?php echo $reopenAdd ? sanitize((string)($_POST['email'] ?? '')) : ''; ?>" maxlength="254" autocomplete="email">
                    <div class="form-text">A one-time link will be sent so the staff member can set a password, if SMTP is configured.</div>
                </div>
                <div>
                    <label class="form-label" for="newAdminRecoveryPhone">Recovery phone <span class="text-muted">(optional)</span></label>
                    <input class="form-control" type="tel" id="newAdminRecoveryPhone" name="recovery_phone" value="<?php echo $reopenAdd ? sanitize((string)($_POST['recovery_phone'] ?? '')) : ''; ?>" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" placeholder="09123456789" autocomplete="off">
                    <div class="form-text">Lets the staff member reset a forgotten password with an SMS code.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Add staff account</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade accounts-modal" id="staffEmailModal" tabindex="-1" aria-labelledby="staffEmailModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" data-admin-confirm="false" autocomplete="off">
            <div class="modal-header">
                <span class="accounts-modal-icon" aria-hidden="true"><i class="bi bi-envelope"></i></span>
                <div>
                    <h2 class="modal-title" id="staffEmailModalTitle">Staff recovery email</h2>
                    <p class="accounts-modal-sub">Set or clear the email for <strong data-fill="username"></strong>.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                <input type="hidden" name="action" value="set_staff_email">
                <input type="hidden" name="user_id" value="" data-fill="user_id">
                <label class="form-label" for="staffRecoveryEmail">Email address</label>
                <input class="form-control" type="email" id="staffRecoveryEmail" name="email" maxlength="254" autocomplete="email">
                <div class="form-text">Password recovery by email requires SMTP to be configured.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit">Save email</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade accounts-modal" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" data-admin-confirm="false" autocomplete="off">
            <div class="modal-header">
                <span class="accounts-modal-icon" aria-hidden="true"><i class="bi bi-key"></i></span>
                <div>
                    <h2 class="modal-title" id="resetPasswordModalTitle">Reset password</h2>
                    <p class="accounts-modal-sub">Set a temporary password for <strong data-fill="username"></strong>. They are signed out of existing sessions.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                <input type="hidden" name="action" value="reset_admin_password">
                <input type="hidden" name="user_id" value="" data-fill="user_id">
                <div class="row g-3">
                    <div class="col-12 col-sm-6">
                        <label class="form-label" for="resetPasswordNew">New password</label>
                        <input class="form-control" type="password" id="resetPasswordNew" name="password" minlength="<?php echo ADMIN_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label" for="resetPasswordConfirm">Confirm password</label>
                        <input class="form-control" type="password" id="resetPasswordConfirm" name="password_confirmation" minlength="<?php echo ADMIN_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required>
                    </div>
                    <div class="col-12"><div class="form-text mt-0">At least <?php echo ADMIN_PASSWORD_MIN_LENGTH; ?> characters. Share it securely, such as in person.</div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save password</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade accounts-modal" id="ownEmailModal" tabindex="-1" aria-labelledby="ownEmailModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" data-admin-confirm="false" autocomplete="off">
            <div class="modal-header">
                <span class="accounts-modal-icon" aria-hidden="true"><i class="bi bi-envelope"></i></span>
                <div>
                    <h2 class="modal-title" id="ownEmailModalTitle">Your recovery email</h2>
                    <p class="accounts-modal-sub">An email address lets you use password recovery if SMTP is configured.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                <input type="hidden" name="action" value="set_own_email">
                <?php
                $ownEmail = '';
                foreach ($users as $accountRow) {
                    if ($accountRow['username'] === $_SESSION['admin']) {
                        $ownEmail = (string)($accountRow['email'] ?? '');
                    }
                }
                ?>
                <div class="mb-3">
                    <label class="form-label" for="ownRecoveryEmail">Email address</label>
                    <input class="form-control" type="email" id="ownRecoveryEmail" name="email" value="<?php echo sanitize($ownEmail); ?>" maxlength="254" autocomplete="email">
                </div>
                <div>
                    <label class="form-label" for="ownEmailCurrentPassword">Current password</label>
                    <input class="form-control" type="password" id="ownEmailCurrentPassword" name="current_password" autocomplete="current-password" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit">Save email</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade accounts-modal" id="recoveryPhoneModal" tabindex="-1" aria-labelledby="recoveryPhoneModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" data-admin-confirm="false" autocomplete="off">
            <div class="modal-header">
                <span class="accounts-modal-icon" aria-hidden="true"><i class="bi bi-phone"></i></span>
                <div>
                    <h2 class="modal-title" id="recoveryPhoneModalTitle">Recovery phone</h2>
                    <p class="accounts-modal-sub">SMS recovery for <strong data-fill="username"></strong>. <span data-fill="masked"></span></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                <input type="hidden" name="action" value="set_recovery_phone">
                <input type="hidden" name="user_id" value="" data-fill="user_id">
                <?php $modalPhoneField('adminRecoveryPhone'); ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save phone</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade accounts-modal" id="removeAdminModal" tabindex="-1" aria-labelledby="removeAdminModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" data-admin-confirm="false">
            <div class="modal-header">
                <span class="accounts-modal-icon is-danger" aria-hidden="true"><i class="bi bi-person-x"></i></span>
                <div>
                    <h2 class="modal-title" id="removeAdminModalTitle">Remove account?</h2>
                    <p class="accounts-modal-sub"><strong data-fill="username"></strong> will no longer be able to sign in. This cannot be undone.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
            <input type="hidden" name="action" value="delete_admin">
            <input type="hidden" name="user_id" value="" data-fill="user_id">
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep account</button>
                <button class="btn btn-danger" type="submit"><i class="bi bi-person-x me-1" aria-hidden="true"></i>Remove account</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade accounts-modal" id="ownPasswordModal" tabindex="-1" aria-labelledby="ownPasswordModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" data-admin-confirm="false" autocomplete="off">
            <div class="modal-header">
                <span class="accounts-modal-icon" aria-hidden="true"><i class="bi bi-key"></i></span>
                <div>
                    <h2 class="modal-title" id="ownPasswordModalTitle">Change your password</h2>
                    <p class="accounts-modal-sub">Use a unique password with at least <?php echo ADMIN_PASSWORD_MIN_LENGTH; ?> characters. You stay signed in on this device.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                <input type="hidden" name="action" value="change_own_password">
                <div class="mb-3">
                    <label class="form-label" for="currentAdminPassword">Current password</label>
                    <input class="form-control" type="password" id="currentAdminPassword" name="current_password" autocomplete="current-password" required>
                </div>
                <div class="row g-3">
                    <div class="col-12 col-sm-6">
                        <label class="form-label" for="newAdminPassword">New password</label>
                        <input class="form-control" type="password" id="newAdminPassword" name="password" minlength="<?php echo ADMIN_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label" for="confirmAdminPassword">Confirm new password</label>
                        <input class="form-control" type="password" id="confirmAdminPassword" name="password_confirmation" minlength="<?php echo ADMIN_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Change password</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade accounts-modal" id="ownPhoneModal" tabindex="-1" aria-labelledby="ownPhoneModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" data-admin-confirm="false" autocomplete="off">
            <div class="modal-header">
                <span class="accounts-modal-icon" aria-hidden="true"><i class="bi bi-phone"></i></span>
                <div>
                    <h2 class="modal-title" id="ownPhoneModalTitle">Your recovery phone</h2>
                    <p class="accounts-modal-sub">A one-time code is texted here if you forget your password. Use a phone only you control.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                <input type="hidden" name="action" value="set_own_recovery_phone">
                <?php
                $ownPhone = '';
                foreach ($users as $accountRow) {
                    if ($accountRow['username'] === $_SESSION['admin']) {
                        $ownPhone = password_reset_mask_phone($accountRow['recovery_phone'] ?? '');
                    }
                }
                ?>
                <p class="small mb-3">
                    Current number:
                    <?php if ($ownPhone !== ''): ?>
                        <span class="account-recovery is-on"><i class="bi bi-shield-check" aria-hidden="true"></i>On<span class="account-phone"><?php echo sanitize($ownPhone); ?></span></span>
                    <?php else: ?>
                        <span class="account-recovery is-off"><i class="bi bi-shield-exclamation" aria-hidden="true"></i>Not set</span>
                    <?php endif; ?>
                </p>
                <div class="mb-3"><?php $modalPhoneField('ownRecoveryPhone'); ?></div>
                <div>
                    <label class="form-label" for="ownRecoveryPhonePassword">Current password</label>
                    <input class="form-control" type="password" id="ownRecoveryPhonePassword" name="current_password" autocomplete="current-password" required>
                    <div class="form-text">Needed to change this setting.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save recovery phone</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const fillFromTrigger = function (modal, event) {
        const trigger = event.relatedTarget;
        if (!trigger) return;
        modal.querySelectorAll('[data-fill="user_id"]').forEach(function (input) { input.value = trigger.dataset.userId || ''; });
        modal.querySelectorAll('[data-fill="username"]').forEach(function (label) { label.textContent = trigger.dataset.username || ''; });
        modal.querySelectorAll('[data-fill="masked"]').forEach(function (label) {
            label.textContent = trigger.dataset.masked ? 'Current number: ' + trigger.dataset.masked : 'No recovery phone set yet.';
        });
    };
    ['staffEmailModal', 'resetPasswordModal', 'recoveryPhoneModal', 'removeAdminModal'].forEach(function (id) {
        const modal = document.getElementById(id);
        if (modal) modal.addEventListener('show.bs.modal', function (event) {
            fillFromTrigger(modal, event);
            if (id === 'staffEmailModal') {
                const trigger = event.relatedTarget;
                const email = modal.querySelector('#staffRecoveryEmail');
                if (trigger && email) email.value = trigger.dataset.email || '';
            }
        });
    });
    document.querySelectorAll('.accounts-modal').forEach(function (modal) {
        modal.addEventListener('shown.bs.modal', function () {
            const first = modal.querySelector('input:not([type="hidden"])');
            if (first) first.focus();
        });
        // Never leave typed passwords or numbers behind when a pop-out is closed.
        modal.addEventListener('hidden.bs.modal', function () {
            modal.querySelectorAll('input[type="password"], input[type="tel"]').forEach(function (input) { input.value = ''; });
        });
    });
    <?php if ($reopenAdd): ?>
    new bootstrap.Modal(document.getElementById('addAdminModal')).show();
    <?php endif; ?>
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
