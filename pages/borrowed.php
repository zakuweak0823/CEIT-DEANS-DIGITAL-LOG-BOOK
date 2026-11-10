<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/borrowed_inventory.php';
ensure_admin();

try {
    ensure_borrowed_inventory_schema($conn);
} catch (Throwable $error) {
    error_log('Borrowable inventory setup failed: ' . $error->getMessage());
    http_response_code(500);
    exit('Borrowable inventory is unavailable. Check the server log and try again.');
}

$message = '';
$messageType = 'success';
$showBorrowingHistory = isset($_GET['view']) && $_GET['view'] === 'history';
$setMessage = static function (string $text, string $type = 'success') use (&$message, &$messageType): void {
    $message = $text;
    $messageType = $type;
};

if (!$showBorrowingHistory && isset($_POST['delete_category'])) {
    $categoryId = isset($_POST['category_id']) && is_string($_POST['category_id'])
        ? (int)$_POST['category_id']
        : 0;
    try {
        delete_borrowed_inventory_category($conn, $categoryId);
        $setMessage('Category and its inventory were deleted. Past borrowing records were kept.');
    } catch (DomainException $error) {
        $setMessage($error->getMessage(), 'warning');
    } catch (Throwable $error) {
        error_log('Borrowable inventory category deletion failed: ' . $error->getMessage());
        $setMessage('The category could not be deleted. Check the server log and try again.', 'danger');
    }
}

if (!$showBorrowingHistory && isset($_POST['add_category'])) {
    $categoryName = isset($_POST['category_name']) && is_string($_POST['category_name'])
        ? trim($_POST['category_name'])
        : '';
    $requiresIdentifier = isset($_POST['requires_identifier']) && $_POST['requires_identifier'] === '1';
    if ($categoryName === '' || strlen($categoryName) > 100) {
        $setMessage('Enter a category name of 1–100 characters.', 'danger');
    } else {
        $stmt = safe_query($conn, 'INSERT INTO item_categories (category_name, requires_identifier) VALUES (?, ?)', 'si', [$categoryName, (int)$requiresIdentifier]);
        if ($stmt === false) {
            $setMessage('That category could not be added. It may already exist.', 'danger');
        } else {
            mysqli_stmt_close($stmt);
            $setMessage('Inventory category added.');
        }
    }
}

if (!$showBorrowingHistory && (isset($_POST['add_inventory_item']) || isset($_POST['update_inventory_item']))) {
    $isUpdate = isset($_POST['update_inventory_item']);
    $inventoryId = (int)($_POST['inventory_id'] ?? 0);
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $itemName = isset($_POST['item_name']) && is_string($_POST['item_name']) ? trim($_POST['item_name']) : '';
    $identifierInput = isset($_POST['identifier']) && is_string($_POST['identifier']) ? trim($_POST['identifier']) : '';
    $validCategory = $categoryId > 0
        && (int)safe_scalar($conn, 'SELECT COUNT(*) FROM item_categories WHERE category_id = ?', 'i', [$categoryId]) === 1;
    $requiresIdentifier = $validCategory
        && (int)safe_scalar($conn, 'SELECT requires_identifier FROM item_categories WHERE category_id = ?', 'i', [$categoryId]) === 1;
    $identifier = $requiresIdentifier ? $identifierInput : null;
    if (($isUpdate && $inventoryId <= 0) || !$validCategory || $itemName === '' || strlen($itemName) > 255
        || ($requiresIdentifier && ($identifierInput === '' || strlen($identifierInput) > 100))) {
        $setMessage('Select a category, enter an item name, and provide an identifier when the category requires one.', 'danger');
    } elseif ($isUpdate) {
        $activeLoan = (int)safe_scalar(
            $conn,
            "SELECT COUNT(*) FROM borrowed_items WHERE inventory_id = ? AND status <> 'Returned'",
            'i',
            [$inventoryId]
        );
        if ($activeLoan > 0) {
            $setMessage('This item cannot be edited until it has been returned.', 'warning');
        } else {
            $stmt = safe_query(
                $conn,
                'UPDATE borrowable_inventory SET category_id = ?, item_name = ?, identifier = ? WHERE inventory_id = ?',
                'issi',
                [$categoryId, $itemName, $identifier, $inventoryId]
            );
            if ($stmt === false) {
                $setMessage('The inventory item could not be updated. Check that its identifier is unique in the category.', 'danger');
            } else {
                mysqli_stmt_close($stmt);
                $setMessage('Inventory item updated.');
            }
        }
    } else {
        $stmt = safe_query(
            $conn,
            "INSERT INTO borrowable_inventory (category_id, item_name, identifier, availability_status) VALUES (?, ?, ?, 'Available')",
            'iss',
            [$categoryId, $itemName, $identifier]
        );
        if ($stmt === false) {
            $setMessage('The item could not be added. Check that its identifier is unique in the category.', 'danger');
        } else {
            mysqli_stmt_close($stmt);
            $setMessage('Inventory item added and marked Available.');
        }
    }
}

