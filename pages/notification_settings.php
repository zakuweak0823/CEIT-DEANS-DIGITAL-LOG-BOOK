<?php
require_once 'auth.php';
require_once 'db.php';
ensure_admin();

$message = '';

if (isset($_POST['save_settings'])) {
    $sms_enabled = isset($_POST['sms_enabled']) ? 1 : 0;
    $email_enabled = isset($_POST['email_enabled']) ? 1 : 0;
    $email_host = trim($_POST['email_host'] ?? '');
    $email_port = trim($_POST['email_port'] ?? '');
    $email_username = trim($_POST['email_username'] ?? '');
    $email_password = trim($_POST['email_password'] ?? '');
    $email_from = trim($_POST['email_from'] ?? '');
    $philsms_api_token = trim((string)preg_replace('/^Bearer\s+/i', '', trim($_POST['philsms_api_token'] ?? '')));
    $philsms_sender_id = trim($_POST['philsms_sender_id'] ?? 'PhilSMS');

    if ($email_password === '') {
        // A blank password field means "leave the saved credential unchanged".
        $email_password = (string)safe_scalar($conn, "SELECT value FROM settings WHERE key_name = 'email_password'");
    }

    $portValid = $email_port === '' || (ctype_digit($email_port) && (int)$email_port >= 1 && (int)$email_port <= 65535);
    if (!$portValid || ($email_enabled && ($email_host === '' || !filter_var($email_username, FILTER_VALIDATE_EMAIL) || !filter_var($email_from, FILTER_VALIDATE_EMAIL)))) {
        $message = show_alert('Please check the SMTP port and required email settings.', 'danger');
    } else {

    if ($philsms_sender_id === '' || strlen($philsms_sender_id) > 11) {
        $message = show_alert('Enter the sender ID approved for your PhilSMS account (up to 11 characters).', 'danger');
    }

    // Keep the PhilSMS token out of the upsert when its field is blank, preserving the saved token.
    if ($message === '') {
        $settingsData = [
            'sms_enabled' => $sms_enabled,
            'email_enabled' => $email_enabled,
            'email_host' => $email_host,
            'email_port' => $email_port,
            'email_username' => $email_username,
            'email_password' => $email_password,
            'email_from' => $email_from,
            'philsms_sender_id' => $philsms_sender_id
        ];
        if ($philsms_api_token !== '') {
            $settingsData['philsms_api_token'] = $philsms_api_token;
        }

        $saveFailed = false;
        foreach ($settingsData as $key => $value) {
            $stmt = safe_query($conn, "INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?", 'sss', [$key, $value, $value]);
            if ($stmt !== false) {
                mysqli_stmt_close($stmt);
            } else {
                $saveFailed = true;
            }
        }

        $message = $saveFailed
            ? show_alert('Some settings could not be saved. Please check the database logs.', 'danger')
            : show_alert('Settings saved successfully.');
    }
    }
}

// Load current settings
$settings = [];
$stmt = safe_query($conn, "SELECT key_name, value FROM settings");
if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $settings[$row['key_name']] = $row['value'];
    }
    mysqli_stmt_close($stmt);
}

$sms_enabled = $settings['sms_enabled'] ?? 0;
$email_enabled = $settings['email_enabled'] ?? 0;
$email_host = $settings['email_host'] ?? '';
$email_port = $settings['email_port'] ?? '587';
$email_username = $settings['email_username'] ?? '';
$email_password = $settings['email_password'] ?? '';
$email_from = $settings['email_from'] ?? '';
$has_saved_philsms_token = !empty($settings['philsms_api_token']);
$philsms_api_token_in_use = $has_saved_philsms_token ? (string)$settings['philsms_api_token'] : (string)(getenv('PHILSMS_API_TOKEN') ?: '');
$philsms_api_token_in_use = trim((string)preg_replace('/^Bearer\s+/i', '', trim($philsms_api_token_in_use)));
$philsms_token_source = $philsms_api_token_in_use === ''
    ? 'Not configured'
    : ($has_saved_philsms_token ? 'Application settings' : 'Server environment');
$philsms_sender_id = trim((string)($settings['philsms_sender_id'] ?? ''));
if ($philsms_sender_id === '') {
    $philsms_sender_id = trim((string)(getenv('PHILSMS_SENDER_ID') ?: 'PhilSMS'));
}
?>

