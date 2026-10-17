<?php
require_once 'auth.php';
require_once 'db.php';
verify_csrf_request();

if (!empty($_SESSION['admin'])) {
    header('Location: dashboard');
    exit;
}

$error = '';
$username = '';
if (isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE username = ?");
        if ($stmt === false) {
            $error = 'Unable to prepare login query.';
        } else {
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);

            if (mysqli_stmt_num_rows($stmt) === 1) {
                $hash = '';
                mysqli_stmt_bind_result($stmt, $hash);
                mysqli_stmt_fetch($stmt);

                // Successful login regenerates the session ID to prevent fixation.
                if ($hash !== '' && password_verify($password, $hash)) {
                    session_regenerate_id(true);
                    $_SESSION['admin'] = $username;
                    $_SESSION['admin_login_arrival'] = true;
                    header('Location: dashboard');
                    exit;
                }

                // Upgrade legacy plaintext credentials on successful use; new logins use hashes.
                if ($password === $hash) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $update = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE username = ?");
                    if ($update !== false) {
                        mysqli_stmt_bind_param($update, 'ss', $newHash, $username);
                        mysqli_stmt_execute($update);
                        mysqli_stmt_close($update);
                    }

                    session_regenerate_id(true);
                    $_SESSION['admin'] = $username;
                    $_SESSION['admin_login_arrival'] = true;
                    header('Location: dashboard');
                    exit;
                }
            } else {
                $error = 'Invalid login credentials.';
            }

            mysqli_stmt_close($stmt);
        }
    }
}
?>
<?php include 'header.php'; ?>
<section class="login-shell" aria-labelledby="loginTitle">
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
                <p class="login-eyebrow">ADMINISTRATOR SIGN IN</p>
                <h1 id="loginTitle">Welcome back</h1>
                <p class="login-intro">Enter your account details to continue.</p>
            </div>
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
                        <input type="password" id="password" name="password" class="form-control" autocomplete="current-password" required>
                    </div>
                </div>
                <button type="submit" name="login" class="btn login-submit">
                    <span>Sign in to Admin</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
                </button>
            </form>
            <p class="login-security-note"><i class="bi bi-shield-lock" aria-hidden="true"></i> Authorized administrators only</p>
        </div>
    </div>
</section>
<?php include 'footer.php'; ?>
