<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'batch_support.php';

header('X-Robots-Tag: noindex, nofollow');
$storageError = false;
try {
    ensure_batch_storage($conn);
} catch (Throwable $error) {
    error_log('Batch tracker storage setup failed: ' . $error->getMessage());
    http_response_code(500);
    $storageError = true;
}
$trackingToken = trim($_GET['token'] ?? '');
$batch = null;

// The opaque token is the public capability; expose only aggregate batch details, never paper records.
if (!$storageError && preg_match('/^[a-f0-9]{64}$/', $trackingToken)) {
    $stmt = safe_query(
        $conn,
        "SELECT batch_id, batch_code, batch_type, status, is_priority, batch_date, paper_count, intake_notes,
                received_paper_count, intake_expected_count, received_at, received_by
         FROM document_batches
         WHERE tracking_token = ?",
        's',
        [$trackingToken]
    );
    if ($stmt !== false) {
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if ($row) {
            $batch = [
                'code' => $row['batch_code'],
                'type' => $row['batch_type'],
                'status' => $row['status'],
                'isPriority' => (int)$row['is_priority'],
                'date' => $row['batch_date'],
                'paperCount' => (int)$row['paper_count'],
                'receivedPaperCount' => $row['received_paper_count'] === null ? null : (int)$row['received_paper_count'],
                'intakeExpectedCount' => $row['intake_expected_count'] === null ? null : (int)$row['intake_expected_count'],
                'receivedAt' => $row['received_at'],
                'receivedBy' => $row['received_by'],
                'intakeNotes' => $row['intake_notes'] ?? '',
                'history' => fetch_status_history($conn, 'batch_id', (int)$row['batch_id']),
            ];
        }
        mysqli_stmt_close($stmt);
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($batch !== null && $batch['receivedAt'] === null): ?><meta http-equiv="refresh" content="60"><?php endif; ?>
    <title><?php echo $batch !== null && $batch['receivedAt'] !== null ? 'Batch Receipt' : 'Batch Paper Tracker'; ?> | CEIT Dean's Office</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { min-height: 100vh; background: #f0fdf4; color: #064e3b; }
        .tracker-card { width: min(100%, 100%); max-width: 48rem; border-color: #bbf7d0; border-radius: 1.25rem; }
        [hidden] { display: none !important; }
        /* Self-contained so the status stays readable even if the admin stylesheet or Bootstrap theme is unavailable. */
        .tracker-status { display: inline-block; padding: .3rem .75rem; border-radius: 999px; background: #fef9c3; color: #713f12; font-size: .85rem; font-weight: 700; line-height: 1.2; }
        .tracker-status.status-for-release, .tracker-status.status-approved { background: #dbeafe; color: #1e3a8a; }
        .tracker-status.status-released { background: #dcfce7; color: #166534; }
        .tracker-status.status-incomplete, .tracker-status.status-returned { background: #ffedd5; color: #9a3412; }
        .tracker-status.status-on-hold, .tracker-status.status-cancelled { background: #e5e7eb; color: #374151; }
        .tracker-status.status-priority { background: #ffedd5; color: #9a3412; }
        .history { margin: 1.25rem 0 1.25rem; }
        .history h2 { margin: 0 0 .75rem; font-size: 1rem; font-weight: 700; color: #102e24; }
        .timeline { position: relative; margin: 0; padding: 0; list-style: none; }
        .timeline li { position: relative; padding: 0 0 1.1rem 1.6rem; }
        .timeline li::before { content: ""; position: absolute; left: .42rem; top: .4rem; bottom: -.1rem; width: 2px; background: #cfe3d6; }
        .timeline li:last-child { padding-bottom: 0; }
        .timeline li:last-child::before { display: none; }
        .timeline li::after { content: ""; position: absolute; left: 0; top: .25rem; width: .9rem; height: .9rem; border-radius: 50%; border: 2px solid #9fc4ad; background: #fff; }
        .timeline li.is-latest::after { border-color: #16834a; background: #16834a; box-shadow: 0 0 0 4px #d7f0df; }
        .timeline .tl-title { margin: 0; color: #173d2c; font-weight: 700; font-size: .95rem; }
        .timeline li:not(.is-latest) .tl-title { color: #526d63; font-weight: 600; }
        .timeline .tl-note { margin: .1rem 0 0; color: #526d63; font-size: .85rem; }
        .timeline .tl-time { margin: .1rem 0 0; color: #60766b; font-size: .78rem; }
        .receipt-letterhead { display: grid; grid-template-columns: 4.5rem minmax(0, 1fr) auto; align-items: center; gap: 1rem; padding-bottom: 1.25rem; border-bottom: 2px solid #0f5132; }
        .receipt-letterhead img { width: 4.25rem; height: 4.25rem; object-fit: contain; }
        .receipt-letterhead p { margin: 0; color: #466356; font-size: .78rem; line-height: 1.45; }
        .receipt-letterhead .receipt-university { color: #064e3b; font-size: 1rem; font-weight: 800; letter-spacing: .035em; }
        .receipt-stamp { text-align: right; }
        .receipt-stamp span { display: block; color: #0f5132; font-size: .68rem; font-weight: 800; letter-spacing: .12em; }
        .receipt-stamp strong { display: block; margin-top: .25rem; color: #064e3b; font-size: .9rem; }
        .receipt-title { margin: 1.25rem 0 .25rem; color: #064e3b; font-size: 1.55rem; font-weight: 750; }
        .receipt-intro { margin: 0 0 1.25rem; color: #526d63; }
        .receipt-details { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .85rem; margin: 0 0 1.25rem; }
        .receipt-detail { min-width: 0; padding: .8rem .9rem; border: 1px solid #dce9df; border-radius: .75rem; background: #fbfdfb; }
        .receipt-detail dt { margin-bottom: .2rem; color: #60766b; font-size: .7rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
        .receipt-detail dd { margin: 0; color: #173d2c; font-size: .95rem; font-weight: 700; overflow-wrap: anywhere; }
        .receipt-counts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .85rem; margin-bottom: 1rem; }
        .receipt-count { padding: 1rem; border: 1px solid #dce9df; border-radius: .85rem; background: #f7fbf8; }
        .receipt-count span { display: block; color: #60766b; font-size: .75rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
        .receipt-count strong { display: block; margin-top: .25rem; color: #064e3b; font-size: 1.65rem; line-height: 1.15; }
        .receipt-confirmation { padding: 1rem 1.1rem; border: 1px solid #b7ddc3; border-left: 4px solid #16834a; border-radius: .75rem; background: #f1faf3; }
        .receipt-confirmation p:last-child { margin-bottom: 0; }
        .receipt-notes { margin-top: 1rem; padding-top: .8rem; border-top: 1px solid #d5e7da; white-space: pre-wrap; overflow-wrap: anywhere; }
        .receipt-signoff { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 2.5rem; }
        .receipt-signoff div { padding-top: .45rem; border-top: 1px solid #506b5c; color: #526d63; font-size: .78rem; }
        .receipt-print-meta { margin-top: 1.5rem; padding-top: .75rem; border-top: 1px solid #dce9df; color: #60766b; font-size: .72rem; text-align: center; }
        .receipt-reference { color: #0f5132; font-weight: 700; }
        @media (max-width: 520px) {
            .receipt-letterhead { grid-template-columns: 3.4rem minmax(0, 1fr); gap: .7rem; }
            .receipt-letterhead img { width: 3.25rem; height: 3.25rem; }
            .receipt-stamp { grid-column: 1 / -1; display: flex; justify-content: space-between; align-items: baseline; text-align: left; }
            .receipt-details { grid-template-columns: 1fr; gap: .55rem; }
            .receipt-signoff { gap: 1rem; }
        }
        @media print {
            @page { size: A4 portrait; margin: 14mm; }
            body { min-height: 0; background: #fff !important; color: #111; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            .container { max-width: none !important; padding: 0 !important; }
            .tracker-card { width: 100%; max-width: none; border: 0; border-radius: 0; box-shadow: none !important; }
            .tracker-card > .card-body { padding: 0 !important; }
            .receipt-letterhead { grid-template-columns: 20mm minmax(0, 1fr) auto; gap: 5mm; padding-bottom: 4mm; }
            .receipt-letterhead img { width: 19mm; height: 19mm; }
            .receipt-title { margin-top: 7mm; font-size: 18pt; }
            .receipt-detail, .receipt-count, .receipt-confirmation { break-inside: avoid; }
            .receipt-detail { padding: 3mm; }
            .receipt-count { padding: 4mm; }
            .receipt-count strong { font-size: 20pt; }
            .receipt-signoff { margin-top: 18mm; }
            .receipt-print-meta { margin-top: 10mm; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
<main class="container py-4 py-md-5">
    <div class="tracker-card card shadow-sm mx-auto">
        <div class="card-body p-4 p-md-5">
            <?php if ($storageError): ?>
                <p class="small fw-semibold text-uppercase text-success mb-2">CEIT Dean's Office</p>
                <h1 class="h3 text-danger">Tracker temporarily unavailable</h1>
                <p class="mb-0 text-secondary">The bulk submission tracker could not load its storage. Please try again later or contact the office.</p>
            <?php elseif ($batch === null): ?>
                <p class="small fw-semibold text-uppercase text-success mb-2">CEIT Dean's Office</p>
                <h1 class="h3 text-success">Bulk submission not found</h1>
                <p class="mb-0 text-secondary">This tracking link is invalid or no longer available. Please ask the office for an updated QR code.</p>
            <?php else: ?>
                <?php
                $statusText = trim((string)$batch['status']);
                $statusSlug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($statusText)), '-');
                $expectedCount = $batch['intakeExpectedCount'] !== null ? $batch['intakeExpectedCount'] : $batch['paperCount'];
                ?>
                <?php if ($batch['receivedAt'] !== null): ?>
                    <header class="receipt-letterhead">
                        <img src="assets/images/CVSU.jpeg" alt="Cavite State University seal">
                        <div>
                            <p>Republic of the Philippines</p>
                            <p class="receipt-university">CAVITE STATE UNIVERSITY</p>
                            <p>College of Engineering and Information Technology</p>
                            <p>Dean's Office · Digital Logbook</p>
                        </div>
                        <div class="receipt-stamp">
                            <span>OFFICE RECEIPT</span>
                            <strong><?php echo sanitize($batch['code']); ?></strong>
                        </div>
                    </header>
                    <h1 class="receipt-title">Bulk document intake receipt</h1>
                    <p class="receipt-intro">This receipt confirms the office's recorded intake count for the bulk submission identified below.</p>
                <?php else: ?>
                    <p class="small fw-semibold text-uppercase text-success mb-2">CEIT Dean's Office</p>
                    <h1 class="h3 text-success">Batch paper tracker</h1>
                    <p class="text-secondary mb-4 no-print">Current bulk submission details and tracking status. This page refreshes every minute.</p>
                <?php endif; ?>
                <dl class="<?php echo $batch['receivedAt'] !== null ? 'receipt-details' : 'row small mb-4'; ?>">
                    <?php if ($batch['receivedAt'] !== null): ?>
                        <div class="receipt-detail"><dt>Batch reference</dt><dd class="receipt-reference"><?php echo sanitize($batch['code']); ?></dd></div>
                        <div class="receipt-detail"><dt>Document type</dt><dd><?php echo sanitize($batch['type']); ?></dd></div>
                        <div class="receipt-detail"><dt>Date submitted</dt><dd><?php echo sanitize(date('F j, Y', strtotime($batch['date']))); ?></dd></div>
                        <div class="receipt-detail"><dt>Current status</dt><dd><span class="tracker-status status-<?php echo sanitize($statusSlug !== '' ? $statusSlug : 'unknown'); ?>"><?php echo sanitize($statusText !== '' ? $statusText : 'Not recorded'); ?></span><?php if ($batch['isPriority']): ?> <span class="tracker-status status-priority ms-1">Priority</span><?php endif; ?></dd></div>
                    <?php else: ?>
                        <dt class="col-5 col-sm-4">Bulk submission</dt>
                        <dd class="col-7 col-sm-8"><?php echo sanitize($batch['code']); ?></dd>
                        <dt class="col-5 col-sm-4">Type</dt>
                        <dd class="col-7 col-sm-8"><?php echo sanitize($batch['type']); ?></dd>
                        <dt class="col-5 col-sm-4">Status</dt>
                        <dd class="col-7 col-sm-8">
                            <span class="tracker-status status-<?php echo sanitize($statusSlug !== '' ? $statusSlug : 'unknown'); ?>"><?php echo sanitize($statusText !== '' ? $statusText : 'Not recorded'); ?></span>
                            <?php if ($batch['isPriority']): ?><span class="tracker-status status-priority ms-1">Priority</span><?php endif; ?>
                        </dd>
                        <dt class="col-5 col-sm-4">Date recorded</dt>
                        <dd class="col-7 col-sm-8"><?php echo sanitize(date('M j, Y', strtotime($batch['date']))); ?></dd>
                    <?php endif; ?>
                </dl>
                <?php if ($batch['receivedAt'] !== null): ?>
                    <div class="receipt-counts">
                        <div class="receipt-count"><span>Expected documents</span><strong><?php echo number_format($expectedCount); ?></strong></div>
                        <div class="receipt-count"><span>Physically received</span><strong><?php echo number_format((int)$batch['receivedPaperCount']); ?></strong></div>
                    </div>
                <?php else: ?>
                    <div class="rounded-3 border bg-light p-3 mb-3">
                        <span class="text-secondary">Expected documents</span>
                        <strong class="ms-2"><?php echo number_format($expectedCount); ?></strong>
                    </div>
                <?php endif; ?>
                <section class="history" aria-labelledby="historyTitle">
                    <h2 id="historyTitle">Tracking history</h2>
                    <ol class="timeline">
                        <?php foreach ($batch['history'] as $historyIndex => $event): ?>
                            <li<?php echo $historyIndex === 0 ? ' class="is-latest"' : ''; ?>>
                                <p class="tl-title"><?php echo sanitize($event['kind'] === 'scan' ? 'Received by the office' : $event['status']); ?></p>
                                <?php if (trim((string)($event['note'] ?? '')) !== ''): ?><p class="tl-note"><?php echo sanitize($event['note']); ?></p><?php endif; ?>
                                <p class="tl-time"><?php echo sanitize(date('M j, Y, g:i A', strtotime($event['at']))); ?></p>
                            </li>
                        <?php endforeach; ?>
                        <?php if (!$batch['history']): ?><li class="is-latest"><p class="tl-title">No history recorded yet.</p></li><?php endif; ?>
                    </ol>
                </section>
                <?php if ($batch['receivedAt'] !== null): ?>
                    <div class="receipt-confirmation">
                        <p class="fw-semibold text-success mb-2">Status recorded by the CEIT Dean's Office</p>
                        <?php if ($batch['receivedPaperCount'] !== $expectedCount): ?>
                            <?php $countDifference = (int)$batch['receivedPaperCount'] - (int)$expectedCount; ?>
                            <p class="mb-1 text-danger">Count discrepancy: <strong><?php echo number_format(abs($countDifference)); ?> <?php echo $countDifference < 0 ? 'fewer' : 'more'; ?> than expected</strong></p>
                        <?php endif; ?>
                        <p class="mb-0">Status updates are recorded by the office. Tap Refresh status to see the latest.</p>
                        <?php if (trim((string)$batch['intakeNotes']) !== ''): ?><div class="receipt-notes"><strong>Condition / count note</strong><br><?php echo nl2br(sanitize($batch['intakeNotes'])); ?></div><?php endif; ?>
                    </div>
                    <div class="receipt-signoff">
                        <div>CEIT Dean's Office representative</div>
                        <div>Submitter's copy</div>
                    </div>
                    <p class="receipt-print-meta">Generated <?php echo sanitize(date('F j, Y \a\t g:i A')); ?> · Receipt reference <?php echo sanitize($batch['code']); ?> · Verify using the official bulk tracking link.</p>
                    <button type="button" class="btn btn-success mt-3 no-print" onclick="window.location.reload()">Refresh status</button>
                    <button type="button" class="btn btn-outline-success mt-3 ms-1 no-print" onclick="window.print()">Print / save receipt</button>
                <?php else: ?>
                    <div class="rounded-3 border border-warning bg-warning-subtle p-3">
                        <strong>Waiting for office count confirmation</strong>
                        <p class="mb-0 mt-1 small">The count above was submitted by the sender and has not yet been verified by staff.</p>
                    </div>
                <?php endif; ?>
                <p class="small text-secondary mt-4 mb-0 no-print">Contact the CEIT Dean's Office for assistance with this bulk submission.</p>
            <?php endif; ?>
            <!-- Hidden until the script finds a safe place to return to; a bare "./" can point at a folder with no page (403 Forbidden). -->
            <a class="btn btn-outline-success mt-3 no-print" id="returnLink" href="#" hidden>Return</a>
        </div>
    </div>
</main>
<script>
(function () {
    const link = document.getElementById('returnLink');
    if (!link) return;
    const sameHost = (value) => {
        if (!value) return null;
        try {
            const url = new URL(value, window.location.href);
            if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.hostname !== window.location.hostname) return null;
            if (window.location.protocol === 'https:' && url.protocol === 'http:' && url.port === '') url.protocol = 'https:';
            return url;
        } catch (error) {
            return null;
        }
    };
    const requested = sameHost(new URLSearchParams(window.location.search).get('return') || '');
    const referrer = document.referrer ? sameHost(document.referrer) : null;
    const target = requested || (referrer && !/tracker/i.test(referrer.pathname) ? referrer : null);
    if (target) {
        link.href = target.href;
        link.hidden = false;
    } else if (window.location.port === '5173') {
        link.href = '/';
        link.hidden = false;
    } else if (window.history.length > 1) {
        // No known page to go back to (for example, opened from a QR scanner): use the browser's own Back.
        link.addEventListener('click', function (event) {
            event.preventDefault();
            window.history.back();
        });
        link.hidden = false;
    }
})();
</script>
</body>
</html>