<?php
// Single source of truth for document and bulk-submission statuses.
// Staff pages, reports, the tracker, the mobile API and SMS must all read these values
// so the wording is identical everywhere.

const DOC_STATUS_RECEIVED = 'Received';
const DOC_STATUS_IN_REVIEW = 'In Review';
const DOC_STATUS_FORWARDED = 'Forwarded';
const DOC_STATUS_INCOMPLETE = 'Incomplete';
const DOC_STATUS_ON_HOLD = 'On Hold';
const DOC_STATUS_APPROVED = 'Approved';
const DOC_STATUS_FOR_RELEASE = 'For Release';
const DOC_STATUS_RELEASED = 'Released';
const DOC_STATUS_RETURNED = 'Returned';
const DOC_STATUS_CANCELLED = 'Cancelled';

/** Statuses where the office is working on an incoming item (the SLA clock runs). */
function doc_status_open(): array
{
    return [DOC_STATUS_RECEIVED, DOC_STATUS_IN_REVIEW, DOC_STATUS_FORWARDED, DOC_STATUS_APPROVED, DOC_STATUS_ON_HOLD];
}

/** Every status an incoming item can hold, in display order. */
function doc_status_incoming(): array
{
    return [
        DOC_STATUS_RECEIVED, DOC_STATUS_IN_REVIEW, DOC_STATUS_FORWARDED, DOC_STATUS_INCOMPLETE,
        DOC_STATUS_ON_HOLD, DOC_STATUS_APPROVED, DOC_STATUS_RETURNED, DOC_STATUS_CANCELLED,
    ];
}

/** Statuses from which an incoming item may be prepared for release. */
function doc_status_can_prepare_release(): array
{
    return [DOC_STATUS_RECEIVED, DOC_STATUS_IN_REVIEW, DOC_STATUS_FORWARDED, DOC_STATUS_APPROVED];
}

/** Next step when staff press the advance button on a bulk submission, or null when it cannot advance. */
function doc_status_next(string $status): ?string
{
    if (in_array($status, doc_status_can_prepare_release(), true)) {
        return DOC_STATUS_FOR_RELEASE;
    }
    return $status === DOC_STATUS_FOR_RELEASE ? DOC_STATUS_RELEASED : null;
}

/** Statuses an outgoing item can hold. */
function doc_status_outgoing(): array
{
    return [DOC_STATUS_FOR_RELEASE, DOC_STATUS_RELEASED, DOC_STATUS_RETURNED, DOC_STATUS_CANCELLED];
}

/** Every status in the system, in display order. */
function doc_status_all(): array
{
    return [
        DOC_STATUS_RECEIVED, DOC_STATUS_IN_REVIEW, DOC_STATUS_FORWARDED, DOC_STATUS_INCOMPLETE,
        DOC_STATUS_ON_HOLD, DOC_STATUS_APPROVED, DOC_STATUS_FOR_RELEASE, DOC_STATUS_RELEASED,
        DOC_STATUS_RETURNED, DOC_STATUS_CANCELLED,
    ];
}

/** Statuses a document-status filter may use: each status plus the "Open" group. */
function doc_status_filter_options(): array
{
    return array_merge(['Open'], doc_status_all());
}

function doc_status_is_valid(string $status): bool
{
    return in_array($status, doc_status_all(), true);
}

/** Quoted list for SQL IN (...). Values are fixed constants, never user input. */
function doc_status_sql_in(array $statuses): string
{
    return "'" . implode("','", array_map(static function ($status) {
        return str_replace("'", "''", $status);
    }, $statuses)) . "'";
}

function doc_status_open_sql(): string
{
    return doc_status_sql_in(doc_status_open());
}

/** Where the tracker and tables show the item: Incoming, Outgoing or History. */
function doc_status_page(string $status): string
{
    if ($status === DOC_STATUS_FOR_RELEASE) {
        return 'Outgoing';
    }
    if (in_array($status, [DOC_STATUS_RELEASED, DOC_STATUS_RETURNED, DOC_STATUS_CANCELLED], true)) {
        return 'History';
    }
    return 'Incoming';
}

