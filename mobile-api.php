<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
// Echo only known app origins; requests without an Origin still support native clients.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = ['http://localhost:5173', 'http://localhost', 'capacitor://localhost', 'ionic://localhost'];
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once 'db.php';
require_once 'sms_service.php';
require_once 'batch_support.php';
require_once 'mobile_form_settings.php';
require_once 'mobile_app_settings.php';
require_once 'document_tracking.php';

// Initialize incremental schema additions before serving any mobile action.
try {
    ensure_document_tracking_schema($conn);
    $conn->query('ALTER TABLE documents ADD COLUMN IF NOT EXISTS client_phone VARCHAR(20) DEFAULT NULL');
    $conn->query("ALTER TABLE documents ADD COLUMN IF NOT EXISTS submitter_type VARCHAR(20) NOT NULL DEFAULT 'Other'");
    $conn->query('ALTER TABLE documents ADD COLUMN IF NOT EXISTS is_priority TINYINT(1) NOT NULL DEFAULT 0');
    $conn->query("UPDATE documents SET submitter_type = sender WHERE submitter_type = 'Other' AND sender IN ('Student', 'Faculty')");
    $conn->query("UPDATE documents SET status = 'Pending' WHERE type = 'Incoming' AND status = 'Received'");
    $conn->query('ALTER TABLE borrowed_items ADD COLUMN IF NOT EXISTS borrower_phone VARCHAR(20) DEFAULT NULL');
    $conn->query("CREATE TABLE IF NOT EXISTS mobile_api_tokens (
        token_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mobile_api_tokens_expiry (expires_at),
        CONSTRAINT fk_mobile_api_tokens_user FOREIGN KEY (user_id)
            REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $error) {
    error_log('Mobile API schema setup failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Mobile authentication storage is unavailable']);
    exit;
}

try {
    $documentTrackingSettings = get_document_tracking_settings($conn);
} catch (Throwable $error) {
    error_log('Document tracking settings could not be loaded: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Document tracking settings are unavailable']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'document_tracker_status') {
    if (!$documentTrackingSettings['enabled']) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Document tracking is currently unavailable']);
        exit;
    }
    $trackingToken = trim((string)($_GET['token'] ?? ''));
    if (preg_match('/^[a-f0-9]{64}$/', $trackingToken) !== 1) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'This tracking QR code is invalid or was not issued by this office']);
        exit;
    }
    $stmt = safe_query(
        $conn,
        "SELECT doc_id, type, subject, status, DATE_FORMAT(date_created, '%Y-%m-%d') AS date
         FROM documents WHERE tracking_token = ? LIMIT 1",
        's',
        [$trackingToken]
    );
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Document status could not be loaded']);
        exit;
    }
    $result = mysqli_stmt_get_result($stmt);
    $document = $result === false ? false : mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    if ($document === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Document status could not be read']);
        exit;
    }
    if ($document === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'This tracking QR code is invalid or was not issued by this office']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'record' => [
            'reference' => document_tracking_reference($document['doc_id']),
            'type' => $document['type'],
            'subject' => $document['subject'],
            'status' => $document['status'],
            'date' => $document['date'],
        ],
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'batch_tracker_status') {
    try {
        ensure_batch_storage($conn);
    } catch (Throwable $error) {
        error_log('Batch tracker storage could not be initialized: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' =>         'Bulk submission tracking is temporarily unavailable']);
        exit;
    }
    $trackingToken = trim((string)($_GET['token'] ?? ''));
    if (preg_match('/^[a-f0-9]{64}$/', $trackingToken) !== 1) {
        http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'This bulk submission QR code is invalid or was not issued by this office']);
        exit;
    }
    $stmt = safe_query(
        $conn,
        'SELECT batch_id FROM document_batches WHERE tracking_token = ? LIMIT 1',
        's',
        [$trackingToken]
    );
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'The bulk submission status could not be loaded']);
        exit;
    }
    $result = mysqli_stmt_get_result($stmt);
    $batch = $result === false ? false : mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    if ($batch === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' =>         'The bulk submission status could not be read']);
        exit;
    }
    if ($batch === null) {
        http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'This bulk submission QR code is invalid or was not issued by this office']);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($conn->query("ALTER TABLE documents ADD COLUMN IF NOT EXISTS entry_source VARCHAR(32) NOT NULL DEFAULT 'standard'") === false) {
    error_log('Mobile Quick Submit tracking setup failed: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Quick Submit tracking storage is unavailable']);
    exit;
}

