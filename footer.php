</main>
<?php if ($adminName): ?>
    <footer class="admin-footer" role="contentinfo">
        <div class="admin-footer-inner">
            <div class="admin-footer-brand">
                <span class="admin-footer-mark" aria-hidden="true"><i class="bi bi-journal-check"></i></span>
                <span class="admin-footer-title" title="Cavite State University - College of Engineering and Information Technology Dean's Office Digital Logbook">&copy; <?php echo date('Y'); ?> Cavite State University <span aria-hidden="true">&middot;</span> CEIT Dean's Office</span>
            </div>
            <div class="admin-footer-status" data-network-status role="status" aria-live="polite" aria-atomic="true">
                <span class="admin-footer-status-indicator" aria-hidden="true"><i class="bi bi-wifi"></i></span>
                <span data-network-status-label>Checking...</span>
            </div>
        </div>
    </footer>
    </div>
</div>
<?php else: ?>
<footer class="bg-white border-top py-3">
    <div class="container text-center small text-muted">
        &copy; <?php echo date('Y'); ?> Cavite State University - CEIT Deans Office Digital Logbook. Admin web interface only.
    </div>
</footer>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let adminServerUnavailable = false;
let adminServerAvailabilityHandler = null;
let adminDocumentActivityCheck = null;
const updateAdminNetworkStatus = function () {
    const networkStatus = document.querySelector('[data-network-status]');
    const networkStatusLabel = networkStatus && networkStatus.querySelector('[data-network-status-label]');
    if (!networkStatus || !networkStatusLabel) return;

    const state = !navigator.onLine ? 'offline' : (adminServerUnavailable ? 'server-disconnected' : 'online');
    const labels = {
        online: 'Online',
        offline: 'Offline',
        'server-disconnected': 'Server unavailable'
    };
    const descriptions = {
        online: 'Your browser and the office server are reachable.',
        offline: 'Your browser reports that it is offline.',
        'server-disconnected': 'Your browser is online, but the office server is not responding.'
    };
    networkStatus.dataset.state = state;
    networkStatusLabel.textContent = labels[state];
    networkStatus.title = descriptions[state];
};

if (document.body.classList.contains('admin-body')) {
    updateAdminNetworkStatus();
    window.addEventListener('online', updateAdminNetworkStatus);
    window.addEventListener('offline', updateAdminNetworkStatus);

    const compactModeStorageKey = 'ceit-admin-preferences-v1';
    const compactModeToggle = document.getElementById('adminCompactModeToggle');
    const applyCompactMode = function (enabled) {
        document.body.classList.toggle('admin-compact', enabled);
        if (compactModeToggle) {
            compactModeToggle.checked = enabled;
            compactModeToggle.setAttribute('aria-checked', String(enabled));
        }
    };
    const readCompactMode = function () {
        try {
            const preferences = JSON.parse(window.localStorage.getItem(compactModeStorageKey) || '{}');
            return preferences.compactMode === true;
        } catch (error) {
            console.warn('Compact mode preference could not be read.', error);
            return false;
        }
    };
    applyCompactMode(readCompactMode());
    if (compactModeToggle) {
        compactModeToggle.addEventListener('change', function () {
            try {
                const preferences = JSON.parse(window.localStorage.getItem(compactModeStorageKey) || '{}');
                preferences.compactMode = compactModeToggle.checked;
                window.localStorage.setItem(compactModeStorageKey, JSON.stringify(preferences));
                applyCompactMode(preferences.compactMode);
                window.dispatchEvent(new CustomEvent('adminpreferenceschange', { detail: preferences }));
            } catch (error) {
                console.error('Compact mode preference could not be saved.', error);
                compactModeToggle.checked = document.body.classList.contains('admin-compact');
            }
        });
    }
    window.addEventListener('storage', function (event) {
        if (event.key === compactModeStorageKey) applyCompactMode(readCompactMode());
    });
    window.addEventListener('adminpreferenceschange', function (event) {
        if (event.detail && typeof event.detail.compactMode === 'boolean') {
            applyCompactMode(event.detail.compactMode);
        }
    });

    const submissionLogToggle = document.getElementById('mobileSubmissionLogToggle');
    if (submissionLogToggle) {
        const csrfToken = submissionLogToggle.dataset.csrfToken;
        let submissionLogEnabled = false;
        let submissionLogRequestInProgress = false;
        submissionLogToggle.disabled = document.body.classList.contains('admin-compact');
        const renderSubmissionLogButton = function () {
            submissionLogToggle.setAttribute('aria-pressed', String(submissionLogEnabled));
            submissionLogToggle.innerHTML = submissionLogEnabled
                ? '<i class="bi bi-pause-circle me-2" aria-hidden="true"></i><span>Pause CMD log</span>'
                : '<i class="bi bi-terminal me-2" aria-hidden="true"></i><span>Start CMD log</span>';
        };
        const requestSubmissionLogState = async function (enabled) {
            if (submissionLogRequestInProgress) return;
            if (enabled === true && document.body.classList.contains('admin-compact')) return;
            submissionLogRequestInProgress = true;
            let stopAfterStatusCheck = false;
            submissionLogToggle.disabled = true;
            try {
                const options = {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store',
                };
                if (typeof enabled === 'boolean') {
                    const formData = new FormData();
                    formData.set('enabled', enabled ? '1' : '0');
                    formData.set('csrf_token', csrfToken);
                    options.method = 'POST';
                    options.body = formData;
                }
                const response = await fetch('dashboard.php?mobile_console=1', options);
                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Could not update the CMD log.');
                }
                submissionLogEnabled = data.enabled;
                renderSubmissionLogButton();
                stopAfterStatusCheck = typeof enabled !== 'boolean'
                    && data.enabled
                    && document.body.classList.contains('admin-compact');
            } catch (error) {
                window.alert(error.message);
            } finally {
                submissionLogToggle.disabled = document.body.classList.contains('admin-compact');
                submissionLogRequestInProgress = false;
            }
            if (stopAfterStatusCheck || (document.body.classList.contains('admin-compact') && submissionLogEnabled)) {
                requestSubmissionLogState(false);
            }
        };

        submissionLogToggle.addEventListener('click', function () {
            requestSubmissionLogState(!submissionLogEnabled);
        });
        const stopCommandLogInCompactMode = function () {
            const compact = document.body.classList.contains('admin-compact');
            submissionLogToggle.disabled = compact || submissionLogRequestInProgress;
            if (compact && submissionLogEnabled) requestSubmissionLogState(false);
        };
        window.addEventListener('adminpreferenceschange', stopCommandLogInCompactMode);
        window.addEventListener('storage', function (event) {
            if (event.key === compactModeStorageKey) stopCommandLogInCompactMode();
        });
        requestSubmissionLogState();
    }
}

