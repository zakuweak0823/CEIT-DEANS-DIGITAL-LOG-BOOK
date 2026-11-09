<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
if (!empty($_SESSION['admin'])) {
    record_audit_event($conn, 'logout', (string)$_SESSION['admin']);
}
// Clear the server-side session before redirecting so logout invalidates the current admin session.
session_start();
$_SESSION = [];
session_destroy();
header('Location: login');
exit;
