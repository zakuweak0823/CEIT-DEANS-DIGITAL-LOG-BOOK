<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'mobile_submission_console_control.php';
require_once 'sla_support.php';
ensure_admin();

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

$incomingToday = safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Incoming' AND DATE(date_created) = ?", 's', [$today]);
$yesterday = date('Y-m-d', strtotime('-1 day'));
$incomingYesterday = safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Incoming' AND DATE(date_created) = ?", 's', [$yesterday]);
$outgoingYesterday = safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Outgoing' AND DATE(date_created) = ?", 's', [$yesterday]);
$incomingMonth = safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Incoming' AND DATE(date_created) >= ?", 's', [$monthStart]);
$outgoingToday = safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Outgoing' AND DATE(date_created) = ?", 's', [$today]);
$outgoingMonth = safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Outgoing' AND DATE(date_created) >= ?", 's', [$monthStart]);
$loansActive = safe_scalar($conn, "SELECT COUNT(*) FROM borrowed_items WHERE status != 'Returned'");
$pendingIncoming = safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Incoming' AND status = 'Pending'");
$forReleaseCount = safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Outgoing' AND status = 'For Release'");
$smsPending = safe_scalar($conn, "SELECT COUNT(*) FROM sms_logs WHERE LOWER(status) IN ('pending', 'queued')");
$smsFailed = safe_scalar($conn, "SELECT COUNT(*) FROM sms_logs WHERE LOWER(status) = 'failed'");
$totalDocuments = safe_scalar($conn, "SELECT COUNT(*) FROM documents");

$slaSettings = get_sla_settings($conn);
$slaOverdue = get_sla_overdue($conn, $slaSettings, 300);
$overdueTotal = (int)$slaOverdue['documentCount'];
$overdueIncoming = (int)safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Incoming' AND status = 'Pending' AND date_created < (NOW() - INTERVAL ? DAY)", 'i', [$slaSettings['incomingDays']]);
$overdueOutgoing = (int)safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Outgoing' AND status = 'For Release' AND date_created < (NOW() - INTERVAL ? DAY)", 'i', [$slaSettings['outgoingDays']]);
$overdueRows = [];
foreach ($slaOverdue['documents'] as $doc) {
    $isIncoming = $doc['type'] === 'Incoming';
    $person = $isIncoming ? ($doc['submitter_name'] !== '' ? $doc['submitter_name'] : $doc['sender']) : $doc['recipient'];
    $overdueRows[] = [
        'subject' => $doc['subject'],
        'type' => $isIncoming ? 'Incoming' : 'Outgoing',
        'person' => (string)$person,
        'status' => (string)$doc['status'],
        'open' => (int)$doc['days_open'],
        'late' => (int)$doc['days_open'] - (int)($isIncoming ? $slaSettings['incomingDays'] : $slaSettings['outgoingDays']),
        'href' => ($isIncoming ? 'incoming' : 'outgoing') . '?document_id=' . (int)$doc['doc_id'],
    ];
}
usort($overdueRows, static function ($a, $b) { return $b['late'] <=> $a['late']; });
// Full overdue lists for the pop-out windows; the dashboard table itself shows the top six.
$overdueByType = ['Incoming' => [], 'Outgoing' => []];
foreach ($overdueRows as $overdueRow) {
    $overdueByType[$overdueRow['type']][] = $overdueRow;
}
$overdueRows = array_slice($overdueRows, 0, 6);
$openDocuments = (int)$pendingIncoming + (int)$forReleaseCount;
$slaCompliance = $openDocuments > 0 ? max(0, (int)round((($openDocuments - $slaOverdue['documentCount']) / $openDocuments) * 100)) : 100;

// Age of documents that are still open (Pending incoming, For Release outgoing).
$agingBuckets = ['0-1 day' => 0, '2-3 days' => 0, '4-7 days' => 0, 'Over 7 days' => 0];
$stmt = safe_query($conn, "SELECT DATEDIFF(NOW(), date_created) AS age FROM documents WHERE (type = 'Incoming' AND status = 'Pending') OR (type = 'Outgoing' AND status = 'For Release')");
if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $age = (int)$row['age'];
        if ($age <= 1) {
            $agingBuckets['0-1 day']++;
        } elseif ($age <= 3) {
            $agingBuckets['2-3 days']++;
        } elseif ($age <= 7) {
            $agingBuckets['4-7 days']++;
        } else {
            $agingBuckets['Over 7 days']++;
        }
    }
    mysqli_stmt_close($stmt);
}

