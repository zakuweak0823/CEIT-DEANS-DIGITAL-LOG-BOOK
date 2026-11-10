<?php
function ensure_borrowed_inventory_schema($conn): void
{
    $categoryColumns = [];
    $categoryColumnResult = $conn->query('SHOW COLUMNS FROM item_categories');
    if ($categoryColumnResult === false) {
        throw new RuntimeException('Could not inspect inventory categories: ' . $conn->error);
    }
    while ($column = $categoryColumnResult->fetch_assoc()) {
        $categoryColumns[$column['Field']] = true;
    }
    $categoryColumnResult->free();
    foreach ([
        'requires_identifier' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'is_default' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ] as $columnName => $columnDefinition) {
        if (!isset($categoryColumns[$columnName])
            && $conn->query('ALTER TABLE item_categories ADD COLUMN ' . $columnName . ' ' . $columnDefinition) === false) {
            throw new RuntimeException('Could not add inventory-category field ' . $columnName . ': ' . $conn->error);
        }
    }

    $createInventoryItems = "CREATE TABLE IF NOT EXISTS borrowable_inventory (
        inventory_id INT AUTO_INCREMENT PRIMARY KEY,
        category_id INT NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        identifier VARCHAR(100) DEFAULT NULL,
        availability_status VARCHAR(20) NOT NULL DEFAULT 'Available',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_borrowable_inventory_category_identifier (category_id, identifier),
        CONSTRAINT fk_borrowable_inventory_category FOREIGN KEY (category_id)
            REFERENCES item_categories(category_id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if ($conn->query($createInventoryItems) === false) {
        throw new RuntimeException('Could not create borrowable inventory storage: ' . $conn->error);
    }

    $inventoryIdentifierResult = $conn->query("SHOW COLUMNS FROM borrowable_inventory LIKE 'identifier'");
    if ($inventoryIdentifierResult === false) {
        throw new RuntimeException('Could not inspect inventory identifiers: ' . $conn->error);
    }
    $inventoryIdentifierColumn = $inventoryIdentifierResult->fetch_assoc();
    $inventoryIdentifierResult->free();
    if (!$inventoryIdentifierColumn) {
        throw new RuntimeException('Inventory identifier storage is unavailable.');
    }
    if ($inventoryIdentifierColumn['Null'] !== 'YES'
        && $conn->query('ALTER TABLE borrowable_inventory MODIFY identifier VARCHAR(100) DEFAULT NULL') === false) {
        throw new RuntimeException('Could not allow identifier-free inventory items: ' . $conn->error);
    }

    if ($conn->query("INSERT IGNORE INTO item_categories (category_name, requires_identifier, is_default) VALUES ('Other', 0, 1)") === false
        || $conn->query("UPDATE item_categories SET requires_identifier = 0, is_default = 1 WHERE category_name = 'Other'") === false) {
        throw new RuntimeException('Could not ensure the default Other category: ' . $conn->error);
    }

    $inventoryColumns = [];
    $columnResult = $conn->query('SHOW COLUMNS FROM borrowed_items');
    if ($columnResult === false) {
        throw new RuntimeException('Could not inspect borrowed-item storage: ' . $conn->error);
    }
    while ($column = $columnResult->fetch_assoc()) {
        $inventoryColumns[$column['Field']] = true;
    }
    $columnResult->free();
    foreach ([
        'category_id' => 'INT DEFAULT NULL',
        'inventory_id' => 'INT DEFAULT NULL',
        'inventory_identifier' => 'VARCHAR(100) DEFAULT NULL',
        'borrower_phone' => 'VARCHAR(20) DEFAULT NULL',
        'returned_date' => 'DATE DEFAULT NULL',
    ] as $columnName => $columnDefinition) {
        if (!isset($inventoryColumns[$columnName])
            && $conn->query('ALTER TABLE borrowed_items ADD COLUMN ' . $columnName . ' ' . $columnDefinition) === false) {
            throw new RuntimeException('Could not add borrowed-item field ' . $columnName . ': ' . $conn->error);
        }
    }

    $indexResult = $conn->query("SHOW INDEX FROM borrowed_items WHERE Key_name = 'idx_borrowed_items_inventory'");
    if ($indexResult === false) {
        throw new RuntimeException('Could not inspect the borrowed-inventory index: ' . $conn->error);
    }
    $hasInventoryIndex = $indexResult->num_rows > 0;
    $indexResult->free();
    if (!$hasInventoryIndex && $conn->query('CREATE INDEX idx_borrowed_items_inventory ON borrowed_items (inventory_id)') === false) {
        throw new RuntimeException('Could not create the borrowed-inventory index: ' . $conn->error);
    }
}

function delete_borrowed_inventory_category($conn, int $categoryId): void
{
    if ($categoryId <= 0) {
        throw new DomainException('Select a valid category.');
    }

    $conn->begin_transaction();
    try {
        $categoryStmt = safe_query($conn, 'SELECT category_id, is_default FROM item_categories WHERE category_id = ? FOR UPDATE', 'i', [$categoryId]);
        if ($categoryStmt === false) {
            throw new RuntimeException('Could not verify the selected category.');
        }
        $category = mysqli_fetch_assoc(mysqli_stmt_get_result($categoryStmt));
        mysqli_stmt_close($categoryStmt);
        if (!$category) {
            throw new DomainException('This category no longer exists.');
        }
        if ((int)$category['is_default'] === 1) {
            throw new DomainException('The default Other category cannot be deleted.');
        }

        $inventoryStmt = safe_query($conn, 'SELECT inventory_id FROM borrowable_inventory WHERE category_id = ? FOR UPDATE', 'i', [$categoryId]);
        if ($inventoryStmt === false) {
            throw new RuntimeException('Could not lock the category inventory.');
        }
        mysqli_stmt_close($inventoryStmt);

        $activeRecords = (int)safe_scalar(
            $conn,
            "SELECT COUNT(*) FROM borrowed_items borrow_record
             LEFT JOIN borrowable_inventory asset ON asset.inventory_id = borrow_record.inventory_id
             WHERE (borrow_record.category_id = ? OR asset.category_id = ?) AND borrow_record.status <> 'Returned'",
            'ii',
            [$categoryId, $categoryId]
        );
        if ($activeRecords > 0) {
            throw new DomainException('Return all items in this category before deleting it.');
        }

        $preserveHistoryStmt = safe_query(
            $conn,
            'UPDATE borrowed_items borrow_record
             LEFT JOIN borrowable_inventory asset ON asset.inventory_id = borrow_record.inventory_id
             SET borrow_record.inventory_identifier = COALESCE(borrow_record.inventory_identifier, asset.identifier),
                 borrow_record.inventory_id = NULL,
                 borrow_record.category_id = NULL
             WHERE borrow_record.category_id = ? OR asset.category_id = ?',
            'ii',
            [$categoryId, $categoryId]
        );
        if ($preserveHistoryStmt === false) {
            throw new RuntimeException('Could not preserve borrowing history for the category.');
        }
        mysqli_stmt_close($preserveHistoryStmt);

        $deleteItemsStmt = safe_query($conn, 'DELETE FROM borrowable_inventory WHERE category_id = ?', 'i', [$categoryId]);
        if ($deleteItemsStmt === false) {
            throw new RuntimeException('Could not remove the category inventory.');
        }
        mysqli_stmt_close($deleteItemsStmt);

        $deleteCategoryStmt = safe_query($conn, 'DELETE FROM item_categories WHERE category_id = ?', 'i', [$categoryId]);
        if ($deleteCategoryStmt === false || mysqli_stmt_affected_rows($deleteCategoryStmt) !== 1) {
            if ($deleteCategoryStmt !== false) {
                mysqli_stmt_close($deleteCategoryStmt);
            }
            throw new RuntimeException('Could not remove the category.');
        }
        mysqli_stmt_close($deleteCategoryStmt);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
