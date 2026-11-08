<?php
require_once __DIR__ . '/auth.php';

/**
 * Exchange a QR token for a session-bound record reference in the address bar.
 * The token is checked against the database on every later tracker request.
 */
function tracker_session_token($conn, string $kind): string
{
    $sources = [
        'document' => ['documents', 'doc_id'],
        'batch' => ['document_batches', 'batch_id'],
    ];
    if (!isset($sources[$kind])) {
        throw new InvalidArgumentException('Invalid tracker type.');
    }
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');

    if (array_key_exists('token', $_GET)) {
        $token = is_string($_GET['token']) ? trim($_GET['token']) : '';
        $id = null;
        if (preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
            [$table, $column] = $sources[$kind];
            $stmt = safe_query($conn, "SELECT $column FROM $table WHERE tracking_token = ? LIMIT 1", 's', [$token]);
            if ($stmt === false) throw new RuntimeException('Tracker access could not be verified.');
            $result = mysqli_stmt_get_result($stmt);
            if ($result === false) {
                mysqli_stmt_close($stmt);
                throw new RuntimeException('Tracker access could not be read.');
            }
            $row = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
            if ($row) $id = (int)$row[$column];
        }
        if ($id !== null) {
            $_SESSION['tracker_access'][$kind][$id] = $token;
        }
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path[0] !== '/' || strncmp($path, '//', 2) === 0) {
            throw new RuntimeException('Tracker redirect path is invalid.');
        }
        header('Location: ' . $path . ($id === null ? '?invalid=1' : '?record=' . $id), true, 303);
        exit;
    }

    $record = $_GET['record'] ?? '';
    if (!is_string($record) || !ctype_digit($record) || (int)$record <= 0) return '';
    $id = (int)$record;
    $token = $_SESSION['tracker_access'][$kind][$id] ?? '';
    if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) return '';

    [$table, $column] = $sources[$kind];
    $stmt = safe_query($conn, "SELECT $column FROM $table WHERE $column = ? AND tracking_token = ? LIMIT 1", 'is', [$id, $token]);
    if ($stmt === false) throw new RuntimeException('Tracker access could not be verified.');
    $result = mysqli_stmt_get_result($stmt);
    if ($result === false) {
        mysqli_stmt_close($stmt);
        throw new RuntimeException('Tracker access could not be read.');
    }
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    if (!$row) {
        unset($_SESSION['tracker_access'][$kind][$id]);
        return '';
    }
    return $token;
}
