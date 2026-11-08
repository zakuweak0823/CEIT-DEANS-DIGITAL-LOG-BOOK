<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'password_reset_support.php';

if (!empty($_SESSION['admin'])) {
    header('Location: dashboard');
    exit;
}

$setupOk = ensure_password_reset_schema($conn);
$smsReady = $setupOk && password_reset_sms_available($conn);
$error = '';
$info = '';
$username = '';
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
    } elseif (!$smsReady) {
        $error = 'SMS recovery is not available right now. Please contact your Super Admin.';
    } elseif (isset($_POST['request_code'])) {
        $username = trim((string)($_POST['username'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $username) !== 1) {
            $error = 'Enter your administrator username.';
        } else {
            $state = password_reset_throttle_state($conn, $username);
            if ($state === 'limited') {
                $error = 'Too many recovery requests. Please try again in an hour or contact your Super Admin.';
            } elseif ($state === 'cooldown') {
                $error = 'Please wait a minute before requesting another code.';
            } else {
                if (random_int(1, 20) === 1) {
                    password_reset_purge($conn);
                }
                $issued = password_reset_issue($conn, $username);
                $_SESSION['pw_reset'] = ['username' => $username, 'at' => time()];
                // The reply is identical whether or not the account can receive a code.
                password_reset_finish_response('forgot_password');
                if ($issued !== null) {
                    password_reset_send_code($conn, $issued['phone'], $issued['code']);
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
$step = is_array($pending) ? 'reset' : 'request';
?>
<?php include 'header.php'; ?>
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
                <h1 id="recoveryTitle">Password recovery</h1>
                <p class="login-intro"><?php echo $step === 'reset' ? 'Enter the code we sent and choose a new password.' : 'We will text a one-time code to your registered recovery phone.'; ?></p>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-danger login-error" role="alert">
                    <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                    <span><?php echo sanitize($error); ?></span>
                </div>
            <?php endif; ?>
            <?php if (!$smsReady): ?>
                <div class="alert alert-warning login-recovery-note" role="note">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <div>
                        <p class="mb-0"><strong>SMS recovery is not available right now.</strong> Contact your Super Admin and ask them to reset your password from <strong>Administrator accounts</strong>. A Super Admin who is locked out needs the server owner to run <code>tools/reset_admin_password.php</code>.</p>
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
                        <span>Send recovery code</span><i class="bi bi-send" aria-hidden="true"></i>
                    </button>
                </form>
                <div class="alert alert-info login-recovery-note mt-3" role="note">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <div>
                        <p class="mb-0">No recovery phone on your account? Ask your Super Admin to add one or to reset your password from <strong>Administrator accounts</strong>.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-info login-recovery-note" role="status">
                    <i class="bi bi-chat-dots" aria-hidden="true"></i>
                    <div>
                        <p class="mb-1">If the account <strong><?php echo sanitize((string)$pending['username']); ?></strong> has a recovery phone, a 6-digit code was just sent to it. It expires in <?php echo (int)PASSWORD_RESET_CODE_MINUTES; ?> minutes.</p>
                        <p class="mb-0 small">Nothing arrived? Wait a minute, then <a href="forgot_password?restart=1">request a new code</a>.</p>
                    </div>
                </div>
                <form method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <div class="login-field">
                        <label for="recoveryCode">6-digit code</label>
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
<?php include 'footer.php'; ?>
