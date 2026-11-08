<?php
// Clear the server-side session before redirecting so logout invalidates the current admin session.
session_start();
$_SESSION = [];
session_destroy();
header('Location: login');
exit;
