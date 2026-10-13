<?php
function ensure_sms_provider_tracking_schema($conn)
{
    try {
        // Existing installations gain provider metadata while legacy rows remain attributed to Semaphore.
        $conn->query('ALTER TABLE sms_logs ADD COLUMN IF NOT EXISTS provider_message_id VARCHAR(64) DEFAULT NULL');
        $conn->query('ALTER TABLE sms_logs ADD COLUMN IF NOT EXISTS provider_status_checked_at DATETIME DEFAULT NULL');
        $conn->query("ALTER TABLE sms_logs ADD COLUMN IF NOT EXISTS provider_name VARCHAR(32) NOT NULL DEFAULT 'Semaphore'");
        return true;
    } catch (mysqli_sql_exception $e) {
        error_log('Unable to prepare SMS provider tracking: ' . $e->getMessage());
        return false;
    }
}

function philsms_api_request($method, $apiToken, $params = [], $messageId = null)
{
    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'The cURL extension is required to communicate with PhilSMS.'];
    }

    $baseUrl = 'https://dashboard.philsms.com/api/v3';
    $url = $baseUrl . '/sms/send';
    if ($messageId !== null) {
        $url = $baseUrl . '/sms/' . rawurlencode((string)$messageId);
    }

    $curl = curl_init();
    if ($curl === false) {
        return ['success' => false, 'error' => 'Unable to initialize the PhilSMS request.'];
    }

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiToken,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
    ];
    if ($method === 'POST') {
        $encodedParams = json_encode($params, JSON_UNESCAPED_UNICODE);
        if ($encodedParams === false) {
            curl_close($curl);
            return ['success' => false, 'error' => 'The SMS message could not be encoded for PhilSMS.'];
        }
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $encodedParams;
    }
    curl_setopt_array($curl, $options);

    $responseBody = curl_exec($curl);
    $statusCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = $responseBody === false ? (curl_error($curl) ?: 'PhilSMS request failed.') : '';
    curl_close($curl);

    if ($responseBody === false) {
        return ['success' => false, 'error' => $curlError];
    }

    $decoded = json_decode($responseBody, true);
    if ($statusCode < 200 || $statusCode >= 300) {
        $providerError = is_array($decoded) ? philsms_error_message($decoded) : '';
        if ($statusCode === 401 || stripos($providerError, 'unauthenticated') !== false || stripos($providerError, 'unauthorized') !== false) {
            return [
                'success' => false,
                'error' => 'PhilSMS rejected the API token. Check that it is active in your PhilSMS Developers dashboard, and enter the token without a "Bearer " prefix.',
            ];
        }
        if ($providerError === '') {
            $providerError = 'PhilSMS returned HTTP ' . $statusCode . '.';
        }
        return ['success' => false, 'error' => $providerError];
    }
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return ['success' => false, 'error' => 'PhilSMS returned an unreadable response.'];
    }
    if (strtolower((string)($decoded['status'] ?? '')) !== 'success') {
        $providerError = philsms_error_message($decoded);
        if (stripos($providerError, 'unauthenticated') !== false || stripos($providerError, 'unauthorized') !== false) {
            return [
                'success' => false,
                'error' => 'PhilSMS rejected the API token. Check that it is active in your PhilSMS Developers dashboard, and enter the token without a "Bearer " prefix.',
            ];
        }
        return ['success' => false, 'error' => $providerError ?: 'PhilSMS did not accept the request.'];
    }

    return ['success' => true, 'data' => $decoded];
}

function philsms_error_message($responseData)
{
    foreach (['message', 'error', 'errors'] as $key) {
        if (!array_key_exists($key, $responseData)) {
            continue;
        }
        $value = $responseData[$key];
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        if (is_array($value)) {
            $messages = [];
            foreach ($value as $field => $detail) {
                if (is_string($detail) && trim($detail) !== '') {
                    $messages[] = is_string($field) ? $field . ': ' . trim($detail) : trim($detail);
                } elseif (is_array($detail)) {
                    foreach ($detail as $item) {
                        if (is_string($item) && trim($item) !== '') {
                            $messages[] = (is_string($field) ? $field . ': ' : '') . trim($item);
                        }
                    }
                }
            }
            if ($messages) {
                return implode(' ', array_slice($messages, 0, 3));
            }
        }
    }
    return '';
}

