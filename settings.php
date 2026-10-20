<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'mobile_form_settings.php';
require_once 'mobile_app_settings.php';
require_once 'batch_support.php';
require_once 'document_tracking.php';
ensure_admin();

try {
    ensure_document_tracking_schema($conn);
    $documentTrackingSettings = get_document_tracking_settings($conn);
} catch (Throwable $error) {
    error_log('Document tracking setup failed in Admin Settings: ' . $error->getMessage());
    http_response_code(500);
    exit('Document tracking settings are unavailable. Check the server log and try again.');
}

$formSettingsNotice = '';
$formSettingsNoticeType = 'success';
$formSettingsNoticeScope = 'form';
if (isset($_POST['save_mobile_access'])) {
    $formSettingsNoticeScope = 'mobile-access';
    $mobileKioskEnabled = isset($_POST['mobile_kiosk_enabled']) ? '1' : '0';
    $stmt = safe_query($conn, "INSERT INTO settings (key_name, value) VALUES ('mobile_kiosk_enabled', ?) ON DUPLICATE KEY UPDATE value = ?", 'ss', [$mobileKioskEnabled, $mobileKioskEnabled]);
    if ($stmt !== false) {
        mysqli_stmt_close($stmt);
        $formSettingsNotice = 'Mobile logbook access settings saved.';
    } else {
        $formSettingsNotice = 'Mobile logbook access settings could not be saved. Check the server log and try again.';
        $formSettingsNoticeType = 'danger';
    }
} elseif (isset($_POST['save_document_tracking_settings'])) {
    $formSettingsNoticeScope = 'document-tracking';
    $documentTrackingEnabled = isset($_POST['document_tracking_enabled']) ? '1' : '0';
    $trackerBaseUrlInput = $_POST['document_tracker_base_url'] ?? '';
    $normalizedTrackerBaseUrl = normalize_document_tracker_base_url($trackerBaseUrlInput);
    if ($normalizedTrackerBaseUrl === null) {
        $formSettingsNotice = 'Enter a valid HTTP or HTTPS tracker base URL without credentials, query parameters, or fragments.';
        $formSettingsNoticeType = 'danger';
    } else {
        $conn->begin_transaction();
        $saveFailed = false;
        foreach ([
            'document_tracking_enabled' => $documentTrackingEnabled,
            'document_tracker_base_url' => $normalizedTrackerBaseUrl,
        ] as $key => $value) {
            $stmt = safe_query($conn, 'INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?', 'sss', [$key, $value, $value]);
            if ($stmt === false) {
                $saveFailed = true;
                break;
            }
            mysqli_stmt_close($stmt);
        }
        if ($saveFailed) {
            $conn->rollback();
            $formSettingsNotice = 'Document tracking settings could not be saved. Check the server log and try again.';
            $formSettingsNoticeType = 'danger';
        } else {
            $conn->commit();
            $formSettingsNotice = 'Document tracking settings saved.';
        }
    }
} elseif (isset($_POST['save_batch_workflow'])) {
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
        if ($key === 'senderEnabled') {
            $labels[$key] = ($_POST['sender_enabled'] ?? '') === '1';
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
} elseif (isset($_POST['save_mobile_app_settings'])) {
    $formSettingsNoticeScope = 'mobile-app';
    $theme = $_POST['mobile_theme'] ?? '';
    $submittedSettings = [
        'theme' => $theme,
        'headerTitle' => $_POST['mobile_header_title'] ?? null,
        'officeName' => $_POST['mobile_office_name'] ?? null,
        'welcomeHeading' => $_POST['mobile_welcome_heading'] ?? null,
        'welcomeMessage' => $_POST['mobile_welcome_message'] ?? null,
        'confirmationSound' => ($_POST['mobile_confirmation_sound'] ?? '') === '1',
        'pullToRefreshSound' => ($_POST['mobile_refresh_sound'] ?? '') === '1',
    ];
    $limits = ['headerTitle' => 48, 'officeName' => 48, 'welcomeHeading' => 90, 'welcomeMessage' => 180];
    $valid = is_string($theme) && in_array($theme, ['classic', 'glass'], true);
    foreach ($limits as $key => $limit) {
        $value = $submittedSettings[$key];
        if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $limit) {
            $valid = false;
            break;
        }
        $submittedSettings[$key] = trim($value);
    }

    if (!$valid) {
        $formSettingsNotice = 'Choose a valid mobile theme and enter each display text within its character limit.';
        $formSettingsNoticeType = 'danger';
    } elseif (save_mobile_app_settings($conn, $submittedSettings)) {
        $formSettingsNotice = 'Mobile app settings saved and will apply to connected devices.';
    } else {
        $formSettingsNotice = 'Mobile app settings could not be saved. Check the server log and try again.';
        $formSettingsNoticeType = 'danger';
    }
}
$mobileFormLabels = get_mobile_form_labels($conn);
$mobileAppSettings = get_mobile_app_settings($conn);
$batchWorkflowSettings = get_batch_workflow_settings($conn);
try {
    $documentTrackingSettings = get_document_tracking_settings($conn);
} catch (Throwable $error) {
    error_log('Document tracking settings could not be refreshed: ' . $error->getMessage());
    http_response_code(500);
    exit('Document tracking settings could not be loaded. Check the server log and try again.');
}
$mobileKioskSettingStmt = safe_query($conn, "SELECT value FROM settings WHERE key_name = 'mobile_kiosk_enabled' LIMIT 1");
if ($mobileKioskSettingStmt === false) {
    http_response_code(500);
    exit('Mobile logbook access settings are unavailable. Check the server log and try again.');
}
$mobileKioskSetting = mysqli_stmt_get_result($mobileKioskSettingStmt);
if ($mobileKioskSetting === false) {
    mysqli_stmt_close($mobileKioskSettingStmt);
    http_response_code(500);
    exit('Mobile logbook access settings could not be read. Check the server log and try again.');
}
$mobileKioskSettingRow = mysqli_fetch_assoc($mobileKioskSetting);
mysqli_stmt_close($mobileKioskSettingStmt);
if ($mobileKioskSettingRow === false) {
    http_response_code(500);
    exit('Mobile logbook access settings could not be read. Check the server log and try again.');
}
$mobileKioskEnabled = $mobileKioskSettingRow === null || (string)$mobileKioskSettingRow['value'] === '1';

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
                <p class="text-muted mb-0">Personalize your workspace and control mobile logbook access.</p>
            </div>
            <a class="btn btn-outline-primary" href="notification_settings">
                <i class="bi bi-chat-dots me-1" aria-hidden="true"></i>Communication Settings
            </a>
        </div>
    </div>

    <div class="col-12">
        <section class="card shadow-sm" id="mobile-access-settings" aria-labelledby="mobile-access-settings-title">
            <div class="card-header bg-white px-3 px-md-4 py-3">
                <h5 class="mb-1 text-primary" id="mobile-access-settings-title">Mobile logbook access</h5>
                <p class="mb-0 small text-muted">Control whether the mobile logbook can be used without an admin sign-in.</p>
            </div>
            <div class="card-body p-3 p-md-4">
                <?php if ($formSettingsNotice !== '' && $formSettingsNoticeScope === 'mobile-access'): ?>
                    <div class="alert alert-<?php echo sanitize($formSettingsNoticeType); ?>" role="status"><?php echo sanitize($formSettingsNotice); ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="mobile_kiosk_enabled" id="mobile_kiosk_enabled" value="1" <?php echo $mobileKioskEnabled ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-semibold" for="mobile_kiosk_enabled">Enable mobile logbook access without sign-in</label>
                    </div>
                    <div class="alert alert-warning mt-3" role="note">
                        When enabled, anyone who can reach this server can view and change logbook records through the mobile app. Use only on a trusted office device and network, not on public or shared networks.
                    </div>
                    <button class="btn btn-primary" type="submit" name="save_mobile_access" value="1">
                        <i class="bi bi-check2 me-1" aria-hidden="true"></i>Save mobile access
                    </button>
                </form>
            </div>
        </section>
    </div>

    <div class="col-12">
        <section class="card shadow-sm" id="document-tracking-settings" aria-labelledby="document-tracking-settings-title">
            <div class="card-header bg-white px-3 px-md-4 py-3">
                <h5 class="mb-1 text-primary" id="document-tracking-settings-title">Document status tracking</h5>
                <p class="mb-0 small text-muted">Issue QR tracking links for incoming documents and control public status lookups.</p>
            </div>
            <div class="card-body p-3 p-md-4">
                <?php if ($formSettingsNotice !== '' && $formSettingsNoticeScope === 'document-tracking'): ?>
                    <div class="alert alert-<?php echo sanitize($formSettingsNoticeType); ?>" role="status"><?php echo sanitize($formSettingsNotice); ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" name="document_tracking_enabled" id="document_tracking_enabled" value="1" <?php echo $documentTrackingSettings['enabled'] ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-semibold" for="document_tracking_enabled">Enable QR document tracking</label>
                    </div>
                    <label class="form-label fw-semibold" for="document_tracker_base_url">Tracker base URL <span class="fw-normal text-muted">(optional)</span></label>
                    <input class="form-control" type="url" name="document_tracker_base_url" id="document_tracker_base_url" maxlength="255" value="<?php echo sanitize($documentTrackingSettings['configuredBaseUrl']); ?>" placeholder="https://logbook.example.edu/CEIT-DEANS-DIGITAL-LOG-BOOK">
                    <div class="form-text mb-3">This is the base address encoded into tracking QR codes, not the Vite mobile-app address. For phones, use the office computer's Apache HTTPS URL and project path (for example, https://192.168.1.20/CEIT-DEANS-DIGITAL-LOG-BOOK); do not add :5173. Leave blank to use this server's current request address.</div>
                    <div class="alert alert-info" role="note">
                        Phone camera scanning requires trusted HTTPS. Start the mobile UI with npm run dev:lan and open its HTTPS LAN address on the phone; install and trust the office certificate first. “localhost” on a phone means the phone itself.
                    </div>
                    <button class="btn btn-primary" type="submit" name="save_document_tracking_settings" value="1">
                        <i class="bi bi-check2 me-1" aria-hidden="true"></i>Save tracking settings
                    </button>
                </form>
            </div>
        </section>
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
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch" id="formSenderEnabled" name="sender_enabled" value="1" <?php echo $mobileFormLabels['senderEnabled'] ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="formSenderEnabled">Show and require the Sender (“From”) field in mobile document and Bulk Papers forms</label>
                            </div>
                            <div class="form-text mb-3">When off, the field is hidden and mobile submissions save a blank sender.</div>
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
        <section class="card shadow-sm" aria-labelledby="mobile-app-settings-title">
            <div class="card-header bg-white px-3 px-md-4 py-3">
                <h5 class="mb-1 text-primary" id="mobile-app-settings-title">Mobile app appearance and experience</h5>
                <p class="mb-0 small text-muted">Configure the shared kiosk look, welcome text, and optional sounds for phones and browsers.</p>
            </div>
            <div class="card-body p-3 p-md-4">
                <?php if ($formSettingsNotice !== '' && $formSettingsNoticeScope === 'mobile-app'): ?>
                    <div class="alert alert-<?php echo sanitize($formSettingsNoticeType); ?>" role="status"><?php echo sanitize($formSettingsNotice); ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="mobileTheme">Mobile app theme</label>
                            <select class="form-select" id="mobileTheme" name="mobile_theme" required>
                                <option value="classic" <?php echo $mobileAppSettings['theme'] === 'classic' ? 'selected' : ''; ?>>Classic — current mobile UI (default)</option>
                                <option value="glass" <?php echo $mobileAppSettings['theme'] === 'glass' ? 'selected' : ''; ?>>Glass — translucent green panels</option>
                            </select>
                            <div class="form-text">This theme applies to all mobile kiosk users. Classic remains the default until changed here.</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="mobileHeaderTitle">Header title</label>
                            <input class="form-control" id="mobileHeaderTitle" name="mobile_header_title" maxlength="48" value="<?php echo sanitize($mobileAppSettings['headerTitle']); ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="mobileOfficeName">Office name</label>
                            <input class="form-control" id="mobileOfficeName" name="mobile_office_name" maxlength="48" value="<?php echo sanitize($mobileAppSettings['officeName']); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="mobileWelcomeHeading">Welcome heading</label>
                            <input class="form-control" id="mobileWelcomeHeading" name="mobile_welcome_heading" maxlength="90" value="<?php echo sanitize($mobileAppSettings['welcomeHeading']); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="mobileWelcomeMessage">Welcome message</label>
                            <textarea class="form-control" id="mobileWelcomeMessage" name="mobile_welcome_message" maxlength="180" rows="2" required><?php echo sanitize($mobileAppSettings['welcomeMessage']); ?></textarea>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="mobileConfirmationSound" name="mobile_confirmation_sound" value="1" <?php echo $mobileAppSettings['confirmationSound'] ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="mobileConfirmationSound">Play sound after a successful action</label>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="mobileRefreshSound" name="mobile_refresh_sound" value="1" <?php echo $mobileAppSettings['pullToRefreshSound'] ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="mobileRefreshSound">Play sound when pull-to-refresh starts</label>
                            </div>
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3" type="submit" name="save_mobile_app_settings" value="1">
                        <i class="bi bi-check2 me-1" aria-hidden="true"></i>Save mobile app settings
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
                        <fieldset class="admin-theme-picker">
                            <legend class="form-label fw-semibold mb-1">Admin appearance</legend>
                            <p class="form-text mt-0 mb-3">Choose the workspace style used across Admin pages, including Compact Mode. Your choice is saved in this browser.</p>
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="admin-theme-option" for="adminThemeDefault">
                                        <input class="form-check-input" type="radio" name="adminTheme" id="adminThemeDefault" value="default">
                                        <span class="admin-theme-preview admin-theme-preview-default" aria-hidden="true">
                                            <span></span><span></span><span></span><i></i>
                                        </span>
                                        <span class="admin-theme-option-copy">
                                            <strong>Classic</strong>
                                            <small>Current Admin design · Default</small>
                                        </span>
                                    </label>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="admin-theme-option" for="adminThemeCivic">
                                        <input class="form-check-input" type="radio" name="adminTheme" id="adminThemeCivic" value="civic">
                                        <span class="admin-theme-preview admin-theme-preview-civic" aria-hidden="true">
                                            <span></span><span></span><span></span><i></i>
                                        </span>
                                        <span class="admin-theme-option-copy">
                                            <strong>Civic Canvas</strong>
                                            <small>Editorial-style navigation · rich green and warm canvas</small>
                                        </span>
                                    </label>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="admin-theme-option" for="adminThemeGlass">
                                        <input class="form-check-input" type="radio" name="adminTheme" id="adminThemeGlass" value="glass">
                                        <span class="admin-theme-preview admin-theme-preview-glass" aria-hidden="true">
                                            <span></span><span></span><span></span><i></i>
                                        </span>
                                        <span class="admin-theme-option-copy">
                                            <strong>Prism Glass</strong>
                                            <small>Transparent glass · CvSU greens · soft sage accents</small>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </fieldset>
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
                <h5 class="mb-1 text-primary" id="system-settings-title">Communication services</h5>
                <p class="mb-0 small text-muted">Manage the SMS and email services used by the logbook.</p>
            </div>
            <div class="card-body p-3 p-md-4 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                <div>
                    <strong>SMS and email delivery</strong>
                    <p class="small text-muted mb-0">Configure messaging providers and sender details.</p>
                </div>
                <a class="btn btn-primary flex-shrink-0" href="notification_settings">Open Communication Settings <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></a>
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
    const defaults = { activityInterval: 15, soundAlerts: true, desktopAlerts: false, pendingBubble: true, assistantEnabled: true, compactMode: false, adminTheme: 'default' };
    const intervalControl = document.getElementById('adminActivityInterval');
    const soundControl = document.getElementById('adminSoundAlerts');
    const desktopControl = document.getElementById('adminDesktopAlerts');
    const desktopHelp = document.getElementById('adminDesktopAlertHelp');
    const pendingBubbleControl = document.getElementById('adminPendingBubble');
    const assistantEnabledControl = document.getElementById('adminAssistantEnabled');
    const compactModeControl = document.getElementById('adminCompactMode');
    const compactModeStatus = document.getElementById('settingsCompactModeStatus');
    const themeControls = Array.from(document.querySelectorAll('input[name="adminTheme"]'));
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
                compactMode: saved.compactMode === true,
                adminTheme: ['civic', 'glass'].includes(saved.adminTheme) ? saved.adminTheme : defaults.adminTheme
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
        themeControls.forEach(function (control) {
            control.checked = control.value === preferences.adminTheme;
        });
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
    themeControls.forEach(function (control) {
        control.addEventListener('change', function () {
            if (!control.checked) return;
            preferences.adminTheme = ['civic', 'glass'].includes(control.value) ? control.value : 'default';
            savePreferences(preferences.adminTheme === 'civic'
                ? 'Civic Canvas applied across the Admin workspace.'
                : preferences.adminTheme === 'glass'
                    ? 'Prism Glass applied across the Admin workspace.'
                    : 'Classic Admin appearance restored.');
        });
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
