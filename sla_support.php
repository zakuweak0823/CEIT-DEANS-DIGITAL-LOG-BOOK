<?php
// Service Level Agreement (SLA) thresholds, stored as one JSON value in the settings table.

function sla_settings_defaults(): array
{
    return [
        'enabled' => true,
        'incomingDays' => 3,
        'outgoingDays' => 2,
        'loanDays' => 7,
    ];
}

function sla_clamp_days($value, int $fallback): int
{
    if (is_string($value) && !preg_match('/^\d{1,3}$/', trim($value))) {
        return $fallback;
    }
    $days = (int)$value;
    return ($days >= 1 && $days <= 365) ? $days : $fallback;
}

function get_sla_settings($conn): array
{
    $defaults = sla_settings_defaults();
    $stmt = safe_query($conn, "SELECT value FROM settings WHERE key_name = 'sla_settings' LIMIT 1");
    if ($stmt === false) {
        return $defaults;
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    $saved = $row ? json_decode((string)$row['value'], true) : null;
    if (!is_array($saved)) {
        return $defaults;
    }
    return [
        'enabled' => !array_key_exists('enabled', $saved) || (bool)$saved['enabled'],
        'incomingDays' => sla_clamp_days($saved['incomingDays'] ?? null, $defaults['incomingDays']),
        'outgoingDays' => sla_clamp_days($saved['outgoingDays'] ?? null, $defaults['outgoingDays']),
        'loanDays' => sla_clamp_days($saved['loanDays'] ?? null, $defaults['loanDays']),
    ];
}

function save_sla_settings($conn, array $settings): bool
{
    $json = json_encode([
        'enabled' => !empty($settings['enabled']),
        'incomingDays' => (int)$settings['incomingDays'],
        'outgoingDays' => (int)$settings['outgoingDays'],
        'loanDays' => (int)$settings['loanDays'],
    ]);
    $stmt = safe_query($conn, "INSERT INTO settings (key_name, value) VALUES ('sla_settings', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)", 's', [$json]);
    if ($stmt === false) {
        return false;
    }
    mysqli_stmt_close($stmt);
    return true;
}

// Returns overdue documents (oldest first) and overdue loans for the configured thresholds.
function get_sla_overdue($conn, array $sla, int $limit = 8): array
{
    $overdue = ['documents' => [], 'documentCount' => 0, 'loans' => [], 'loanCount' => 0];
    if (empty($sla['enabled'])) {
        return $overdue;
    }

    $documentFilter = "((type = 'Incoming' AND status = 'Pending' AND date_created < (NOW() - INTERVAL ? DAY))
        OR (type = 'Outgoing' AND status = 'For Release' AND date_created < (NOW() - INTERVAL ? DAY)))";
    $overdue['documentCount'] = (int)safe_scalar($conn, "SELECT COUNT(*) FROM documents WHERE $documentFilter", 'ii', [$sla['incomingDays'], $sla['outgoingDays']]);
    $stmt = safe_query(
        $conn,
        "SELECT doc_id, type, status, subject, sender, submitter_name, recipient, date_created, DATEDIFF(NOW(), date_created) AS days_open
         FROM documents WHERE $documentFilter ORDER BY date_created ASC LIMIT " . (int)$limit,
        'ii',
        [$sla['incomingDays'], $sla['outgoingDays']]
    );
    if ($stmt !== false) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $overdue['documents'][] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    $overdue['loanCount'] = (int)safe_scalar($conn, "SELECT COUNT(*) FROM borrowed_items WHERE status <> 'Returned' AND date_borrowed < (NOW() - INTERVAL ? DAY)", 'i', [$sla['loanDays']]);
    $stmt = safe_query(
        $conn,
        "SELECT item_id, item_name, borrower, date_borrowed, DATEDIFF(NOW(), date_borrowed) AS days_out
         FROM borrowed_items WHERE status <> 'Returned' AND date_borrowed < (NOW() - INTERVAL ? DAY) ORDER BY date_borrowed ASC LIMIT " . (int)$limit,
        'i',
        [$sla['loanDays']]
    );
    if ($stmt !== false) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $overdue['loans'][] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    return $overdue;
}
