(function () {
    document.addEventListener('DOMContentLoaded', function () {
        const infoButtons = document.querySelectorAll('[data-settings-info]');
        if (typeof bootstrap === 'undefined' || !bootstrap.Popover) {
            console.error('Settings information controls could not be initialized.');
            infoButtons.forEach(function (button) {
                const text = document.createElement('span');
                text.className = 'small text-muted';
                text.textContent = button.dataset.settingsInfo;
                button.replaceWith(text);
            });
            return;
        }
        infoButtons.forEach(function (button) {
            const popover = new bootstrap.Popover(button, {
                container: 'body',
                content: button.dataset.settingsInfo,
                customClass: 'admin-status-popover',
                placement: 'auto',
                trigger: 'hover focus'
            });
            button.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    popover.hide();
                    button.blur();
                }
            });
        });
    });

    const trackerBaseUrl = document.getElementById('document_tracker_base_url');
    const useCurrentTrackerAddress = document.getElementById('useCurrentTrackerAddress');
    if (trackerBaseUrl && useCurrentTrackerAddress) {
        useCurrentTrackerAddress.addEventListener('click', function () {
            const baseAddress = new URL('.', window.location.href);
            trackerBaseUrl.value = baseAddress.origin + baseAddress.pathname.replace(/\/+$/, '');
            trackerBaseUrl.focus();
        });
    }

    const storageKey = 'ceit-admin-preferences-v1';
    const defaults = { activityInterval: 15, soundAlerts: true, desktopAlerts: false, submissionToast: true, assistantEnabled: true, compactMode: false, adminTheme: 'default' };
    const intervalControl = document.getElementById('adminActivityInterval');
    const soundControl = document.getElementById('adminSoundAlerts');
    const desktopControl = document.getElementById('adminDesktopAlerts');
    const desktopHelp = document.getElementById('adminDesktopAlertHelp');
    const submissionToastControl = document.getElementById('adminSubmissionToast');
    const assistantEnabledControl = document.getElementById('adminAssistantEnabled');
    const compactModeControl = document.getElementById('adminCompactMode');
    const compactModeStatus = document.getElementById('settingsCompactModeStatus');
    const themeControls = Array.from(document.querySelectorAll('input[name="adminTheme"]'));
    const status = document.getElementById('adminPreferencesStatus');
    const resetButton = document.getElementById('resetAdminPreferences');
    if (!intervalControl || !soundControl || !desktopControl || !desktopHelp || !submissionToastControl || !assistantEnabledControl || !compactModeControl || !status || !resetButton) return;

    let storageAvailable = true;
    const readPreferences = function () {
        try {
            const saved = JSON.parse(window.localStorage.getItem(storageKey) || '{}');
            return {
                activityInterval: [15, 30, 60].includes(Number(saved.activityInterval)) ? Number(saved.activityInterval) : defaults.activityInterval,
                soundAlerts: typeof saved.soundAlerts === 'boolean' ? saved.soundAlerts : defaults.soundAlerts,
                desktopAlerts: saved.desktopAlerts === true,
                submissionToast: typeof saved.submissionToast === 'boolean' ? saved.submissionToast : saved.pendingBubble !== false,
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
        submissionToastControl.checked = preferences.submissionToast;
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
    submissionToastControl.addEventListener('change', function () {
        preferences.submissionToast = submissionToastControl.checked;
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
