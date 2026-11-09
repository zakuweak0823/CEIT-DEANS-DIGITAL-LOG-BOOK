<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
ensure_admin();
header('Cache-Control: no-store');
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="row gy-4">
    <div class="col-12">
        <h2 class="mb-0 text-primary">Communication Services</h2>
    </div>
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body p-3 p-md-4">
                <h5 class="card-title text-primary">SMS and email</h5>
                <p>Contact the server administrator to configure PhilSMS or change SMS availability. Provider credentials and settings cannot be edited in this portal.</p>
                <p class="mb-0 text-muted">SMTP email delivery is configured in the protected <code>config/smtp.php</code> file, outside the portal.</p>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
