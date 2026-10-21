<?php
require_once 'auth.php';
require_once 'db.php';
ensure_admin();

$reportType = isset($_GET['report_type']) && is_string($_GET['report_type']) ? $_GET['report_type'] : '';
$from = isset($_GET['from']) && is_string($_GET['from']) ? $_GET['from'] : date('Y-m-01');
$to = isset($_GET['to']) && is_string($_GET['to']) ? $_GET['to'] : date('Y-m-d');
$message = '';
$preview = [];
$reportGenerated = false;
$statusCounts = [];
$dailyCounts = [];
$dateField = 'date_created';

if (isset($_GET['generate'])) {
    $validTypes = ['All Reports', 'Incoming', 'Outgoing', 'Borrowed Items', 'SMS Logs'];
    $fromDate = DateTime::createFromFormat('!Y-m-d', $from);
    $toDate = DateTime::createFromFormat('!Y-m-d', $to);
    if (!in_array($reportType, $validTypes, true)) {
        $message = show_alert('Please select a valid report type.', 'danger');
    } elseif (!$fromDate || $fromDate->format('Y-m-d') !== $from || !$toDate || $toDate->format('Y-m-d') !== $to || $from > $to) {
        $message = show_alert('Please select a valid date range.', 'danger');
    } else {
        // Use each record type's own timestamp while keeping the selected end date inclusive.
        if ($reportType === 'All Reports') {
            $dateField = 'report_date';
            $reportQueries = [
                ['Incoming Documents', "SELECT date_created AS report_date, 'Incoming Documents' AS report_category, subject AS report_record, CONCAT_WS(' | ', CONCAT('From: ', sender), IF(NULLIF(recipient, '') IS NULL, NULL, CONCAT('To: ', recipient))) AS report_details, status FROM documents WHERE type = 'Incoming' AND date_created >= ? AND date_created < DATE_ADD(?, INTERVAL 1 DAY)", 'ss'],
                ['Outgoing Documents', "SELECT date_created AS report_date, 'Outgoing Documents' AS report_category, subject AS report_record, CONCAT_WS(' | ', CONCAT('From: ', sender), IF(NULLIF(recipient, '') IS NULL, NULL, CONCAT('To: ', recipient))) AS report_details, status FROM documents WHERE type = 'Outgoing' AND date_created >= ? AND date_created < DATE_ADD(?, INTERVAL 1 DAY)", 'ss'],
                ['Borrowed Items', "SELECT date_borrowed AS report_date, 'Borrowed Items' AS report_category, item_name AS report_record, CONCAT('Borrower: ', borrower) AS report_details, CASE WHEN status = 'Returned' THEN 'Returned' ELSE 'Borrowed' END AS status FROM borrowed_items WHERE date_borrowed >= ? AND date_borrowed < DATE_ADD(?, INTERVAL 1 DAY)", 'ss'],
                ['SMS Logs', "SELECT date_sent AS report_date, 'SMS Logs' AS report_category, 'SMS notification' AS report_record, CONCAT('To: ', recipient, ' | ', message) AS report_details, status FROM sms_logs WHERE date_sent >= ? AND date_sent < DATE_ADD(?, INTERVAL 1 DAY)", 'ss'],
            ];
            foreach ($reportQueries as [$category, $sql, $types]) {
                $stmt = safe_query($conn, $sql, $types, [$from, $to]);
                if ($stmt === false) {
                    $message = show_alert('The report could not be loaded. Please try again.', 'danger');
                    break;
                }
                $result = mysqli_stmt_get_result($stmt);
                if ($result === false) {
                    error_log('Combined report results could not be read for ' . $category . '.');
                    mysqli_stmt_close($stmt);
                    $message = show_alert('The report could not be loaded. Please try again.', 'danger');
                    break;
                }
                while ($row = mysqli_fetch_assoc($result)) {
                    $preview[] = $row;
                }
                mysqli_stmt_close($stmt);
            }
            if ($message === '') {
                usort($preview, static function ($first, $second) {
                    return strcmp((string)$second['report_date'], (string)$first['report_date']);
                });
                $reportGenerated = true;
            } else {
                $preview = [];
            }
        } elseif ($reportType === 'Borrowed Items') {
            $dateField = 'date_borrowed';
            $stmt = safe_query($conn, "SELECT item_name, borrower, date_borrowed, CASE WHEN status = 'Returned' THEN 'Returned' ELSE 'Borrowed' END AS status FROM borrowed_items WHERE date_borrowed >= ? AND date_borrowed < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY date_borrowed DESC", 'ss', [$from, $to]);
        } elseif ($reportType === 'SMS Logs') {
            $dateField = 'date_sent';
            $stmt = safe_query($conn, "SELECT date_sent, recipient, message, status FROM sms_logs WHERE date_sent >= ? AND date_sent < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY date_sent DESC", 'ss', [$from, $to]);
        } else {
            $stmt = safe_query($conn, "SELECT date_created, sender, recipient, subject, status FROM documents WHERE type = ? AND date_created >= ? AND date_created < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY date_created DESC", 'sss', [$reportType, $from, $to]);
        }
        if ($reportType !== 'All Reports') {
            if ($stmt === false) {
                $message = show_alert('The report could not be loaded. Please try again.', 'danger');
            } else {
                $result = mysqli_stmt_get_result($stmt);
                if ($result === false) {
                    error_log('Report results could not be read.');
                    $message = show_alert('The report could not be loaded. Please try again.', 'danger');
                } else {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $preview[] = $row;
                    }
                    $reportGenerated = true;
                }
                mysqli_stmt_close($stmt);
            }
        }

        if ($reportGenerated && count($preview) === 0) {
            $message = '<div class="alert alert-info" role="status">' . sanitize('No records found for the selected period. Try widening the date range.') . '</div>';
        } elseif ($reportGenerated) {
            // Summaries are derived from the exact preview rows so cards and exports agree.
            foreach ($preview as $row) {
                $status = (string)($row['status'] ?? 'Unknown');
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
                $date = substr((string)($row[$dateField] ?? ''), 0, 10);
                if ($date !== '') {
                    $dailyCounts[$date] = ($dailyCounts[$date] ?? 0) + 1;
                }
            }
            arsort($statusCounts);
            ksort($dailyCounts);
        }
    }
}

