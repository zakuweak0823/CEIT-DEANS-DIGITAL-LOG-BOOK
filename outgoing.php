<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'sms_service.php';
require_once 'mobile_form_settings.php';
require_once 'document_tracking.php';
require_once 'sla_support.php';
ensure_admin();

$mobileFormLabels = get_mobile_form_labels($conn);
try {
    ensure_document_tracking_schema($conn);
    $documentTrackingSettings = get_document_tracking_settings($conn);
    // These schema fixes only need to run once per login session, not on every page view.
    if (empty($_SESSION['outgoing_schema_ready'])) {
        foreach ([
            'ALTER TABLE documents ADD COLUMN IF NOT EXISTS client_phone VARCHAR(20) DEFAULT NULL',
            "ALTER TABLE documents ADD COLUMN IF NOT EXISTS submitter_type VARCHAR(20) NOT NULL DEFAULT 'Other'",
            'ALTER TABLE documents ADD COLUMN IF NOT EXISTS is_priority TINYINT(1) NOT NULL DEFAULT 0',
        ] as $schemaSql) {
            if ($conn->query($schemaSql) === false) {
                throw new RuntimeException($conn->error);
            }
        }
        $_SESSION['outgoing_schema_ready'] = true;
    }
} catch (Throwable $error) {
    error_log('Outgoing document tracking setup failed: ' . $error->getMessage());
    http_response_code(500);
    exit('Outgoing document storage is unavailable. Check the server log and try again.');
}

$message = '';
$smsOutcome = null;
$setSmsOutcome = static function ($title, $detail, $type = 'info') use (&$smsOutcome) {
    $smsOutcome = ['title' => $title, 'detail' => $detail, 'type' => $type];
};

// After an action, the page redirects so refreshing the browser cannot submit the same form twice
// (which would resend SMS messages or repeat status changes).
// The result message travels in the session for exactly one page view.
if (isset($_SESSION['outgoing_flash']) && is_array($_SESSION['outgoing_flash'])) {
    $message = (string)($_SESSION['outgoing_flash']['message'] ?? '');
    $flashOutcome = $_SESSION['outgoing_flash']['smsOutcome'] ?? null;
    $smsOutcome = is_array($flashOutcome) ? $flashOutcome : null;
}
unset($_SESSION['outgoing_flash']);
$shouldRedirect = false;

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
// Returns an error message, or null when the edited values are valid.
$validateOutgoing = static function (string $subject, ?string $clientPhone) use ($textLength): ?string {
    if ($subject === '') {
        return 'Document type is required.';
    }
    if ($textLength($subject) > 255) {
        return 'The document type must be 255 characters or fewer.';
    }
    if ($clientPhone === null || ($clientPhone !== '' && !is_valid_contact_number($clientPhone))) {
        return 'Enter an 11-digit contact number using digits only, or leave it blank.';
    }
    return null;
};

$search = isset($_GET['search']) && is_string($_GET['search']) ? trim($_GET['search']) : '';
$documentIdParam = $_GET['document_id'] ?? '';
$documentIdFilter = is_string($documentIdParam) && ctype_digit($documentIdParam) ? (int)$documentIdParam : 0;
$statusFilter = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';
$overdueFilter = ($_GET['overdue'] ?? '') === '1';
$orderFilter = ($_GET['order'] ?? '') === 'newest' ? 'newest' : 'oldest';
$slaSettings = get_sla_settings($conn);
$overdueDays = (int)$slaSettings['outgoingDays'];
$validStatusFilters = ['For Release', 'Released'];
if (!in_array($statusFilter, $validStatusFilters, true)) {
    $statusFilter = '';
}

