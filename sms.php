<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'sms_service.php';
ensure_admin();

$message = '';
$recipient = '';
$smsMessage = '';
$smsPopup = null;
$setSmsPopup = static function ($title, $detail, $type = 'info') use (&$smsPopup) {
    $smsPopup = ['title' => $title, 'detail' => $detail, 'type' => $type];
};

if (!ensure_sms_provider_tracking_schema($conn)) {
    $message = show_alert('SMS delivery tracking could not be initialized. Please check the database configuration.', 'danger');
    $setSmsPopup('SMS setup problem', 'SMS delivery tracking could not be initialized. Please check the database configuration.', 'danger');
}

if (isset($_POST['refresh_statuses'])) {
    $lastRefresh = (int)($_SESSION['sms_status_refresh_at'] ?? 0);
    $waitSeconds = 60 - (time() - $lastRefresh);
    if ($lastRefresh > 0 && $waitSeconds > 0) {
        $detail = "Please wait {$waitSeconds} second(s) before refreshing statuses again.";
        $message = show_alert($detail, 'danger');
        $setSmsPopup('Please wait before refreshing', $detail, 'warning');
    } else {
        $refreshResult = refresh_philsms_sms_statuses($conn);
        if (!empty($refreshResult['checked']) || !empty($refreshResult['failed'])) {
            $_SESSION['sms_status_refresh_at'] = time();
        }
        $alertType = empty($refreshResult['success']) ? 'danger' : 'success';
        $detail = $refreshResult['message'];
        if (!empty($refreshResult['untracked'])) {
            $detail .= ' ' . $refreshResult['untracked'] . ' older or untracked pending message(s) cannot be refreshed through PhilSMS.';
        }
        $message = show_alert($detail, $alertType);
        $popupType = empty($refreshResult['success'])
            ? 'danger'
            : (!empty($refreshResult['pending']) ? 'warning' : (!empty($refreshResult['checked']) ? 'success' : 'info'));
        $setSmsPopup('SMS status refresh', $detail, $popupType);
    }
}

if (isset($_POST['send'])) {
    $recipient = contact_number_input($_POST['recipient'] ?? '') ?? '';
    $smsMessage = trim($_POST['sms_message'] ?? '');

    if ($recipient === '' || $smsMessage === '') {
        $detail = 'Recipient number and message are required.';
        $message = show_alert($detail, 'danger');
        $setSmsPopup('SMS not sent', $detail, 'danger');
    } elseif (!is_valid_contact_number($recipient)) {
        $detail = 'Enter an 11-digit recipient number using digits only.';
        $message = show_alert($detail, 'danger');
        $setSmsPopup('SMS not sent', $detail, 'danger');
    } else {
        $result = send_logged_sms($conn, $recipient, $smsMessage);
        if ($result['success']) {
            $message = show_alert($result['message']);
            $status = strtolower($result['status'] ?? '');
            if (empty($result['logged'])) {
                $setSmsPopup('PhilSMS accepted the SMS, but logging failed', 'Do not send it again yet, because PhilSMS may already be processing it. Check the PhilSMS dashboard before retrying.', 'warning');
            } elseif (in_array($status, ['success', 'delivered'], true)) {
                $setSmsPopup('SMS sent successfully', 'PhilSMS reports the message status as ' . $result['status'] . '.', 'success');
            } elseif (in_array($status, ['pending', 'queued', 'sent'], true)) {
                $setSmsPopup('SMS accepted — delivery pending', 'PhilSMS accepted the message and reports ' . $result['status'] . '. This is not delivery confirmation yet. Use “Refresh statuses” to check again.', 'warning');
            } else {
                $setSmsPopup('SMS accepted by PhilSMS', $result['message'], 'info');
            }
            $recipient = $smsMessage = '';
        } else {
            $detail = 'SMS failed: ' . $result['message'];
            $message = show_alert($detail, 'danger');
            $setSmsPopup('SMS could not be sent', $detail, 'danger');
        }
    }
}

