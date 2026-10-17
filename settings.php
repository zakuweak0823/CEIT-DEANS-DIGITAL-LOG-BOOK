<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'mobile_form_settings.php';
require_once 'batch_support.php';
ensure_admin();

$formSettingsNotice = '';
$formSettingsNoticeType = 'success';
$formSettingsNoticeScope = 'form';
if (isset($_POST['save_batch_workflow'])) {
    $formSettingsNoticeScope = 'workflow';
    $confirmationMode = $_POST['intake_confirmation_mode'] ?? '';
    $qrMode = $_POST['batch_qr_mode'] ?? '';
    if (!is_string($confirmationMode) || !is_string($qrMode) ||
        !in_array($confirmationMode, ['confirm_before_release', 'confirm_with_release'], true) ||
        !in_array($qrMode, ['local_batch_id', 'tracking_url'], true)) {
        $formSettingsNotice = 'Choose a valid intake confirmation and QR mode.';
        $formSettingsNoticeType = 'danger';
    } elseif (save_batch_workflow_settings($conn, [
        'intake_confirmation_mode' => $confirmationMode,
        'batch_qr_mode' => $qrMode,
    ])) {
        $formSettingsNotice = 'Bulk-paper workflow and QR settings saved.';
    } else {
        $formSettingsNotice = 'Bulk-paper workflow settings could not be saved. Check the server log and try again.';
        $formSettingsNoticeType = 'danger';
    }
} elseif (isset($_POST['save_form_labels'])) {
    $labels = [];
    $submittedLabels = $_POST['form_labels'] ?? [];
    if (!is_array($submittedLabels)) {
        $submittedLabels = [];
    }
    foreach (mobile_form_label_defaults() as $key => $default) {
        if ($key === 'recipientEnabled') {
            $labels[$key] = ($_POST['recipient_enabled'] ?? '') === '1';
            continue;
        }
        $value = $submittedLabels[$key] ?? '';
        if (!is_string($value)) {
            $formSettingsNotice = 'Each form label must contain 1 to 60 characters.';
            $formSettingsNoticeType = 'danger';
            break;
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > 60) {
            $formSettingsNotice = 'Each form label must contain 1 to 60 characters.';
            $formSettingsNoticeType = 'danger';
            break;
        }
        $labels[$key] = $value;
    }

    if ($formSettingsNotice === '') {
        if (save_mobile_form_labels($conn, $labels)) {
            $formSettingsNotice = 'Form labels saved for the admin portal and mobile app.';
        } else {
            $formSettingsNotice = 'Form labels could not be saved. Check the server log and try again.';
            $formSettingsNoticeType = 'danger';
        }
    }
}
$mobileFormLabels = get_mobile_form_labels($conn);
$batchWorkflowSettings = get_batch_workflow_settings($conn);

$localCaCertificateName = 'ceit-logbook-local-ca.crt';
$localCaCertificatePath = __DIR__ . DIRECTORY_SEPARATOR . $localCaCertificateName;
$settingsScriptDirectory = trim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/.');
$localCaCertificateUrl = ($settingsScriptDirectory === '' ? '' : '/' . $settingsScriptDirectory)
    . '/' . rawurlencode($localCaCertificateName);
?>

