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

    const submissionLogToggle = document.getElementById('mobileSubmissionLogToggle');
    if (submissionLogToggle) {
        const csrfToken = submissionLogToggle.dataset.csrfToken;
        let submissionLogEnabled = false;
        let submissionLogRequestInProgress = false;
        const renderSubmissionLogButton = function () {
            submissionLogToggle.setAttribute('aria-pressed', String(submissionLogEnabled));
            submissionLogToggle.innerHTML = submissionLogEnabled
                ? '<i class="bi bi-pause-circle me-2" aria-hidden="true"></i><span>Pause CMD log</span>'
                : '<i class="bi bi-terminal me-2" aria-hidden="true"></i><span>Start CMD log</span>';
        };
        const requestSubmissionLogState = async function (enabled) {
            if (submissionLogRequestInProgress) return;
            submissionLogRequestInProgress = true;
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
            } catch (error) {
                window.alert(error.message);
            } finally {
                submissionLogToggle.disabled = false;
                submissionLogRequestInProgress = false;
            }
        };

        submissionLogToggle.addEventListener('click', function () {
            requestSubmissionLogState(!submissionLogEnabled);
        });
        requestSubmissionLogState();
    }
}

if (document.body.classList.contains('admin-body')) {
    const latestDocumentAttribute = Number(document.body.dataset.latestDocumentId);
    let latestObservedDocumentId = Number.isSafeInteger(latestDocumentAttribute) ? latestDocumentAttribute : 0;
    let hasDocumentActivityBaseline = document.body.dataset.documentActivityReady === 'true';
    let pendingNotificationTone = false;
    let notificationCheckInProgress = false;
    const notificationTone = new Audio(new URL('tone/notif_tone.mp3', window.location.href).href);
    notificationTone.preload = 'auto';

    const playAdminNotificationTone = function () {
        notificationTone.currentTime = 0;
        notificationTone.play().then(function () {
            pendingNotificationTone = false;
        }).catch(function (error) {
            pendingNotificationTone = true;
            console.warn('New-document notification tone is waiting for browser audio permission.', error);
        });
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
                playAdminNotificationTone();
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

    adminDocumentActivityCheck = checkForNewDocuments;
    window.setInterval(checkForNewDocuments, 15000);
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
        if (table.closest('.d-none, .d-md-block')) return;
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
