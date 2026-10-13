<?php
require_once 'auth.php';
require_once 'db.php';
ensure_admin();

if ($conn->query("ALTER TABLE documents ADD COLUMN IF NOT EXISTS entry_source VARCHAR(32) NOT NULL DEFAULT 'standard'") === false) {
    error_log('Quick Submit tracking setup failed: ' . $conn->error);
    http_response_code(500);
    exit('Quick Submit tracking is unavailable. Check the database setup and try again.');
}

// The explicit source marker separates Quick Submit records from other incoming documents.
$search = trim($_GET['search'] ?? '');
$quickDocuments = [];
$query = "SELECT doc_id, date_created, type, sender, recipient, subject, status
          FROM documents WHERE entry_source = 'quick_submit'";
$params = [];
$types = '';
if ($search !== '') {
    $searchTerm = '%' . $search . '%';
    $query .= ' AND (sender LIKE ? OR recipient LIKE ? OR subject LIKE ? OR status LIKE ?)';
    $types = 'ssss';
    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
}
$query .= ' ORDER BY date_created DESC';
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
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Date Submitted</th>
                                <th>From</th>
                                <th>Document Type</th>
                                <th>Recipient</th>
                                <th>Current Section</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($quickDocuments) === 0): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No mobile Quick Submit records<?php echo $search !== '' ? ' match this search' : ' yet'; ?>.</td></tr>
                            <?php else: ?>
                                <?php foreach ($quickDocuments as $document): ?>
                                    <tr>
                                        <td><?php echo sanitize($document['date_created']); ?></td>
                                        <td><?php echo sanitize($document['sender']); ?></td>
                                        <td><?php echo sanitize($document['subject']); ?></td>
                                        <td><?php echo sanitize($document['recipient']); ?></td>
                                        <td><?php echo sanitize($document['type']); ?></td>
                                        <td><span class="badge <?php echo $document['status'] === 'Pending' ? 'text-bg-warning' : ($document['status'] === 'For Release' ? 'text-bg-primary' : 'text-bg-success'); ?>"><?php echo sanitize($document['status']); ?></span></td>
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
