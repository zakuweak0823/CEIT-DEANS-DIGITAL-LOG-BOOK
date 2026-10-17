<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'sms_service.php';
require_once 'mobile_form_settings.php';
ensure_admin();

$mobileFormLabels = get_mobile_form_labels($conn);

$conn->query('ALTER TABLE documents ADD COLUMN IF NOT EXISTS client_phone VARCHAR(20) DEFAULT NULL');
$conn->query("ALTER TABLE documents ADD COLUMN IF NOT EXISTS submitter_type VARCHAR(20) NOT NULL DEFAULT 'Other'");
$conn->query('ALTER TABLE documents ADD COLUMN IF NOT EXISTS is_priority TINYINT(1) NOT NULL DEFAULT 0');

$message = '';
$smsOutcome = null;
$setSmsOutcome = static function ($title, $detail, $type = 'info') use (&$smsOutcome) {
    $smsOutcome = ['title' => $title, 'detail' => $detail, 'type' => $type];
};
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
if (!in_array($statusFilter, ['', 'For Release', 'Released'], true)) {
    $statusFilter = '';
}
if (isset($_POST['update'])) {
    $id = (int)($_POST['id'] ?? 0);
    $recipient = $mobileFormLabels['recipientEnabled'] ? trim($_POST['recipient'] ?? '') : '';
    $subject = trim($_POST['subject'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $clientPhone = contact_number_input($_POST['client_phone'] ?? '');
    $documentDate = trim($_POST['document_date'] ?? '');
    $dateObject = DateTime::createFromFormat('!Y-m-d', $documentDate);

    if ($id > 0 && (!$mobileFormLabels['recipientEnabled'] || $recipient !== '') && $subject !== '' && $dateObject && $dateObject->format('Y-m-d') === $documentDate && $clientPhone !== null && ($clientPhone === '' || is_valid_contact_number($clientPhone))) {
        $phoneValue = $clientPhone === '' ? null : $clientPhone;
        $stmt = safe_query($conn, "UPDATE documents SET recipient = ?, client_phone = ?, subject = ?, remarks = ?, date_created = TIMESTAMP(?, TIME(date_created)) WHERE doc_id = ? AND type = 'Outgoing'", 'sssssi', [$recipient, $phoneValue, $subject, $remarks, $documentDate, $id]);
        if ($stmt !== false) {
            mysqli_stmt_close($stmt);
            $message = show_alert('Outgoing document updated successfully.');
        } else {
            $message = show_alert('Failed to update outgoing document.', 'danger');
        }
    } else {
        $message = show_alert($clientPhone === null || ($clientPhone !== '' && !is_valid_contact_number($clientPhone))
            ? 'Enter an 11-digit contact number using digits only, or leave it blank.'
            : 'Check the recipient, document type, date, and record details.', 'danger');
    }
}

if (isset($_POST['set_outgoing_status'])) {
    $id = (int)($_POST['status_doc_id'] ?? 0);
    $status = trim($_POST['new_status'] ?? '');
    if ($id <= 0 || !in_array($status, ['For Release', 'Released'], true)) {
        $message = show_alert('Invalid release status change.', 'danger');
    } else {
        // Restrict transitions to the two supported release states and report no-op retries.
        $stmt = safe_query($conn, "UPDATE documents SET status = ? WHERE doc_id = ? AND type = 'Outgoing' AND status IN ('For Release', 'Released') AND status <> ?", 'sis', [$status, $id, $status]);
        if ($stmt === false) {
            $message = show_alert('Could not update the document release status.', 'danger');
        } else {
            $updated = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            $message = $updated === 1
                ? show_alert('Document status updated to ' . $status . '.')
                : show_alert('Document was not found or already has that status.', 'warning');
        }
    }
}

if (isset($_POST['send_client_sms'])) {
    // Recheck status and contact at send time; the page form may have gone stale.
    $id = (int)($_POST['sms_doc_id'] ?? 0);
    $stmt = safe_query($conn, "SELECT subject, client_phone, status FROM documents WHERE doc_id = ? AND type = 'Outgoing' AND status = 'For Release'", 'i', [$id]);
    $smsDocument = null;
    if ($stmt !== false) {
        $result = mysqli_stmt_get_result($stmt);
        $smsDocument = mysqli_fetch_assoc($result) ?: null;
        mysqli_stmt_close($stmt);
    }
    if (!$smsDocument) {
        $message = show_alert('The outgoing document was not found or is no longer For Release.', 'danger');
        $setSmsOutcome('Document unavailable', 'Only documents with status “For Release” can receive this notification. Refresh the list and try again.', 'danger');
    } elseif (!is_valid_contact_number(trim((string)($smsDocument['client_phone'] ?? '')))) {
        $message = show_alert('No valid client contact is saved for this document. Edit it to add the number.', 'danger');
        $setSmsOutcome('SMS was not sent', 'No valid client contact is saved for this document. Close this message, edit the document to add a phone number, then try again.', 'danger');
    } else {
        $smsText = "CvSU Dean's Office: Your document '{$smsDocument['subject']}' is ready for release. Please visit the office to claim it.";
        $smsResult = send_logged_sms($conn, $smsDocument['client_phone'], $smsText);
        if ($smsResult['success']) {
            $message = show_alert($smsResult['message']);
            $smsStatus = strtolower($smsResult['status'] ?? '');
            if (empty($smsResult['logged'])) {
                $setSmsOutcome('PhilSMS accepted the SMS, but logging failed', 'Do not send it again yet, because PhilSMS may already be processing it. Check the PhilSMS dashboard before retrying.', 'warning');
            } elseif (in_array($smsStatus, ['pending', 'queued', 'sent'], true)) {
                $setSmsOutcome('SMS accepted — delivery pending', 'PhilSMS accepted the notification and reports ' . $smsResult['status'] . '. Delivery is not confirmed yet. Check the SMS Logs page and refresh statuses later.', 'warning');
            } elseif (in_array($smsStatus, ['success', 'delivered'], true)) {
                $setSmsOutcome('Notification sent successfully', 'PhilSMS reports the notification status as ' . $smsResult['status'] . '.', 'success');
            } else {
                $setSmsOutcome('SMS accepted by PhilSMS', $smsResult['message'], 'info');
            }
        } else {
            $detail = 'SMS was not sent: ' . $smsResult['message'];
            $message = show_alert($detail, 'danger');
            $setSmsOutcome('SMS notification failed', $detail, 'danger');
        }
    }
}

$documents = [];
if ($search !== '' && $statusFilter !== '') {
    $searchTerm = "%{$search}%";
    $stmt = safe_query($conn, "SELECT * FROM documents WHERE type = 'Outgoing' AND status = ? AND (sender LIKE ? OR recipient LIKE ? OR subject LIKE ? OR remarks LIKE ?) ORDER BY (status = 'For Release') DESC, date_created DESC", 'sssss', [$statusFilter, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
} elseif ($search !== '') {
    $searchTerm = "%{$search}%";
    $stmt = safe_query($conn, "SELECT * FROM documents WHERE type = 'Outgoing' AND (sender LIKE ? OR recipient LIKE ? OR subject LIKE ? OR remarks LIKE ? OR status LIKE ?) ORDER BY (status = 'For Release') DESC, date_created DESC", 'sssss', [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
} elseif ($statusFilter !== '') {
    $stmt = safe_query($conn, "SELECT * FROM documents WHERE type = 'Outgoing' AND status = ? ORDER BY (status = 'For Release') DESC, date_created DESC", 's', [$statusFilter]);
} else {
    $stmt = safe_query($conn, "SELECT * FROM documents WHERE type = 'Outgoing' ORDER BY (status = 'For Release') DESC, date_created DESC");
}

if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $documents[] = $row;
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
                <h2 class="mb-1 text-primary">Outgoing Documents</h2>
                <p class="text-muted mb-0">Prepare documents for release, then mark them released when handed over.</p>
            </div>
        </div>
    </div>
    <?php if ($message): ?>
        <div class="col-12"><?php echo $message; ?></div>
    <?php endif; ?>
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="GET" class="row g-2 mb-3">
                    <div class="col-12 col-md-7">
                        <input type="search" name="search" class="form-control" placeholder="Search outgoing documents" value="<?php echo sanitize($search); ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="visually-hidden" for="outgoingStatusFilter">Filter by status</label>
                        <select class="form-select" id="outgoingStatusFilter" name="status">
                            <option value="">All statuses</option>
                            <?php foreach (['For Release', 'Released'] as $filterOption): ?>
                                <option value="<?php echo sanitize($filterOption); ?>" <?php echo $statusFilter === $filterOption ? 'selected' : ''; ?>><?php echo sanitize($filterOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-2 d-grid">
                        <button class="btn btn-primary" type="submit">Search</button>
                    </div>
                </form>
                <div class="table-responsive outgoing-documents-table-responsive">
                    <table class="table table-bordered align-middle mb-0 outgoing-documents-table">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Recipient</th>
                                <th>Document type</th>
                                <th>Client Contact</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($documents) === 0): ?>
                                <tr><td colspan="6" class="text-center text-muted">No outgoing documents found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($documents as $document): ?>
                                    <tr>
                                        <td data-label="Date"><?php echo sanitize(date('Y-m-d', strtotime($document['date_created']))); ?></td>
                                        <td data-label="Recipient"><?php echo sanitize($document['recipient']); ?></td>
                                        <td data-label="Document type"><?php echo sanitize($document['subject']); ?></td>
                                        <td data-label="Client contact"><?php echo sanitize($document['client_phone'] ?? 'Not provided'); ?></td>
                                        <td data-label="Status">
                                            <span class="badge <?php echo $document['status'] === 'Released' ? 'text-bg-success' : 'text-bg-warning'; ?>"><?php echo sanitize($document['status']); ?></span>
                                            <?php if (($document['submitter_type'] ?? '') === 'Faculty' && (int)($document['is_priority'] ?? 0) === 1): ?>
                                                <span class="badge text-bg-warning ms-1"><i class="bi bi-star-fill me-1" aria-hidden="true"></i>Priority</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Actions">
                                            <div class="table-actions">
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="editDocument(<?php echo (int)$document['doc_id']; ?>, <?php echo htmlspecialchars(json_encode($document['recipient'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($document['client_phone'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($document['subject'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($document['remarks'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode(date('Y-m-d', strtotime($document['date_created'])), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                                                <i class="bi bi-pencil"></i> Edit
                                            </button>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                <input type="hidden" name="status_doc_id" value="<?php echo (int)$document['doc_id']; ?>">
                                                <input type="hidden" name="new_status" value="<?php echo $document['status'] === 'Released' ? 'For Release' : 'Released'; ?>">
                                                <button type="submit" name="set_outgoing_status" class="btn btn-sm <?php echo $document['status'] === 'Released' ? 'btn-outline-secondary' : 'btn-success'; ?>">
                                                    <i class="bi <?php echo $document['status'] === 'Released' ? 'bi-arrow-counterclockwise' : 'bi-check2-circle'; ?> me-1" aria-hidden="true"></i>
                                                    <?php echo $document['status'] === 'Released' ? 'Set For Release' : 'Mark Released'; ?>
                                                </button>
                                            </form>
                                            <?php if ($document['status'] === 'For Release' && is_valid_contact_number(trim((string)($document['client_phone'] ?? '')))): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-success js-send-release-sms"
                                                    data-document-id="<?php echo (int)$document['doc_id']; ?>"
                                                    data-document-subject="<?php echo sanitize($document['subject']); ?>"
                                                    data-document-recipient="<?php echo sanitize($document['recipient']); ?>"
                                                    data-document-phone="<?php echo sanitize($document['client_phone'] ?? ''); ?>"
                                                    data-document-status="<?php echo sanitize($document['status']); ?>"
                                                >
                                                    <i class="bi bi-chat-dots me-1" aria-hidden="true"></i>Send SMS
                                                </button>
                                            <?php endif; ?>
                                            </div>
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
</div>

<div class="modal fade" id="releaseSmsConfirmModal" tabindex="-1" aria-labelledby="releaseSmsConfirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title text-primary" id="releaseSmsConfirmTitle">
                    <i class="bi bi-chat-dots me-2" aria-hidden="true"></i>Send release notification?
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="releaseSmsDescription" class="mb-3"></p>
                <dl class="row mb-3">
                    <dt class="col-4">Document</dt>
                    <dd class="col-8" id="releaseSmsSubject"></dd>
                    <dt class="col-4">Recipient</dt>
                    <dd class="col-8" id="releaseSmsRecipient"></dd>
                    <dt class="col-4">Phone</dt>
                    <dd class="col-8" id="releaseSmsPhone"></dd>
                </dl>
                <div class="alert alert-warning mb-0" id="releaseSmsMissingPhone" role="alert" hidden>
                    No valid client phone number is saved. Edit this document to add a number before sending.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" id="releaseSmsForm" class="m-0" data-admin-confirm="false">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <input type="hidden" name="sms_doc_id" id="releaseSmsDocumentId" value="">
                    <button type="submit" name="send_client_sms" id="releaseSmsSubmit" class="btn btn-success">
                        <i class="bi bi-send me-1" aria-hidden="true"></i>Send notification
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($smsOutcome !== null): ?>
    <?php
    $smsTone = in_array($smsOutcome['type'], ['success', 'warning', 'danger'], true)
        ? $smsOutcome['type']
        : 'info';
    $smsIcon = [
        'success' => 'bi-check-circle-fill',
        'warning' => 'bi-hourglass-split',
        'danger' => 'bi-exclamation-triangle-fill',
        'info' => 'bi-info-circle-fill',
    ][$smsTone];
    ?>
    <div class="modal fade" id="releaseSmsOutcomeModal" tabindex="-1" aria-labelledby="releaseSmsOutcomeTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title d-flex align-items-center gap-2 text-<?php echo sanitize($smsTone); ?>" id="releaseSmsOutcomeTitle">
                        <i class="bi <?php echo sanitize($smsIcon); ?>" aria-hidden="true"></i>
                        <?php echo sanitize($smsOutcome['title']); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" role="alert" aria-live="assertive"><?php echo sanitize($smsOutcome['detail']); ?></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Got it</button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Outgoing Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="editId">
                    <div class="mb-3">
                        <label class="form-label" for="editDate">Date</label>
                        <input type="date" id="editDate" name="document_date" class="form-control" required>
                    </div>
                    <div class="mb-3" <?php echo $mobileFormLabels['recipientEnabled'] ? '' : 'hidden'; ?>>
                        <label class="form-label" for="editRecipient"><?php echo sanitize($mobileFormLabels['recipient']); ?></label>
                        <input type="text" id="editRecipient" name="recipient" class="form-control" <?php echo $mobileFormLabels['recipientEnabled'] ? 'required' : 'disabled'; ?>>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="editClientPhone"><?php echo sanitize($mobileFormLabels['contactNumber']); ?> (optional)</label>
                        <input type="tel" id="editClientPhone" name="client_phone" class="form-control" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" placeholder="09123456789" title="Enter exactly 11 digits.">
                        <div class="form-text">Enter exactly 11 digits, or leave blank.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="editSubject"><?php echo sanitize($mobileFormLabels['documentType']); ?></label>
                        <input type="text" id="editSubject" name="subject" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="editRemarks"><?php echo sanitize($mobileFormLabels['remarks']); ?></label>
                        <textarea id="editRemarks" name="remarks" class="form-control" rows="4"></textarea>
                    </div>
                    <div class="mb-3">
                    <div class="d-grid">
                        <button type="submit" name="update" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function editDocument(id, recipient, clientPhone, subject, remarks, date) {
    document.getElementById('editId').value = id;
    document.getElementById('editDate').value = date;
    document.getElementById('editRecipient').value = recipient;
    document.getElementById('editClientPhone').value = clientPhone;
    document.getElementById('editSubject').value = subject;
    document.getElementById('editRemarks').value = remarks;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

document.addEventListener('DOMContentLoaded', function () {
    const confirmModalElement = document.getElementById('releaseSmsConfirmModal');
    const confirmModal = new bootstrap.Modal(confirmModalElement);
    const validPhonePattern = /^[0-9]{11}$/;

    document.querySelectorAll('.js-send-release-sms').forEach(function (button) {
        button.addEventListener('click', function () {
            const status = button.dataset.documentStatus;
            const phone = button.dataset.documentPhone.trim();
            const hasValidPhone = validPhonePattern.test(phone);

            document.getElementById('releaseSmsDocumentId').value = button.dataset.documentId;
            document.getElementById('releaseSmsSubject').textContent = button.dataset.documentSubject;
            document.getElementById('releaseSmsRecipient').textContent = button.dataset.documentRecipient;
            document.getElementById('releaseSmsPhone').textContent = phone || 'Not provided';
            document.getElementById('releaseSmsDescription').textContent = status === 'For Release'
                ? 'This will notify the recipient that their document is ready to claim.'
                : 'This will notify the recipient that their document has been released.';
            document.getElementById('releaseSmsMissingPhone').hidden = hasValidPhone;
            document.getElementById('releaseSmsSubmit').disabled = !hasValidPhone;
            confirmModal.show();
        });
    });

    const outcomeModal = document.getElementById('releaseSmsOutcomeModal');
    if (outcomeModal) {
        new bootstrap.Modal(outcomeModal).show();
    }
});

</script>

<?php include 'footer.php'; ?>
