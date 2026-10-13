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
    $validTypes = ['Incoming', 'Outgoing', 'Borrowed Items', 'SMS Logs'];
    $fromDate = DateTime::createFromFormat('!Y-m-d', $from);
    $toDate = DateTime::createFromFormat('!Y-m-d', $to);
    if (!in_array($reportType, $validTypes, true)) {
        $message = show_alert('Please select a valid report type.', 'danger');
    } elseif (!$fromDate || $fromDate->format('Y-m-d') !== $from || !$toDate || $toDate->format('Y-m-d') !== $to || $from > $to) {
        $message = show_alert('Please select a valid date range.', 'danger');
    } else {
        // Use each record type's own timestamp while keeping the selected end date inclusive.
        if ($reportType === 'Borrowed Items') {
            $dateField = 'date_borrowed';
            $stmt = safe_query($conn, "SELECT item_name, borrower, date_borrowed, CASE WHEN status = 'Returned' THEN 'Returned' ELSE 'Borrowed' END AS status FROM borrowed_items WHERE date_borrowed >= ? AND date_borrowed < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY date_borrowed DESC", 'ss', [$from, $to]);
        } elseif ($reportType === 'SMS Logs') {
            $dateField = 'date_sent';
            $stmt = safe_query($conn, "SELECT date_sent, recipient, message, status FROM sms_logs WHERE date_sent >= ? AND date_sent < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY date_sent DESC", 'ss', [$from, $to]);
        } else {
            $stmt = safe_query($conn, "SELECT date_created, sender, recipient, subject, status FROM documents WHERE type = ? AND date_created >= ? AND date_created < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY date_created DESC", 'sss', [$reportType, $from, $to]);
        }
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
    return 'Report Preview';
}
?>
<?php include 'header.php'; ?>
<style>
.report-page .report-stat {
    border: 0;
    border-left: 4px solid var(--brand-green);
}
.report-page .report-stat-label {
    color: #64748b;
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.report-page .report-chart-wrap {
    height: 250px;
}
.report-page .report-table th {
    white-space: nowrap;
}
@media print {
    body.admin-body,
    .admin-layout,
    .main-panel {
        height: auto !important;
        min-height: 0 !important;
        overflow: visible !important;
    }
    .sidebar,
    .topbar,
    .admin-footer,
    .report-controls,
    .report-actions,
    .report-filter,
    .pending-documents-float {
        display: none !important;
    }
    .admin-content {
        overflow: visible !important;
        padding: 0 !important;
    }
    .report-page > .col-12:not(:last-child) .card {
        box-shadow: none !important;
        break-inside: avoid;
    }
    .report-page .report-filter-card {
        display: none !important;
    }
    .report-page .report-table-wrap {
        overflow: visible !important;
    }
    .report-page .report-table {
        font-size: 9pt;
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
                            <option value="">Select Report Type</option>
                            <option value="Incoming" <?php echo $reportType === 'Incoming' ? 'selected' : ''; ?>>Incoming Documents</option>
                            <option value="Outgoing" <?php echo $reportType === 'Outgoing' ? 'selected' : ''; ?>>Outgoing Documents</option>
                            <option value="Borrowed Items" <?php echo $reportType === 'Borrowed Items' ? 'selected' : ''; ?>>Borrowed Items</option>
                            <option value="SMS Logs" <?php echo $reportType === 'SMS Logs' ? 'selected' : ''; ?>>SMS Logs</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="from">From</label>
                        <input type="date" id="from" name="from" class="form-control" value="<?php echo sanitize($from); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="to">To</label>
                        <input type="date" id="to" name="to" class="form-control" value="<?php echo sanitize($to); ?>">
                    </div>
                    <div class="col-md-2 d-grid">
                        <button type="submit" name="generate" value="1" class="btn btn-primary"><i class="bi bi-bar-chart-line me-1" aria-hidden="true"></i>Generate report</button>
                    </div>
                    <div class="col-12 d-flex flex-wrap align-items-center gap-2 pt-1">
                        <span class="small text-muted me-1">Quick range:</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary date-preset" data-range="today">Today</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary date-preset" data-range="week">This week</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary date-preset" data-range="month">This month</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary date-preset" data-range="30days">Last 30 days</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php if ($reportGenerated && count($preview) > 0): ?>
    <div class="col-12">
        <div class="row g-3">
            <div class="col-sm-6 col-xl-4">
                <div class="card shadow-sm report-stat h-100"><div class="card-body">
                    <div class="report-stat-label">Total records</div>
                    <div class="display-6 fw-semibold text-primary"><?php echo number_format(count($preview)); ?></div>
                    <div class="small text-muted">Matching the selected date range</div>
                </div></div>
            </div>
            <div class="col-sm-6 col-xl-4">
                <div class="card shadow-sm report-stat h-100"><div class="card-body">
                    <div class="report-stat-label">Most common status</div>
                    <div class="fs-3 fw-semibold text-primary"><?php echo sanitize((string)array_key_first($statusCounts)); ?></div>
                    <div class="small text-muted"><?php echo number_format((int)reset($statusCounts)); ?> record(s)</div>
                </div></div>
            </div>
            <div class="col-sm-6 col-xl-4">
                <div class="card shadow-sm report-stat h-100"><div class="card-body">
                    <div class="report-stat-label">Status types</div>
                    <div class="display-6 fw-semibold text-primary"><?php echo number_format(count($statusCounts)); ?></div>
                    <div class="small text-muted">Distinct statuses in this report</div>
                </div></div>
            </div>
            <div class="col-lg-7">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="h6 text-primary mb-3">Daily activity</h3>
                        <div class="report-chart-wrap"><canvas id="reportActivityChart" role="img" aria-label="Daily record counts for the selected date range"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
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
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-3">
                    <div>
                        <h2 class="h5 card-title mb-1 text-primary"><?php echo sanitize(reportTitle($reportType)); ?></h2>
                        <p class="text-muted mb-0"><?php echo sanitize($from); ?> to <?php echo sanitize($to); ?><?php if ($reportGenerated): ?> <span class="ms-1">· <?php echo number_format(count($preview)); ?> record(s)</span><?php endif; ?></p>
                    </div>
                    <div class="d-flex flex-wrap gap-2 report-actions">
                        <?php if ($reportGenerated && count($preview) > 0): ?>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>
                            <button type="button" class="btn btn-outline-primary" id="exportReport" title="Export the records currently shown by your filters"><i class="bi bi-download me-1" aria-hidden="true"></i>Export visible CSV</button>
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
                    <table id="reportTable" class="table table-hover align-middle mb-0 report-table">
                        <thead class="table-light">
                            <tr>
                                <?php if ($reportType === 'Borrowed Items'): ?>
                                    <th>Item</th>
                                    <th>Borrower</th>
                                    <th>Date Borrowed</th>
                                    <th>Status</th>
                                <?php elseif ($reportType === 'SMS Logs'): ?>
                                    <th>Date Sent</th>
                                    <th>Recipient</th>
                                    <th>Message</th>
                                    <th>Status</th>
                                <?php else: ?>
                                    <th>Date</th>
                                    <th>From</th>
                                    <th>To</th>
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
                                            <td><?php echo sanitize((string)$row['date_borrowed']); ?></td>
                                            <td><span class="badge text-bg-<?php echo $row['status'] === 'Returned' ? 'success' : 'warning'; ?>"><?php echo sanitize((string)$row['status']); ?></span></td>
                                        <?php elseif ($reportType === 'SMS Logs'): ?>
                                            <td><?php echo sanitize((string)$row['date_sent']); ?></td>
                                            <td><?php echo sanitize((string)$row['recipient']); ?></td>
                                            <td class="report-message"><?php echo sanitize((string)$row['message']); ?></td>
                                            <td><span class="badge text-bg-<?php echo $row['status'] === 'Failed' ? 'danger' : ($row['status'] === 'Pending' ? 'warning' : 'success'); ?>"><?php echo sanitize((string)$row['status']); ?></span></td>
                                        <?php else: ?>
                                            <td><?php echo sanitize((string)$row['date_created']); ?></td>
                                            <td><?php echo sanitize((string)$row['sender']); ?></td>
                                            <td><?php echo sanitize((string)$row['recipient']); ?></td>
                                            <td><?php echo sanitize((string)$row['subject']); ?></td>
                                            <td><span class="badge text-bg-<?php echo $row['status'] === 'Pending' ? 'warning' : ($row['status'] === 'Released' ? 'success' : 'primary'); ?>"><?php echo sanitize((string)$row['status']); ?></span></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($reportGenerated && count($preview) > 0): ?>
                                <tr id="reportNoMatches" hidden><td colspan="<?php echo $reportType === 'SMS Logs' ? '4' : '5'; ?>" class="text-center text-muted py-4">No records match your search and status filters.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($reportGenerated && count($preview) > 0): ?>
                    <div class="small text-muted mt-3" id="reportResultCount" aria-live="polite"></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
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
}
reportSearch.addEventListener('input', filterReportRows);
statusFilter.addEventListener('change', filterReportRows);
filterReportRows();

document.getElementById('exportReport').addEventListener('click', function () {
    const table = document.getElementById('reportTable');
    const rows = Array.from(table.querySelectorAll('thead tr, tbody tr[data-status]')).filter(function (row) {
        return row.matches('thead tr') || !row.hidden;
    });
    const csv = rows.map(function (row) {
        return Array.from(row.querySelectorAll('th, td')).map(function (cell) {
            let value = cell.innerText.replace(/\r?\n/g, ' ').trim();
            if (/^[\s]*[=+\-@]/.test(value)) value = "'" + value;
            return '"' + value.replace(/"/g, '""') + '"';
        }).join(',');
    }).join('\r\n');
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = '<?php echo sanitize(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $reportType))); ?>-<?php echo sanitize($from); ?>-to-<?php echo sanitize($to); ?>.csv';
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
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
        } else if (button.dataset.range === '30days') {
            start.setDate(today.getDate() - 29);
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