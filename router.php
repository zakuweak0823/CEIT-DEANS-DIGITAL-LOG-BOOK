<?php
$adminPages = [
    'dashboard',
    'quick_document',
    'incoming',
    'outgoing',
    'batch_papers',
    'batch_tracker',
    'borrowed',
    'item_categories',
    'sms',
    'notification_settings',
    'reports',
    'records',
    'login',
    'logout',
];

// Keep clean routes explicit so unknown paths are never mapped to arbitrary PHP files.
$requestPath = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Canonicalize browser navigation, but never redirect POSTs that carry form submissions.
if (in_array($requestMethod, ['GET', 'HEAD'], true)
    && preg_match('~/(dashboard|quick_document|incoming|outgoing|batch_papers|batch_tracker|borrowed|item_categories|sms|notification_settings|reports|records|login|logout)\.php$~i', $requestPath, $matches)
) {
    $query = (string)($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ' . substr($requestPath, 0, -4) . ($query !== '' ? '?' . $query : ''), true, 302);
    exit;
}

if ($requestPath === '/' || $requestPath === '') {
    $page = 'dashboard';
} else {
    $page = trim($requestPath, '/');
}

if (in_array($page, $adminPages, true)) {
    $scriptPath = __DIR__ . DIRECTORY_SEPARATOR . $page . '.php';
    if (!is_file($scriptPath)) {
        http_response_code(404);
        exit('Page not found.');
    }

    $_SERVER['SCRIPT_FILENAME'] = $scriptPath;
    $_SERVER['SCRIPT_NAME'] = '/' . $page . '.php';
    require $scriptPath;
    // Returning true tells PHP's built-in server that the router handled this request.
    return true;
}

// Let the built-in server serve existing assets and API endpoints normally.
$filePath = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $requestPath);
if ($requestPath !== '/' && is_file($filePath)) {
    return false;
}

return false;
