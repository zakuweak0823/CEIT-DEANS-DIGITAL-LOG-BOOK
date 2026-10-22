<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'mobile_form_settings.php';
require_once 'document_tracking.php';
require_once 'sla_support.php';
ensure_admin();

$mobileFormLabels = get_mobile_form_labels($conn);
try {
    ensure_document_tracking_schema($conn);
    $documentTrackingSettings = get_document_tracking_settings($conn);
    foreach ([
        'ALTER TABLE documents ADD COLUMN IF NOT EXISTS client_phone VARCHAR(20) DEFAULT NULL',
        "ALTER TABLE documents ADD COLUMN IF NOT EXISTS submitter_type VARCHAR(20) NOT NULL DEFAULT 'Other'",
        'ALTER TABLE documents ADD COLUMN IF NOT EXISTS is_priority TINYINT(1) NOT NULL DEFAULT 0',
        "UPDATE documents SET submitter_type = sender WHERE submitter_type = 'Other' AND sender IN ('Student', 'Faculty')",
        "UPDATE documents SET status = 'Pending' WHERE type = 'Incoming' AND status = 'Received'",
    ] as $schemaSql) {
        if ($conn->query($schemaSql) === false) {
            throw new RuntimeException($conn->error);
        }
    }
} catch (Throwable $error) {
    error_log('Incoming document tracking setup failed: ' . $error->getMessage());
    http_response_code(500);
    exit('Incoming document storage is unavailable. Check the server log and try again.');
}

$message = '';
$releaseOutcome = null;
$setReleaseOutcome = static function ($title, $detail, $type = 'info') use (&$releaseOutcome) {
    $releaseOutcome = ['title' => $title, 'detail' => $detail, 'type' => $type];
};
$sender = '';
$submitterName = '';
$submitterType = 'Other';
$recipient = '';
$subject = '';
$remarks = '';
$clientPhone = '';
$search = trim($_GET['search'] ?? '');
$documentIdParam = $_GET['document_id'] ?? '';
$documentIdFilter = is_string($documentIdParam) && ctype_digit($documentIdParam) ? (int)$documentIdParam : 0;
$statusFilter = trim($_GET['status'] ?? '');
$overdueFilter = ($_GET['overdue'] ?? '') === '1';
$overdueDays = (int)get_sla_settings($conn)['incomingDays'];
$validStatusFilters = ['Pending', 'For Release', 'Released'];
if (!in_array($statusFilter, $validStatusFilters, true)) {
    $statusFilter = '';
}

