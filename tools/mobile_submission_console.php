<?php
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mobile_submission_console_control.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$lockHandle = fopen(mobile_submission_console_lock_path(), 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "Another mobile submission CMD log window is already running.\r\n";
    exit(0);
}

function console_table_cell($value, $width)
{
    $value = preg_replace('/[\r\n\t]+/', ' ', (string)$value);
    if (function_exists('mb_strimwidth')) {
        $value = mb_strimwidth($value, 0, $width, '', 'UTF-8');
        $displayWidth = mb_strwidth($value, 'UTF-8');
    } else {
        $value = substr($value, 0, $width);
        $displayWidth = strlen($value);
    }

    return $value . str_repeat(' ', max(0, $width - $displayWidth));
}

function print_console_table_border($widths)
{
    $segments = [];
    foreach ($widths as $width) {
        $segments[] = str_repeat('-', $width + 2);
    }
    echo '+' . implode('+', $segments) . "+\r\n";
}

function print_console_table_row($values, $widths)
{
    $cells = [];
    foreach ($widths as $index => $width) {
        $cells[] = ' ' . console_table_cell($values[$index] ?? '', $width) . ' ';
    }
    echo '|' . implode('|', $cells) . "|\r\n";
}

function latest_document_id($conn)
{
    $result = $conn->query('SELECT COALESCE(MAX(doc_id), 0) AS latest_id FROM documents');
    if ($result === false) {
        throw new RuntimeException('Could not read the latest document reference.');
    }
    $row = $result->fetch_assoc();
    $result->free();
    return (int)$row['latest_id'];
}

