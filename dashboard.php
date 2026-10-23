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

// Reporting window for the trend, "busiest times" and top-sources analytics.
$range = (int)($_GET['range'] ?? 30);
if (!in_array($range, [7, 30, 90], true)) {
    $range = 30;
}
// A borrowed item is flagged once it has been out longer than this many days.
$loanLimitDays = 7;

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
$overdueBulkDocuments = (int)$slaOverdue['bulkDocumentCount'];
$openBulkDocuments = (int)safe_scalar($conn, "SELECT COUNT(*) FROM document_batches WHERE (document_type = 'Incoming' AND status = 'Pending') OR (document_type = 'Outgoing' AND status = 'For Release')");
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
foreach ($slaOverdue['bulkDocuments'] as $batch) {
    $isIncoming = $batch['type'] === 'Incoming';
    $limitDays = (int)($isIncoming ? $slaSettings['incomingDays'] : $slaSettings['outgoingDays']);
    $submitter = trim((string)$batch['submitter_name']);
    $overdueRows[] = [
        'subject' => trim((string)$batch['subject']) . ' · ' . (string)$batch['batch_code'],
        'type' => 'Bulk Documents',
        'person' => $submitter !== '' ? $submitter : (string)$batch['sender'],
        'status' => (string)$batch['status'],
        'open' => (int)$batch['days_open'],
        'late' => (int)$batch['days_open'] - $limitDays,
        'href' => 'batch_papers?search=' . rawurlencode((string)$batch['batch_code']),
    ];
}
usort($overdueRows, static function ($a, $b) { return $b['late'] <=> $a['late']; });
// Full overdue lists for the pop-out windows; the dashboard table itself shows the top six.
$overdueByType = ['Incoming' => [], 'Outgoing' => [], 'Bulk Documents' => []];
foreach ($overdueRows as $overdueRow) {
    $overdueByType[$overdueRow['type']][] = $overdueRow;
}
// How late the overdue records are, so the admin can tell "slightly late" from "badly stuck".
$lateBands = ['1-2 days late' => 0, '3-7 days late' => 0, 'Over 7 days late' => 0];
foreach ($overdueRows as $overdueRow) {
    $lateBy = max(1, (int)$overdueRow['late']);
    if ($lateBy <= 2) {
        $lateBands['1-2 days late']++;
    } elseif ($lateBy <= 7) {
        $lateBands['3-7 days late']++;
    } else {
        $lateBands['Over 7 days late']++;
    }
}
$overdueRows = array_slice($overdueRows, 0, 6);
$openDocuments = (int)$pendingIncoming + (int)$forReleaseCount + $openBulkDocuments;
$slaCompliance = $openDocuments > 0 ? max(0, (int)round((($openDocuments - $slaOverdue['documentCount']) / $openDocuments) * 100)) : 100;

// Age of documents that are still open (Pending incoming, For Release outgoing).
$agingBuckets = ['0-1 day' => 0, '2-3 days' => 0, '4-7 days' => 0, 'Over 7 days' => 0];
$agingByType = [
    'Incoming' => ['0-1 day' => 0, '2-3 days' => 0, '4-7 days' => 0, 'Over 7 days' => 0],
    'Outgoing' => ['0-1 day' => 0, '2-3 days' => 0, '4-7 days' => 0, 'Over 7 days' => 0],
];
$openAgeSum = 0;
$openAgeCount = 0;
$oldestOpenAge = 0;
$stmt = safe_query($conn, "SELECT type, DATEDIFF(NOW(), date_created) AS age FROM documents WHERE (type = 'Incoming' AND status = 'Pending') OR (type = 'Outgoing' AND status = 'For Release')");
if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $age = max(0, (int)$row['age']);
        if ($age <= 1) {
            $bucket = '0-1 day';
        } elseif ($age <= 3) {
            $bucket = '2-3 days';
        } elseif ($age <= 7) {
            $bucket = '4-7 days';
        } else {
            $bucket = 'Over 7 days';
        }
        $agingBuckets[$bucket]++;
        if (isset($agingByType[$row['type']])) {
            $agingByType[$row['type']][$bucket]++;
        }
        $openAgeSum += $age;
        $openAgeCount++;
        $oldestOpenAge = max($oldestOpenAge, $age);
    }
    mysqli_stmt_close($stmt);
}
$avgOpenWait = $openAgeCount > 0 ? round($openAgeSum / $openAgeCount, 1) : 0;

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
$stmt = safe_query($conn, "SELECT item_name, borrower, date_borrowed, DATEDIFF(NOW(), date_borrowed) AS days_out FROM borrowed_items WHERE status <> 'Returned' ORDER BY date_borrowed ASC LIMIT 5");
if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $loanList[] = $row;
    }
    mysqli_stmt_close($stmt);
}

$recentSms = [];
$stmt = safe_query($conn, "SELECT recipient, message, status, date_sent FROM sms_logs ORDER BY (LOWER(status) IN ('failed', 'pending', 'queued')) DESC, date_sent DESC LIMIT 5");
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

// ---- Extra analytics ---------------------------------------------------------
$fetchRows = static function ($conn, string $sql): array {
    $rows = [];
    $stmt = safe_query($conn, $sql);
    if ($stmt !== false) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
    return $rows;
};

