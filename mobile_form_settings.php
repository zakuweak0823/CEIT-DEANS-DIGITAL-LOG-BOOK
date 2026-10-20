<?php
function mobile_form_label_defaults(): array
{
    return [
        'sender' => 'Submitter type',
        'documentType' => 'Document type',
        'contactNumber' => 'Client contact number',
        'remarks' => 'Remarks',
        'senderEnabled' => true,
    ];
}

function get_mobile_form_labels($conn): array
{
    $defaults = mobile_form_label_defaults();
    $stmt = safe_query($conn, "SELECT value FROM settings WHERE key_name = 'mobile_form_labels'");
    if ($stmt === false) {
        throw new RuntimeException('Form labels could not be loaded.');
    }

    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || !is_string($row['value'])) {
        return $defaults;
    }

    $savedLabels = json_decode($row['value'], true);
    if (!is_array($savedLabels)) {
        return $defaults;
    }

    foreach ($defaults as $key => $default) {
        if ($key === 'senderEnabled') {
            $defaults[$key] = ($savedLabels[$key] ?? true) === true;
            continue;
        }
        $value = $savedLabels[$key] ?? null;
        if (is_string($value)) {
            $value = trim($value);
            if ($key === 'sender' && strcasecmp($value, 'From') === 0) {
                $value = $default;
            }
            if ($value !== '' && strlen($value) <= 60) {
                $defaults[$key] = $value;
            }
        }
    }

    return $defaults;
}

function save_mobile_form_labels($conn, array $labels): bool
{
    $labels['senderEnabled'] = ($labels['senderEnabled'] ?? true) === true;
    $value = json_encode($labels, JSON_UNESCAPED_UNICODE);
    if (!is_string($value)) {
        return false;
    }

    $stmt = safe_query(
        $conn,
        "INSERT INTO settings (key_name, value) VALUES ('mobile_form_labels', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
        's',
        [$value]
    );
    if ($stmt === false) {
        return false;
    }

    mysqli_stmt_close($stmt);
    return true;
}
?>
