<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
ensure_super_admin();
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit('Provider configuration is managed on the server.');
}
$basePath = rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])), '/');
header('Location: ' . $basePath . '/notification_settings', true, 303);
exit;
