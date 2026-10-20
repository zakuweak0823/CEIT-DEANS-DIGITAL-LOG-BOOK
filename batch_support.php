<?php
function batch_workflow_setting_defaults()
{
    return [
        'intake_confirmation_mode' => 'confirm_with_release',
    ];
}

function get_batch_workflow_settings($conn)
{
    $settings = batch_workflow_setting_defaults();
    $stmt = safe_query(
        $conn,
        "SELECT key_name, value FROM settings WHERE key_name = 'intake_confirmation_mode'"
    );
    if ($stmt === false) {
        throw new RuntimeException('Could not load batch workflow settings.');
    }
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        if ($row['key_name'] === 'intake_confirmation_mode' && in_array($row['value'], ['confirm_before_release', 'confirm_with_release'], true)) {
            $settings[$row['key_name']] = $row['value'];
        }
    }
    mysqli_stmt_close($stmt);
    return $settings;
}

function save_batch_workflow_settings($conn, $settings)
{
    $defaults = batch_workflow_setting_defaults();
    foreach ($defaults as $key => $default) {
        if (!isset($settings[$key]) || !in_array($settings[$key], ['confirm_before_release', 'confirm_with_release'], true)) {
            return false;
        }
    }
    if (!$conn->begin_transaction()) {
        return false;
    }
    foreach ($defaults as $key => $default) {
        $stmt = safe_query(
            $conn,
            'INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            'ss',
            [$key, $settings[$key]]
        );
        if ($stmt === false) {
            $conn->rollback();
            return false;
        }
        mysqli_stmt_close($stmt);
    }
    if (!$conn->commit()) {
        $conn->rollback();
        return false;
    }
    return true;
}

function ensure_batch_storage($conn)
{
    $conn->query("CREATE TABLE IF NOT EXISTS document_batches (
        batch_id INT AUTO_INCREMENT PRIMARY KEY,
        batch_code VARCHAR(32) NOT NULL UNIQUE,
        document_type VARCHAR(20) NOT NULL,
        batch_type VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        batch_date DATE NOT NULL,
        sender VARCHAR(255) NOT NULL,
        submitter_name VARCHAR(255) NOT NULL DEFAULT '',
        recipient VARCHAR(255) NOT NULL,
        remarks TEXT,
        tracking_token CHAR(64) DEFAULT NULL,
        INDEX idx_document_batches_tracking_token (tracking_token),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM document_batches');
    while ($column = $result->fetch_assoc()) {
        $columns[$column['Field']] = true;
    }
    $result->free();

    // Only infer historical values when adding columns; later requests must preserve admin edits.
    $migrateBatchType = !isset($columns['batch_type']);
    $migrateStatus = !isset($columns['status']);
    if ($migrateBatchType) {
        $conn->query("ALTER TABLE document_batches ADD COLUMN batch_type VARCHAR(255) NOT NULL DEFAULT ''");
    }
    if ($migrateStatus) {
        $conn->query("ALTER TABLE document_batches ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Pending'");
    }
    if (!isset($columns['submitter_name']) && $conn->query("ALTER TABLE document_batches ADD COLUMN submitter_name VARCHAR(255) NOT NULL DEFAULT ''") === false) {
        throw new RuntimeException('Could not add the batch submitter name field: ' . $conn->error);
    }
    $conn->query('ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS tracking_token CHAR(64) DEFAULT NULL');
    $indexResult = $conn->query("SHOW INDEX FROM document_batches WHERE Column_name = 'tracking_token'");
    if ($indexResult === false) {
        throw new RuntimeException('Could not check batch tracking-token index: ' . $conn->error);
    }
    $hasTrackingTokenIndex = $indexResult->num_rows > 0;
    $indexResult->free();
    if (!$hasTrackingTokenIndex && $conn->query('CREATE INDEX idx_document_batches_tracking_token ON document_batches (tracking_token)') === false) {
        throw new RuntimeException('Could not create batch tracking-token index: ' . $conn->error);
    }
    $conn->query("ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS sender_type VARCHAR(20) NOT NULL DEFAULT 'Student'");
    $conn->query("ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS recipient_contact_type VARCHAR(20) NOT NULL DEFAULT 'Unassigned'");
    $conn->query('ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS paper_count INT UNSIGNED NOT NULL DEFAULT 0');
    $conn->query('ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS is_priority TINYINT(1) NOT NULL DEFAULT 0');
    foreach ([
        'ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS received_paper_count INT UNSIGNED DEFAULT NULL',
        'ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS intake_expected_count INT UNSIGNED DEFAULT NULL',
        'ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS intake_notes TEXT',
        'ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS received_at DATETIME DEFAULT NULL',
        "ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS received_by VARCHAR(100) NOT NULL DEFAULT ''",
    ] as $intakeSchemaStatement) {
        if ($conn->query($intakeSchemaStatement) === false) {
            throw new RuntimeException('Could not update batch intake storage: ' . $conn->error);
        }
    }
    $conn->query("CREATE TABLE IF NOT EXISTS batch_papers (
        paper_id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        doc_id INT NOT NULL UNIQUE,
        subject VARCHAR(255) NOT NULL,
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_batch_papers_batch FOREIGN KEY (batch_id)
            REFERENCES document_batches(batch_id) ON DELETE CASCADE,
        CONSTRAINT fk_batch_papers_document FOREIGN KEY (doc_id)
            REFERENCES documents(doc_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query('UPDATE document_batches SET batch_type = document_type WHERE batch_type = \'\'');
    if ($migrateStatus) {
        // Recover the most advanced linked-paper state, falling back to the old direction default.
        $conn->query("UPDATE document_batches b
            SET b.status = CASE
                WHEN EXISTS (
                    SELECT 1 FROM batch_papers p INNER JOIN documents d ON d.doc_id = p.doc_id
                    WHERE p.batch_id = b.batch_id AND d.status = 'Released'
                ) THEN 'Released'
                WHEN EXISTS (
                    SELECT 1 FROM batch_papers p INNER JOIN documents d ON d.doc_id = p.doc_id
                    WHERE p.batch_id = b.batch_id AND d.status = 'For Release'
                ) THEN 'For Release'
                WHEN b.document_type = 'Outgoing' THEN 'For Release'
                ELSE 'Pending'
            END");
    }
    $conn->query('UPDATE document_batches b SET b.paper_count = (SELECT COUNT(*) FROM batch_papers p WHERE p.batch_id = b.batch_id) WHERE b.paper_count = 0');
}
?>