if (isset($_POST['resend_failed_sms'])) {
    $smsId = filter_var($_POST['sms_id'] ?? null, FILTER_VALIDATE_INT);
    if ($smsId === false || $smsId <= 0) {
        $detail = 'The failed SMS record could not be identified. Refresh the page and try again.';
        $message = show_alert($detail, 'danger');
        $setSmsPopup('SMS was not resent', $detail, 'danger');
    } else {
        $failedLog = null;
        $claimError = false;
        $transactionStarted = false;
        try {
            $conn->begin_transaction();
            $transactionStarted = true;
        } catch (Throwable $error) {
            $claimError = true;
            error_log('Failed SMS resend transaction could not start: ' . $error->getMessage());
        }
        if (!$claimError) {
            $stmt = safe_query(
                $conn,
                "SELECT recipient, message FROM sms_logs WHERE sms_id = ? AND LOWER(status) = 'failed' LIMIT 1 FOR UPDATE",
                'i',
                [$smsId]
            );
            if ($stmt === false) {
                $claimError = true;
            } else {
                $result = mysqli_stmt_get_result($stmt);
                $failedLog = $result ? mysqli_fetch_assoc($result) : null;
                mysqli_stmt_close($stmt);
            }
        }

        if ($claimError) {
            if ($transactionStarted) {
                $conn->rollback();
            }
            $detail = 'The SMS record could not be checked, so no resend was attempted.';
            $message = show_alert($detail, 'danger');
            $setSmsPopup('SMS was not resent', $detail, 'danger');
        } elseif (!$failedLog) {
            $conn->rollback();
            $detail = 'Only SMS records currently marked Failed can be resent. Refresh the logs and check the latest status.';
            $message = show_alert($detail, 'warning');
            $setSmsPopup('SMS was not resent', $detail, 'warning');
        } elseif (!is_valid_contact_number(trim((string)$failedLog['recipient']))) {
            $conn->rollback();
            $detail = 'This SMS recipient does not have a valid 11-digit number. Update the contact before resending.';
            $message = show_alert($detail, 'danger');
            $setSmsPopup('SMS was not resent', $detail, 'danger');
        } else {
            $claimStmt = safe_query($conn, "UPDATE sms_logs SET status = 'Sending' WHERE sms_id = ? AND LOWER(status) = 'failed'", 'i', [$smsId]);
            if ($claimStmt === false || mysqli_stmt_affected_rows($claimStmt) !== 1) {
                if ($claimStmt !== false) {
                    mysqli_stmt_close($claimStmt);
                }
                $conn->rollback();
                $detail = 'This SMS is no longer marked Failed, so another resend was not started. Refresh the logs to check its current status.';
                $message = show_alert($detail, 'warning');
                $setSmsPopup('SMS was not resent', $detail, 'warning');
            } else {
                mysqli_stmt_close($claimStmt);
                $conn->commit();
                // Update the original row with the latest attempt instead of creating a duplicate log.
                $resendResult = send_logged_sms($conn, $failedLog['recipient'], $failedLog['message'], $smsId);
                $resendStatus = strtolower($resendResult['status'] ?? '');
                if (!empty($resendResult['success'])) {
                    $detail = 'The existing SMS log for ' . $failedLog['recipient'] . ' was updated. No duplicate log was created.';
                    if (empty($resendResult['logged'])) {
                        $detail = 'PhilSMS may have accepted the resend, but the existing log could not be updated. Check PhilSMS before trying again; do not resend yet.';
                        $popupType = 'warning';
                        $popupTitle = 'Resend may have been accepted';
                    } elseif (in_array($resendStatus, ['success', 'delivered'], true)) {
                        $detail .= ' PhilSMS reports the status as ' . $resendResult['status'] . '.';
                        $popupType = 'success';
                        $popupTitle = 'SMS resent successfully';
                    } elseif (in_array($resendStatus, ['pending', 'queued', 'sent'], true)) {
                        $detail .= ' PhilSMS reports ' . $resendResult['status'] . '; delivery is not confirmed yet.';
                        $popupType = 'warning';
                        $popupTitle = 'Resend accepted — delivery pending';
                    } else {
                        $detail .= ' ' . $resendResult['message'];
                        $popupType = 'info';
                        $popupTitle = 'Resend accepted by PhilSMS';
                    }
                } else {
                    $detail = 'The resend failed: ' . $resendResult['message'] . ' The same log entry was retained; no duplicate was created.';
                    $popupType = 'danger';
                    $popupTitle = 'SMS could not be resent';
                }
                $message = show_alert($detail, $popupType === 'warning' ? 'success' : $popupType);
                $setSmsPopup($popupTitle, $detail, $popupType);
            }
        }
    }
}