<?php include 'header.php'; ?>
<div class="row gy-4">
    <div class="col-12">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-3">
            <div>
                <h2 class="mb-1 text-primary">Communication Settings</h2>
                <p class="text-muted mb-0">Configure SMS and email delivery for the logbook.</p>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="col-12"><?php echo $message; ?></div>
    <?php endif; ?>

    <div class="col-12">
        <form method="POST" class="card shadow-sm">
            <div class="card-header bg-white px-3 px-md-4 py-3">
                <h5 class="mb-1 text-primary">Messaging Configuration</h5>
                <p class="mb-0 small text-muted">Configure the communication services used by the logbook.</p>
            </div>
            <div class="card-body p-3 p-md-4">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <h5 class="card-title text-primary mb-4">SMS Delivery</h5>
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="sms_enabled" id="sms_enabled" <?php echo $sms_enabled ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="sms_enabled">
                                    Enable SMS notifications
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label" for="sms_api_provider">SMS API Provider</label>
                            <input type="text" id="sms_api_provider" class="form-control" value="PhilSMS" readonly>
                            <div class="form-text">API endpoint: <code class="text-break">https://dashboard.philsms.com/api/v3/sms/send</code></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="philsms_api_token_current">PhilSMS API token currently in use</label>
                            <div class="input-group">
                                <input type="password" id="philsms_api_token_current" class="form-control" value="<?php echo sanitize($philsms_api_token_in_use); ?>" readonly autocomplete="off" placeholder="No PhilSMS token configured">
                                <button class="btn btn-outline-secondary" type="button" data-toggle-password="sms_api_key_current" data-password-label="API key" aria-label="Show API key" aria-pressed="false">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <div class="form-text">Source: <?php echo sanitize($philsms_token_source); ?>. Existing Semaphore credentials are not used.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="philsms_api_token">PhilSMS API token</label>
                            <div class="input-group">
                                <input type="password" id="philsms_api_token" name="philsms_api_token" class="form-control" value="" autocomplete="new-password" placeholder="<?php echo $philsms_api_token_in_use !== '' ? 'Leave blank to keep current token' : 'Enter PhilSMS API token'; ?>">
                                <button class="btn btn-outline-secondary" type="button" data-toggle-password="sms_api_key" data-password-label="new API key" aria-label="Show new API key" aria-pressed="false">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <div class="form-text"><?php echo $philsms_api_token_in_use !== '' ? 'Leave blank to keep the current PhilSMS token.' : 'Required for sending SMS. Obtain it from the PhilSMS Developers dashboard.'; ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="philsms_sender_id">Approved sender ID</label>
                            <input type="text" id="philsms_sender_id" name="philsms_sender_id" class="form-control" value="<?php echo sanitize($philsms_sender_id); ?>" maxlength="11" required>
                            <div class="form-text">Use a sender ID enabled for your PhilSMS account (maximum 11 characters).</div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <h5 class="card-title text-primary mb-4">Email Delivery</h5>
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="email_enabled" id="email_enabled" <?php echo $email_enabled ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="email_enabled">
                                    Enable email notifications
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">SMTP Host</label>
                            <input type="text" name="email_host" class="form-control" value="<?php echo sanitize($email_host); ?>" placeholder="smtp.gmail.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">SMTP Port</label>
                            <input type="number" name="email_port" class="form-control" value="<?php echo sanitize($email_port); ?>" placeholder="587">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="email" name="email_username" class="form-control" value="<?php echo sanitize($email_username); ?>" placeholder="your-email@gmail.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="password" name="email_password" class="form-control" value="" autocomplete="new-password" placeholder="<?php echo $email_password !== '' ? 'Configured; leave blank to keep' : 'App password'; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">From Email</label>
                            <input type="email" name="email_from" class="form-control" value="<?php echo sanitize($email_from); ?>" placeholder="noreply@cvsu.edu.ph">
                        </div>
                    </div>

            </div>
            <div class="card-footer d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2 bg-white p-3 p-md-4">
                <div class="small text-muted" id="emailSetupStatus" role="status" aria-live="polite">Email validation checks configuration only; it does not send a test message.</div>
                <div class="d-flex flex-column flex-sm-row gap-2">
                    <button type="button" class="btn btn-outline-primary" onclick="testEmail()">Validate Email Settings</button>
                    <button type="submit" name="save_settings" class="btn btn-primary"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Save Settings</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function testEmail() {
    const host = document.querySelector('[name="email_host"]').value.trim();
    const port = document.querySelector('[name="email_port"]').value.trim();
    const username = document.querySelector('[name="email_username"]').value.trim();
    const from = document.querySelector('[name="email_from"]').value.trim();
    const emailEnabled = document.getElementById('email_enabled').checked;
    const messages = [];
    if (!emailEnabled) messages.push('Email notifications are disabled.');
    if (!host) messages.push('SMTP host is required.');
    if (!/^\d+$/.test(port) || Number(port) < 1 || Number(port) > 65535) messages.push('Enter a valid SMTP port.');
    if (!/^\S+@\S+\.\S+$/.test(username)) messages.push('Enter a valid SMTP username email.');
    if (!/^\S+@\S+\.\S+$/.test(from)) messages.push('Enter a valid sender email.');
    const status = document.getElementById('emailSetupStatus');
    status.textContent = messages.length
        ? messages.join(' ')
        : 'Email settings look complete. No test email was sent.';
    status.classList.toggle('text-danger', messages.length > 0);
    status.classList.toggle('text-success', messages.length === 0);
}
</script>

<script>
document.querySelectorAll('[data-toggle-password]').forEach(function(button) {
    button.addEventListener('click', function() {
        const input = button.closest('.input-group')?.querySelector('input');
        const icon = button.querySelector('i');
        if (!input || !icon) return;
        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        button.setAttribute('aria-label', (isHidden ? 'Hide ' : 'Show ') + button.dataset.passwordLabel);
        button.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
        icon.classList.toggle('bi-eye', !isHidden);
        icon.classList.toggle('bi-eye-slash', isHidden);
    });
});
</script>

<?php include 'footer.php'; ?>