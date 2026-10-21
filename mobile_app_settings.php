<?php
function mobile_app_settings_defaults(): array
{
    return [
        'theme' => 'classic',
        'headerTitle' => 'CEIT Digital Logbook',
        'officeName' => "Dean's Office",
        'welcomeHeading' => 'Office Logbook',
        'welcomeMessage' => 'Submit and track documents, submit bulk documents, and record borrowed items.',
        'confirmationSound' => true,
        'pullToRefreshSound' => true,
    ];
}

function get_mobile_app_settings($conn): array
{
    $settings = mobile_app_settings_defaults();
    $stmt = safe_query($conn, "SELECT value FROM settings WHERE key_name = 'mobile_app_settings'");
    if ($stmt === false) {
        throw new RuntimeException('Mobile app settings could not be loaded.');
    }

    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || !is_string($row['value'])) {
        return $settings;
    }

    $savedSettings = json_decode($row['value'], true);
    if (!is_array($savedSettings)) {
        return $settings;
    }

    if (in_array($savedSettings['theme'] ?? null, ['classic', 'glass'], true)) {
        $settings['theme'] = $savedSettings['theme'];
    }

    foreach (['headerTitle' => 48, 'officeName' => 48, 'welcomeHeading' => 90, 'welcomeMessage' => 180] as $key => $maxLength) {
        $value = $savedSettings[$key] ?? null;
        if (is_string($value)) {
            $value = trim($value);
            if ($value !== '' && strlen($value) <= $maxLength) {
                $settings[$key] = $value;
            }
        }
    }

    foreach (['confirmationSound', 'pullToRefreshSound'] as $key) {
        if (is_bool($savedSettings[$key] ?? null)) {
            $settings[$key] = $savedSettings[$key];
        }
    }

    return $settings;
}

function save_mobile_app_settings($conn, array $settings): bool
{
    if (!in_array($settings['theme'] ?? null, ['classic', 'glass'], true)) {
        return false;
    }

    $value = ['theme' => $settings['theme']];
    foreach (['headerTitle' => 48, 'officeName' => 48, 'welcomeHeading' => 90, 'welcomeMessage' => 180] as $key => $maxLength) {
        $text = $settings[$key] ?? null;
        if (!is_string($text) || trim($text) === '' || strlen(trim($text)) > $maxLength) {
            return false;
        }
        $value[$key] = trim($text);
    }

    foreach (['confirmationSound', 'pullToRefreshSound'] as $key) {
        if (!is_bool($settings[$key] ?? null)) {
            return false;
        }
        $value[$key] = $settings[$key];
    }

    $encodedValue = json_encode($value, JSON_UNESCAPED_UNICODE);
    if (!is_string($encodedValue)) {
        return false;
    }

    $stmt = safe_query(
        $conn,
        "INSERT INTO settings (key_name, value) VALUES ('mobile_app_settings', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
        's',
        [$encodedValue]
    );
    if ($stmt === false) {
        return false;
    }

    mysqli_stmt_close($stmt);
    return true;
}
?>