$smsStatusData = [];
$stmt = safe_query($conn, "SELECT CONCAT(UPPER(SUBSTRING(status, 1, 1)), LOWER(SUBSTRING(status, 2))) AS status_label, COUNT(*) AS total FROM sms_logs GROUP BY LOWER(status) ORDER BY total DESC");
if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $smsStatusData[$row['status_label'] !== '' ? $row['status_label'] : 'Unknown'] = (int)$row['total'];
    }
    mysqli_stmt_close($stmt);
}

$recentDocuments = [];
$stmt = safe_query($conn, "SELECT date_created, type, sender, submitter_name, recipient, subject, status FROM documents ORDER BY date_created DESC LIMIT 5");
if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $recentDocuments[] = $row;
    }
    mysqli_stmt_close($stmt);
}

$loanList = [];
$stmt = safe_query($conn, "SELECT item_name, borrower, date_borrowed FROM borrowed_items WHERE status <> 'Returned' ORDER BY date_borrowed ASC LIMIT 5");
if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $loanList[] = $row;
    }
    mysqli_stmt_close($stmt);
}

$recentSms = [];
$stmt = safe_query($conn, "SELECT recipient, message, status, date_sent FROM sms_logs ORDER BY date_sent DESC LIMIT 5");
if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $recentSms[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// Keep the status chart stable when the database contains legacy status labels.
$statusData = [];
$stmt = safe_query($conn, "SELECT status, COUNT(*) as count FROM documents GROUP BY status");
if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $statusData[$row['status']] = $row['count'];
    }
    mysqli_stmt_close($stmt);
}
$statusOrder = ['Pending', 'For Release', 'Released', 'Received'];
uksort($statusData, static function ($left, $right) use ($statusOrder) {
    $leftIndex = array_search($left, $statusOrder, true);
    $rightIndex = array_search($right, $statusOrder, true);
    $leftIndex = $leftIndex === false ? count($statusOrder) : $leftIndex;
    $rightIndex = $rightIndex === false ? count($statusOrder) : $rightIndex;
    return $leftIndex === $rightIndex ? strcasecmp($left, $right) : $leftIndex <=> $rightIndex;
});

// Keep chart series in chronological order, including months with zero submissions.
$monthlyIncoming = [];
$monthlyOutgoing = [];
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("first day of -$i months"));
    $monthlyIncoming[] = (int)safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Incoming' AND DATE_FORMAT(date_created, '%Y-%m') = ?", 's', [$month]);
    $monthlyOutgoing[] = (int)safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Outgoing' AND DATE_FORMAT(date_created, '%Y-%m') = ?", 's', [$month]);
    $months[] = date('M Y', strtotime("first day of -$i months"));
}

$plural = static function (int $count, string $singular, ?string $pluralForm = null): string {
    return number_format($count) . ' ' . ($count === 1 ? $singular : ($pluralForm ?? $singular . 's'));
};
?>

