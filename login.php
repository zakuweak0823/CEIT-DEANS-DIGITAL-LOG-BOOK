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
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="card-title mb-4 text-primary">Admin Login</h2>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo sanitize($error); ?></div>
                <?php endif; ?>
                <form method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" id="username" name="username" class="form-control" value="<?php echo sanitize($username); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" id="password" name="password" class="form-control" required>
                    </div>
                    <button type="submit" name="login" class="btn btn-primary w-100">Login</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
