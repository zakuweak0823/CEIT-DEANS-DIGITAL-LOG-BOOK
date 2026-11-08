<?php
require_once 'auth.php';
require_once 'db.php';
ensure_admin();

if ($conn->query("ALTER TABLE documents ADD COLUMN IF NOT EXISTS entry_source VARCHAR(32) NOT NULL DEFAULT 'standard'") === false) {
    error_log('Quick Submit tracking setup failed: ' . $conn->error);
    http_response_code(500);
    exit('Quick Submit tracking is unavailable. Check the database setup and try again.');
}
foreach ([
    'ALTER TABLE documents ADD COLUMN IF NOT EXISTS is_priority TINYINT(1) NOT NULL DEFAULT 0',
] as $schemaSql) {
    if ($conn->query($schemaSql) === false) {
        error_log('Quick Submit display setup failed: ' . $conn->error);
        http_response_code(500);
        exit('Quick Submit records are unavailable. Check the database setup and try again.');
    }
}

// The explicit source marker separates Quick Submit records from other incoming documents.
$search = trim($_GET['search'] ?? '');
$quickDocuments = [];
$query = "SELECT doc_id, date_created, type, sender, recipient, subject, status, is_priority
          FROM documents WHERE entry_source = 'quick_submit'";
$params = [];
$types = '';
if ($search !== '') {
    $searchTerm = '%' . $search . '%';
    $query .= ' AND (sender LIKE ? OR recipient LIKE ? OR subject LIKE ? OR status LIKE ?)';
    $types = 'ssss';
    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
}
$query .= " ORDER BY (status = 'Released') ASC, CASE WHEN status <> 'Released' THEN date_created END ASC, date_created DESC";
$stmt = safe_query($conn, $query, $types, $params);
if ($stmt === false) {
    http_response_code(500);
    exit('Quick Submit records could not be loaded. Please try again later.');
}
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $quickDocuments[] = $row;
}
mysqli_stmt_close($stmt);
?>
<?php include 'header.php'; ?>
<div class="row g-4">
    <div class="col-12">
        <div class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-3">
            <div class="text-center text-md-start">
                <p class="quick-document-eyebrow mb-1"><i class="bi bi-lightning-charge-fill me-1" aria-hidden="true"></i>Mobile submissions</p>
                <h2 class="mb-1 text-primary">Quick Submit Records</h2>
                <p class="mb-0 text-muted">Only documents created with the mobile UI&apos;s Quick Submit button appear here.</p>
            </div>
            <a class="btn btn-outline-primary" href="incoming"><i class="bi bi-list-ul me-2" aria-hidden="true"></i>View All Incoming Documents</a>
        </div>
    </div>
    <div class="col-12">
        <div class="quick-document-storage-note" role="note">
            <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
            <span>These are incoming document records, stored with the rest of the incoming documents. This page only filters records submitted through <strong>Quick Submit</strong>; regular incoming submissions are not included. Tracking applies to submissions made after this feature was added.</span>
        </div>
    </div>
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="GET" class="row g-2 mb-3">
                    <div class="col-12 col-md-10">
                        <label class="visually-hidden" for="quickDocumentSearch">Search Quick Submit records</label>
                        <input type="search" id="quickDocumentSearch" name="search" class="form-control" placeholder="Search Quick Submit records" value="<?php echo sanitize($search); ?>">
                    </div>
                    <div class="col-12 col-md-2 d-grid">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-search me-2" aria-hidden="true"></i>Search</button>
                    </div>
                </form>
                <!-- Use the shared row-separated table skin consistently across admin lists. -->
                <div class="table-responsive">
                    <table class="table align-middle mb-0 quick-submit-table">
                        <thead class="table-light">
                            <tr>
                                <th>Date Submitted</th>
                                <th>From</th>
                                <th>Document Type</th>
                                <th>Recipient</th>
                                <th>Current Section</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($quickDocuments) === 0): ?>
                                <tr><td colspan="8" class="text-center text-muted py-4">No mobile Quick Submit records<?php echo $search !== '' ? ' match this search' : ' yet'; ?>.</td></tr>
                            <?php else: ?>
                                <?php foreach ($quickDocuments as $document): ?>
                                    <tr>
                                        <td><?php echo sanitize($document['date_created']); ?></td>
                                        <td><?php echo sanitize($document['sender']); ?></td>
                                        <td><?php echo sanitize($document['subject']); ?></td>
                                        <td><?php echo sanitize($document['recipient']); ?></td>
                                        <td><?php echo sanitize($document['type']); ?></td>
                                        <td><span class="badge <?php echo admin_status_badge_class($document['status']); ?>"><?php echo sanitize($document['status']); ?></span></td>
                                        <td>
                                            <?php if ((int)$document['is_priority'] === 1): ?>
                                                <span class="badge rounded-pill text-bg-warning"><i class="bi bi-star-fill me-1" aria-hidden="true"></i>Priority</span>
                                            <?php else: ?>
                                                <span class="badge rounded-pill text-bg-light border">Standard</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="table-actions document-row-actions">
                                                <?php // Route by the record's current type; destination pages filter by this exact ID. ?>
                                                <?php $managePage = $document['type'] === 'Outgoing' ? 'outgoing' : 'incoming'; ?>
                                                <a class="btn btn-sm btn-outline-primary document-action-button" href="<?php echo $managePage; ?>?document_id=<?php echo (int)$document['doc_id']; ?>" aria-label="Manage Quick Submit record <?php echo (int)$document['doc_id']; ?>" title="Open in <?php echo $managePage === 'outgoing' ? 'Outgoing' : 'Incoming'; ?> Documents">
                                                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span class="action-label">Manage</span>
                                                </a>
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
<?php include 'footer.php'; ?>