if (!$showBorrowingHistory && isset($_POST['set_inventory_status'])) {
    $inventoryId = (int)($_POST['inventory_id'] ?? 0);
    $newStatus = isset($_POST['new_status']) && is_string($_POST['new_status']) ? $_POST['new_status'] : '';
    if ($inventoryId <= 0 || !in_array($newStatus, ['Available', 'Unavailable'], true)) {
        $setMessage('Choose a valid inventory item and status.', 'danger');
    } else {
        try {
            $conn->begin_transaction();
            $lockStmt = safe_query($conn, 'SELECT inventory_id FROM borrowable_inventory WHERE inventory_id = ? FOR UPDATE', 'i', [$inventoryId]);
            if ($lockStmt === false) {
                throw new RuntimeException('Could not verify inventory item.');
            }
            $lockedItem = mysqli_fetch_assoc(mysqli_stmt_get_result($lockStmt));
            mysqli_stmt_close($lockStmt);
            if (!$lockedItem) {
                throw new DomainException('Inventory item not found.');
            }
            $activeLoan = (int)safe_scalar(
                $conn,
                "SELECT COUNT(*) FROM borrowed_items WHERE inventory_id = ? AND status <> 'Returned'",
                'i',
                [$inventoryId]
            );
            if ($activeLoan > 0) {
                throw new DomainException('This item is currently with a borrower. Mark it returned before changing availability.');
            }
            $updateStmt = safe_query($conn, 'UPDATE borrowable_inventory SET availability_status = ? WHERE inventory_id = ?', 'si', [$newStatus, $inventoryId]);
            if ($updateStmt === false) {
                throw new RuntimeException('Could not update inventory availability.');
            }
            mysqli_stmt_close($updateStmt);
            $conn->commit();
            $setMessage('Inventory item marked ' . $newStatus . '.');
        } catch (Throwable $error) {
            $conn->rollback();
            if ($error instanceof DomainException) {
                $setMessage($error->getMessage(), 'warning');
            } else {
                error_log('Borrowable inventory status update failed: ' . $error->getMessage());
                $setMessage('Inventory availability could not be updated.', 'danger');
            }
        }
    }
}

