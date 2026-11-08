<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/login_support.php';
verify_csrf_request();

if (!empty($_SESSION['admin'])) {
    header('Location: dashboard');
    exit;
}

$error = '';
$username = '';
$notice = (string)($_SESSION['login_notice'] ?? '');
unset($_SESSION['login_notice']);
if (isset($_POST['login'])) {
    $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $password = $_POST['password'] ?? '';

    if ($username === '' || !is_string($password) || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        try {
            $login = authenticate_admin_login($conn, $username, $password);
            if ($login['status'] === 'success') {
                session_regenerate_id(true);
                $_SESSION['admin'] = $login['user']['username'];
                $_SESSION['admin_role'] = $login['user']['role'];
                $_SESSION['admin_password_hash'] = $login['user']['password'];
                $_SESSION['admin_login_arrival'] = true;
                header('Location: dashboard');
                exit;
            }
            if ($login['status'] === 'locked') {
                http_response_code(429);
                $error = 'Too many failed sign-in attempts. Please wait 15 minutes after the fifth attempt and try again.';
            } else {
                $error = 'Invalid username or password.';
            }
        } catch (Throwable $exception) {
            error_log('Administrator login failed: ' . $exception->getMessage());
            http_response_code(503);
            $error = 'Sign-in is temporarily unavailable. Please try again later.';
        }
    }
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<section class="login-shell" aria-labelledby="loginTitle">
    <div class="login-panel">
        <div class="login-form-wrap">
            <div class="login-card-brand">
                <img src="assets/images/CVSU.jpeg" alt="CVSU Logo" class="login-card-logo">
                <span>
                    <strong>CEIT Dean's Office</strong>
                    <small>Digital Logbook</small>
                </span>
            </div>
            <div class="login-heading">
                <h1 id="loginTitle">Administrator Login</h1>
                <p class="login-intro">Use your authorized account to access the logbook.</p>
            </div>
            <?php if ($notice !== '' && !$error): ?>
                <div class="alert alert-success d-flex align-items-center gap-2" role="status">
                    <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                    <span><?php echo sanitize($notice); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger login-error" role="alert">
                    <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                    <span><?php echo sanitize($error); ?></span>
                </div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                <div class="login-field">
                    <label for="username">Username</label>
                    <div class="login-input-wrap">
                        <i class="bi bi-person" aria-hidden="true"></i>
                        <input type="text" id="username" name="username" class="form-control" value="<?php echo sanitize($username); ?>" autocomplete="username" required autofocus>
                    </div>
                </div>
                <div class="login-field">
                    <label for="password">Password</label>
                    <div class="login-input-wrap">
                        <i class="bi bi-lock" aria-hidden="true"></i>
                        <input type="password" id="password" name="password" class="form-control" autocomplete="current-password" aria-describedby="passwordVisibilityHint" required>
                        <button class="login-password-toggle" type="button" aria-label="Show password" aria-controls="password" aria-pressed="false" title="Show password">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <span id="passwordVisibilityHint" class="visually-hidden">Use the show password button to reveal or hide your password.</span>
                </div>
                <button type="submit" name="login" class="btn login-submit">
                    <span>Sign In</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
                </button>
            </form>
            <p class="login-recovery-link"><a href="forgot_password">Forgot your password?</a></p>
        </div>
    </div>
</section>
<script src="assets/js/page-login.js?v=<?php echo (int)@filemtime(dirname(__DIR__) . '/assets/js/page-login.js'); ?>"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