<?php include 'header.php'; ?>
<div class="row gy-4">
    <div class="col-12">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-3">
            <div>
                <h2 class="mb-1 text-primary">Admin Settings</h2>
                <p class="text-muted mb-0">Personalize your workspace and manage how this browser keeps you informed.</p>
            </div>
            <a class="btn btn-outline-primary" href="notification_settings">
                <i class="bi bi-bell me-1" aria-hidden="true"></i>System notification settings
            </a>
        </div>
    </div>

    <div class="col-12">
        <section class="card shadow-sm" aria-labelledby="form-label-settings-title">
            <div class="card-header bg-white px-3 px-md-4 py-3">
                <h5 class="mb-1 text-primary" id="form-label-settings-title">Shared form labels</h5>
                <p class="mb-0 small text-muted">Customize field names used by document and bulk-paper forms in the admin portal and mobile app.</p>
            </div>
            <div class="card-body p-3 p-md-4">
                <?php if ($formSettingsNotice !== '' && $formSettingsNoticeScope === 'form'): ?>
                    <div class="alert alert-<?php echo sanitize($formSettingsNoticeType); ?>" role="status"><?php echo sanitize($formSettingsNotice); ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="formLabelSender">Sender</label>
                            <input class="form-control" id="formLabelSender" name="form_labels[sender]" maxlength="60" value="<?php echo sanitize($mobileFormLabels['sender']); ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="formLabelRecipient">Recipient</label>
                            <input class="form-control" id="formLabelRecipient" name="form_labels[recipient]" maxlength="60" value="<?php echo sanitize($mobileFormLabels['recipient']); ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="formLabelDocumentType">Document type</label>
                            <input class="form-control" id="formLabelDocumentType" name="form_labels[documentType]" maxlength="60" value="<?php echo sanitize($mobileFormLabels['documentType']); ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="formLabelContactNumber">Contact number</label>
                            <input class="form-control" id="formLabelContactNumber" name="form_labels[contactNumber]" maxlength="60" value="<?php echo sanitize($mobileFormLabels['contactNumber']); ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="formLabelRemarks">Remarks</label>
                            <input class="form-control" id="formLabelRemarks" name="form_labels[remarks]" maxlength="60" value="<?php echo sanitize($mobileFormLabels['remarks']); ?>" required>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="formRecipientEnabled" name="recipient_enabled" value="1" <?php echo $mobileFormLabels['recipientEnabled'] ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="formRecipientEnabled">Show Recipient field in admin and mobile forms</label>
                            </div>
                            <div class="form-text">When off, recipient inputs are hidden and documents save with a blank recipient.</div>
                        </div>
                    </div>
                    <p class="form-text mt-3">Changing a label only changes how the field is named; it does not rename or alter stored records.</p>
                    <button class="btn btn-primary" type="submit" name="save_form_labels" value="1">
                        <i class="bi bi-check2 me-1" aria-hidden="true"></i>Save form labels
                    </button>
                </form>
            </div>
        </section>
    </div>

    <div class="col-12">
        <section class="card shadow-sm" aria-labelledby="batch-workflow-settings-title">
            <div class="card-header bg-white px-3 px-md-4 py-3">
                <h5 class="mb-1 text-primary" id="batch-workflow-settings-title">Bulk-paper intake and QR</h5>
                <p class="mb-0 small text-muted">Choose how Admin records receipt and what each batch QR contains. You can change these options at any time.</p>
            </div>
            <div class="card-body p-3 p-md-4">
                <?php if ($formSettingsNotice !== '' && $formSettingsNoticeScope === 'workflow'): ?>
                    <div class="alert alert-<?php echo sanitize($formSettingsNoticeType); ?>" role="status"><?php echo sanitize($formSettingsNotice); ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <label class="form-label fw-semibold" for="intakeConfirmationMode">When should receipt be confirmed?</label>
                            <select class="form-select" id="intakeConfirmationMode" name="intake_confirmation_mode" required>
                                <option value="confirm_with_release" <?php echo $batchWorkflowSettings['intake_confirmation_mode'] === 'confirm_with_release' ? 'selected' : ''; ?>>Confirm count and move Pending to For Release in one step (recommended)</option>
                                <option value="confirm_before_release" <?php echo $batchWorkflowSettings['intake_confirmation_mode'] === 'confirm_before_release' ? 'selected' : ''; ?>>Confirm receipt first; move Pending to For Release afterwards</option>
                            </select>
                            <div class="form-text">The count form is Admin-only. In either mode, an unexpected count requires a note.</div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <label class="form-label fw-semibold" for="batchQrMode">What should a batch QR contain?</label>
                            <select class="form-select" id="batchQrMode" name="batch_qr_mode" required>
                                <option value="local_batch_id" <?php echo $batchWorkflowSettings['batch_qr_mode'] === 'local_batch_id' ? 'selected' : ''; ?>>Local batch identifier (recommended for localhost)</option>
                                <option value="tracking_url" <?php echo $batchWorkflowSettings['batch_qr_mode'] === 'tracking_url' ? 'selected' : ''; ?>>Public tracking-page link</option>
                            </select>
                            <div class="form-text">A tracking link is reachable only from devices that can connect to this server. A localhost link works only on the server device.</div>
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3" type="submit" name="save_batch_workflow" value="1">
                        <i class="bi bi-check2 me-1" aria-hidden="true"></i>Save bulk-paper settings
                    </button>
                </form>
            </div>
        </section>
    </div>

    <div class="col-12">
        <section class="card shadow-sm" aria-labelledby="workspace-settings-title">
            <div class="card-header bg-white px-3 px-md-4 py-3">
                <div class="d-flex align-items-start gap-3">
                    <span class="rounded-circle bg-primary-subtle text-primary p-2" aria-hidden="true"><i class="bi bi-activity"></i></span>
                    <div>
                        <h5 class="mb-1 text-primary" id="workspace-settings-title">Workspace activity</h5>
                        <p class="mb-0 small text-muted">Control how often this page checks for new logbook records and how it alerts you.</p>
                    </div>
                </div>
            </div>
            <div class="card-body p-3 p-md-4">
                <div class="row g-4">
                    <div class="col-12 col-lg-6">
                        <label class="form-label fw-semibold" for="adminActivityInterval">Check for new records</label>
                        <select class="form-select" id="adminActivityInterval">
                            <option value="15">Every 15 seconds (recommended)</option>
                            <option value="30">Every 30 seconds</option>
                            <option value="60">Every minute (lower network use)</option>
                        </select>
                        <div class="form-text">Checks pause while this tab is hidden or the browser is offline.</div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <p class="form-label fw-semibold mb-2">New-record alerts</p>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="adminSoundAlerts">
                            <label class="form-check-label" for="adminSoundAlerts">Play a sound</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="adminDesktopAlerts">
                            <label class="form-check-label" for="adminDesktopAlerts">Show a desktop alert while this tab is in the background</label>
                        </div>
                        <div class="form-text" id="adminDesktopAlertHelp">Desktop alerts require browser permission and use generic text without record details.</div>
                    </div>
                    <div class="col-12">
                        <hr class="my-0">
                        <p class="form-label fw-semibold mb-2">Workspace shortcuts</p>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="adminPendingBubble">
                            <label class="form-check-label" for="adminPendingBubble">Show the floating pending-documents bubble</label>
                        </div>
                        <div class="form-text">Turn it off to hide the draggable shortcut. Pending documents remain available from the navigation menu.</div>
                        <div class="form-check form-switch mt-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="adminAssistantEnabled">
                            <label class="form-check-label" for="adminAssistantEnabled">Show the side-peeking Admin Assistant</label>
                        </div>
                        <div class="form-text">When enabled, a small clickable tab stays at the screen edge. Turn it off to hide the assistant completely.</div>
                    </div>
                    <div class="col-12">
                        <div class="d-flex align-items-center justify-content-between gap-3">
                            <div class="form-check form-switch admin-compact-mode-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="adminCompactMode">
                            <label class="form-check-label" for="adminCompactMode">Compact Mode (mobile-style workspace)</label>
                            </div>
                            <span class="admin-compact-mode-state" id="settingsCompactModeStatus" role="status">Off</span>
                        </div>
                        <div class="form-text">Switches the entire admin portal to a mobile-style layout with bottom navigation, stacked content, and card-based records—even on a desktop.</div>
                    </div>
                </div>
                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mt-4 pt-3 border-top">
                    <p class="small text-muted mb-0" id="adminPreferencesStatus" role="status" aria-live="polite">Preferences are saved in this browser only.</p>
                    <button class="btn btn-sm btn-outline-secondary" type="button" id="resetAdminPreferences">
                        <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Restore defaults
                    </button>
                </div>
            </div>
        </section>
    </div>

    <div class="col-12">
        <section class="card shadow-sm" aria-labelledby="system-settings-title">
            <div class="card-header bg-white px-3 px-md-4 py-3">
                <h5 class="mb-1 text-primary" id="system-settings-title">System configuration</h5>
                <p class="mb-0 small text-muted">Manage settings that apply across the logbook, mobile app, and notification services.</p>
            </div>
            <div class="card-body p-3 p-md-4 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                <div>
                    <strong>SMS, email, and mobile office access</strong>
                    <p class="small text-muted mb-0">Configure delivery providers, sender details, and mobile kiosk availability.</p>
                </div>
                <a class="btn btn-primary flex-shrink-0" href="notification_settings">Open notification settings <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></a>
            </div>
        </section>
    </div>

    <div class="col-12">
        <section class="card shadow-sm" aria-labelledby="mobile-camera-certificate-title">
            <div class="card-header bg-white px-3 px-md-4 py-3">
                <h5 class="mb-1 text-primary" id="mobile-camera-certificate-title">Mobile camera certificate</h5>
                <p class="mb-0 small text-muted">Download the public certificate needed to trust this local development server on a phone.</p>
            </div>
            <div class="card-body p-3 p-md-4 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                <div>
                    <strong>Enable secure QR scanning on your phone</strong>
                    <p class="small text-muted mb-0">Install this certificate on your phone as a trusted CA, then open the logbook using this computer's HTTPS address. The private key is not included.</p>
                </div>
                <?php if (is_file($localCaCertificatePath)): ?>
                    <a class="btn btn-primary flex-shrink-0"
                        href="<?php echo htmlspecialchars($localCaCertificateUrl, ENT_QUOTES, 'UTF-8'); ?>"
                        download="<?php echo htmlspecialchars($localCaCertificateName, ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="bi bi-download me-1" aria-hidden="true"></i>Download certificate
                    </a>
                <?php else: ?>
                    <p class="small text-warning mb-0" role="status">The local development certificate is not installed on this server.</p>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<script>