if (!$showBorrowingHistory && isset($_POST['mark_inventory_returned'])) {
    $loanId = (int)($_POST['loan_id'] ?? 0);
    $inventoryId = (int)($_POST['inventory_id'] ?? 0);
    if ($loanId <= 0 || $inventoryId < 0) {
        $setMessage('Select a valid inventory item to return.', 'danger');
    } else {
        try {
            $conn->begin_transaction();
            if ($inventoryId > 0) {
                $inventoryLock = safe_query($conn, 'SELECT inventory_id FROM borrowable_inventory WHERE inventory_id = ? FOR UPDATE', 'i', [$inventoryId]);
                if ($inventoryLock === false) {
                    throw new RuntimeException('Could not verify the inventory item.');
                }
                $lockedInventory = mysqli_fetch_assoc(mysqli_stmt_get_result($inventoryLock));
                mysqli_stmt_close($inventoryLock);
                if (!$lockedInventory) {
                    throw new DomainException('This inventory item no longer exists.');
                }
                $loanStmt = safe_query(
                    $conn,
                    "SELECT item_id FROM borrowed_items WHERE item_id = ? AND inventory_id = ? AND status <> 'Returned' FOR UPDATE",
                    'ii',
                    [$loanId, $inventoryId]
                );
            } else {
                $loanStmt = safe_query(
                    $conn,
                    "SELECT loan.item_id
                     FROM borrowed_items loan
                     INNER JOIN item_categories category ON category.category_id = loan.category_id
                     WHERE loan.item_id = ? AND loan.inventory_id IS NULL AND category.is_default = 1
                       AND loan.status <> 'Returned'
                     FOR UPDATE",
                    'i',
                    [$loanId]
                );
            }
            if ($loanStmt === false) {
                throw new RuntimeException('Could not verify the active borrowing record.');
            }
            $activeLoan = mysqli_fetch_assoc(mysqli_stmt_get_result($loanStmt));
            mysqli_stmt_close($loanStmt);
            if (!$activeLoan) {
                throw new DomainException('This item is not currently marked as borrowed.');
            }
            $updateStmt = safe_query(
                $conn,
                "UPDATE borrowed_items SET status = 'Returned', returned_date = COALESCE(returned_date, CURDATE()) WHERE item_id = ?",
                'i',
                [$loanId]
            );
            if ($updateStmt === false) {
                throw new RuntimeException('Could not mark the item returned.');
            }
            mysqli_stmt_close($updateStmt);
            $conn->commit();
            $setMessage('Item marked returned. Its inventory availability is shown in the status column.');
        } catch (Throwable $error) {
            $conn->rollback();
            if ($error instanceof DomainException) {
                $setMessage($error->getMessage(), 'warning');
            } else {
                error_log('Borrowable inventory return failed: ' . $error->getMessage());
                $setMessage('The item could not be marked returned.', 'danger');
            }
        }
    }
}

$categories = [];
$categoryStmt = safe_query($conn, 'SELECT category_id, category_name, requires_identifier, is_default FROM item_categories ORDER BY is_default DESC, category_name');
if ($categoryStmt === false) {
    http_response_code(500);
    exit('Inventory categories could not be loaded.');
}
$categoryResult = mysqli_stmt_get_result($categoryStmt);
while ($row = mysqli_fetch_assoc($categoryResult)) {
    $categories[] = $row;
}
mysqli_stmt_close($categoryStmt);

$inventoryItems = [];
$inventoryStmt = safe_query(
    $conn,
    "SELECT asset.inventory_id, asset.category_id, category.category_name, asset.item_name, asset.identifier,
            asset.availability_status, 0 AS is_one_off,
            (SELECT loan.item_id FROM borrowed_items loan
             WHERE loan.inventory_id = asset.inventory_id AND loan.status <> 'Returned'
             ORDER BY loan.date_borrowed DESC LIMIT 1) AS active_loan_id,
            (SELECT loan.borrower FROM borrowed_items loan
             WHERE loan.inventory_id = asset.inventory_id AND loan.status <> 'Returned'
             ORDER BY loan.date_borrowed DESC LIMIT 1) AS active_borrower,
            CASE WHEN EXISTS (
                SELECT 1 FROM borrowed_items loan
                WHERE loan.inventory_id = asset.inventory_id AND loan.status <> 'Returned'
            ) THEN 'Borrowed' ELSE asset.availability_status END AS display_status
     FROM borrowable_inventory asset
     INNER JOIN item_categories category ON category.category_id = asset.category_id
     ORDER BY category.category_name, asset.item_name, asset.identifier"
);
if ($inventoryStmt === false) {
    http_response_code(500);
    exit('Inventory items could not be loaded.');
}
$inventoryResult = mysqli_stmt_get_result($inventoryStmt);
while ($row = mysqli_fetch_assoc($inventoryResult)) {
    $inventoryItems[] = $row;
}
mysqli_stmt_close($inventoryStmt);

