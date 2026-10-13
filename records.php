<?php
require_once 'auth.php';
require_once 'db.php';
ensure_admin();

$search = trim($_GET['search'] ?? '');

// Bind the same search term per field so user input remains data, never SQL syntax.
if ($search !== '') {
    $searchTerm = "%{$search}%";
    $stmt = mysqli_prepare($conn, "SELECT * FROM documents WHERE type LIKE ? OR sender LIKE ? OR recipient LIKE ? OR subject LIKE ? OR status LIKE ? ORDER BY date_created DESC");
    mysqli_stmt_bind_param($stmt, 'sssss', $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
} else {
    $stmt = mysqli_prepare($conn, "SELECT * FROM documents ORDER BY date_created DESC");
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
?>
<?php include 'header.php'; ?>
<div class="row justify-content-center">
    <div class="col-12">
        <div class="card shadow-sm mb-4">
            <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                <div>
                    <h2 class="card-title mb-2 text-primary">Document Records</h2>
                    <p class="text-muted mb-0">Browse incoming and outgoing documents with search support.</p>
                </div>
                <form method="GET" class="d-flex gap-2">
                    <input type="search" name="search" class="form-control" placeholder="Search documents" value="<?php echo sanitize($search); ?>">
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Sender</th>
                        <th>Recipient</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Date Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && mysqli_num_rows($result) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($result)): ?>
                            <tr>
                                <td><?php echo sanitize($row['type']); ?></td>
                                <td><?php echo sanitize($row['sender']); ?></td>
                                <td><?php echo sanitize($row['recipient']); ?></td>
                                <td><?php echo sanitize($row['subject']); ?></td>
                                <td><?php echo sanitize($row['status']); ?></td>
                                <td><?php echo sanitize($row['date_created']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">No document records found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
