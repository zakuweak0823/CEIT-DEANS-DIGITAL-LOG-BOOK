<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'password_reset_support.php';
ensure_super_admin();
ensure_password_reset_schema($conn);

$notice = '';
$noticeType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create_admin') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');
        $recoveryPhone = contact_number_input($_POST['recovery_phone'] ?? '');

        if (preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $username) !== 1) {
            $notice = 'Use 3–100 characters for the username: letters, numbers, periods, underscores, or hyphens.';
            $noticeType = 'danger';
        } elseif (!admin_password_meets_minimum_length($password)) {
            $notice = 'Use a password with at least ' . ADMIN_PASSWORD_MIN_LENGTH . ' characters.';
            $noticeType = 'danger';
        } elseif ($password !== $passwordConfirmation) {
            $notice = 'The password fields do not match.';
            $noticeType = 'danger';
        } elseif ($recoveryPhone !== '' && !is_valid_contact_number($recoveryPhone)) {
            $notice = 'Enter the recovery phone as exactly 11 digits, or leave it empty.';
            $noticeType = 'danger';
        } else {
            try {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = safe_query($conn, "INSERT INTO users (username, password, role, recovery_phone) VALUES (?, ?, 'admin', ?)", 'sss', [$username, $passwordHash, $recoveryPhone !== '' ? $recoveryPhone : null]);
                if ($stmt === false) {
                    $notice = 'The administrator account could not be created. Check the server log and try again.';
                    $noticeType = 'danger';
                } else {
                    mysqli_stmt_close($stmt);
                    $notice = 'Administrator account created. Share the initial password with the account holder through a secure channel.';
                }
            } catch (mysqli_sql_exception $error) {
                if ((int)$error->getCode() === 1062) {
                    $notice = 'That username is already in use.';
                    $noticeType = 'danger';
                } else {
                    throw $error;
                }
            }
        }
    } elseif ($action === 'reset_admin_password') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');

        if (!$userId || $userId < 1) {
            $notice = 'Select a valid administrator account.';
            $noticeType = 'danger';
        } elseif (!admin_password_meets_minimum_length($password)) {
            $notice = 'Use a password with at least ' . ADMIN_PASSWORD_MIN_LENGTH . ' characters.';
            $noticeType = 'danger';
        } elseif ($password !== $passwordConfirmation) {
            $notice = 'The password fields do not match.';
            $noticeType = 'danger';
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = safe_query($conn, "UPDATE users SET password = ? WHERE user_id = ? AND role = 'admin'", 'si', [$passwordHash, $userId]);
            if ($stmt === false) {
                $notice = 'The password could not be reset. Check the server log and try again.';
                $noticeType = 'danger';
            } else {
                $updated = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);
                $notice = $updated === 1
                    ? 'Administrator password reset. Share the new password through a secure channel.'
                    : 'The administrator account was not found or cannot be reset.';
                $noticeType = $updated === 1 ? 'success' : 'warning';
            }
        }
    } elseif ($action === 'change_own_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');
        $stmt = safe_query($conn, "SELECT password FROM users WHERE username = ? AND role = 'super_admin' LIMIT 1", 's', [$_SESSION['admin']]);
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
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = safe_query($conn, "UPDATE users SET password = ? WHERE username = ? AND role = 'super_admin'", 'ss', [$passwordHash, $_SESSION['admin']]);
                if ($stmt === false) {
                    $notice = 'Your password could not be changed. Check the server log and try again.';
                    $noticeType = 'danger';
                } else {
                    mysqli_stmt_close($stmt);
                    $_SESSION['admin_password_hash'] = $passwordHash;
                    session_regenerate_id(true);
                    $notice = 'Your password has been changed.';
                }
            }
        }
    } elseif ($action === 'set_recovery_phone') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $recoveryPhone = contact_number_input($_POST['recovery_phone'] ?? '');
        if (!$userId || $userId < 1) {
            $notice = 'Select a valid administrator account.';
            $noticeType = 'danger';
        } elseif ($recoveryPhone !== '' && !is_valid_contact_number($recoveryPhone)) {
            $notice = 'Enter the recovery phone as exactly 11 digits, or leave it empty to turn SMS recovery off.';
            $noticeType = 'danger';
        } else {
            $stmt = safe_query($conn, "UPDATE users SET recovery_phone = ? WHERE user_id = ? AND role = 'admin'", 'si', [$recoveryPhone !== '' ? $recoveryPhone : null, $userId]);
            if ($stmt === false) {
                $notice = 'The recovery phone could not be saved. Check the server log and try again.';
                $noticeType = 'danger';
            } else {
                mysqli_stmt_close($stmt);
                $notice = $recoveryPhone !== '' ? 'Recovery phone saved. SMS password recovery is on for this account.' : 'Recovery phone removed. SMS password recovery is off for this account.';
            }
        }
    } elseif ($action === 'set_own_recovery_phone') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $recoveryPhone = contact_number_input($_POST['recovery_phone'] ?? '');
        $stmt = safe_query($conn, "SELECT password FROM users WHERE username = ? AND role = 'super_admin' LIMIT 1", 's', [$_SESSION['admin']]);
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
            $stmt = safe_query($conn, "UPDATE users SET recovery_phone = ? WHERE username = ? AND role = 'super_admin'", 'ss', [$recoveryPhone !== '' ? $recoveryPhone : null, $_SESSION['admin']]);
            if ($stmt === false) {
                $notice = 'Your recovery phone could not be saved. Check the server log and try again.';
                $noticeType = 'danger';
            } else {
                mysqli_stmt_close($stmt);
                $notice = $recoveryPhone !== '' ? 'Your recovery phone was saved.' : 'Your recovery phone was removed.';
            }
        }
    } elseif ($action === 'delete_admin') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId || $userId < 1) {
            $notice = 'Select a valid administrator account.';
            $noticeType = 'danger';
        } else {
            $stmt = safe_query($conn, "DELETE FROM users WHERE user_id = ? AND role = 'admin' AND username <> ?", 'is', [$userId, $_SESSION['admin']]);
            if ($stmt === false) {
                $notice = 'The administrator account could not be removed. Check the server log and try again.';
                $noticeType = 'danger';
            } else {
                $deleted = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);
                $notice = $deleted === 1
                    ? 'Administrator account removed.'
                    : 'The account was not found or cannot be removed.';
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
$stmt = safe_query($conn, 'SELECT user_id, username, role, recovery_phone FROM users ORDER BY CASE WHEN role = \'super_admin\' THEN 0 ELSE 1 END, username');
if ($stmt === false) {
    http_response_code(500);
    exit('Administrator accounts could not be loaded. Check the server log and try again.');
}
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $users[] = $row;
}
mysqli_stmt_close($stmt);