// Monthly volume: one grouped query instead of twelve, zero-filled and in chronological order.
$monthlyIncoming = [];
$monthlyOutgoing = [];
$months = [];
$monthKeys = [];
for ($i = 5; $i >= 0; $i--) {
    $monthKeys[] = date('Y-m', strtotime("first day of -$i months"));
    $months[] = date('M Y', strtotime("first day of -$i months"));
}
$monthCounts = [];
$sixMonthsAgo = date('Y-m-01', strtotime('first day of -5 months'));
foreach ($fetchRows($conn, "SELECT DATE_FORMAT(date_created, '%Y-%m') AS ym, type, COUNT(*) AS total FROM documents WHERE date_created >= '" . $sixMonthsAgo . "' GROUP BY ym, type") as $row) {
    $monthCounts[$row['ym']][$row['type']] = (int)$row['total'];
}
foreach ($monthKeys as $monthKey) {
    $monthlyIncoming[] = $monthCounts[$monthKey]['Incoming'] ?? 0;
    $monthlyOutgoing[] = $monthCounts[$monthKey]['Outgoing'] ?? 0;
}

// Month-to-date volume compared with the same stretch of last month (fair, like-for-like).
$mtdTotal = (int)$incomingMonth + (int)$outgoingMonth;
$prevStart = new DateTime('first day of last month midnight');
$prevDays = min((int)date('j'), (int)$prevStart->format('t'));
$prevEnd = (clone $prevStart)->modify('+' . $prevDays . ' days');
$prevMtdTotal = (int)safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE date_created >= ? AND date_created < ?", 'ss', [$prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')]);
$mtdDelta = $prevMtdTotal > 0 ? (int)round((($mtdTotal - $prevMtdTotal) / $prevMtdTotal) * 100) : null;

// Documents that will pass their time limit within the next 24 hours (act before they turn overdue).
$dueSoon = 0;
if ($slaSettings['enabled']) {
    $inLimit = max(1, (int)$slaSettings['incomingDays']);
    $outLimit = max(1, (int)$slaSettings['outgoingDays']);
    $dueSoon = (int)safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE (type = 'Incoming' AND status = 'Pending' AND date_created < (NOW() - INTERVAL " . ($inLimit - 1) . " DAY) AND date_created >= (NOW() - INTERVAL " . $inLimit . " DAY)) OR (type = 'Outgoing' AND status = 'For Release' AND date_created < (NOW() - INTERVAL " . ($outLimit - 1) . " DAY) AND date_created >= (NOW() - INTERVAL " . $outLimit . " DAY))");
}

// SMS reliability over the last 30 days.
$smsHealth = $fetchRows($conn, "SELECT SUM(LOWER(status) IN ('delivered', 'sent')) AS ok_total, SUM(LOWER(status) = 'failed') AS failed_total FROM sms_logs WHERE date_sent >= (NOW() - INTERVAL 30 DAY)");
$smsOk30 = (int)($smsHealth[0]['ok_total'] ?? 0);
$smsFailed30 = (int)($smsHealth[0]['failed_total'] ?? 0);
$smsRate = ($smsOk30 + $smsFailed30) > 0 ? (int)round(($smsOk30 / ($smsOk30 + $smsFailed30)) * 100) : null;
$smsStuck = (int)safe_scalar($conn, "SELECT COUNT(*) FROM sms_logs WHERE LOWER(status) IN ('pending', 'queued') AND date_sent < (NOW() - INTERVAL 1 DAY)");

// Daily intake for the selected window.
$trendLabels = [];
$trendIncoming = [];
$trendOutgoing = [];
$trendIndex = [];
for ($i = $range - 1; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $trendIndex[$day] = count($trendLabels);
    $trendLabels[] = date('M j', strtotime($day));
    $trendIncoming[] = 0;
    $trendOutgoing[] = 0;
}
$trendStart = date('Y-m-d', strtotime('-' . ($range - 1) . ' days'));
foreach ($fetchRows($conn, "SELECT DATE(date_created) AS d, type, COUNT(*) AS total FROM documents WHERE date_created >= '" . $trendStart . "' GROUP BY DATE(date_created), type") as $row) {
    if (!isset($trendIndex[$row['d']])) {
        continue;
    }
    if ($row['type'] === 'Incoming') {
        $trendIncoming[$trendIndex[$row['d']]] = (int)$row['total'];
    } elseif ($row['type'] === 'Outgoing') {
        $trendOutgoing[$trendIndex[$row['d']]] = (int)$row['total'];
    }
}
$trendTotal = array_sum($trendIncoming) + array_sum($trendOutgoing);
$trendAvg = round($trendTotal / max(1, $range), 1);

