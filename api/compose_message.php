<?php
// Returns a generated SMS for the staff preview. Read-only: nothing is saved or sent here.
require_once 'auth.php';
require_once 'db.php';
require_once 'document_tracking.php';
require_once 'message_composer.php';
ensure_admin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$documentId = isset($_GET['doc_id']) && ctype_digit((string)$_GET['doc_id']) ? (int)$_GET['doc_id'] : 0;
$status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
$event = ($_GET['event'] ?? '') === 'requirements_complete' ? 'requirements_complete' : 'status';
$variant = isset($_GET['variant']) && ctype_digit((string)$_GET['variant']) ? min((int)$_GET['variant'], 1000) : 0;
$items = isset($_GET['items']) && is_array($_GET['items']) ? array_slice(array_filter($_GET['items'], 'is_string'), 0, 15) : null;

try {
    $context = composer_document_context($conn, $documentId, get_document_tracking_settings($conn));
} catch (Throwable $error) {
    error_log('Message preview failed: ' . $error->getMessage());
    $context = null;
}
if ($context === null || !doc_status_is_valid($status)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Document not found.']);
    exit;
}
$context['status'] = $status;
$context['event'] = $event;
$context['variant'] = $variant;
if ($items !== null) {
    require_once 'requirements_support.php';
    $context['items'] = requirements_clean_labels($items);
}
$text = compose_sms_message($context);
echo json_encode(['success' => true, 'message' => $text, 'length' => strlen($text), 'hasPhone' => is_valid_contact_number($context['phone'])]);
