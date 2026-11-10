<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/document_tracking.php';
require_once __DIR__ . '/../includes/status_support.php';

if (empty($_SESSION['admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Admin session required']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$statement = safe_query(
    $conn,
    "SELECT COALESCE(MAX(doc_id), 0) AS latest_document_id,
        (SELECT COUNT(*) FROM documents WHERE type = 'Incoming' AND status IN (" . doc_status_open_sql() . ")) AS pending_count
     FROM documents"
);
if ($statement === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Document activity could not be checked']);
    exit;
}

$result = mysqli_stmt_get_result($statement);
$row = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($statement);
if ($row === null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Document activity could not be checked']);
    exit;
}

$incomingStatement = safe_query(
    $conn,
    "SELECT doc_id, local_reference, subject, status, DATE_FORMAT(date_created, '%Y-%m-%d') AS date
     FROM documents
     WHERE type = 'Incoming' AND status IN (" . doc_status_open_sql() . ")
     ORDER BY date_created DESC, doc_id DESC
     LIMIT 8"
);
if ($incomingStatement === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Incoming document notifications could not be loaded']);
    exit;
}
$incomingResult = mysqli_stmt_get_result($incomingStatement);
if ($incomingResult === false) {
    mysqli_stmt_close($incomingStatement);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Incoming document notifications could not be read']);
    exit;
}
$incomingDocuments = [];
while ($document = mysqli_fetch_assoc($incomingResult)) {
    $incomingDocuments[] = [
        'id' => (int)$document['doc_id'],
        'reference' => document_tracking_reference($document['doc_id'], $document['local_reference']),
        'subject' => (string)$document['subject'],
        'status' => (string)$document['status'],
        'date' => (string)$document['date'],
    ];
}
mysqli_stmt_close($incomingStatement);

echo json_encode([
    'success' => true,
    'latestDocumentId' => (int)$row['latest_document_id'],
    'pendingCount' => (int)$row['pending_count'],
    'incomingDocuments' => $incomingDocuments,
]);