$smsLogs = [];
$stmt = safe_query($conn, "SELECT * FROM sms_logs ORDER BY date_sent DESC LIMIT 50");
if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $smsLogs[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}
?>
<?php include 'header.php'; ?>
<div class="row g-4">
    <div class="col-12">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-3">
            <div>
                <h2 class="mb-1 text-primary">SMS Management</h2>
                <p class="text-muted mb-0">Send SMS notifications and view message logs.</p>
            </div>
            <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#smsForm">
                <i class="bi bi-chat-dots"></i> Send SMS
            </button>
        </div>
    </div>
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                    <div>
                        <h5 class="card-title mb-1">SMS Logs</h5>
                        <p class="small text-muted mb-0">Pending means PhilSMS has not yet reported a final status. Refresh to check tracked messages. Older Semaphore logs are not queried through PhilSMS.</p>
                    </div>
                    <form method="POST" class="d-inline sms-confirm-form" data-admin-confirm="false" data-confirm-title="Refresh delivery statuses?" data-confirm-message="This checks eligible pending messages with PhilSMS. It will not send new SMS messages, and refreshes are limited to once per minute." data-confirm-button="Refresh statuses">
                        <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                        <button type="submit" name="refresh_statuses" class="btn btn-outline-primary">
                            <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Refresh statuses
                        </button>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Date Sent</th>
                                <th scope="col">Recipient</th>
                                <th scope="col">Message</th>
                                <th scope="col">Status</th>
                                <th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($smsLogs) === 0): ?>
                                <tr><td colspan="5" class="text-center text-muted">No SMS logs found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($smsLogs as $log): ?>
                                    <tr>
                                        <td><?php echo sanitize($log['date_sent']); ?></td>
                                        <td><?php echo sanitize($log['recipient']); ?></td>
                                        <td><?php echo sanitize(substr($log['message'], 0, 50)) . (strlen($log['message']) > 50 ? '...' : ''); ?></td>
                                        <td>
                                            <?php echo sanitize($log['status']); ?>
                                            <?php if (!empty($log['provider_message_id'])): ?>
                                                <div class="small text-muted"><?php echo sanitize($log['provider_name'] ?? 'Semaphore'); ?> ID: <?php echo sanitize($log['provider_message_id']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (strtolower((string)$log['status']) === 'failed'): ?>
                                                <form method="POST" class="d-inline sms-confirm-form" data-admin-confirm="false" data-confirm-title="Resend failed SMS?" data-confirm-message="This retries the message to the same recipient and updates this SMS log instead of creating a duplicate. The provider may still charge for the resend." data-confirm-button="Resend SMS">
                                                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                    <input type="hidden" name="sms_id" value="<?php echo (int)$log['sms_id']; ?>">
                                                    <button type="submit" name="resend_failed_sms" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Resend
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="smsForm" tabindex="-1" aria-labelledby="smsFormTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title text-primary" id="smsFormTitle">Send SMS</h5>
                        <p class="mb-0 mt-1 small text-muted">Send a message to a recipient.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="smsSendForm" data-admin-confirm="false">
                    <div class="modal-body" id="smsComposeFields">
                        <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                        <div class="mb-3">
                            <label class="form-label" for="recipient">Recipient Number</label>
                            <input type="tel" id="recipient" name="recipient" class="form-control" value="<?php echo sanitize($recipient); ?>" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" placeholder="09123456789" title="Enter exactly 11 digits." required>
                            <div class="form-text">Enter exactly 11 digits using numbers only.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="sms_message">Message</label>
                            <textarea id="sms_message" name="sms_message" class="form-control" rows="4" placeholder="Enter your message" required><?php echo sanitize($smsMessage); ?></textarea>
                        </div>
                    </div>
                    <div class="modal-footer" id="smsComposeFooter">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="send" class="btn btn-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Send SMS</button>
                    </div>
                    <section class="modal-body d-none" id="smsSendReview" aria-labelledby="smsSendReviewTitle">
                        <div class="mb-3 text-center">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary" style="width:3.25rem;height:3.25rem">
                                <i class="bi bi-chat-square-text fs-4" aria-hidden="true"></i>
                            </span>
                        </div>
                        <h3 class="h5 text-center" id="smsSendReviewTitle">Review before sending</h3>
                        <p class="text-center text-muted">Check the recipient and message. Sending may incur provider charges.</p>
                        <dl class="mb-0 rounded-3 border bg-light p-3">
                            <dt class="small text-muted">Recipient</dt>
                            <dd class="mb-3 fw-semibold" id="smsReviewRecipient"></dd>
                            <dt class="small text-muted">Message</dt>
                            <dd class="mb-0 text-break" id="smsReviewMessage"></dd>
                        </dl>
                    </section>
                    <div class="modal-footer d-none" id="smsSendReviewFooter">
                        <button type="button" class="btn btn-outline-secondary" id="smsReviewBack">Back to edit</button>
                        <button type="button" class="btn btn-primary" id="smsReviewConfirm"><i class="bi bi-send me-1" aria-hidden="true"></i>Confirm and send</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal fade" id="smsActionConfirmModal" tabindex="-1" aria-labelledby="smsActionConfirmTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title d-flex align-items-center gap-2 text-primary" id="smsActionConfirmTitle">
                        <i class="bi bi-question-circle-fill" aria-hidden="true"></i>
                        <span id="smsActionConfirmHeading">Confirm action</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close confirmation"></button>
                </div>
                <div class="modal-body" id="smsActionConfirmMessage"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="smsActionConfirmSubmit">Continue</button>
                </div>
            </div>
        </div>
    </div>
    <?php if ($smsPopup !== null): ?>
        <?php
        $popupTone = in_array($smsPopup['type'], ['success', 'warning', 'danger', 'info'], true)
            ? $smsPopup['type']
            : 'info';
        $popupIcon = [
            'success' => 'bi-check-circle-fill',
            'warning' => 'bi-hourglass-split',
            'danger' => 'bi-exclamation-triangle-fill',
            'info' => 'bi-info-circle-fill',
        ][$popupTone];
        ?>
        <div class="modal fade" id="smsOutcomeModal" tabindex="-1" aria-labelledby="smsOutcomeTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header">
                        <h5 class="modal-title d-flex align-items-center gap-2 text-<?php echo sanitize($popupTone); ?>" id="smsOutcomeTitle">
                            <i class="bi <?php echo sanitize($popupIcon); ?>" aria-hidden="true"></i>
                            <?php echo sanitize($smsPopup['title']); ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" role="alert" aria-live="assertive">
                        <?php echo sanitize($smsPopup['detail']); ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Got it</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const smsForm = document.getElementById('smsSendForm');
    const composeFields = document.getElementById('smsComposeFields');
    const composeFooter = document.getElementById('smsComposeFooter');
    const reviewPanel = document.getElementById('smsSendReview');
    const reviewFooter = document.getElementById('smsSendReviewFooter');
    const reviewRecipient = document.getElementById('smsReviewRecipient');
    const reviewMessage = document.getElementById('smsReviewMessage');
    const reviewButton = document.getElementById('smsReviewConfirm');
    let smsSubmitter = null;

    const showSmsReview = function () {
        const recipientInput = document.getElementById('recipient');
        const messageInput = document.getElementById('sms_message');
        if (!smsForm || !composeFields || !composeFooter || !reviewPanel || !reviewFooter || !recipientInput || !messageInput) return;
        reviewRecipient.textContent = recipientInput.value.trim();
        reviewMessage.textContent = messageInput.value.trim();
        composeFields.classList.add('d-none');
        composeFooter.classList.add('d-none');
        reviewPanel.classList.remove('d-none');
        reviewFooter.classList.remove('d-none');
    };

    const hideSmsReview = function () {
        if (!composeFields || !composeFooter || !reviewPanel || !reviewFooter) return;
        composeFields.classList.remove('d-none');
        composeFooter.classList.remove('d-none');
        reviewPanel.classList.add('d-none');
        reviewFooter.classList.add('d-none');
        smsSubmitter = null;
        if (reviewButton) {
            reviewButton.disabled = false;
            reviewButton.innerHTML = '<i class="bi bi-send me-1" aria-hidden="true"></i>Confirm and send';
        }
    };

    if (smsForm) {
        smsForm.addEventListener('submit', function (event) {
            if (smsForm.dataset.confirmed === 'true') {
                delete smsForm.dataset.confirmed;
                return;
            }
            event.preventDefault();
            smsSubmitter = event.submitter;
            showSmsReview();
        });
    }

    document.getElementById('smsReviewBack')?.addEventListener('click', hideSmsReview);
    reviewButton?.addEventListener('click', function () {
        if (!smsForm || !smsForm.reportValidity()) return;
        reviewButton.disabled = true;
        reviewButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Sending…';
        smsForm.dataset.confirmed = 'true';
        let sendField = smsForm.querySelector('input[name="send"][type="hidden"]');
        if (!sendField) {
            sendField = document.createElement('input');
            sendField.type = 'hidden';
            sendField.name = 'send';
            sendField.value = '1';
            smsForm.appendChild(sendField);
        }
        if (smsSubmitter && smsSubmitter.name === 'send') {
            smsForm.requestSubmit(smsSubmitter);
        } else {
            smsForm.requestSubmit();
        }
    });

    const actionConfirmElement = document.getElementById('smsActionConfirmModal');
    const actionConfirmModal = actionConfirmElement ? new bootstrap.Modal(actionConfirmElement) : null;
    const actionConfirmHeading = document.getElementById('smsActionConfirmHeading');
    const actionConfirmMessage = document.getElementById('smsActionConfirmMessage');
    const actionConfirmSubmit = document.getElementById('smsActionConfirmSubmit');
    let pendingSmsForm = null;
    let pendingSmsSubmitter = null;

    document.querySelectorAll('form.sms-confirm-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.confirmed === 'true') {
                delete form.dataset.confirmed;
                return;
            }
            event.preventDefault();
            pendingSmsForm = form;
            pendingSmsSubmitter = event.submitter;
            actionConfirmHeading.textContent = form.dataset.confirmTitle || 'Confirm action';
            actionConfirmMessage.textContent = form.dataset.confirmMessage || 'Continue with this SMS action?';
            actionConfirmSubmit.textContent = form.dataset.confirmButton || 'Continue';
            actionConfirmSubmit.disabled = false;
            actionConfirmModal.show();
        });
    });

    actionConfirmSubmit?.addEventListener('click', function () {
        if (!pendingSmsForm) return;
        const form = pendingSmsForm;
        const submitter = pendingSmsSubmitter;
        pendingSmsForm = null;
        pendingSmsSubmitter = null;
        actionConfirmSubmit.disabled = true;
        actionConfirmSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Working…';
        actionConfirmElement.addEventListener('hidden.bs.modal', function () {
            form.dataset.confirmed = 'true';
            if (submitter) {
                form.requestSubmit(submitter);
            } else {
                form.requestSubmit();
            }
        }, { once: true });
        actionConfirmModal.hide();
    });

    const outcomeModal = document.getElementById('smsOutcomeModal');
    if (outcomeModal) {
        new bootstrap.Modal(outcomeModal).show();
    }
});
</script>
<?php include 'footer.php'; ?>
