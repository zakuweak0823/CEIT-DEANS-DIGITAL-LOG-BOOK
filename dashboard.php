<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'mobile_submission_console_control.php';
ensure_admin();

if (($_GET['quick_action'] ?? '') === 'add_incoming') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $sender = trim($_POST['sender'] ?? '');
    $submitterType = trim($_POST['submitter_type'] ?? 'Other');
    $subject = trim($_POST['subject'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $clientPhone = contact_number_input($_POST['client_phone'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Use the form to add an incoming document.']);
    } elseif ($sender === '' || $subject === '' || !in_array($submitterType, ['Student', 'Faculty', 'Other'], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Sender and document type are required.']);
    } elseif ($clientPhone === null || ($clientPhone !== '' && !is_valid_contact_number($clientPhone))) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Enter an 11-digit contact number using digits only, or leave it blank.']);
    } else {
        $phoneValue = $clientPhone === '' ? null : $clientPhone;
        $stmt = safe_query($conn, "INSERT INTO documents(type, sender, recipient, client_phone, subject, remarks, status, submitter_type) VALUES('Incoming', ?, '', ?, ?, ?, 'Pending', ?)", 'sssss', [$sender, $phoneValue, $subject, $remarks, $submitterType]);
        if ($stmt === false) {
            error_log('Dashboard quick action failed to save incoming document.');
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not save the incoming document. Please try again.']);
        } else {
            mysqli_stmt_close($stmt);
            echo json_encode(['success' => true, 'message' => 'Incoming document saved successfully.']);
        }
    }
    exit;
}

if (($_GET['mobile_console'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $enabled = $_POST['enabled'] ?? '';
            if (!in_array($enabled, ['0', '1'], true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid CMD log state.']);
                exit;
            }
            if ($enabled === '1') {
                set_mobile_submission_console_enabled(true);
                try {
                    start_mobile_submission_console();
                } catch (Throwable $error) {
                    set_mobile_submission_console_enabled(false);
                    throw $error;
                }
            } else {
                set_mobile_submission_console_enabled(false);
            }
        }

        echo json_encode(['success' => true, 'enabled' => mobile_submission_console_is_enabled()]);
    } catch (Throwable $error) {
        error_log('Dashboard CMD submission log control failed: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not update the CMD submission log.']);
    }
    exit;
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01');

$incomingToday = 0;
$incomingMonth = 0;
$outgoingToday = 0;
$outgoingMonth = 0;
$borrowedCurrent = 0;
$pendingIncoming = 0;
$forReleaseCount = 0;
$smsToday = 0;
$smsMonth = 0;
$smsDelivered = 0;
$smsPending = 0;
$smsFailed = 0;
$documentCount = 0;
$borrowedCount = 0;
$categories = 0;

$incomingToday = safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Incoming' AND DATE(date_created) = ?", 's', [$today]);
$incomingMonth = safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Incoming' AND DATE(date_created) >= ?", 's', [$monthStart]);
$outgoingToday = safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Outgoing' AND DATE(date_created) = ?", 's', [$today]);
$outgoingMonth = safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Outgoing' AND DATE(date_created) >= ?", 's', [$monthStart]);
$borrowedCurrent = safe_scalar($conn, "SELECT COUNT(*) FROM borrowed_items WHERE status != 'Returned'");
$pendingIncoming = safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Incoming' AND status = 'Pending'");
$forReleaseCount = safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Outgoing' AND status = 'For Release'");
$smsToday = safe_scalar($conn, "SELECT COUNT(*) FROM sms_logs WHERE DATE(date_sent) = ?", 's', [$today]);
$smsMonth = safe_scalar($conn, "SELECT COUNT(*) FROM sms_logs WHERE DATE(date_sent) >= ?", 's', [$monthStart]);

// Provider status labels vary; group their common variants for comparable dashboard totals.
$status = '';
$statusCount = 0;
$stmt = mysqli_prepare($conn, "SELECT status, COUNT(*) FROM sms_logs GROUP BY status");
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $status, $statusCount);
while (mysqli_stmt_fetch($stmt)) {
    $normalizedSmsStatus = strtolower($status);
    if (in_array($normalizedSmsStatus, ['delivered', 'success'], true)) {
        $smsDelivered += $statusCount;
    } elseif (in_array($normalizedSmsStatus, ['pending', 'queued', 'sent'], true)) {
        $smsPending += $statusCount;
    } elseif ($normalizedSmsStatus === 'failed') {
        $smsFailed += $statusCount;
    }
}
mysqli_stmt_close($stmt);

$documentCount = safe_scalar($conn, "SELECT COUNT(*) FROM documents");
$borrowedCount = safe_scalar($conn, "SELECT COUNT(*) FROM borrowed_items");
$categories = safe_scalar($conn, "SELECT COUNT(DISTINCT type) FROM documents");

$totalItems = $documentCount + $borrowedCount;

$recentDocuments = [];
$stmt = safe_query($conn, "SELECT date_created, type, sender, recipient, subject, status FROM documents ORDER BY date_created DESC LIMIT 5");
if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $recentDocuments[] = $row;
    }
    mysqli_stmt_close($stmt);
}