/**
 * Clickable status badge: the badge itself opens a menu of statuses and one click saves the change.
 * $hidden holds the extra POST fields (record id); $actionName is the POST flag the page's handler checks.
 */
function render_status_menu(string $current, array $options, array $hidden, string $actionName, string $label): void
{
    $colors = doc_status_chart_colors();
    ?>
    <form method="POST" class="status-menu m-0">
        <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
        <?php foreach ($hidden as $hiddenName => $hiddenValue): ?>
            <input type="hidden" name="<?php echo sanitize($hiddenName); ?>" value="<?php echo sanitize((string)$hiddenValue); ?>">
        <?php endforeach; ?>
        <input type="hidden" name="<?php echo sanitize($actionName); ?>" value="1">
        <div class="dropdown">
            <button type="button" class="badge status-menu-toggle dropdown-toggle <?php echo admin_status_badge_class($current); ?>"
                data-bs-toggle="dropdown" aria-expanded="false"
                aria-label="Status of <?php echo sanitize($label); ?>: <?php echo sanitize($current); ?>. Select to change.">
                <?php echo sanitize($current); ?>
            </button>
            <ul class="dropdown-menu status-menu-list">
                <li><h6 class="dropdown-header">Change status to</h6></li>
                <?php foreach ($options as $option): ?>
                    <li>
                        <button type="submit" name="new_status" value="<?php echo sanitize($option); ?>"
                            class="dropdown-item status-menu-item<?php echo $option === $current ? ' active' : ''; ?>"
                            <?php echo $option === $current ? 'disabled aria-current="true"' : ''; ?>>
                            <span class="status-menu-dot" style="background: <?php echo sanitize($colors[$option] ?? '#d4d4d8'); ?>"></span>
                            <?php echo sanitize($option); ?>
                            <?php if ($option === $current): ?><i class="bi bi-check2 ms-auto" aria-hidden="true"></i><?php endif; ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </form>
    <?php
}

/** Colors for charts (dashboard). */
function doc_status_chart_colors(): array
{
    return [
        DOC_STATUS_RECEIVED => '#c4de7a',
        DOC_STATUS_IN_REVIEW => '#f6d776',
        DOC_STATUS_FORWARDED => '#f2b680',
        DOC_STATUS_INCOMPLETE => '#e8a0a8',
        DOC_STATUS_ON_HOLD => '#b8c0cc',
        DOC_STATUS_APPROVED => '#9ad0f5',
        DOC_STATUS_FOR_RELEASE => '#7cc9bd',
        DOC_STATUS_RELEASED => '#79c28f',
        DOC_STATUS_RETURNED => '#c9b6e4',
        DOC_STATUS_CANCELLED => '#d4d4d8',
    ];
}

/**
 * Status history: one row per change, written by database triggers so no page or API can forget to log it.
 * Documents get their triggers here; bulk submissions get theirs in ensure_batch_storage().
 */
