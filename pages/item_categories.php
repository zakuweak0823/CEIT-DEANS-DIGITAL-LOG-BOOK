<?php
require_once 'auth.php';
require_once 'db.php';
ensure_admin();

// Make the database connection available explicitly for static analysis and queries.
$conn = $GLOBALS['conn'] ?? null;

$message = '';
$showCategoryModal = false;

if (isset($_POST['add_category'])) {
    $category_name = trim($_POST['category_name'] ?? '');
    if ($category_name === '') {
        $message = show_alert('Category name is required.', 'danger');
        $showCategoryModal = true;
    } else {
        $stmt = safe_query($conn, "INSERT INTO item_categories (category_name) VALUES (?)", 's', [$category_name]);
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
    $category_id = (int)$_POST['category_id'];
    // A category can be referenced by existing loans, so database constraints decide if deletion is safe.
    $stmt = safe_query($conn, "DELETE FROM item_categories WHERE category_id = ?", 'i', [$category_id]);
    if ($stmt !== false) {
        mysqli_stmt_close($stmt);
        $message = show_alert('Category deleted successfully.');
    } else {
        $message = show_alert('Error deleting category.', 'danger');
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

<?php include 'header.php'; ?>
<div class="row gy-4">
    <div class="col-12">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-3">
            <div>
                <h2 class="mb-1 text-primary">Item Categories</h2>
                <p class="text-muted mb-0">Manage categories for borrowed items.</p>
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
                                <span><?php echo sanitize($category['category_name']); ?></span>
                                <form method="POST" class="d-inline-flex">
                                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                    <input type="hidden" name="category_id" value="<?php echo $category['category_id']; ?>">
                                    <button type="submit" name="delete_category" class="btn btn-sm btn-outline-danger" data-confirm-title="Delete this category?" data-confirm-message="This will permanently remove the category. Borrowed item records that use it may no longer show a category." data-confirm-button="Delete category">Delete</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const addModal = document.getElementById('addCategoryModal');
    if (addModal.dataset.openOnLoad === 'true') {
        new bootstrap.Modal(addModal).show();
    }
});
</script>

<?php include 'footer.php'; ?>