if (isset($_POST['issue_tracking_qr'])) {
    $documentId = (int)($_POST['tracking_doc_id'] ?? 0);
    if (!$documentTrackingSettings['enabled']) {
        $message = show_alert('Document tracking is turned off in Admin Settings.', 'warning');
    } elseif ($documentId <= 0) {
        $message = show_alert('Select a valid incoming document.', 'danger');
    } else {
        $conn->begin_transaction();
        try {
            $stmt = safe_query($conn, "SELECT tracking_token FROM documents WHERE doc_id = ? AND type = 'Incoming' FOR UPDATE", 'i', [$documentId]);
            if ($stmt === false) {
                throw new RuntimeException('Could not load the selected document.');
            }
            $result = mysqli_stmt_get_result($stmt);
            $document = $result === false ? false : mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
            if ($document === false) {
                throw new RuntimeException('Could not read the selected document.');
            }
            if ($document === null) {
                throw new DomainException('Incoming document was not found.');
            }
            if ($document['tracking_token'] === null || $document['tracking_token'] === '') {
                $trackingToken = create_document_tracking_token();
                $stmt = safe_query($conn, 'UPDATE documents SET tracking_token = ? WHERE doc_id = ? AND tracking_token IS NULL', 'si', [$trackingToken, $documentId]);
                if ($stmt === false || mysqli_stmt_affected_rows($stmt) !== 1) {
                    if ($stmt !== false) mysqli_stmt_close($stmt);
                    throw new RuntimeException('Could not issue a tracking QR code.');
                }
                mysqli_stmt_close($stmt);
            }
            $conn->commit();
            $message = show_alert('The document tracking QR code is ready in the table.', 'success');
        } catch (DomainException $error) {
            $conn->rollback();
            $message = show_alert($error->getMessage(), 'danger');
        } catch (Throwable $error) {
            $conn->rollback();
            error_log('Incoming document tracking QR issuance failed: ' . $error->getMessage());
            $message = show_alert('The tracking QR could not be issued. Check the server log and try again.', 'danger');
        }
    }
} elseif (isset($_POST['save'])) {
    $sender = trim($_POST['sender'] ?? '');
    $submitterName = trim($_POST['submitter_name'] ?? '');
    $submitterType = trim($_POST['submitter_type'] ?? 'Other');
    $recipient = '';
    $subject = trim($_POST['subject'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $clientPhone = contact_number_input($_POST['client_phone'] ?? '');

    if ($subject === '' || $submitterName === '' || strlen($submitterName) > 255 || !in_array($submitterType, ['Student', 'Faculty', 'Other'], true)) {
        $message = show_alert('Submitter name, submitter type, and document type are required. The name must be 255 characters or fewer.', 'danger');
    } elseif ($clientPhone === null || ($clientPhone !== '' && !is_valid_contact_number($clientPhone))) {
        $message = show_alert('Enter an 11-digit contact number using digits only, or leave it blank.', 'danger');
    } else {
        $phoneValue = $clientPhone === '' ? null : $clientPhone;
        $stmt = safe_query($conn, "INSERT INTO documents(type, sender, submitter_name, recipient, client_phone, subject, remarks, status, submitter_type) VALUES('Incoming', ?, ?, ?, ?, ?, ?, 'Pending', ?)", 'sssssss', [$sender, $submitterName, $recipient, $phoneValue, $subject, $remarks, $submitterType]);
        if ($stmt !== false) {
            mysqli_stmt_close($stmt);
            $message = show_alert('Incoming document saved successfully.');
            $sender = $submitterName = $recipient = $subject = $remarks = $clientPhone = '';
            $submitterType = 'Other';
        } else {
            $message = show_alert('Failed to save incoming document.', 'danger');
        }
    }
}

if (isset($_POST['update'])) {
    $id = (int)($_POST['id'] ?? 0);
    $sender = trim($_POST['sender'] ?? '');
    $submitterName = trim($_POST['submitter_name'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $clientPhone = contact_number_input($_POST['client_phone'] ?? '');
    $submitterType = trim($_POST['submitter_type'] ?? 'Other');

    if ($id > 0 && $submitterName !== '' && strlen($submitterName) <= 255 && $subject !== '' && in_array($submitterType, ['Student', 'Faculty', 'Other'], true) && $clientPhone !== null && ($clientPhone === '' || is_valid_contact_number($clientPhone))) {
        $phoneValue = $clientPhone === '' ? null : $clientPhone;
        $stmt = safe_query($conn, "UPDATE documents SET sender = ?, submitter_name = ?, client_phone = ?, subject = ?, remarks = ?, submitter_type = ? WHERE doc_id = ? AND type = 'Incoming'", 'ssssssi', [$sender, $submitterName, $phoneValue, $subject, $remarks, $submitterType, $id]);
        if ($stmt !== false) {
            mysqli_stmt_close($stmt);
            $message = show_alert('Incoming document updated successfully.');
        } else {
            $message = show_alert('Failed to update incoming document.', 'danger');
        }
    } else {
        $message = show_alert($clientPhone === null || ($clientPhone !== '' && !is_valid_contact_number($clientPhone))
            ? 'Enter an 11-digit contact number using digits only, or leave it blank.'
            : 'Check the submitter name, submitter type, document type, and record details.', 'danger');
    }
}

if (isset($_POST['prepare_release'])) {
    $id = (int)($_POST['prepare_release_id'] ?? 0);
    if ($id <= 0) {
        $message = show_alert('Invalid document selected.', 'danger');
        $setReleaseOutcome('Could not prepare document', 'The selected document ID is invalid. Refresh the page and try again.', 'danger');
    } else {
        $stmt = safe_query($conn, "SELECT sender, recipient, status FROM documents WHERE doc_id = ? AND type = 'Incoming'", 'i', [$id]);
        $releaseDocument = null;
        if ($stmt !== false) {
            $result = mysqli_stmt_get_result($stmt);
            $releaseDocument = mysqli_fetch_assoc($result) ?: null;
            mysqli_stmt_close($stmt);
        }
        if (!$releaseDocument) {
            $message = show_alert('This incoming document was not found or has already been moved.', 'danger');
            $setReleaseOutcome('Document not found', 'This incoming document may have already been moved. Refresh the list and check Outgoing Documents.', 'danger');
        } elseif ($releaseDocument['status'] !== 'Pending') {
            $message = show_alert('Only pending documents can be prepared for release.', 'warning');
            $setReleaseOutcome('Document is no longer pending', 'Only pending incoming documents can be prepared for release. Refresh the list to see its current status.', 'warning');
        } else {
            // Moving an incoming item to outgoing reverses who sent and receives it.
            // The status predicate also protects against a concurrent update after the read.
            $outgoingSender = trim((string)$releaseDocument['recipient']) !== ''
                ? $releaseDocument['recipient']
                : "Dean's Office";
            $stmt = safe_query($conn, "UPDATE documents SET type = 'Outgoing', status = 'For Release', sender = ?, recipient = ? WHERE doc_id = ? AND type = 'Incoming' AND status = 'Pending'", 'ssi', [$outgoingSender, $releaseDocument['sender'], $id]);
            if ($stmt === false || mysqli_stmt_affected_rows($stmt) !== 1) {
                if ($stmt !== false) mysqli_stmt_close($stmt);
                $message = show_alert('The document could not be prepared. Refresh the page and try again.', 'danger');
                $setReleaseOutcome('Could not prepare document', 'The document may have changed while you were working. Refresh the page and check its status before trying again.', 'danger');
            } else {
                mysqli_stmt_close($stmt);
                $message = show_alert('Document moved to Outgoing as For Release.');
                $setReleaseOutcome('Document prepared for release', 'The document is now in Outgoing with status “For Release”. Its previous recipient is used as the outgoing sender, or Dean’s Office if no recipient was saved; the original sender remains the recipient.', 'success');
            }
        }
    }
}

if (isset($_POST['toggle_priority'])) {
    $id = (int)($_POST['priority_doc_id'] ?? 0);
    $stmt = safe_query($conn, "UPDATE documents SET is_priority = IF(is_priority = 1, 0, 1) WHERE doc_id = ? AND type = 'Incoming' AND submitter_type = 'Faculty'", 'i', [$id]);
    if ($stmt !== false) {
        $updated = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        $message = $updated > 0
            ? show_alert('Faculty submission priority updated.')
            : show_alert('Priority is available only for faculty submissions.', 'warning');
    } else {
        $message = show_alert('Could not update submission priority.', 'danger');
    }
}

$submitterFilter = $_GET['submitter'] ?? 'All';
if (!in_array($submitterFilter, ['All', 'Faculty', 'Student', 'Other'], true)) {
    $submitterFilter = 'All';
}
$documents = [];
if ($overdueFilter && $documentIdFilter === 0) {
    // The dashboard's overdue tile must list only documents past their processing time.
    $stmt = safe_query($conn, "SELECT * FROM documents WHERE type = 'Incoming' AND status = 'Pending' AND date_created < (NOW() - INTERVAL ? DAY) ORDER BY date_created ASC", 'i', [$overdueDays]);
} elseif ($documentIdFilter > 0) {
    // Quick Submit's Manage action must show only the selected incoming record.
    $stmt = safe_query($conn, "SELECT * FROM documents WHERE type = 'Incoming' AND doc_id = ?", 'i', [$documentIdFilter]);
} elseif ($search !== '' && $statusFilter !== '' && $submitterFilter !== 'All') {
    $searchTerm = "%{$search}%";
    $stmt = safe_query($conn, "SELECT * FROM documents WHERE type = 'Incoming' AND status = ? AND submitter_type = ? AND (submitter_name LIKE ? OR sender LIKE ? OR recipient LIKE ? OR subject LIKE ? OR remarks LIKE ?) ORDER BY is_priority DESC, date_created DESC", 'sssssss', [$statusFilter, $submitterFilter, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
} elseif ($search !== '' && $statusFilter !== '') {
    $searchTerm = "%{$search}%";
    $stmt = safe_query($conn, "SELECT * FROM documents WHERE type = 'Incoming' AND status = ? AND (submitter_name LIKE ? OR sender LIKE ? OR recipient LIKE ? OR subject LIKE ? OR remarks LIKE ?) ORDER BY is_priority DESC, date_created DESC", 'ssssss', [$statusFilter, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
} elseif ($search !== '' && $submitterFilter !== 'All') {
    $searchTerm = "%{$search}%";
    $stmt = safe_query($conn, "SELECT * FROM documents WHERE type = 'Incoming' AND submitter_type = ? AND (submitter_name LIKE ? OR sender LIKE ? OR recipient LIKE ? OR subject LIKE ? OR remarks LIKE ? OR status LIKE ?) ORDER BY is_priority DESC, date_created DESC", 'sssssss', [$submitterFilter, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
} elseif ($search !== '') {
    $searchTerm = "%{$search}%";
    $stmt = safe_query($conn, "SELECT * FROM documents WHERE type = 'Incoming' AND (submitter_name LIKE ? OR sender LIKE ? OR recipient LIKE ? OR subject LIKE ? OR remarks LIKE ? OR status LIKE ?) ORDER BY is_priority DESC, date_created DESC", 'ssssss', [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
} elseif ($statusFilter !== '' && $submitterFilter !== 'All') {
    $stmt = safe_query($conn, "SELECT * FROM documents WHERE type = 'Incoming' AND status = ? AND submitter_type = ? ORDER BY is_priority DESC, date_created DESC", 'ss', [$statusFilter, $submitterFilter]);
} elseif ($statusFilter !== '') {
    $stmt = safe_query($conn, "SELECT * FROM documents WHERE type = 'Incoming' AND status = ? ORDER BY is_priority DESC, date_created DESC", 's', [$statusFilter]);
} elseif ($submitterFilter !== 'All') {
    $stmt = safe_query($conn, "SELECT * FROM documents WHERE type = 'Incoming' AND submitter_type = ? ORDER BY is_priority DESC, date_created DESC", 's', [$submitterFilter]);
} else {
    $stmt = safe_query($conn, "SELECT * FROM documents WHERE type = 'Incoming' ORDER BY is_priority DESC, date_created DESC");
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
                <h2 class="mb-1 text-primary">Incoming Documents</h2>
                <p class="text-muted mb-0">Review incoming submissions, identify who submitted them, and issue QR tracking codes.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#incomingForm">
                <i class="bi bi-plus-lg"></i> Add Document
            </button>
            <a class="btn btn-primary" href="quick_document">
                <i class="bi bi-lightning-charge"></i> Quick Submit
            </a>
            </div>
        </div>
    </div>
    <?php if ($message): ?>
        <div class="col-12"><?php echo $message; ?></div>
    <?php endif; ?>
    <?php if ($overdueFilter && $documentIdFilter === 0): ?>
        <div class="col-12">
            <div class="selected-record-banner" role="status">
                <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                <div>
                    <strong>Overdue incoming documents</strong>
                    <span>Showing <?php echo count($documents); ?> open document(s) waiting longer than <?php echo (int)$overdueDays; ?> day(s).</span>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="incoming">Show all incoming documents</a>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($documentIdFilter > 0): ?>
        <div class="col-12">
            <div class="selected-record-banner" role="status">
                <i class="bi bi-bullseye" aria-hidden="true"></i>
                <div>
                    <strong>Selected record</strong>
                    <span><?php echo $documents ? sanitize($documents[0]['subject']) . ' · ' . sanitize($documents[0]['status']) : 'This record could not be found.'; ?></span>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="incoming">Show all incoming documents</a>
            </div>
        </div>
    <?php endif; ?>
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <nav class="nav nav-pills flex-wrap gap-2 mb-3" aria-label="Incoming submission sections">
                    <?php foreach (['All' => 'All submissions', 'Faculty' => 'Faculty', 'Student' => 'Students', 'Other' => 'Other'] as $typeValue => $typeLabel): ?>
                        <?php $typeQuery = array_filter(['search' => $search, 'status' => $statusFilter, 'submitter' => $typeValue === 'All' ? '' : $typeValue], static function ($value) { return $value !== ''; }); ?>
                        <a class="nav-link<?php echo $submitterFilter === $typeValue ? ' active' : ''; ?>" href="incoming<?php echo $typeQuery ? '?' . http_build_query($typeQuery) : ''; ?>"<?php echo $submitterFilter === $typeValue ? ' aria-current="page"' : ''; ?>>
                            <?php echo sanitize($typeLabel); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <form method="GET" class="row g-2 mb-3">
                    <?php if ($submitterFilter !== 'All'): ?><input type="hidden" name="submitter" value="<?php echo sanitize($submitterFilter); ?>"><?php endif; ?>
                    <div class="col-12 col-md-7">
                        <input type="search" name="search" class="form-control" placeholder="Search by subject, name, or reference" value="<?php echo sanitize($search); ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="visually-hidden" for="incomingStatusFilter">Filter by status</label>
                        <select class="form-select" id="incomingStatusFilter" name="status">
                            <option value="">All statuses</option>
                            <?php foreach ($validStatusFilters as $filterOption): ?>
                                <option value="<?php echo sanitize($filterOption); ?>" <?php echo $statusFilter === $filterOption ? 'selected' : ''; ?>><?php echo sanitize($filterOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-2 d-grid">
                        <button class="btn btn-primary">Search</button>
                    </div>
                </form>
                <!-- Use the shared row-separated table skin consistently across admin lists. -->
                <div class="table-responsive">
                    <table class="table align-middle mb-0 incoming-documents-table">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Submitted by</th>
                                <th scope="col">Reference</th>
                                <th scope="col">Tracking QR</th>
                                <th scope="col">Subject</th>
                                <th scope="col">Recipient</th>
                                <th scope="col">Contact number</th>
                                <th scope="col">Status</th>
                                <th scope="col">Priority</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($documents) === 0): ?>
                                <tr><td colspan="10" class="text-center text-muted">No incoming documents found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($documents as $document): ?>
                                    <tr>
                                        <td data-label="Date"><?php echo sanitize(date('M j, Y', strtotime($document['date_created']))); ?><div class="small text-muted"><?php echo sanitize(date('g:i A', strtotime($document['date_created']))); ?></div></td>
                                        <td data-label="Submitted by">
                                            <div class="table-cell-badges">
                                                <span class="badge text-bg-light border"><?php echo sanitize($document['submitter_type']); ?></span>
                                            </div>
                                            <div class="mt-1 fw-semibold"><?php echo sanitize(trim((string)($document['submitter_name'] ?? '')) !== '' ? $document['submitter_name'] : 'Name not recorded'); ?></div>
                                        </td>
                                        <td data-label="Reference"><code><?php echo sanitize(document_tracking_reference($document['doc_id'])); ?></code></td>
                                        <td data-label="Tracking QR">
                                            <?php if (!$documentTrackingSettings['enabled']): ?>
                                                <span class="small text-muted">QR tracking is disabled</span>
                                            <?php elseif (!empty($document['tracking_token'])): ?>
                                                <?php $documentTrackerUrl = $documentTrackingSettings['baseUrl'] . '?token=' . rawurlencode($document['tracking_token']); ?>
                                                <button type="button" class="btn btn-sm btn-outline-success" aria-label="View tracking QR code for <?php echo sanitize(document_tracking_reference($document['doc_id'])); ?>" onclick="showDocumentTrackingQr(<?php echo htmlspecialchars(json_encode($documentTrackerUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode(document_tracking_reference($document['doc_id']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                                                    <i class="bi bi-qr-code me-1" aria-hidden="true"></i>View QR code
                                                </button>
                                            <?php else: ?>
                                                <form method="POST" class="m-0">
                                                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                    <input type="hidden" name="tracking_doc_id" value="<?php echo (int)$document['doc_id']; ?>">
                                                    <button type="submit" name="issue_tracking_qr" value="1" class="btn btn-sm btn-outline-success">
                                                        <i class="bi bi-qr-code me-1" aria-hidden="true"></i>Create QR code
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Subject"><?php echo sanitize($document['subject']); ?></td>
                                        <td data-label="Recipient"><?php echo sanitize(trim((string)($document['recipient'] ?? '')) !== '' ? $document['recipient'] : 'Not provided'); ?></td>
                                        <td data-label="Contact number"><?php echo sanitize($document['client_phone'] ?? 'Not provided'); ?></td>
                                        <td data-label="Status">
                                            <span class="badge <?php echo admin_status_badge_class($document['status']); ?>">
                                                <?php echo sanitize($document['status']); ?>
                                            </span>
                                        </td>
                                        <td data-label="Priority">
                                            <?php if ($document['submitter_type'] === 'Faculty'): ?>
                                                <form method="POST" class="document-priority-control">
                                                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                    <input type="hidden" name="priority_doc_id" value="<?php echo (int)$document['doc_id']; ?>">
                                                    <button class="btn btn-sm <?php echo (int)$document['is_priority'] === 1 ? 'btn-warning' : 'btn-outline-secondary'; ?>" type="submit" name="toggle_priority" aria-label="<?php echo (int)$document['is_priority'] === 1 ? 'Remove priority from' : 'Mark as priority'; ?> <?php echo sanitize(document_tracking_reference($document['doc_id'])); ?>">
                                                        <i class="bi bi-star<?php echo (int)$document['is_priority'] === 1 ? '-fill' : ''; ?> me-1" aria-hidden="true"></i><?php echo (int)$document['is_priority'] === 1 ? 'Priority' : 'Standard'; ?>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="badge rounded-pill text-bg-light border">Standard</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Actions">
                                            <div class="table-actions document-row-actions">
                                            <button type="button" class="btn btn-sm btn-outline-primary document-action-button" title="Edit document" aria-label="Edit <?php echo sanitize(document_tracking_reference($document['doc_id'])); ?>" onclick="editDocument(<?php echo (int)$document['doc_id']; ?>, <?php echo htmlspecialchars(json_encode($document['sender'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($document['submitter_name'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($document['client_phone'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($document['subject'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($document['remarks'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($document['submitter_type'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                                                <i class="bi bi-pencil" aria-hidden="true"></i><span class="action-label">Edit</span>
                                            </button>
                                            <?php if ($document['status'] === 'Pending'): ?>
                                                <button
                                                    class="btn btn-sm btn-outline-success js-prepare-release document-action-button"
                                                    type="button"
                                                    title="Prepare for release"
                                                    aria-label="Prepare <?php echo sanitize(document_tracking_reference($document['doc_id'])); ?> for release"
                                                    data-document-id="<?php echo (int)$document['doc_id']; ?>"
                                                    data-document-subject="<?php echo sanitize($document['subject']); ?>"
                                                    data-document-sender="<?php echo sanitize($document['sender']); ?>"
                                                    data-document-recipient="<?php echo sanitize($document['recipient']); ?>"
                                                >
                                                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span class="action-label">Prepare for release</span>
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
<div class="modal fade" id="incomingForm" tabindex="-1" aria-labelledby="incomingFormTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title text-primary" id="incomingFormTitle">Add Incoming Document</h5>
                    <p class="mb-0 mt-1 small text-muted">Contact information is optional.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" novalidate>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <div class="mb-3">
                        <label class="form-label" for="submitter_name">Name of person submitting</label>
                        <input type="text" id="submitter_name" name="submitter_name" class="form-control" maxlength="255" value="<?php echo sanitize($submitterName); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="submitter_type">Submitter type</label>
                        <select id="submitter_type" name="submitter_type" class="form-select" required>
                            <option value="Student" <?php echo $submitterType === 'Student' ? 'selected' : ''; ?>>Student</option>
                            <option value="Faculty" <?php echo $submitterType === 'Faculty' ? 'selected' : ''; ?>>Faculty</option>
                            <option value="Other" <?php echo $submitterType === 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="sender">Submitting department or office <span class="text-muted">(optional)</span></label>
                        <input type="text" id="sender" name="sender" class="form-control" value="<?php echo sanitize($sender); ?>" maxlength="255">
                        <div class="form-text">Add this only when the document is submitted on behalf of a department or office.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="subject"><?php echo sanitize($mobileFormLabels['documentType']); ?></label>
                        <input type="text" id="subject" name="subject" class="form-control" value="<?php echo sanitize($subject); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="client_phone"><?php echo sanitize($mobileFormLabels['contactNumber']); ?> <span class="text-muted">(optional)</span></label>
                        <input type="tel" id="client_phone" name="client_phone" class="form-control" value="<?php echo sanitize($clientPhone ?? ''); ?>" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" placeholder="09123456789" title="Enter exactly 11 digits.">
                        <div class="form-text">Enter exactly 11 digits, or leave blank.</div>
                    </div>
                    <div>
                        <label class="form-label" for="remarks"><?php echo sanitize($mobileFormLabels['remarks']); ?> <span class="text-muted">(optional)</span></label>
                        <textarea id="remarks" name="remarks" class="form-control" rows="3"><?php echo sanitize($remarks); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save" class="btn btn-primary">Save Document</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Incoming Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="editId">
                    <div class="mb-3">
                        <label class="form-label" for="editSubmitterName">Name of person submitting</label>
                        <input type="text" id="editSubmitterName" name="submitter_name" class="form-control" maxlength="255" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="editSender">Submitting department or office <span class="text-muted">(optional)</span></label>
                        <input type="text" id="editSender" name="sender" class="form-control" maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="editSubmitterType">Submitter type</label>
                        <select id="editSubmitterType" name="submitter_type" class="form-select" required>
                            <option value="Student">Student</option>
                            <option value="Faculty">Faculty</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="editClientPhone"><?php echo sanitize($mobileFormLabels['contactNumber']); ?></label>
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
                    <div class="d-grid">
                        <button type="submit" name="update" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="documentTrackingQrModal" tabindex="-1" aria-labelledby="documentTrackingQrTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered qr-code-modal">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title text-primary" id="documentTrackingQrTitle">Document tracking QR</h5>
                    <p class="small text-muted mb-0" id="documentTrackingQrReference"></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <p class="small text-secondary">This office-issued QR opens the dedicated document status tracker.</p>
                <div class="qr-code-frame">
                    <canvas id="documentTrackingQrCanvas" aria-label="Document status tracking QR code"></canvas>
                </div>
                <div id="documentTrackingQrError" class="alert alert-danger mt-3" role="alert" hidden></div>
                <a id="documentTrackingQrLink" class="btn btn-outline-primary btn-sm tracking-link-button mt-3" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Open tracking page</a>
            </div>
            <div class="modal-footer">
                <a id="downloadDocumentTrackingQr" class="btn btn-primary disabled" aria-disabled="true" download="document-tracking.png"><i class="bi bi-download me-1" aria-hidden="true"></i>Download QR</a>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="prepareReleaseModal" tabindex="-1" aria-labelledby="prepareReleaseTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title text-primary" id="prepareReleaseTitle">
                    <i class="bi bi-box-arrow-up-right me-2" aria-hidden="true"></i>Prepare document for release?
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>This will move the document from <strong>Incoming</strong> to <strong>Outgoing</strong> and set its status to <strong>For Release</strong>.</p>
                <dl class="row mb-3">
                    <dt class="col-4">Subject</dt>
                    <dd class="col-8" id="prepareReleaseSubject"></dd>
                    <dt class="col-4">Current sender</dt>
                    <dd class="col-8" id="prepareReleaseSender"></dd>
                    <dt class="col-4">Current recipient</dt>
                    <dd class="col-8" id="prepareReleaseRecipient"></dd>
                </dl>
                <div class="alert alert-warning mb-0" role="note">
                    The outgoing record will use the current recipient as its sender and the current sender as its recipient. Please confirm these details before continuing.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" id="prepareReleaseForm" class="m-0" data-admin-confirm="false">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <input type="hidden" name="prepare_release_id" id="prepareReleaseId" value="">
                    <button type="submit" name="prepare_release" class="btn btn-success">
                        <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Confirm and prepare
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($releaseOutcome !== null): ?>
    <?php
    $releaseTone = in_array($releaseOutcome['type'], ['success', 'warning', 'danger'], true)
        ? $releaseOutcome['type']
        : 'info';
    $releaseIcon = [
        'success' => 'bi-check-circle-fill',
        'warning' => 'bi-exclamation-circle-fill',
        'danger' => 'bi-exclamation-triangle-fill',
        'info' => 'bi-info-circle-fill',
    ][$releaseTone];
    ?>
    <div class="modal fade" id="prepareReleaseOutcome" tabindex="-1" aria-labelledby="prepareReleaseOutcomeTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title d-flex align-items-center gap-2 text-<?php echo sanitize($releaseTone); ?>" id="prepareReleaseOutcomeTitle">
                        <i class="bi <?php echo sanitize($releaseIcon); ?>" aria-hidden="true"></i>
                        <?php echo sanitize($releaseOutcome['title']); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" role="alert" aria-live="assertive"><?php echo sanitize($releaseOutcome['detail']); ?></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Got it</button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script src="assets/js/qr-tools.bundle.js"></script>
<script>
function editDocument(id, sender, submitterName, clientPhone, subject, remarks, submitterType) {
    document.getElementById('editId').value = id;
    document.getElementById('editSender').value = sender;
    document.getElementById('editSubmitterName').value = submitterName;
    document.getElementById('editClientPhone').value = clientPhone;
    document.getElementById('editSubject').value = subject;
    document.getElementById('editRemarks').value = remarks;
    document.getElementById('editSubmitterType').value = submitterType;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function showDocumentTrackingQr(trackerUrl, reference) {
    const canvas = document.getElementById('documentTrackingQrCanvas');
    const error = document.getElementById('documentTrackingQrError');
    const link = document.getElementById('documentTrackingQrLink');
    const download = document.getElementById('downloadDocumentTrackingQr');
    document.getElementById('documentTrackingQrReference').textContent = reference;
    link.href = trackerUrl;
    link.title = 'Open the tracking page in a new tab';
    error.hidden = true;
    download.classList.add('disabled');
    download.setAttribute('aria-disabled', 'true');
    download.removeAttribute('href');
    download.download = reference.toLowerCase() + '-tracking-qr.png';

    if (!window.QRCode || typeof window.QRCode.toCanvas !== 'function') {
        error.textContent = 'The QR generator could not be loaded. Refresh the page and try again.';
        error.hidden = false;
    } else {
        window.QRCode.toCanvas(canvas, trackerUrl, { width: 480, margin: 2, errorCorrectionLevel: 'H', color: { dark: '#064e3b', light: '#ffffff' } }, function (generationError) {
            if (generationError) {
                error.textContent = 'Could not generate the document tracking QR code.';
                error.hidden = false;
                return;
            }
            download.href = canvas.toDataURL('image/png');
            download.classList.remove('disabled');
            download.setAttribute('aria-disabled', 'false');
        });
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('documentTrackingQrModal')).show();
}

document.addEventListener('DOMContentLoaded', function () {
    const prepareModalElement = document.getElementById('prepareReleaseModal');
    const prepareModal = new bootstrap.Modal(prepareModalElement);
    document.querySelectorAll('.js-prepare-release').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('prepareReleaseId').value = button.dataset.documentId;
            document.getElementById('prepareReleaseSubject').textContent = button.dataset.documentSubject;
            document.getElementById('prepareReleaseSender').textContent = button.dataset.documentSender;
            document.getElementById('prepareReleaseRecipient').textContent = button.dataset.documentRecipient;
            prepareModal.show();
        });
    });

    const outcomeModal = document.getElementById('prepareReleaseOutcome');
    if (outcomeModal) {
        new bootstrap.Modal(outcomeModal).show();
    }

    if (new URLSearchParams(window.location.search).get('open') === 'add') {
        const incomingForm = document.getElementById('incomingForm');
        if (incomingForm) {
            bootstrap.Modal.getOrCreateInstance(incomingForm).show();
        }
    }
});

</script>

<?php include 'footer.php'; ?>
