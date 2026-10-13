<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Centralize the connection so admin pages and the mobile API share one UTF-8 database setup.
$host = "localhost";
$user = "root";
$pass = "";
$db = "dit_logbook";

try {
    $conn = mysqli_connect($host, $user, $pass, $db);
    if (!$conn) {
        throw new Exception("Connection Failed: " . mysqli_connect_error());
    }
    mysqli_set_charset($conn, "utf8mb4");
} catch (Exception $e) {
    error_log($e->getMessage());
    die("Database connection error. Please try again later.");
}

function safe_query($conn, $query, $types = '', $params = []) {
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt === false) {
        error_log("Failed to prepare query: " . mysqli_error($conn));
        return false;
    }
    if ($types !== '' && !empty($params)) {
        // mysqli_stmt_bind_param needs references; keep the values alive for the call.
        $refs = array();
        foreach($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }
        mysqli_stmt_bind_param($stmt, $types, ...$refs);
    }
    if (!mysqli_stmt_execute($stmt)) {
        error_log("Failed to execute query: " . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        return false;
    }
    return $stmt;
}

function safe_scalar($conn, $query, $types = '', $params = []) {
    $stmt = safe_query($conn, $query, $types, $params);
    if ($stmt === false) return 0;
    // This helper owns the statement and returns only the first selected value.
    mysqli_stmt_bind_result($stmt, $result);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return $result ?? 0;
}

function contact_number_input($value) {
    return is_string($value) ? trim($value) : null;
}

function is_valid_contact_number($value): bool {
    return is_string($value) && preg_match('/^[0-9]{11}$/', $value) === 1;
}
?>