try {
    $conn->query("CREATE TABLE IF NOT EXISTS mobile_submission_logs (
        log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        document_id INT NOT NULL,
        submission_type VARCHAR(24) NOT NULL,
        sender VARCHAR(255) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        recipient VARCHAR(255) NOT NULL,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mobile_submission_logs_submitted_at (submitted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $error) {
    error_log('Mobile submission log setup failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Mobile submission log storage is unavailable']);
    exit;
}

$mobileKioskSettingStmt = safe_query($conn, "SELECT value FROM settings WHERE key_name = 'mobile_kiosk_enabled' LIMIT 1");
if ($mobileKioskSettingStmt === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Mobile access settings are unavailable']);
    exit;
}
$mobileKioskSettingResult = mysqli_stmt_get_result($mobileKioskSettingStmt);
if ($mobileKioskSettingResult === false) {
    mysqli_stmt_close($mobileKioskSettingStmt);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Mobile access settings could not be read']);
    exit;
}
$mobileKioskSettingRow = mysqli_fetch_assoc($mobileKioskSettingResult);
mysqli_stmt_close($mobileKioskSettingStmt);
if ($mobileKioskSettingRow === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Mobile access settings could not be read']);
    exit;
}
$officeKioskEnabled = $mobileKioskSettingRow === null || (string)$mobileKioskSettingRow['value'] === '1';

try {
    ensure_batch_storage($conn);
} catch (Throwable $error) {
    error_log('Mobile batch table setup failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Bulk submission storage could not be initialized']);
    exit;
}
$batchWorkflowSettings = get_batch_workflow_settings($conn);
$mobileFormLabels = get_mobile_form_labels($conn);
try {
    $mobileAppSettings = get_mobile_app_settings($conn);
} catch (Throwable $error) {
    error_log('Mobile app settings could not be loaded: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Mobile app settings are unavailable']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if ($input === null) {
        error_log('Mobile API JSON decode failed: ' . json_last_error_msg());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        exit;
    }
    
    $action = $input['action'] ?? '';

    if ($action === 'login') {
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $stmt = safe_query($conn, 'SELECT user_id, password, role FROM users WHERE username = ?', 's', [$username]);
        $user = null;
        if ($stmt !== false) {
            $result = mysqli_stmt_get_result($stmt);
            $user = mysqli_fetch_assoc($result) ?: null;
            mysqli_stmt_close($stmt);
        }

        $passwordValid = $user && in_array($user['role'], ['admin', 'super_admin'], true) && (
            password_verify($password, $user['password']) || hash_equals($user['password'], $password)
        );
        if (!$passwordValid) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
            exit;
        }

        if (!password_verify($password, $user['password'])) {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = safe_query($conn, 'UPDATE users SET password = ? WHERE user_id = ?', 'si', [$passwordHash, $user['user_id']]);
            if ($stmt === false) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Could not secure the account password']);
                exit;
            }
            mysqli_stmt_close($stmt);
        }

        // Only the raw token is returned once; the database stores its SHA-256 digest.
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + 60 * 60 * 24 * 30);
        $stmt = safe_query($conn, 'INSERT INTO mobile_api_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)', 'iss', [$user['user_id'], $tokenHash, $expiresAt]);
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not create a mobile session']);
            exit;
        }
        mysqli_stmt_close($stmt);
        $conn->query('DELETE FROM mobile_api_tokens WHERE expires_at < NOW()');
        echo json_encode(['success' => true, 'token' => $token, 'username' => $username, 'expiresAt' => $expiresAt]);
        exit;
    }
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authorization === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
$tokenHash = '';
$authenticatedUser = null;
if ($authorization !== '' && preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $authorization, $tokenMatch)) {
    // Hash the bearer credential before lookup so a database read does not reveal usable tokens.
    $tokenHash = hash('sha256', $tokenMatch[1]);
    $stmt = safe_query($conn, 'SELECT user_id FROM mobile_api_tokens WHERE token_hash = ? AND expires_at > NOW()', 's', [$tokenHash]);
    if ($stmt !== false) {
        $result = mysqli_stmt_get_result($stmt);
        $authenticatedUser = mysqli_fetch_assoc($result) ?: null;
        mysqli_stmt_close($stmt);
    }
}
if (!$officeKioskEnabled && !$authenticatedUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => "Mobile logbook access is disabled. Enable it in Admin Settings if access without sign-in is required."]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = $input ?? json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    // Each API action exits after returning its own JSON response; keep dispatch paths independent.
    if ($action === 'logout') {
        $stmt = safe_query($conn, 'DELETE FROM mobile_api_tokens WHERE token_hash = ?', 's', [$tokenHash]);
        if ($stmt !== false) {
            mysqli_stmt_close($stmt);
        }
        echo json_encode(['success' => true, 'message' => 'Signed out']);
        exit;
    }

    if ($action === 'get_agenda') {
        $agendaDate = trim($input['date'] ?? date('Y-m-d'));
        $agendaType = trim($input['type'] ?? 'All');
        $search = trim($input['search'] ?? '');
        $dateObject = DateTime::createFromFormat('!Y-m-d', $agendaDate);
        if (!$dateObject || $dateObject->format('Y-m-d') !== $agendaDate || !in_array($agendaType, ['All', 'Incoming', 'Outgoing'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid agenda filters']);
            exit;
        }

        $searchTerm = '%' . $search . '%';
        $stmt = safe_query(
            $conn,
            "SELECT doc_id AS id, DATE_FORMAT(date_created, '%Y-%m-%d') AS date,
                    DATE_FORMAT(date_created, '%H:%i') AS time, type, sender, recipient, subject, status
             FROM documents
             WHERE DATE(date_created) = ?
                AND (? = 'All' OR type = ?)
                AND (? = '' OR sender LIKE ? OR recipient LIKE ? OR subject LIKE ? OR remarks LIKE ?)
             ORDER BY date_created ASC, doc_id ASC",
            'ssssssss',
            [$agendaDate, $agendaType, $agendaType, $search, $searchTerm, $searchTerm, $searchTerm, $searchTerm]
        );
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not load the office agenda']);
            exit;
        }
        $result = mysqli_stmt_get_result($stmt);
        $records = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $records[] = $row;
        }
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => true, 'records' => $records, 'date' => $agendaDate, 'count' => count($records)]);
        exit;
    }

    if ($action === 'prepare_incoming' || $action === 'release_incoming') {
        $documentId = (int)($input['doc_id'] ?? 0);
        if ($documentId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid document id']);
            exit;
        }

        $stmt = safe_query($conn, "SELECT subject, sender, recipient, status FROM documents WHERE doc_id = ? AND type = 'Incoming'", 'i', [$documentId]);
        $document = null;
        if ($stmt !== false) {
            $result = mysqli_stmt_get_result($stmt);
            $document = mysqli_fetch_assoc($result) ?: null;
            mysqli_stmt_close($stmt);
        }
        if (!$document) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'This incoming document was not found or has already been released']);
            exit;
        }
        if ($document['status'] !== 'Pending') {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Only pending documents can be prepared for release']);
            exit;
        }
        // Repeat the eligibility checks in the write to prevent stale mobile clients overwriting a newer state.
        $outgoingSender = trim((string)$document['recipient']) !== ''
            ? $document['recipient']
            : "Dean's Office";
        $stmt = safe_query($conn, "UPDATE documents SET type = 'Outgoing', status = 'For Release', sender = ?, recipient = ? WHERE doc_id = ? AND type = 'Incoming' AND status = 'Pending'", 'ssi', [$outgoingSender, $document['sender'], $documentId]);
        if ($stmt === false || mysqli_stmt_affected_rows($stmt) !== 1) {
            if ($stmt !== false) mysqli_stmt_close($stmt);
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Could not prepare this document; refresh the logbook and try again']);
            exit;
        }
        mysqli_stmt_close($stmt);

        echo json_encode([
            'success' => true,
            'prepared' => true,
            'status' => 'For Release',
            'sender' => $outgoingSender,
            'recipient' => $document['sender'],
        ]);
        exit;
    }

    if ($action === 'set_outgoing_status') {
        $documentId = (int)($input['doc_id'] ?? 0);
        $status = trim($input['status'] ?? '');
        if ($documentId <= 0 || !in_array($status, ['For Release', 'Released'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid outgoing document status']);
            exit;
        }
        $stmt = safe_query($conn, "UPDATE documents SET status = ? WHERE doc_id = ? AND type = 'Outgoing' AND status IN ('For Release', 'Released') AND status <> ?", 'sis', [$status, $documentId, $status]);
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not update the document status']);
            exit;
        }
        $updated = mysqli_stmt_affected_rows($stmt) === 1;
        mysqli_stmt_close($stmt);
        if (!$updated) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Document was not found or already has that status']);
            exit;
        }
        echo json_encode(['success' => true, 'status' => $status]);
        exit;
    }

    if ($action === 'notify_client') {
        $documentId = (int)($input['doc_id'] ?? 0);
        $stmt = safe_query($conn, "SELECT subject, client_phone FROM documents WHERE doc_id = ? AND type = 'Outgoing' AND status = 'Released'", 'i', [$documentId]);
        $document = null;
        if ($stmt !== false) {
            $result = mysqli_stmt_get_result($stmt);
            $document = mysqli_fetch_assoc($result) ?: null;
            mysqli_stmt_close($stmt);
        }
        if (!$document) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Released document not found']);
            exit;
        }
        $clientPhone = trim((string)($document['client_phone'] ?? ''));
        if (!is_valid_contact_number($clientPhone)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No valid client contact number is saved for this document']);
            exit;
        }
        $smsText = "CvSU Dean's Office: The document '{$document['subject']}' has been released. Please contact the office if you need assistance.";
        $smsResult = send_logged_sms($conn, $clientPhone, $smsText);
        echo json_encode(['success' => true, 'sms' => $smsResult]);
        exit;
    }

    if ($action === 'client_status_lookup') {
        $clientPhone = contact_number_input($input['client_phone'] ?? '');
        if (!is_valid_contact_number($clientPhone)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Enter the 11-digit contact number you gave to the office.']);
            exit;
        }

        $stmt = safe_query(
            $conn,
            "SELECT CONCAT('DIT-', LPAD(doc_id, 6, '0')) AS reference,
                    subject, DATE_FORMAT(date_created, '%Y-%m-%d') AS date, type, status,
                    0 AS paperCount, 'document' AS recordType
             FROM documents
             WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(client_phone, '+', ''), ' ', ''), '-', ''), '(', ''), ')', ''), '.', '') = ?
             UNION ALL
             SELECT batch_code AS reference, batch_type AS subject,
                    DATE_FORMAT(batch_date, '%Y-%m-%d') AS date, 'Bulk papers' AS type, status,
                    paper_count AS paperCount, 'batch' AS recordType
             FROM document_batches
             WHERE recipient_contact_type = 'Contact'
                AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(recipient, '+', ''), ' ', ''), '-', ''), '(', ''), ')', ''), '.', '') = ?
             ORDER BY date DESC, reference DESC
             LIMIT 20",
            'ss',
            [$clientPhone, $clientPhone]
        );
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not check document status']);
            exit;
        }
        $result = mysqli_stmt_get_result($stmt);
        $records = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $records[] = $row;
        }
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => true, 'records' => $records]);
        exit;
    }

    if ($action === 'get_report') {
        $reportType = trim($input['report_type'] ?? 'All');
        $from = trim($input['from'] ?? '');
        $to = trim($input['to'] ?? '');
        $validTypes = ['All', 'Incoming', 'Outgoing', 'Borrowed', 'SMS Logs'];
        $validFrom = $from === '' || (DateTime::createFromFormat('!Y-m-d', $from) && DateTime::createFromFormat('!Y-m-d', $from)->format('Y-m-d') === $from);
        $validTo = $to === '' || (DateTime::createFromFormat('!Y-m-d', $to) && DateTime::createFromFormat('!Y-m-d', $to)->format('Y-m-d') === $to);
        if (!in_array($reportType, $validTypes, true) || !$validFrom || !$validTo || ($from !== '' && $to !== '' && $from > $to)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid report filters']);
            exit;
        }

        $stmt = safe_query(
            $conn,
            "SELECT DATE_FORMAT(date_created, '%Y-%m-%d') AS date, type, subject, status
                FROM documents
                WHERE (? = 'All' OR type = ?)
                    AND (? = '' OR DATE(date_created) >= ?)
                    AND (? = '' OR DATE(date_created) <= ?)
             UNION ALL
             SELECT DATE_FORMAT(date_borrowed, '%Y-%m-%d') AS date, 'Borrowed' AS type, item_name AS subject, status
                FROM borrowed_items
                WHERE (? = 'All' OR ? = 'Borrowed')
                    AND (? = '' OR DATE(date_borrowed) >= ?)
                    AND (? = '' OR DATE(date_borrowed) <= ?)
             UNION ALL
             SELECT DATE_FORMAT(date_sent, '%Y-%m-%d') AS date, 'SMS Logs' AS type, message AS subject, status
                FROM sms_logs
                WHERE (? = 'All' OR ? = 'SMS Logs')
                    AND (? = '' OR DATE(date_sent) >= ?)
                    AND (? = '' OR DATE(date_sent) <= ?)
             ORDER BY date DESC",
            'ssssssssssssssssss',
            [$reportType, $reportType, $from, $from, $to, $to, $reportType, $reportType, $from, $from, $to, $to, $reportType, $reportType, $from, $from, $to, $to]
        );
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not generate report']);
            exit;
        }
        $result = mysqli_stmt_get_result($stmt);
        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => true, 'records' => $rows]);
        exit;
    }

    if ($action === 'set_batch_status') {
        $batchId = (int)($input['batch_id'] ?? 0);
        if ($batchId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Select a valid bulk submission']);
            exit;
        }
        try {
            // Serialize taps so concurrent requests cannot skip or repeat a workflow transition.
            $conn->begin_transaction();
            $stmt = safe_query($conn, 'SELECT status, paper_count, received_at FROM document_batches WHERE batch_id = ? FOR UPDATE', 'i', [$batchId]);
            if ($stmt === false) {
                throw new RuntimeException('Could not verify the bulk submission status');
            }
            $batch = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$batch) {
                throw new RuntimeException('The batch was not found');
            }
            $nextStatus = $batch['status'] === 'Pending'
                ? 'For Release'
                : ($batch['status'] === 'For Release' ? 'Released' : '');
            if ($nextStatus === '') {
                throw new RuntimeException('This batch has already been released');
            }
            if ($batch['status'] === 'Pending' && $batch['received_at'] === null) {
                throw new DomainException('Confirm receipt in Admin before moving this bulk submission to For Release.');
            }
            $stmt = safe_query($conn, 'UPDATE document_batches SET status = ? WHERE batch_id = ?', 'si', [$nextStatus, $batchId]);
            if ($stmt === false) {
                throw new RuntimeException('Could not update batch status');
            }
            mysqli_stmt_close($stmt);
            $stmt = safe_query($conn, 'UPDATE documents d INNER JOIN batch_papers p ON p.doc_id = d.doc_id SET d.status = ? WHERE p.batch_id = ?', 'si', [$nextStatus, $batchId]);
            if ($stmt === false) {
                throw new RuntimeException('Could not update linked paper statuses');
            }
            mysqli_stmt_close($stmt);
            $conn->commit();
            echo json_encode([
                'success' => true,
                'previous_status' => $batch['status'],
                'status' => $nextStatus,
                'paper_count' => (int)$batch['paper_count'],
            ]);
        } catch (Throwable $error) {
            $conn->rollback();
            error_log('Mobile batch status update failed: ' . $error->getMessage());
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => $error instanceof DomainException
                ? $error->getMessage()
                : 'The bulk submission status could not be updated. Refresh the list and try again.']);
        }
        exit;
    }

    if ($action === 'add_batch' || $action === 'update_batch') {
        $documentType = trim($input['document_type'] ?? '');
        $batchType = trim($input['batch_type'] ?? '');
        $submitterName = trim($input['submitter_name'] ?? '');
        $isPriority = 0;
        $batchDate = trim($input['batch_date'] ?? '');
        $senderType = $mobileFormLabels['senderEnabled'] ? trim($input['sender_type'] ?? '') : '';
        $recipientContactType = 'Unassigned';
        $recipient = '';
        $batchRemarks = trim($input['remarks'] ?? '');
        $paperCount = filter_var($input['paper_count'] ?? null, FILTER_VALIDATE_INT);
        [$documentPagesJson, $validPages] = normalize_document_pages($input['document_pages'] ?? null, is_int($paperCount) ? max(0, min($paperCount, 9999)) : 0);
        $batchId = (int)($input['batch_id'] ?? 0);
        if ($action === 'add_batch') {
            $batchDate = date('Y-m-d');
        }
        $dateObject = DateTime::createFromFormat('!Y-m-d', $batchDate);
        if (!in_array($documentType, ['Incoming', 'Outgoing'], true) ||
            $batchType === '' || strlen($batchType) > 255 ||
            $submitterName === '' || strlen($submitterName) > 255 ||
            ($action === 'add_batch' && (!$dateObject || $dateObject->format('Y-m-d') !== $batchDate || $batchDate < date('Y-m-d'))) ||
            ($mobileFormLabels['senderEnabled'] && !in_array($senderType, ['Student', 'Faculty'], true)) ||
            $paperCount === false || $paperCount < 1 || $paperCount > 3 ||
            !$validPages ||
            ($action === 'update_batch' && $batchId <= 0)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Enter the submitter name and check the bulk submission type, date, submitter type, and number of documents (1–3).']);
            exit;
        }

        try {
            // Batch edits and linked document updates must commit or roll back together.
            $conn->begin_transaction();
            $recipientValue = $documentType === 'Incoming' ? 'Dean\'s Office' : '';
            if ($action === 'add_batch') {
                $batchCode = 'BP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $trackingToken = bin2hex(random_bytes(32));
                $recipientValue = '';
                $stmt = safe_query(
                    $conn,
                    'INSERT INTO document_batches (batch_code, document_type, batch_type, status, batch_date, sender, sender_type, submitter_name, recipient, recipient_contact_type, remarks,                                         tracking_token, paper_count, document_pages, is_priority) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                                        'ssssssssssssisi',
                                        [$batchCode, $documentType, $batchType, 'Pending', $batchDate, $senderType, $senderType, $submitterName, $recipientValue, $recipientContactType, $batchRemarks, $trackingToken, $paperCount, $documentPagesJson, $isPriority]
                );
                if ($stmt === false) throw new RuntimeException('Could not create the batch');
                mysqli_stmt_close($stmt);
                $batchId = mysqli_insert_id($conn);
            } else {
                $existsStmt = safe_query($conn, 'SELECT batch_id, status, is_priority, paper_count, received_at FROM document_batches WHERE batch_id = ? FOR UPDATE', 'i', [$batchId]);
                if ($existsStmt === false) throw new RuntimeException('Could not verify the batch');
                $existingBatch = mysqli_fetch_assoc(mysqli_stmt_get_result($existsStmt));
                mysqli_stmt_close($existsStmt);
                if (!$existingBatch) throw new RuntimeException('The batch was not found');
                if ($existingBatch['received_at'] !== null && $paperCount !== (int)$existingBatch['paper_count']) {
                    throw new RuntimeException('The number of documents cannot be changed after staff confirm receipt.');
                }
                $batchStatus = $existingBatch['status'];
                $isPriority = (int)$existingBatch['is_priority'];
                $stmt = safe_query(
                    $conn,
                    'UPDATE document_batches SET document_type = ?, batch_type = ?, sender = ?, sender_type = ?, submitter_name = ?,                     remarks = ?,                     paper_count = ?, document_pages = ? WHERE batch_id = ?',
                                        'ssssssisi',
                                        [$documentType, $batchType, $senderType, $senderType, $submitterName, $batchRemarks, $paperCount, $documentPagesJson, $batchId]
                );
                if ($stmt === false) throw new RuntimeException('Could not update batch details');
                mysqli_stmt_close($stmt);

                $stmt = safe_query(
                    $conn,
                    'UPDATE documents d INNER JOIN batch_papers p ON p.doc_id = d.doc_id SET d.type = ?, d.sender = ?, d.submitter_type = ?, d.status = ?, d.is_priority = ? WHERE p.batch_id = ?',
                    'ssssii',
                    [$documentType, $senderType, $senderType, $batchStatus, $isPriority, $batchId]
                );
                if ($stmt === false) throw new RuntimeException('Could not update linked paper records');
                mysqli_stmt_close($stmt);
            }

            $conn->commit();
            if ($action === 'update_batch') {
                echo json_encode(['success' => true, 'message' => 'Bulk submission updated']);
                exit;
            }
            echo json_encode(['success' => true, 'message' => 'Bulk submission saved', 'batch' => [
                'id' => $batchId,
                'batchCode' => $batchCode,
                'trackingToken' => $trackingToken,
                'documentType' => $documentType,
                'batchType' => $batchType,
                'status' => $action === 'add_batch' ? 'Pending' : $batchStatus,
                'isPriority' => $isPriority,
                'batchDate' => $batchDate,
                'sender' => $senderType,
                'senderType' => $senderType,
                'submitterName' => $submitterName,
                'recipient' => $recipientValue,
                'recipientContactType' => $recipientContactType,
                'paperCount' => $paperCount,
                'documentPages' => $documentPagesJson === null ? [] : json_decode($documentPagesJson, true),
                'remarks' => $batchRemarks,
            ]]);
        } catch (Throwable $error) {
            $conn->rollback();
            error_log('Mobile batch save/update failed: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'The bulk submission could not be saved or updated']);
        }
    } elseif ($action === 'add_incoming' || $action === 'quick_submit_incoming') {
        $isQuickSubmit = $action === 'quick_submit_incoming';
        $sender = $mobileFormLabels['senderEnabled'] ? trim($input['sender'] ?? '') : '';
        $submitterName = is_string($input['submitter_name'] ?? null) ? trim($input['submitter_name']) : '';
        $recipient = 'Dean\'s Office';
        $subject = trim($input['subject'] ?? '');
        $remarks = trim($input['remarks'] ?? '');
        $clientPhone = contact_number_input($input['client_phone'] ?? '');
        $submitterType = $mobileFormLabels['senderEnabled']
            ? trim($input['submitter_type'] ?? $sender)
            : 'Other';
        $validClientPhone = $clientPhone !== null && (
            $isQuickSubmit
                ? ($clientPhone === '' || is_valid_contact_number($clientPhone))
                : is_valid_contact_number($clientPhone)
        );
        if ((!$mobileFormLabels['senderEnabled'] || $sender !== '') &&
            $submitterName !== '' && strlen($submitterName) <= 255 &&
            $subject && $validClientPhone &&
            in_array($submitterType, ['Student', 'Faculty', 'Other'], true)) {
            $phoneValue = $clientPhone === '' ? null : $clientPhone;
            $entrySource = $isQuickSubmit ? 'quick_submit' : 'standard';
            $conn->begin_transaction();
            try {
                $stmt = safe_query($conn, "INSERT INTO documents(type, sender, submitter_name, recipient, client_phone, subject, remarks, status, date_created, entry_source, submitter_type) VALUES('Incoming', ?, ?, ?, ?, ?, ?, 'Pending', NOW(), ?, ?)", 'ssssssss', [$sender, $submitterName, $recipient, $phoneValue, $subject, $remarks, $entrySource, $submitterType]);
                if ($stmt === false) {
                    throw new RuntimeException('Incoming document insert failed');
                }
                $documentId = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
                $logStmt = safe_query(
                    $conn,
                    'INSERT INTO mobile_submission_logs(document_id, submission_type, sender, subject, recipient) VALUES(?, ?, ?, ?, ?)',
                    'issss',
                    [$documentId, $isQuickSubmit ? 'Quick Submit' : 'Standard Submit', $sender, $subject, $recipient]
                );
                if ($logStmt === false) {
                    throw new RuntimeException('Mobile submission log insert failed');
                }
                mysqli_stmt_close($logStmt);
                error_log(sprintf(
                    '[Mobile submission] %s | %s | Incoming document #%d saved',
                    date('Y-m-d H:i:s'),
                    $isQuickSubmit ? 'Quick Submit' : 'Standard Submit',
                    $documentId
                ));
                $trackingToken = null;
                if ($documentTrackingSettings['enabled']) {
                    $trackingToken = create_document_tracking_token();
                    $tokenStmt = safe_query(
                        $conn,
                        'UPDATE documents SET tracking_token = ? WHERE doc_id = ? AND tracking_token IS NULL',
                        'si',
                        [$trackingToken, $documentId]
                    );
                    if ($tokenStmt === false || mysqli_stmt_affected_rows($tokenStmt) !== 1) {
                        if ($tokenStmt !== false) {
                            mysqli_stmt_close($tokenStmt);
                        }
                        throw new RuntimeException('Document tracking QR could not be issued');
                    }
                    mysqli_stmt_close($tokenStmt);
                }
                $conn->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'Incoming document submitted',
                    'id' => $documentId,
                    'status' => 'Pending',
                    'reference' => document_tracking_reference($documentId),
                    'submitterName' => $submitterName,
                    'trackerUrl' => $trackingToken === null
                        ? null
                        : $documentTrackingSettings['baseUrl'] . '?token=' . rawurlencode($trackingToken),
                ]);
            } catch (Throwable $error) {
                $conn->rollback();
                error_log('Mobile incoming submission failed: ' . $error->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'The document could not be submitted. Please try again.']);
            }
        } else {
            if (!$validClientPhone) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $isQuickSubmit
                    ? 'Enter an 11-digit contact number using digits only, or leave it blank.'
                    : 'Enter an 11-digit contact number using numbers only.']);
                exit;
            }
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        }
    } elseif ($action === 'add_outgoing') {
        $sender = trim($input['sender'] ?? '');
        $recipient = '';
        $subject = trim($input['subject'] ?? '');
        $remarks = trim($input['remarks'] ?? '');
        $clientPhone = contact_number_input($input['client_phone'] ?? '');
        $validClientPhone = $clientPhone !== null && ($clientPhone === '' || is_valid_contact_number($clientPhone));

        if ($sender && $subject && $validClientPhone) {
            $phoneValue = $clientPhone === '' ? null : $clientPhone;
            $stmt = safe_query($conn, "INSERT INTO documents(type, sender, recipient, client_phone, subject, remarks, status, date_created) VALUES('Outgoing', ?, ?, ?, ?, ?, 'For Release', NOW())", 'sssss', [$sender, $recipient, $phoneValue, $subject, $remarks]);
            if ($stmt !== false) {
                $documentId = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
                echo json_encode(['success' => true, 'message' => 'Outgoing document added', 'id' => $documentId, 'status' => 'For Release']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to add outgoing document']);
            }
        } else {
            if (!$validClientPhone) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Enter an 11-digit contact number using digits only, or leave it blank.']);
                exit;
            }
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        }
    } elseif ($action === 'mark_borrowed_returned') {
        $itemId = (int)($input['item_id'] ?? 0);
        if ($itemId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid borrowed item ID']);
            exit;
        }

        $stmt = safe_query($conn, "UPDATE borrowed_items SET status = 'Returned', returned_date = COALESCE(returned_date, CURDATE()) WHERE item_id = ? AND status != 'Returned'", 'i', [$itemId]);
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not update the borrowed item']);
            exit;
        }
        $updated = mysqli_stmt_affected_rows($stmt) > 0;
        mysqli_stmt_close($stmt);
        if (!$updated) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'The borrowed item was not found or has already been returned']);
            exit;
        }
        echo json_encode(['success' => true, 'status' => 'Returned', 'returned' => date('Y-m-d')]);
        exit;
    } elseif ($action === 'add_borrowed') {
        $item = trim($input['item'] ?? '');
        $borrower = trim($input['borrower'] ?? '');
        $borrowed_date = trim($input['borrowed_date'] ?? '');
        $borrower_phone = contact_number_input($input['borrower_phone'] ?? '');
        $category_id = $input['category_id'] ?? null; // Allow null for category_id
        $borrowedDateObject = DateTime::createFromFormat('!Y-m-d', $borrowed_date);

        if ($item && $borrower && $borrower_phone !== null && ($borrower_phone === '' || is_valid_contact_number($borrower_phone)) && $borrowedDateObject && $borrowedDateObject->format('Y-m-d') === $borrowed_date) {
            $loanStatus = 'Borrowed';
            $borrowerPhoneValue = $borrower_phone === '' ? null : $borrower_phone;
            $stmt = safe_query($conn, 'INSERT INTO borrowed_items(item_name, category_id, borrower, borrower_phone, returned_date, date_borrowed, status) VALUES(?, ?, ?, ?, NULL, ?, ?)', 'sissss', [$item, $category_id, $borrower, $borrowerPhoneValue, $borrowed_date, $loanStatus]);
            if ($stmt !== false) {
                $itemId = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
                echo json_encode(['success' => true, 'message' => 'Borrowed item recorded', 'id' => $itemId, 'status' => $loanStatus]);
            } else {
                echo json_encode(['success' => false, 'message' => 'The borrowed item could not be recorded. Please try again.']);
            }
        } else {
            if ($borrower_phone === null || ($borrower_phone !== '' && !is_valid_contact_number($borrower_phone))) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Enter an 11-digit contact number using numbers only, or leave it blank.']);
                exit;
            }
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit;
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01');

function fetch_scalar($conn, $query, $types = '', $params = []) {
    $stmt = mysqli_prepare($conn, $query);
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $value);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return $value ?: 0;
}

