<?php
// Printed report settings: what appears on the printed/PDF report (and optionally the Excel export).
// Stored as one JSON value in the existing `settings` table, so no database changes are needed.

const PRINT_REPORT_SETTINGS_KEY = 'print_report_settings';

function print_report_settings_defaults(): array
{
    return [
        'letterhead' => true,          // University / college letterhead with the seal
        'title' => true,               // Report title
        'period' => true,              // Reporting period and record count box
        'footer' => true,              // Footer with office name and date generated
        'preparedBy' => true,          // "Prepared by" line inside the footer
        'columnRole' => true,          // Student / Faculty
        'columnContact' => true,       // Contact number
        'columnStatus' => false,       // Status
        'bulkBatchCode' => true,       // Batch code in Bulk Reports
        'bulkReceivedCount' => true,   // Intake count in Bulk Reports
        'bulkPriority' => true,        // Priority in Bulk Reports
        'excelFollowsPrint' => true,   // Excel export uses the same column choices
    ];
}

function get_print_report_settings($conn): array
{
    $settings = print_report_settings_defaults();
    $stmt = safe_query($conn, "SELECT value FROM settings WHERE key_name = 'print_report_settings' LIMIT 1");
    if ($stmt === false) {
        return $settings;
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result === false ? null : mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    if (!$row) {
        return $settings;
    }
    $saved = json_decode((string)$row['value'], true);
    if (!is_array($saved)) {
        return $settings;
    }
    foreach ($settings as $key => $default) {
        if (isset($saved[$key]) && is_bool($saved[$key])) {
            $settings[$key] = $saved[$key];
        }
    }
    return $settings;
}

function save_print_report_settings($conn, array $submitted): bool
{
    $clean = [];
    foreach (print_report_settings_defaults() as $key => $default) {
        $clean[$key] = ($submitted[$key] ?? false) === true;
    }
    try {
        $json = json_encode($clean, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        error_log('Printed report settings could not be encoded: ' . $error->getMessage());
        return false;
    }
    $stmt = safe_query(
        $conn,
        'INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?',
        'sss',
        [PRINT_REPORT_SETTINGS_KEY, $json, $json]
    );
    if ($stmt === false) {
        return false;
    }
    mysqli_stmt_close($stmt);
    return true;
}

// Columns of each report layout, in the same order as the table on screen.
// 'print' = relative print width, 'excel' = Excel column width.
function report_column_layout(string $reportType): array
{
    if ($reportType === 'Bulk Reports') {
        return [
            ['key' => 'date', 'print' => 12, 'excel' => 16],
            ['key' => 'batch', 'print' => 14, 'excel' => 20],
            ['key' => 'subject', 'print' => 20, 'excel' => 34],
            ['key' => 'sender', 'print' => 16, 'excel' => 24],
            ['key' => 'role', 'print' => 9, 'excel' => 12],
            ['key' => 'recipient', 'print' => 14, 'excel' => 24],
            ['key' => 'count', 'print' => 8, 'excel' => 12],
            ['key' => 'received', 'print' => 9, 'excel' => 16],
            ['key' => 'priority', 'print' => 8, 'excel' => 12],
            ['key' => 'status', 'print' => 10, 'excel' => 18],
        ];
    }
    if ($reportType === 'All Reports') {
        return [
            ['key' => 'date', 'print' => 18, 'excel' => 23],
            ['key' => 'category', 'print' => 22, 'excel' => 27],
            ['key' => 'subject', 'print' => 22, 'excel' => 38],
            ['key' => 'details', 'print' => 38, 'excel' => 65],
            ['key' => 'status', 'print' => 12, 'excel' => 18],
        ];
    }
    if ($reportType === 'SMS Logs') {
        return [
            ['key' => 'date', 'print' => 20, 'excel' => 23],
            ['key' => 'recipient', 'print' => 25, 'excel' => 20],
            ['key' => 'message', 'print' => 55, 'excel' => 70],
            ['key' => 'status', 'print' => 12, 'excel' => 18],
        ];
    }
    if ($reportType === 'Borrowed Items') {
        return [
            ['key' => 'item', 'print' => 35, 'excel' => 34],
            ['key' => 'borrower', 'print' => 30, 'excel' => 28],
            ['key' => 'date', 'print' => 35, 'excel' => 20],
            ['key' => 'status', 'print' => 12, 'excel' => 18],
        ];
    }
    return [
        ['key' => 'date', 'print' => 15, 'excel' => 23],
        ['key' => 'sender', 'print' => 16, 'excel' => 24],
        ['key' => 'role', 'print' => 9, 'excel' => 12],
        ['key' => 'contact', 'print' => 15, 'excel' => 18],
        ['key' => 'recipient', 'print' => 16, 'excel' => 24],
        ['key' => 'subject', 'print' => 29, 'excel' => 48],
        ['key' => 'status', 'print' => 12, 'excel' => 18],
    ];
}

function report_hidden_columns(array $settings): array
{
    $hidden = [];
    if (empty($settings['columnStatus'])) {
        $hidden[] = 'status';
    }
    if (empty($settings['columnRole'])) {
        $hidden[] = 'role';
    }
    if (empty($settings['columnContact'])) {
        $hidden[] = 'contact';
    }
    if (empty($settings['bulkBatchCode'])) {
        $hidden[] = 'batch';
    }
    if (empty($settings['bulkReceivedCount'])) {
        $hidden[] = 'received';
    }
    if (empty($settings['bulkPriority'])) {
        $hidden[] = 'priority';
    }
    return $hidden;
}