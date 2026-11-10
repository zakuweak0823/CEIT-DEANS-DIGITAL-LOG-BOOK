<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/borrowed_inventory.php';
ensure_admin();

// Make the database connection available explicitly for static analysis and queries.
$conn = $GLOBALS['conn'] ?? null;
$messageType = 'success';
try {
    ensure_borrowed_inventory_schema($conn);
} catch (Throwable $error) {
    error_log('Borrowable inventory setup failed on Item Categories: ' . $error->getMessage());
    http_response_code(500);
    exit('Borrowable inventory is unavailable. Check the server log and try again.');
}

$message = '';
$showCategoryModal = false;

if (isset($_POST['add_category'])) {
    $category_name = trim($_POST['category_name'] ?? '');
    $requires_identifier = isset($_POST['requires_identifier']) && $_POST['requires_identifier'] === '1' ? 1 : 0;
    if ($category_name === '') {
        $message = show_alert('Category name is required.', 'danger');
        $showCategoryModal = true;
    } else {
        $stmt = safe_query($conn, "INSERT INTO item_categories (category_name, requires_identifier) VALUES (?, ?)", 'si', [$category_name, $requires_identifier]);
        if ($stmt !== false) {
            mysqli_stmt_close($stmt);
            $message = show_alert('Category added successfully.');
        } else {
            $message = show_alert('Error adding category.', 'danger');
            $showCategoryModal = true;
        }
    }
}

if (isset($_POST['delete_category'])) {
    $category_id = isset($_POST['category_id']) && is_string($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
    try {
        delete_borrowed_inventory_category($conn, $category_id);
        $message = show_alert('Category and its inventory were deleted. Past borrowing records were kept.');
    } catch (DomainException $error) {
        $messageType = 'warning';
        $message = show_alert($error->getMessage(), $messageType);
    } catch (Throwable $error) {
        error_log('Item category deletion failed: ' . $error->getMessage());
        $messageType = 'danger';
        $message = show_alert('The category could not be deleted. Check the server log and try again.', $messageType);
    }
}

$categories = [];
$stmt = safe_query($conn, "SELECT * FROM item_categories ORDER BY category_name");
if ($stmt !== false) {
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $categories[] = $row;
    }
    mysqli_stmt_close($stmt);
}
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="row gy-4">
    <div class="col-12">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-3">
            <div>
                <h2 class="mb-1 text-primary">Item Categories</h2>
                <p class="text-muted mb-0">Manage shared categories for the Borrowing &amp; Inventory module.</p>
            </div>
            <div class="col-12">
                <div class="alert alert-info mb-0">After adding a category, open <a class="alert-link" href="borrowed">Borrowing &amp; Inventory</a> to register individual items and set their availability.</div>
            </div>
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Category
            </button>
        </div>
    </div>

    <?php if ($message) echo $message; ?>

    <div class="modal fade" id="addCategoryModal" tabindex="-1" aria-labelledby="addCategoryModalTitle" aria-hidden="true" data-open-on-load="<?php echo $showCategoryModal ? 'true' : 'false'; ?>">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-primary" id="addCategoryModalTitle">Add New Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <div>
                        <label class="form-label" for="categoryName">Category Name</label>
                        <input type="text" id="categoryName" name="category_name" class="form-control" required>
                    </div>
                    <div class="form-check mt-3">
                        <input type="checkbox" id="categoryRequiresIdentifier" name="requires_identifier" value="1" class="form-check-input" checked>
                        <label class="form-check-label" for="categoryRequiresIdentifier">Ask for an identifier ID when registering items</label>
                    </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_category" class="btn btn-primary">Add Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <h5 class="card-title text-primary mb-4">Existing Categories</h5>
                <div class="list-group">
                    <?php if (empty($categories)): ?>
                        <div class="text-center text-muted py-4">No categories found.</div>
                    <?php else: ?>
                        <?php foreach ($categories as $category): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center gap-3">
                                <span><?php echo sanitize($category['category_name']); ?><?php if (!(int)$category['requires_identifier']): ?><small class="d-block text-muted">Identifier ID not required</small><?php endif; ?></span>
                                <form method="POST" class="d-inline-flex">
                                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                    <input type="hidden" name="category_id" value="<?php echo $category['category_id']; ?>">
                                    <?php if ((int)$category['is_default'] === 1): ?>
                                        <span class="text-muted">Default</span>
                                    <?php else: ?>
                                        <button type="submit" name="delete_category" class="btn btn-sm btn-outline-danger" data-confirm-title="Delete this category?" data-confirm-message="This will permanently remove the category. Borrow records that use it may no longer show a category." data-confirm-button="Delete category">Delete</button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/page-item-categories.js?v=<?php echo (int)@filemtime(dirname(__DIR__) . '/assets/js/page-item-categories.js'); ?>"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>