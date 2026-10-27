<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'mobile_form_settings.php';
require_once 'document_tracking.php';
require_once 'sla_support.php';
require_once 'message_composer.php';
require_once 'requirements_support.php';
require_once 'sms_service.php';
ensure_admin();

$mobileFormLabels = get_mobile_form_labels($conn);
try {
    ensure_document_tracking_schema($conn);
    $documentTrackingSettings = get_document_tracking_settings($conn);
    // These schema and data fixes only need to run once per login session, not on every page view.
    if (empty($_SESSION['incoming_schema_ready'])) {
        foreach ([
            'ALTER TABLE documents ADD COLUMN IF NOT EXISTS client_phone VARCHAR(20) DEFAULT NULL',
            "ALTER TABLE documents ADD COLUMN IF NOT EXISTS submitter_type VARCHAR(20) NOT NULL DEFAULT 'Other'",
            'ALTER TABLE documents ADD COLUMN IF NOT EXISTS is_priority TINYINT(1) NOT NULL DEFAULT 0',
            "UPDATE documents SET submitter_type = sender WHERE submitter_type = 'Other' AND sender IN ('Student', 'Faculty')",
        ] as $schemaSql) {
            if ($conn->query($schemaSql) === false) {
                throw new RuntimeException($conn->error);
            }
        }
        $_SESSION['incoming_schema_ready'] = true;
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

// After an action, the page redirects so refreshing the browser cannot submit the same form twice.
// The result message travels in the session for exactly one page view.
if (isset($_SESSION['incoming_flash']) && is_array($_SESSION['incoming_flash'])) {
    $message = (string)($_SESSION['incoming_flash']['message'] ?? '');
    $flashOutcome = $_SESSION['incoming_flash']['releaseOutcome'] ?? null;
    $releaseOutcome = is_array($flashOutcome) ? $flashOutcome : null;
}
unset($_SESSION['incoming_flash']);
$shouldRedirect = false;
$reopenAddModal = false;

// Read posted values safely: arrays or missing fields become an empty/default string instead of an error.
$postText = static function (string $key, string $default = ''): string {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : $default;
};
$postId = static function (string $key): int {
    $value = isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
    return ctype_digit($value) ? (int)$value : 0;
};
$textLength = static function (string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
};
// One set of rules for adding and editing. Returns an error message, or null when the input is valid.
$validateIncoming = static function (string $submitterName, string $submitterType, string $sender, string $subject, ?string $clientPhone) use ($textLength): ?string {
    if ($submitterName === '' || $subject === '' || !in_array($submitterType, ['Student', 'Faculty', 'Other'], true)) {
        return 'Submitter name, submitter type, and document type are required.';
    }
    if ($textLength($submitterName) > 255 || $textLength($sender) > 255 || $textLength($subject) > 255) {
        return 'The name, department or office, and document type must each be 255 characters or fewer.';
    }
    if ($clientPhone === null || ($clientPhone !== '' && !is_valid_contact_number($clientPhone))) {
        return 'Enter an 11-digit contact number using digits only, or leave it blank.';
    }
    return null;
};

$sender = '';
$submitterName = '';
$submitterType = 'Other';
$recipient = '';
$subject = '';
$remarks = '';
$clientPhone = '';

$search = isset($_GET['search']) && is_string($_GET['search']) ? trim($_GET['search']) : '';
$documentIdParam = $_GET['document_id'] ?? '';
$documentIdFilter = is_string($documentIdParam) && ctype_digit($documentIdParam) ? (int)$documentIdParam : 0;
$statusFilter = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';
// Old bookmarks and links used "Pending" for everything the office still has to handle.
if ($statusFilter === 'Pending') {
    $statusFilter = 'Open';
}
$overdueFilter = ($_GET['overdue'] ?? '') === '1';
$orderFilter = ($_GET['order'] ?? '') === 'newest' ? 'newest' : 'oldest';
$slaSettings = get_sla_settings($conn);
$overdueDays = (int)$slaSettings['incomingDays'];
$validStatusFilters = array_merge(['Open'], doc_status_incoming());
if (!in_array($statusFilter, $validStatusFilters, true)) {
    $statusFilter = '';
}
$submitterFilter = isset($_GET['submitter']) && is_string($_GET['submitter']) ? $_GET['submitter'] : 'All';
if (!in_array($submitterFilter, ['All', 'Faculty', 'Student', 'Other'], true)) {
    $submitterFilter = 'All';
}

if (isset($_POST['issue_tracking_qr'])) {
    $shouldRedirect = true;
    $documentId = $postId('tracking_doc_id');
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
    $sender = $postText('sender');
    $submitterName = $postText('submitter_name');
    $submitterType = $postText('submitter_type', 'Other');
    $recipient = '';
    $subject = $postText('subject');
    $remarks = $postText('remarks');
    $clientPhone = contact_number_input($postText('client_phone'));

    $validationError = $validateIncoming($submitterName, $submitterType, $sender, $subject, $clientPhone);
    if ($validationError !== null) {
        $message = show_alert($validationError, 'danger');
        $reopenAddModal = true;
    } else {
        $phoneValue = $clientPhone === '' ? null : $clientPhone;
        $stmt = safe_query($conn, "INSERT INTO documents(type, sender, submitter_name, recipient, client_phone, subject, remarks, status, submitter_type) VALUES('Incoming', ?, ?, ?, ?, ?, ?, 'Received', ?)", 'sssssss', [$sender, $submitterName, $recipient, $phoneValue, $subject, $remarks, $submitterType]);
        if ($stmt !== false) {
            mysqli_stmt_close($stmt);
            $message = show_alert('Incoming document saved successfully.');
            $shouldRedirect = true;
        } else {
            $message = show_alert('Failed to save incoming document.', 'danger');
            $reopenAddModal = true;
        }
    }
}

if (isset($_POST['update'])) {
    $id = $postId('id');
    $editSender = $postText('sender');
    $editSubmitterName = $postText('submitter_name');
    $editSubject = $postText('subject');
    $editRemarks = $postText('remarks');
    $editClientPhone = contact_number_input($postText('client_phone'));
    $editSubmitterType = $postText('submitter_type', 'Other');

    $validationError = $id <= 0
        ? 'The selected document is invalid. Refresh the page and try again.'
        : $validateIncoming($editSubmitterName, $editSubmitterType, $editSender, $editSubject, $editClientPhone);
    if ($validationError !== null) {
        $message = show_alert($validationError, 'danger');
    } else {
        $phoneValue = $editClientPhone === '' ? null : $editClientPhone;
        $stmt = safe_query($conn, "UPDATE documents SET sender = ?, submitter_name = ?, client_phone = ?, subject = ?, remarks = ?, submitter_type = ? WHERE doc_id = ? AND type = 'Incoming'", 'ssssssi', [$editSender, $editSubmitterName, $phoneValue, $editSubject, $editRemarks, $editSubmitterType, $id]);
        if ($stmt !== false) {
            mysqli_stmt_close($stmt);
            $message = show_alert('Incoming document updated successfully.');
            $shouldRedirect = true;
        } else {
            $message = show_alert('Failed to update incoming document.', 'danger');
        }
    }
}

if (isset($_POST['prepare_release'])) {
    $shouldRedirect = true;
    $id = $postId('prepare_release_id');
    if ($id <= 0) {
        $message = show_alert('Invalid document selected.', 'danger');
        $setReleaseOutcome('Could not prepare document', 'The selected document ID is invalid. Refresh the page and try again.', 'danger');
    } else {
        $stmt = safe_query($conn, "SELECT sender, submitter_name, recipient, status FROM documents WHERE doc_id = ? AND type = 'Incoming'", 'i', [$id]);
        $releaseDocument = null;
        if ($stmt !== false) {
            $result = mysqli_stmt_get_result($stmt);
            $releaseDocument = $result ? (mysqli_fetch_assoc($result) ?: null) : null;
            mysqli_stmt_close($stmt);
        }
        if (!$releaseDocument) {
            $message = show_alert('This incoming document was not found or has already been moved.', 'danger');
            $setReleaseOutcome('Document not found', 'This incoming document may have already been moved. Refresh the list and check Outgoing Documents.', 'danger');
        } elseif (!in_array($releaseDocument['status'], doc_status_can_prepare_release(), true)) {
            $message = show_alert('Only documents that are Received, In Review, Forwarded, or Approved can be prepared for release.', 'warning');
            $setReleaseOutcome('Document cannot be released yet', 'This document is ' . $releaseDocument['status'] . '. Only Received, In Review, Forwarded, or Approved documents can be prepared for release. Refresh the list to see its current status.', 'warning');
        } else {
            // Moving an incoming item to outgoing reverses who sent and receives it.
            // The status predicate also protects against a concurrent update after the read.
            $outgoingSender = trim((string)$releaseDocument['recipient']) !== ''
                ? $releaseDocument['recipient']
                : "Dean's Office";
            // When no department or office was saved, the submitter's name becomes the outgoing recipient
            // so the outgoing record never loses track of who the document goes back to.
            $outgoingRecipient = trim((string)$releaseDocument['sender']) !== ''
                ? $releaseDocument['sender']
                : trim((string)($releaseDocument['submitter_name'] ?? ''));
            $stmt = safe_query($conn, "UPDATE documents SET type = 'Outgoing', status = 'For Release', sender = ?, recipient = ? WHERE doc_id = ? AND type = 'Incoming' AND status IN (" . doc_status_sql_in(doc_status_can_prepare_release()) . ")", 'ssi', [$outgoingSender, $outgoingRecipient, $id]);
            if ($stmt === false || mysqli_stmt_affected_rows($stmt) !== 1) {
                if ($stmt !== false) mysqli_stmt_close($stmt);
                $message = show_alert('The document could not be prepared. Refresh the page and try again.', 'danger');
                $setReleaseOutcome('Could not prepare document', 'The document may have changed while you were working. Refresh the page and check its status before trying again.', 'danger');
            } else {
                mysqli_stmt_close($stmt);
                $message = show_alert('Document moved to Outgoing as For Release.');
                $setReleaseOutcome('Document prepared for release', 'The document is now in Outgoing with status “For Release”. Its previous recipient is used as the outgoing sender, or Dean’s Office if no recipient was saved. The original sender becomes the recipient, or the submitter’s name if no department or office was saved.', 'success');
            }
        }
    }
}

if (isset($_POST['change_status'])) {
    $shouldRedirect = true;
    $id = $postId('status_doc_id');
    $newStatus = $postText('new_status');
    if ($id <= 0 || !in_array($newStatus, doc_status_incoming(), true)) {
        $message = show_alert('Choose a valid status for the selected document.', 'danger');
    } else {
        // Only incoming items can change here; releasing stays a separate action with its own checks.
        $stmt = safe_query($conn, "UPDATE documents SET status = ? WHERE doc_id = ? AND type = 'Incoming' AND status <> ?", 'sis', [$newStatus, $id, $newStatus]);
        if ($stmt === false) {
            $message = show_alert('Could not update the document status.', 'danger');
        } else {
            $changed = mysqli_stmt_affected_rows($stmt) === 1;
            mysqli_stmt_close($stmt);
            $message = $changed
                ? show_alert('Status updated to ' . $newStatus . '.')
                : show_alert('The document was not found or already has that status.', 'warning');
        }
    }
}

// Sends a generated (or staff-edited) SMS to the submitter and returns a short sentence for the page message.
$sendDocumentSms = static function (string $phone, string $text) use ($conn): string {
    if (!is_valid_contact_number($phone)) {
        return ' No SMS was sent because no valid contact number is saved.';
    }
    $smsResult = send_logged_sms($conn, $phone, $text);
    return $smsResult['success'] ? ' SMS sent to the submitter.' : ' The SMS was not sent: ' . $smsResult['message'];
};

if (isset($_POST['mark_incomplete'])) {
    $shouldRedirect = true;
    $id = $postId('incomplete_doc_id');
    $labels = requirements_clean_labels(array_merge(
        isset($_POST['req_items']) && is_array($_POST['req_items']) ? array_filter($_POST['req_items'], 'is_string') : [],
        [$postText('req_other')]
    ));
    $context = $id > 0 ? composer_document_context($conn, $id, $documentTrackingSettings) : null;
    $typeCheck = $id > 0 ? safe_scalar($conn, 'SELECT type FROM documents WHERE doc_id = ?', 'i', [$id]) : null;
    if ($context === null || $typeCheck !== 'Incoming') {
        $message = show_alert('The incoming document was not found.', 'danger');
    } elseif (!$labels) {
        $message = show_alert('Select or type at least one missing requirement.', 'warning');
    } else {
        requirements_add($conn, $id, $labels);
        if ($context['status'] !== DOC_STATUS_INCOMPLETE) {
            $stmt = safe_query($conn, "UPDATE documents SET status = 'Incomplete' WHERE doc_id = ? AND type = 'Incoming'", 'i', [$id]);
            if ($stmt !== false) {
                mysqli_stmt_close($stmt);
            }
        }
        $pendingLabels = requirements_pending_labels($conn, $id);
        if ($context['status'] !== DOC_STATUS_INCOMPLETE) {
            requirements_note_latest_event($conn, $id, 'Needs: ' . implode(', ', $pendingLabels));
        }
        $smsNote = '';
        if (!empty($_POST['send_sms'])) {
            $context['status'] = DOC_STATUS_INCOMPLETE;
            $context['items'] = $pendingLabels;
            $smsText = $postText('sms_text');
            if ($smsText === '') {
                $smsText = compose_sms_message($context);
            }
            $smsNote = $sendDocumentSms($context['phone'], mb_substr($smsText, 0, 320, 'UTF-8'));
        }
        $message = show_alert('Marked as Incomplete. Missing: ' . implode(', ', $pendingLabels) . '.' . $smsNote);
    }
}

if (isset($_POST['update_requirements'])) {
    $shouldRedirect = true;
    $id = $postId('req_doc_id');
    $context = $id > 0 ? composer_document_context($conn, $id, $documentTrackingSettings) : null;
    $typeCheck = $id > 0 ? safe_scalar($conn, 'SELECT type FROM documents WHERE doc_id = ?', 'i', [$id]) : null;
    if ($context === null || $typeCheck !== 'Incoming') {
        $message = show_alert('The incoming document was not found.', 'danger');
    } else {
        $receivedIds = isset($_POST['req_received']) && is_array($_POST['req_received'])
            ? array_map('intval', array_filter($_POST['req_received'], 'is_string'))
            : [];
        requirements_set_received($conn, $id, $receivedIds);
        requirements_add($conn, $id, requirements_clean_labels([$postText('req_new')]));
        $pendingLabels = requirements_pending_labels($conn, $id);
        if ($pendingLabels || $context['status'] !== DOC_STATUS_INCOMPLETE) {
            $message = show_alert($pendingLabels ? 'Requirements updated. Still needed: ' . implode(', ', $pendingLabels) . '.' : 'Requirements updated.');
        } else {
            $stmt = safe_query($conn, "UPDATE documents SET status = 'Received' WHERE doc_id = ? AND type = 'Incoming' AND status = 'Incomplete'", 'i', [$id]);
            if ($stmt !== false) {
                mysqli_stmt_close($stmt);
            }
            requirements_note_latest_event($conn, $id, 'All requirements received');
            $smsNote = '';
            if (!empty($_POST['send_sms'])) {
                $context['event'] = 'requirements_complete';
                $context['status'] = DOC_STATUS_RECEIVED;
                $smsNote = $sendDocumentSms($context['phone'], compose_sms_message($context));
            }
            $message = show_alert('All requirements received. Status moved back to Received.' . $smsNote);
        }
    }
}

if (isset($_POST['toggle_priority'])) {
    $shouldRedirect = true;
    $id = $postId('priority_doc_id');
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

if ($shouldRedirect) {
    $_SESSION['incoming_flash'] = ['message' => $message, 'releaseOutcome' => $releaseOutcome];
    $redirectTarget = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($redirectTarget === '' || $redirectTarget[0] !== '/' || strpos($redirectTarget, '//') === 0) {
        $redirectTarget = 'incoming';
    }
    header('Location: ' . $redirectTarget);
    exit;
}

// Build the list query from the active filters instead of one hand-written query per combination.
$whereClauses = ["type = 'Incoming'"];
$whereTypes = '';
$whereParams = [];
if ($overdueFilter && $documentIdFilter === 0) {
    // The dashboard's overdue tile must list only documents past their processing time.
    $whereClauses[] = 'status IN (' . doc_status_open_sql() . ')';
    $whereClauses[] = 'date_created < (NOW() - INTERVAL ? DAY)';
    $whereTypes .= 'i';
    $whereParams[] = $overdueDays;
    $orderSql = 'date_created ASC';
} elseif ($documentIdFilter > 0) {
    // Quick Submit's Manage action must show only the selected incoming record.
    $whereClauses[] = 'doc_id = ?';
    $whereTypes .= 'i';
    $whereParams[] = $documentIdFilter;
    $orderSql = 'doc_id ASC';
} else {
    if ($submitterFilter !== 'All') {
        $whereClauses[] = 'submitter_type = ?';
        $whereTypes .= 's';
        $whereParams[] = $submitterFilter;
    }
    if ($statusFilter === 'Open') {
        $whereClauses[] = 'status IN (' . doc_status_open_sql() . ')';
    } elseif ($statusFilter !== '') {
        $whereClauses[] = 'status = ?';
        $whereTypes .= 's';
        $whereParams[] = $statusFilter;
    }
    if ($search !== '') {
        $searchColumns = ['submitter_name', 'sender', 'recipient', 'subject', 'remarks'];
        if ($statusFilter === '') {
            $searchColumns[] = 'status';
        }
        $searchTerm = '%' . addcslashes($search, '%_\\') . '%';
        $whereClauses[] = '(' . implode(' OR ', array_map(static function ($column) {
            return $column . ' LIKE ?';
        }, $searchColumns)) . ')';
        foreach ($searchColumns as $searchColumn) {
            $whereTypes .= 's';
            $whereParams[] = $searchTerm;
        }
    }
    $orderSql = $orderFilter === 'newest'
        ? 'is_priority DESC, date_created DESC, doc_id DESC'
        : 'is_priority DESC, date_created ASC, doc_id ASC';
}
$listSql = "SELECT *, (status IN (" . doc_status_open_sql() . ") AND date_created < (NOW() - INTERVAL ? DAY)) AS is_overdue FROM documents WHERE "
    . implode(' AND ', $whereClauses) . ' ORDER BY ' . $orderSql;
$stmt = safe_query($conn, $listSql, 'i' . $whereTypes, array_merge([$overdueDays], $whereParams));

$documents = [];
if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $documents[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}
$clearFilterQuery = $submitterFilter !== 'All' ? ['submitter' => $submitterFilter] : [];
$requirementPresets = requirements_presets($conn);
$documentRequirements = [];
foreach ($documents as $listedDocument) {
    $documentRequirements[(int)$listedDocument['doc_id']] = requirements_list($conn, (int)$listedDocument['doc_id']);
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
                        <?php $typeQuery = array_filter(['search' => $search, 'status' => $statusFilter, 'order' => $orderFilter === 'newest' ? 'newest' : '', 'submitter' => $typeValue === 'All' ? '' : $typeValue], static function ($value) { return $value !== ''; }); ?>
                        <a class="nav-link<?php echo $submitterFilter === $typeValue ? ' active' : ''; ?>" href="incoming<?php echo $typeQuery ? '?' . http_build_query($typeQuery) : ''; ?>"<?php echo $submitterFilter === $typeValue ? ' aria-current="page"' : ''; ?>>
                            <?php echo sanitize($typeLabel); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <form method="GET" class="row g-2 mb-3">
                    <?php if ($submitterFilter !== 'All'): ?><input type="hidden" name="submitter" value="<?php echo sanitize($submitterFilter); ?>"><?php endif; ?>
                    <div class="col-12 col-md-5">
                        <label class="visually-hidden" for="incomingSearch">Search incoming documents</label>
                        <input type="search" id="incomingSearch" name="search" class="form-control" placeholder="Search by name, subject, office, or remarks" value="<?php echo sanitize($search); ?>">
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
                    <div class="col-12 col-md-2">
                        <label class="visually-hidden" for="incomingOrderFilter">Sort order</label>
                        <select class="form-select" id="incomingOrderFilter" name="order">
                            <option value="oldest" <?php echo $orderFilter === 'oldest' ? 'selected' : ''; ?>>Oldest first</option>
                            <option value="newest" <?php echo $orderFilter === 'newest' ? 'selected' : ''; ?>>Newest first</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2 d-grid">
                        <button class="btn btn-primary">Search</button>
                    </div>
                </form>
                <p class="small text-muted mb-2" role="status" aria-live="polite">
                    Showing <?php echo number_format(count($documents)); ?> document(s)<?php if ($search !== '' || $statusFilter !== ''): ?> · <a href="incoming<?php echo $clearFilterQuery ? '?' . sanitize(http_build_query($clearFilterQuery)) : ''; ?>">Clear search and status</a><?php endif; ?>
                </p>
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
                                    <?php
                                    $documentReference = document_tracking_reference($document['doc_id']);
                                    $documentSubmitterName = trim((string)($document['submitter_name'] ?? ''));
                                    $documentPhone = trim((string)($document['client_phone'] ?? ''));
                                    $editPayload = json_encode([
                                        'id' => (int)$document['doc_id'],
                                        'sender' => (string)$document['sender'],
                                        'submitterName' => (string)($document['submitter_name'] ?? ''),
                                        'clientPhone' => (string)($document['client_phone'] ?? ''),
                                        'subject' => (string)$document['subject'],
                                        'remarks' => (string)($document['remarks'] ?? ''),
                                        'submitterType' => (string)$document['submitter_type'],
                                    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
                                    ?>
                                    <tr>
                                        <td data-label="Date"><?php echo sanitize(date('M j, Y', strtotime($document['date_created']))); ?><div class="small text-muted"><?php echo sanitize(date('g:i A', strtotime($document['date_created']))); ?></div></td>
                                        <td data-label="Submitted by">
                                            <div class="table-cell-badges">
                                                <span class="badge text-bg-light border"><?php echo sanitize($document['submitter_type']); ?></span>
                                            </div>
                                            <div class="mt-1 fw-semibold"><?php echo sanitize($documentSubmitterName !== '' ? $document['submitter_name'] : 'Name not recorded'); ?></div>
                                        </td>
                                        <td data-label="Reference"><code><?php echo sanitize($documentReference); ?></code></td>
                                        <td data-label="Tracking QR">
                                            <?php if (!$documentTrackingSettings['enabled']): ?>
                                                <span class="small text-muted">QR tracking is disabled</span>
                                            <?php elseif (!empty($document['tracking_token'])): ?>
                                                <?php $documentTrackerUrl = $documentTrackingSettings['baseUrl'] . '?token=' . rawurlencode($document['tracking_token']); ?>
                                                <button type="button" class="btn btn-sm btn-outline-success js-show-tracking-qr" aria-label="View tracking QR code for <?php echo sanitize($documentReference); ?>" data-tracker-url="<?php echo sanitize($documentTrackerUrl); ?>" data-reference="<?php echo sanitize($documentReference); ?>">
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
                                        <td data-label="Contact number"><?php echo sanitize($documentPhone !== '' ? $documentPhone : 'Not provided'); ?></td>
                                        <td data-label="Status">
                                            <div class="d-inline-flex flex-column align-items-start gap-1">
                                                <?php render_status_menu($document['status'], doc_status_incoming(), ['status_doc_id' => (int)$document['doc_id']], 'change_status', $documentReference); ?>
                                                <?php
                                                $rowRequirements = $documentRequirements[(int)$document['doc_id']] ?? [];
                                                $rowReceived = count(array_filter($rowRequirements, static function ($requirement) {
                                                    return $requirement['received_at'] !== null;
                                                }));
                                                ?>
                                                <?php if ($document['status'] === DOC_STATUS_INCOMPLETE): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-danger js-requirements"
                                                        data-doc-id="<?php echo (int)$document['doc_id']; ?>"
                                                        data-reference="<?php echo sanitize($documentReference); ?>"
                                                        data-has-phone="<?php echo is_valid_contact_number($documentPhone) ? '1' : '0'; ?>"
                                                        data-requirements="<?php echo htmlspecialchars(json_encode($rowRequirements, JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]', ENT_QUOTES, 'UTF-8'); ?>">
                                                        <i class="bi bi-list-check me-1" aria-hidden="true"></i>Requirements <?php echo $rowRequirements ? '(' . $rowReceived . '/' . count($rowRequirements) . ')' : ''; ?>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (!empty($slaSettings['enabled']) && !empty($document['is_overdue'])): ?>
                                                    <span class="badge text-bg-danger" title="Waiting longer than <?php echo (int)$overdueDays; ?> day(s)">Overdue</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="Priority">
                                            <?php if ($document['submitter_type'] === 'Faculty'): ?>
                                                <form method="POST" class="document-priority-control">
                                                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                    <input type="hidden" name="priority_doc_id" value="<?php echo (int)$document['doc_id']; ?>">
                                                    <button class="btn btn-sm <?php echo (int)$document['is_priority'] === 1 ? 'btn-warning' : 'btn-outline-secondary'; ?>" type="submit" name="toggle_priority" aria-label="<?php echo (int)$document['is_priority'] === 1 ? 'Remove priority from' : 'Mark as priority'; ?> <?php echo sanitize($documentReference); ?>">
                                                        <i class="bi bi-star<?php echo (int)$document['is_priority'] === 1 ? '-fill' : ''; ?> me-1" aria-hidden="true"></i><?php echo (int)$document['is_priority'] === 1 ? 'Priority' : 'Standard'; ?>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="badge rounded-pill text-bg-light border">Standard</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Actions">
                                            <div class="table-actions document-row-actions">
                                            <button type="button" class="btn btn-sm btn-outline-primary document-action-button js-edit-document" title="Edit document" aria-label="Edit <?php echo sanitize($documentReference); ?>" data-document="<?php echo htmlspecialchars($editPayload !== false ? $editPayload : '{}', ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="bi bi-pencil" aria-hidden="true"></i><span class="action-label">Edit</span>
                                            </button>
                                            <?php if (in_array($document['status'], doc_status_can_prepare_release(), true)): ?>
                                                <?php
                                                $releaseSenderLabel = trim((string)$document['sender']) !== '' ? $document['sender'] : ($documentSubmitterName !== '' ? $documentSubmitterName : 'Not provided');
                                                $releaseRecipientLabel = trim((string)$document['recipient']) !== '' ? $document['recipient'] : "Dean's Office";
                                                ?>
                                                <button
                                                    class="btn btn-sm btn-outline-success js-prepare-release document-action-button"
                                                    type="button"
                                                    title="Prepare for release"
                                                    aria-label="Prepare <?php echo sanitize($documentReference); ?> for release"
                                                    data-document-id="<?php echo (int)$document['doc_id']; ?>"
                                                    data-document-subject="<?php echo sanitize($document['subject']); ?>"
                                                    data-document-sender="<?php echo sanitize($releaseSenderLabel); ?>"
                                                    data-document-recipient="<?php echo sanitize($releaseRecipientLabel); ?>"
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
<div class="modal fade" id="incomingForm" tabindex="-1" aria-labelledby="incomingFormTitle" aria-hidden="true"<?php echo $reopenAddModal ? ' data-reopen="1"' : ''; ?>>
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
                        <input type="text" id="subject" name="subject" class="form-control" maxlength="255" value="<?php echo sanitize($subject); ?>" required>
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
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalTitle">Edit Incoming Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
                        <input type="text" id="editSubject" name="subject" class="form-control" maxlength="255" required>
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

<div class="modal fade" id="incompleteModal" tabindex="-1" aria-labelledby="incompleteTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow" id="incompleteForm" data-admin-confirm="false">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
            <input type="hidden" name="incomplete_doc_id" id="incompleteDocId" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="incompleteTitle"><i class="bi bi-exclamation-circle me-2 text-danger" aria-hidden="true"></i>Mark as Incomplete <small class="text-muted" id="incompleteRef"></small></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="fw-semibold mb-2">What is missing?</p>
                <div class="d-flex flex-wrap gap-2 mb-2" id="incompletePresets">
                    <?php foreach ($requirementPresets as $presetIndex => $preset): ?>
                        <input type="checkbox" class="btn-check js-req-item" name="req_items[]" id="reqPreset<?php echo (int)$presetIndex; ?>" value="<?php echo sanitize($preset); ?>" autocomplete="off">
                        <label class="btn btn-sm btn-outline-secondary" for="reqPreset<?php echo (int)$presetIndex; ?>"><?php echo sanitize($preset); ?></label>
                    <?php endforeach; ?>
                </div>
                <label class="form-label small" for="incompleteOther">Other (separate items with commas)</label>
                <input type="text" class="form-control mb-3" id="incompleteOther" name="req_other" maxlength="200" placeholder="e.g. Photocopy of diploma, Barangay certificate">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="send_sms" value="1" id="incompleteSendSms" checked>
                    <label class="form-check-label" for="incompleteSendSms">Notify the submitter by SMS</label>
                </div>
                <div id="incompleteSmsBox">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small mb-0" for="incompleteSmsText">Message (generated from the document; you can edit it)</label>
                        <button type="button" class="btn btn-sm btn-link p-0" id="incompleteRegenerate"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Different wording</button>
                    </div>
                    <textarea class="form-control" id="incompleteSmsText" name="sms_text" rows="4" maxlength="320"></textarea>
                    <div class="small text-muted mt-1" id="incompleteSmsMeta"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="mark_incomplete" value="1" class="btn btn-danger">Mark Incomplete</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="requirementsModal" tabindex="-1" aria-labelledby="requirementsTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow" data-admin-confirm="false">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
            <input type="hidden" name="req_doc_id" id="requirementsDocId" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="requirementsTitle"><i class="bi bi-list-check me-2" aria-hidden="true"></i>Requirements <small class="text-muted" id="requirementsRef"></small></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">Tick each item once the submitter has handed it in. When everything is ticked, the document goes back to Received.</p>
                <div id="requirementsList" class="mb-3"></div>
                <label class="form-label small" for="requirementsNew">Add another missing item</label>
                <input type="text" class="form-control mb-3" id="requirementsNew" name="req_new" maxlength="100">
                <div class="form-check" id="requirementsSmsRow">
                    <input class="form-check-input" type="checkbox" name="send_sms" value="1" id="requirementsSendSms" checked>
                    <label class="form-check-label" for="requirementsSendSms">SMS the submitter when all requirements are complete</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="update_requirements" value="1" class="btn btn-success">Save</button>
            </div>
        </form>
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
    $releaseTone = in_array($releaseOutcome['type'] ?? '', ['success', 'warning', 'danger'], true)
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
                        <?php echo sanitize((string)($releaseOutcome['title'] ?? '')); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" role="alert" aria-live="assertive"><?php echo sanitize((string)($releaseOutcome['detail'] ?? '')); ?></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Got it</button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script src="assets/js/qr-tools.bundle.js"></script>
<script>
function editDocument(data) {
    document.getElementById('editId').value = data.id;
    document.getElementById('editSender').value = data.sender || '';
    document.getElementById('editSubmitterName').value = data.submitterName || '';
    document.getElementById('editClientPhone').value = data.clientPhone || '';
    document.getElementById('editSubject').value = data.subject || '';
    document.getElementById('editRemarks').value = data.remarks || '';
    document.getElementById('editSubmitterType').value = data.submitterType || 'Other';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
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

// Row buttons carry their data in attributes, so one handler covers every row.
document.addEventListener('click', function (event) {
    const editButton = event.target.closest('.js-edit-document');
    if (editButton) {
        let data = null;
        try {
            data = JSON.parse(editButton.dataset.document || '{}');
        } catch (error) {
            return;
        }
        editDocument(data);
        return;
    }
    const qrButton = event.target.closest('.js-show-tracking-qr');
    if (qrButton) {
        showDocumentTrackingQr(qrButton.dataset.trackerUrl, qrButton.dataset.reference);
    }
});

document.addEventListener('DOMContentLoaded', function () {
    const docMeta = <?php
        $docMeta = [];
        foreach ($documents as $metaDocument) {
            $metaId = (int)$metaDocument['doc_id'];
            $docMeta[$metaId] = [
                'ref' => document_tracking_reference($metaId),
                'hasPhone' => is_valid_contact_number(trim((string)($metaDocument['client_phone'] ?? ''))),
                'pending' => array_values(array_map(static function ($requirement) {
                    return $requirement['label'];
                }, array_filter($documentRequirements[$metaId] ?? [], static function ($requirement) {
                    return $requirement['received_at'] === null;
                }))),
            ];
        }
        echo json_encode($docMeta ?: new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    ?>;
    const incompleteModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('incompleteModal'));
    const smsText = document.getElementById('incompleteSmsText');
    const smsMeta = document.getElementById('incompleteSmsMeta');
    const sendSms = document.getElementById('incompleteSendSms');
    const smsBox = document.getElementById('incompleteSmsBox');
    const otherInput = document.getElementById('incompleteOther');
    let incompleteDocId = 0;
    let variant = 0;
    let timer = null;
    let userEdited = false;

    const selectedItems = function () {
        const items = Array.from(document.querySelectorAll('#incompletePresets .js-req-item:checked')).map(function (box) { return box.value; });
        return items.concat(otherInput.value.split(/[\n,;]+/).map(function (part) { return part.trim(); }).filter(Boolean));
    };
    const refreshPreview = function () {
        if (!incompleteDocId || userEdited) return;
        const params = new URLSearchParams({ doc_id: String(incompleteDocId), status: 'Incomplete', variant: String(variant) });
        selectedItems().forEach(function (item) { params.append('items[]', item); });
        fetch('compose_message.php?' + params.toString(), { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (!result.success || userEdited) return;
                smsText.value = result.message;
                smsMeta.textContent = result.message.length + ' characters' + (result.message.length > 160 ? ' (sent as ' + Math.ceil(result.message.length / 153) + ' SMS)' : ' (1 SMS)');
            })
            .catch(function () { smsMeta.textContent = 'Could not generate the message. You can type one.'; });
    };
    const schedulePreview = function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(refreshPreview, 250);
    };

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.classList.contains('status-menu')) return;
        if (!event.submitter || event.submitter.name !== 'new_status' || event.submitter.value !== 'Incomplete') return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const id = form.querySelector('[name="status_doc_id"]').value;
        const meta = docMeta[id] || { ref: '', hasPhone: false, pending: [] };
        incompleteDocId = parseInt(id, 10);
        variant = 0;
        userEdited = false;
        document.getElementById('incompleteDocId').value = id;
        document.getElementById('incompleteRef').textContent = meta.ref;
        otherInput.value = '';
        document.querySelectorAll('#incompletePresets .js-req-item').forEach(function (box) {
            box.checked = meta.pending.indexOf(box.value) !== -1;
        });
        const custom = meta.pending.filter(function (label) {
            return !Array.from(document.querySelectorAll('#incompletePresets .js-req-item')).some(function (box) { return box.value === label; });
        });
        otherInput.value = custom.join(', ');
        sendSms.disabled = !meta.hasPhone;
        sendSms.checked = meta.hasPhone;
        smsBox.hidden = !meta.hasPhone;
        if (!meta.hasPhone) {
            smsMeta.textContent = '';
        }
        smsText.value = '';
        incompleteModal.show();
        refreshPreview();
    }, true);

    document.getElementById('incompletePresets').addEventListener('change', schedulePreview);
    otherInput.addEventListener('input', schedulePreview);
    smsText.addEventListener('input', function () {
        userEdited = smsText.value.trim() !== '';
        smsMeta.textContent = smsText.value.length + ' characters (edited)';
    });
    document.getElementById('incompleteRegenerate').addEventListener('click', function () {
        variant += 1;
        userEdited = false;
        refreshPreview();
    });
    sendSms.addEventListener('change', function () { smsBox.hidden = !sendSms.checked; });

    const requirementsModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('requirementsModal'));
    document.querySelectorAll('.js-requirements').forEach(function (button) {
        button.addEventListener('click', function () {
            let items = [];
            try { items = JSON.parse(button.dataset.requirements || '[]'); } catch (error) { items = []; }
            document.getElementById('requirementsDocId').value = button.dataset.docId;
            document.getElementById('requirementsRef').textContent = button.dataset.reference;
            document.getElementById('requirementsNew').value = '';
            const sms = document.getElementById('requirementsSendSms');
            sms.disabled = button.dataset.hasPhone !== '1';
            sms.checked = button.dataset.hasPhone === '1';
            document.getElementById('requirementsSmsRow').hidden = button.dataset.hasPhone !== '1';
            const list = document.getElementById('requirementsList');
            list.replaceChildren();
            if (items.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'small text-muted mb-0';
                empty.textContent = 'No requirements were listed for this document.';
                list.append(empty);
            }
            items.forEach(function (item) {
                const row = document.createElement('div');
                row.className = 'form-check';
                const box = document.createElement('input');
                box.type = 'checkbox';
                box.className = 'form-check-input';
                box.name = 'req_received[]';
                box.value = item.req_id;
                box.id = 'req' + item.req_id;
                box.checked = item.received_at !== null;
                const label = document.createElement('label');
                label.className = 'form-check-label';
                label.htmlFor = box.id;
                label.textContent = item.label + (item.received_at ? ' (received ' + item.received_at.slice(0, 10) + ')' : '');
                row.append(box, label);
                list.append(row);
            });
            requirementsModal.show();
        });
    });

    const prepareModalElement = document.getElementById('prepareReleaseModal');
    const prepareModal = bootstrap.Modal.getOrCreateInstance(prepareModalElement);
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
        bootstrap.Modal.getOrCreateInstance(outcomeModal).show();
    }

    // Reopen the Add Document form when saving failed, so the typed values and the error are both visible.
    const incomingForm = document.getElementById('incomingForm');
    if (incomingForm && (incomingForm.dataset.reopen === '1' || new URLSearchParams(window.location.search).get('open') === 'add')) {
        bootstrap.Modal.getOrCreateInstance(incomingForm).show();
    }
});

</script>

<?php include 'footer.php'; ?>