<?php
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
        recipient VARCHAR(255) NOT NULL,
        remarks TEXT,
        tracking_token CHAR(64) DEFAULT NULL,
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
    $conn->query('ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS tracking_token CHAR(64) DEFAULT NULL');
    $conn->query("ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS sender_type VARCHAR(20) NOT NULL DEFAULT 'Student'");
    $conn->query("ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS recipient_contact_type VARCHAR(20) NOT NULL DEFAULT 'Unassigned'");
    $conn->query('ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS paper_count INT UNSIGNED NOT NULL DEFAULT 0');
    $conn->query('ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS is_priority TINYINT(1) NOT NULL DEFAULT 0');
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
