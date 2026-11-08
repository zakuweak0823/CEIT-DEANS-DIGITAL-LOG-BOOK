</main>
<?php
// header.php defines these; defaults keep the footer safe if it is ever included on its own.
$adminName = $adminName ?? null;
$isLoginPage = $isLoginPage ?? false;
?>
<?php if ($adminName): ?>
    <footer class="admin-footer" role="contentinfo">
        <div class="admin-footer-inner">
            <div class="admin-footer-brand">
                <span class="admin-footer-mark" aria-hidden="true"><i class="bi bi-journal-check"></i></span>
                <span class="admin-footer-title" title="Cavite State University - College of Engineering and Information Technology Dean's Office Digital Logbook">&copy; <?php echo date('Y'); ?> Cavite State University <span aria-hidden="true">&middot;</span> CEIT Dean's Office</span>
            </div>
            <div class="admin-footer-status" data-network-status role="status" aria-live="polite" aria-atomic="true">
                <span class="admin-footer-status-indicator" aria-hidden="true"><i class="bi bi-wifi"></i></span>
                <span data-network-status-label>Checking...</span>
            </div>
        </div>
    </footer>
    </div>
</div>
<?php else: ?>
<footer class="bg-white border-top py-3<?php echo $isLoginPage ? ' login-page-footer' : ''; ?>">
    <div class="container text-center small text-muted">
        &copy; <?php echo date('Y'); ?> Cavite State University - CEIT Deans Office Digital Logbook. Admin web interface only.
    </div>
</footer>
<?php endif; ?>
<?php if ($adminName): ?>
<div class="modal fade" id="statusDetailsModal" tabindex="-1" aria-labelledby="statusDetailsTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" id="statusDetailsForm" data-admin-confirm="false">
            <div class="modal-header">
                <h5 class="modal-title" id="statusDetailsTitle">Update document status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="statusDetailsSummary" class="text-muted"></p>
                <label for="statusDetailsNote" class="form-label fw-semibold">What happened or where is the document going?</label>
                <textarea class="form-control" id="statusDetailsNote" name="status_note" rows="3" maxlength="255" required placeholder="For example: Forwarded to the Registrar for review."></textarea>
                <p class="form-text mb-0">These details appear in tracking history and can be seen by anyone with the tracking link. Do not include confidential information.</p>
                <div id="statusDetailsFields"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save status</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/status-details.js?v=<?php echo (int)@filemtime(dirname(__DIR__) . '/assets/js/status-details.js'); ?>"></script>
<script src="assets/js/admin-tables.js?v=<?php echo (int)@filemtime(dirname(__DIR__) . '/assets/js/admin-tables.js'); ?>"></script>
<script src="assets/js/admin-status-menus.js?v=<?php echo (int)@filemtime(dirname(__DIR__) . '/assets/js/admin-status-menus.js'); ?>"></script>
<script src="assets/js/admin-pagination.js?v=<?php echo (int)@filemtime(dirname(__DIR__) . '/assets/js/admin-pagination.js'); ?>"></script>
<script src="assets/js/admin-cell-clamp.js?v=<?php echo (int)@filemtime(dirname(__DIR__) . '/assets/js/admin-cell-clamp.js'); ?>"></script>
<script src="assets/js/admin-table-layout.js?v=<?php echo (int)@filemtime(dirname(__DIR__) . '/assets/js/admin-table-layout.js'); ?>"></script>
<script src="assets/js/admin-workspace.js?v=<?php echo (int)@filemtime(dirname(__DIR__) . '/assets/js/admin-workspace.js'); ?>"></script>
</body>
</html>