$borrowedList = [];
$stmt = mysqli_prepare($conn, "SELECT item_name, borrower, date_borrowed, CASE WHEN status = 'Returned' THEN 'Returned' ELSE 'Borrowed' END AS display_status FROM borrowed_items ORDER BY date_borrowed DESC LIMIT 5");
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $borrowedList[] = $row;
}
mysqli_stmt_close($stmt);

$recentSms = [];
$stmt = safe_query($conn, "SELECT recipient, message, status, date_sent FROM sms_logs ORDER BY date_sent DESC LIMIT 5");
if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $recentSms[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// Data for charts
$statusData = [];
$stmt = safe_query($conn, "SELECT status, COUNT(*) as count FROM documents GROUP BY status");
if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $statusData[$row['status']] = $row['count'];
    }
    mysqli_stmt_close($stmt);
}

// Keep chart series in chronological order, including months with zero submissions.
$monthlyIncoming = [];
$monthlyOutgoing = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $count = safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Incoming' AND DATE_FORMAT(date_created, '%Y-%m') = ?", 's', [$month]);
    $monthlyIncoming[] = $count;

    $count = safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Outgoing' AND DATE_FORMAT(date_created, '%Y-%m') = ?", 's', [$month]);
    $monthlyOutgoing[] = $count;
}
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $months[] = date('M Y', strtotime("-$i months"));
}
?>