(function () {
    const storageKey = 'ceit-admin-preferences-v1';
    const defaults = { activityInterval: 15, soundAlerts: true, desktopAlerts: false, pendingBubble: true, assistantEnabled: true, compactMode: false };
    const intervalControl = document.getElementById('adminActivityInterval');
    const soundControl = document.getElementById('adminSoundAlerts');
    const desktopControl = document.getElementById('adminDesktopAlerts');
    const desktopHelp = document.getElementById('adminDesktopAlertHelp');
    const pendingBubbleControl = document.getElementById('adminPendingBubble');
    const assistantEnabledControl = document.getElementById('adminAssistantEnabled');
    const compactModeControl = document.getElementById('adminCompactMode');
    const compactModeStatus = document.getElementById('settingsCompactModeStatus');
    const status = document.getElementById('adminPreferencesStatus');
    const resetButton = document.getElementById('resetAdminPreferences');
    if (!intervalControl || !soundControl || !desktopControl || !desktopHelp || !pendingBubbleControl || !assistantEnabledControl || !compactModeControl || !status || !resetButton) return;

    let storageAvailable = true;
    const readPreferences = function () {
        try {
            const saved = JSON.parse(window.localStorage.getItem(storageKey) || '{}');
            return {
                activityInterval: [15, 30, 60].includes(Number(saved.activityInterval)) ? Number(saved.activityInterval) : defaults.activityInterval,
                soundAlerts: typeof saved.soundAlerts === 'boolean' ? saved.soundAlerts : defaults.soundAlerts,
                desktopAlerts: saved.desktopAlerts === true,
                pendingBubble: saved.pendingBubble !== false,
                assistantEnabled: saved.assistantEnabled !== false,
                compactMode: saved.compactMode === true
            };
        } catch (error) {
            storageAvailable = false;
            return Object.assign({}, defaults);
        }
    };

    let preferences = readPreferences();
    const renderPreferences = function () {
        intervalControl.value = String(preferences.activityInterval);
        soundControl.checked = preferences.soundAlerts;
        const notificationsSupported = 'Notification' in window;
        desktopControl.disabled = !notificationsSupported || Notification.permission === 'denied';
        desktopControl.checked = notificationsSupported && Notification.permission === 'granted' && preferences.desktopAlerts;
        pendingBubbleControl.checked = preferences.pendingBubble;
        assistantEnabledControl.checked = preferences.assistantEnabled;
        compactModeControl.checked = preferences.compactMode;
        compactModeStatus.textContent = preferences.compactMode ? 'On' : 'Off';
        compactModeStatus.classList.toggle('is-on', preferences.compactMode);
        if (!notificationsSupported) {
            desktopHelp.textContent = 'This browser does not support desktop notifications.';
        } else if (Notification.permission === 'denied') {
            desktopHelp.textContent = 'Notifications are blocked by your browser. Allow them in site settings to enable desktop alerts.';
        } else if (Notification.permission === 'default') {
            desktopHelp.textContent = 'Your browser will ask for permission when you turn desktop alerts on. Alerts use generic text and do not include record details.';
        } else {
            desktopHelp.textContent = 'Desktop alerts appear only when this tab is in the background and never include record details.';
        }
    };

    const savePreferences = function (message) {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(preferences));
            status.textContent = message || 'Saved in this browser. Live monitoring has been updated.';
            window.dispatchEvent(new CustomEvent('adminpreferenceschange', { detail: preferences }));
        } catch (error) {
            storageAvailable = false;
            status.textContent = 'Could not save preferences in this browser. Check its storage settings.';
        }
        renderPreferences();
    };

    intervalControl.addEventListener('change', function () {
        preferences.activityInterval = Number(intervalControl.value);
        savePreferences();
    });
    soundControl.addEventListener('change', function () {
        preferences.soundAlerts = soundControl.checked;
        savePreferences();
    });
    desktopControl.addEventListener('change', async function () {
        if (!desktopControl.checked) {
            preferences.desktopAlerts = false;
            savePreferences();
            return;
        }
        try {
            const permission = Notification.permission === 'default'
                ? await Notification.requestPermission()
                : Notification.permission;
            preferences.desktopAlerts = permission === 'granted';
            const message = !preferences.desktopAlerts
                ? permission === 'denied'
                    ? 'Desktop alerts were blocked. Change notification permission in your browser settings.'
                    : 'Desktop alerts were not enabled.'
                : undefined;
            savePreferences(message);
        } catch (error) {
            preferences.desktopAlerts = false;
            savePreferences('Your browser could not enable desktop alerts.');
            renderPreferences();
        }
    });
    pendingBubbleControl.addEventListener('change', function () {
        preferences.pendingBubble = pendingBubbleControl.checked;
        savePreferences();
    });
    assistantEnabledControl.addEventListener('change', function () {
        preferences.assistantEnabled = assistantEnabledControl.checked;
        savePreferences(preferences.assistantEnabled
            ? 'Admin Assistant enabled. Its side tab is ready to open.'
            : 'Admin Assistant hidden.');
    });
    compactModeControl.addEventListener('change', function () {
        preferences.compactMode = compactModeControl.checked;
        savePreferences();
    });
    resetButton.addEventListener('click', function () {
        preferences = Object.assign({}, defaults);
        savePreferences('Default workspace preferences restored.');
    });
    window.addEventListener('storage', function (event) {
        if (event.key !== storageKey) return;
        preferences = readPreferences();
        status.textContent = 'Preferences updated from another tab.';
        renderPreferences();
    });
    window.addEventListener('adminpreferenceschange', function (event) {
        if (!event.detail || typeof event.detail !== 'object') return;
        preferences = Object.assign({}, preferences, event.detail);
        renderPreferences();
    });
    renderPreferences();
    if (!storageAvailable) {
        status.textContent = 'Browser storage is unavailable. Defaults are active but preferences cannot be saved.';
    }
}());
</script>
<?php include 'footer.php'; ?>
