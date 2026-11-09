<?php
$pages = [
    'dashboard', 'quick_document', 'incoming', 'outgoing', 'batch_papers',
    'batch_tracker', 'borrowed', 'item_categories', 'sms', 'notification_settings',
    'credentials', 'settings', 'reports', 'records', 'audit_logs', 'login', 'forgot_password',
    'users', 'logout',
];
$apiEndpoints = [
    'admin_document_activity', 'backup_download', 'compose_message',
    'mobile-api', 'server_health',
];

$requestPath = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$projectRoot = dirname(__DIR__);
$basePath = '';
if (PHP_SAPI !== 'cli-server') {
    $basePath = rtrim(str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')))), '/.');
}
if ($basePath !== '' && ($requestPath === $basePath || strncmp($requestPath, $basePath . '/', strlen($basePath) + 1) === 0)) {
    $requestPath = substr($requestPath, strlen($basePath));
}
$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$pageNames = array_merge($pages, ['document_tracker']);
if (in_array($requestMethod, ['GET', 'HEAD'], true)
    && preg_match('~^/(?:pages/)?([a-z_]+)\.php$~i', $requestPath, $matches)
    && in_array(strtolower($matches[1]), $pageNames, true)) {
    $page = strtolower($matches[1]);
    $cleanPath = $page === 'document_tracker' ? 'document-tracker' : $page;
    $query = (string)($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ' . $basePath . '/' . $cleanPath . ($query !== '' ? '?' . $query : ''), true, 302);
    exit;
}

if (preg_match('~^/(?:pages|api|includes|backups|config|database|tools)(?:/|$)~i', $requestPath)
    || preg_match('~(?:^|/)(?:\.env(?:\.[^/]*)?|ceit-logbook-sms\.ini|[^/]+\.(?:sql|sql\.gz|dump))$~i', $requestPath)) {
    http_response_code(404);
    exit('Not found.');
}

$route = trim($requestPath, '/');
if ($route === '') {
    $route = 'dashboard';
}

$script = null;
if ($route === 'document-tracker' || $route === 'document_tracker.php') {
    $script = 'pages/document_tracker.php';
} elseif (in_array($route, $pages, true)) {
    $script = 'pages/' . $route . '.php';
} elseif (substr($route, -4) === '.php') {
    $name = substr($route, 0, -4);
    if (in_array($name, $pages, true) || $name === 'dashboard_old') {
        $script = 'pages/' . $name . '.php';
    } elseif (in_array($name, $apiEndpoints, true)) {
        $script = 'api/' . $name . '.php';
    }
}

if ($script !== null) {
    $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $script);
    if (!is_file($scriptPath)) {
        http_response_code(404);
        exit('Page not found.');
    }
    chdir($projectRoot);
    $_SERVER['SCRIPT_FILENAME'] = $scriptPath;
    $_SERVER['SCRIPT_NAME'] = $basePath . '/' . basename($script);
    require $scriptPath;
    return true;
}

if (PHP_SAPI === 'cli-server') {
    return false;
}
http_response_code(404);
exit('Not found.');