// Busiest weekdays and hours (last 90 days) for planning who needs to be at the desk.
$weekdayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$weekdayTotals = array_fill(0, 7, 0);
$hourTotals = array_fill(0, 24, 0);
foreach ($fetchRows($conn, "SELECT DAYOFWEEK(date_created) AS dow, HOUR(date_created) AS hr, COUNT(*) AS total FROM documents WHERE date_created >= (NOW() - INTERVAL 90 DAY) GROUP BY dow, hr") as $row) {
    $weekdayTotals[((int)$row['dow'] + 5) % 7] += (int)$row['total'];
    $hourTotals[(int)$row['hr']] += (int)$row['total'];
}
// If every record carries a midnight timestamp the time of day was never captured, so skip that chart.
$hasHourData = (array_sum($hourTotals) - $hourTotals[0]) > 0;
$hourLabels = [];
$hourValues = [];
if ($hasHourData) {
    $activeHours = array_keys(array_filter($hourTotals));
    for ($h = min($activeHours); $h <= max($activeHours); $h++) {
        $hourLabels[] = date('ga', mktime($h, 0, 0));
        $hourValues[] = $hourTotals[$h];
    }
}
$busiestWeekday = array_sum($weekdayTotals) > 0 ? $weekdayLabels[array_search(max($weekdayTotals), $weekdayTotals, true)] : null;

// Documents about to go overdue (next 24 hours), soonest first, so the admin knows exactly what to chase.
$dueSoonRows = [];
if ($slaSettings['enabled']) {
    $inLimitSql = max(1, (int)$slaSettings['incomingDays']);
    $outLimitSql = max(1, (int)$slaSettings['outgoingDays']);
    foreach ($fetchRows($conn, "SELECT doc_id, type, subject, sender, submitter_name, recipient, DATEDIFF(NOW(), date_created) AS days_open, TIMESTAMPDIFF(HOUR, NOW(), DATE_ADD(date_created, INTERVAL CASE WHEN type = 'Incoming' THEN " . $inLimitSql . " ELSE " . $outLimitSql . " END DAY)) AS hours_left FROM documents WHERE (type = 'Incoming' AND status = 'Pending' AND date_created < (NOW() - INTERVAL " . ($inLimitSql - 1) . " DAY) AND date_created >= (NOW() - INTERVAL " . $inLimitSql . " DAY)) OR (type = 'Outgoing' AND status = 'For Release' AND date_created < (NOW() - INTERVAL " . ($outLimitSql - 1) . " DAY) AND date_created >= (NOW() - INTERVAL " . $outLimitSql . " DAY)) ORDER BY hours_left ASC LIMIT 6") as $row) {
        $isIncomingRow = $row['type'] === 'Incoming';
        $personRow = $isIncomingRow ? (trim((string)$row['submitter_name']) !== '' ? $row['submitter_name'] : $row['sender']) : $row['recipient'];
        $dueSoonRows[] = [
            'subject' => (string)$row['subject'],
            'type' => (string)$row['type'],
            'person' => (string)$personRow,
            'open' => (int)$row['days_open'],
            'hours' => max(0, (int)$row['hours_left']),
            'href' => ($isIncomingRow ? 'incoming' : 'outgoing') . '?document_id=' . (int)$row['doc_id'],
        ];
    }
}

// Selected period versus the period right before it (same length), to show whether things are improving.
$periodStart = date('Y-m-d', strtotime('-' . ($range - 1) . ' days'));
$prevPeriodStart = date('Y-m-d', strtotime('-' . (($range * 2) - 1) . ' days'));
$docPeriod = $fetchRows($conn, "SELECT SUM(type = 'Incoming' AND date_created >= '" . $periodStart . "') AS cur_in, SUM(type = 'Outgoing' AND date_created >= '" . $periodStart . "') AS cur_out, SUM(type = 'Incoming' AND date_created < '" . $periodStart . "') AS prev_in, SUM(type = 'Outgoing' AND date_created < '" . $periodStart . "') AS prev_out FROM documents WHERE date_created >= '" . $prevPeriodStart . "'");
$smsPeriod = $fetchRows($conn, "SELECT SUM(date_sent >= '" . $periodStart . "') AS cur_sent, SUM(date_sent >= '" . $periodStart . "' AND LOWER(status) = 'failed') AS cur_failed, SUM(date_sent < '" . $periodStart . "') AS prev_sent, SUM(date_sent < '" . $periodStart . "' AND LOWER(status) = 'failed') AS prev_failed FROM sms_logs WHERE date_sent >= '" . $prevPeriodStart . "'");
$loanPeriod = $fetchRows($conn, "SELECT SUM(date_borrowed >= '" . $periodStart . "') AS cur_loans, SUM(date_borrowed < '" . $periodStart . "') AS prev_loans FROM borrowed_items WHERE date_borrowed >= '" . $prevPeriodStart . "'");
$dp = $docPeriod[0] ?? [];
$sp = $smsPeriod[0] ?? [];
$lp = $loanPeriod[0] ?? [];
$curIn = (int)($dp['cur_in'] ?? 0);
$curOut = (int)($dp['cur_out'] ?? 0);
$prevIn = (int)($dp['prev_in'] ?? 0);
$prevOut = (int)($dp['prev_out'] ?? 0);
// [label, current, previous, higher is worse?, decimals]
$periodRows = [
    ['Incoming documents', $curIn, $prevIn, false, 0],
    ['Outgoing documents', $curOut, $prevOut, false, 0],
    ['Total documents', $curIn + $curOut, $prevIn + $prevOut, false, 0],
    ['Average per day', round(($curIn + $curOut) / $range, 1), round(($prevIn + $prevOut) / $range, 1), false, 1],
    ['SMS sent', (int)($sp['cur_sent'] ?? 0), (int)($sp['prev_sent'] ?? 0), false, 0],
    ['SMS failed', (int)($sp['cur_failed'] ?? 0), (int)($sp['prev_failed'] ?? 0), true, 0],
    ['Items borrowed', (int)($lp['cur_loans'] ?? 0), (int)($lp['prev_loans'] ?? 0), false, 0],
];

