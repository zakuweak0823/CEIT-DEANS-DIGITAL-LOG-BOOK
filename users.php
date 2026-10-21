<?php
require_once 'auth.php';
require_once 'db.php';
ensure_super_admin();

$notice = '';
$noticeType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create_admin') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');

        if (preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $username) !== 1) {
            $notice = 'Use 3–100 characters for the username: letters, numbers, periods, underscores, or hyphens.';
            $noticeType = 'danger';
        } elseif (!admin_password_meets_minimum_length($password)) {
            $notice = 'Use a password with at least 12 characters.';
            $noticeType = 'danger';
        } elseif ($password !== $passwordConfirmation) {
            $notice = 'The password fields do not match.';
            $noticeType = 'danger';
        } else {
            try {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = safe_query($conn, "INSERT INTO users (username, password, role) VALUES (?, ?, 'admin')", 'ss', [$username, $passwordHash]);
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
            $notice = 'Use a password with at least 12 characters.';
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
                $notice = 'Use a password with at least 12 characters.';
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
$stmt = safe_query($conn, 'SELECT user_id, username, role FROM users ORDER BY CASE WHEN role = \'super_admin\' THEN 0 ELSE 1 END, username');
if ($stmt === false) {
    http_response_code(500);
    exit('Administrator accounts could not be loaded. Check the server log and try again.');
}
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $users[] = $row;
}
mysqli_stmt_close($stmt);
?>
<?php include 'header.php'; ?>
<div class="row g-4 admin-accounts-page">
    <div class="col-12">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-2">
            <div>
                <h1 class="h2 mb-1 text-primary">Administrator accounts</h1>
                <p class="text-muted mb-0">Create administrator accounts and reset their passwords.</p>
            </div>
        </div>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="col-12"><div class="alert alert-<?php echo sanitize($noticeType); ?>" role="status"><?php echo sanitize($notice); ?></div></div>
    <?php endif; ?>

    <div class="col-12 col-xl-4">
        <section class="card shadow-sm h-100" aria-labelledby="createAdminTitle">
            <div class="card-body">
                <h2 class="h5 card-title text-primary" id="createAdminTitle">Add administrator</h2>
                <p class="small text-muted">New accounts receive standard administrator access.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <input type="hidden" name="action" value="create_admin">
                    <div class="mb-3">
                        <label class="form-label" for="newAdminUsername">Username</label>
                        <input class="form-control" id="newAdminUsername" name="username" minlength="3" maxlength="100" pattern="[A-Za-z0-9_.-]{3,100}" autocomplete="off" required>
                        <div class="form-text">3–100 letters, numbers, periods, underscores, or hyphens.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="newAdminPassword">Initial password</label>
                        <input class="form-control" type="password" id="newAdminPassword" name="password" minlength="12" autocomplete="new-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="newAdminPasswordConfirmation">Confirm password</label>
                        <input class="form-control" type="password" id="newAdminPasswordConfirmation" name="password_confirmation" minlength="12" autocomplete="new-password" required>
                    </div>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Create account</button>
                </form>
            </div>
        </section>
    </div>

    <div class="col-12 col-xl-8">
        <section class="card shadow-sm" aria-labelledby="adminAccountsTitle">
            <div class="card-body">
                <div class="mb-3">
                    <h2 class="h5 card-title text-primary mb-1" id="adminAccountsTitle">Accounts</h2>
                    <p class="small text-muted mb-0">Only a Super Admin can manage administrator accounts.</p>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 admin-accounts-table admin-mobile-card-table">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Username</th>
                                <th scope="col">Role</th>
                                <th scope="col">Password</th>
                                <th scope="col">Account</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td data-label="Username"><?php echo sanitize($user['username']); ?><?php echo $user['username'] === $_SESSION['admin'] ? ' <span class="text-muted">(you)</span>' : ''; ?></td>
                                    <td data-label="Role">
                                        <span class="badge text-bg-<?php echo $user['role'] === 'super_admin' ? 'success' : 'secondary'; ?>">
                                            <?php echo $user['role'] === 'super_admin' ? 'Super Admin' : 'Administrator'; ?>
                                        </span>
                                    </td>
                                    <td data-label="Password">
                                        <?php if ($user['role'] === 'admin'): ?>
                                            <details class="admin-password-reset">
                                                <summary class="btn btn-sm btn-outline-primary">Reset password</summary>
                                                <form method="POST" class="admin-password-reset-form">
                                                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="reset_admin_password">
                                                    <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">
                                                    <label class="form-label" for="resetPassword<?php echo (int)$user['user_id']; ?>">New password</label>
                                                    <input class="form-control mb-2" type="password" id="resetPassword<?php echo (int)$user['user_id']; ?>" name="password" minlength="12" autocomplete="new-password" required>
                                                    <label class="form-label" for="confirmPassword<?php echo (int)$user['user_id']; ?>">Confirm password</label>
                                                    <input class="form-control mb-2" type="password" id="confirmPassword<?php echo (int)$user['user_id']; ?>" name="password_confirmation" minlength="12" autocomplete="new-password" required>
                                                    <button class="btn btn-primary btn-sm" type="submit">Save password</button>
                                                </form>
                                            </details>
                                        <?php else: ?>
                                            <span class="text-muted">Managed by system administrator</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Account">
                                        <?php if ($user['role'] === 'admin' && $user['username'] !== $_SESSION['admin']): ?>
                                            <form method="POST" onsubmit="return confirm('Remove this administrator account? The user will no longer be able to sign in.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="delete_admin">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$user['user_id']; ?>">
                                                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-person-x me-1" aria-hidden="true"></i>Remove</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <div class="col-12">
        <section class="card shadow-sm" aria-labelledby="changeOwnPasswordTitle">
            <div class="card-body">
                <h2 class="h5 card-title text-primary" id="changeOwnPasswordTitle">Change your password</h2>
                <p class="small text-muted">Use a unique password with at least 12 characters.</p>
                <form method="POST" class="row g-3 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <input type="hidden" name="action" value="change_own_password">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="currentSuperAdminPassword">Current password</label>
                        <input class="form-control" type="password" id="currentSuperAdminPassword" name="current_password" autocomplete="current-password" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="newSuperAdminPassword">New password</label>
                        <input class="form-control" type="password" id="newSuperAdminPassword" name="password" minlength="12" autocomplete="new-password" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="confirmSuperAdminPassword">Confirm new password</label>
                        <input class="form-control" type="password" id="confirmSuperAdminPassword" name="password_confirmation" minlength="12" autocomplete="new-password" required>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary" type="submit">Change password</button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</div>
<?php include 'footer.php'; ?>
