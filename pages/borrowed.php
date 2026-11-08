<?php
require_once 'auth.php';
require_once 'db.php';
ensure_admin();

$conn->query('ALTER TABLE borrowed_items ADD COLUMN IF NOT EXISTS borrower_phone VARCHAR(20) DEFAULT NULL');

$message = '';
$item = '';
$borrower = '';
$borrower_phone = '';
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'All';
if (!in_array($statusFilter, ['All', 'Unreturned', 'Returned'], true)) {
    $statusFilter = 'All';
}
$showAddModal = false;

$categories = [];
$stmt = safe_query($conn, "SELECT * FROM item_categories ORDER BY category_name");
if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $categories[] = $row;
    }
    mysqli_stmt_close($stmt);
}

if (isset($_POST['save'])) {
    $item = trim($_POST['item'] ?? '');
    $borrower = trim($_POST['borrower'] ?? '');
    $borrower_phone = contact_number_input($_POST['borrower_phone'] ?? '');
    $category_raw = trim($_POST['category_id'] ?? '');
    $category_id = ($category_raw === '' ? null : (int)$category_raw);
    if ($category_id !== null && $category_id <= 0) {
        $category_id = null;
    }

    if ($item === '' || $borrower === '') {
        $message = show_alert('Item name and borrower are required.', 'danger');
        $showAddModal = true;
    } elseif ($borrower_phone === null || ($borrower_phone !== '' && !is_valid_contact_number($borrower_phone))) {
        $message = show_alert('Enter an 11-digit contact number using digits only, or leave it blank.', 'danger');
        $showAddModal = true;
    } else {
        $phoneValue = $borrower_phone === '' ? null : $borrower_phone;
        $stmt = safe_query($conn, "INSERT INTO borrowed_items(item_name, category_id, borrower, borrower_phone, status) VALUES(?, ?, ?, ?, 'Borrowed')", 'siss', [$item, $category_id, $borrower, $phoneValue]);
        if ($stmt !== false) {
            mysqli_stmt_close($stmt);
            $message = show_alert('Borrowed item recorded successfully.');
            $item = $borrower = $borrower_phone = '';
        } else {
            $message = show_alert('Failed to save the borrowed item.', 'danger');
            $showAddModal = true;
        }
    }
}

if (isset($_POST['update'])) {
    $id = (int)($_POST['id'] ?? 0);
    $item = trim($_POST['item'] ?? '');
    $borrower = trim($_POST['borrower'] ?? '');
    $borrower_phone = contact_number_input($_POST['borrower_phone'] ?? '');
    $category_raw = trim($_POST['category_id'] ?? '');
    $category_id = ($category_raw === '' ? null : (int)$category_raw);
    if ($category_id !== null && $category_id <= 0) {
        $category_id = null;
    }

    if ($id > 0 && $item !== '' && $borrower !== '' && ($borrower_phone === '' || is_valid_contact_number($borrower_phone))) {
        $phoneValue = $borrower_phone === '' ? null : $borrower_phone;
        $stmt = safe_query($conn, "UPDATE borrowed_items SET item_name = ?, category_id = ?, borrower = ?, borrower_phone = ?, status = 'Borrowed' WHERE item_id = ? AND status != 'Returned'", 'sissi', [$item, $category_id, $borrower, $phoneValue, $id]);
        if ($stmt !== false) {
            $updated = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            $stillEditable = $updated > 0 || safe_scalar($conn, "SELECT COUNT(*) FROM borrowed_items WHERE item_id = ? AND status != 'Returned'", 'i', [$id]) > 0;
            $message = $stillEditable
                ? show_alert('Borrowed item updated successfully.')
                : show_alert('Returned items cannot be edited.', 'warning');
        } else {
            $message = show_alert('Failed to update the borrowed item.', 'danger');
        }
    } else {
        $message = show_alert($borrower_phone === null || ($borrower_phone !== '' && !is_valid_contact_number($borrower_phone))
            ? 'Enter an 11-digit contact number using digits only, or leave it blank.'
            : 'Check the item name, borrower, and record details.', 'danger');
    }
}

