<?php
function ensure_document_tracking_schema($conn): void
{
    $statements = [
        'ALTER TABLE documents ADD COLUMN IF NOT EXISTS submitter_name VARCHAR(255) NOT NULL DEFAULT \'\'',
        'ALTER TABLE documents ADD COLUMN IF NOT EXISTS tracking_token CHAR(64) DEFAULT NULL',
        'ALTER TABLE documents ADD COLUMN IF NOT EXISTS university_reference VARCHAR(100) DEFAULT NULL',
        'ALTER TABLE documents ADD COLUMN IF NOT EXISTS local_reference VARCHAR(40) DEFAULT NULL',
    ];
    foreach ($statements as $sql) {
        if ($conn->query($sql) === false) {
            throw new RuntimeException('Could not prepare document tracking storage: ' . $conn->error);
        }
    }

    $columns = $conn->query("SHOW COLUMNS FROM documents LIKE 'local_reference'");
    if ($columns === false || !($column = $columns->fetch_assoc())) {
        throw new RuntimeException('Could not check document reference storage.');
    }
    $columns->free();
    if (preg_match('/^varchar\((\d+)\)$/i', (string)$column['Type'], $matches) !== 1) {
        throw new RuntimeException('Document reference storage has an unexpected type.');
    }
    if ((int)$matches[1] < 40 && $conn->query('ALTER TABLE documents MODIFY COLUMN local_reference VARCHAR(40) DEFAULT NULL') === false) {
        throw new RuntimeException('Could not expand document reference storage: ' . $conn->error);
    }

    $indexes = $conn->query("SHOW INDEX FROM documents WHERE Key_name = 'uq_documents_tracking_token'");
    if ($indexes === false) {
        throw new RuntimeException('Could not check document tracking indexes: ' . $conn->error);
    }
    $hasTrackingIndex = $indexes->num_rows > 0;
    $indexes->free();
    if (!$hasTrackingIndex && $conn->query('ALTER TABLE documents ADD UNIQUE KEY uq_documents_tracking_token (tracking_token)') === false) {
        throw new RuntimeException('Could not add the document tracking token index: ' . $conn->error);
    }

    $indexes = $conn->query("SHOW INDEX FROM documents WHERE Key_name = 'uq_documents_local_reference'");
    if ($indexes === false) {
        throw new RuntimeException('Could not check document reference indexes: ' . $conn->error);
    }
    $hasReferenceIndex = $indexes->num_rows > 0;
    $indexes->free();
    if (!$hasReferenceIndex && $conn->query('ALTER TABLE documents ADD UNIQUE KEY uq_documents_local_reference (local_reference)') === false) {
        throw new RuntimeException('Could not add the document reference index: ' . $conn->error);
    }
}

function normalize_document_tracker_base_url($value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (strlen($value) > 255 || preg_match('/[\x00-\x20\\\\]/', $value)) {
        return null;
    }
    $parts = parse_url($value);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        return null;
    }
    $host = (string)$parts['host'];
    if (filter_var($host, FILTER_VALIDATE_IP) === false
        && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i', $host) !== 1) {
        return null;
    }
    $port = $parts['port'] ?? null;
    if ($port !== null && ($port < 1 || $port > 65535)) {
        return null;
    }
    $path = (string)($parts['path'] ?? '');
    if (preg_match('/%(?![a-f0-9]{2})/i', $path) === 1
        || preg_match('~(?:^|/)\.\.(?:/|$)~', rawurldecode($path)) === 1) {
        return null;
    }

    return rtrim($value, '/');
}

function get_document_tracking_settings($conn): array
{
    $stmt = safe_query(
        $conn,
        "SELECT key_name, value FROM settings WHERE key_name IN ('document_tracking_enabled', 'document_tracker_base_url')"
    );
    if ($stmt === false) {
        throw new RuntimeException('Could not load document tracking settings.');
    }
    $result = mysqli_stmt_get_result($stmt);
    if ($result === false) {
        mysqli_stmt_close($stmt);
        throw new RuntimeException('Could not read document tracking settings.');
    }
    $settings = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $settings[$row['key_name']] = $row['value'];
    }
    mysqli_stmt_close($stmt);

    $configuredBaseUrl = $settings['document_tracker_base_url'] ?? '';
    $normalizedBaseUrl = normalize_document_tracker_base_url($configuredBaseUrl);
    if ($normalizedBaseUrl === null) {
        throw new RuntimeException('The configured document tracker URL is invalid.');
    }
    $enabled = !array_key_exists('document_tracking_enabled', $settings)
        || (string)$settings['document_tracking_enabled'] === '1';
    $baseUrl = $normalizedBaseUrl !== ''
        ? $normalizedBaseUrl
        : ($enabled ? get_request_document_tracker_base_url() : '');
    return [
        'enabled' => $enabled,
        'baseUrl' => $baseUrl === '' ? '' : $baseUrl . '/document-tracker',
        'configuredBaseUrl' => $normalizedBaseUrl,
    ];
}

function get_request_document_tracker_base_url(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (preg_match('/^[a-z0-9.-]+(?::[0-9]{1,5})?$/i', $host) !== 1) {
        throw new RuntimeException('Set the public tracker URL in Admin Settings before issuing tracking QR codes.');
    }
    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    $directory = trim(str_replace('\\', '/', dirname($scriptPath)), '/.');
    $baseUrl = normalize_document_tracker_base_url($scheme . '://' . $host . ($directory !== '' ? '/' . $directory : ''));
    if ($baseUrl === null || $baseUrl === '') {
        throw new RuntimeException('Set the public tracker URL in Admin Settings before issuing tracking QR codes.');
    }
    return $baseUrl;
}

function create_document_tracking_token(): string
{
    return bin2hex(random_bytes(32));
}

function create_document_reference(): string
{
    $date = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    return 'CEIT-' . $date->format('Ymd') . '-' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function insert_document_with_reference($conn, string $sql, string $types, array $params, ?callable $generateReference = null): array
{
    $generateReference = $generateReference ?? 'create_document_reference';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $reference = $generateReference();
        try {
            $stmt = safe_query($conn, $sql, $types . 's', array_merge($params, [$reference]));
            if ($stmt === false) {
                throw new RuntimeException('Document insert failed.');
            }
            return ['statement' => $stmt, 'reference' => $reference];
        } catch (mysqli_sql_exception $error) {
            if ($error->getCode() !== 1062 || strpos($error->getMessage(), 'uq_documents_local_reference') === false) {
                throw $error;
            }
        }
    }
    throw new RuntimeException('Could not generate a unique document reference after multiple attempts.');
}

function document_tracking_reference($documentId, ?string $localReference): string
{
    return $localReference ?? ('DIT-' . str_pad((string)(int)$documentId, 6, '0', STR_PAD_LEFT));
}

function document_tracking_reference_sql(): string
{
    return "COALESCE(local_reference, CONCAT('DIT-', LPAD(doc_id, GREATEST(6, CHAR_LENGTH(doc_id)), '0')))";
}
