<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once 'db.php';

$statement = false;
try {
    $statement = safe_query($conn, 'SELECT 1');
} catch (mysqli_sql_exception $error) {
    error_log('Admin server health check failed: ' . $error->getMessage());
}

if ($statement === false) {
    http_response_code(503);
    header('Retry-After: 15');
    echo json_encode(['success' => false, 'message' => 'The office database is temporarily unavailable.']);
    exit;
}

mysqli_stmt_close($statement);
echo json_encode(['success' => true]);