function fetch_rows($conn, $query, $types = '', $params = []) {
    $stmt = mysqli_prepare($conn, $query);
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

$dashboard = [
    'incomingToday' => fetch_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Incoming' AND DATE(date_created) = ?", 's', [$today]),
    'incomingMonth' => fetch_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Incoming' AND DATE(date_created) >= ?", 's', [$monthStart]),
    'outgoingToday' => fetch_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Outgoing' AND DATE(date_created) = ?", 's', [$today]),
    'outgoingMonth' => fetch_scalar($conn, "SELECT COUNT(*) FROM documents WHERE type = 'Outgoing' AND DATE(date_created) >= ?", 's', [$monthStart]),
    'borrowedCurrent' => fetch_scalar($conn, "SELECT COUNT(*) FROM borrowed_items WHERE status != 'Returned'"),
    'smsToday' => fetch_scalar($conn, "SELECT COUNT(*) FROM sms_logs WHERE DATE(date_sent) = ?", 's', [$today]),
    'smsMonth' => fetch_scalar($conn, "SELECT COUNT(*) FROM sms_logs WHERE DATE(date_sent) >= ?", 's', [$monthStart]),
];

$statusRows = fetch_rows($conn, "SELECT status, COUNT(*) AS count FROM sms_logs GROUP BY status");
$smsSummary = ['delivered' => 0, 'pending' => 0, 'failed' => 0];
foreach ($statusRows as $row) {
    $key = strtolower($row['status']);
    if (in_array($key, ['delivered', 'success'], true)) {
        $smsSummary['delivered'] += (int)$row['count'];
    } elseif (in_array($key, ['pending', 'queued', 'sent'], true)) {
        $smsSummary['pending'] += (int)$row['count'];
    } elseif ($key === 'failed') {
        $smsSummary['failed'] += (int)$row['count'];
    }
}
$smsSummary['total'] = $dashboard['smsMonth'];

$systemOverview = [
    'totalUsers' => fetch_scalar($conn, "SELECT COUNT(*) FROM users"),
    'documentCount' => fetch_scalar($conn, "SELECT COUNT(*) FROM documents"),
    'borrowedCount' => fetch_scalar($conn, "SELECT COUNT(*) FROM borrowed_items"),
    'categories' => fetch_scalar($conn, "SELECT COUNT(DISTINCT type) FROM documents"),
];

$response = [
    'mobileKioskEnabled' => $officeKioskEnabled,
    'documentTrackerEnabled' => $documentTrackingSettings['enabled'],
    'documentTrackerBaseUrl' => $documentTrackingSettings['baseUrl'],
    'batchTrackerBaseUrl' => $documentTrackingSettings['configuredBaseUrl'] === ''
        ? ''
        : $documentTrackingSettings['configuredBaseUrl'] . '/batch_tracker',
    'formLabels' => $mobileFormLabels,
    'mobileSettings' => $mobileAppSettings,
    'dashboard' => $dashboard,
    'smsSummary' => $smsSummary,
    'systemOverview' => $systemOverview,
    'documentStatuses' => fetch_rows($conn, "SELECT status, COUNT(*) AS count FROM documents GROUP BY status ORDER BY status"),
    'recentDocuments' => fetch_rows($conn, "SELECT doc_id AS id, CONCAT('DIT-', LPAD(doc_id, 6, '0')) AS reference, date_created AS date, type, sender, submitter_name AS submitterName, recipient, client_phone AS clientPhone, subject AS details, remarks, CONCAT(sender, ' / ', recipient) AS fromTo, status, submitter_type AS submitterType, is_priority AS isPriority FROM documents ORDER BY date_created DESC LIMIT 50"),
    'recentBatches' => fetch_rows($conn, "SELECT b.batch_id AS id, b.batch_code AS batchCode, b.document_type AS documentType, b.batch_type AS batchType, b.status, b.batch_date AS batchDate, b.sender_type AS senderType, b.submitter_name AS submitterName, b.recipient, b.recipient_contact_type AS recipientContactType, b.remarks, b.paper_count AS paperCount, b.document_pages AS documentPages, b.received_paper_count AS receivedPaperCount, b.intake_expected_count AS intakeExpectedCount, b.received_at AS receivedAt, b.is_priority AS isPriority FROM document_batches b ORDER BY b.is_priority DESC, b.batch_date DESC, b.batch_id DESC LIMIT 20"),
    'borrowedItems' => fetch_rows($conn, "SELECT item_id AS id, item_name AS item, borrower, DATE(date_borrowed) AS borrowed, DATE(returned_date) AS returned, CASE WHEN status = 'Returned' THEN 'Returned' ELSE 'Borrowed' END AS status FROM borrowed_items ORDER BY date_borrowed DESC LIMIT 5"),
    'smsLogs' => fetch_rows($conn, "SELECT recipient AS user, message, status, DATE_FORMAT(date_sent, '%h:%i %p') AS time FROM sms_logs ORDER BY date_sent DESC LIMIT 5"),
];

echo json_encode($response);