function reportTitle(string $type): string
{
    if ($type === 'Incoming') return 'Incoming Documents Report';
    if ($type === 'Outgoing') return 'Outgoing Documents Report';
    if ($type === 'Borrowed Items') return 'Borrowed Items Report';
    if ($type === 'SMS Logs') return 'SMS Delivery Report';
    if ($type === 'All Reports') return 'All Reports';
    return 'Report Preview';
}
?>
<?php include 'header.php'; ?>
<style>
.report-page .report-stat {
    position: relative;
    overflow: hidden;
    border: 1px solid #e2ebe5;
    border-radius: 1rem;
    background: linear-gradient(145deg, #fff 0%, #f7fbf8 100%);
    box-shadow: 0 0.5rem 1.4rem rgba(6, 78, 59, 0.06);
    transition: transform 180ms ease, box-shadow 180ms ease;
}
.report-page .report-stat:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.8rem 1.8rem rgba(6, 78, 59, 0.1);
}
.report-page .report-stat .card-body {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 0.25rem 0.75rem;
    padding: 1.15rem 1.25rem;
}
.report-page .report-stat-label,
.report-page .report-stat-value,
.report-page .report-stat-note {
    grid-column: 1;
}
.report-page .report-stat-icon {
    display: grid;
    width: 3rem;
    height: 3rem;
    grid-column: 2;
    grid-row: 1 / span 3;
    place-items: center;
    border: 1px solid rgba(15, 81, 50, 0.1);
    border-radius: 0.9rem;
    background: #eaf5ee;
    color: #0f5132;
    font-size: 1.25rem;
}
.report-page .report-stat-label {
    color: #64748b;
    font-size: 0.75rem;
    font-weight: 750;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.report-page .report-stat-value {
    color: #123e2a;
    font-size: clamp(1.5rem, 2.4vw, 2rem);
    font-weight: 750;
    line-height: 1.2;
    overflow-wrap: anywhere;
}
.report-page .report-stat-note {
    color: #64748b;
    font-size: 0.8rem;
}
.report-page .report-chart > .card {
    height: 100%;
    border: 1px solid #e2ebe5;
    border-radius: 1rem;
    box-shadow: 0 0.5rem 1.4rem rgba(6, 78, 59, 0.05);
}
.report-page .report-chart > .card .card-body {
    padding: clamp(1rem, 2vw, 1.35rem);
}
.report-page .report-table--all th:first-child,
.report-page .report-table--all td:first-child,
.report-page .report-table--all th:last-child,
.report-page .report-table--all td:last-child {
    text-align: center !important;
}
.report-page .report-table--all td:nth-child(2) {
    font-weight: 650;
}
.report-page .report-chart-wrap {
    height: 250px;
}
.report-page .report-table th {
    white-space: nowrap;
}
.report-page .report-table--all {
    width: 100%;
    table-layout: fixed;
}
/* Fixed columns keep longer on-screen labels inside their intended cells. */
.report-page .report-table--all col.report-col-date {
    width: 16%;
}
.report-page .report-table--all col.report-col-category {
    width: 21%;
}
.report-page .report-table--all col.report-col-subject {
    width: 19%;
}
.report-page .report-table--all col.report-col-details {
    width: 32%;
}
.report-page .report-table--all col.report-col-status {
    width: 12%;
}
.report-page .report-print-header {
    display: none;
}
.report-page .report-print-note {
    display: none;
}
@media print {
    html,
    body.admin-body {
        width: 100% !important;
        height: auto !important;
        margin: 0 !important;
        padding: 0 !important;
        color: #000 !important;
        background: #fff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    body.admin-body,
    .admin-layout,
    .main-panel,
    .admin-content {
        height: auto !important;
        min-height: 0 !important;
        overflow: visible !important;
    }
    .admin-layout,
    .main-panel {
        display: block !important;
        width: 100% !important;
    }
    .sidebar,
    .topbar,
    .admin-footer,
    .admin-assistant,
    .admin-login-transition,
    .report-controls,
    .report-actions,
    .report-filter,
    .report-summary,
    .pending-documents-float {
        display: none !important;
    }
    .admin-content {
        overflow: visible !important;
        width: 100% !important;
        flex: none !important;
        padding: 0.38in 0.42in 0.44in !important;
        background: #fff !important;
    }
    .report-page {
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        --bs-gutter-x: 0;
        --bs-gutter-y: 0;
    }
    .report-page > .col-12 {
        padding: 0 !important;
    }
    .report-page .report-filter-card {
        display: none !important;
    }
    .report-page .report-table-wrap {
        overflow: visible !important;
    }
    .report-page > .col-12:last-child,
    .report-page > .col-12:last-child > .card,
    .report-page > .col-12:last-child > .card > .card-body {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
    }
    .report-page .report-document-card,
    .report-page .report-document-card > .card-body,
    .report-page .report-table-wrap {
        color: #000 !important;
        background: #fff !important;
    }
    .report-page .report-screen-heading,
    .report-page #reportResultCount {
        display: none !important;
    }
    .report-page .report-table {
        display: table !important;
        width: 100% !important;
        min-width: 0 !important;
        margin: 0 !important;
        border-collapse: collapse !important;
        table-layout: fixed !important;
        font-size: 8pt;
    }
    .report-page .report-table col.report-col-date {
        width: 18%;
    }
    .report-page .report-table--documents col.report-col-party {
        width: 15%;
    }
    .report-page .report-table--documents col.report-col-subject {
        width: 38%;
    }
    .report-page .report-table--documents col.report-col-status {
        width: 14%;
    }
    /* Print widths must wrap long labels instead of shifting adjacent columns. */
    .report-page .report-table--all col.report-col-date {
        width: 16%;
    }
    .report-page .report-table--all col.report-col-category {
        width: 21%;
    }
    .report-page .report-table--all col.report-col-subject {
        width: 19%;
    }
    .report-page .report-table--all col.report-col-details {
        width: 32%;
    }
    .report-page .report-table--all col.report-col-status {
        width: 12%;
    }
    .report-page .report-table--sms col.report-col-recipient {
        width: 23%;
    }
    .report-page .report-table--sms col.report-col-message {
        width: 43%;
    }
    .report-page .report-table--sms col.report-col-status {
        width: 16%;
    }
    .report-page .report-table--borrowed col.report-col-item {
        width: 29%;
    }
    .report-page .report-table--borrowed col.report-col-borrower {
        width: 25%;
    }
    .report-page .report-table--borrowed col.report-col-date {
        width: 22%;
    }
    .report-page .report-table--borrowed col.report-col-status {
        width: 24%;
    }
    .report-page .report-print-header {
        display: block !important;
        margin: 0 0 0.12in;
        padding: 0 0 0.08in;
        border-bottom: 2px solid #14633d;
    }
    .report-page .report-letterhead {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 0.65in 0.05in max-content 0.05in 0.65in minmax(0, 1fr);
        align-items: center;
        width: 100%;
        max-width: 100%;
        min-height: 0.78in;
        margin: 0 auto;
        text-align: center;
    }
    .report-page .report-letterhead-mark {
        grid-column: 2;
        width: 0.65in;
        height: 0.65in;
        object-fit: contain;
    }
    .report-page .report-letterhead-copy {
        grid-column: 4;
        text-align: center;
    }
    .report-page .report-letterhead::after {
        content: "";
        grid-column: 6;
    }
    .report-page .report-letterhead-copy p {
        margin: 0;
        color: #000 !important;
        font: 9pt/1.2 Arial, sans-serif;
    }
    .report-page .report-letterhead-copy .report-university-name {
        margin: 0.01in 0;
        color: #000 !important;
        font-size: 14pt;
        font-weight: 700;
        letter-spacing: 0.025em;
    }
    .report-page .report-letterhead-copy .report-college-name {
        position: relative;
        top: 0.08in;
        margin-top: 0.04in;
        font-size: 9pt;
        font-weight: 700;
        letter-spacing: 0.01em;
    }
    .report-page .report-print-title {
        margin: 0.11in 0 0.07in;
        color: #000 !important;
        font: 700 15pt/1.1 Arial, sans-serif;
        text-align: center;
    }
    .report-page .report-print-meta {
        display: grid;
        grid-template-columns: minmax(0, 1.8fr) minmax(1in, 1fr);
        gap: 0.12in;
        margin: 0;
        padding: 0.08in 0.1in;
        border: 1px solid #b7c2bb;
        background: #fff !important;
    }
    .report-page .report-print-meta div {
        min-width: 0;
    }
    .report-page .report-print-meta dt {
        margin: 0 0 0.025in;
        color: #000 !important;
        font: 700 7.5pt/1.2 Arial, sans-serif;
        letter-spacing: 0.07em;
        text-transform: uppercase;
    }
    .report-page .report-print-meta dd {
        margin: 0;
        color: #000 !important;
        font: 9.5pt/1.25 Arial, sans-serif;
        font-weight: 600;
    }
    .report-page .report-table td {
        font-size: 9pt;
        line-height: 1.3;
    }
    .report-page .report-table--all td:nth-child(2) {
        font-size: 8pt;
        font-weight: 600;
    }
    .report-page .report-table--all td:nth-child(4) {
        font-size: 8pt;
        line-height: 1.25;
    }
    .report-page .report-table--all tbody tr {
        break-inside: avoid;
        page-break-inside: avoid;
    }
    /* Clamp only the print presentation; the full text stays available to screen and workbook exports. */
    .report-page .report-table--all .report-print-cell {
        display: -webkit-box !important;
        overflow: hidden;
        overflow-wrap: anywhere;
        max-height: 5em;
        line-height: 1.25;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 4;
    }
    .report-page .report-print-note {
        display: block !important;
        margin: 0.06in 0 0;
        color: #4b5563 !important;
        font: 7pt/1.2 Arial, sans-serif;
        text-align: right;
    }
    .report-page .report-table .report-date-cell {
        text-align: center !important;
        white-space: nowrap;
    }
    .report-page .report-date-part {
        display: block;
    }
    .report-page .report-table thead {
        display: table-header-group !important;
    }
    .report-page .report-table tbody {
        display: table-row-group !important;
    }
    .report-page .report-table tr {
        display: table-row !important;
        overflow: visible !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
    }
    .report-page .report-table th,
    .report-page .report-table td {
        display: table-cell !important;
        min-width: 0 !important;
        padding: 0.075in 0.08in !important;
        border: 1px solid #aab9b0 !important;
        color: #000 !important;
        background: #fff !important;
        vertical-align: middle !important;
        text-align: left !important;
        overflow-wrap: anywhere;
        word-break: normal;
        grid-template-columns: none !important;
        gap: 0 !important;
    }
    .report-page .report-table td::before,
    .report-page .report-table th::before {
        display: none !important;
        content: none !important;
    }
    .report-page .report-table th {
        color: #000 !important;
        background: #f0f0f0 !important;
        font-size: 7pt !important;
        font-weight: 700;
        letter-spacing: 0.015em;
        text-transform: uppercase;
        white-space: normal !important;
        overflow-wrap: anywhere;
        line-height: 1.15;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .report-page .report-table--all th:first-child,
    .report-page .report-table--all th:nth-child(2),
    .report-page .report-table--all th:nth-child(3),
    .report-page .report-table--all th:nth-child(4),
    .report-page .report-table--all th:last-child {
        text-align: center !important;
    }
    .report-page .report-table--all td:nth-child(2) .badge {
        display: block !important;
        max-width: 100%;
        margin: 0 !important;
        padding: 0 !important;
        overflow-wrap: anywhere;
        text-align: left;
        white-space: normal !important;
    }
    .report-page .report-table--all td:last-child .badge {
        display: inline-block !important;
        max-width: 100%;
        overflow-wrap: anywhere;
        white-space: normal !important;
    }
    .report-page .report-table--sms td:nth-child(2) {
        white-space: nowrap;
    }
    .report-page .report-table--documents th:first-child,
    .report-page .report-table--documents td:first-child,
    .report-page .report-table--documents th:last-child,
    .report-page .report-table--documents td:last-child,
    .report-page .report-table--sms th:first-child,
    .report-page .report-table--sms td:first-child,
    .report-page .report-table--sms th:last-child,
    .report-page .report-table--sms td:last-child,
    .report-page .report-table--borrowed th:nth-child(3),
    .report-page .report-table--borrowed td:nth-child(3),
    .report-page .report-table--borrowed th:last-child,
    .report-page .report-table--borrowed td:last-child {
        text-align: center !important;
    }
    .report-page .report-table--all th:first-child,
    .report-page .report-table--all td:first-child,
    .report-page .report-table--all th:last-child,
    .report-page .report-table--all td:last-child {
        text-align: center !important;
    }
    .report-page .report-table--sms th:nth-child(2),
    .report-page .report-table--sms td:nth-child(2) {
        text-align: center !important;
    }
    .report-page .report-table tbody tr {
        break-inside: avoid;
    }
    .report-page .report-table tr[hidden],
    .report-page #reportNoMatches {
        display: none !important;
    }
    .report-page .badge {
        border: 0 !important;
        color: #000 !important;
        background: transparent !important;
        padding: 0 !important;
        font-size: inherit;
    }
    .report-page .report-print-footer {
        display: flex !important;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.2in;
        margin-top: 0.1in;
        padding-top: 0.05in;
        border-top: 1px solid #aab9b0;
        color: #000 !important;
        font: 7.5pt/1.3 Arial, sans-serif;
    }
    .report-page .report-print-footer-meta {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        text-align: right;
    }
    .report-page .report-message {
        overflow-wrap: anywhere;
    }
    .report-page .report-table .badge {
        white-space: normal;
    }
    @page {
        size: A4 portrait;
        margin: 0;
    }
}
</style>
<div class="row g-4 report-page">
    <div class="col-12">
        <div class="card shadow-sm report-filter-card">
            <div class="card-body">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-3">
                    <div>
                        <h2 class="mb-1 text-primary">Reports</h2>
                        <p class="text-muted mb-0">Review activity, narrow results, and export a clean report.</p>
                    </div>
                </div>
                <?php if ($message) echo $message; ?>
                <form method="GET" id="reportForm" class="row g-3 align-items-end report-controls">
                    <?php if (($_GET['embed'] ?? '') === '1'): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <div class="col-md-4">
                        <label class="form-label" for="report_type">Report type</label>
                        <select id="report_type" name="report_type" class="form-select" required>
                            <option value="All Reports" <?php echo $reportType === 'All Reports' ? 'selected' : ''; ?>>All Reports</option>
                            <option value="Incoming" <?php echo $reportType === 'Incoming' ? 'selected' : ''; ?>>Incoming Documents</option>
                            <option value="Outgoing" <?php echo $reportType === 'Outgoing' ? 'selected' : ''; ?>>Outgoing Documents</option>
                            <option value="Borrowed Items" <?php echo $reportType === 'Borrowed Items' ? 'selected' : ''; ?>>Borrowed Items</option>
                            <option value="SMS Logs" <?php echo $reportType === 'SMS Logs' ? 'selected' : ''; ?>>SMS Logs</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="from">From</label>
                        <input type="date" id="from" name="from" class="form-control" max="<?php echo sanitize($to); ?>" value="<?php echo sanitize($from); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="to">To</label>
                        <input type="date" id="to" name="to" class="form-control" min="<?php echo sanitize($from); ?>" value="<?php echo sanitize($to); ?>">
                    </div>
                    <div class="col-md-2 d-grid">
                        <button type="submit" name="generate" value="1" class="btn btn-primary"><i class="bi bi-bar-chart-line me-1" aria-hidden="true"></i>Generate report</button>
                    </div>
                    <div class="col-12 d-flex flex-wrap align-items-center gap-2 pt-1">
                        <span class="small text-muted me-1">Quick range:</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary date-preset" data-range="today">Today</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary date-preset" data-range="week">This week</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary date-preset" data-range="month">This month</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary date-preset" data-range="year">This year</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php if ($reportGenerated && count($preview) > 0): ?>
    <div class="col-12 report-summary">
        <div class="row g-3">
            <div class="col-sm-6 col-xl-4">
                <div class="card shadow-sm report-stat h-100"><div class="card-body">
                    <div class="report-stat-label">Total records</div>
                    <div class="report-stat-value"><?php echo number_format(count($preview)); ?></div>
                    <div class="report-stat-note">Across <?php echo $reportType === 'All Reports' ? 'all report categories' : sanitize($reportType); ?> in the selected period</div>
                    <span class="report-stat-icon" aria-hidden="true"><i class="bi bi-files"></i></span>
                </div></div>
            </div>
            <div class="col-sm-6 col-xl-4">
                <div class="card shadow-sm report-stat h-100"><div class="card-body">
                    <div class="report-stat-label">Most common status</div>
                    <div class="report-stat-value fs-3"><?php echo sanitize((string)array_key_first($statusCounts)); ?></div>
                    <div class="report-stat-note"><?php echo number_format((int)reset($statusCounts)); ?> record(s) · most common status</div>
                    <span class="report-stat-icon" aria-hidden="true"><i class="bi bi-activity"></i></span>
                </div></div>
            </div>
            <div class="col-sm-6 col-xl-4">
                <div class="card shadow-sm report-stat h-100"><div class="card-body">
                    <div class="report-stat-label">Active days</div>
                    <div class="report-stat-value"><?php echo number_format(count($dailyCounts)); ?></div>
                    <div class="report-stat-note">Days with recorded activity</div>
                    <span class="report-stat-icon" aria-hidden="true"><i class="bi bi-calendar2-week"></i></span>
                </div></div>
            </div>
            <div class="col-lg-7 report-chart">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-primary mb-3">Daily activity</h3>
                        <div class="report-chart-wrap"><canvas id="reportActivityChart" role="img" aria-label="Daily record counts for the selected date range"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5 report-chart">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-primary mb-3">Status breakdown</h3>
                        <?php foreach ($statusCounts as $status => $count): ?>
                            <?php $statusPercent = (int)round(($count / count($preview)) * 100); ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between gap-3 small mb-1">
                                    <span><?php echo sanitize($status); ?></span>
                                    <span class="text-muted"><?php echo number_format($count); ?> · <?php echo $statusPercent; ?>%</span>
                                </div>
                                <div class="progress" role="progressbar" aria-label="<?php echo sanitize($status . ' status'); ?>" aria-valuenow="<?php echo $statusPercent; ?>" aria-valuemin="0" aria-valuemax="100" style="height: .45rem">
                                    <div class="progress-bar bg-success" style="width: <?php echo $statusPercent; ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-12">
        <div class="card shadow-sm report-document-card">
            <div class="card-body">
                <?php if ($reportGenerated): ?>
                <header class="report-print-header">
                    <div class="report-letterhead">
                        <img class="report-letterhead-mark" src="mobile-ui/src/office-logo.png" alt="Cavite State University seal">
                        <div class="report-letterhead-copy">
                            <p>Republic of the Philippines</p>
                            <p class="report-university-name">CAVITE STATE UNIVERSITY</p>
                            <p>Don Severino de las Alas Campus, Indang, Cavite</p>
                            <p class="report-college-name">COLLEGE OF ENGINEERING AND INFORMATION TECHNOLOGY</p>
                        </div>
                    </div>
                    <h1 class="report-print-title"><?php echo sanitize(reportTitle($reportType)); ?></h1>
                    <dl class="report-print-meta">
                        <div>
                            <dt>Reporting period</dt>
                            <dd><?php echo sanitize(date('M j, Y', strtotime($from))); ?> – <?php echo sanitize(date('M j, Y', strtotime($to))); ?></dd>
                        </div>
                        <div>
                            <dt>Record count</dt>
                            <dd id="reportPrintRecordCount"><?php echo number_format(count($preview)); ?></dd>
                        </div>
                    </dl>
                    <?php if ($reportType === 'All Reports'): ?>
                        <p class="report-print-note">Long record details are shortened for print. Export to Excel for complete details.</p>
                    <?php endif; ?>
                </header>
                <?php endif; ?>
                <div class="report-screen-heading d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-3">
                    <div>
                        <h2 class="h5 card-title mb-1 text-primary"><?php echo sanitize(reportTitle($reportType)); ?></h2>
                        <p class="text-muted mb-0"><?php echo sanitize($from); ?> to <?php echo sanitize($to); ?><?php if ($reportGenerated): ?> <span class="ms-1">· <?php echo number_format(count($preview)); ?> record(s)</span><?php endif; ?></p>
                    </div>
                    <div class="d-flex flex-wrap gap-2 report-actions">
                        <?php if ($reportGenerated && count($preview) > 0): ?>
                            <button type="button" class="btn btn-outline-secondary" onclick="printReport()"><i class="bi bi-printer me-1" aria-hidden="true"></i>Print / Save as PDF</button>
                            <button type="button" class="btn btn-primary" id="exportExcel" title="Download a formatted Excel workbook with readable column widths"><i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Export Excel</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($reportGenerated && count($preview) > 0): ?>
                <div class="row g-2 mb-3 report-filter">
                    <div class="col-md-7">
                        <label class="visually-hidden" for="reportSearch">Search report</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                            <input type="search" class="form-control" id="reportSearch" placeholder="Search within this report..." autocomplete="off">
                        </div>
                    </div>
                    <div class="col-md-5">
                        <label class="visually-hidden" for="statusFilter">Filter by status</label>
                        <select class="form-select" id="statusFilter">
                            <option value="">All statuses</option>
                            <?php foreach (array_keys($statusCounts) as $status): ?>
                                <option value="<?php echo sanitize($status); ?>"><?php echo sanitize($status); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php endif; ?>
                <div class="table-responsive report-table-wrap">
                    <table id="reportTable" class="table table-hover align-middle mb-0 report-table <?php echo $reportType === 'All Reports' ? 'report-table--all' : ($reportType === 'SMS Logs' ? 'report-table--sms' : ($reportType === 'Borrowed Items' ? 'report-table--borrowed' : 'report-table--documents')); ?>">
                        <?php if ($reportType === 'Borrowed Items'): ?>
                            <colgroup>
                                <col class="report-col-item">
                                <col class="report-col-borrower">
                                <col class="report-col-date">
                                <col class="report-col-status">
                            </colgroup>
                        <?php elseif ($reportType === 'SMS Logs'): ?>
                            <colgroup>
                                <col class="report-col-date">
                                <col class="report-col-recipient">
                                <col class="report-col-message">
                                <col class="report-col-status">
                            </colgroup>
                        <?php elseif ($reportType === 'All Reports'): ?>
                            <colgroup>
                                <col class="report-col-date">
                                <col class="report-col-category">
                                <col class="report-col-subject">
                                <col class="report-col-details">
                                <col class="report-col-status">
                            </colgroup>
                        <?php else: ?>
                            <colgroup>
                                <col class="report-col-date">
                                <col class="report-col-party">
                                <col class="report-col-party">
                                <col class="report-col-subject">
                                <col class="report-col-status">
                            </colgroup>
                        <?php endif; ?>
                        <thead class="table-light">
                            <tr>
                                <?php if ($reportType === 'Borrowed Items'): ?>
                                    <th>Item</th>
                                    <th>Borrower</th>
                                    <th>Date borrowed</th>
                                    <th>Status</th>
                                <?php elseif ($reportType === 'SMS Logs'): ?>
                                    <th>Date and time</th>
                                    <th>Recipient</th>
                                    <th>Message</th>
                                    <th>Status</th>
                                <?php elseif ($reportType === 'All Reports'): ?>
                                    <th>Date and time</th>
                                    <th>Report type</th>
                                    <th>Record</th>
                                    <th>Details</th>
                                    <th>Status</th>
                                <?php else: ?>
                                    <th>Date and time</th>
                                    <th>Sender</th>
                                    <th>Recipient</th>
                                    <th>Subject</th>
                                    <th>Status</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$reportGenerated): ?>
                                <tr><td colspan="<?php echo in_array($reportType, ['SMS Logs', 'Borrowed Items'], true) ? '4' : '5'; ?>" class="text-center text-muted py-5"><i class="bi bi-bar-chart-line fs-3 d-block mb-2" aria-hidden="true"></i>Select a report type and date range, then generate your report.</td></tr>
                            <?php elseif (count($preview) === 0): ?>
                                <tr><td colspan="<?php echo in_array($reportType, ['SMS Logs', 'Borrowed Items'], true) ? '4' : '5'; ?>" class="text-center text-muted py-5">No records found for this selection.</td></tr>
                            <?php else: ?>
                                <?php foreach ($preview as $row): ?>
                                    <tr data-status="<?php echo sanitize((string)($row['status'] ?? 'Unknown')); ?>">
                                        <?php if ($reportType === 'Borrowed Items'): ?>
                                            <td><?php echo sanitize((string)$row['item_name']); ?></td>
                                            <td><?php echo sanitize((string)$row['borrower']); ?></td>
                                            <td data-csv-value="<?php echo sanitize((string)$row['date_borrowed']); ?>"><?php echo sanitize(date('M j, Y', strtotime((string)$row['date_borrowed']))); ?></td>
                                            <td><span class="badge <?php echo admin_status_badge_class($row['status']); ?>"><?php echo sanitize((string)$row['status']); ?></span></td>
                                        <?php elseif ($reportType === 'SMS Logs'): ?>
                                            <td class="report-date-cell" data-csv-value="<?php echo sanitize((string)$row['date_sent']); ?>">
                                                <span class="report-date-part"><?php echo sanitize(date('M j, Y', strtotime((string)$row['date_sent']))); ?></span>
                                                <span class="report-date-part"><?php echo sanitize(date('g:i A', strtotime((string)$row['date_sent']))); ?></span>
                                            </td>
                                            <td><?php echo sanitize((string)$row['recipient']); ?></td>
                                            <td class="report-message"><?php echo sanitize((string)$row['message']); ?></td>
                                            <td><span class="badge <?php echo admin_status_badge_class($row['status']); ?>"><?php echo sanitize((string)$row['status']); ?></span></td>
                                        <?php elseif ($reportType === 'All Reports'): ?>
                                            <td class="report-date-cell" data-csv-value="<?php echo sanitize((string)$row['report_date']); ?>">
                                                <span class="report-date-part"><?php echo sanitize(date('M j, Y', strtotime((string)$row['report_date']))); ?></span>
                                                <span class="report-date-part"><?php echo sanitize(date('g:i A', strtotime((string)$row['report_date']))); ?></span>
                                            </td>
                                            <td><span class="badge rounded-pill text-bg-light border"><?php echo sanitize((string)$row['report_category']); ?></span></td>
                                            <td class="report-message"><span class="report-print-cell" title="<?php echo sanitize((string)$row['report_record']); ?>"><?php echo sanitize((string)$row['report_record']); ?></span></td>
                                            <td class="report-message"><span class="report-print-cell" title="<?php echo sanitize((string)$row['report_details']); ?>"><?php echo sanitize((string)$row['report_details']); ?></span></td>
                                            <td><span class="badge <?php echo admin_status_badge_class($row['status']); ?>"><?php echo sanitize((string)$row['status']); ?></span></td>
                                        <?php else: ?>
                                            <td class="report-date-cell" data-csv-value="<?php echo sanitize((string)$row['date_created']); ?>">
                                                <span class="report-date-part"><?php echo sanitize(date('M j, Y', strtotime((string)$row['date_created']))); ?></span>
                                                <span class="report-date-part"><?php echo sanitize(date('g:i A', strtotime((string)$row['date_created']))); ?></span>
                                            </td>
                                            <td><?php echo sanitize((string)$row['sender']); ?></td>
                                            <td><?php echo sanitize((string)$row['recipient']); ?></td>
                                            <td><?php echo sanitize((string)$row['subject']); ?></td>
                                            <td><span class="badge <?php echo admin_status_badge_class($row['status']); ?>"><?php echo sanitize((string)$row['status']); ?></span></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($reportGenerated && count($preview) > 0): ?>
                                <tr id="reportNoMatches" hidden><td colspan="<?php echo in_array($reportType, ['SMS Logs', 'Borrowed Items'], true) ? '4' : '5'; ?>" class="text-center text-muted py-4">No records match your search and status filters.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($reportGenerated && count($preview) > 0): ?>
                    <div class="small text-muted mt-3" id="reportResultCount" aria-live="polite"></div>
                <?php endif; ?>
                <?php if ($reportGenerated && count($preview) > 0): ?>
                    <footer class="report-print-footer">
                        <span>CEIT Dean’s Office Digital Logbook</span>
                        <span class="report-print-footer-meta">
                            <span>Generated <?php echo sanitize(date('F j, Y · g:i A')); ?></span>
                            <span>Prepared by: <?php echo sanitize((string)($_SESSION['admin'] ?? 'Administrator')); ?></span>
                        </span>
                    </footer>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/report_excel_export.js"></script>
