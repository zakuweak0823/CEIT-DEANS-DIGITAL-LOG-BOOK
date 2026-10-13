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
    $row = $result->fetch_assoc();
    return (int)$row['latest_id'];
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

function handle_monitor_command($command, $conn, $widths, $tableWidth, &$lastDocumentId)
{
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
            if (!handle_monitor_command(trim($command), $conn, $widths, $tableWidth, $lastDocumentId)) {
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