// Borrowed items: how long they have been out and who is holding the most.
$loansOverdue = (int)safe_scalar($conn, "SELECT COUNT(*) FROM borrowed_items WHERE status <> 'Returned' AND date_borrowed < (NOW() - INTERVAL " . (int)$loanLimitDays . " DAY)");
$loanAging = ['0-3 days' => 0, '4-7 days' => 0, 'Over 7 days' => 0];
foreach ($fetchRows($conn, "SELECT DATEDIFF(NOW(), date_borrowed) AS days_out FROM borrowed_items WHERE status <> 'Returned'") as $row) {
    $daysOut = (int)$row['days_out'];
    $loanAging[$daysOut <= 3 ? '0-3 days' : ($daysOut <= 7 ? '4-7 days' : 'Over 7 days')]++;
}
$topBorrowers = $fetchRows($conn, "SELECT borrower, COUNT(*) AS total FROM borrowed_items WHERE status <> 'Returned' GROUP BY borrower HAVING total > 1 ORDER BY total DESC, borrower ASC LIMIT 3");

$plural = static function (int $count, string $singular, ?string $pluralForm = null): string {
    return number_format($count) . ' ' . ($count === 1 ? $singular : ($pluralForm ?? $singular . 's'));
};
?>

<?php include 'header.php'; ?>
<style>
.dashboard-delta { display: inline-flex; align-items: center; gap: .2rem; padding: .05rem .45rem; border-radius: 999px; font-size: .72rem; font-weight: 600; background: #eef2f1; color: #475569; }
.dashboard-delta.is-up { background: #e3f3e8; color: #17613e; }
.dashboard-delta.is-down { background: #fdecee; color: #a23a47; }
.dashboard-range-pills { display: inline-flex; gap: .25rem; padding: .2rem; border-radius: 999px; background: #eef2f1; }
.dashboard-range-pills a { padding: .2rem .75rem; border-radius: 999px; font-size: .8rem; font-weight: 600; color: #475569; text-decoration: none; }
.dashboard-range-pills a.is-active { background: #17613e; color: #fff; }
.dashboard-chart-wide { grid-column: 1 / -1; }
.dashboard-chip-row { display: flex; flex-wrap: wrap; gap: .5rem; margin: 0 0 1rem; }
.dashboard-chip { display: inline-flex; align-items: center; gap: .4rem; padding: .3rem .7rem; border-radius: 999px; font-size: .8rem; background: #eef2f1; color: #334155; }
.dashboard-chip strong { font-weight: 700; }
.dashboard-chip.is-warn { background: #fdf3d3; color: #7a5b00; }
.dashboard-chip.is-bad { background: #fdecee; color: #a23a47; }
.dashboard-chip.is-good { background: #e3f3e8; color: #17613e; }
.dashboard-loan-days { display: inline-block; margin-left: .35rem; padding: 0 .4rem; border-radius: 999px; font-size: .72rem; font-weight: 600; background: #eef2f1; color: #475569; }
.dashboard-loan-days.is-late { background: #fdecee; color: #a23a47; }
.dashboard-panel-note { margin: .75rem 0 0; font-size: .8rem; color: #64748b; }
</style>
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

    <section class="dashboard-metrics" aria-label="Performance snapshot">
        <div class="dashboard-metric">
            <span class="dashboard-metric-icon dashboard-metric-icon-green"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i></span>
            <span class="dashboard-metric-copy"><span>Volume this month</span><strong><?php echo number_format($mtdTotal); ?></strong><small>
                <?php if ($mtdDelta === null): ?>
                    No data for the same days last month
                <?php else: ?>
                    <span class="dashboard-delta <?php echo $mtdDelta > 0 ? 'is-up' : ($mtdDelta < 0 ? 'is-down' : ''); ?>"><i class="bi bi-<?php echo $mtdDelta > 0 ? 'arrow-up-right' : ($mtdDelta < 0 ? 'arrow-down-right' : 'dash'); ?>" aria-hidden="true"></i><?php echo abs($mtdDelta); ?>%</span>
                    vs <?php echo number_format($prevMtdTotal); ?> same days last month
                <?php endif; ?>
            </small></span>
        </div>
        <div class="dashboard-metric">
            <span class="dashboard-metric-icon dashboard-metric-icon-sand"><i class="bi bi-alarm" aria-hidden="true"></i></span>
            <span class="dashboard-metric-copy"><span>Due within 24 hours</span><strong><?php echo $slaSettings['enabled'] ? number_format($dueSoon) : '-'; ?></strong><small><?php echo $slaSettings['enabled'] ? 'Act now to avoid new overdue records' : 'Overdue tracking is turned off'; ?></small></span>
        </div>
        <div class="dashboard-metric">
            <span class="dashboard-metric-icon dashboard-metric-icon-teal"><i class="bi bi-stopwatch" aria-hidden="true"></i></span>
            <span class="dashboard-metric-copy"><span>Average wait (open)</span><strong><?php echo number_format($avgOpenWait, 1); ?> <small style="display:inline">days</small></strong><small>Oldest open: <?php echo sanitize($plural((int)$oldestOpenAge, 'day')); ?></small></span>
        </div>
        <a class="dashboard-metric dashboard-metric-link" href="sms">
            <span class="dashboard-metric-icon dashboard-metric-icon-neutral"><i class="bi bi-chat-dots" aria-hidden="true"></i></span>
            <span class="dashboard-metric-copy"><span>SMS delivery (30 days)</span><strong><?php echo $smsRate === null ? '-' : (int)$smsRate . '%'; ?></strong><small><?php echo number_format($smsFailed30); ?> failed<?php echo $smsStuck > 0 ? ', ' . number_format($smsStuck) . ' stuck over 24h' : ''; ?></small></span>
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
                <?php foreach ([
                    ['Incoming', $overdueIncoming, 'overdue after ' . $plural((int)$slaSettings['incomingDays'], 'day')],
                    ['Outgoing', $overdueOutgoing, 'overdue after ' . $plural((int)$slaSettings['outgoingDays'], 'day')],
                    ['Bulk Documents', $overdueBulkDocuments, 'open batches past their direction limit'],
                ] as $limitTile): ?>
                    <?php $tileTag = $limitTile[1] > 0 ? 'button' : 'div'; ?>
                    <<?php echo $tileTag; ?> class="dashboard-sla-stat <?php echo $limitTile[1] > 0 ? 'is-alert dashboard-sla-link' : 'is-ok'; ?>"<?php echo $limitTile[1] > 0 ? ' type="button" data-bs-toggle="modal" data-bs-target="#overdueModal' . sanitize(str_replace(' ', '', $limitTile[0])) . '" aria-label="View the ' . (int)$limitTile[1] . ' overdue ' . strtolower(sanitize($limitTile[0])) . '"' : ''; ?>>
                        <span><?php echo sanitize($limitTile[0]); ?></span>
                        <strong><?php echo number_format($limitTile[1]); ?></strong>
                        <em><?php echo sanitize($limitTile[2]); ?></em>
                    </<?php echo $tileTag; ?>>
                <?php endforeach; ?>
                <div class="dashboard-sla-stat">
                    <span>Open documents on time</span>
                    <strong><?php echo (int)$slaCompliance; ?>%</strong>
                    <div class="dashboard-ontime-bar" role="img" aria-label="<?php echo (int)$slaCompliance; ?> percent of open documents are within their time limit"><span style="width: <?php echo (int)$slaCompliance; ?>%"></span></div>
                </div>
            </div>
            <div class="dashboard-chip-row" aria-label="Overdue breakdown">
                <span class="dashboard-chip <?php echo $dueSoon > 0 ? 'is-warn' : 'is-good'; ?>"><i class="bi bi-alarm" aria-hidden="true"></i>Due within 24 hours <strong><?php echo number_format($dueSoon); ?></strong></span>
                <?php foreach ($lateBands as $bandLabel => $bandCount): ?>
                    <span class="dashboard-chip <?php echo $bandCount > 0 && $bandLabel === 'Over 7 days late' ? 'is-bad' : ''; ?>"><?php echo sanitize($bandLabel); ?> <strong><?php echo number_format($bandCount); ?></strong></span>
                <?php endforeach; ?>
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
                                    <td data-label="Status">
                                        <div class="d-inline-flex flex-column align-items-center gap-1">
                                            <span class="badge <?php echo admin_status_badge_class($row['status']); ?>"><?php echo sanitize($row['status']); ?></span>
                                            <span class="badge text-bg-danger">Overdue</span>
                                        </div>
                                    </td>
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

    <?php foreach (['Incoming' => $overdueIncoming, 'Outgoing' => $overdueOutgoing, 'Bulk Documents' => $overdueBulkDocuments] as $modalType => $modalCount): ?>
        <?php if ($modalCount > 0): ?>
            <?php $modalId = str_replace(' ', '', $modalType); ?>
            <div class="modal fade overdue-modal" id="overdueModal<?php echo sanitize($modalId); ?>" tabindex="-1" aria-labelledby="overdueModal<?php echo sanitize($modalId); ?>Title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title" id="overdueModal<?php echo sanitize($modalId); ?>Title">Overdue <?php echo strtolower(sanitize($modalType)); ?></h5>
                                <p class="overdue-modal-sub"><?php echo number_format($modalCount); ?> <?php echo $modalType === 'Bulk Documents' ? 'past the applicable incoming or outgoing time limit' : 'past the ' . sanitize($plural((int)$slaSettings[strtolower($modalType) . 'Days'], 'day')) . ' limit'; ?>, most overdue first</p>
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
                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo $modalType === 'Incoming' ? 'incoming?overdue=1' : ($modalType === 'Outgoing' ? 'outgoing?overdue=1' : 'batch_papers?overdue=1'); ?>"><?php echo $modalType === 'Bulk Documents' ? 'Open overdue bulk documents' : 'Open full list'; ?></a>
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
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <nav class="dashboard-range-pills" aria-label="Reporting period">
                    <?php foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days'] as $rangeValue => $rangeLabel): ?>
                        <a href="?range=<?php echo $rangeValue; ?>" class="<?php echo $range === $rangeValue ? 'is-active' : ''; ?>"<?php echo $range === $rangeValue ? ' aria-current="true"' : ''; ?>><?php echo $rangeLabel; ?></a>
                    <?php endforeach; ?>
                </nav>
                <a href="reports" class="dashboard-text-link">Open reports<i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
            </div>
        </div>
        <div class="dashboard-chart-grid">
            <article class="dashboard-panel dashboard-chart-panel dashboard-chart-wide">
                <div class="dashboard-panel-heading"><h3>Daily document intake<button type="button" class="chart-info-button" data-chart-info="<strong>Incoming and outgoing documents recorded each day</strong> in the selected period. Use it to spot spikes and quiet days." aria-label="About this chart"><i class="bi bi-info-circle" aria-hidden="true"></i></button></h3><span>Last <?php echo (int)$range; ?> days &middot; <?php echo number_format($trendTotal); ?> documents &middot; <?php echo number_format($trendAvg, 1); ?> per day</span></div>
                <div class="dashboard-chart-container"><canvas id="redesignTrendChart" role="img" aria-label="Line chart of incoming and outgoing documents per day"></canvas></div>
            </article>
            <article class="dashboard-panel dashboard-chart-panel">
                <div class="dashboard-panel-heading"><h3>Documents by their status</h3><span>All recorded documents</span></div>
                <div class="dashboard-chart-container"><canvas id="redesignStatusChart" role="img" aria-label="Chart showing document counts by status"></canvas></div>
            </article>
            <article class="dashboard-panel dashboard-chart-panel">
                <div class="dashboard-panel-heading"><h3>Monthly document volume</h3><span>Last six months</span></div>
                <div class="dashboard-chart-container"><canvas id="redesignMonthlyChart" role="img" aria-label="Chart comparing incoming and outgoing document counts by month"></canvas></div>
            </article>
            <article class="dashboard-panel dashboard-chart-panel">
                <div class="dashboard-panel-heading"><h3>Open workload by age<button type="button" class="chart-info-button" data-chart-info="<strong>Bars show open documents</strong> (Pending incoming and For Release outgoing), grouped by how many days they have been waiting and split by type. Documents waiting over 7 days need attention first." aria-label="About this chart"><i class="bi bi-info-circle" aria-hidden="true"></i></button></h3><span>Days documents have been waiting</span></div>
                <div class="dashboard-chart-container"><canvas id="redesignAgingChart" role="img" aria-label="Chart showing how many days open documents have been waiting, split by incoming and outgoing"></canvas></div>
            </article>
            <article class="dashboard-panel dashboard-chart-panel">
                <div class="dashboard-panel-heading"><h3>SMS overview<button type="button" class="chart-info-button" data-chart-info="<strong>Delivered:</strong> confirmed received on the recipient's phone.<br><strong>Pending:</strong> awaiting confirmation.<br><strong>Failed:</strong> not delivered. Follow up with the recipient." aria-label="What the SMS statuses mean"><i class="bi bi-info-circle" aria-hidden="true"></i></button></h3><span>Delivery status of all messages</span></div>
                <div class="dashboard-chart-container"><canvas id="redesignSmsChart" role="img" aria-label="Chart showing SMS messages by delivery status"></canvas></div>
            </article>
            <article class="dashboard-panel dashboard-chart-panel<?php echo $hasHourData ? '' : ' dashboard-chart-wide'; ?>">
                <div class="dashboard-panel-heading"><h3>Busiest weekdays<button type="button" class="chart-info-button" data-chart-info="<strong>Documents recorded per weekday over the last 90 days.</strong> Helps decide when more staff are needed at the desk." aria-label="About this chart"><i class="bi bi-info-circle" aria-hidden="true"></i></button></h3><span><?php echo $busiestWeekday !== null ? 'Busiest: ' . sanitize($busiestWeekday) . ' &middot; last 90 days' : 'Last 90 days'; ?></span></div>
                <div class="dashboard-chart-container"><canvas id="redesignWeekdayChart" role="img" aria-label="Chart showing documents recorded per weekday"></canvas></div>
            </article>
            <?php if ($hasHourData): ?>
                <article class="dashboard-panel dashboard-chart-panel">
                    <div class="dashboard-panel-heading"><h3>Busiest hours</h3><span>Time of day &middot; last 90 days</span></div>
                    <div class="dashboard-chart-container"><canvas id="redesignHourChart" role="img" aria-label="Chart showing documents recorded per hour of the day"></canvas></div>
                </article>
            <?php endif; ?>
        </div>
    </section>

    <section class="dashboard-followup-grid" aria-label="Upcoming deadlines and period comparison">
        <article class="dashboard-panel">
            <div class="dashboard-panel-heading dashboard-panel-heading-action">
                <div><span class="dashboard-section-eyebrow">Act today</span><h2>About to go overdue</h2><p><?php echo $slaSettings['enabled'] ? 'Will pass their time limit within 24 hours, soonest first.' : 'Overdue tracking is turned off.'; ?></p></div>
                <a href="settings#sla-settings" class="dashboard-text-link">Time limits<i class="bi bi-gear" aria-hidden="true"></i></a>
            </div>
            <?php if (empty($dueSoonRows)): ?>
                <div class="dashboard-empty-state"><i class="bi bi-check2-circle" aria-hidden="true"></i><span><?php echo $slaSettings['enabled'] ? 'Nothing is about to go overdue in the next 24 hours.' : 'Turn on overdue tracking to see upcoming deadlines.'; ?></span></div>
            <?php else: ?>
                <div class="dashboard-followup-list">
                    <?php foreach ($dueSoonRows as $dueRow): ?>
                        <a class="dashboard-followup-item" href="<?php echo sanitize($dueRow['href']); ?>">
                            <span class="dashboard-followup-icon"><i class="bi bi-alarm" aria-hidden="true"></i></span>
                            <span class="dashboard-followup-copy"><strong><?php echo sanitize($dueRow['subject']); ?></strong><small><?php echo sanitize($dueRow['type']); ?> - <?php echo sanitize($dueRow['person'] !== '' ? $dueRow['person'] : '-'); ?> - <?php echo sanitize($plural($dueRow['open'], 'day')); ?> open</small></span>
                            <span class="dashboard-loan-days is-late"><?php echo $dueRow['hours'] < 1 ? 'Under 1 hour' : sanitize($plural($dueRow['hours'], 'hour')) . ' left'; ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php if ($dueSoon > count($dueSoonRows)): ?>
                    <p class="dashboard-panel-note">Showing <?php echo count($dueSoonRows); ?> of <?php echo number_format($dueSoon); ?> documents due within 24 hours.</p>
                <?php endif; ?>
            <?php endif; ?>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-panel-heading dashboard-panel-heading-action">
                <div><span class="dashboard-section-eyebrow">Trend</span><h2>Compared with the previous period</h2><p>Last <?php echo (int)$range; ?> days versus the <?php echo (int)$range; ?> days before.</p></div>
                <a href="reports" class="dashboard-text-link">Open reports<i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 dashboard-activity-table">
                    <thead><tr><th scope="col">Measure</th><th scope="col" class="text-end">Now</th><th scope="col" class="text-end">Before</th><th scope="col" class="text-end">Change</th></tr></thead>
                    <tbody>
                        <?php foreach ($periodRows as $periodRow): ?>
                            <?php
                            [$periodLabel, $periodNow, $periodBefore, $higherIsWorse, $periodDecimals] = $periodRow;
                            $periodChange = $periodBefore > 0 ? (int)round((($periodNow - $periodBefore) / $periodBefore) * 100) : null;
                            $deltaClass = '';
                            if ($periodChange !== null && $periodChange !== 0) {
                                $isGood = $higherIsWorse ? $periodChange < 0 : $periodChange > 0;
                                $deltaClass = $higherIsWorse ? ($isGood ? 'is-up' : 'is-down') : ($periodChange > 0 ? 'is-up' : 'is-down');
                            }
                            ?>
                            <tr>
                                <td><?php echo sanitize($periodLabel); ?></td>
                                <td class="text-end"><strong><?php echo number_format($periodNow, $periodDecimals); ?></strong></td>
                                <td class="text-end text-muted"><?php echo number_format($periodBefore, $periodDecimals); ?></td>
                                <td class="text-end">
                                    <?php if ($periodChange === null): ?>
                                        <span class="text-muted"><?php echo $periodNow > 0 ? 'New' : '-'; ?></span>
                                    <?php else: ?>
                                        <span class="dashboard-delta <?php echo $deltaClass; ?>"><i class="bi bi-<?php echo $periodChange > 0 ? 'arrow-up-right' : ($periodChange < 0 ? 'arrow-down-right' : 'dash'); ?>" aria-hidden="true"></i><?php echo abs($periodChange); ?>%</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="dashboard-panel-note">Green means better, red means worse. Failed SMS going up is shown as worse.</p>
        </article>
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
                <div><span class="dashboard-section-eyebrow">Borrowed Items</span><h2>Awaiting return</h2><p><?php echo sanitize($plural((int)$loansActive, 'borrowed item')); ?><?php echo $loansOverdue > 0 ? ', ' . number_format($loansOverdue) . ' out longer than ' . (int)$loanLimitDays . ' days' : ''; ?>.</p></div>
                <a href="borrowed" class="dashboard-text-link">View borrowed items<i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <?php if ($loansActive > 0): ?>
                <div class="dashboard-chip-row">
                    <?php foreach ($loanAging as $loanBucket => $loanBucketCount): ?>
                        <span class="dashboard-chip <?php echo $loanBucket === 'Over 7 days' && $loanBucketCount > 0 ? 'is-bad' : ''; ?>"><?php echo sanitize($loanBucket); ?> <strong><?php echo number_format($loanBucketCount); ?></strong></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (empty($loanList)): ?>
                <div class="dashboard-empty-state"><i class="bi bi-box-seam" aria-hidden="true"></i><span>There are no borrowed items awaiting return.</span></div>
            <?php else: ?>
                <div class="dashboard-followup-list">
                    <?php foreach ($loanList as $item): ?>
                        <a class="dashboard-followup-item" href="borrowed?search=<?php echo rawurlencode($item['item_name']); ?>">
                            <span class="dashboard-followup-icon"><i class="bi bi-box" aria-hidden="true"></i></span>
                            <span class="dashboard-followup-copy"><strong><?php echo sanitize($item['item_name']); ?></strong><small><?php echo sanitize($item['borrower']); ?> - borrowed on <?php echo sanitize(date('M j, Y', strtotime($item['date_borrowed']))); ?><span class="dashboard-loan-days <?php echo (int)$item['days_out'] > $loanLimitDays ? 'is-late' : ''; ?>"><?php echo sanitize($plural(max(0, (int)$item['days_out']), 'day')); ?> out</span></small></span>
                            <i class="bi bi-chevron-right dashboard-followup-arrow" aria-hidden="true"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($topBorrowers)): ?>
                <p class="dashboard-panel-note">Holding several items: <?php echo sanitize(implode(', ', array_map(static function ($b) { return $b['borrower'] . ' (' . (int)$b['total'] . ')'; }, $topBorrowers))); ?></p>
            <?php endif; ?>
        </article>

        <article class="dashboard-panel">
            <div class="dashboard-panel-heading dashboard-panel-heading-action">
                <div><span class="dashboard-section-eyebrow">Communication</span><h2>SMS needing attention</h2><p><?php echo sanitize($plural((int)($smsPending + $smsFailed), 'message')); ?> need follow-up. Failed and pending messages are shown first.</p></div>
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

    const agingByType = <?php echo json_encode($agingByType); ?>;
    const agingLabels = Object.keys(agingByType.Incoming);
    new Chart(document.getElementById('redesignAgingChart'), {
        type: 'bar',
        data: {
            labels: agingLabels,
            datasets: [{
                label: 'Incoming (Pending)',
                data: agingLabels.map((label) => agingByType.Incoming[label]),
                backgroundColor: '#17613e',
                maxBarThickness: 44
            }, {
                label: 'Outgoing (For Release)',
                data: agingLabels.map((label) => agingByType.Outgoing[label]),
                backgroundColor: '#5eb7a8',
                borderRadius: 5,
                maxBarThickness: 44
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: Object.assign({}, countAxis, { stacked: true }), x: { stacked: true, grid: { display: false } } },
            plugins: { legend },
            interaction: { intersect: false, mode: 'index' }
        }
    });

    const trendLabels = <?php echo json_encode($trendLabels); ?>;
    new Chart(document.getElementById('redesignTrendChart'), {
        type: 'line',
        data: {
            labels: trendLabels,
            datasets: [{
                label: 'Incoming',
                data: <?php echo json_encode($trendIncoming); ?>,
                borderColor: '#17613e',
                backgroundColor: 'rgba(23, 97, 62, 0.10)',
                fill: true,
                tension: 0.3,
                pointRadius: trendLabels.length > 31 ? 0 : 3,
                pointHoverRadius: 5
            }, {
                label: 'Outgoing',
                data: <?php echo json_encode($trendOutgoing); ?>,
                borderColor: '#5eb7a8',
                backgroundColor: 'rgba(94, 183, 168, 0.10)',
                fill: true,
                tension: 0.3,
                pointRadius: trendLabels.length > 31 ? 0 : 3,
                pointHoverRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: countAxis, x: { grid: { display: false }, ticks: { maxTicksLimit: 10, autoSkip: true } } },
            plugins: { legend },
            interaction: { intersect: false, mode: 'index' }
        }
    });

    const weekdayValues = <?php echo json_encode($weekdayTotals); ?>;
    const weekdayPeak = Math.max.apply(null, weekdayValues);
    new Chart(document.getElementById('redesignWeekdayChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($weekdayLabels); ?>,
            datasets: [{
                label: 'Documents',
                data: weekdayValues,
                backgroundColor: weekdayValues.map((value) => (weekdayPeak > 0 && value === weekdayPeak) ? '#17613e' : '#9fd3c8'),
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

    <?php if ($hasHourData): ?>
    const hourValues = <?php echo json_encode($hourValues); ?>;
    const hourPeak = Math.max.apply(null, hourValues);
    new Chart(document.getElementById('redesignHourChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($hourLabels); ?>,
            datasets: [{
                label: 'Documents',
                data: hourValues,
                backgroundColor: hourValues.map((value) => (hourPeak > 0 && value === hourPeak) ? '#17613e' : '#9fd3c8'),
                borderRadius: 5,
                maxBarThickness: 32
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: countAxis, x: { grid: { display: false } } },
            plugins: { legend: { display: false } }
        }
    });
    <?php endif; ?>

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