function philsms_message_record($responseData)
{
    if (!is_array($responseData)) {
        return [];
    }
    if (isset($responseData['data']) && is_array($responseData['data'])) {
        return philsms_message_record($responseData['data']);
    }
    if (isset($responseData[0]) && is_array($responseData[0])) {
        return $responseData[0];
    }
    return $responseData;
}

function philsms_message_id($messageRecord)
{
    foreach (['uid', 'message_id', 'id'] as $key) {
        if (isset($messageRecord[$key]) && is_scalar($messageRecord[$key]) && (string)$messageRecord[$key] !== '') {
            return (string)$messageRecord[$key];
        }
    }
    return null;
}

function philsms_status_value($messageRecord)
{
    foreach (['status', 'message_status', 'delivery_status'] as $key) {
        if (isset($messageRecord[$key]) && is_string($messageRecord[$key])) {
            $status = trim($messageRecord[$key]);
            if ($status !== '' && strtolower($status) !== 'success') {
                return $status;
            }
        }
    }
    return '';
}

function normalize_philsms_api_token($apiToken)
{
    return trim((string)preg_replace('/^Bearer\s+/i', '', trim((string)$apiToken)));
}

function get_philsms_api_token($conn)
{
    $stmt = safe_query($conn, "SELECT value FROM settings WHERE key_name = 'philsms_api_token' LIMIT 1");
    if ($stmt !== false) {
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
        if ($row && trim((string)$row['value']) !== '') {
            return normalize_philsms_api_token($row['value']);
        }
    }
    return normalize_philsms_api_token(getenv('PHILSMS_API_TOKEN') ?: '');
}

function get_philsms_sender_id($conn)
{
    $stmt = safe_query($conn, "SELECT value FROM settings WHERE key_name = 'philsms_sender_id' LIMIT 1");
    if ($stmt !== false) {
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
        if ($row && trim((string)$row['value']) !== '') {
            return trim((string)$row['value']);
        }
    }
    return trim((string)(getenv('PHILSMS_SENDER_ID') ?: 'PhilSMS'));
}

function normalize_philsms_recipient($recipient)
{
    $digits = preg_replace('/\D+/', '', (string)$recipient);
    if (strlen($digits) === 11 && $digits[0] === '0') {
        return '63' . substr($digits, 1);
    }
    if (strlen($digits) === 10 && $digits[0] === '9') {
        return '63' . $digits;
    }
    return $digits;
}

function log_sms_attempt($conn, $recipient, $message, $status, $messageId = null)
{
    $logStmt = safe_query(
        $conn,
        'INSERT INTO sms_logs (recipient, message, status, provider_message_id, provider_name) VALUES (?, ?, ?, ?, ?)',
        'sssss',
        [$recipient, $message, $status, $messageId, 'PhilSMS']
    );
    if ($logStmt === false) {
        return false;
    }
    mysqli_stmt_close($logStmt);
    return true;
}

function update_logged_sms_attempt($conn, $smsId, $status, $messageId = null)
{
    $updateStmt = safe_query(
        $conn,
        'UPDATE sms_logs
         SET status = ?, provider_message_id = ?, provider_name = ?, provider_status_checked_at = NULL, date_sent = CURRENT_TIMESTAMP
         WHERE sms_id = ?',
        'sssi',
        [$status, $messageId, 'PhilSMS', $smsId]
    );
    if ($updateStmt === false) {
        return false;
    }
    mysqli_stmt_close($updateStmt);
    return true;
}

function persist_sms_attempt($conn, $recipient, $message, $status, $messageId = null, $existingSmsId = null)
{
    if ($existingSmsId !== null) {
        return update_logged_sms_attempt($conn, (int)$existingSmsId, $status, $messageId);
    }
    return log_sms_attempt($conn, $recipient, $message, $status, $messageId);
}