<?php include 'header.php'; ?>
<div class="dashboard-page dashboard-redesign">
    <header class="dashboard-page-heading">
        <div>
            <h1>Dashboard</h1>
            <p>An overview of documents, borrowed items, and records that need attention.</p>
        </div>
        <nav class="dashboard-page-actions" aria-label="Dashboard actions">
            <button class="btn dashboard-action-primary" type="button" data-quick-action-url="batch_papers?open=scanner&amp;embed=1" data-quick-action-title="Scan office QR code" data-quick-action-layout="scanner" data-bs-toggle="modal" data-bs-target="#quickActionModal"><i class="bi bi-qr-code-scan" aria-hidden="true"></i>            Scan QR code</button>
                    </nav>
    </header>

    <section class="dashboard-metrics" aria-label="Office summary">
        <a class="dashboard-metric dashboard-metric-link" href="incoming">
            <span class="dashboard-metric-icon dashboard-metric-icon-green"><i class="bi bi-inbox" aria-hidden="true"></i></span>
            <span class="dashboard-metric-copy"><span>Incoming today</span><strong><?php echo number_format((int)$incomingToday); ?></strong><small>Yesterday: <?php echo number_format((int)$incomingYesterday); ?><br><?php echo number_format((int)$incomingMonth); ?> this month</small></span>
        </a>
        <a class="dashboard-metric dashboard-metric-link" href="outgoing">
            <span class="dashboard-metric-icon dashboard-metric-icon-teal"><i class="bi bi-send" aria-hidden="true"></i></span>
            <span class="dashboard-metric-copy"><span>Outgoing today</span><strong><?php echo number_format((int)$outgoingToday); ?></strong><small>Yesterday: <?php echo number_format((int)$outgoingYesterday); ?><br><?php echo number_format((int)$outgoingMonth); ?> this month</small></span>
        </a>
        <a class="dashboard-metric dashboard-metric-link" href="incoming?status=Pending">
            <span class="dashboard-metric-icon dashboard-metric-icon-sand"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span>
            <span class="dashboard-metric-copy"><span>Pending incoming</span><strong><?php echo number_format((int)$pendingIncoming); ?></strong><small>Awaiting review</small></span>
        </a>
        <a class="dashboard-metric dashboard-metric-link" href="outgoing?status=For%20Release">
            <span class="dashboard-metric-icon dashboard-metric-icon-neutral"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></span>
            <span class="dashboard-metric-copy"><span>For release</span><strong><?php echo number_format((int)$forReleaseCount); ?></strong><small>Outgoing, ready for pickup</small></span>
        </a>
    </section>

    <section class="dashboard-panel dashboard-sla-panel" aria-labelledby="dashboardSlaTitle">
        <div class="dashboard-panel-heading dashboard-panel-heading-action">
            <div>
                <span class="dashboard-section-eyebrow">Processing time</span>
                <h2 id="dashboardSlaTitle">Overdue records</h2>
                <?php if ($slaSettings['enabled']): ?>
                    <p>Records that stayed open longer than their time limit.</p>
                <?php else: ?>
                    <p>Overdue tracking is turned off.</p>
                <?php endif; ?>
            </div>
            <a href="settings#sla-settings" class="dashboard-text-link">Processing time settings<i class="bi bi-gear" aria-hidden="true"></i></a>
        </div>
        <?php if ($slaSettings['enabled']): ?>
            <div class="dashboard-sla-summary">
                <?php foreach ([['Incoming', $overdueIncoming, (int)$slaSettings['incomingDays'], 'incoming?overdue=1'], ['Outgoing', $overdueOutgoing, (int)$slaSettings['outgoingDays'], 'outgoing?overdue=1']] as $limitTile): ?>
                    <?php $tileTag = $limitTile[1] > 0 ? 'button' : 'div'; ?>
                    <<?php echo $tileTag; ?> class="dashboard-sla-stat <?php echo $limitTile[1] > 0 ? 'is-alert dashboard-sla-link' : 'is-ok'; ?>"<?php echo $limitTile[1] > 0 ? ' type="button" data-bs-toggle="modal" data-bs-target="#overdueModal' . sanitize($limitTile[0]) . '" aria-label="View the ' . (int)$limitTile[1] . ' overdue ' . strtolower(sanitize($limitTile[0])) . ' documents"' : ''; ?>>
                        <span><?php echo sanitize($limitTile[0]); ?></span>
                        <strong><?php echo number_format($limitTile[1]); ?></strong>
                        <em>overdue after <?php echo sanitize($plural($limitTile[2], 'day')); ?></em>
                    </<?php echo $tileTag; ?>>
                <?php endforeach; ?>
                <div class="dashboard-sla-stat">
                    <span>Open documents on time</span>
                    <strong><?php echo (int)$slaCompliance; ?>%</strong>
                    <div class="dashboard-ontime-bar" role="img" aria-label="<?php echo (int)$slaCompliance; ?> percent of open documents are within their time limit"><span style="width: <?php echo (int)$slaCompliance; ?>%"></span></div>
                </div>
            </div>
            <?php if ($overdueTotal === 0): ?>
                <div class="dashboard-empty-state"><i class="bi bi-check2-circle" aria-hidden="true"></i><span>No overdue records. All items are within their processing time.</span></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 dashboard-activity-table admin-mobile-card-table">
                        <thead><tr><th scope="col">Record</th><th scope="col">Type</th><th scope="col">Person</th><th scope="col">Status</th><th scope="col">Overdue by</th><th scope="col">Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($overdueRows as $row): ?>
                                <tr>
                                    <td data-label="Record"><span class="dashboard-activity-subject"><?php echo sanitize($row['subject']); ?></span></td>
                                    <td data-label="Type"><span class="badge <?php echo $row['type'] === 'Incoming' ? 'dashboard-direction-incoming' : ($row['type'] === 'Outgoing' ? 'dashboard-direction-outgoing' : 'admin-status-neutral'); ?>"><?php echo sanitize($row['type']); ?></span></td>
                                    <td data-label="Person"><?php echo sanitize($row['person'] !== '' ? $row['person'] : '-'); ?></td>
                                    <td data-label="Status"><span class="badge <?php echo admin_status_badge_class($row['status']); ?>"><?php echo sanitize($row['status']); ?></span></td>
                                    <td data-label="Overdue by"><span class="dashboard-overdue-days"><?php echo sanitize($plural(max(1, $row['late']), 'day')); ?></span><small class="d-block text-muted"><?php echo sanitize($plural($row['open'], 'day')); ?> open</small></td>
                                    <td data-label="Actions"><a class="btn btn-sm btn-outline-secondary" href="<?php echo sanitize($row['href']); ?>" aria-label="Review <?php echo sanitize($row['subject']); ?>">Review</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($overdueTotal > count($overdueRows)): ?>
                    <p class="dashboard-overdue-note">Showing the <?php echo count($overdueRows); ?> most overdue of <?php echo number_format($overdueTotal); ?> records.</p>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php foreach (['Incoming' => $overdueIncoming, 'Outgoing' => $overdueOutgoing] as $modalType => $modalCount): ?>
        <?php if ($modalCount > 0): ?>
            <div class="modal fade overdue-modal" id="overdueModal<?php echo $modalType; ?>" tabindex="-1" aria-labelledby="overdueModal<?php echo $modalType; ?>Title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title" id="overdueModal<?php echo $modalType; ?>Title">Overdue <?php echo strtolower($modalType); ?> documents</h5>
                                <p class="overdue-modal-sub"><?php echo number_format($modalCount); ?> past the <?php echo sanitize($plural((int)$slaSettings[strtolower($modalType) . 'Days'], 'day')); ?> limit, most overdue first</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body p-0">
                            <ul class="overdue-modal-list">
                                <?php foreach ($overdueByType[$modalType] as $row): ?>
                                    <li>
                                        <div class="overdue-modal-text">
                                            <strong class="cell-clamp"><?php echo sanitize($row['subject']); ?></strong>
                                            <span><?php echo sanitize($row['person'] !== '' ? $row['person'] : '-'); ?></span>
                                        </div>
                                        <div class="overdue-modal-meta">
                                            <span class="dashboard-overdue-days"><?php echo sanitize($plural(max(1, $row['late']), 'day')); ?> overdue</span>
                                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo sanitize($row['href']); ?>" aria-label="Review <?php echo sanitize($row['subject']); ?>">Review</a>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div class="modal-footer">
                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo $modalType === 'Incoming' ? 'incoming' : 'outgoing'; ?>?overdue=1">Open full list</a>
                            <button type="button" class="btn btn-sm btn-primary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <section class="dashboard-reporting" aria-labelledby="dashboardReportingTitle">
        <div class="dashboard-section-heading">
            <div><span class="dashboard-section-eyebrow">Analytics</span><h2 id="dashboardReportingTitle">Document activity</h2></div>
            <a href="reports" class="dashboard-text-link">Open reports<i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
        </div>
        <div class="dashboard-chart-grid">
            <article class="dashboard-panel dashboard-chart-panel">
                <div class="dashboard-panel-heading"><h3>Documents by their status</h3><span>All recorded documents</span></div>
                <div class="dashboard-chart-container"><canvas id="redesignStatusChart" role="img" aria-label="Chart showing document counts by status"></canvas></div>
            </article>
            <article class="dashboard-panel dashboard-chart-panel">
                <div class="dashboard-panel-heading"><h3>Monthly document volume</h3><span>Last six months</span></div>
                <div class="dashboard-chart-container"><canvas id="redesignMonthlyChart" role="img" aria-label="Chart comparing incoming and outgoing document counts by month"></canvas></div>
            </article>
            <article class="dashboard-panel dashboard-chart-panel">
                <div class="dashboard-panel-heading"><h3>Document processing time<button type="button" class="chart-info-button" data-chart-info="<strong>Bars show open documents</strong> (Pending incoming and For Release outgoing), grouped by how many days they have been waiting. Documents waiting over 7 days need attention first." aria-label="About this chart"><i class="bi bi-info-circle" aria-hidden="true"></i></button></h3><span>Days Document has been waiting</span></div>
                <div class="dashboard-chart-container"><canvas id="redesignAgingChart" role="img" aria-label="Chart showing how many days documents have been waiting for action"></canvas></div>
            </article>
            <article class="dashboard-panel dashboard-chart-panel">
                <div class="dashboard-panel-heading"><h3>SMS overview<button type="button" class="chart-info-button" data-chart-info="<strong>Delivered:</strong> confirmed received on the recipient's phone.<br><strong>Pending:</strong> awaiting confirmation.<br><strong>Failed:</strong> not delivered. Follow up with the recipient." aria-label="What the SMS statuses mean"><i class="bi bi-info-circle" aria-hidden="true"></i></button></h3><span>Delivery status of all messages</span></div>
                <div class="dashboard-chart-container"><canvas id="redesignSmsChart" role="img" aria-label="Chart showing SMS messages by delivery status"></canvas></div>
            </article>
        </div>
    </section>

    <section class="dashboard-panel dashboard-records-panel" aria-labelledby="dashboardRecentDocumentsTitle">
        <div class="dashboard-panel-heading dashboard-panel-heading-action">
            <div><span class="dashboard-section-eyebrow">Latest records</span><h2 id="dashboardRecentDocumentsTitle">Recent document activity</h2><p>The five most recently recorded documents.</p></div>
            <a href="records" class="dashboard-text-link">View all records<i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0 dashboard-activity-table admin-mobile-card-table">
                <thead><tr><th scope="col">Date</th><th scope="col">Direction</th><th scope="col">Subject</th><th scope="col">Submitter / recipient</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($recentDocuments)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-inbox d-block fs-4 mb-1" aria-hidden="true"></i>No documents have been recorded yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentDocuments as $doc): ?>
                            <?php
                            $documentStatus = (string)$doc['status'];
                            $documentTypeClass = $doc['type'] === 'Incoming' ? 'dashboard-direction-incoming' : 'dashboard-direction-outgoing';
                            $documentParty = $doc['type'] === 'Incoming' ? ($doc['submitter_name'] !== '' ? $doc['submitter_name'] : $doc['sender']) : $doc['recipient'];
                            ?>
                            <tr>
                                <td data-label="Date"><span class="dashboard-activity-date"><?php echo sanitize(date('M j, Y', strtotime($doc['date_created']))); ?></span></td>
                                <td data-label="Direction"><span class="badge <?php echo $documentTypeClass; ?>"><?php echo sanitize($doc['type']); ?></span></td>
                                <td data-label="Subject"><span class="dashboard-activity-subject"><?php echo sanitize($doc['subject']); ?></span></td>
                                <td data-label="<?php echo $doc['type'] === 'Incoming' ? 'Submitter' : 'Recipient'; ?>"><?php echo sanitize($documentParty !== '' ? $documentParty : '-'); ?></td>
                                <td data-label="Status"><span class="badge <?php echo admin_status_badge_class($documentStatus); ?>"><?php echo sanitize($documentStatus); ?></span></td>
                                <td data-label="Actions"><a class="btn btn-sm dashboard-record-view" href="records?search=<?php echo rawurlencode($doc['subject']); ?>" aria-label="View record for <?php echo sanitize($doc['subject']); ?>"><i class="bi bi-eye" aria-hidden="true"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="dashboard-followup-grid" aria-label="Office follow-up">
        <article class="dashboard-panel">
            <div class="dashboard-panel-heading dashboard-panel-heading-action">
                <div><span class="dashboard-section-eyebrow">Borrowed Items</span><h2>Awaiting return</h2><p><?php echo sanitize($plural((int)$loansActive, 'borrowed item')); ?></p></div>
                <a href="borrowed" class="dashboard-text-link">View borrowed items<i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <?php if (empty($loanList)): ?>
                <div class="dashboard-empty-state"><i class="bi bi-box-seam" aria-hidden="true"></i><span>There are no borrowed items awaiting return.</span></div>
            <?php else: ?>
                <div class="dashboard-followup-list">
                    <?php foreach ($loanList as $item): ?>
                        <a class="dashboard-followup-item" href="borrowed?search=<?php echo rawurlencode($item['item_name']); ?>">
                            <span class="dashboard-followup-icon"><i class="bi bi-box" aria-hidden="true"></i></span>
                            <span class="dashboard-followup-copy"><strong><?php echo sanitize($item['item_name']); ?></strong><small><?php echo sanitize($item['borrower']); ?> - borrowed on <?php echo sanitize(date('M j, Y', strtotime($item['date_borrowed']))); ?></small></span>
                            <i class="bi bi-chevron-right dashboard-followup-arrow" aria-hidden="true"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-panel-heading dashboard-panel-heading-action">
                <div><span class="dashboard-section-eyebrow">Communication</span><h2>Recent SMS messages</h2><p><?php echo sanitize($plural((int)($smsPending + $smsFailed), 'message')); ?> need follow-up.</p></div>
                <a href="sms" class="dashboard-text-link">View SMS logs<i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <?php if (empty($recentSms)): ?>
                <div class="dashboard-empty-state"><i class="bi bi-chat-square-text" aria-hidden="true"></i><span>No SMS messages have been sent yet.</span></div>
            <?php else: ?>
                <div class="dashboard-followup-list dashboard-sms-list">
                    <?php foreach ($recentSms as $sms): ?>
                        <article class="dashboard-followup-item dashboard-sms-item">
                            <span class="dashboard-followup-icon"><i class="bi bi-chat-dots" aria-hidden="true"></i></span>
                            <span class="dashboard-followup-copy"><strong><?php echo sanitize($sms['recipient']); ?></strong><small class="dashboard-sms-message" title="<?php echo sanitize($sms['message']); ?>"><?php echo sanitize($sms['message']); ?></small><small><?php echo sanitize(date('M j, Y, g:i A', strtotime($sms['date_sent']))); ?></small></span>
                            <span class="badge <?php echo admin_status_badge_class($sms['status']); ?> dashboard-sms-status"><?php echo sanitize($sms['status']); ?></span>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </section>