<?php include 'header.php'; ?>
<div class="row g-3 dashboard-page">
    <div class="col-12">
        <section class="civic-dashboard-hero" aria-labelledby="civicDashboardTitle">
            <div class="civic-dashboard-hero-copy">
                <span class="civic-dashboard-kicker"><i class="bi bi-grid-1x2" aria-hidden="true"></i> OFFICE OVERVIEW <span aria-hidden="true"></span> <?php echo sanitize(date('l, F j')); ?></span>
                <h1 id="civicDashboardTitle">Office activity, in focus.</h1>
                <p>Track document flow, follow-ups, and today’s work from one place.</p>
                <div class="civic-dashboard-actions">
                    <a href="incoming?status=Pending"><i class="bi bi-inbox" aria-hidden="true"></i> Review incoming <span><?php echo number_format((int)$pendingIncoming); ?></span></a>
                    <a href="records"><i class="bi bi-arrow-up-right" aria-hidden="true"></i> Browse records</a>
                </div>
            </div>
            <div class="civic-dashboard-hero-aside" aria-label="Today's activity">
                <span class="civic-dashboard-aside-label">TODAY'S ACTIVITY</span>
                <div class="civic-dashboard-total">
                    <strong><?php echo number_format((int)$incomingToday + (int)$outgoingToday); ?></strong>
                    <span>documents recorded</span>
                </div>
                <div class="civic-dashboard-split">
                    <span><i class="bi bi-arrow-down-left" aria-hidden="true"></i><strong><?php echo number_format((int)$incomingToday); ?></strong><small>Incoming</small></span>
                    <span><i class="bi bi-arrow-up-right" aria-hidden="true"></i><strong><?php echo number_format((int)$outgoingToday); ?></strong><small>Outgoing</small></span>
                </div>
                <span class="civic-dashboard-updated"><i class="bi bi-clock-history" aria-hidden="true"></i> Snapshot at <?php echo sanitize(date('g:i A')); ?></span>
            </div>
            <span class="civic-dashboard-orbit civic-dashboard-orbit-one" aria-hidden="true"></span>
            <span class="civic-dashboard-orbit civic-dashboard-orbit-two" aria-hidden="true"></span>
        </section>
    </div>
    <div class="col-12">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-1 dashboard-welcome">
            <div>
                <h2 class="mb-1 text-primary">Office activity</h2>
                <p class="text-muted mb-0">Review priority follow-ups and the latest office activity below.</p>
            </div>
        </div>
    </div>

    <div class="col-12">
        <section class="dashboard-quick-actions" aria-labelledby="dashboard-quick-actions-title">
            <div class="dashboard-quick-actions-heading">
                <h3 id="dashboard-quick-actions-title" class="h6 mb-0">Quick actions</h3>
                <span>Common tasks, one click away</span>
            </div>
            <div class="dashboard-quick-actions-grid">
                <button class="dashboard-quick-action-link" type="button" data-bs-toggle="modal" data-bs-target="#dashboardIncomingForm">
                    <span class="dashboard-quick-action-icon"><i class="bi bi-file-earmark-plus" aria-hidden="true"></i></span>
                    <span class="dashboard-quick-action-copy"><strong>Add incoming</strong><small>Register a document</small></span>
                    <i class="bi bi-arrow-up-right dashboard-quick-action-arrow" aria-hidden="true"></i>
                </button>
                <button class="dashboard-quick-action-link" type="button" data-quick-action-url="batch_papers?open=scanner&amp;embed=1" data-quick-action-title="Scan CEIT QR" data-quick-action-layout="scanner" data-bs-toggle="modal" data-bs-target="#quickActionModal">
                    <span class="dashboard-quick-action-icon"><i class="bi bi-qr-code-scan" aria-hidden="true"></i></span>
                    <span class="dashboard-quick-action-copy"><strong>Scan CEIT QR</strong><small>Open the scanner</small></span>
                    <i class="bi bi-arrow-up-right dashboard-quick-action-arrow" aria-hidden="true"></i>
                </button>
                <button class="dashboard-quick-action-link" type="button" data-quick-action-url="batch_papers?open=batch&amp;embed=1" data-quick-action-title="Batch Papers" data-quick-action-layout="batch" data-bs-toggle="modal" data-bs-target="#quickActionModal">
                    <span class="dashboard-quick-action-icon"><i class="bi bi-files" aria-hidden="true"></i></span>
                    <span class="dashboard-quick-action-copy"><strong>Batch papers</strong><small>Manage paper batches</small></span>
                    <i class="bi bi-arrow-up-right dashboard-quick-action-arrow" aria-hidden="true"></i>
                </button>
                <button class="dashboard-quick-action-link" type="button" data-quick-action-url="reports?embed=1" data-quick-action-title="Reports" data-quick-action-layout="reports" data-bs-toggle="modal" data-bs-target="#quickActionModal">
                    <span class="dashboard-quick-action-icon"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></span>
                    <span class="dashboard-quick-action-copy"><strong>View reports</strong><small>Review office activity</small></span>
                    <i class="bi bi-arrow-up-right dashboard-quick-action-arrow" aria-hidden="true"></i>
                </button>
            </div>
        </section>
    </div>

    <div class="col-12">
        <section class="card dashboard-section-card dashboard-attention-card shadow-sm rounded-4 border-0" aria-labelledby="dashboard-attention-title">
            <div class="card-body">
                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                    <div>
                        <h3 id="dashboard-attention-title" class="h5 text-primary mb-1">Needs attention</h3>
                        <p class="text-muted mb-0">Open work and delivery issues that may need follow-up.</p>
                    </div>
                </div>
                <div class="dashboard-attention-grid">
                    <a class="dashboard-attention-item" href="incoming?status=Pending">
                        <span class="dashboard-attention-icon dashboard-attention-icon-amber"><i class="bi bi-inbox" aria-hidden="true"></i></span>
                        <span class="dashboard-attention-count"><?php echo number_format((int)$pendingIncoming); ?></span>
                        <span class="dashboard-attention-label">Incoming to review</span>
                    </a>
                    <a class="dashboard-attention-item" href="outgoing?status=For%20Release">
                        <span class="dashboard-attention-icon dashboard-attention-icon-blue"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></span>
                        <span class="dashboard-attention-count"><?php echo number_format((int)$forReleaseCount); ?></span>
                        <span class="dashboard-attention-label">Ready for release</span>
                    </a>
                    <a class="dashboard-attention-item" href="sms">
                        <span class="dashboard-attention-icon dashboard-attention-icon-red"><i class="bi bi-chat-left-dots" aria-hidden="true"></i></span>
                        <span class="dashboard-attention-count"><?php echo number_format((int)($smsPending + $smsFailed)); ?></span>
                        <span class="dashboard-attention-label">SMS to review</span>
                        <span class="dashboard-attention-detail"><?php echo number_format((int)$smsFailed); ?> failed · <?php echo number_format((int)$smsPending); ?> pending</span>
                    </a>
                </div>
            </div>
        </section>
    </div>

    <div class="col-12 col-sm-6 col-md-4">
        <div class="card dashboard-stat-card shadow-sm rounded-4 border p-4">
            <div class="d-flex align-items-center gap-3 mb-2">
                <span class="badge bg-primary rounded-pill p-2"><i class="bi bi-arrow-down-circle text-white fs-5"></i></span>
                <div>
                    <div class="text-muted">Incoming Documents</div>
                    <div class="h4 mb-0"><?php echo sanitize($incomingToday); ?></div>
                </div>
            </div>
            <div class="text-muted"><?php echo sanitize($incomingMonth); ?> this month</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <div class="card dashboard-stat-card shadow-sm rounded-4 border p-4">
            <div class="d-flex align-items-center gap-3 mb-2">
                <span class="badge bg-csu-blue rounded-pill p-2"><i class="bi bi-arrow-up-circle text-white fs-5"></i></span>
                <div>
                    <div class="text-muted">Outgoing Documents</div>
                    <div class="h4 mb-0"><?php echo sanitize($outgoingToday); ?></div>
                </div>
            </div>
            <div class="text-muted"><?php echo sanitize($outgoingMonth); ?> this month</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <div class="card dashboard-stat-card shadow-sm rounded-4 border p-4">
            <div class="d-flex align-items-center gap-3 mb-2">
                <span class="badge bg-success rounded-pill p-2"><i class="bi bi-box-seam text-white fs-5"></i></span>
                <div>
                    <div class="text-muted">Borrowed Items</div>
                    <div class="h4 mb-0"><?php echo sanitize($borrowedCurrent); ?></div>
                </div>
            </div>
            <div class="text-muted">Currently borrowed</div>
        </div>
    </div>

    <!-- Analytics Charts -->
    <div class="col-12">
        <div class="row g-3 dashboard-charts">
            <div class="col-12 col-md-4">
                <div class="card dashboard-chart-card shadow-sm rounded-4 border-0">
                    <div class="card-body">
                        <h5 class="card-title text-primary">Document Status Distribution</h5>
                        <div class="dashboard-chart-container"><canvas id="statusChart"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card dashboard-chart-card shadow-sm rounded-4 border-0">
                    <div class="card-body">
                        <h5 class="card-title text-primary">Monthly Document Trends</h5>
                        <div class="dashboard-chart-container"><canvas id="monthlyChart"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card dashboard-chart-card shadow-sm rounded-4 border-0">
                    <div class="card-body">
                        <h5 class="card-title text-primary">SMS Status Overview</h5>
                        <div class="dashboard-chart-container"><canvas id="smsChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card dashboard-section-card shadow-sm rounded-4 border-0">
            <div class="card-body">
                <div class="dashboard-section-heading d-flex justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <h5 class="mb-1 text-primary">Latest Document Activity</h5>
                        <p class="text-muted mb-0">The most recently added incoming and outgoing documents.</p>
                    </div>
                    <a href="records" class="btn btn-sm btn-outline-primary">View records <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 dashboard-activity-table">
                        <thead class="small text-muted">
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Direction</th>
                                <th scope="col">Subject</th>
                                <th scope="col">Sender / recipient</th>
                                <th scope="col">Status</th>
                                <th scope="col"><span class="visually-hidden">Action</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentDocuments)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-inbox d-block fs-4 mb-1" aria-hidden="true"></i>No recent document activity.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentDocuments as $doc): ?>
                                    <?php
                                    $documentStatus = (string)$doc['status'];
                                    $documentStatusClass = $documentStatus === 'Pending' ? 'warning' : ($documentStatus === 'For Release' ? 'primary' : ($documentStatus === 'Released' ? 'success' : 'secondary'));
                                    $documentTypeClass = $doc['type'] === 'Incoming' ? 'primary' : 'success';
                                    ?>
                                    <tr>
                                        <td><span class="dashboard-activity-date"><?php echo sanitize(date('M j, Y', strtotime($doc['date_created']))); ?></span></td>
                                        <td><span class="badge text-bg-<?php echo $documentTypeClass; ?>"><?php echo sanitize($doc['type']); ?></span></td>
                                        <td><span class="dashboard-activity-subject"><?php echo sanitize($doc['subject']); ?></span></td>
                                        <td>
                                            <span class="dashboard-activity-parties"><span class="text-muted">From</span> <?php echo sanitize($doc['sender']); ?></span>
                                            <span class="dashboard-activity-parties"><span class="text-muted">To</span> <?php echo sanitize($doc['recipient']); ?></span>
                                        </td>
                                        <td><span class="badge text-bg-<?php echo $documentStatusClass; ?>"><?php echo sanitize($documentStatus); ?></span></td>
                                        <td><a class="btn btn-sm btn-light border dashboard-activity-view" href="records?search=<?php echo rawurlencode($doc['subject']); ?>" aria-label="View record for <?php echo sanitize($doc['subject']); ?>"><i class="bi bi-eye" aria-hidden="true"></i></a></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card dashboard-section-card shadow-sm rounded-4 border-0">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h5 class="mb-1 text-primary">Borrowed Items</h5>
                    <p class="text-muted mb-0">Items currently borrowed.</p>
                </div>
                <a href="borrowed" class="btn btn-sm btn-outline-primary">View Borrowed</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="text-uppercase small text-muted">
                        <tr>
                            <th>Item</th>
                            <th>Borrower</th>
                            <th>Borrowed</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($borrowedList)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No borrowed items to show.</td></tr>
                        <?php else: ?>
                            <?php foreach ($borrowedList as $item): ?>
                                <tr>
                                    <td><?php echo sanitize($item['item_name']); ?></td>
                                    <td><?php echo sanitize($item['borrower']); ?></td>
                                    <td><?php echo sanitize($item['date_borrowed']); ?></td>
                                    <td><span class="badge bg-<?php echo $item['display_status'] === 'Returned' ? 'success' : 'warning'; ?> text-white"><?php echo sanitize($item['display_status']); ?></span></td>
                                    <td><a class="btn btn-sm btn-light border" href="borrowed?search=<?php echo rawurlencode($item['item_name']); ?>" aria-label="View borrowed item"><i class="bi bi-eye"></i></a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    </div>

    <div class="col-12">
    <div class="row g-3">
        <div class="col-12">
    <div class="card dashboard-section-card shadow-sm rounded-4 border-0">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="mb-1 text-primary">SMS Summary</h5>
                        <p class="text-muted mb-0">Monthly delivery status at a glance.</p>
                    </div>
                    <a href="sms" class="btn btn-sm btn-outline-primary">All SMS</a>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Delivered</span>
                    <strong><?php echo sanitize($smsDelivered); ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Pending</span>
                    <strong><?php echo sanitize($smsPending); ?></strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-muted">Failed</span>
                    <strong><?php echo sanitize($smsFailed); ?></strong>
                </div>
            </div>
        </div>
            </div>
            <div class="col-12">
        <div class="card dashboard-section-card shadow-sm rounded-4 border-0">
            <div class="card-body">
                <div class="dashboard-section-heading d-flex justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <h5 class="mb-1 text-primary">Recent SMS Logs</h5>
                                    <p class="text-muted mb-0">Recent message outcomes and recipients.</p>
                    </div>
                                <a href="sms" class="btn btn-sm btn-outline-primary">View SMS <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></a>
                </div>
                <?php if (empty($recentSms)): ?>
                                <div class="dashboard-sms-empty text-center text-muted py-4"><i class="bi bi-chat-square-text d-block fs-4 mb-1" aria-hidden="true"></i>No recent SMS records.</div>
                <?php else: ?>
                                <div class="dashboard-sms-list">
                                <?php foreach ($recentSms as $sms): ?>
                                    <?php
                                    $recentSmsStatus = strtolower((string)$sms['status']);
                                    $recentSmsStatusClass = in_array($recentSmsStatus, ['delivered', 'success'], true) ? 'success' : ($recentSmsStatus === 'failed' ? 'danger' : (in_array($recentSmsStatus, ['pending', 'queued', 'sent'], true) ? 'warning text-dark' : 'secondary'));
                                    ?>
                                    <article class="dashboard-sms-item">
                                        <div class="dashboard-sms-copy">
                                            <div class="dashboard-sms-recipient"><i class="bi bi-telephone me-2" aria-hidden="true"></i><?php echo sanitize($sms['recipient']); ?></div>
                                            <p class="dashboard-sms-message mb-0" title="<?php echo sanitize($sms['message']); ?>"><?php echo sanitize($sms['message']); ?></p>
                                            <time class="dashboard-sms-time" datetime="<?php echo sanitize(date('c', strtotime($sms['date_sent']))); ?>"><i class="bi bi-clock me-1" aria-hidden="true"></i><?php echo sanitize(date('M j, Y · g:i A', strtotime($sms['date_sent']))); ?></time>
                                        </div>
                                        <span class="badge text-bg-<?php echo $recentSmsStatusClass; ?> dashboard-sms-status"><?php echo sanitize($sms['status']); ?></span>
                                    </article>
                                <?php endforeach; ?>
                                </div>
                <?php endif; ?>
                </div>
        </div>
                </div>
            <div class="col-12">
        <div class="card dashboard-section-card shadow-sm rounded-4 border-0">
                <div class="card-body">
                    <div class="system-overview-heading">
                        <div>
                            <p class="dashboard-eyebrow mb-1">Workspace snapshot</p>
                            <h3 class="h5 text-primary mb-1">System Overview</h3>
                            <p class="small text-muted mb-0">Records and setup at a glance</p>
                        </div>
                        <span class="system-overview-live"><span aria-hidden="true"></span>Updated on page load</span>
                    </div>
                    <div class="system-overview-grid">
                        <div class="system-overview-metric">
                            <span class="system-overview-icon"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span>
                            <span class="system-overview-value"><?php echo number_format((int)$totalItems); ?></span>
                            <span class="system-overview-label">Total records</span>
                            <span class="system-overview-detail"><?php echo number_format((int)$documentCount); ?> documents · <?php echo number_format((int)$borrowedCount); ?> borrowed-item records</span>
                        </div>
                        <a class="system-overview-metric" href="borrowed">
                            <span class="system-overview-icon system-overview-icon-gold"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
                            <span class="system-overview-value"><?php echo number_format((int)$borrowedCurrent); ?></span>
                            <span class="system-overview-label">Items borrowed</span>
                            <span class="system-overview-detail"><?php echo (int)$borrowedCurrent > 0 ? 'Awaiting return' : 'All items returned'; ?></span>
                        </a>
                        <div class="system-overview-metric">
                            <span class="system-overview-icon system-overview-icon-violet"><i class="bi bi-diagram-3" aria-hidden="true"></i></span>
                            <span class="system-overview-value"><?php echo number_format((int)$categories); ?></span>
                            <span class="system-overview-label">Document flows</span>
                            <span class="system-overview-detail">Incoming and outgoing</span>
                        </div>
                    </div>
                    <div class="system-overview-footer">
                        <span><i class="bi bi-database-check me-2" aria-hidden="true"></i>Dashboard data loaded successfully</span>
                        <span>Snapshot · <?php echo sanitize(date('g:i A')); ?></span>
                    </div>
                </div>
        </div>
            </div>
        </div>
    </div>