function send_logged_sms($conn, $recipient, $message, $existingSmsId = null)
{
    $recipient = trim((string)$recipient);
    $message = trim((string)$message);
    if ($recipient === '' || $message === '') {
        return ['success' => false, 'status' => 'Failed', 'message' => 'Recipient number and message are required.'];
    }
    $recipient = normalize_philsms_recipient($recipient);
    if (strlen($recipient) < 7 || strlen($recipient) > 15) {
        return ['success' => false, 'status' => 'Failed', 'message' => 'Enter a valid recipient phone number.'];
    }
    if (!ensure_sms_provider_tracking_schema($conn)) {
        return ['success' => false, 'status' => 'Failed', 'message' => 'SMS tracking could not be prepared. No message was sent.'];
    }

    $smsEnabled = safe_scalar($conn, "SELECT value FROM settings WHERE key_name = 'sms_enabled' LIMIT 1");
    if (empty($smsEnabled)) {
        persist_sms_attempt($conn, $recipient, $message, 'Failed', null, $existingSmsId);
        return ['success' => false, 'status' => 'Failed', 'message' => 'SMS notifications are disabled in Notification Settings.'];
    }
    $apiToken = get_philsms_api_token($conn);
    if ($apiToken === '') {
        persist_sms_attempt($conn, $recipient, $message, 'Failed', null, $existingSmsId);
        return ['success' => false, 'status' => 'Failed', 'message' => 'No PhilSMS API token is configured in Notification Settings.'];
    }

    $apiResult = philsms_api_request('POST', $apiToken, [
        'recipient' => $recipient,
        'sender_id' => get_philsms_sender_id($conn),
        'type' => preg_match('/[^\x00-\x7F]/', $message) ? 'unicode' : 'plain',
        'message' => $message,
    ]);
    $messageRecord = !empty($apiResult['success']) ? philsms_message_record($apiResult['data']) : [];
    $messageId = philsms_message_id($messageRecord);
    $providerStatus = philsms_status_value($messageRecord);
    $accepted = !empty($apiResult['success']);
    $error = $apiResult['error'] ?? '';

    // PhilSMS accepts a successful API response even if an ID is unexpectedly omitted.
    // Keep it Pending rather than implying delivery or encouraging an unsafe duplicate retry.
    $logStatus = $accepted ? ($providerStatus !== '' ? $providerStatus : 'Pending') : 'Failed';
    $logged = persist_sms_attempt($conn, $recipient, $message, $logStatus, $messageId, $existingSmsId);
    if (!$logged && $accepted) {
        $error = 'PhilSMS accepted the message, but its delivery record could not be saved.';
    }

    return [
        'success' => $accepted && strtolower($providerStatus) !== 'failed',
        'status' => $logStatus,
        'logged' => $logged,
        'provider_message_id' => $messageId,
        'message' => $accepted && strtolower($logStatus) !== 'failed'
            ? ($logged
                ? ($messageId !== null
                    ? 'PhilSMS accepted the message. Its delivery status can be refreshed from the SMS logs.'
                    : 'PhilSMS accepted the message, but returned no message ID. Its delivery status cannot be refreshed; do not resend it yet.')
                : 'PhilSMS accepted the message, but the local delivery record was not saved. Check PhilSMS before trying again.')
            : ($error !== '' ? $error : 'SMS failed.'),
    ];
}