if (isset($_POST['issue_tracking_qr'])) {
    $shouldRedirect = true;
    $documentId = $postId('tracking_doc_id');
    if (!$documentTrackingSettings['enabled']) {
        $message = show_alert('Document tracking is turned off in Admin Settings.', 'warning');
    } elseif ($documentId <= 0) {
        $message = show_alert('Select a valid outgoing document.', 'danger');
    } else {
        $conn->begin_transaction();
        try {
            $stmt = safe_query($conn, "SELECT tracking_token FROM documents WHERE doc_id = ? AND type = 'Outgoing' FOR UPDATE", 'i', [$documentId]);
            if ($stmt === false) {
                throw new RuntimeException('Could not load the selected outgoing document.');
            }
            $result = mysqli_stmt_get_result($stmt);
            $document = $result === false ? false : mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
            if ($document === false) {
                throw new RuntimeException('Could not read the selected outgoing document.');
            }
            if ($document === null) {
                throw new DomainException('Outgoing document was not found.');
            }
            if ($document['tracking_token'] === null || $document['tracking_token'] === '') {
                $trackingToken = create_document_tracking_token();
                $stmt = safe_query($conn, "UPDATE documents SET tracking_token = ? WHERE doc_id = ? AND (tracking_token IS NULL OR tracking_token = '')", 'si', [$trackingToken, $documentId]);
                if ($stmt === false || mysqli_stmt_affected_rows($stmt) !== 1) {
                    if ($stmt !== false) mysqli_stmt_close($stmt);
                    throw new RuntimeException('Could not issue a tracking QR code.');
                }
                mysqli_stmt_close($stmt);
            }
            $conn->commit();
            $message = show_alert('The office-issued outgoing document QR is ready in the table.', 'success');
        } catch (DomainException $error) {
            $conn->rollback();
            $message = show_alert($error->getMessage(), 'danger');
        } catch (Throwable $error) {
            $conn->rollback();
            error_log('Outgoing document tracking QR issuance failed: ' . $error->getMessage());
            $message = show_alert('The tracking QR could not be issued. Check the server log and try again.', 'danger');
        }
    }
} elseif (isset($_POST['update'])) {
    $id = $postId('id');
    $editSubject = $postText('subject');
    $editRemarks = $postText('remarks');
    $editClientPhone = contact_number_input($postText('client_phone'));

    $validationError = $id <= 0
        ? 'The selected document is invalid. Refresh the page and try again.'
        : $validateOutgoing($editSubject, $editClientPhone);
    if ($validationError !== null) {
        $message = show_alert($validationError, 'danger');
    } else {
        $phoneValue = $editClientPhone === '' ? null : $editClientPhone;
        $stmt = safe_query($conn, "UPDATE documents SET client_phone = ?, subject = ?, remarks = ? WHERE doc_id = ? AND type = 'Outgoing'", 'sssi', [$phoneValue, $editSubject, $editRemarks, $id]);
        if ($stmt !== false) {
            mysqli_stmt_close($stmt);
            $message = show_alert('Outgoing document updated successfully.');
            $shouldRedirect = true;
        } else {
            $message = show_alert('Failed to update outgoing document.', 'danger');
        }
    }
} elseif (isset($_POST['set_outgoing_status'])) {
    $shouldRedirect = true;
    $id = $postId('status_doc_id');
    $status = $postText('new_status');
    if ($id <= 0 || !in_array($status, $validStatusFilters, true)) {
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
} elseif (isset($_POST['send_client_sms'])) {
    $shouldRedirect = true;
    // Recheck status and contact at send time; the page form may have gone stale.
    $id = $postId('sms_doc_id');
    $smsDocument = null;
    $stmt = safe_query($conn, "SELECT subject, client_phone, status FROM documents WHERE doc_id = ? AND type = 'Outgoing' AND status = 'For Release'", 'i', [$id]);
    if ($stmt !== false) {
        $result = mysqli_stmt_get_result($stmt);
        $smsDocument = $result ? (mysqli_fetch_assoc($result) ?: null) : null;
        mysqli_stmt_close($stmt);
    }

    // Server-side guard against double sends (double click, second tab, replayed request).
    $lastSentAt = (int)($_SESSION['outgoing_sms_sent_at'][$id] ?? 0);

    if (!$smsDocument) {
        $message = show_alert('The outgoing document was not found or is no longer For Release.', 'danger');
        $setSmsOutcome('Document unavailable', 'Only documents with status “For Release” can receive this notification. Refresh the list and try again.', 'danger');
    } elseif (!is_valid_contact_number(trim((string)($smsDocument['client_phone'] ?? '')))) {
        $message = show_alert('No valid client contact is saved for this document. Edit it to add the number.', 'danger');
        $setSmsOutcome('SMS was not sent', 'No valid client contact is saved for this document. Close this message, edit the document to add a phone number, then try again.', 'danger');
    } elseif ($lastSentAt > 0 && (time() - $lastSentAt) < 120) {
        $message = show_alert('A notification for this document was just sent. Wait a couple of minutes before sending another.', 'warning');
        $setSmsOutcome('Notification already sent', 'A notification for this document was sent less than two minutes ago. Check the SMS Logs page before sending it again.', 'warning');
    } else {
        $smsText = "CvSU Dean's Office: Your document '{$smsDocument['subject']}' is ready for release. Please visit the office to claim it.";
        $smsResult = send_logged_sms($conn, $smsDocument['client_phone'], $smsText);
        if ($smsResult['success']) {
            $_SESSION['outgoing_sms_sent_at'][$id] = time();
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

if ($shouldRedirect) {
    $_SESSION['outgoing_flash'] = ['message' => $message, 'smsOutcome' => $smsOutcome];
    $redirectTarget = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($redirectTarget === '' || $redirectTarget[0] !== '/' || strpos($redirectTarget, '//') === 0) {
        $redirectTarget = 'outgoing';
    }
    header('Location: ' . $redirectTarget);
    exit;
}

// Build the list query from the active filters instead of one hand-written query per combination.
$whereClauses = ["type = 'Outgoing'"];
$whereTypes = '';
$whereParams = [];
if ($overdueFilter && $documentIdFilter === 0) {
    // The dashboard's overdue tile must list only documents past their processing time.
    $whereClauses[] = "status = 'For Release'";
    $whereClauses[] = 'date_created < (NOW() - INTERVAL ? DAY)';
    $whereTypes .= 'i';
    $whereParams[] = $overdueDays;
    $orderSql = 'date_created ASC';
} elseif ($documentIdFilter > 0) {
    // Quick Submit's Manage action must resolve to one exact outgoing document.
    $whereClauses[] = 'doc_id = ?';
    $whereTypes .= 'i';
    $whereParams[] = $documentIdFilter;
    $orderSql = 'doc_id ASC';
} else {
    if ($statusFilter !== '') {
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
    // Documents waiting for release always come first; the order filter applies within each group.
    $orderSql = $orderFilter === 'newest'
        ? "(status = 'For Release') DESC, is_priority DESC, date_created DESC, doc_id DESC"
        : "(status = 'For Release') DESC, is_priority DESC, CASE WHEN status = 'For Release' THEN date_created END ASC, date_created DESC, doc_id DESC";
}
$listSql = "SELECT *, (status = 'For Release' AND date_created < (NOW() - INTERVAL ? DAY)) AS is_overdue FROM documents WHERE "
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
    <?php if ($overdueFilter && $documentIdFilter === 0): ?>
        <div class="col-12">
            <div class="selected-record-banner" role="status">
                <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                <div>
                    <strong>Overdue outgoing documents</strong>
                    <span>Showing <?php echo count($documents); ?> open document(s) waiting longer than <?php echo (int)$overdueDays; ?> day(s).</span>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="outgoing">Show all outgoing documents</a>
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
                <a class="btn btn-sm btn-outline-primary" href="outgoing">Show all outgoing documents</a>
            </div>
        </div>
    <?php endif; ?>
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="GET" class="row g-2 mb-3">
                    <div class="col-12 col-md-5">
                        <label class="visually-hidden" for="outgoingSearch">Search outgoing documents</label>
                        <input type="search" id="outgoingSearch" name="search" class="form-control" placeholder="Search by subject, name, or recipient" value="<?php echo sanitize($search); ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="visually-hidden" for="outgoingStatusFilter">Filter by status</label>
                        <select class="form-select" id="outgoingStatusFilter" name="status">
                            <option value="">All statuses</option>
                            <?php foreach ($validStatusFilters as $filterOption): ?>
                                <option value="<?php echo sanitize($filterOption); ?>" <?php echo $statusFilter === $filterOption ? 'selected' : ''; ?>><?php echo sanitize($filterOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="visually-hidden" for="outgoingOrderFilter">Sort order</label>
                        <select class="form-select" id="outgoingOrderFilter" name="order">
                            <option value="oldest" <?php echo $orderFilter === 'oldest' ? 'selected' : ''; ?>>Oldest first</option>
                            <option value="newest" <?php echo $orderFilter === 'newest' ? 'selected' : ''; ?>>Newest first</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2 d-grid">
                        <button class="btn btn-primary" type="submit">Search</button>
                    </div>
                </form>
                <p class="small text-muted mb-2" role="status" aria-live="polite">
                    Showing <?php echo number_format(count($documents)); ?> document(s)<?php if ($search !== '' || $statusFilter !== ''): ?> · <a href="outgoing">Clear search and status</a><?php endif; ?>
                </p>
                <!-- Use the shared row-separated table skin consistently across admin lists. -->
                <div class="table-responsive outgoing-documents-table-responsive">
                    <table class="table align-middle mb-0 outgoing-documents-table">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Reference</th>
                                <th scope="col">Tracking QR</th>
                                <th scope="col">Submitted by</th>
                                <th scope="col">Recipient</th>
                                <th scope="col">Subject</th>
                                <th scope="col">Contact number</th>
                                <th scope="col">Status</th>
                                <th scope="col">Priority</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($documents) === 0): ?>
                                <tr><td colspan="10" class="text-center text-muted">No outgoing documents found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($documents as $document): ?>
                                    <?php
                                    $documentReference = document_tracking_reference($document['doc_id']);
                                    $documentSubmitterName = trim((string)($document['submitter_name'] ?? ''));
                                    $documentRecipient = trim((string)($document['recipient'] ?? ''));
                                    $documentPhone = trim((string)($document['client_phone'] ?? ''));
                                    $isReleased = $document['status'] === 'Released';
                                    $editPayload = json_encode([
                                        'id' => (int)$document['doc_id'],
                                        'clientPhone' => (string)($document['client_phone'] ?? ''),
                                        'subject' => (string)$document['subject'],
                                        'remarks' => (string)($document['remarks'] ?? ''),
                                    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
                                    ?>
                                    <tr>
                                        <td data-label="Date"><?php echo sanitize(date('M j, Y', strtotime($document['date_created']))); ?><div class="small text-muted"><?php echo sanitize(date('g:i A', strtotime($document['date_created']))); ?></div></td>
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
                                        <td data-label="Submitted by"><?php echo sanitize($documentSubmitterName !== '' ? $document['submitter_name'] : 'Name not recorded'); ?></td>
                                        <td data-label="Recipient"><?php echo sanitize($documentRecipient !== '' ? $document['recipient'] : 'Not provided'); ?></td>
                                        <td data-label="Subject"><?php echo sanitize($document['subject']); ?></td>
                                        <td data-label="Contact number"><?php echo sanitize($documentPhone !== '' ? $documentPhone : 'Not provided'); ?></td>
                                        <td data-label="Status">
                                            <div class="d-inline-flex flex-column align-items-stretch gap-1 text-center">
                                                <span class="badge <?php echo admin_status_badge_class($document['status']); ?>"><?php echo sanitize($document['status']); ?></span>
                                                <?php if (!empty($slaSettings['enabled']) && !empty($document['is_overdue'])): ?>
                                                    <span class="badge text-bg-danger" title="Waiting longer than <?php echo (int)$overdueDays; ?> day(s)">Overdue</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="Priority">
                                            <?php if ((int)($document['is_priority'] ?? 0) === 1): ?>
                                                <span class="badge rounded-pill text-bg-warning"><i class="bi bi-star-fill me-1" aria-hidden="true"></i>Priority</span>
                                            <?php else: ?>
                                                <span class="badge rounded-pill text-bg-light border">Standard</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Actions">
                                            <div class="table-actions document-row-actions">
                                            <button type="button" class="btn btn-sm btn-outline-primary document-action-button js-edit-document" title="Edit document" aria-label="Edit <?php echo sanitize($documentReference); ?>" data-document="<?php echo htmlspecialchars($editPayload !== false ? $editPayload : '{}', ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="bi bi-pencil" aria-hidden="true"></i><span class="action-label">Edit</span>
                                            </button>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                <input type="hidden" name="status_doc_id" value="<?php echo (int)$document['doc_id']; ?>">
                                                <input type="hidden" name="new_status" value="<?php echo $isReleased ? 'For Release' : 'Released'; ?>">
                                                <button type="submit" name="set_outgoing_status" class="btn btn-sm document-action-button <?php echo $isReleased ? 'btn-outline-secondary' : 'btn-success'; ?>" title="<?php echo $isReleased ? 'Set for release' : 'Mark released'; ?>" aria-label="<?php echo ($isReleased ? 'Set for release: ' : 'Mark released: ') . sanitize($documentReference); ?>">
                                                    <i class="bi <?php echo $isReleased ? 'bi-arrow-counterclockwise' : 'bi-check2-circle'; ?>" aria-hidden="true"></i>
                                                    <span class="action-label"><?php echo $isReleased ? 'Set For Release' : 'Mark Released'; ?></span>
                                                </button>
                                            </form>
                                            <?php if ($document['status'] === 'For Release' && is_valid_contact_number($documentPhone)): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-success js-send-release-sms document-action-button"
                                                    title="Send release notification"
                                                    aria-label="Send release notification for <?php echo sanitize($documentReference); ?>"
                                                    data-document-id="<?php echo (int)$document['doc_id']; ?>"
                                                    data-document-subject="<?php echo sanitize($document['subject']); ?>"
                                                    data-document-recipient="<?php echo sanitize($documentRecipient !== '' ? $document['recipient'] : 'Not provided'); ?>"
                                                    data-document-phone="<?php echo sanitize($documentPhone); ?>"
                                                    data-document-status="<?php echo sanitize($document['status']); ?>"
                                                >
                                                    <i class="bi bi-chat-dots" aria-hidden="true"></i><span class="action-label">Send SMS</span>
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
    $smsTone = in_array($smsOutcome['type'] ?? '', ['success', 'warning', 'danger'], true)
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
                        <?php echo sanitize((string)($smsOutcome['title'] ?? '')); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" role="alert" aria-live="assertive"><?php echo sanitize((string)($smsOutcome['detail'] ?? '')); ?></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Got it</button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalTitle">Edit Outgoing Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <input type="hidden" name="id" id="editId">
                    <div class="mb-3">
                        <label class="form-label" for="editClientPhone"><?php echo sanitize($mobileFormLabels['contactNumber']); ?> <span class="text-muted">(optional)</span></label>
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

<script src="assets/js/qr-tools.bundle.js"></script>
<script>
function editDocument(data) {
    document.getElementById('editId').value = data.id;
    document.getElementById('editClientPhone').value = data.clientPhone || '';
    document.getElementById('editSubject').value = data.subject || '';
    document.getElementById('editRemarks').value = data.remarks || '';
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

    // Clear any QR left over from a previously opened document.
    const context = canvas.getContext('2d');
    if (context) {
        context.clearRect(0, 0, canvas.width, canvas.height);
    }

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
                if (context) {
                    context.clearRect(0, 0, canvas.width, canvas.height);
                }
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
    const confirmModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('releaseSmsConfirmModal'));
    const smsForm = document.getElementById('releaseSmsForm');
    const smsSubmit = document.getElementById('releaseSmsSubmit');
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
            smsSubmit.disabled = !hasValidPhone;
            confirmModal.show();
        });
    });

    // Prevent a double click from sending the same SMS twice. The disabled button
    // is not included in the POST, so submit via the form's own name/value pair.
    smsForm.addEventListener('submit', function () {
        window.setTimeout(function () {
            smsSubmit.disabled = true;
            smsSubmit.textContent = 'Sending…';
        }, 0);
    });

    const outcomeModal = document.getElementById('releaseSmsOutcomeModal');
    if (outcomeModal) {
        bootstrap.Modal.getOrCreateInstance(outcomeModal).show();
    }
});
</script>

<?php include 'footer.php'; ?>