$otherActiveStmt = safe_query(
    $conn,
    "SELECT loan.category_id, category.category_name, loan.item_name, loan.borrower AS active_borrower,
            loan.item_id AS active_loan_id, NULL AS inventory_id, NULL AS identifier,
            'Borrowed' AS availability_status, 'Borrowed' AS display_status, 1 AS is_one_off
     FROM borrowed_items loan
     INNER JOIN item_categories category ON category.category_id = loan.category_id
     WHERE category.is_default = 1 AND loan.inventory_id IS NULL AND loan.status <> 'Returned'
     ORDER BY loan.item_name, loan.date_borrowed DESC"
);
if ($otherActiveStmt === false) {
    http_response_code(500);
    exit('Unlisted borrowed items could not be loaded.');
}
$otherActiveResult = mysqli_stmt_get_result($otherActiveStmt);
while ($row = mysqli_fetch_assoc($otherActiveResult)) {
    $inventoryItems[] = $row;
}
mysqli_stmt_close($otherActiveStmt);

$inventoryItemsByCategory = [];
$categorySummaries = [];
foreach ($categories as $category) {
    $categoryId = (int)$category['category_id'];
    $inventoryItemsByCategory[$categoryId] = [];
    $categorySummaries[$categoryId] = [
        'id' => $categoryId,
        'name' => $category['category_name'],
        'is_default' => (int)$category['is_default'] === 1,
        'total' => 0,
        'Available' => 0,
        'Borrowed' => 0,
        'Unavailable' => 0,
    ];
}
foreach ($inventoryItems as $inventoryItem) {
    $categoryId = (int)$inventoryItem['category_id'];
    if (!isset($categorySummaries[$categoryId])) {
        continue;
    }
    $inventoryItemsByCategory[$categoryId][] = $inventoryItem;
    $categorySummaries[$categoryId]['total']++;
    if (isset($categorySummaries[$categoryId][$inventoryItem['display_status']])) {
        $categorySummaries[$categoryId][$inventoryItem['display_status']]++;
    }
}

