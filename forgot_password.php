<?php
require_once 'auth.php';
if (!empty($_SESSION['admin'])) {
    header('Location: dashboard');
    exit;
}
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
                <p class="login-intro">Use the recovery steps for your administrator account.</p>
            </div>
            <div class="alert alert-info login-recovery-note" role="note">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <div>
                    <p class="mb-2"><strong>Standard administrator:</strong> Contact your Super Admin and ask them to reset your password from <strong>Administrator Accounts</strong> in the admin portal.</p>
                    <p class="mb-0"><strong>Super Admin:</strong> Contact the trusted system administrator for account recovery. Super Admin passwords cannot be reset from this page.</p>
                </div>
            </div>
            <p class="login-recovery-link">Password reset emails are not configured for this system.</p>
            <a class="btn login-submit login-recovery-return" href="login">
                <span>Return to sign in</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</section>
<?php include 'footer.php'; ?>