</div>

<div class="modal fade" id="quickActionModal" tabindex="-1" aria-labelledby="quickActionModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl quick-action-modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header py-2">
                <h2 class="modal-title h6 text-primary" id="quickActionModalTitle">Quick action</h2>
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
    document.querySelectorAll('.chart-info-button').forEach(function (button) {
        new bootstrap.Popover(button, {
            html: true,
            trigger: 'focus hover',
            placement: 'bottom',
            container: 'body',
            customClass: 'chart-info-popover',
            content: button.getAttribute('data-chart-info')
        });
    });
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

    const statusColors = {
        'Pending': '#f6d776',
        'For Release': '#7cc9bd',
        'Released': '#79c28f',
        'Received': '#c4de7a'
    };
    const gridColor = 'rgba(100, 116, 139, 0.14)';
    const fontFamily = getComputedStyle(document.body).fontFamily;
    Chart.defaults.font.family = fontFamily;
    Chart.defaults.color = '#475569';
    const legend = { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, padding: 16 } };
    const countAxis = { beginAtZero: true, ticks: { precision: 0 }, grid: { color: gridColor }, border: { display: false } };
    const percentTooltip = {
        callbacks: {
            label: (context) => {
                const total = context.dataset.data.reduce((sum, value) => sum + value, 0);
                const share = total > 0 ? Math.round((context.parsed / total) * 100) : 0;
                return ' ' + context.label + ': ' + context.parsed + ' (' + share + '%)';
            }
        }
    };
    // Shows the total in the doughnut centre, or a message when there is no data.
    const centerTotal = {
        id: 'centerTotal',
        afterDraw(chart) {
            const values = chart.data.datasets[0].data;
            const total = values.reduce((sum, value) => sum + value, 0);
            const area = chart.chartArea;
            const x = (area.left + area.right) / 2;
            const y = (area.top + area.bottom) / 2;
            const context = chart.ctx;
            context.save();
            context.textAlign = 'center';
            context.textBaseline = 'middle';
            context.fillStyle = '#064e3b';
            if (total === 0) {
                context.font = '600 13px ' + fontFamily;
                context.fillText('No data yet', x, y);
            } else {
                context.font = '700 24px ' + fontFamily;
                context.fillText(total.toLocaleString(), x, y - 7);
                context.fillStyle = '#64748b';
                context.font = '500 11px ' + fontFamily;
                context.fillText('Total', x, y + 14);
            }
            context.restore();
        }
    };

    const statusData = <?php echo json_encode($statusData); ?>;
    const statusLabels = Object.keys(statusData);
    new Chart(document.getElementById('redesignStatusChart'), {
        type: 'doughnut',
        data: {
            labels: statusLabels,
            datasets: [{
                data: Object.values(statusData).map(Number),
                backgroundColor: statusLabels.map((label) => statusColors[label] || '#cbd5e1'),
                borderColor: '#ffffff',
                borderWidth: 2,
                borderRadius: 3,
                spacing: 2
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, cutout: '66%', plugins: { legend, tooltip: percentTooltip } },
        plugins: [centerTotal]
    });

    new Chart(document.getElementById('redesignMonthlyChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($months); ?>,
            datasets: [{
                label: 'Incoming',
                data: <?php echo json_encode($monthlyIncoming); ?>,
                backgroundColor: '#17613e',
                borderRadius: 5,
                maxBarThickness: 28
            }, {
                label: 'Outgoing',
                data: <?php echo json_encode($monthlyOutgoing); ?>,
                backgroundColor: '#5eb7a8',
                borderRadius: 5,
                maxBarThickness: 28
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: countAxis, x: { grid: { display: false } } },
            plugins: { legend },
            interaction: { intersect: false, mode: 'index' }
        }
    });

    const agingData = <?php echo json_encode($agingBuckets); ?>;
    new Chart(document.getElementById('redesignAgingChart'), {
        type: 'bar',
        data: {
            labels: Object.keys(agingData),
            datasets: [{
                label: 'Documents',
                data: Object.values(agingData),
                backgroundColor: ['#79c28f', '#c4de7a', '#f6d776', '#e8a0a8'],
                borderRadius: 5,
                maxBarThickness: 44
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: countAxis, x: { grid: { display: false } } },
            plugins: { legend: { display: false } }
        }
    });

    const smsData = <?php echo json_encode($smsStatusData); ?>;
    const smsColors = { 'Delivered': '#79c28f', 'Sent': '#7cc9bd', 'Pending': '#f6d776', 'Queued': '#f6d776', 'Failed': '#e8a0a8' };
    const smsLabels = Object.keys(smsData);
    new Chart(document.getElementById('redesignSmsChart'), {
        type: 'doughnut',
        data: {
            labels: smsLabels,
            datasets: [{
                data: Object.values(smsData).map(Number),
                backgroundColor: smsLabels.map((label) => smsColors[label] || '#cbd5e1'),
                borderColor: '#ffffff',
                borderWidth: 2,
                borderRadius: 3,
                spacing: 2
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, cutout: '66%', plugins: { legend, tooltip: percentTooltip } },
        plugins: [centerTotal]
    });
});
</script>

<?php include 'footer.php'; ?>
