<?php
require_once 'auth.php';
require_once 'db.php';
ensure_admin();
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