$search = isset($_GET['search']) && is_string($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : 'All';
if (!in_array($statusFilter, ['All', 'Unreturned', 'Returned'], true)) {
    $statusFilter = 'All';
}
$loanConditions = [];
$loanTypes = '';
$loanParams = [];
if ($search !== '') {
    $searchTerm = '%' . $search . '%';
    $loanConditions[] = '(loan.item_name LIKE ? OR loan.borrower LIKE ? OR asset.identifier LIKE ? OR category.category_name LIKE ?)';
    $loanTypes .= 'ssss';
    array_push($loanParams, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}
if ($statusFilter !== 'All') {
    $loanConditions[] = $statusFilter === 'Returned' ? "loan.status = 'Returned'" : "loan.status <> 'Returned'";
}
$loanSql = "SELECT loan.item_id, loan.inventory_id, loan.item_name, loan.borrower, loan.borrower_phone,
                   loan.date_borrowed, loan.returned_date, loan.status,
                   COALESCE(loan.inventory_identifier, asset.identifier) AS identifier,
                   COALESCE(category.category_name, CASE WHEN loan.inventory_identifier IS NOT NULL THEN 'Deleted category' ELSE 'Legacy record' END) AS category_name,
                   CASE WHEN loan.status = 'Returned' THEN 'Returned' ELSE 'Unreturned' END AS display_status
            FROM borrowed_items loan
            LEFT JOIN borrowable_inventory asset ON asset.inventory_id = loan.inventory_id
            LEFT JOIN item_categories category ON category.category_id = loan.category_id";
if ($loanConditions) {
    $loanSql .= ' WHERE ' . implode(' AND ', $loanConditions);
}
$loanSql .= ' ORDER BY loan.item_name ASC, loan.borrower ASC, loan.date_borrowed DESC LIMIT 200';
$loanStmt = safe_query($conn, $loanSql, $loanTypes, $loanParams);
if ($loanStmt === false) {
    http_response_code(500);
    exit('Borrowing records could not be loaded.');
}
$loans = [];
$loanResult = mysqli_stmt_get_result($loanStmt);
while ($row = mysqli_fetch_assoc($loanResult)) {
    $loans[] = $row;
}
mysqli_stmt_close($loanStmt);

$itemFilterParam = $_GET['item_id'] ?? '';
$itemFilterId = is_string($itemFilterParam) && ctype_digit($itemFilterParam) ? (int)$itemFilterParam : 0;
$selectedLoan = null;
if ($itemFilterId > 0) {
    foreach ($loans as $loan) {
        if ((int)$loan['item_id'] === $itemFilterId) {
            $selectedLoan = $loan;
            break;
        }
    }
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="row g-4">
    <div class="col-12">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
            <div>
                <h2 class="mb-1 text-primary"><?php echo $showBorrowingHistory ? 'Borrowing History' : 'Borrowing &amp; Inventory'; ?></h2>
                <p class="mb-0 text-muted"><?php echo $showBorrowingHistory ? 'Review borrowing records. Returns are managed from inventory.' : 'Manage items by category, record returns, and update availability.'; ?></p>
            </div>
            <?php if ($showBorrowingHistory): ?>
                <a class="btn btn-outline-primary" href="borrowed"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to inventory</a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($message !== ''): ?>
        <div class="col-12"><?php echo show_alert($message, $messageType); ?></div>
    <?php endif; ?>

    <?php if (!$showBorrowingHistory): ?>
    <div class="col-12 col-xl-4">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <h5 class="card-title text-primary">Add category</h5>
                <p class="small text-muted">Example: Projectors, Cameras, or Laptops.</p>
                <form method="POST" class="d-flex flex-column gap-2">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <label class="form-label mb-0" for="inventoryCategoryName">Category name</label>
                    <input type="text" id="inventoryCategoryName" name="category_name" class="form-control" maxlength="100" placeholder="e.g. Projectors" required>
                    <div class="form-check">
                        <input type="checkbox" id="categoryRequiresIdentifier" name="requires_identifier" value="1" class="form-check-input" checked>
                        <label class="form-check-label" for="categoryRequiresIdentifier">Ask for an identifier ID when registering items</label>
                    </div>
                    <button type="submit" name="add_category" class="btn btn-primary align-self-start"><i class="bi bi-folder-plus me-1" aria-hidden="true"></i>Add category</button>
                </form>
                <hr>
                <p class="small text-muted mb-0"><?php echo count($categories); ?> categories configured.</p>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-8">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <h5 class="card-title text-primary">Register an item</h5>
                <p class="small text-muted">Items are grouped under their category. An identifier is required only when that category asks for one. “Other” lets users borrow an unlisted item without registering an ID.</p>
                <form method="POST" class="row g-3 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="newInventoryCategory">Category</label>
                        <select id="newInventoryCategory" name="category_id" class="form-select" required>
                            <option value="">Select category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo (int)$category['category_id']; ?>" data-requires-identifier="<?php echo (int)$category['requires_identifier']; ?>"><?php echo sanitize($category['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="newInventoryName">Item name</label>
                        <input type="text" id="newInventoryName" name="item_name" class="form-control" maxlength="255" placeholder="e.g. Projector 1" required>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="newInventoryIdentifier">Identifier ID</label>
                        <input type="text" id="newInventoryIdentifier" name="identifier" class="form-control" maxlength="100" placeholder="e.g. PRJ-001" required>
                    </div>
                    <div class="col-12 col-md-1 d-grid">
                        <button type="submit" name="add_inventory_item" class="btn btn-primary" aria-label="Register item"><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editInventoryItemModal" tabindex="-1" aria-labelledby="editInventoryItemTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-primary" id="editInventoryItemTitle">Edit item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <input type="hidden" id="editInventoryId" name="inventory_id">
                    <div class="mb-3">
                        <label class="form-label" for="editInventoryCategory">Category</label>
                        <select id="editInventoryCategory" name="category_id" class="form-select" required>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo (int)$category['category_id']; ?>" data-requires-identifier="<?php echo (int)$category['requires_identifier']; ?>"><?php echo sanitize($category['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="editInventoryName">Item name</label>
                        <input type="text" id="editInventoryName" name="item_name" class="form-control" maxlength="255" required>
                    </div>
                    <div>
                        <label class="form-label" for="editInventoryIdentifier">Identifier ID <span class="text-muted">(if required)</span></label>
                        <input type="text" id="editInventoryIdentifier" name="identifier" class="form-control" maxlength="100" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_inventory_item" class="btn btn-primary">Save item details</button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-column align-items-center gap-2 mb-3 text-center">
                    <div class="text-center">
                        <h5 class="card-title text-primary mb-1">Inventory status</h5>
                        <p class="small text-muted mb-0">Open a category to see only its items. Return borrowed items or update availability from each item’s Action column.</p>
                    </div>
                    <span class="small text-muted"><?php echo count($inventoryItems); ?> item(s)</span>
                </div>
                <?php if (!$categories): ?>
                    <p class="py-4 text-center text-muted mb-0">No categories are available.</p>
                <?php else: ?>
                    <div class="accordion" id="inventoryCategoryAccordion">
                        <?php foreach ($categories as $category): ?>
                            <?php
                            $categoryId = (int)$category['category_id'];
                            $categoryItems = $inventoryItemsByCategory[$categoryId] ?? [];
                            ?>
                            <div class="accordion-item">
                                <h3 class="accordion-header" id="inventoryCategoryHeading<?php echo $categoryId; ?>">
                                    <button class="accordion-button<?php echo $categoryId === (int)$categories[0]['category_id'] ? '' : ' collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#inventoryCategoryCollapse<?php echo $categoryId; ?>" aria-expanded="<?php echo $categoryId === (int)$categories[0]['category_id'] ? 'true' : 'false'; ?>" aria-controls="inventoryCategoryCollapse<?php echo $categoryId; ?>">
                                        <span class="fw-semibold"><?php echo sanitize($category['category_name']); ?></span>
                                        <span class="small text-muted ms-2"><?php echo count($categoryItems); ?> item(s)</span>
                                    </button>
                                </h3>
                                <div id="inventoryCategoryCollapse<?php echo $categoryId; ?>" class="accordion-collapse collapse<?php echo $categoryId === (int)$categories[0]['category_id'] ? ' show' : ''; ?>" aria-labelledby="inventoryCategoryHeading<?php echo $categoryId; ?>" data-bs-parent="#inventoryCategoryAccordion">
                                    <div class="accordion-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-hover align-middle mb-0 borrowed-inventory-table" data-status-guide="inventory">
                                                <thead class="table-light"><tr><th>Item</th><th>Identifier ID</th><th>Status</th><th>Action</th></tr></thead>
                                                <tbody>
                                                <?php if (!$categoryItems): ?>
                                                    <tr><td colspan="4" class="py-4 text-center text-muted">No items in this category yet.</td></tr>
                                                <?php else: foreach ($categoryItems as $inventoryItem): ?>
                                                    <tr>
                                                        <td class="fw-semibold"><?php echo sanitize($inventoryItem['item_name']); ?><?php if (!empty($inventoryItem['is_one_off'])): ?><small class="d-block fw-normal text-muted">Unlisted item</small><?php endif; ?></td>
                                                        <td><?php echo $inventoryItem['identifier'] !== null && $inventoryItem['identifier'] !== '' ? '<code>' . sanitize($inventoryItem['identifier']) . '</code>' : '—'; ?></td>
                                                        <td>
                                                            <?php $statusDot = $inventoryItem['display_status'] === 'Available' ? 'bg-success' : ($inventoryItem['display_status'] === 'Borrowed' ? 'bg-primary' : 'bg-secondary'); ?>
                                                            <span class="d-inline-flex align-items-center gap-2"><span class="rounded-circle <?php echo $statusDot; ?>" style="width:.6rem;height:.6rem" aria-hidden="true"></span><?php echo sanitize($inventoryItem['display_status']); ?></span>
                                                            <?php if (!empty($inventoryItem['active_borrower'])): ?><small class="d-block text-muted">With <?php echo sanitize($inventoryItem['active_borrower']); ?></small><?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex flex-wrap gap-2">
                                                                <?php if ($inventoryItem['display_status'] === 'Borrowed' && !empty($inventoryItem['active_loan_id'])): ?>
                                                                    <form method="POST">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                                        <input type="hidden" name="inventory_id" value="<?php echo (int)($inventoryItem['inventory_id'] ?? 0); ?>">
                                                                        <input type="hidden" name="loan_id" value="<?php echo (int)$inventoryItem['active_loan_id']; ?>">
                                                                        <button type="submit" name="mark_inventory_returned" class="btn btn-sm btn-success">Mark returned</button>
                                                                    </form>
                                                                <?php elseif (empty($inventoryItem['is_one_off'])): ?>
                                                                    <button
                                                                        type="button"
                                                                        class="btn btn-sm btn-outline-primary"
                                                                        data-bs-toggle="modal"
                                                                        data-bs-target="#editInventoryItemModal"
                                                                        data-inventory-edit
                                                                        data-inventory-id="<?php echo (int)$inventoryItem['inventory_id']; ?>"
                                                                        data-category-id="<?php echo (int)$inventoryItem['category_id']; ?>"
                                                                        data-item-name="<?php echo sanitize($inventoryItem['item_name']); ?>"
                                                                        data-identifier="<?php echo sanitize($inventoryItem['identifier'] ?? ''); ?>"
                                                                    >Edit item</button>
                                                                    <form method="POST">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                                        <input type="hidden" name="inventory_id" value="<?php echo (int)$inventoryItem['inventory_id']; ?>">
                                                                        <input type="hidden" name="new_status" value="<?php echo $inventoryItem['display_status'] === 'Available' ? 'Unavailable' : 'Available'; ?>">
                                                                        <button type="submit" name="set_inventory_status" class="btn btn-sm btn-outline-secondary"><?php echo $inventoryItem['display_status'] === 'Available' ? 'Mark unavailable' : 'Mark available'; ?></button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <span class="small text-muted">No action available.</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-column align-items-center gap-2 mb-3 text-center">
                    <div class="text-center">
                        <h5 class="card-title text-primary mb-1">Categories</h5>
                        <p class="small text-muted mb-0">Inventory counts by category.</p>
                    </div>
                    <span class="small text-muted"><?php echo count($categorySummaries); ?> categories</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 borrowed-category-table">
                        <thead class="table-light">
                            <tr><th scope="col">Category</th><th scope="col">Items</th><th scope="col">Available</th><th scope="col">Borrowed</th><th scope="col">Unavailable</th><th scope="col">Action</th></tr>
                        </thead>
                        <tbody>
                            <?php if (!$categorySummaries): ?>
                                <tr><td colspan="6" class="py-4 text-muted">No categories have been added.</td></tr>
                            <?php else: foreach ($categorySummaries as $summary): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo sanitize($summary['name']); ?></td>
                                    <td><?php echo (int)$summary['total']; ?></td>
                                    <td><?php echo (int)$summary['Available']; ?></td>
                                    <td><?php echo (int)$summary['Borrowed']; ?></td>
                                    <td><?php echo (int)$summary['Unavailable']; ?></td>
                                    <td>
                                        <?php if ($summary['is_default']): ?>
                                            <span class="text-muted">Default</span>
                                        <?php else: ?>
                                        <form method="POST" class="m-0">
                                            <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                            <input type="hidden" name="category_id" value="<?php echo (int)$summary['id']; ?>">
                                            <button
                                                type="submit"
                                                name="delete_category"
                                                class="btn btn-sm btn-outline-danger"
                                                <?php echo $summary['Borrowed'] > 0 ? 'disabled title="Return all items in this category before deleting it."' : ''; ?>
                                                data-confirm-title="Delete this category?"
                                                data-confirm-message="This deletes the category and its registered inventory items. Past borrowing records will be kept. Items must be returned before this category can be deleted."
                                                data-confirm-button="Delete category"
                                            ><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="col-12" id="borrowing-history">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-3">
                    <div>
                        <h5 class="card-title text-primary mb-1">Borrowing records</h5>
                        <p class="small text-muted mb-0">Read-only records. Mark items returned from the Action column in their inventory category.</p>
                    </div>
                    <form method="GET" class="row g-2">
                        <input type="hidden" name="view" value="history">
                        <div class="col">
                            <label class="visually-hidden" for="borrowedSearch">Search borrowing records</label>
                            <input type="search" id="borrowedSearch" name="search" class="form-control" placeholder="Item, identifier, borrower" value="<?php echo sanitize($search); ?>">
                        </div>
                        <div class="col-auto">
                            <label class="visually-hidden" for="borrowedStatus">Borrowing status</label>
                            <select id="borrowedStatus" name="status" class="form-select">
                                <?php foreach (['All' => 'All records', 'Unreturned' => 'Borrowed', 'Returned' => 'Returned'] as $value => $label): ?>
                                    <option value="<?php echo $value; ?>"<?php echo $statusFilter === $value ? ' selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto"><button type="submit" class="btn btn-outline-primary">Filter</button></div>
                    </form>
                </div>
                <?php if ($itemFilterId > 0): ?>
                    <div class="alert alert-info py-2">Selected record: <?php echo $selectedLoan ? sanitize($selectedLoan['item_name'] . ' · ' . $selectedLoan['borrower']) : 'This borrowing record is not in the current result set.'; ?> <a class="alert-link ms-2" href="borrowed?view=history#borrowing-history">Show all</a></div>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 borrowed-history-table" data-status-guide="borrowed">
                        <thead class="table-light"><tr><th>Item / ID</th><th>Category</th><th>Borrower</th><th>Borrowed</th><th>Returned</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php if (!$loans): ?>
                            <tr><td colspan="6" class="py-4 text-muted">No borrowing records match these filters.</td></tr>
                        <?php else: foreach ($loans as $loan): ?>
                            <tr>
                                <td><div class="fw-semibold"><?php echo sanitize($loan['item_name']); ?></div><?php if ($loan['identifier']): ?><code><?php echo sanitize($loan['identifier']); ?></code><?php endif; ?></td>
                                <td><?php echo sanitize($loan['category_name'] ?? 'Legacy record'); ?></td>
                                <td><div class="fw-semibold"><?php echo sanitize($loan['borrower']); ?></div><?php if ($loan['borrower_phone']): ?><small class="text-muted"><?php echo sanitize($loan['borrower_phone']); ?></small><?php endif; ?></td>
                                <td><?php echo sanitize(date('M j, Y g:i A', strtotime($loan['date_borrowed']))); ?></td>
                                <td><?php echo $loan['returned_date'] ? sanitize(date('M j, Y', strtotime($loan['returned_date']))) : '—'; ?></td>
                                <td><span class="badge rounded-pill <?php echo admin_status_badge_class($loan['display_status']); ?>"><?php echo sanitize($loan['display_status'] === 'Unreturned' ? 'Borrowed' : 'Returned'); ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<script>
const configureInventoryIdentifier = (categorySelect, identifierInput) => {
    const option = categorySelect.selectedOptions[0];
    const required = option?.dataset.requiresIdentifier === '1';
    identifierInput.required = required;
    identifierInput.disabled = !required;
    if (!required) identifierInput.value = '';
};
const newInventoryCategory = document.getElementById('newInventoryCategory');
const newInventoryIdentifier = document.getElementById('newInventoryIdentifier');
const editInventoryCategory = document.getElementById('editInventoryCategory');
const editInventoryIdentifier = document.getElementById('editInventoryIdentifier');
if (newInventoryCategory && newInventoryIdentifier) {
    newInventoryCategory.addEventListener('change', () => configureInventoryIdentifier(newInventoryCategory, newInventoryIdentifier));
    configureInventoryIdentifier(newInventoryCategory, newInventoryIdentifier);
}
if (editInventoryCategory && editInventoryIdentifier) {
    editInventoryCategory.addEventListener('change', () => configureInventoryIdentifier(editInventoryCategory, editInventoryIdentifier));
    document.querySelectorAll('[data-inventory-edit]').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('editInventoryId').value = button.dataset.inventoryId;
            editInventoryCategory.value = button.dataset.categoryId;
            document.getElementById('editInventoryName').value = button.dataset.itemName;
            configureInventoryIdentifier(editInventoryCategory, editInventoryIdentifier);
            editInventoryIdentifier.value = button.dataset.identifier;
        });
    });
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
