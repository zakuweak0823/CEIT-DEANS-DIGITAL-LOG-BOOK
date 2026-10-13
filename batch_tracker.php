<?php
require_once 'auth.php';
require_once 'db.php';

header('X-Robots-Tag: noindex, nofollow');
$trackingToken = trim($_GET['token'] ?? '');
$batch = null;

// The opaque token is the public capability; expose only aggregate batch details, never paper records.
if (preg_match('/^[a-f0-9]{64}$/', $trackingToken)) {
    $stmt = safe_query(
        $conn,
        "SELECT batch_code, batch_type, status, is_priority, batch_date, paper_count
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
    <?php if ($batch !== null): ?><meta http-equiv="refresh" content="60"><?php endif; ?>
    <title>Batch Paper Tracker | CEIT Dean's Office</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { min-height: 100vh; background: #f0fdf4; color: #064e3b; }
        .tracker-card { width: min(100%, 44rem); border-color: #bbf7d0; border-radius: 1.25rem; }
    </style>
</head>
<body>
<main class="container py-4 py-md-5">
    <div class="tracker-card card shadow-sm mx-auto">
        <div class="card-body p-4 p-md-5">
            <p class="small fw-semibold text-uppercase text-success mb-2">CEIT Dean's Office</p>
            <?php if ($batch === null): ?>
                <h1 class="h3 text-success">Batch not found</h1>
                <p class="mb-0 text-secondary">This tracking link is invalid or no longer available. Please ask the office for an updated QR code.</p>
            <?php else: ?>
                <h1 class="h3 text-success">Batch paper tracker</h1>
                <p class="text-secondary mb-4">Current batch details and tracking status. This page refreshes every minute.</p>
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
                <div class="rounded-3 border bg-light p-3">
                    <span class="text-secondary">Total papers</span>
                    <strong class="ms-2"><?php echo (int)$batch['paperCount']; ?></strong>
                </div>
                <p class="small text-secondary mt-4 mb-0">Contact the CEIT Dean's Office for assistance with this batch.</p>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
