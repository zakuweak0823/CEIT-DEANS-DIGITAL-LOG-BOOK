<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
ensure_admin_role();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Use the backup button in Admin Settings.');
}

$dumpTool = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin'
    . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'mysqldump.exe' : 'mysqldump');
if (!is_file($dumpTool) || !function_exists('proc_open')) {
    error_log('Database backup unavailable: mysqldump or proc_open is unavailable.');
    http_response_code(503);
    exit('Database backup is unavailable. Contact the server administrator.');
}

$engineCheck = safe_query($conn, "SELECT COUNT(*) AS unsupported FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND (ENGINE IS NULL OR ENGINE <> 'InnoDB')", 's', [$db]);
if ($engineCheck === false) {
    http_response_code(500);
    exit('Database backup could not verify storage engines. Check the server log.');
}
$engineResult = mysqli_stmt_get_result($engineCheck);
if ($engineResult === false || !($engineRow = mysqli_fetch_assoc($engineResult))) {
    mysqli_stmt_close($engineCheck);
    error_log('Database backup could not read storage engines.');
    http_response_code(500);
    exit('Database backup could not verify storage engines. Check the server log.');
}
mysqli_stmt_close($engineCheck);
if ((int)$engineRow['unsupported'] !== 0) {
    error_log('Database backup requires InnoDB tables for a consistent snapshot.');
    http_response_code(503);
    exit('A consistent backup cannot be created for this database. Contact the server administrator.');
}

$output = tmpfile();
$errors = tmpfile();
if ($output === false || $errors === false) {
    if (is_resource($output)) fclose($output);
    if (is_resource($errors)) fclose($errors);
    error_log('Database backup could not create temporary files.');
    http_response_code(500);
    exit('Database backup could not be prepared. Check the server log.');
}

$environment = getenv();
if (!is_array($environment)) $environment = [];
$environment['MYSQL_PWD'] = $pass;
$command = [
    $dumpTool, '--no-defaults', '--host=' . $host, '--user=' . $user,
    '--single-transaction', '--quick', '--routines', '--events', '--triggers',
    '--default-character-set=utf8mb4', '--', $db,
];
$pipes = [];
session_write_close();
$process = proc_open($command, [0 => ['pipe', 'r'], 1 => $output, 2 => $errors], $pipes, null, $environment);
if (!is_resource($process)) {
    fclose($output);
    fclose($errors);
    error_log('Database backup process could not start.');
    http_response_code(500);
    exit('Database backup could not be started. Check the server log.');
}
fclose($pipes[0]);
$exitCode = proc_close($process);
if ($exitCode !== 0 || fstat($output)['size'] === 0) {
    rewind($errors);
    error_log('Database backup failed (exit ' . $exitCode . '): ' . stream_get_contents($errors));
    fclose($output);
    fclose($errors);
    http_response_code(500);
    exit('Database backup failed. Check the server log and try again.');
}
fclose($errors);
rewind($output);
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="ceit-logbook-backup-' . date('Y-m-d-His') . '.sql"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
fpassthru($output);
fclose($output);