function refresh_philsms_sms_statuses($conn)
{
    if (!ensure_sms_provider_tracking_schema($conn)) {
        return ['success' => false, 'updated' => 0, 'checked' => 0, 'pending' => 0, 'failed' => 0, 'untracked' => 0, 'message' => 'SMS tracking could not be prepared.'];
    }
    $apiToken = get_philsms_api_token($conn);
    if ($apiToken === '') {
        return ['success' => false, 'updated' => 0, 'checked' => 0, 'pending' => 0, 'failed' => 0, 'untracked' => 0, 'message' => 'No PhilSMS API token is configured.'];
    }

    $untrackedPhilsms = (int)safe_scalar(
        $conn,
        "SELECT COUNT(*) FROM sms_logs
         WHERE LOWER(status) IN ('pending', 'queued', 'sent', 'processing') AND provider_message_id IS NULL
           AND provider_name = 'PhilSMS'"
    );
    $legacyPending = (int)safe_scalar(
        $conn,
        "SELECT COUNT(*) FROM sms_logs
         WHERE LOWER(status) IN ('pending', 'queued', 'sent', 'processing')
           AND provider_name <> 'PhilSMS'"
    );
    $untracked = $untrackedPhilsms + $legacyPending;
    $pendingCount = (int)safe_scalar(
        $conn,
        "SELECT COUNT(*) FROM sms_logs
         WHERE LOWER(status) IN ('pending', 'queued', 'sent', 'processing') AND provider_message_id IS NOT NULL
           AND provider_name = 'PhilSMS'"
    );
    $stmt = safe_query(
        $conn,
        "SELECT sms_id, provider_message_id FROM sms_logs
         WHERE LOWER(status) IN ('pending', 'queued', 'sent', 'processing') AND provider_message_id IS NOT NULL
           AND provider_name = 'PhilSMS'
           AND (provider_status_checked_at IS NULL OR provider_status_checked_at <= DATE_SUB(NOW(), INTERVAL 1 MINUTE))
         ORDER BY COALESCE(provider_status_checked_at, '1970-01-01 00:00:00'), date_sent ASC
         LIMIT 20"
    );
    if ($stmt === false) {
        return ['success' => false, 'updated' => 0, 'checked' => 0, 'pending' => 0, 'failed' => 0, 'untracked' => $untracked, 'message' => 'Pending SMS records could not be loaded.'];
    }

    $pendingRows = [];
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $pendingRows[] = $row;
    }
    mysqli_stmt_close($stmt);

    $checked = 0;
    $updated = 0;
    $failed = 0;
    $stillPending = 0;
    $reportedStatuses = [];
    $lookupErrors = [];
    foreach ($pendingRows as $row) {
        $apiResult = philsms_api_request('GET', $apiToken, [], $row['provider_message_id']);
        if (empty($apiResult['success'])) {
            $failed++;
            if (!empty($apiResult['error']) && count($lookupErrors) < 3) {
                $lookupErrors[] = $apiResult['error'];
            }
            $checkStmt = safe_query(
                $conn,
                'UPDATE sms_logs SET provider_status_checked_at = NOW() WHERE sms_id = ?',
                'i',
                [$row['sms_id']]
            );
            if ($checkStmt !== false) {
                mysqli_stmt_close($checkStmt);
            }
            continue;
        }

        $messageRecord = philsms_message_record($apiResult['data']);
        $providerStatus = philsms_status_value($messageRecord);
        if ($providerStatus === '') {
            $failed++;
            if (count($lookupErrors) < 3) {
                $lookupErrors[] = 'PhilSMS returned no delivery status for message ' . $row['provider_message_id'] . '.';
            }
            $checkStmt = safe_query(
                $conn,
                'UPDATE sms_logs SET provider_status_checked_at = NOW() WHERE sms_id = ?',
                'i',
                [$row['sms_id']]
            );
            if ($checkStmt !== false) {
                mysqli_stmt_close($checkStmt);
            }
            continue;
        }
        $checked++;
        $reportedStatuses[$providerStatus] = ($reportedStatuses[$providerStatus] ?? 0) + 1;
        if (in_array(strtolower($providerStatus), ['pending', 'queued', 'sent', 'processing'], true)) {
            $stillPending++;
        }
        $localStatus = $providerStatus;
        $updateStmt = safe_query(
            $conn,
            'UPDATE sms_logs SET status = ?, provider_status_checked_at = NOW() WHERE sms_id = ? AND provider_message_id = ?',
            'sis',
            [$localStatus, $row['sms_id'], $row['provider_message_id']]
        );
        if ($updateStmt !== false) {
            $updated++;
            mysqli_stmt_close($updateStmt);
        } else {
            $failed++;
            if (count($lookupErrors) < 3) {
                $lookupErrors[] = 'The delivery status for message ' . $row['provider_message_id'] . ' could not be saved locally.';
            }
        }
    }

    $statusSummary = [];
    foreach ($reportedStatuses as $status => $count) {
        $statusSummary[] = $count . ' ' . $status;
    }
    $statusDetail = $statusSummary ? ' PhilSMS reports: ' . implode(', ', $statusSummary) . '.' : '';
    foreach (array_keys($reportedStatuses) as $reportedStatus) {
        if (in_array(strtolower($reportedStatus), ['pending', 'queued', 'sent', 'processing'], true)) {
            $statusDetail .= ' Pending or queued means delivery is not confirmed yet.';
            break;
        }
    }
    if ($lookupErrors) {
        $statusDetail .= ' Details: ' . implode(' ', $lookupErrors);
    }

    return [
        'success' => $failed === 0,
        'updated' => $updated,
        'checked' => $checked,
        'pending' => $stillPending,
        'failed' => $failed,
        'untracked' => $untracked,
        'message' => count($pendingRows) > 0
            ? "Checked {$checked} of {$pendingCount} tracked pending message(s); {$updated} status value(s) saved and {$failed} lookup(s) failed.{$statusDetail}"
            : ($pendingCount > 0
                ? 'No tracked messages are due for refresh yet. Wait one minute before refreshing again.'
                : 'There are no tracked pending messages to refresh.'),
    ];
}
?>
