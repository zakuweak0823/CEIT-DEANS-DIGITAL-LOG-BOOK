<?php
/** Missing-requirement checklist for Incomplete documents (table is created by ensure_document_events()). */

function requirements_default_presets(): array
{
    return ['Valid ID', 'Signed request form', 'Endorsement letter', 'Authorization letter', 'Supporting documents', 'Clearance', 'Payment receipt'];
}

/** Default items plus the custom items staff typed most often, so the list adapts to the office. */
function requirements_presets($conn): array
{
    $presets = requirements_default_presets();
    $stmt = safe_query($conn, 'SELECT label FROM document_requirements GROUP BY label ORDER BY COUNT(*) DESC, MAX(req_id) DESC LIMIT 12');
    if ($stmt !== false) {
        $result = mysqli_stmt_get_result($stmt);
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $known = array_map('strtolower', $presets);
            if (!in_array(strtolower($row['label']), $known, true)) {
                $presets[] = $row['label'];
            }
        }
        mysqli_stmt_close($stmt);
    }
    return $presets;
}

/** Turns typed text (commas or new lines) into clean, unique labels. */
function requirements_clean_labels(array $labels): array
{
    $clean = [];
    foreach ($labels as $label) {
        foreach (preg_split('/[\r\n,;]+/', (string)$label) as $part) {
            $part = trim((string)preg_replace('/\s+/', ' ', $part));
            if ($part === '') {
                continue;
            }
            $part = function_exists('mb_substr') ? mb_substr($part, 0, 100, 'UTF-8') : substr($part, 0, 100);
            $clean[strtolower($part)] = $part;
        }
    }
    return array_slice(array_values($clean), 0, 15);
}

function requirements_list($conn, int $documentId): array
{
    $stmt = safe_query($conn, 'SELECT req_id, label, received_at FROM document_requirements WHERE doc_id = ? ORDER BY req_id', 'i', [$documentId]);
    if ($stmt === false) {
        return [];
    }
    $result = mysqli_stmt_get_result($stmt);
    $rows = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmt);
    return $rows;
}

function requirements_pending_labels($conn, int $documentId): array
{
    $labels = [];
    foreach (requirements_list($conn, $documentId) as $row) {
        if ($row['received_at'] === null) {
            $labels[] = $row['label'];
        }
    }
    return $labels;
}

/** Adds labels that are not already on the document; a received one asked for again becomes pending. */
function requirements_add($conn, int $documentId, array $labels): void
{
    $existing = [];
    foreach (requirements_list($conn, $documentId) as $row) {
        $existing[strtolower($row['label'])] = $row;
    }
    foreach ($labels as $label) {
        $key = strtolower($label);
        if (isset($existing[$key])) {
            if ($existing[$key]['received_at'] !== null) {
                $stmt = safe_query($conn, 'UPDATE document_requirements SET received_at = NULL WHERE req_id = ?', 'i', [(int)$existing[$key]['req_id']]);
                if ($stmt !== false) {
                    mysqli_stmt_close($stmt);
                }
            }
            continue;
        }
        $stmt = safe_query($conn, 'INSERT INTO document_requirements (doc_id, label) VALUES (?, ?)', 'is', [$documentId, $label]);
        if ($stmt !== false) {
            mysqli_stmt_close($stmt);
        }
    }
}

/** Marks exactly the ticked requirements as received; unticked ones go back to pending. */
function requirements_set_received($conn, int $documentId, array $receivedIds): void
{
    foreach (requirements_list($conn, $documentId) as $row) {
        $isReceived = in_array((int)$row['req_id'], $receivedIds, true);
        if ($isReceived === ($row['received_at'] !== null)) {
            continue;
        }
        $stmt = safe_query(
            $conn,
            $isReceived ? 'UPDATE document_requirements SET received_at = NOW() WHERE req_id = ?' : 'UPDATE document_requirements SET received_at = NULL WHERE req_id = ?',
            'i',
            [(int)$row['req_id']]
        );
        if ($stmt !== false) {
            mysqli_stmt_close($stmt);
        }
    }
}

/** Writes a note on the newest history entry (the one a status change just created). */
function requirements_note_latest_event($conn, int $documentId, string $note): void
{
    $note = function_exists('mb_substr') ? mb_substr($note, 0, 250, 'UTF-8') : substr($note, 0, 250);
    $stmt = safe_query($conn, "UPDATE document_events SET note = ? WHERE doc_id = ? AND kind = 'status' ORDER BY event_id DESC LIMIT 1", 'si', [$note, $documentId]);
    if ($stmt !== false) {
        mysqli_stmt_close($stmt);
    }
}
