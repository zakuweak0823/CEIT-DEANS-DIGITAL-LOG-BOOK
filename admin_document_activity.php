<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once 'auth.php';
require_once 'db.php';

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

$statement = safe_query($conn, 'SELECT COALESCE(MAX(doc_id), 0) AS latest_document_id FROM documents');
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

echo json_encode(['success' => true, 'latestDocumentId' => (int)$row['latest_document_id']]);