function console_count_rows($conn, $query, $types = '', $params = [])
{
    $stmt = safe_query($conn, $query, $types, $params);
    if ($stmt === false) {
        throw new RuntimeException('Could not count records for the requested CMD log operation.');
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_row($result);
    mysqli_stmt_close($stmt);
    if (!$row) {
        throw new RuntimeException('The CMD log record count could not be read.');
    }
    return (int)$row[0];
}

function console_execute_clear_query($conn, $query, $types = '', $params = [])
{
    $stmt = safe_query($conn, $query, $types, $params);
    if ($stmt === false) {
        throw new RuntimeException('A database deletion for the requested CMD log operation failed.');
    }
    $deleted = max(0, mysqli_stmt_affected_rows($stmt));
    mysqli_stmt_close($stmt);
    return $deleted;
}

function console_clear_preview($conn, $scope)
{
    if (in_array($scope, ['incoming', 'outgoing'], true)) {
        $type = ucfirst($scope);
        return [
            'documents' => console_count_rows($conn, 'SELECT COUNT(*) FROM documents WHERE type = ?', 's', [$type]),
            'batches' => console_count_rows($conn, 'SELECT COUNT(*) FROM document_batches WHERE document_type = ?', 's', [$type]),
            'batch paper links' => console_count_rows(
                $conn,
                'SELECT COUNT(*) FROM batch_papers p INNER JOIN documents d ON d.doc_id = p.doc_id WHERE d.type = ?',
                's',
                [$type]
            ),
            'mobile submission audit rows' => console_count_rows(
                $conn,
                'SELECT COUNT(*) FROM mobile_submission_logs l INNER JOIN documents d ON d.doc_id = l.document_id WHERE d.type = ?',
                's',
                [$type]
            ),
        ];
    }

    if ($scope === 'batches') {
        return [
            'batches' => console_count_rows($conn, 'SELECT COUNT(*) FROM document_batches'),
            'paper documents' => console_count_rows($conn, 'SELECT COUNT(*) FROM batch_papers'),
            'mobile submission audit rows' => console_count_rows(
                $conn,
                'SELECT COUNT(*) FROM mobile_submission_logs l INNER JOIN batch_papers p ON p.doc_id = l.document_id'
            ),
        ];
    }

    if ($scope === 'borrowed') {
        return ['borrowed-item records' => console_count_rows($conn, 'SELECT COUNT(*) FROM borrowed_items')];
    }

    if ($scope === 'sms') {
        return ['SMS log records' => console_count_rows($conn, 'SELECT COUNT(*) FROM sms_logs')];
    }

    if ($scope === 'all') {
        return [
            'documents' => console_count_rows($conn, 'SELECT COUNT(*) FROM documents'),
            'batches' => console_count_rows($conn, 'SELECT COUNT(*) FROM document_batches'),
            'batch paper links' => console_count_rows($conn, 'SELECT COUNT(*) FROM batch_papers'),
            'borrowed-item records' => console_count_rows($conn, 'SELECT COUNT(*) FROM borrowed_items'),
            'SMS log records' => console_count_rows($conn, 'SELECT COUNT(*) FROM sms_logs'),
            'mobile submission audit rows' => console_count_rows($conn, 'SELECT COUNT(*) FROM mobile_submission_logs'),
        ];
    }

    throw new InvalidArgumentException('Choose incoming, outgoing, batches, borrowed, sms, or all.');
}

function console_clear_log_scope($conn, $scope)
{
    $counterTables = [];
    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Could not start a safe transaction for the CMD log operation.');
    }

    try {
        if (in_array($scope, ['incoming', 'outgoing'], true)) {
            $type = ucfirst($scope);
            console_execute_clear_query(
                $conn,
                'DELETE l FROM mobile_submission_logs l INNER JOIN documents d ON d.doc_id = l.document_id WHERE d.type = ?',
                's',
                [$type]
            );
            console_execute_clear_query($conn, 'DELETE FROM documents WHERE type = ?', 's', [$type]);
            console_execute_clear_query($conn, 'DELETE FROM document_batches WHERE document_type = ?', 's', [$type]);
            $counterTables = ['documents', 'mobile_submission_logs', 'document_batches', 'batch_papers'];
        } elseif ($scope === 'batches') {
            console_execute_clear_query(
                $conn,
                'DELETE l FROM mobile_submission_logs l INNER JOIN batch_papers p ON p.doc_id = l.document_id'
            );
            console_execute_clear_query(
                $conn,
                'DELETE d FROM documents d INNER JOIN batch_papers p ON p.doc_id = d.doc_id'
            );
            console_execute_clear_query($conn, 'DELETE FROM document_batches');
            $counterTables = ['documents', 'mobile_submission_logs', 'document_batches', 'batch_papers'];
        } elseif ($scope === 'borrowed') {
            console_execute_clear_query($conn, 'DELETE FROM borrowed_items');
            $counterTables = ['borrowed_items'];
        } elseif ($scope === 'sms') {
            console_execute_clear_query($conn, 'DELETE FROM sms_logs');
            $counterTables = ['sms_logs'];
        } elseif ($scope === 'all') {
            console_execute_clear_query($conn, 'DELETE FROM mobile_submission_logs');
            console_execute_clear_query($conn, 'DELETE FROM documents');
            console_execute_clear_query($conn, 'DELETE FROM document_batches');
            console_execute_clear_query($conn, 'DELETE FROM borrowed_items');
            console_execute_clear_query($conn, 'DELETE FROM sms_logs');
            $counterTables = ['documents', 'document_batches', 'batch_papers', 'borrowed_items', 'sms_logs', 'mobile_submission_logs'];
        } else {
            throw new InvalidArgumentException('Choose incoming, outgoing, batches, borrowed, sms, or all.');
        }

        if (!$conn->commit()) {
            throw new RuntimeException('The CMD log data deletion could not be committed.');
        }
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    $counterResults = [];
    // Keep IDs stable whenever another category still has rows in a shared table.
    foreach ($counterTables as $table) {
        $remaining = console_count_rows($conn, 'SELECT COUNT(*) FROM ' . $table);
        if ($remaining > 0) {
            $counterResults[$table] = 'kept at its current sequence (' . number_format($remaining) . ' rows remain)';
            continue;
        }
        if (!$conn->query('ALTER TABLE ' . $table . ' AUTO_INCREMENT = 1')) {
            throw new RuntimeException(
                'The records were deleted, but the ' . $table . ' auto-increment could not be reset. Check the MySQL permissions and error log.'
            );
        }
        $counterResults[$table] = 'reset to 1';
    }

    return $counterResults;
}

function print_console_warning($scope, $preview)
{
    $scopeLabel = strtoupper($scope);
    $border = str_repeat('!', 72);
    echo "\r\n+" . $border . "+\r\n";
    echo "|  !!! WARNING: PERMANENT DATA DELETION - NOT A SCREEN CLEAR !!!\r\n";
    echo "|  Target: " . $scopeLabel . "\r\n";
    echo "+" . $border . "+\r\n";
    foreach ($preview as $label => $count) {
        echo '  ' . ucfirst($label) . ': ' . number_format($count) . "\r\n";
    }
    if (in_array($scope, ['incoming', 'outgoing'], true)) {
        echo "  Linked batch records and mobile submission audit rows are included.\r\n";
        echo "  Incoming and Outgoing share document IDs. The document counter can\r\n";
        echo "  reset only when no documents of either direction remain.\r\n";
    } elseif ($scope === 'batches') {
        echo "  Batch paper documents and their mobile audit rows are included.\r\n";
        echo "  The shared document counter resets only if no documents remain.\r\n";
    } elseif ($scope === 'borrowed') {
        echo "  Borrower/category setup and all other log types will be kept.\r\n";
    } elseif ($scope === 'sms') {
        echo "  SMS/provider settings and all other log types will be kept.\r\n";
    } else {
        echo "  All documents, batches, borrowed-item records, SMS logs, and\r\n";
        echo "  mobile submission audit rows will be deleted.\r\n";
        echo "  Admin accounts, settings, categories, and API tokens will be kept.\r\n";
    }
    echo "  Empty affected tables will have their auto-increment reset to 1.\r\n";
    echo "  Type YES, then type DELETE " . $scopeLabel . " to confirm twice.\r\n";
    echo "  Type /cancel at either prompt to keep all data.\r\n";
    echo "+" . $border . "+\r\n\r\n";
}

function print_monitor_heading($tableWidth)
{
    $title = 'CEIT DEANS OFFICE  |  DOCUMENT ACTIVITY MONITOR';
    $titlePadding = max(0, (int)(($tableWidth - strlen($title)) / 2));
    echo "\r\n" . str_repeat('=', $tableWidth) . "\r\n";
    echo str_repeat(' ', $titlePadding) . $title . "\r\n";
    echo str_repeat('=', $tableWidth) . "\r\n";
}

function print_document_history($conn, $widths, $limit = 20, $direction = null, $search = null)
{
    $limit = max(1, min(50, (int)$limit));
    $query = 'SELECT doc_id, date_created, type, sender, subject, recipient, status FROM documents';
    $conditions = [];
    $types = '';
    $params = [];
    if (in_array($direction, ['Incoming', 'Outgoing'], true)) {
        $conditions[] = 'type = ?';
        $types .= 's';
        $params[] = $direction;
    }
    if (is_string($search) && $search !== '') {
        if (preg_match('/^#?(\d+)$/', $search, $referenceMatch)) {
            $conditions[] = 'doc_id = ?';
            $types .= 'i';
            $params[] = (int)$referenceMatch[1];
        } else {
            $conditions[] = '(sender LIKE ? OR subject LIKE ? OR recipient LIKE ? OR status LIKE ?)';
            $types .= 'ssss';
            $searchTerm = '%' . $search . '%';
            array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
        }
    }
    if ($conditions) {
        $query .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $query .= ' ORDER BY doc_id DESC LIMIT ' . $limit;

    $stmt = safe_query($conn, $query, $types, $params);
    if ($stmt === false) {
        throw new RuntimeException('Could not load document history.');
    }
    $result = mysqli_stmt_get_result($stmt);
    $documents = [];
    while ($row = $result->fetch_assoc()) {
        $documents[] = $row;
    }
    mysqli_stmt_close($stmt);
    $documents = array_reverse($documents);

    print_console_table_border($widths);
    print_console_table_row(['Ref #', 'Date created', 'Direction', 'From', 'Document / subject', 'Recipient', 'Status'], $widths);
    print_console_table_border($widths);
    foreach ($documents as $row) {
        print_console_table_row([
            '#' . $row['doc_id'],
            $row['date_created'],
            $row['type'],
            $row['sender'],
            $row['subject'],
            $row['recipient'],
            $row['status'],
        ], $widths);
    }
    print_console_table_border($widths);
    return $documents;
}

function print_command_prompt()
{
    echo "Admin : -> ";
    fflush(STDOUT);
}

function handle_monitor_command($command, $conn, $widths, $tableWidth, &$lastDocumentId, &$pendingClear)
{
    if ($pendingClear !== null) {
        if (time() > $pendingClear['expires_at']) {
            $pendingClear = null;
            echo "\r\n  WARNING: Data-clear confirmation expired. No data was changed.\r\n\r\n";
            return true;
        }
        if (strtolower(trim($command)) === '/cancel' || strtoupper(trim($command)) === 'NO') {
            $pendingClear = null;
            echo "\r\n  Data-clear cancelled. No data was changed.\r\n\r\n";
            return true;
        }
        if ($pendingClear['stage'] === 'yes') {
            if (strtoupper(trim($command)) !== 'YES') {
                $pendingClear = null;
                echo "\r\n  WARNING: Confirmation did not match. Data-clear cancelled; no data was changed.\r\n\r\n";
                return true;
            }
            $pendingClear['stage'] = 'phrase';
            echo "\r\n  SECOND CONFIRMATION: Type " . $pendingClear['phrase'] . " exactly to permanently delete these records, or /cancel.\r\n\r\n";
            return true;
        }
        if (strtoupper(trim($command)) !== $pendingClear['phrase']) {
            $pendingClear = null;
            echo "\r\n  WARNING: Final confirmation did not match. Data-clear cancelled; no data was changed.\r\n\r\n";
            return true;
        }

        $scope = $pendingClear['scope'];
        $pendingClear = null;
        try {
            $counterResults = console_clear_log_scope($conn, $scope);
            $lastDocumentId = latest_document_id($conn);
            echo "\r\n  DATA CLEAR COMPLETE: " . strtoupper($scope) . " records were deleted.\r\n";
            foreach ($counterResults as $table => $result) {
                echo '  ' . $table . ' auto-increment: ' . $result . ".\r\n";
            }
            echo "\r\n";
        } catch (Throwable $error) {
            error_log('CMD log data clear failed: ' . $error->getMessage());
            echo "\r\n  WARNING: " . $error->getMessage() . "\r\n";
            echo "  Check the database before retrying; the deletion may already have committed if only a counter reset failed.\r\n\r\n";
        }
        return true;
    }

    $parts = preg_split('/\s+/', trim($command));
    $name = strtolower($parts[0] ?? '');
    $argument = $parts[1] ?? null;
    $limit = 20;
    if ($argument !== null && ctype_digit($argument) && (int)$argument >= 1 && (int)$argument <= 50) {
        $limit = (int)$argument;
    }

    if ($name === '/help') {
        echo "\r\n  COMMANDS\r\n";
        echo "  /help          Show this command list\r\n";
        echo "  /refresh [N]   Show the latest N documents (default 20, maximum 50)\r\n";
        echo "  /incoming [N]  Show the latest N incoming documents\r\n";
        echo "  /outgoing [N]  Show the latest N outgoing documents\r\n";
        echo "  /find TEXT     Search sender, subject, recipient, status, or reference\r\n";
        echo "  /status        Show monitor state and document totals\r\n";
        echo "  /clear         Clear the CMD window and redraw the latest table\r\n";
        echo "  /clear SCOPE   Clear a data log after two confirmations\r\n";
        echo "  /purge SCOPE   Permanently clear a data log; requires two confirmations\r\n";
        echo "                 SCOPE: incoming, outgoing, batches, borrowed, sms, all\r\n";
        echo "  /cancel        Cancel a pending data-clear confirmation\r\n";
        echo "  /pause         Stop monitoring and close this CMD window\r\n";
        echo "  /exit          Exit the monitor\r\n\r\n";
        return true;
    }

    if ($name === '/refresh') {
        if (count($parts) > 2 || ($argument !== null && $limit === 20 && $argument !== '20')) {
            echo "\r\n  Usage: /refresh [N]  (N must be from 1 to 50)\r\n\r\n";
            return true;
        }
        echo "\r\n  Latest " . $limit . " documents\r\n\r\n";
        print_document_history($conn, $widths, $limit);
        echo "  LIVE  |  Watching for new Incoming and Outgoing documents...\r\n\r\n";
        return true;
    }

    if ($name === '/incoming' || $name === '/outgoing') {
        if (count($parts) > 2 || ($argument !== null && (!ctype_digit($argument) || (int)$argument < 1 || (int)$argument > 50))) {
            echo "\r\n  Usage: " . $name . " [N]  (N must be from 1 to 50)\r\n\r\n";
            return true;
        }
        echo "\r\n  Latest " . $limit . ' ' . ucfirst(substr($name, 1)) . " documents\r\n\r\n";
        print_document_history($conn, $widths, $limit, ucfirst(substr($name, 1)));
        echo "  LIVE  |  Watching for new Incoming and Outgoing documents...\r\n\r\n";
        return true;
    }

    if ($name === '/find') {
        $search = trim(substr(trim($command), strlen($parts[0])));
        if ($search === '') {
            echo "\r\n  Usage: /find TEXT\r\n\r\n";
            return true;
        }
        echo "\r\n  Search results for: " . $search . "\r\n\r\n";
        $matches = print_document_history($conn, $widths, 50, null, $search);
        if (!$matches) {
            echo "  No matching documents found.\r\n";
        }
        echo "\r\n";
        return true;
    }

    if ($name === '/status' && count($parts) === 1) {
        $result = $conn->query('SELECT COUNT(*) AS total FROM documents');
        $row = $result->fetch_assoc();
        echo "\r\n  Monitor: " . (mobile_submission_console_is_enabled() ? 'ACTIVE' : 'PAUSED') . "\r\n";
        echo "  Documents in logbook: " . number_format((int)$row['total']) . "\r\n";
        echo "  Latest document reference: #" . latest_document_id($conn) . "\r\n\r\n";
        return true;
    }

    if ($name === '/purge' || ($name === '/clear' && count($parts) > 1)) {
        $supportedScopes = ['incoming', 'outgoing', 'batches', 'borrowed', 'sms', 'all'];
        if (count($parts) !== 2 || !in_array(strtolower($argument ?? ''), $supportedScopes, true)) {
            echo "\r\n  Usage: /clear|/purge incoming|outgoing|batches|borrowed|sms|all\r\n";
            echo "  This permanently deletes selected records and requires two confirmations.\r\n\r\n";
            return true;
        }
        $scope = strtolower($argument);
        try {
            $preview = console_clear_preview($conn, $scope);
        } catch (Throwable $error) {
            error_log('CMD log data clear preview failed: ' . $error->getMessage());
            echo "\r\n  WARNING: Could not prepare this data-clear request. No data was changed.\r\n\r\n";
            return true;
        }
        print_console_warning($scope, $preview);
        $pendingClear = [
            'scope' => $scope,
            'phrase' => 'DELETE ' . strtoupper($scope),
            'stage' => 'yes',
            'expires_at' => time() + 120,
        ];
        return true;
    }

    if ($name === '/clear' && count($parts) === 1) {
        if (PHP_OS_FAMILY === 'Windows') {
            system('cls');
        } else {
            system('clear');
        }
        fflush(STDOUT);
        print_monitor_heading($tableWidth);
        echo "  Latest document activity\r\n\r\n";
        print_document_history($conn, $widths);
        echo "  LIVE  |  Watching for new Incoming and Outgoing documents...\r\n\r\n";
        return true;
    }

    if (($name === '/pause' || $name === '/exit') && count($parts) === 1) {
        set_mobile_submission_console_enabled(false);
        echo "\r\n  Document activity monitor stopped.\r\n";
        return false;
    }

    echo "\r\n  Unknown command. Type /help to see available commands.\r\n\r\n";
    return true;
}

$widths = [6, 16, 9, 16, 22, 16, 12];
$tableWidth = array_sum($widths) + (count($widths) * 3) + 1;
$lastDocumentId = latest_document_id($conn);
$pendingClear = null;
if (defined('STDIN')) {
    stream_set_blocking(STDIN, false);
}

print_monitor_heading($tableWidth);
echo "  Latest document activity — use /help for commands.\r\n\r\n";
$recentDocuments = print_document_history($conn, $widths);
if (!empty($recentDocuments)) {
    $lastDocumentId = max($lastDocumentId, (int)end($recentDocuments)['doc_id']);
}
echo "\r\n  LIVE  |  Watching for new Incoming and Outgoing documents...\r\n\r\n";
print_command_prompt();

while (true) {
    if (defined('STDIN')) {
        $command = fgets(STDIN);
        if ($command !== false && trim($command) !== '') {
            if (!handle_monitor_command(trim($command), $conn, $widths, $tableWidth, $lastDocumentId, $pendingClear)) {
                break;
            }
            print_command_prompt();
        }
    }

    if (!mobile_submission_console_is_enabled()) {
        echo "\r\n  Document activity monitor stopped from the dashboard.\r\n";
        break;
    }

    $stmt = safe_query(
        $conn,
        'SELECT doc_id, date_created, type, sender, subject, recipient, status
         FROM documents
         WHERE doc_id > ?
         ORDER BY doc_id ASC
         LIMIT 100',
        'i',
        [$lastDocumentId]
    );
    if ($stmt === false) {
        fwrite(STDERR, "Could not read document activity. Check the database connection.\r\n");
        exit(1);
    }

    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        echo "\r\n";
        print_console_table_border($widths);
        print_console_table_row([
            '#' . $row['doc_id'],
            $row['date_created'],
            $row['type'],
            $row['sender'],
            $row['subject'],
            $row['recipient'],
            $row['status'],
        ], $widths);
        print_console_table_border($widths);
        $lastDocumentId = (int)$row['doc_id'];
    }
    mysqli_stmt_close($stmt);
    if ($lastDocumentId > 0) {
        print_command_prompt();
    }
    sleep(1);
}