function ensure_document_events($conn): void
{
    if ($conn->query("CREATE TABLE IF NOT EXISTS document_events (
        event_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        doc_id INT DEFAULT NULL,
        batch_id INT DEFAULT NULL,
        kind VARCHAR(12) NOT NULL DEFAULT 'status',
        status VARCHAR(20) NOT NULL,
        note VARCHAR(255) DEFAULT NULL,
        actor VARCHAR(100) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_document_events_doc (doc_id, created_at),
        INDEX idx_document_events_batch (batch_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") === false) {
        throw new RuntimeException('Could not create the status history table: ' . $conn->error);
    }
    if ($conn->query("CREATE TABLE IF NOT EXISTS document_requirements (
        req_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        doc_id INT NOT NULL,
        label VARCHAR(100) NOT NULL,
        received_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_document_requirements_doc (doc_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") === false) {
        throw new RuntimeException('Could not create the requirements table: ' . $conn->error);
    }

    $done = $conn->query("SELECT 1 FROM settings WHERE key_name = 'document_events_v1' LIMIT 1");
    if ($done === false) {
        throw new RuntimeException('Could not check the status history setup: ' . $conn->error);
    }
    $alreadyDone = $done->num_rows > 0;
    $done->free();
    if ($alreadyDone) {
        return;
    }

    $statements = [
        'DROP TRIGGER IF EXISTS documents_events_ai',
        'DROP TRIGGER IF EXISTS documents_events_au',
        "CREATE TRIGGER documents_events_ai AFTER INSERT ON documents FOR EACH ROW
            INSERT INTO document_events (doc_id, kind, status, note, actor, created_at)
            VALUES (NEW.doc_id, 'status', NEW.status, 'Logged by the office', @doc_actor, COALESCE(NEW.date_created, NOW()))",
        "CREATE TRIGGER documents_events_au AFTER UPDATE ON documents FOR EACH ROW
            BEGIN
                IF NOT (OLD.status <=> NEW.status) OR NOT (OLD.type <=> NEW.type) THEN
                    INSERT INTO document_events (doc_id, kind, status, note, actor)
                    VALUES (NEW.doc_id, 'status', NEW.status,
                        IF(NOT (OLD.type <=> NEW.type), CONCAT('Moved to ', NEW.type, ' documents'), NULL), @doc_actor);
                END IF;
            END",
        // Existing records get one starting entry so every tracker page has something to show.
        "INSERT INTO document_events (doc_id, kind, status, note, created_at)
            SELECT d.doc_id, 'status', d.status, 'Earlier history was not recorded', COALESCE(d.date_created, NOW())
            FROM documents d WHERE NOT EXISTS (SELECT 1 FROM document_events e WHERE e.doc_id = d.doc_id)",
        "INSERT INTO settings (key_name, value) VALUES ('document_events_v1', '1') ON DUPLICATE KEY UPDATE value = '1'",
    ];
    foreach ($statements as $sql) {
        if ($conn->query($sql) === false) {
            throw new RuntimeException('Could not set up the status history: ' . $conn->error);
        }
    }
}

/** Bulk submissions: same idea, plus a "receipt confirmed" entry when staff confirm the received count. */
function ensure_batch_events($conn): void
{
    $done = $conn->query("SELECT 1 FROM settings WHERE key_name = 'batch_events_v1' LIMIT 1");
    if ($done === false) {
        throw new RuntimeException('Could not check the bulk status history setup: ' . $conn->error);
    }
    $alreadyDone = $done->num_rows > 0;
    $done->free();
    if ($alreadyDone) {
        ensure_batch_receipt_backfill($conn);
        return;
    }

    $statements = [
        'DROP TRIGGER IF EXISTS batches_events_ai',
        'DROP TRIGGER IF EXISTS batches_events_au',
        "CREATE TRIGGER batches_events_ai AFTER INSERT ON document_batches FOR EACH ROW
            INSERT INTO document_events (batch_id, kind, status, note, actor, created_at)
            VALUES (NEW.batch_id, 'status', NEW.status, 'Bulk submission logged by the office', @doc_actor, COALESCE(NEW.created_at, NOW()))",
        "CREATE TRIGGER batches_events_au AFTER UPDATE ON document_batches FOR EACH ROW
            BEGIN
                IF NOT (OLD.status <=> NEW.status) THEN
                    INSERT INTO document_events (batch_id, kind, status, actor)
                    VALUES (NEW.batch_id, 'status', NEW.status, @doc_actor);
                END IF;
                IF OLD.received_at IS NULL AND NEW.received_at IS NOT NULL THEN
                    INSERT INTO document_events (batch_id, kind, status, note, actor)
                    VALUES (NEW.batch_id, 'scan', NEW.status,
                        CONCAT('Receipt confirmed: ', IFNULL(NEW.received_paper_count, 0), ' of ', NEW.paper_count, ' documents received'), @doc_actor);
                END IF;
            END",
        "INSERT INTO document_events (batch_id, kind, status, note, created_at)
            SELECT b.batch_id, 'status', b.status, 'Earlier history was not recorded', COALESCE(b.created_at, NOW())
            FROM document_batches b WHERE NOT EXISTS (SELECT 1 FROM document_events e WHERE e.batch_id = b.batch_id)",
        "INSERT INTO settings (key_name, value) VALUES ('batch_events_v1', '1') ON DUPLICATE KEY UPDATE value = '1'",
    ];
    foreach ($statements as $sql) {
        if ($conn->query($sql) === false) {
            throw new RuntimeException('Could not set up the bulk status history: ' . $conn->error);
        }
    }
    ensure_batch_receipt_backfill($conn);
}

/** Batches confirmed before history existed get their receipt confirmation added at the time it happened. */
function ensure_batch_receipt_backfill($conn): void
{
    $done = $conn->query("SELECT 1 FROM settings WHERE key_name = 'batch_receipt_backfill_v1' LIMIT 1");
    if ($done === false) {
        throw new RuntimeException('Could not check the bulk receipt history: ' . $conn->error);
    }
    $alreadyDone = $done->num_rows > 0;
    $done->free();
    if ($alreadyDone) {
        return;
    }
    $statements = [
        "INSERT INTO document_events (batch_id, kind, status, note, actor, created_at)
            SELECT b.batch_id, 'scan', b.status,
                CONCAT('Receipt confirmed: ', IFNULL(b.received_paper_count, 0), ' of ', b.paper_count, ' documents received'),
                b.received_by, b.received_at
            FROM document_batches b
            WHERE b.received_at IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM document_events e WHERE e.batch_id = b.batch_id AND e.kind = 'scan')",
        "INSERT INTO settings (key_name, value) VALUES ('batch_receipt_backfill_v1', '1') ON DUPLICATE KEY UPDATE value = '1'",
    ];
    foreach ($statements as $sql) {
        if ($conn->query($sql) === false) {
            throw new RuntimeException('Could not add the bulk receipt history: ' . $conn->error);
        }
    }
}

/** Public-safe history for the tracker pages: newest first, without staff names. */
function fetch_status_history($conn, string $column, int $id, int $limit = 50): array
{
    if (!in_array($column, ['doc_id', 'batch_id'], true)) {
        return [];
    }
    $stmt = safe_query(
        $conn,
        "SELECT status, kind, note, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS at
         FROM document_events WHERE $column = ? ORDER BY created_at DESC, event_id DESC LIMIT " . (int)$limit,
        'i',
        [$id]
    );
    if ($stmt === false) {
        return [];
    }
    $result = mysqli_stmt_get_result($stmt);
    $rows = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmt);
    $defaults = [
        'Received' => 'The office has received your document.',
        'In Review' => 'Your document is being reviewed.',
        'Forwarded' => 'Your document was forwarded for action.',
        'Incomplete' => 'Additional requirements are needed. Please contact the Dean\'s Office.',
        'On Hold' => 'Processing is temporarily on hold.',
        'Approved' => 'Your document was approved.',
        'For Release' => 'Ready for release. You may claim it at the Dean\'s Office.',
        'Released' => 'The document has been released.',
        'Returned' => 'The document was returned.',
        'Cancelled' => 'This request was cancelled.',
    ];
    foreach ($rows as &$row) {
        if (trim((string)($row['note'] ?? '')) === '' && $row['kind'] === 'status') {
            $row['note'] = $defaults[$row['status']] ?? '';
        }
    }
    unset($row);
    return $rows;
}

/**
 * One-time data migration: the old "Pending" (and older "Received") incoming values become "Received".
 * Outgoing values are unchanged. Safe to run repeatedly.
 */
function ensure_doc_status_migration($conn): void
{
    $done = $conn->query("SELECT 1 FROM settings WHERE key_name = 'doc_status_migration_v1' LIMIT 1");
    if ($done === false) {
        throw new RuntimeException('Could not check the document status migration: ' . $conn->error);
    }
    $alreadyDone = $done->num_rows > 0;
    $done->free();
    if ($alreadyDone) {
        return;
    }

    $statements = [
        "UPDATE documents SET status = 'Received' WHERE status IN ('Pending', '') AND type = 'Incoming'",
        "UPDATE document_batches SET status = 'Received' WHERE status = 'Pending'",
        "ALTER TABLE document_batches MODIFY status VARCHAR(20) NOT NULL DEFAULT 'Received'",
        "INSERT INTO settings (key_name, value) VALUES ('doc_status_migration_v1', '1') ON DUPLICATE KEY UPDATE value = '1'",
    ];
    foreach ($statements as $sql) {
        if ($conn->query($sql) === false) {
            throw new RuntimeException('Could not update document statuses: ' . $conn->error);
        }
    }
}