</div>

<div class="modal fade" id="dashboardIncomingForm" tabindex="-1" aria-labelledby="dashboardIncomingFormTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title text-primary" id="dashboardIncomingFormTitle">Add Incoming Document</h5>
                    <p class="mb-0 mt-1 small text-muted">Contact information is optional.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="dashboardIncomingFormElement" novalidate>
                <div class="modal-body">
                    <div class="alert alert-danger d-none mb-3" id="dashboardIncomingFormError" role="alert"></div>
                    <div class="mb-3">
                        <label class="form-label" for="dashboardSubmitterType">Submitted by</label>
                        <select id="dashboardSubmitterType" name="submitter_type" class="form-select" required>
                            <option value="Student">Student</option>
                            <option value="Faculty">Faculty</option>
                            <option value="Other" selected>Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="dashboardIncomingSender">From</label>
                        <input type="text" id="dashboardIncomingSender" name="sender" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="dashboardIncomingSubject">Document type</label>
                        <input type="text" id="dashboardIncomingSubject" name="subject" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="dashboardIncomingPhone">Client Contact Number <span class="text-muted">(optional)</span></label>
                        <input type="tel" id="dashboardIncomingPhone" name="client_phone" class="form-control" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" placeholder="09123456789" title="Enter exactly 11 digits.">
                        <div class="form-text">Enter exactly 11 digits, or leave blank.</div>
                    </div>
                    <div>
                        <label class="form-label" for="dashboardIncomingRemarks">Remarks <span class="text-muted">(optional)</span></label>
                        <textarea id="dashboardIncomingRemarks" name="remarks" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="dashboardIncomingSave">
                        <i class="bi bi-check2 me-1" aria-hidden="true"></i>Save Document
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="quickActionModal" tabindex="-1" aria-labelledby="quickActionModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl quick-action-modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header py-2">
                <h5 class="modal-title text-primary fs-6" id="quickActionModalTitle">Quick action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="quickActionFrame" class="quick-action-frame" title="Quick action tool" allow="camera"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const quickActionModal = document.getElementById('quickActionModal');
    const quickActionModalDialog = quickActionModal.querySelector('.quick-action-modal-dialog');
    const quickActionFrame = document.getElementById('quickActionFrame');
    const quickActionModalTitle = document.getElementById('quickActionModalTitle');

    quickActionModal.addEventListener('show.bs.modal', function(event) {
        const trigger = event.relatedTarget;
        if (!trigger || !trigger.dataset.quickActionUrl) return;
        quickActionModalDialog.classList.remove('quick-action-modal-scanner', 'quick-action-modal-batch', 'quick-action-modal-reports');
        const layout = trigger.dataset.quickActionLayout;
        if (layout) quickActionModalDialog.classList.add('quick-action-modal-' + layout);
        quickActionModalTitle.textContent = trigger.dataset.quickActionTitle || 'Quick action';
        quickActionFrame.title = (trigger.dataset.quickActionTitle || 'Quick action') + ' tool';
        quickActionFrame.src = trigger.dataset.quickActionUrl;
    });
    quickActionModal.addEventListener('hidden.bs.modal', function() {
        quickActionFrame.src = 'about:blank';
        quickActionModalDialog.classList.remove('quick-action-modal-scanner');
    });

    const incomingForm = document.getElementById('dashboardIncomingFormElement');
    const incomingFormError = document.getElementById('dashboardIncomingFormError');
    const incomingSaveButton = document.getElementById('dashboardIncomingSave');

    incomingForm.addEventListener('submit', async function(event) {
        event.preventDefault();
        incomingFormError.classList.add('d-none');
        incomingFormError.textContent = '';
        incomingSaveButton.disabled = true;
        incomingSaveButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Saving...';

        try {
            const formData = new FormData(incomingForm);
            formData.set('csrf_token', <?php echo json_encode(csrf_token()); ?>);
            const response = await fetch('dashboard.php?quick_action=add_incoming', {
                method: 'POST',
                body: formData,
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Could not save the incoming document.');
            }

            window.location.reload();
        } catch (error) {
            incomingFormError.textContent = error.message;
            incomingFormError.classList.remove('d-none');
        } finally {
            incomingSaveButton.disabled = false;
            incomingSaveButton.innerHTML = '<i class="bi bi-check2 me-1" aria-hidden="true"></i>Save Document';
        }
    });

    // Status Distribution Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    const statusData = <?php echo json_encode($statusData); ?>;
    const statusLabels = Object.keys(statusData);
    const statusValues = Object.values(statusData);
    const statusColors = {
        'Pending': '#ffc107',
        'For Release': '#0d6efd',
        'Released': '#198754',
        'Received': '#6c757d'
    };

    new Chart(statusCtx, {
        type: 'pie',
        data: {
            labels: statusLabels,
            datasets: [{
                data: statusValues,
                backgroundColor: statusLabels.map((label) => statusColors[label] || '#6c757d'),
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                }
            }
        }
    });

    // Monthly Trends Chart
    const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
    const months = <?php echo json_encode($months); ?>;
    const incomingData = <?php echo json_encode($monthlyIncoming); ?>;
    const outgoingData = <?php echo json_encode($monthlyOutgoing); ?>;

    new Chart(monthlyCtx, {
        type: 'bar',
        data: {
            labels: months,
            datasets: [{
                label: 'Incoming',
                data: incomingData,
                backgroundColor: '#0f5132',
                borderColor: '#0f5132',
                borderWidth: 1
            }, {
                label: 'Outgoing',
                data: outgoingData,
                backgroundColor: '#ff6b35',
                borderColor: '#ff6b35',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });

    // SMS Status Chart
    const smsCtx = document.getElementById('smsChart').getContext('2d');
    new Chart(smsCtx, {
        type: 'doughnut',
        data: {
            labels: ['Delivered', 'Pending', 'Failed'],
            datasets: [{
                data: [<?php echo $smsDelivered; ?>, <?php echo $smsPending; ?>, <?php echo $smsFailed; ?>],
                backgroundColor: [
                    '#0f5132',
                    '#ffa500',
                    '#dc3545'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                }
            }
        }
    });
});
</script>

<?php include 'footer.php'; ?>
