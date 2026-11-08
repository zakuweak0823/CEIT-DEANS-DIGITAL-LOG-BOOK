<?php
function fetch_scalar(mysqli $conn, string $query, string $types = '', array $params = []) {
    $stmt = mysqli_prepare($conn, $query);
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $value);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return $value ?: 0;
}

function fetch_rows(mysqli $conn, string $query, string $types = '', array $params = []) {
    $stmt = mysqli_prepare($conn, $query);
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}
