<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit_log.php';
ensure_admin_role();
header('Cache-Control: no-store');

$auditRows = [];
$stmt = safe_query(
    $conn,
    "SELECT a.created_at, a.actor, a.action,
            CASE
                WHEN a.action IN ('staff_email_changed', 'staff_recovery_phone_changed')
                    AND a.target REGEXP '^[0-9]+$'
                    THEN COALESCE(affected_user.username, CONCAT('Account #', a.target))
                ELSE a.target
            END AS account,
            a.details, a.ip_address
     FROM audit_logs a
     LEFT JOIN users affected_user
        ON a.action IN ('staff_email_changed', 'staff_recovery_phone_changed')
        AND a.target REGEXP '^[0-9]+$'
        AND affected_user.user_id = CAST(a.target AS UNSIGNED)
     ORDER BY a.audit_id DESC LIMIT 300"
);
if ($stmt === false) {
    http_response_code(500);
    exit('System audit logs could not be loaded. Check the server log and try again.');
}
$result = mysqli_stmt_get_result($stmt);
if ($result === false) {
    mysqli_stmt_close($stmt);
    http_response_code(500);
    exit('System audit logs could not be read. Check the server log and try again.');
}
while ($row = mysqli_fetch_assoc($result)) {
    $auditRows[] = $row;
}
mysqli_stmt_close($stmt);

$statusRows = [];
$stmt = safe_query(
    $conn,
    "SELECT e.created_at, e.actor, e.kind, e.status, e.note,
            COALESCE(d.local_reference, d.subject, b.batch_code, CONCAT('Record ', COALESCE(e.doc_id, e.batch_id))) AS record_label,
            CASE WHEN e.batch_id IS NULL THEN 'Document' ELSE 'Bulk submission' END AS record_type
     FROM document_events e
     LEFT JOIN documents d ON d.doc_id = e.doc_id
     LEFT JOIN document_batches b ON b.batch_id = e.batch_id
     ORDER BY e.event_id DESC LIMIT 300"
);
if ($stmt === false) {
    http_response_code(500);
    exit('Document status history could not be loaded. Check the server log and try again.');
}
$result = mysqli_stmt_get_result($stmt);
if ($result === false) {
    mysqli_stmt_close($stmt);
    http_response_code(500);
    exit('Document status history could not be read. Check the server log and try again.');
}
while ($row = mysqli_fetch_assoc($result)) {
    $statusRows[] = $row;
}
mysqli_stmt_close($stmt);
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="row gy-4">
    <div class="col-12">
        <h1 class="h2 mb-1 text-primary">System audit logs</h1>
        <p class="text-muted mb-0">Review recent sign-ins, account and password events, and document or bulk status history. The latest 300 entries are shown in each section.</p>
    </div>
    <div class="col-12">
        <section class="card shadow-sm" aria-labelledby="accountAuditTitle" aria-describedby="accountAuditHelp">
            <div class="card-header bg-white"><h2 class="h5 mb-0 text-primary" id="accountAuditTitle">Account and security activity</h2></div>
            <p class="audit-log-help" id="accountAuditHelp">Passwords, email addresses, and phone numbers are never shown here.</p>
            <div class="table-responsive audit-log-table-wrap">
            <table class="table table-striped table-hover align-middle mb-0 audit-log-table audit-account-table">
                <thead><tr><th scope="col">Date and time</th><th scope="col">User account</th><th scope="col">Action</th><th scope="col">IP Address</th></tr></thead>
                <tbody>
                <?php if ($auditRows === []): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No account or security activity has been logged yet.</td></tr>
                <?php else: foreach ($auditRows as $row): ?>
                    <?php $actionLabel = audit_action_label((string)$row['action'], (string)($row['details'] ?? '')); ?>
                    <tr>
                        <td class="audit-log-date"><?php echo sanitize(date('M j, Y g:i A', strtotime((string)$row['created_at']))); ?></td>
                        <td class="audit-log-account"><?php echo sanitize((string)($row['account'] ?? '—') !== '' ? (string)$row['account'] : '—'); ?></td>
                        <td class="audit-log-action"><?php echo sanitize($actionLabel); ?></td>
                        <?php $ipAddress = (string)$row['ip_address']; ?>
                        <td class="audit-log-ip"><span class="audit-log-ip-value"<?php echo in_array($ipAddress, ['::1', '127.0.0.1'], true) ? ' title="Local server loopback address"' : ''; ?>><?php echo sanitize(audit_ip_label($ipAddress)); ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-12">
        <section class="card shadow-sm" aria-labelledby="recordAuditTitle">
            <div class="card-header bg-white"><h2 class="h5 mb-0 text-primary" id="recordAuditTitle">Document and bulk status history</h2></div>
            <div class="table-responsive audit-log-table-wrap">
            <table class="table table-striped table-hover align-middle mb-0 audit-log-table audit-status-table" data-status-guide="incoming,outgoing">
                <thead><tr><th scope="col">Date and time</th><th scope="col">Performed by</th><th scope="col">Record type</th><th scope="col">Record</th><th scope="col">Action</th><th scope="col">Status</th><th scope="col">Details</th></tr></thead>
                    <tbody>
                    <?php if ($statusRows === []): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No document or bulk status events have been logged yet.</td></tr>
                    <?php else: foreach ($statusRows as $row): ?>
                        <tr>
                            <td class="audit-log-date"><?php echo sanitize(date('M j, Y g:i A', strtotime((string)$row['created_at']))); ?></td>
                            <td><?php echo sanitize((string)($row['actor'] ?? 'System')); ?></td>
                            <td><?php echo sanitize((string)$row['record_type']); ?></td>
                            <td class="audit-log-record"><?php echo sanitize((string)$row['record_label']); ?></td>
                            <td><?php echo sanitize(audit_status_event_label((string)$row['kind'])); ?></td>
                            <td><?php echo sanitize((string)$row['status']); ?></td>
                            <td><?php echo sanitize((string)($row['note'] ?? '') !== '' ? (string)$row['note'] : '—'); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