$accountTotal = count($users);
$superAdminTotal = 0;
$recoveryOnTotal = 0;
foreach ($users as $accountRow) {
    $superAdminTotal += $accountRow['role'] === 'super_admin' ? 1 : 0;
    $recoveryOnTotal += is_valid_contact_number((string)($accountRow['recovery_phone'] ?? '')) ? 1 : 0;
}
$recoveryOffTotal = $accountTotal - $recoveryOnTotal;
$smsRecoveryReady = password_reset_sms_available($conn);
// A failed "Add administrator" submit reopens its pop-out with the typed values kept.
$reopenAdd = $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string)($_POST['action'] ?? '') === 'create_admin'
    && $noticeType !== 'success';
?>
<?php include 'header.php'; ?>
<div class="row g-4 admin-accounts-page">
    <div class="col-12">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-2">
            <div>
                <h1 class="h2 mb-1 text-primary">Administrator accounts</h1>
                <p class="text-muted mb-0">Add administrators, reset passwords, and manage who can recover an account by SMS.</p>
            </div>
        </div>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="col-12"><div class="alert alert-<?php echo sanitize($noticeType); ?>" role="status"><?php echo sanitize($notice); ?></div></div>
    <?php endif; ?>

    <div class="col-12">
        <div class="accounts-summary" role="list">
            <div class="accounts-summary-item" role="listitem">
                <span class="accounts-summary-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
                <span class="accounts-summary-copy"><strong><?php echo number_format($accountTotal); ?></strong><small>Accounts</small></span>
            </div>
            <div class="accounts-summary-item" role="listitem">
                <span class="accounts-summary-icon"><i class="bi bi-person-gear" aria-hidden="true"></i></span>
                <span class="accounts-summary-copy"><strong><?php echo number_format($superAdminTotal); ?></strong><small>Super Admin<?php echo $superAdminTotal === 1 ? '' : 's'; ?></small></span>
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
                <span>SMS recovery is switched off because SMS is not set up. Turn on SMS and add a PhilSMS token in <a href="notification_settings">Communication Settings</a> so recovery codes can be sent.</span>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-12">
        <section class="card shadow-sm" aria-labelledby="adminAccountsTitle">
            <div class="card-body">
                <div class="accounts-card-head">
                    <div>
                        <h2 class="h5 card-title text-primary mb-1" id="adminAccountsTitle"><i class="bi bi-people me-2" aria-hidden="true"></i>Accounts</h2>
                        <p class="small text-muted mb-0">A recovery phone lets an administrator reset a forgotten password with an SMS code. Only a Super Admin can manage these accounts.</p>
                    </div>
                    <button class="btn btn-primary accounts-add-button" type="button" data-bs-toggle="modal" data-bs-target="#addAdminModal">
                        <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Add administrator
                    </button>
                </div>
                <ul class="accounts-list" aria-label="Administrator accounts">
                    <?php foreach ($users as $index => $user): ?>
                        <?php
                        $isSuper = $user['role'] === 'super_admin';
                        $isYou = $user['username'] === $_SESSION['admin'];
                        $maskedPhone = password_reset_mask_phone($user['recovery_phone'] ?? '');
                        ?>
                        <li class="accounts-row" style="--row-index: <?php echo (int)$index; ?>">
                            <div class="account-cell">
                                <span class="account-avatar<?php echo $isSuper ? ' is-super' : ''; ?>" aria-hidden="true"><?php echo sanitize(strtoupper(substr($user['username'], 0, 1))); ?></span>
                                <span class="account-cell-copy">
                                    <strong><?php echo sanitize($user['username']); ?><?php echo $isYou ? ' <span class="account-you">you</span>' : ''; ?></strong>
                                    <span class="badge text-bg-<?php echo $isSuper ? 'success' : 'secondary'; ?>"><?php echo $isSuper ? 'Super Admin' : 'Administrator'; ?></span>
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
                                <?php if ($isYou || !$isSuper): ?>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-primary accounts-manage-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Manage <?php echo sanitize($user['username']); ?>">
                                            Manage<i class="bi bi-chevron-down ms-2" aria-hidden="true"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end accounts-menu">
                                            <?php if ($isYou): ?>
                                                <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#ownPasswordModal"><i class="bi bi-key" aria-hidden="true"></i>Change my password</button></li>
                                                <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#ownPhoneModal"><i class="bi bi-phone" aria-hidden="true"></i><?php echo $maskedPhone !== '' ? 'Change my recovery phone' : 'Add my recovery phone'; ?></button></li>
                                            <?php else: ?>
                                                <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#resetPasswordModal" data-user-id="<?php echo (int)$user['user_id']; ?>" data-username="<?php echo sanitize($user['username']); ?>"><i class="bi bi-key" aria-hidden="true"></i>Reset password</button></li>
                                                <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#recoveryPhoneModal" data-user-id="<?php echo (int)$user['user_id']; ?>" data-username="<?php echo sanitize($user['username']); ?>" data-masked="<?php echo sanitize($maskedPhone); ?>"><i class="bi bi-phone" aria-hidden="true"></i><?php echo $maskedPhone !== '' ? 'Change recovery phone' : 'Add recovery phone'; ?></button></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><button class="dropdown-item is-danger" type="button" data-bs-toggle="modal" data-bs-target="#removeAdminModal" data-user-id="<?php echo (int)$user['user_id']; ?>" data-username="<?php echo sanitize($user['username']); ?>"><i class="bi bi-person-x" aria-hidden="true"></i>Remove account</button></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">Managed by its owner</span>
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
                    <h2 class="modal-title" id="addAdminModalTitle">Add administrator</h2>
                    <p class="accounts-modal-sub">New accounts receive standard administrator access.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                <input type="hidden" name="action" value="create_admin">
                <div class="mb-3">
                    <label class="form-label" for="newAdminUsername">Username</label>
                    <input class="form-control" id="newAdminUsername" name="username" value="<?php echo $reopenAdd ? sanitize((string)($_POST['username'] ?? '')) : ''; ?>" minlength="3" maxlength="100" pattern="[A-Za-z0-9_.\-]{3,100}" autocomplete="off" required>
                    <div class="form-text">3–100 letters, numbers, periods, underscores, or hyphens.</div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6">
                        <label class="form-label" for="newAdminPassword">Password</label>
                        <input class="form-control" type="password" id="newAdminPassword" name="password" minlength="<?php echo ADMIN_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label" for="newAdminPasswordConfirmation">Confirm password</label>
                        <input class="form-control" type="password" id="newAdminPasswordConfirmation" name="password_confirmation" minlength="<?php echo ADMIN_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required>
                    </div>
                    <div class="col-12"><div class="form-text mt-0">At least <?php echo ADMIN_PASSWORD_MIN_LENGTH; ?> characters. Share it with the account holder in person.</div></div>
                </div>
                <div>
                    <label class="form-label" for="newAdminRecoveryPhone">Recovery phone <span class="text-muted">(optional)</span></label>
                    <input class="form-control" type="tel" id="newAdminRecoveryPhone" name="recovery_phone" value="<?php echo $reopenAdd ? sanitize((string)($_POST['recovery_phone'] ?? '')) : ''; ?>" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" placeholder="09123456789" autocomplete="off">
                    <div class="form-text">Lets this administrator reset a forgotten password with an SMS code.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Create account</button>
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
                    <label class="form-label" for="currentSuperAdminPassword">Current password</label>
                    <input class="form-control" type="password" id="currentSuperAdminPassword" name="current_password" autocomplete="current-password" required>
                </div>
                <div class="row g-3">
                    <div class="col-12 col-sm-6">
                        <label class="form-label" for="newSuperAdminPassword">New password</label>
                        <input class="form-control" type="password" id="newSuperAdminPassword" name="password" minlength="<?php echo ADMIN_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label" for="confirmSuperAdminPassword">Confirm new password</label>
                        <input class="form-control" type="password" id="confirmSuperAdminPassword" name="password_confirmation" minlength="<?php echo ADMIN_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required>
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
    ['resetPasswordModal', 'recoveryPhoneModal', 'removeAdminModal'].forEach(function (id) {
        const modal = document.getElementById(id);
        if (modal) modal.addEventListener('show.bs.modal', function (event) { fillFromTrigger(modal, event); });
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
<?php include 'footer.php'; ?>