if (document.body.classList.contains('admin-body')) {
    const latestDocumentAttribute = Number(document.body.dataset.latestDocumentId);
    let latestObservedDocumentId = Number.isSafeInteger(latestDocumentAttribute) ? latestDocumentAttribute : 0;
    let hasDocumentActivityBaseline = document.body.dataset.documentActivityReady === 'true';
    const adminPreferenceStorageKey = 'ceit-admin-preferences-v1';
    const defaultAdminPreferences = { activityInterval: 15, soundAlerts: true, desktopAlerts: false, pendingBubble: true, compactMode: false };
    const readAdminPreferences = function () {
        try {
            const saved = JSON.parse(window.localStorage.getItem(adminPreferenceStorageKey) || '{}');
            return {
                activityInterval: [15, 30, 60].includes(Number(saved.activityInterval)) ? Number(saved.activityInterval) : defaultAdminPreferences.activityInterval,
                soundAlerts: typeof saved.soundAlerts === 'boolean' ? saved.soundAlerts : defaultAdminPreferences.soundAlerts,
                desktopAlerts: saved.desktopAlerts === true,
                pendingBubble: saved.pendingBubble !== false,
                compactMode: saved.compactMode === true
            };
        } catch (error) {
            console.warn('Admin preferences could not be read; using default monitoring settings.', error);
            return Object.assign({}, defaultAdminPreferences);
        }
    };
    let adminPreferences = readAdminPreferences();
    let pendingNotificationTone = false;
    let notificationCheckInProgress = false;
    let documentActivityIntervalId = 0;
    const notificationTone = new Audio(new URL('tone/notif_tone.mp3', window.location.href).href);
    notificationTone.preload = 'auto';

    const playAdminNotificationTone = function () {
        if (!adminPreferences.soundAlerts) return;
        notificationTone.currentTime = 0;
        notificationTone.play().then(function () {
            pendingNotificationTone = false;
        }).catch(function (error) {
            pendingNotificationTone = true;
            console.warn('New-document notification tone is waiting for browser audio permission.', error);
        });
    };

    const notifyAdminOfNewDocument = function () {
        if (adminPreferences.soundAlerts) playAdminNotificationTone();
        if (!adminPreferences.desktopAlerts || !document.hidden || !('Notification' in window) || Notification.permission !== 'granted') return;
        try {
            const notification = new Notification('New logbook activity', {
                body: 'A new record was added. Open the admin portal to review it.',
                icon: new URL('CVSU.jpeg', window.location.href).href,
                tag: 'ceit-new-logbook-record'
            });
            notification.addEventListener('click', function () {
                window.focus();
                window.location.assign(new URL('records', window.location.href));
                notification.close();
            }, { once: true });
        } catch (error) {
            console.warn('The browser could not display a desktop document alert.', error);
        }
    };

    const checkForNewDocuments = async function () {
        if (notificationCheckInProgress || document.hidden || !navigator.onLine) return;
        notificationCheckInProgress = true;
        try {
            const activityUrl = new URL('admin_document_activity.php', window.location.href);
            const response = await fetch(activityUrl, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            if (response.status === 401) {
                throw new Error('Your admin session has expired. Sign in again to resume monitoring.');
            }
            if (!response.ok) throw new Error('Document activity check returned HTTP ' + response.status);
            const activity = await response.json();
            if (!activity.success || !Number.isSafeInteger(Number(activity.latestDocumentId))) {
                throw new Error(activity.message || 'Document activity response was invalid');
            }
            if (adminServerAvailabilityHandler) adminServerAvailabilityHandler(true);
            const latestDocumentId = Number(activity.latestDocumentId);
            if (!hasDocumentActivityBaseline) {
                latestObservedDocumentId = latestDocumentId;
                hasDocumentActivityBaseline = true;
                return;
            }
            if (latestDocumentId > latestObservedDocumentId) {
                latestObservedDocumentId = latestDocumentId;
                notifyAdminOfNewDocument();
            }
        } catch (error) {
            console.warn('New-document activity check failed; it will retry automatically.', error);
            if (!String(error.message || '').includes('session has expired')) {
                if (adminServerAvailabilityHandler) adminServerAvailabilityHandler(false);
            }
        } finally {
            notificationCheckInProgress = false;
        }
    };

    const scheduleDocumentActivityChecks = function () {
        window.clearInterval(documentActivityIntervalId);
        documentActivityIntervalId = window.setInterval(checkForNewDocuments, adminPreferences.activityInterval * 1000);
    };
    const applyAdminPreferences = function (preferences) {
        if (preferences && typeof preferences === 'object') {
            adminPreferences = {
                activityInterval: [15, 30, 60].includes(Number(preferences.activityInterval)) ? Number(preferences.activityInterval) : defaultAdminPreferences.activityInterval,
                soundAlerts: typeof preferences.soundAlerts === 'boolean' ? preferences.soundAlerts : defaultAdminPreferences.soundAlerts,
                desktopAlerts: preferences.desktopAlerts === true,
                pendingBubble: preferences.pendingBubble !== false,
                compactMode: preferences.compactMode === true
            };
        } else {
            adminPreferences = readAdminPreferences();
        }
        if (!adminPreferences.soundAlerts) pendingNotificationTone = false;
        const pendingShortcut = document.querySelector('.pending-documents-float');
        if (pendingShortcut) {
            pendingShortcut.hidden = !adminPreferences.pendingBubble;
            pendingShortcut.setAttribute('aria-hidden', String(!adminPreferences.pendingBubble));
        }
        scheduleDocumentActivityChecks();
    };

    adminDocumentActivityCheck = checkForNewDocuments;
    scheduleDocumentActivityChecks();
    window.addEventListener('adminpreferenceschange', function (event) {
        applyAdminPreferences(event.detail);
    });
    window.addEventListener('storage', function (event) {
        if (event.key === adminPreferenceStorageKey) applyAdminPreferences();
    });
    window.setTimeout(checkForNewDocuments, 1000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) checkForNewDocuments();
    });
    window.addEventListener('online', checkForNewDocuments);
    document.addEventListener('pointerdown', function () {
        if (pendingNotificationTone) playAdminNotificationTone();
    }, { capture: true });
    document.addEventListener('keydown', function () {
        if (pendingNotificationTone) playAdminNotificationTone();
    }, { capture: true });

    applyAdminPreferences();
    const pendingShortcut = document.querySelector('.pending-documents-float');
    if (pendingShortcut) {
        const dragHandle = pendingShortcut.querySelector('[data-pending-drag-handle]');
        const margin = 8;
        let pointerDrag = null;
        let suppressShortcutClick = false;
        let clearSuppressedClickTimer = 0;

        const setShortcutPosition = function (left, top) {
            const bounds = pendingShortcut.getBoundingClientRect();
            const maxLeft = Math.max(margin, window.innerWidth - bounds.width - margin);
            const maxTop = Math.max(margin, window.innerHeight - bounds.height - margin);
            const safeLeft = Math.min(Math.max(left, margin), maxLeft);
            const safeTop = Math.min(Math.max(top, margin), maxTop);
            const rightSpace = window.innerWidth - safeLeft - bounds.width - margin;
            const leftSpace = safeLeft + bounds.width - margin;
            const expandRight = rightSpace >= leftSpace;
            const availableSpace = Math.max(bounds.width, expandRight ? rightSpace : leftSpace);

            pendingShortcut.style.left = safeLeft + 'px';
            pendingShortcut.style.top = safeTop + 'px';
            pendingShortcut.style.right = 'auto';
            pendingShortcut.style.bottom = 'auto';
            pendingShortcut.style.setProperty('--pending-expanded-width', Math.min(240, availableSpace) + 'px');
            pendingShortcut.classList.toggle('pending-documents-expand-right', expandRight);
        };

        const initialBounds = pendingShortcut.getBoundingClientRect();
        setShortcutPosition(initialBounds.left, initialBounds.top);

        dragHandle.addEventListener('pointerdown', function (event) {
            if (event.button !== 0 || !event.isPrimary) return;
            const bounds = pendingShortcut.getBoundingClientRect();
            pointerDrag = {
                pointerId: event.pointerId,
                startX: event.clientX,
                startY: event.clientY,
                left: bounds.left,
                top: bounds.top,
                moved: false
            };
            dragHandle.setPointerCapture(event.pointerId);
            event.preventDefault();
        });

        dragHandle.addEventListener('pointermove', function (event) {
            if (!pointerDrag || event.pointerId !== pointerDrag.pointerId) return;
            const deltaX = event.clientX - pointerDrag.startX;
            const deltaY = event.clientY - pointerDrag.startY;
            if (!pointerDrag.moved && Math.hypot(deltaX, deltaY) < 5) return;
            pointerDrag.moved = true;
            pendingShortcut.classList.add('is-dragging');
            setShortcutPosition(pointerDrag.left + deltaX, pointerDrag.top + deltaY);
        });

        const finishShortcutDrag = function (event) {
            if (!pointerDrag || event.pointerId !== pointerDrag.pointerId) return;
            if (pointerDrag.moved) {
                suppressShortcutClick = true;
                window.clearTimeout(clearSuppressedClickTimer);
                clearSuppressedClickTimer = window.setTimeout(function () {
                    suppressShortcutClick = false;
                }, 1000);
            }
            pointerDrag = null;
            pendingShortcut.classList.remove('is-dragging');
        };
        dragHandle.addEventListener('pointerup', finishShortcutDrag);
        dragHandle.addEventListener('pointercancel', finishShortcutDrag);

        pendingShortcut.addEventListener('click', function (event) {
            if (
                event.defaultPrevented ||
                event.button !== 0 ||
                event.metaKey ||
                event.ctrlKey ||
                event.shiftKey ||
                event.altKey ||
                pendingShortcut.target === '_blank' ||
                window.matchMedia('(prefers-reduced-motion: reduce)').matches
            ) return;

            event.preventDefault();
            pendingShortcut.classList.add('is-navigating');
            window.setTimeout(function () {
                window.location.assign(pendingShortcut.href);
            }, 180);
        });

        pendingShortcut.addEventListener('keydown', function (event) {
            const step = event.shiftKey ? 48 : 16;
            const moves = {
                ArrowUp: [0, -step],
                ArrowDown: [0, step],
                ArrowLeft: [-step, 0],
                ArrowRight: [step, 0]
            };
            if (!moves[event.key]) return;
            event.preventDefault();
            const bounds = pendingShortcut.getBoundingClientRect();
            setShortcutPosition(bounds.left + moves[event.key][0], bounds.top + moves[event.key][1]);
        });

        document.addEventListener('click', function (event) {
            if (!suppressShortcutClick) return;
            if (!(event.target instanceof Element) || !event.target.closest('.pending-documents-float')) return;
            event.preventDefault();
            event.stopImmediatePropagation();
        }, true);

        window.addEventListener('resize', function () {
            const bounds = pendingShortcut.getBoundingClientRect();
            setShortcutPosition(bounds.left, bounds.top);
        });
    }

    // Add accessible mobile labels from the table headers without changing desktop markup.
    document.querySelectorAll('.admin-content .table-responsive table').forEach(function (table) {
        if (document.body.classList.contains('admin-compact') && table.closest('.batch-history-table')) return;
        if (!document.body.classList.contains('admin-compact') && table.closest('.d-none, .d-md-block')) return;
        const headerRow = table.tHead && table.tHead.rows.length ? table.tHead.rows[0] : null;
        if (!headerRow) return;

        const labels = Array.from(headerRow.cells).map(function (cell) {
            return cell.textContent.trim().replace(/\s+/g, ' ');
        });
        if (!labels.some(Boolean)) return;

        table.classList.add('admin-mobile-card-table');
        const wrapper = table.closest('.table-responsive');
        if (wrapper) wrapper.classList.add('admin-mobile-card-wrap');

        Array.from(table.tBodies).forEach(function (body) {
            Array.from(body.rows).forEach(function (row) {
                Array.from(row.cells).forEach(function (cell, index) {
                    if (cell.hasAttribute('colspan') || cell.dataset.label || !labels[index]) return;
                    cell.dataset.label = labels[index];
                });
            });
        });
    });

    const assistant = document.querySelector('.admin-assistant');
    if (assistant) {
        const launcher = assistant.querySelector('.admin-assistant-launcher');
        const panel = assistant.querySelector('.admin-assistant-panel');
        const closeButton = assistant.querySelector('.admin-assistant-close');
        const messages = assistant.querySelector('.admin-assistant-messages');
        const form = assistant.querySelector('.admin-assistant-form');
        const input = assistant.querySelector('#adminAssistantInput');
        const sendButton = assistant.querySelector('[data-assistant-send]');

        if (launcher && panel && closeButton && messages && form && input && sendButton) {
            const panelTransitionDuration = 190;
            const answers = [
                {
                    match: /\b(pending|awaiting|inbox)\b/i,
                    text: 'Open Pending Inbox to review incoming documents that still need attention. Select a record to view its details and available actions.',
                    links: [{ label: 'Open Pending Inbox', href: 'incoming?status=Pending' }]
                },
                {
                    match: /\b(register|new document|add document|submit document|quick submit)\b/i,
                    text: 'Use Quick Submit Records for the guided submission flow. If you already know it is an incoming document, use Add incoming on the dashboard.',
                    links: [
                        { label: 'Quick Submit Records', href: 'quick_document' },
                        { label: 'Incoming Documents', href: 'incoming' }
                    ]
                },
                {
                    match: /\b(outgoing|release|released|recipient)\b/i,
                    text: 'Open Outgoing Documents to review outgoing records and their release status. Use the record actions there to continue the workflow.',
                    links: [{ label: 'Outgoing Documents', href: 'outgoing' }]
                },
                {
                    match: /\b(batch|bulk paper|tracking qr|batch qr)\b/i,
                    text: 'Batch Papers is for registering and managing groups of papers. Open Batch Tracker when you need to check a tracking link or QR status.',
                    links: [
                        { label: 'Batch Papers', href: 'batch_papers' },
                        { label: 'Batch Tracker', href: 'batch_tracker' }
                    ]
                },
                {
                    match: /\b(borrow|borrowed|return|overdue|item)\b/i,
                    text: 'Use Borrowed Items to check current loans, due dates, and return actions. Item Categories lets you manage the inventory categories.',
                    links: [
                        { label: 'Borrowed Items', href: 'borrowed' },
                        { label: 'Item Categories', href: 'item_categories' }
                    ]
                },
                {
                    match: /\b(report|analytics|statistics|summary)\b/i,
                    text: 'Open Reports to generate document reports. The More menu also has dedicated Borrowed Items and SMS report shortcuts.',
                    links: [{ label: 'Open Reports', href: 'reports' }]
                },
                {
                    match: /\b(search|find record|all records|document records)\b/i,
                    text: 'Use Document Records to search across the logbook. You can also search documents from the search box in the standard workspace.',
                    links: [{ label: 'Search Document Records', href: 'records' }]
                },
                {
                    match: /\b(notification|sms|email|alert|sound|desktop)\b/i,
                    text: 'Admin Settings controls this browser’s activity checks and alerts. Notification Settings is for system-wide messaging configuration and SMS logs.',
                    links: [
                        { label: 'Admin Settings', href: 'settings' },
                        { label: 'Notification Settings', href: 'notification_settings' },
                        { label: 'SMS Logs', href: 'sms' }
                    ]
                },
                {
                    match: /\b(compact|quick mode|mobile mode|bottom menu)\b/i,
                    text: 'Compact Mode is available from your administrator menu or Admin Settings. It uses a mobile-style layout, a short bottom dock, and a More sheet for the other sections.',
                    links: [{ label: 'Admin Settings', href: 'settings' }]
                },
                {
                    match: /\b(phone|mobile access|access.*phone|phone.*access)\b/i,
                    text: 'For phone access, the phone and server must be on a network that can reach the web server. Ask your network administrator to configure secure access; do not expose the development server directly to the public internet.',
                    links: [{ label: 'Admin Settings', href: 'settings' }]
                },
                {
                    match: /\b(server|offline|disconnected|database|connection)\b/i,
                    text: 'If the office server is unavailable, check that Apache and MySQL are running on the host. The recovery screen retries automatically; confirm any unsaved form before retrying.',
                    links: [{ label: 'Dashboard', href: 'dashboard' }]
                },
                {
                    match: /\b(help|what can you do|how do i use)\b/i,
                    text: 'I can guide you to logbook pages and explain common admin workflows. Try asking about pending documents, registering a record, batches, borrowed items, reports, settings, or server recovery.',
                    links: []
                }
            ];

            const setOpen = function (open) {
                if (open) {
                    window.clearTimeout(panel.hideTimer);
                    positionPanel();
                    panel.hidden = false;
                    window.requestAnimationFrame(function () {
                        panel.classList.add('is-open');
                    });
                } else {
                    panel.classList.remove('is-open');
                    panel.hideTimer = window.setTimeout(function () {
                        panel.hidden = true;
                    }, panelTransitionDuration);
                }
                launcher.setAttribute('aria-expanded', String(open));
                launcher.setAttribute('aria-label', open ? 'Close Admin Assistant' : 'Open Admin Assistant');
                if (open) input.focus();
                else window.setTimeout(function () { launcher.focus(); }, panelTransitionDuration);
            };
            const addMessage = function (text, isAssistant, links) {
                const message = document.createElement('div');
                message.className = 'admin-assistant-message' + (isAssistant ? ' is-assistant' : ' is-user');
                const paragraph = document.createElement('p');
                paragraph.textContent = text;
                message.appendChild(paragraph);
                if (links && links.length) {
                    const actions = document.createElement('div');
                    actions.className = 'admin-assistant-actions';
                    links.forEach(function (link) {
                        const anchor = document.createElement('a');
                        anchor.href = link.href;
                        anchor.textContent = link.label;
                        actions.appendChild(anchor);
                    });
                    message.appendChild(actions);
                }
                messages.appendChild(message);
                messages.scrollTop = messages.scrollHeight;
            };
            const answerQuestion = function (question) {
                const normalizedQuestion = question.trim();
                if (!normalizedQuestion) return;
                addMessage(normalizedQuestion, false, []);
                if (/^(?:help me fill this form|what fields can you fill|show form fields)$/i.test(normalizedQuestion)) {
                    describeCurrentForm();
                    return;
                }
                const fillRequest = normalizedQuestion.match(/^(?:fill|prepare|draft)(?: this form)?\s*:\s*(.+)$/i);
                if (fillRequest) {
                    proposeFormFill(fillRequest[1]);
                    return;
                }
                const answer = answers.find(function (entry) {
                    return entry.match.test(normalizedQuestion);
                });
                if (answer) {
                    addMessage(answer.text, true, answer.links);
                    return;
                }
                addMessage(
                    'I can help find admin pages and explain common workflows, but I do not read document records or handle account actions. Try asking about pending documents, incoming or outgoing records, batches, borrowed items, reports, settings, or server recovery.',
                    true,
                    [
                        { label: 'Pending Inbox', href: 'incoming?status=Pending' },
                        { label: 'Quick Submit', href: 'quick_document' },
                        { label: 'Reports', href: 'reports' }
                    ]
                );
            };
            const sendQuestion = function () {
                answerQuestion(input.value);
                input.value = '';
                input.focus();
            };

            const normalizeFieldName = function (value) {
                return value.toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim().replace(/\s+/g, ' ');
            };
            const fieldAliases = {
                subject: ['subject', 'title', 'document subject'],
                sender: ['sender', 'from', 'sender name'],
                recipient: ['recipient', 'to', 'recipient name'],
                type: ['type', 'document type', 'direction'],
                status: ['status', 'current status'],
                remarks: ['remarks', 'notes', 'description'],
                date: ['date', 'document date', 'date created'],
                search: ['search', 'search records', 'query']
            };
            const getControlNames = function (control) {
                const names = [
                    control.name,
                    control.id,
                    control.getAttribute('aria-label'),
                    control.getAttribute('placeholder')
                ];
                if (control.labels) {
                    Array.from(control.labels).forEach(function (label) {
                        names.push(label.textContent);
                    });
                }
                const wrappingLabel = control.closest('label');
                if (wrappingLabel) names.push(wrappingLabel.textContent);
                return names.filter(Boolean).map(normalizeFieldName);
            };
            const isEditableVisibleControl = function (control) {
                if (!(control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement || control instanceof HTMLSelectElement)) return false;
                if (control.disabled || control.readOnly || !control.getClientRects().length) return false;
                if (control instanceof HTMLInputElement && !['text', 'search', 'email', 'tel', 'url', 'number', 'date'].includes(control.type)) return false;
                return true;
            };
            const getVisibleFields = function () {
                return Array.from(document.querySelectorAll('.admin-content form:not(.admin-assistant-form) input, .admin-content form:not(.admin-assistant-form) textarea, .admin-content form:not(.admin-assistant-form) select, .modal.show form:not(.admin-assistant-form) input, .modal.show form:not(.admin-assistant-form) textarea, .modal.show form:not(.admin-assistant-form) select'))
                    .filter(isEditableVisibleControl)
                    .map(function (control) {
                        const names = getControlNames(control);
                        return {
                            control: control,
                            label: (control.labels && control.labels.length ? control.labels[0].textContent : '') || control.getAttribute('aria-label') || control.getAttribute('placeholder') || control.name || control.id || 'Field',
                            names: names
                        };
                    });
            };
            const matchesField = function (field, requestedName) {
                const normalized = normalizeFieldName(requestedName);
                const aliases = Object.keys(fieldAliases).reduce(function (all, key) {
                    const values = fieldAliases[key];
                    if (values.includes(normalized) || key === normalized) all.push.apply(all, values);
                    return all;
                }, [normalized]);
                return field.names.some(function (name) {
                    return aliases.includes(name);
                });
            };
            const describeCurrentForm = function () {
                const fields = getVisibleFields();
                if (!fields.length) {
                    addMessage('I don’t see any visible editable fields in the current admin page. Open a form first, then ask me to help fill it.', true, []);
                    return;
                }
                const uniqueFields = Array.from(new Set(fields.map(function (field) { return field.label.trim(); })).values());
                addMessage('I can prepare values for visible fields on this page. Tell me the values as a colon-separated list, for example: “fill: subject: Meeting records; sender: Dean’s Office”. I’ll show a preview and wait for your approval before filling anything. I will not submit the form.\n\nVisible fields: ' + uniqueFields.join(', '), true, []);
            };
            const parseFieldValues = function (valueText) {
                const pairs = [];
                valueText.split(/[;\n]+/).forEach(function (part) {
                    const separator = part.search(/[:=]/);
                    if (separator < 1) return;
                    const name = part.slice(0, separator).trim();
                    const value = part.slice(separator + 1).trim();
                    if (name && value) pairs.push({ name: name, value: value });
                });
                return pairs;
            };
            const makeFillProposal = function (pairs, fields) {
                const matched = [];
                const unmatched = [];
                pairs.forEach(function (pair) {
                    const candidates = fields.filter(function (field) { return matchesField(field, pair.name); });
                    if (candidates.length !== 1) {
                        unmatched.push(pair.name + (candidates.length > 1 ? ' (matches more than one field)' : ' (no unique visible field match)'));
                        return;
                    }
                    const field = candidates[0];
                    if (field.control instanceof HTMLSelectElement) {
                        const wanted = normalizeFieldName(pair.value);
                        const option = Array.from(field.control.options).find(function (item) {
                            return normalizeFieldName(item.textContent) === wanted || normalizeFieldName(item.value) === wanted;
                        });
                        if (!option) {
                            unmatched.push(pair.name + ' (value is not an available option)');
                            return;
                        }
                        matched.push({ label: field.label.trim(), value: option.value, control: field.control, displayValue: option.textContent.trim() });
                        return;
                    }
                    if (field.control instanceof HTMLInputElement && field.control.type === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(pair.value)) {
                        unmatched.push(pair.name + ' (use a date in YYYY-MM-DD format)');
                        return;
                    }
                    matched.push({ label: field.label.trim(), value: pair.value, control: field.control, displayValue: pair.value });
                });
                if (!matched.length) {
                    addMessage('I could not prepare any fields. ' + (unmatched.length ? 'Check: ' + unmatched.join('; ') + '.' : 'Use “field: value” pairs separated by semicolons.'), true, []);
                    return;
                }
                const message = document.createElement('div');
                message.className = 'admin-assistant-message is-assistant admin-assistant-proposal';
                const paragraph = document.createElement('p');
                paragraph.textContent = 'Review these proposed form values. Nothing will change until you approve:';
                message.appendChild(paragraph);
                const list = document.createElement('ul');
                list.className = 'admin-assistant-proposal-list';
                matched.forEach(function (entry) {
                    const item = document.createElement('li');
                    const name = document.createElement('strong');
                    name.textContent = entry.label + ': ';
                    const value = document.createElement('span');
                    value.textContent = entry.displayValue;
                    item.append(name, value);
                    list.appendChild(item);
                });
                message.appendChild(list);
                if (unmatched.length) {
                    const note = document.createElement('p');
                    note.className = 'admin-assistant-proposal-note';
                    note.textContent = 'Not included: ' + unmatched.join('; ') + '.';
                    message.appendChild(note);
                }
                const controls = document.createElement('div');
                controls.className = 'admin-assistant-proposal-controls';
                const approve = document.createElement('button');
                approve.type = 'button';
                approve.className = 'admin-assistant-approve';
                approve.textContent = 'Approve and fill';
                const cancel = document.createElement('button');
                cancel.type = 'button';
                cancel.className = 'admin-assistant-cancel';
                cancel.textContent = 'Cancel';
                controls.append(approve, cancel);
                message.appendChild(controls);
                messages.appendChild(message);
                messages.scrollTop = messages.scrollHeight;
                approve.addEventListener('click', function () {
                    let filledCount = 0;
                    matched.forEach(function (entry) {
                        if (!entry.control.isConnected || !isEditableVisibleControl(entry.control)) return;
                        entry.control.value = entry.value;
                        filledCount += 1;
                    });
                    message.remove();
                    addMessage(filledCount
                        ? 'Filled ' + filledCount + ' approved field' + (filledCount === 1 ? '' : 's') + '. Review the form and submit it yourself when ready.'
                        : 'The form changed before I could fill it. Nothing was updated; please try again.', true, []);
                });
                cancel.addEventListener('click', function () {
                    message.remove();
                    addMessage('Cancelled. I did not change the form.', true, []);
                });
            };
            const proposeFormFill = function (instruction) {
                const pairs = parseFieldValues(instruction);
                if (!pairs.length) {
                    addMessage('Tell me the values you want prepared as “fill: field: value; field: value”. I’ll match them to visible fields and ask you to approve before filling. I never submit forms.', true, []);
                    return;
                }
                makeFillProposal(pairs, getVisibleFields());
            };
            const launcherStorageKey = 'ceit-admin-assistant-position-v1';
            const getLauncherBounds = function () {
                return launcher.getBoundingClientRect();
            };
            const clampLauncherPosition = function (left, top) {
                const bounds = getLauncherBounds();
                const margin = 8;
                const maxLeft = Math.max(margin, window.innerWidth - bounds.width - margin);
                const bottomReserve = document.body.classList.contains('admin-compact') ? 84 : 0;
                const maxTop = Math.max(margin, window.innerHeight - bounds.height - bottomReserve - margin);
                return {
                    left: Math.min(Math.max(left, margin), maxLeft),
                    top: Math.min(Math.max(top, margin), maxTop)
                };
            };
            const positionLauncher = function (left, top, side, save) {
                const position = clampLauncherPosition(left, top);
                launcher.style.left = side === 'left' ? position.left + 'px' : 'auto';
                launcher.style.right = side === 'right' ? (window.innerWidth - position.left - getLauncherBounds().width) + 'px' : 'auto';
                launcher.style.top = position.top + 'px';
                launcher.style.bottom = 'auto';
                launcher.dataset.dockSide = side;
                if (!save) return;
                try {
                    const yRange = Math.max(1, window.innerHeight - getLauncherBounds().height - 16);
                    window.localStorage.setItem(launcherStorageKey, JSON.stringify({ side: side, y: position.top / yRange }));
                } catch (error) {
                    console.warn('Admin Assistant position could not be saved.', error);
                }
            };
            const restoreLauncherPosition = function () {
                try {
                    const saved = JSON.parse(window.localStorage.getItem(launcherStorageKey) || 'null');
                    if (saved && (saved.side === 'left' || saved.side === 'right') && Number.isFinite(saved.y)) {
                        const range = Math.max(1, window.innerHeight - getLauncherBounds().height - 16);
                        const top = Math.max(8, Math.min(range * saved.y, range));
                        const left = saved.side === 'left' ? 8 : window.innerWidth - getLauncherBounds().width - 8;
                        positionLauncher(left, top, saved.side, false);
                        return;
                    }
                } catch (error) {
                    console.warn('Admin Assistant position could not be restored.', error);
                }
                positionLauncher(window.innerWidth - getLauncherBounds().width - 8, window.innerHeight * 0.46, 'right', false);
            };
            const positionPanel = function () {
                const viewportMargin = 10;
                const launcherBounds = getLauncherBounds();
                panel.style.width = Math.min(384, window.innerWidth - viewportMargin * 2) + 'px';
                panel.style.maxHeight = Math.min(560, window.innerHeight - viewportMargin * 2 - (document.body.classList.contains('admin-compact') ? 78 : 0)) + 'px';
                panel.style.left = 'auto';
                panel.style.right = 'auto';
                panel.style.top = 'auto';
                panel.style.bottom = 'auto';
                const width = Math.min(384, window.innerWidth - viewportMargin * 2);
                const height = Math.min(panel.scrollHeight || 440, window.innerHeight - viewportMargin * 2 - (document.body.classList.contains('admin-compact') ? 78 : 0));
                const side = launcher.dataset.dockSide || 'right';
                const proposedLeft = side === 'right'
                    ? launcherBounds.left - width - 10
                    : launcherBounds.right + 10;
                const left = Math.max(viewportMargin, Math.min(proposedLeft, window.innerWidth - width - viewportMargin));
                const top = Math.max(viewportMargin, Math.min(launcherBounds.top + launcherBounds.height / 2 - height / 2, window.innerHeight - height - viewportMargin - (document.body.classList.contains('admin-compact') ? 78 : 0)));
                panel.style.left = left + 'px';
                panel.style.top = top + 'px';
            };
            let dragState = null;
            let suppressLauncherClick = false;
            launcher.addEventListener('pointerdown', function (event) {
                if (event.button !== 0) return;
                const bounds = getLauncherBounds();
                dragState = { pointerId: event.pointerId, startX: event.clientX, startY: event.clientY, left: bounds.left, top: bounds.top, moved: false };
                launcher.setPointerCapture(event.pointerId);
            });
            launcher.addEventListener('pointermove', function (event) {
                if (!dragState || dragState.pointerId !== event.pointerId) return;
                const deltaX = event.clientX - dragState.startX;
                const deltaY = event.clientY - dragState.startY;
                if (!dragState.moved && Math.hypot(deltaX, deltaY) < 6) return;
                dragState.moved = true;
                launcher.classList.add('is-dragging');
                const position = clampLauncherPosition(dragState.left + deltaX, dragState.top + deltaY);
                launcher.style.left = position.left + 'px';
                launcher.style.right = 'auto';
                launcher.style.top = position.top + 'px';
                launcher.style.bottom = 'auto';
                panel.classList.add('is-repositioning');
                if (!panel.hidden) positionPanel();
            });
            const finishLauncherDrag = function (event) {
                if (!dragState || dragState.pointerId !== event.pointerId) return;
                const bounds = getLauncherBounds();
                if (dragState.moved) {
                    suppressLauncherClick = true;
                    const side = bounds.left + bounds.width / 2 < window.innerWidth / 2 ? 'left' : 'right';
                    positionLauncher(side === 'left' ? 8 : window.innerWidth - bounds.width - 8, bounds.top, side, true);
                    if (!panel.hidden) positionPanel();
                    window.setTimeout(function () { suppressLauncherClick = false; }, 0);
                }
                launcher.classList.remove('is-dragging');
                panel.classList.remove('is-repositioning');
                dragState = null;
            };
            launcher.addEventListener('pointerup', finishLauncherDrag);
            launcher.addEventListener('pointercancel', finishLauncherDrag);
            launcher.addEventListener('keydown', function (event) {
                const moves = { ArrowUp: [0, -12], ArrowDown: [0, 12], ArrowLeft: [-12, 0], ArrowRight: [12, 0] };
                if (!moves[event.key]) return;
                event.preventDefault();
                const bounds = getLauncherBounds();
                const next = clampLauncherPosition(bounds.left + moves[event.key][0], bounds.top + moves[event.key][1]);
                const side = next.left + bounds.width / 2 < window.innerWidth / 2 ? 'left' : 'right';
                positionLauncher(side === 'left' ? 8 : window.innerWidth - bounds.width - 8, next.top, side, true);
                if (!panel.hidden) positionPanel();
            });

            launcher.addEventListener('click', function () {
                if (suppressLauncherClick) return;
                setOpen(!panel.classList.contains('is-open'));
            });
            closeButton.addEventListener('click', function () {
                setOpen(false);
            });
            sendButton.addEventListener('click', sendQuestion);
            input.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter') return;
                event.preventDefault();
                sendQuestion();
            });
            assistant.querySelectorAll('[data-assistant-question]').forEach(function (button) {
                button.addEventListener('click', function () {
                    answerQuestion(button.dataset.assistantQuestion || '');
                });
            });
            restoreLauncherPosition();
            window.addEventListener('resize', function () {
                restoreLauncherPosition();
                if (!panel.hidden) positionPanel();
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !panel.hidden && panel.classList.contains('is-open')) setOpen(false);
            });
        }
    }
}
document.querySelectorAll('.modal').forEach(function (modal) {
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
});
if (document.body.classList.contains('admin-body')) {
    // Intercept POST forms once at document level so dynamically rendered admin forms behave consistently.
    const createAdminDialog = function (id, title, iconClass) {
        const element = document.createElement('div');
        element.className = 'modal fade';
        element.id = id;
        element.tabIndex = -1;
        element.setAttribute('aria-labelledby', id + 'Title');
        element.setAttribute('aria-hidden', 'true');
        element.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow"><div class="modal-header"><h5 class="modal-title d-flex align-items-center gap-2 text-primary" id="' + id + 'Title"><i class="bi ' + iconClass + '" aria-hidden="true"></i><span></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"></div><div class="modal-footer"></div></div></div>';
        element.querySelector('.modal-title span').textContent = title;
        document.body.appendChild(element);
        return element;
    };

    const confirmableForms = Array.from(document.querySelectorAll('form[method="post" i]')).filter(function (form) {
        return form.dataset.adminConfirm !== 'false' && !form.classList.contains('sms-confirm-form');
    });
    const adminConfirmDialog = confirmableForms.length
        ? createAdminDialog('adminActionConfirmModal', 'Confirm this action', 'bi-question-circle-fill')
        : null;
    if (adminConfirmDialog) {
        const confirmBody = adminConfirmDialog.querySelector('.modal-body');
        const confirmFooter = adminConfirmDialog.querySelector('.modal-footer');
        const cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className = 'btn btn-outline-secondary';
        cancelButton.textContent = 'Cancel';
        cancelButton.setAttribute('data-bs-dismiss', 'modal');
        const confirmButton = document.createElement('button');
        confirmButton.type = 'button';
        confirmButton.className = 'btn btn-primary';
        confirmButton.textContent = 'Continue';
        confirmFooter.append(cancelButton, confirmButton);
        const confirmModal = new bootstrap.Modal(adminConfirmDialog);
        let pendingAction = null;
        let actionApproved = false;
        let activeFormModal = null;

        document.addEventListener('submit', function (event) {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.matches('form[method="post" i]')) return;
            if (form.dataset.adminConfirm === 'false' || form.classList.contains('sms-confirm-form')) return;
            if (form.dataset.adminConfirmed === 'true') {
                delete form.dataset.adminConfirmed;
                return;
            }
            if (event.defaultPrevented) return;

            event.preventDefault();
            pendingAction = { form: form, submitter: event.submitter };
            activeFormModal = form.closest('.modal.show');
            const submitter = event.submitter;
            const action = submitter instanceof HTMLElement
                ? (submitter.dataset.confirmAction || submitter.textContent.trim())
                : 'Submit this change';
            const detail = submitter instanceof HTMLElement ? submitter.dataset.confirmMessage : '';
            const isDestructive = submitter instanceof HTMLElement
                ? submitter.matches('.btn-danger, .btn-outline-danger')
                : false;
            const verb = /^(save|add|create|update|register|submit|record|mark|send|delete|remove|return|release|refresh|resend|prepare|change)\b/i.test(action)
                || /delete|remove|return|release|send|priority/i.test(action)
                ? action
                : 'Save ' + action.toLowerCase();
            adminConfirmDialog.querySelector('.modal-title span').textContent = submitter?.dataset.confirmTitle || 'Confirm: ' + verb + '?';
            confirmBody.textContent = detail || 'Please confirm that you want to continue. This change will be saved to the office records.';
            confirmButton.className = 'btn ' + (isDestructive ? 'btn-danger' : 'btn-primary');
            confirmButton.textContent = submitter?.dataset.confirmButton || 'Confirm';
            confirmButton.disabled = false;

            if (activeFormModal) {
                activeFormModal.addEventListener('hidden.bs.modal', function () {
                    confirmModal.show();
                }, { once: true });
                bootstrap.Modal.getInstance(activeFormModal)?.hide();
            } else {
                confirmModal.show();
            }
        });

        adminConfirmDialog.addEventListener('hidden.bs.modal', function () {
            if (!pendingAction) return;
            const action = pendingAction;
            pendingAction = null;
            if (actionApproved) {
                actionApproved = false;
                action.form.dataset.adminConfirmed = 'true';
                if (action.submitter) action.form.requestSubmit(action.submitter);
                else action.form.requestSubmit();
            } else if (activeFormModal) {
                bootstrap.Modal.getOrCreateInstance(activeFormModal).show();
            }
            activeFormModal = null;
        });

        confirmButton.addEventListener('click', function () {
            if (!pendingAction) return;
            actionApproved = true;
            confirmButton.disabled = true;
            confirmButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Working…';
            confirmModal.hide();
        });
    }

    const customOutcomeExists = document.querySelector('#smsOutcomeModal, #prepareReleaseOutcome, #releaseSmsOutcomeModal');
    const firstAlert = Array.from(document.querySelectorAll('.admin-content .alert[role="alert"]')).find(function (alert) {
        return !alert.hidden && getComputedStyle(alert).display !== 'none';
    });
    if (firstAlert && !customOutcomeExists) {
        const tone = ['success', 'danger', 'warning', 'info'].find(function (variant) {
            return firstAlert.classList.contains('alert-' + variant);
        }) || 'info';
        const icon = { success: 'bi-check-circle-fill', danger: 'bi-exclamation-triangle-fill', warning: 'bi-exclamation-circle-fill', info: 'bi-info-circle-fill' }[tone];
        const outcomeDialog = createAdminDialog('adminActionOutcomeModal', tone === 'success' ? 'Action completed' : tone === 'danger' ? 'Action needs attention' : tone === 'warning' ? 'Please review' : 'Update', icon);
        const outcomeTitle = outcomeDialog.querySelector('.modal-title');
        outcomeTitle.classList.remove('text-primary');
        outcomeTitle.classList.add('text-' + tone);
        outcomeDialog.querySelector('.modal-body').textContent = firstAlert.textContent.trim();
        const outcomeButton = document.createElement('button');
        outcomeButton.type = 'button';
        outcomeButton.className = 'btn btn-primary';
        outcomeButton.textContent = 'Got it';
        outcomeButton.setAttribute('data-bs-dismiss', 'modal');
        outcomeDialog.querySelector('.modal-footer').appendChild(outcomeButton);
        firstAlert.remove();
        new bootstrap.Modal(outcomeDialog).show();
    }

    const progress = document.createElement('div');
    progress.id = 'adminLoadProgress';
    progress.setAttribute('aria-hidden', 'true');
    document.body.appendChild(progress);

    const loadingStatus = document.createElement('div');
    loadingStatus.className = 'visually-hidden';
    loadingStatus.id = 'adminLoadingStatus';
    loadingStatus.setAttribute('role', 'status');
    loadingStatus.setAttribute('aria-live', 'polite');
    document.body.appendChild(loadingStatus);

    const networkNotice = document.createElement('div');
    networkNotice.id = 'adminNetworkNotice';
    networkNotice.setAttribute('role', 'status');
    networkNotice.setAttribute('aria-live', 'polite');
    networkNotice.hidden = true;
    document.body.appendChild(networkNotice);
    let networkNoticeTimer = 0;
    let slowRequestTimer = 0;
    const serverOutage = document.createElement('div');
    serverOutage.className = 'admin-server-outage';
    serverOutage.hidden = true;
    serverOutage.innerHTML = '<section class="admin-server-outage-card" role="alertdialog" aria-modal="true" aria-labelledby="adminServerOutageTitle" aria-describedby="adminServerOutageDescription" tabindex="-1"><span class="admin-server-outage-icon" aria-hidden="true"><i class="bi bi-cloud-slash"></i></span><p class="admin-server-outage-eyebrow">Connection interrupted</p><h1 id="adminServerOutageTitle">Office server unavailable</h1><p class="admin-server-outage-description" id="adminServerOutageDescription">We can’t reach the office logbook right now. Your current page is still open, but changes cannot be saved until the server connection returns.</p><p class="admin-server-outage-note">If you submitted a change just before the connection dropped, check the records before trying it again.</p><button type="button" class="btn btn-success admin-server-outage-retry"><i class="bi bi-arrow-clockwise me-2" aria-hidden="true"></i>Check connection</button><p class="admin-server-outage-retry-hint">We’ll keep checking automatically.</p></section>';
    document.body.appendChild(serverOutage);
    const serverOutageCard = serverOutage.querySelector('.admin-server-outage-card');
    const serverOutageRetry = serverOutage.querySelector('.admin-server-outage-retry');
    let outageReturnFocus = null;

    serverOutage.addEventListener('keydown', function (event) {
        if (event.key !== 'Tab') return;
        event.preventDefault();
        serverOutageRetry.focus();
    });

    adminServerAvailabilityHandler = function (isAvailable) {
        const isUnavailable = !isAvailable;
        if (adminServerUnavailable === isUnavailable) return;
        adminServerUnavailable = isUnavailable;
        updateAdminNetworkStatus();
        document.body.classList.toggle('admin-server-disconnected', isUnavailable);
        serverOutage.hidden = !isUnavailable;

        if (isUnavailable) {
            window.clearTimeout(networkNoticeTimer);
            networkNotice.hidden = true;
            outageReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            serverOutageCard.focus();
            return;
        }

        if (outageReturnFocus && document.contains(outageReturnFocus)) outageReturnFocus.focus();
        outageReturnFocus = null;
        networkNotice.textContent = 'Office server connection restored. Check records before retrying a change.';
        networkNotice.hidden = false;
        window.clearTimeout(networkNoticeTimer);
        networkNoticeTimer = window.setTimeout(function () {
            networkNotice.hidden = true;
        }, 8000);
    };

    serverOutageRetry.addEventListener('click', function () {
        if (adminDocumentActivityCheck) adminDocumentActivityCheck();
    });

    const setProgress = function (active, message) {
        window.clearTimeout(slowRequestTimer);
        progress.classList.toggle('is-active', active);
        loadingStatus.textContent = active ? message : '';
        if (active) {
            // A delayed response is ambiguous for writes; warn users to check records before retrying.
            slowRequestTimer = window.setTimeout(function () {
                networkNotice.textContent = 'This is taking longer than usual. Keep this page open; check records before repeating a submitted change.';
                networkNotice.hidden = false;
            }, 8000);
        }
    };

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (event.defaultPrevented) return;
        if (form.matches('form.sms-confirm-form, #smsSendForm') && form.dataset.confirmed !== 'true') return;
        if (form.matches('form[method="post" i]') && form.dataset.adminConfirm !== 'false' && !form.classList.contains('sms-confirm-form')) {
            if (form.dataset.adminConfirmed !== 'true') return;
        }
        if (form.dataset.adminSubmitting === 'true') {
            event.preventDefault();
            return;
        }
        form.dataset.adminSubmitting = 'true';
        if (event.submitter instanceof HTMLElement) {
            event.submitter.classList.add('admin-submit-pending');
            event.submitter.setAttribute('aria-busy', 'true');
        }
        setProgress(true, 'Submitting. Please wait for the page to finish loading.');
    }, true);

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!link || link.target || link.hasAttribute('download')) return;
        const destination = new URL(link.href, window.location.href);
        if (destination.origin !== window.location.origin || destination.href === window.location.href) return;
        if (destination.pathname === window.location.pathname && destination.search === window.location.search) return;
        setProgress(true, 'Opening page. Please wait.');
    }, true);

    window.addEventListener('offline', function () {
        window.clearTimeout(slowRequestTimer);
        adminServerAvailabilityHandler(false);
    });
    window.addEventListener('online', function () {
        window.clearTimeout(slowRequestTimer);
        if (adminDocumentActivityCheck) adminDocumentActivityCheck();
    });
    window.addEventListener('pageshow', function () {
        setProgress(false, '');
        document.querySelectorAll('form[data-admin-submitting="true"]').forEach(function (form) {
            delete form.dataset.adminSubmitting;
        });
        document.querySelectorAll('.admin-submit-pending').forEach(function (button) {
            button.classList.remove('admin-submit-pending');
            button.removeAttribute('aria-busy');
        });
    });
    if (!navigator.onLine) {
        adminServerAvailabilityHandler(false);
    }
}
</script>
</body>
</html>
