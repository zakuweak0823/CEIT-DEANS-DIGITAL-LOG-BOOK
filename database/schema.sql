CREATE DATABASE IF NOT EXISTS dit_logbook CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dit_logbook;

CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'admin',
    recovery_phone VARCHAR(20) DEFAULT NULL
);
ALTER TABLE users ADD COLUMN IF NOT EXISTS recovery_phone VARCHAR(20) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS mobile_api_tokens (
    token_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mobile_api_tokens_expiry (expires_at),
    CONSTRAINT fk_mobile_api_tokens_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS password_resets (
    reset_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    username_key CHAR(64) NOT NULL,
    request_ip VARCHAR(45) NOT NULL DEFAULT '',
    code_salt CHAR(32) DEFAULT NULL,
    code_hash CHAR(64) DEFAULT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_resets_user (user_id, used_at, expires_at),
    INDEX idx_password_resets_key (username_key, created_at),
    INDEX idx_password_resets_ip (request_ip, created_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS documents (
    doc_id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    sender VARCHAR(255) NOT NULL,
    submitter_name VARCHAR(255) NOT NULL DEFAULT '',
    tracking_token CHAR(64) DEFAULT NULL,
    recipient VARCHAR(255) NOT NULL,
    client_phone VARCHAR(20) DEFAULT NULL,
    submitter_type VARCHAR(20) NOT NULL DEFAULT 'Other',
    is_priority TINYINT(1) NOT NULL DEFAULT 0,
    subject VARCHAR(255) NOT NULL,
    remarks TEXT,
    status VARCHAR(50) NOT NULL,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE documents ADD COLUMN IF NOT EXISTS client_phone VARCHAR(20) DEFAULT NULL;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS submitter_name VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE documents ADD COLUMN IF NOT EXISTS tracking_token CHAR(64) DEFAULT NULL;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS entry_source VARCHAR(32) NOT NULL DEFAULT 'standard';
ALTER TABLE documents ADD COLUMN IF NOT EXISTS submitter_type VARCHAR(20) NOT NULL DEFAULT 'Other';
ALTER TABLE documents ADD COLUMN IF NOT EXISTS is_priority TINYINT(1) NOT NULL DEFAULT 0;
UPDATE documents SET status = 'Pending' WHERE type = 'Incoming' AND status = 'Received';

CREATE TABLE IF NOT EXISTS mobile_submission_logs (
    log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    submission_type VARCHAR(24) NOT NULL,
    sender VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mobile_submission_logs_submitted_at (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS item_categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS borrowed_items (
    item_id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(255) NOT NULL,
    category_id INT,
    borrower VARCHAR(255) NOT NULL,
    borrower_phone VARCHAR(20) DEFAULT NULL,
    due_date DATE DEFAULT NULL,
    returned_date DATE DEFAULT NULL,
    status VARCHAR(50) NOT NULL,
    date_borrowed TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES item_categories(category_id)
);

CREATE TABLE IF NOT EXISTS document_batches (
    batch_id INT AUTO_INCREMENT PRIMARY KEY,
    batch_code VARCHAR(32) NOT NULL UNIQUE,
    document_type VARCHAR(20) NOT NULL,
    batch_type VARCHAR(255) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'Pending',
    batch_date DATE NOT NULL,
    sender VARCHAR(255) NOT NULL,
    sender_type VARCHAR(20) NOT NULL DEFAULT 'Student',
    recipient VARCHAR(255) NOT NULL DEFAULT '',
    recipient_contact_type VARCHAR(20) NOT NULL DEFAULT 'Unassigned',
    remarks TEXT,
    tracking_token CHAR(64) DEFAULT NULL,
    paper_count INT UNSIGNED NOT NULL DEFAULT 0,
    is_priority TINYINT(1) NOT NULL DEFAULT 0,
    received_paper_count INT UNSIGNED DEFAULT NULL,
    intake_expected_count INT UNSIGNED DEFAULT NULL,
    intake_notes TEXT,
    received_at DATETIME DEFAULT NULL,
    received_by VARCHAR(100) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS tracking_token CHAR(64) DEFAULT NULL;
ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS batch_type VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT '';
ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS sender_type VARCHAR(20) NOT NULL DEFAULT 'Student';
ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS recipient_contact_type VARCHAR(20) NOT NULL DEFAULT 'Unassigned';
ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS paper_count INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS is_priority TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS received_paper_count INT UNSIGNED DEFAULT NULL;
ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS intake_expected_count INT UNSIGNED DEFAULT NULL;
ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS intake_notes TEXT;
ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS received_at DATETIME DEFAULT NULL;
ALTER TABLE document_batches ADD COLUMN IF NOT EXISTS received_by VARCHAR(100) NOT NULL DEFAULT '';

CREATE TABLE IF NOT EXISTS batch_papers (
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
);

UPDATE document_batches SET batch_type = document_type WHERE batch_type = '';
UPDATE document_batches b
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
END
WHERE b.status = '';
ALTER TABLE document_batches ALTER COLUMN status SET DEFAULT 'Pending';

CREATE TABLE IF NOT EXISTS sms_logs (
    sms_id INT AUTO_INCREMENT PRIMARY KEY,
    recipient VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    status VARCHAR(50) NOT NULL,
    provider_message_id VARCHAR(64) DEFAULT NULL,
    provider_status_checked_at DATETIME DEFAULT NULL,
    date_sent TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE sms_logs ADD COLUMN IF NOT EXISTS provider_message_id VARCHAR(64) DEFAULT NULL;
ALTER TABLE sms_logs ADD COLUMN IF NOT EXISTS provider_status_checked_at DATETIME DEFAULT NULL;

CREATE TABLE IF NOT EXISTS settings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY,
    key_name VARCHAR(100) NOT NULL UNIQUE,
    value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Add category_id column to borrowed_items if it doesn't exist
ALTER TABLE borrowed_items ADD COLUMN IF NOT EXISTS category_id INT;
ALTER TABLE borrowed_items ADD COLUMN IF NOT EXISTS borrower_phone VARCHAR(20) DEFAULT NULL;
ALTER TABLE borrowed_items ADD COLUMN IF NOT EXISTS returned_date DATE DEFAULT NULL;

-- Insert a default admin user with username "admin" and password "admin".
-- The application will migrate the password to a secure hash on the first login.
INSERT INTO users (username, password, role) VALUES ('admin', 'admin', 'super_admin')
ON DUPLICATE KEY UPDATE username = VALUES(username);