<script>
<?php if ($reportGenerated && count($preview) > 0): ?>
const reportChartLabels = <?php echo json_encode(array_keys($dailyCounts), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const reportChartValues = <?php echo json_encode(array_values($dailyCounts)); ?>;
if (typeof Chart !== 'undefined') {
    new Chart(document.getElementById('reportActivityChart'), {
        type: 'bar',
        data: {
            labels: reportChartLabels,
            datasets: [{
                label: 'Records',
                data: reportChartValues,
                backgroundColor: 'rgba(15, 81, 50, 0.72)',
                borderColor: '#0f5132',
                borderWidth: 1,
                borderRadius: 5,
                maxBarThickness: 36
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(15, 81, 50, 0.08)' } }
            }
        }
    });
}

const reportTable = document.getElementById('reportTable');
const reportRows = Array.from(reportTable.querySelectorAll('tbody tr[data-status]'));
const reportSearch = document.getElementById('reportSearch');
const statusFilter = document.getElementById('statusFilter');
const reportNoMatches = document.getElementById('reportNoMatches');
const reportResultCount = document.getElementById('reportResultCount');
const reportPrintRecordCount = document.getElementById('reportPrintRecordCount');
const reportExportTitle = <?php echo json_encode(reportTitle($reportType), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const reportExportRange = <?php echo json_encode($from . ' to ' . $to, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const reportExportGeneratedAt = <?php echo json_encode(date('Y-m-d H:i:s'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const originalDocumentTitle = document.title;
const reportPdfFilename = reportExportTitle + ' - ' + reportExportRange;
let reportPrintTitleApplied = false;

function applyReportPrintTitle() {
    if (reportPrintTitleApplied) return;
    document.title = reportPdfFilename;
    reportPrintTitleApplied = true;
}

function restoreReportPrintTitle() {
    if (!reportPrintTitleApplied) return;
    document.title = originalDocumentTitle;
    reportPrintTitleApplied = false;
}

function printReport() {
    applyReportPrintTitle();
    window.print();
}

window.addEventListener('beforeprint', applyReportPrintTitle);
window.addEventListener('afterprint', restoreReportPrintTitle);

function filterReportRows() {
    const query = reportSearch.value.trim().toLocaleLowerCase();
    const selectedStatus = statusFilter.value;
    let visibleCount = 0;
    reportRows.forEach(function (row) {
        const matchesQuery = row.innerText.toLocaleLowerCase().includes(query);
        const matchesStatus = selectedStatus === '' || row.dataset.status === selectedStatus;
        const visible = matchesQuery && matchesStatus;
        row.hidden = !visible;
        if (visible) visibleCount++;
    });
    reportNoMatches.hidden = visibleCount > 0;
    reportResultCount.textContent = 'Showing ' + visibleCount.toLocaleString() + ' of ' + reportRows.length.toLocaleString() + ' records';
    reportPrintRecordCount.textContent = visibleCount.toLocaleString();
}
reportSearch.addEventListener('input', filterReportRows);
statusFilter.addEventListener('change', filterReportRows);
filterReportRows();

document.getElementById('exportExcel').addEventListener('click', function () {
    const table = document.getElementById('reportTable');
    const visibleRows = Array.from(table.querySelectorAll('tbody tr[data-status]')).filter(function (row) {
        return !row.hidden;
    });
    const headers = Array.from(table.querySelectorAll('thead th')).map(function (cell) {
        return cell.innerText.replace(/\r?\n/g, ' ').trim();
    });
    const rows = visibleRows.map(function (row) {
        return Array.from(row.querySelectorAll('td')).map(function (cell) {
            return (cell.dataset.csvValue || cell.innerText).replace(/\r?\n/g, ' ').trim();
        });
    });
    const columnWidths = <?php echo json_encode(
        $reportType === 'All Reports' ? [23, 27, 38, 65, 18] : ($reportType === 'SMS Logs' ? [23, 20, 70, 18] : ($reportType === 'Borrowed Items' ? [34, 28, 20, 18] : [23, 24, 24, 48, 18])),
        JSON_THROW_ON_ERROR
    ); ?>;

    window.downloadReportWorkbook({
        title: reportExportTitle,
        period: reportExportRange,
        generatedAt: reportExportGeneratedAt,
        recordCount: visibleRows.length,
        headers: headers,
        rows: rows,
        columnWidths: columnWidths,
        filename: reportExportTitle + ' - ' + reportExportRange + '.xlsx'
    });
});
<?php endif; ?>

document.querySelectorAll('.date-preset').forEach(function (button) {
    button.addEventListener('click', function () {
        const today = new Date();
        const toIsoDate = function (date) {
            return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
        };
        const start = new Date(today);
        if (button.dataset.range === 'week') {
            const daysSinceMonday = (today.getDay() + 6) % 7;
            start.setDate(today.getDate() - daysSinceMonday);
        } else if (button.dataset.range === 'month') {
            start.setDate(1);
        } else if (button.dataset.range === 'year') {
            start.setMonth(0, 1);
        }
        document.getElementById('from').value = toIsoDate(start);
        document.getElementById('to').value = toIsoDate(today);
        if (document.getElementById('report_type').value) {
            const form = document.getElementById('reportForm');
            const generate = document.createElement('input');
            generate.type = 'hidden';
            generate.name = 'generate';
            generate.value = '1';
            form.appendChild(generate);
            form.requestSubmit();
        }
    });
});
</script>
<?php include 'footer.php'; ?>