if (isset($_POST['mark_returned'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        // Keep the first recorded return date if this action is repeated or retried.
        $stmt = safe_query($conn, "UPDATE borrowed_items SET status = 'Returned', returned_date = COALESCE(returned_date, CURDATE()) WHERE item_id = ? AND status != 'Returned'", 'i', [$id]);
        if ($stmt !== false) {
            $updated = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            $message = $updated > 0
                ? show_alert('Item marked as returned.')
                : show_alert('This item was already returned or could not be found.', 'warning');
        } else {
            $message = show_alert('Failed to update the item return status.', 'danger');
        }
    } else {
        $message = show_alert('Invalid borrowed item.', 'danger');
    }
}

if (isset($_POST['mark_unreturned'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        // Reopening a return clears its date so the item again counts as outstanding.
        $stmt = safe_query($conn, "UPDATE borrowed_items SET status = 'Borrowed', returned_date = NULL WHERE item_id = ? AND status = 'Returned'", 'i', [$id]);
        if ($stmt !== false) {
            $updated = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            $message = $updated > 0
                ? show_alert('Item marked as unreturned.')
                : show_alert('This item was already unreturned or could not be found.', 'warning');
        } else {
            $message = show_alert('Failed to update the item return status.', 'danger');
        }
    } else {
        $message = show_alert('Invalid borrowed item.', 'danger');
    }
}

$borrowedItems = [];
// Normalize legacy/non-returned status values for the UI's two-state filter.
$statusExpression = "CASE WHEN bi.status = 'Returned' THEN 'Returned' ELSE 'Unreturned' END";
$statusCondition = $statusFilter === 'All' ? '' : " AND {$statusExpression} = ?";
$itemIdParam = $_GET['item_id'] ?? '';
$itemIdFilter = is_string($itemIdParam) && ctype_digit($itemIdParam) ? (int)$itemIdParam : 0;
if ($itemIdFilter > 0) {
    // The dashboard's Review action must show only the selected borrowed item.
    $stmt = safe_query($conn, "SELECT bi.*, ic.category_name, {$statusExpression} AS display_status FROM borrowed_items bi LEFT JOIN item_categories ic ON bi.category_id = ic.category_id WHERE bi.item_id = ?", 'i', [$itemIdFilter]);
} elseif ($search !== '') {
    $searchTerm = "%{$search}%";
    $query = "SELECT bi.*, ic.category_name, {$statusExpression} AS display_status FROM borrowed_items bi LEFT JOIN item_categories ic ON bi.category_id = ic.category_id WHERE (bi.item_name LIKE ? OR bi.borrower LIKE ? OR bi.status LIKE ? OR ic.category_name LIKE ?){$statusCondition} ORDER BY bi.date_borrowed DESC";
    $types = 'ssss' . ($statusFilter === 'All' ? '' : 's');
    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
    if ($statusFilter !== 'All') {
        $params[] = $statusFilter;
    }
    $stmt = safe_query($conn, $query, $types, $params);
} else {
    $query = "SELECT bi.*, ic.category_name, {$statusExpression} AS display_status FROM borrowed_items bi LEFT JOIN item_categories ic ON bi.category_id = ic.category_id";
    if ($statusFilter !== 'All') {
        $query .= " WHERE {$statusExpression} = ?";
        $stmt = safe_query($conn, $query . ' ORDER BY bi.date_borrowed DESC', 's', [$statusFilter]);
    } else {
        $stmt = safe_query($conn, $query . ' ORDER BY bi.date_borrowed DESC');
    }
}

if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $borrowedItems[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}
?>
<?php include 'header.php'; ?>
<div class="row g-4">
    <div class="col-12">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-3">
            <div>
                <h2 class="mb-1 text-primary">Borrowed Items</h2>
                <p class="text-muted mb-0">Track borrowed items and mark them as returned.</p>
            </div>
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#borrowedForm">
                <i class="bi bi-plus-lg"></i> Add Borrowed Item
            </button>
        </div>
    </div>
    <?php if ($itemIdFilter > 0): ?>
        <div class="col-12">
            <div class="selected-record-banner" role="status">
                <i class="bi bi-bullseye" aria-hidden="true"></i>
                <div>
                    <strong>Selected item</strong>
                    <span><?php echo $borrowedItems ? sanitize($borrowedItems[0]['item_name']) . ' · borrowed by ' . sanitize($borrowedItems[0]['borrower']) : 'This item could not be found.'; ?></span>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="borrowed">Show all borrowed items</a>
            </div>
        </div>
    <?php endif; ?>
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="GET" class="row g-2 mb-3 align-items-end">
                    <div class="col-12 col-md">
                        <label class="form-label small fw-semibold mb-1" for="borrowedSearch">Search borrowed items</label>
                        <input type="search" id="borrowedSearch" name="search" class="form-control" placeholder="Item, borrower, or category" value="<?php echo sanitize($search); ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-semibold mb-1" for="borrowedStatus">Status</label>
                        <select id="borrowedStatus" name="status" class="form-select">
                            <?php foreach (['All' => 'All items', 'Unreturned' => 'Not Returned', 'Returned' => 'Returned'] as $value => $label): ?>
                                <option value="<?php echo $value; ?>"<?php echo $statusFilter === $value ? ' selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-auto d-flex gap-2">
                        <button class="btn btn-primary flex-grow-1" type="submit"><i class="bi bi-funnel me-1"></i> Filter</button>
                        <?php if ($search !== '' || $statusFilter !== 'All'): ?>
                            <a class="btn btn-outline-secondary" href="borrowed">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
                <!-- Shared table styling keeps row separators without vertical cell gridlines. -->
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Item</th>
                                <th>Category</th>
                                <th>Borrower</th>
                                <th>Dates</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($borrowedItems) === 0): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No matching borrowed items. Try changing the search or status filter.</td></tr>
                            <?php else: ?>
                                <?php foreach ($borrowedItems as $itemRow): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo sanitize($itemRow['item_name']); ?></td>
                                        <td><?php echo sanitize($itemRow['category_name'] ?? 'Uncategorized'); ?></td>
                                        <td>
                                            <div class="fw-semibold"><?php echo sanitize($itemRow['borrower']); ?></div>
                                            <?php if (!empty($itemRow['borrower_phone'])): ?>
                                                <small class="text-muted"><?php echo sanitize($itemRow['borrower_phone']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small">
                                            <div><span class="text-muted">Date borrowed</span> <?php echo sanitize(date('M j, Y', strtotime($itemRow['date_borrowed']))); ?></div>
                                            <?php if (!empty($itemRow['returned_date'])): ?>
                                                <div class="text-success"><span>Returned</span> <?php echo sanitize(date('M j, Y', strtotime($itemRow['returned_date']))); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge rounded-pill <?php echo admin_status_badge_class($itemRow['display_status']); ?>"><?php echo sanitize($itemRow['display_status'] === 'Unreturned' ? 'Not Returned' : $itemRow['display_status']); ?></span>
                                        </td>
                                        <td>
                                            <div class="table-actions">
                                                <?php if ($itemRow['display_status'] !== 'Returned'): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="editItem(<?php echo (int)$itemRow['item_id']; ?>, <?php echo htmlspecialchars(json_encode($itemRow['item_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($itemRow['borrower'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($itemRow['borrower_phone'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo $itemRow['category_id'] !== null ? (int)$itemRow['category_id'] : 'null'; ?>)">
                                                        <i class="bi bi-pencil"></i> Edit
                                                    </button>
                                                    <form method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                        <input type="hidden" name="id" value="<?php echo (int)$itemRow['item_id']; ?>">
                                                        <button type="submit" name="mark_returned" class="btn btn-sm btn-success" data-confirm-title="Mark item as returned?" data-confirm-message="This records the item as returned and sets the return date to today." data-confirm-button="Mark Returned"><i class="bi bi-check2-circle me-1"></i>Mark Returned</button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                        <input type="hidden" name="id" value="<?php echo (int)$itemRow['item_id']; ?>">
                                                        <button type="submit" name="mark_unreturned" class="btn btn-sm btn-outline-secondary" data-confirm-title="Mark item as not returned?" data-confirm-message="This clears the return date and moves the item back to the not returned list." data-confirm-button="Mark Not Returned"><i class="bi bi-arrow-counterclockwise me-1"></i>Mark Not Returned</button>
                                                    </form>
                                                <?php endif; ?>
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
    <div class="modal fade" id="borrowedForm" tabindex="-1" aria-labelledby="borrowedFormTitle" aria-hidden="true" data-open-on-load="<?php echo $showAddModal ? 'true' : 'false'; ?>">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title text-primary" id="borrowedFormTitle">Add Borrowed Item</h5>
                        <p class="mb-0 mt-1 small text-muted">Record who has the item and any optional return date.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" novalidate>
                    <div class="modal-body">
                        <div class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                        <div class="col-12">
                        <div class="mb-3">
                            <label class="form-label" for="item">Item</label>
                            <input type="text" id="item" name="item" class="form-control" value="<?php echo sanitize($item); ?>" required>
                        </div>
                        </div>
                        <div class="col-12 col-sm-6">
                        <div class="mb-3">
                            <label class="form-label" for="category_id">Category</label>
                            <select id="category_id" name="category_id" class="form-select">
                                <option value="">Uncategorized</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo (int)$cat['category_id']; ?>"<?php echo isset($_POST['category_id']) && (int)$_POST['category_id'] === (int)$cat['category_id'] ? ' selected' : ''; ?>><?php echo sanitize($cat['category_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        </div>
                        <div class="col-12 col-sm-6">
                        <div class="mb-3">
                            <label class="form-label" for="borrower">Borrower Name</label>
                            <input type="text" id="borrower" name="borrower" class="form-control" value="<?php echo sanitize($borrower); ?>" required>
                        </div>
                        </div>
                        <div class="col-12 col-sm-6">
                        <div class="mb-3">
                            <label class="form-label" for="borrower_phone">Borrower Contact Number <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="tel" id="borrower_phone" name="borrower_phone" class="form-control" value="<?php echo sanitize($borrower_phone ?? ''); ?>" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" placeholder="09123456789" title="Enter exactly 11 digits.">
                            <div class="form-text">Enter exactly 11 digits, or leave blank.</div>
                        </div>
                        </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="save" class="btn btn-primary">Record Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Borrowed Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editBorrowedForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <input type="hidden" name="id" id="editId">
                    <div class="mb-3">
                        <label class="form-label" for="editItem">Item</label>
                        <input type="text" id="editItem" name="item" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="editCategory">Category</label>
                        <select id="editCategory" name="category_id" class="form-select">
                            <option value="">Uncategorized</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['category_id']; ?>"><?php echo sanitize($cat['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="editBorrower">Borrower Name</label>
                        <input type="text" id="editBorrower" name="borrower" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="editBorrowerPhone">Borrower Contact Number <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="tel" id="editBorrowerPhone" name="borrower_phone" class="form-control" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" placeholder="09123456789" title="Enter exactly 11 digits.">
                        <div class="form-text">Enter exactly 11 digits, or leave blank.</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="update" form="editBorrowedForm" class="btn btn-primary">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<script>
function editItem(id, item, borrower, borrowerPhone, categoryId) {
    document.getElementById('editId').value = id;
    document.getElementById('editItem').value = item;
    document.getElementById('editBorrower').value = borrower;
    document.getElementById('editBorrowerPhone').value = borrowerPhone;
    document.getElementById('editCategory').value = categoryId || '';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

document.addEventListener('DOMContentLoaded', function () {
    const addModal = document.getElementById('borrowedForm');
    if (addModal.dataset.openOnLoad === 'true') {
        new bootstrap.Modal(addModal).show();
    }
});
</script>

<?php include 'footer.php'; ?>
