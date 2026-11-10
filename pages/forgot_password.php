<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/password_reset_support.php';

if (!empty($_SESSION['admin'])) {
    header('Location: dashboard');
    exit;
}

$setupOk = ensure_password_reset_schema($conn);
$smsReady = $setupOk && password_reset_sms_available($conn);
$emailReady = $setupOk && logbook_email_available();
$error = '';
$info = '';
$username = '';
$linkToken = is_string($_GET['token'] ?? null) ? trim($_GET['token']) : '';
$linkRow = $linkToken !== '' ? password_reset_link_find($conn, $linkToken) : null;
if ($linkToken !== '' && !$linkRow) {
    $error = 'This password link is invalid, expired, or already used. Request a new recovery link.';
}
$pending = $_SESSION['pw_reset'] ?? null;
if (is_array($pending) && (time() - (int)($pending['at'] ?? 0)) > 900) {
    unset($_SESSION['pw_reset']);
    $pending = null;
}
if (isset($_GET['restart'])) {
    unset($_SESSION['pw_reset']);
    header('Location: forgot_password');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $expectedToken = $_SESSION['csrf_token'] ?? '';
    if ($expectedToken === '' || !is_string($submittedToken) || !hash_equals($expectedToken, $submittedToken)) {
        $error = 'This page expired. Please try again.';
    } elseif (isset($_POST['reset_email_password'])) {
        $postedToken = is_string($_POST['token'] ?? null) ? trim($_POST['token']) : '';
        $postedLink = password_reset_link_find($conn, $postedToken);
        $password = (string)($_POST['password'] ?? '');
        $confirmation = (string)($_POST['password_confirmation'] ?? '');
        if (!$postedLink || !in_array($postedLink['purpose'], ['setup', 'recovery'], true)) {
            $error = 'This password link is invalid, expired, or already used. Request a new recovery link.';
        } elseif (!admin_password_meets_minimum_length($password)) {
            $error = 'Use a password with at least ' . ADMIN_PASSWORD_MIN_LENGTH . ' characters.';
        } elseif ($password !== $confirmation) {
            $error = 'The password fields do not match.';
        } elseif (!password_reset_link_complete($conn, $postedLink, $password)) {
            $error = 'Your password could not be changed. Please request a new link and try again.';
        } else {
            unset($_SESSION['pw_reset']);
            session_regenerate_id(true);
            $_SESSION['login_notice'] = 'Your password was changed. Sign in with your new password.';
            header('Location: login');
            exit;
        }
    } elseif (!$smsReady && !$emailReady) {
        $error = 'Password recovery is not available right now. Contact your administrator.';
    } elseif (isset($_POST['request_code'])) {
        $username = trim((string)($_POST['username'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $username) !== 1) {
            $error = 'Enter your username.';
        } else {
            $state = password_reset_throttle_state($conn, $username);
            if ($state === 'limited') {
                $error = 'If you recently requested recovery, wait before trying again. Otherwise, contact an Admin for help.';
            } elseif ($state === 'cooldown') {
                $error = 'If you recently requested recovery, wait before trying again. Otherwise, contact an Admin for help.';
            } else {
                if (random_int(1, 20) === 1) {
                    password_reset_purge($conn);
                }
                $_SESSION['pw_reset'] = ['username' => $username, 'at' => time()];
                $emailIssue = $emailReady ? password_reset_email_issue($conn, $username) : null;
                if ($emailIssue !== null) {
                    password_reset_finish_response('forgot_password');
                    if (!password_reset_email_send($conn, $emailIssue, 'recovery')) {
                        $cancel = safe_query(
                            $conn,
                            'UPDATE password_reset_links SET used_at = NOW() WHERE token_hash = ? AND used_at IS NULL',
                            's',
                            [hash('sha256', (string)$emailIssue['token'])]
                        );
                        if ($cancel === false) {
                            error_log('Undelivered password recovery link could not be revoked.');
                        } else {
                            mysqli_stmt_close($cancel);
                        }
                    }
                    exit;
                }
                $issued = $smsReady ? password_reset_issue($conn, $username) : null;
                // The reply is identical whether or not the account can receive a recovery message.
                password_reset_finish_response('forgot_password');
                if ($issued !== null) {
                    if (password_reset_send_code($conn, $issued['phone'], $issued['code'])) {
                        record_audit_event($conn, 'password_reset_code_sent', $username);
                    }
                }
                exit;
            }
        }
    } elseif (isset($_POST['reset_password']) && is_array($pending)) {
        $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirmation = (string)($_POST['password_confirmation'] ?? '');
        if (strlen($code) !== 6) {
            $error = 'Enter the 6-digit code from your SMS.';
        } elseif (!admin_password_meets_minimum_length($password)) {
            $error = 'Use a password with at least ' . ADMIN_PASSWORD_MIN_LENGTH . ' characters.';
        } elseif ($password !== $confirmation) {
            $error = 'The password fields do not match.';
        } else {
            $reset = password_reset_verify($conn, (string)$pending['username'], $code);
            if ($reset === null) {
                $error = 'That code is incorrect or has expired. Check your SMS, or go back and request a new code.';
            } elseif (!password_reset_complete($conn, $reset, $password)) {
                $error = 'Your password could not be changed. Please try again.';
            } else {
                unset($_SESSION['pw_reset']);
                session_regenerate_id(true);
                $_SESSION['login_notice'] = 'Your password was changed. Sign in with your new password.';
                $phone = (string)$reset['recovery_phone'];
                password_reset_finish_response('login');
                if (is_valid_contact_number($phone)) {
                    password_reset_notify_changed($conn, $phone);
                }
                exit;
            }
        }
    }
    $pending = $_SESSION['pw_reset'] ?? null;
}
$step = $linkRow ? 'email-reset' : (is_array($pending) ? 'reset' : 'request');
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<section class="login-shell" aria-labelledby="recoveryTitle">
    <div class="login-panel">
        <div class="login-form-wrap">
            <div class="login-card-brand">
                <img src="assets/images/CVSU.jpeg" alt="" class="login-card-logo">
                <span>
                    <strong>CEIT Dean's Office</strong>
                    <small>Digital Logbook</small>
                </span>
            </div>
            <div class="login-heading">
                <h1 id="recoveryTitle"><?php echo $step === 'email-reset' && ($linkRow['purpose'] ?? '') === 'setup' ? 'Set up your new account password' : 'Password recovery'; ?></h1>
                <p class="login-intro"><?php echo $step === 'email-reset' ? (($linkRow['purpose'] ?? '') === 'setup' ? 'This one-time link is for setting a password on a new account created by an Admin. It is not for recovering an existing account.' : 'This one-time link is for changing the password on your existing account.') : ($step === 'reset' ? 'Recovery is for an existing account. If it has a registered email or phone, recovery instructions may have been sent there.' : 'Recover access to an existing account. Enter your username; instructions may be sent to a registered email or phone.'); ?></p>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-danger login-error" role="alert">
                    <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                    <span><?php echo sanitize($error); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($step === 'email-reset'): ?>
                <form method="POST" action="forgot_password?token=<?php echo rawurlencode($linkToken); ?>" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <input type="hidden" name="token" value="<?php echo sanitize($linkToken); ?>">
                    <div class="login-field">
                        <label for="recoveryPassword">New password</label>
                        <div class="login-input-wrap">
                            <i class="bi bi-lock" aria-hidden="true"></i>
                            <input type="password" id="recoveryPassword" name="password" class="form-control" minlength="<?php echo ADMIN_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required>
                        </div>
                        <span class="form-text">At least <?php echo ADMIN_PASSWORD_MIN_LENGTH; ?> characters.</span>
                    </div>
                    <div class="login-field">
                        <label for="recoveryPasswordConfirmation">Confirm new password</label>
                        <div class="login-input-wrap">
                            <i class="bi bi-lock-fill" aria-hidden="true"></i>
                            <input type="password" id="recoveryPasswordConfirmation" name="password_confirmation" class="form-control" minlength="<?php echo ADMIN_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required>
                        </div>
                    </div>
                    <button type="submit" name="reset_email_password" value="1" class="btn login-submit">
                        <span>Set password</span><i class="bi bi-check2" aria-hidden="true"></i>
                    </button>
                </form>
            <?php elseif (!$smsReady && !$emailReady): ?>
                <div class="alert alert-warning login-recovery-note" role="note">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <div>
                        <p class="mb-0"><strong>Self-service recovery is not available right now.</strong> Contact an Admin and ask them to reset your password from <strong>Account management</strong>.</p>
                    </div>
                </div>
            <?php elseif ($step === 'request'): ?>
                <form method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <div class="login-field">
                        <label for="recoveryUsername">Username</label>
                        <div class="login-input-wrap">
                            <i class="bi bi-person" aria-hidden="true"></i>
                            <input type="text" id="recoveryUsername" name="username" class="form-control" value="<?php echo sanitize($username); ?>" autocomplete="username" required autofocus>
                        </div>
                    </div>
                    <button type="submit" name="request_code" value="1" class="btn login-submit">
                        <span>Send recovery instructions</span><i class="bi bi-send" aria-hidden="true"></i>
                    </button>
                </form>
                <div class="alert alert-info login-recovery-note mt-3" role="note">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <div>
                        <p class="mb-0">This recovery option is only for an existing account. If you cannot access its registered email or phone, ask an Admin to reset the password from <strong>Account management</strong>.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-info login-recovery-note" role="status">
                    <i class="bi bi-chat-dots" aria-hidden="true"></i>
                    <div>
                        <p class="mb-1"><strong>Recovery request received.</strong> If this username belongs to an existing account with a registered recovery email or phone, instructions may have been sent there. Open the link in your email, or enter the 6-digit code if you received one by SMS; a received code expires in <?php echo (int)PASSWORD_RESET_CODE_MINUTES; ?> minutes.</p>
                        <p class="mb-0 small">For privacy, this page cannot confirm whether the username is registered. Nothing arrived? Verify the username or ask an Admin for help.</p>
                    </div>
                </div>
                <form method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <div class="login-field">
                        <label for="recoveryCode">6-digit SMS code (if received)</label>
                        <div class="login-input-wrap">
                            <i class="bi bi-shield-lock" aria-hidden="true"></i>
                            <input type="text" id="recoveryCode" name="code" class="form-control" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus>
                        </div>
                    </div>
                    <div class="login-field">
                        <label for="recoveryPassword">New password</label>
                        <div class="login-input-wrap">
                            <i class="bi bi-lock" aria-hidden="true"></i>
                            <input type="password" id="recoveryPassword"                             name="password" class="form-control" minlength="<?php echo ADMIN_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" aria-describedby="recoveryPasswordHint" required>
                        </div>
                                                    <span id="recoveryPasswordHint" class="form-text">At least <?php echo ADMIN_PASSWORD_MIN_LENGTH; ?> characters.</span>
                    </div>
                    <div class="login-field">
                        <label for="recoveryPasswordConfirmation">Confirm new password</label>
                        <div class="login-input-wrap">
                            <i class="bi bi-lock-fill" aria-hidden="true"></i>
                            <input type="password" id="recoveryPasswordConfirmation" name="password_confirmation" class="form-control" minlength="<?php echo ADMIN_PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required>
                        </div>
                    </div>
                    <button type="submit" name="reset_password" value="1" class="btn login-submit">
                        <span>Change password</span><i class="bi bi-check2" aria-hidden="true"></i>
                    </button>
                </form>
                <p class="login-recovery-link"><a href="forgot_password?restart=1">Use a different account</a></p>
            <?php endif; ?>
            <a class="btn login-submit login-recovery-return mt-3" href="login">
                <span>Return to sign in</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
