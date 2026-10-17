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
        "SELECT batch_code, batch_type, status, is_priority, batch_date, paper_count, intake_notes,
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
        .tracker-card { width: min(100%, 44rem); border-color: #bbf7d0; border-radius: 1.25rem; }
        @media print {
            body { min-height: 0; background: #fff; color: #111; }
            .container { padding: 0 !important; }
            .tracker-card { width: 100%; border: 1px solid #999; box-shadow: none !important; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
<main class="container py-4 py-md-5">
    <div class="tracker-card card shadow-sm mx-auto">
        <div class="card-body p-4 p-md-5">
            <p class="small fw-semibold text-uppercase text-success mb-2">CEIT Dean's Office</p>
            <?php if ($storageError): ?>
                <h1 class="h3 text-danger">Tracker temporarily unavailable</h1>
                <p class="mb-0 text-secondary">The batch tracker could not load its storage. Please try again later or contact the office.</p>
            <?php elseif ($batch === null): ?>
                <h1 class="h3 text-success">Batch not found</h1>
                <p class="mb-0 text-secondary">This tracking link is invalid or no longer available. Please ask the office for an updated QR code.</p>
            <?php else: ?>
                <h1 class="h3 text-success"><?php echo $batch['receivedAt'] !== null ? 'Bulk paper receipt' : 'Batch paper tracker'; ?></h1>
                <p class="text-secondary mb-4 no-print">Current batch details and tracking status. This page refreshes every minute.</p>
                <dl class="row small mb-4">
                    <dt class="col-5 col-sm-4">Batch</dt>
                    <dd class="col-7 col-sm-8"><?php echo sanitize($batch['code']); ?></dd>
                    <dt class="col-5 col-sm-4">Type</dt>
                    <dd class="col-7 col-sm-8"><?php echo sanitize($batch['type']); ?></dd>
                    <dt class="col-5 col-sm-4">Status</dt>
                    <dd class="col-7 col-sm-8"><span class="badge <?php echo $batch['status'] === 'Released' ? 'text-bg-success' : ($batch['status'] === 'For Release' ? 'text-bg-primary' : 'text-bg-warning'); ?>"><?php echo sanitize($batch['status']); ?></span><?php echo $batch['isPriority'] ? ' <span class="badge text-bg-warning">Priority</span>' : ''; ?></dd>
                    <dt class="col-5 col-sm-4">Date recorded</dt>
                    <dd class="col-7 col-sm-8"><?php echo sanitize(date('M j, Y', strtotime($batch['date']))); ?></dd>
                </dl>
                <div class="rounded-3 border bg-light p-3 mb-3">
                    <span class="text-secondary">Expected papers</span>
                    <strong class="ms-2"><?php echo $batch['intakeExpectedCount'] !== null ? $batch['intakeExpectedCount'] : $batch['paperCount']; ?></strong>
                </div>
                <?php if ($batch['receivedAt'] !== null): ?>
                    <div class="rounded-3 border border-success bg-success-subtle p-3">
                        <p class="fw-semibold text-success mb-2">Office receipt confirmed</p>
                        <p class="mb-1">Papers physically received: <strong><?php echo $batch['receivedPaperCount']; ?></strong></p>
                        <?php if ($batch['receivedPaperCount'] !== $batch['intakeExpectedCount']): ?>
                            <?php $countDifference = $batch['receivedPaperCount'] - $batch['intakeExpectedCount']; ?>
                            <p class="mb-1 text-danger">Count difference: <strong><?php echo abs($countDifference); ?> <?php echo $countDifference < 0 ? 'fewer' : 'more'; ?> than expected</strong></p>
                        <?php else: ?>
                            <p class="mb-1">Count check: <strong>Matches expected</strong></p>
                        <?php endif; ?>
                        <p class="mb-1">Received at: <strong><?php echo sanitize(date('M j, Y g:i A', strtotime($batch['receivedAt']))); ?></strong></p>
                        <?php if ($batch['receivedBy'] !== ''): ?><p class="mb-0">Received by: <strong><?php echo sanitize($batch['receivedBy']); ?></strong></p><?php endif; ?>
                        <?php if (trim((string)$batch['intakeNotes']) !== ''): ?><p class="mt-2 mb-0 border-top pt-2">Receipt note: <?php echo nl2br(sanitize($batch['intakeNotes'])); ?></p><?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-success mt-3 no-print" onclick="window.print()">Print / save receipt</button>
                <?php else: ?>
                    <div class="rounded-3 border border-warning bg-warning-subtle p-3">
                        <strong>Waiting for office count confirmation</strong>
                        <p class="mb-0 mt-1 small">The count above was submitted by the sender and has not yet been verified by staff.</p>
                    </div>
                <?php endif; ?>
                <p class="small text-secondary mt-4 mb-0 no-print">Contact the CEIT Dean's Office for assistance with this batch.</p>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
