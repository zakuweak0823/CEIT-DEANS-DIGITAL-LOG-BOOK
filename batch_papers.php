<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'batch_support.php';
require_once 'mobile_form_settings.php';
ensure_admin();

$mobileFormLabels = get_mobile_form_labels($conn);
$batchWorkflowSettings = get_batch_workflow_settings($conn);

try {
    ensure_batch_storage($conn);
} catch (Throwable $error) {
    error_log('Batch papers table setup failed: ' . $error->getMessage());
    $setupError = true;
}

if (empty($_SESSION['batch_papers_token'])) {
    $_SESSION['batch_papers_token'] = bin2hex(random_bytes(32));
}

$message = '';
$showBatchModal = false;
$setupError = $setupError ?? false;
$documentType = $_POST['document_type'] ?? 'Incoming';
$batchType = trim($_POST['batch_type'] ?? '');
$batchSubmitterName = trim($_POST['submitter_name'] ?? '');
$batchPriority = isset($_POST['is_priority']) ? 1 : 0;
$batchDate = $_POST['batch_date'] ?? date('Y-m-d');
$senderType = $_POST['sender_type'] ?? 'Student';
$recipientContactType = 'Unassigned';
$recipient = '';
$paperCount = (int)($_POST['paper_count'] ?? 1);
[$documentPagesJson, $validPages] = normalize_document_pages($_POST['document_pages'] ?? null, max(0, min($paperCount, 9999)));
$batchRemarks = trim($_POST['remarks'] ?? '');
$search = trim($_GET['search'] ?? '');

if (!$setupError && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    if (!hash_equals($_SESSION['batch_papers_token'], $token)) {
        $message = show_alert('Your session token is invalid. Refresh the page and try again.', 'danger');
    } elseif (isset($_POST['scan_batch_qr'])) {
        header('Content-Type: application/json; charset=utf-8');
        $trackingToken = trim($_POST['tracking_token'] ?? '');
        $batchCode = trim($_POST['batch_code'] ?? '');
        if ((!preg_match('/^[a-f0-9]{64}$/', $trackingToken) && $batchCode === '') ||
            ($batchCode !== '' && !preg_match('/^[A-Z0-9-]{1,32}$/', $batchCode))) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'This QR code is not a valid CEIT bulk submission code.']);
            exit;
        }

        $lookupColumn = $trackingToken !== '' ? 'tracking_token' : 'batch_code';
        $lookupValue = $trackingToken !== '' ? $trackingToken : $batchCode;
        $batchSelect = 'SELECT b.batch_id, b.batch_code, b.document_type, b.batch_type, b.status, b.batch_date, b.paper_count, b.is_priority,
                    b.tracking_token, b.received_paper_count, b.intake_expected_count, b.intake_notes, b.received_at, b.received_by
             FROM document_batches b
             WHERE b.' . $lookupColumn . ' = ?
             LIMIT 1';
        $stmt = safe_query(
            $conn,
            $batchSelect,
            's',
            [$lookupValue]
        );
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not verify this bulk submission QR code.']);
            exit;
        }

        $result = mysqli_stmt_get_result($stmt);
        $batch = null;
        $row = mysqli_fetch_assoc($result);
        if ($row) {
            $batch = [
                'code' => $row['batch_code'],
                'trackingToken' => $row['tracking_token'],
                'type' => $row['batch_type'],
                'date' => $row['batch_date'],
                'paperCount' => (int)$row['paper_count'],
                'status' => $row['status'],
                'isPriority' => (int)$row['is_priority'],
                'receivedPaperCount' => $row['received_paper_count'] === null ? null : (int)$row['received_paper_count'],
                'intakeExpectedCount' => $row['intake_expected_count'] === null ? null : (int)$row['intake_expected_count'],
                'receivedAt' => $row['received_at'],
                'receivedBy' => $row['received_by'],
                'intakeNotes' => $row['intake_notes'],
            ];
        }
        mysqli_stmt_close($stmt);

        if ($batch === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'This QR code was not issued by the CEIT bulk documents tracking system.']);
            exit;
        }
        echo json_encode(['success' => true, 'batch' => $batch]);
        exit;
    } elseif (isset($_POST['confirm_batch_intake'])) {
        header('Content-Type: application/json; charset=utf-8');
        if ($batchWorkflowSettings['intake_confirmation_mode'] !== 'confirm_before_release') {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Use the status action to confirm receipt and move the bulk submission to For Release.']);
            exit;
        }
        $trackingToken = trim($_POST['tracking_token'] ?? '');
        $receivedCount = filter_var($_POST['received_paper_count'] ?? null, FILTER_VALIDATE_INT);
        $intakeNotesInput = $_POST['intake_notes'] ?? '';
        $intakeNotes = is_string($intakeNotesInput) ? trim($intakeNotesInput) : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $trackingToken) ||
            !is_string($intakeNotesInput) ||
            $receivedCount === false || $receivedCount < 0 || $receivedCount > 9999 ||
            strlen($intakeNotes) > 1000) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Enter a received document count from 0 to 9,999 and a note of no more than 1,000 characters.']);
            exit;
        }

        try {
            $conn->begin_transaction();
            $stmt = safe_query(
                $conn,
                'SELECT batch_id, batch_code, batch_type, batch_date, paper_count, status, is_priority,
                        received_paper_count, intake_expected_count, intake_notes, received_at, received_by
                 FROM document_batches WHERE tracking_token = ? FOR UPDATE',
                's',
                [$trackingToken]
            );
            if ($stmt === false) {
                throw new RuntimeException('Could not verify the bulk submission QR code.');
            }
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$row) {
                throw new RuntimeException('This QR code was not issued by the CEIT bulk documents tracking system.');
            }
            if ($row['received_at'] !== null) {
                $conn->commit();
                echo json_encode(['success' => true, 'alreadyConfirmed' => true, 'batch' => [
                    'code' => $row['batch_code'],
                    'type' => $row['batch_type'],
                    'date' => $row['batch_date'],
                    'paperCount' => (int)$row['paper_count'],
                    'status' => $row['status'],
                    'isPriority' => (int)$row['is_priority'],
                    'receivedPaperCount' => (int)$row['received_paper_count'],
                    'intakeExpectedCount' => (int)$row['intake_expected_count'],
                    'intakeNotes' => $row['intake_notes'],
                    'receivedAt' => $row['received_at'],
                    'receivedBy' => $row['received_by'],
                ]]);
                exit;
            }
            if ($row['status'] !== 'Pending') {
                throw new DomainException('Receipt confirmation is available only while the bulk submission is Pending.');
            }
            if ((int)$row['paper_count'] < 1 || (int)$row['paper_count'] > 9999) {
                throw new RuntimeException('This bulk submission has an invalid expected document count. Update it before confirming receipt.');
            }
            if ($receivedCount !== (int)$row['paper_count'] && $intakeNotes === '') {
                throw new InvalidArgumentException('Add a note describing the count difference before confirming receipt.');
            }

            $receivedBy = trim((string)($_SESSION['admin'] ?? 'Staff'));
            $stmt = safe_query(
                $conn,
                'UPDATE document_batches
                 SET received_paper_count = ?, intake_expected_count = paper_count, intake_notes = ?, received_at = NOW(), received_by = ?
                 WHERE batch_id = ? AND received_at IS NULL',
                'issi',
                [$receivedCount, $intakeNotes, $receivedBy, $row['batch_id']]
            );
            if ($stmt === false || mysqli_stmt_affected_rows($stmt) !== 1) {
                if ($stmt !== false) mysqli_stmt_close($stmt);
                throw new RuntimeException('The intake receipt could not be saved. Refresh the page and try again.');
            }
            mysqli_stmt_close($stmt);
            $receiptStmt = safe_query($conn, 'SELECT received_at FROM document_batches WHERE batch_id = ?', 'i', [$row['batch_id']]);
            if ($receiptStmt === false) {
                throw new RuntimeException('The confirmation time could not be verified.');
            }
            $receiptRow = mysqli_fetch_assoc(mysqli_stmt_get_result($receiptStmt));
            mysqli_stmt_close($receiptStmt);
            if (!$receiptRow || !$receiptRow['received_at']) {
                throw new RuntimeException('The receipt confirmation time could not be verified.');
            }
            $conn->commit();
            echo json_encode(['success' => true, 'batch' => [
                'code' => $row['batch_code'],
                'type' => $row['batch_type'],
                'date' => $row['batch_date'],
                'paperCount' => (int)$row['paper_count'],
                'status' => $row['status'],
                'isPriority' => (int)$row['is_priority'],
                'receivedPaperCount' => $receivedCount,
                'intakeExpectedCount' => (int)$row['paper_count'],
                'intakeNotes' => $intakeNotes,
                'receivedAt' => $receiptRow['received_at'],
                'receivedBy' => $receivedBy,
            ]]);
        } catch (Throwable $error) {
            $conn->rollback();
            if ($error instanceof DomainException || $error instanceof InvalidArgumentException) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => $error->getMessage()]);
            } else {
                error_log('Batch intake confirmation failed: ' . $error->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'The intake receipt could not be saved. No receipt was issued.']);
            }
        }
        exit;
    } elseif (isset($_POST['set_batch_status'])) {
        $batchId = (int)($_POST['status_batch_id'] ?? 0);
        if ($batchId <= 0) {
            $_SESSION['batch_flash'] = show_alert('Select a valid batch.', 'danger');
        } else {
            try {
                $conn->begin_transaction();
                $stmt = safe_query($conn, 'SELECT status, paper_count, received_at FROM document_batches WHERE batch_id = ? FOR UPDATE', 'i', [$batchId]);
                if ($stmt === false) {
                    throw new RuntimeException('Could not verify the bulk submission status.');
                }
                $currentBatch = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);
                if (!$currentBatch) {
                    throw new RuntimeException('The batch was not found.');
                }
                $nextStatus = $currentBatch['status'] === 'Pending'
                    ? 'For Release'
                    : ($currentBatch['status'] === 'For Release' ? 'Released' : '');
                if ($nextStatus === '') {
                    throw new RuntimeException('This bulk submission has already been released.');
                }
                if ($currentBatch['status'] === 'Pending' && $batchWorkflowSettings['intake_confirmation_mode'] === 'confirm_before_release' && $currentBatch['received_at'] === null) {
                    throw new DomainException('Confirm the received document count using Scan CEIT QR before moving this bulk submission to For Release.');
                }

                if ($currentBatch['status'] === 'Pending' &&
                    $currentBatch['received_at'] === null &&
                    $batchWorkflowSettings['intake_confirmation_mode'] === 'confirm_with_release') {
                    $receivedCount = filter_var($_POST['status_received_count'] ?? null, FILTER_VALIDATE_INT);
                    $notesInput = $_POST['status_intake_notes'] ?? '';
                    $intakeNotes = is_string($notesInput) ? trim($notesInput) : '';
                    if ((int)$currentBatch['paper_count'] < 1 || (int)$currentBatch['paper_count'] > 9999 ||
                        $receivedCount === false || $receivedCount < 0 || $receivedCount > 9999 ||
                        !is_string($notesInput) || strlen($intakeNotes) > 1000) {
                        throw new DomainException('Enter a received count from 0 to 9,999 and a note of no more than 1,000 characters.');
                    }
                    if ($receivedCount !== (int)$currentBatch['paper_count'] && $intakeNotes === '') {
                        throw new DomainException('Add a note describing the count difference before moving the bulk submission to For Release.');
                    }
                    $receivedBy = trim((string)($_SESSION['admin'] ?? ''));
                    $receiptStmt = safe_query(
                        $conn,
                        'UPDATE document_batches
                         SET received_paper_count = ?, intake_expected_count = paper_count, intake_notes = ?, received_at = NOW(), received_by = ?
                         WHERE batch_id = ? AND received_at IS NULL',
                        'issi',
                        [$receivedCount, $intakeNotes, $receivedBy, $batchId]
                    );
                    if ($receiptStmt === false || mysqli_stmt_affected_rows($receiptStmt) !== 1) {
                        if ($receiptStmt !== false) mysqli_stmt_close($receiptStmt);
                        throw new RuntimeException('Could not save the intake receipt.');
                    }
                    mysqli_stmt_close($receiptStmt);
                }
                $stmt = safe_query($conn, 'UPDATE document_batches SET status = ? WHERE batch_id = ?', 'si', [$nextStatus, $batchId]);
                if ($stmt === false) {
                    throw new RuntimeException('Could not update the bulk submission status.');
                }
                mysqli_stmt_close($stmt);
                $stmt = safe_query(
                    $conn,
                    'UPDATE documents d INNER JOIN batch_papers p ON p.doc_id = d.doc_id SET d.status = ? WHERE p.batch_id = ?',
                    'si',
                    [$nextStatus, $batchId]
                );
                if ($stmt === false) {
                    throw new RuntimeException('Could not update the linked document statuses.');
                }
                mysqli_stmt_close($stmt);
                $conn->commit();
                $_SESSION['batch_flash'] = show_alert(
                    $currentBatch['status'] === 'Pending' && $batchWorkflowSettings['intake_confirmation_mode'] === 'confirm_with_release' && $currentBatch['received_at'] === null
                        ? 'Receipt confirmed and status updated to ' . $nextStatus . '.'
                        : 'Status updated to ' . $nextStatus . '.'
                );
            } catch (Throwable $error) {
                $conn->rollback();
                error_log('Batch status update failed: ' . $error->getMessage());
                $_SESSION['batch_flash'] = show_alert($error instanceof DomainException ? $error->getMessage() : 'Batch status could not be updated.', 'danger');
            }
        }
        header('Location: batch_papers' . ($search !== '' ? '?search=' . rawurlencode($search) : ''));
        exit;
    } elseif (isset($_POST['toggle_batch_priority'])) {
        $batchId = (int)($_POST['priority_batch_id'] ?? 0);
        if ($batchId <= 0) {
            $_SESSION['batch_flash'] = show_alert('Select a valid batch.', 'danger');
        } else {
            try {
                // The lock and transaction keep the batch flag and every linked document aligned.
                $conn->begin_transaction();
                $stmt = safe_query($conn, 'SELECT batch_id, is_priority FROM document_batches WHERE batch_id = ? FOR UPDATE', 'i', [$batchId]);
                if ($stmt === false) {
                    throw new RuntimeException('Could not verify the batch.');
                }
                $batch = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);
                if (!$batch) {
                    throw new RuntimeException('The batch was not found.');
                }
                $newPriority = (int)$batch['is_priority'] === 1 ? 0 : 1;
                $stmt = safe_query($conn, 'UPDATE document_batches SET is_priority = ? WHERE batch_id = ?', 'ii', [$newPriority, $batchId]);
                if ($stmt === false) {
                    throw new RuntimeException('Could not update the bulk submission priority.');
                }
                mysqli_stmt_close($stmt);
                $stmt = safe_query(
                    $conn,
                    'UPDATE documents d INNER JOIN batch_papers p ON p.doc_id = d.doc_id SET d.is_priority = ? WHERE p.batch_id = ?',
                    'ii',
                    [$newPriority, $batchId]
                );
                if ($stmt === false) {
                    throw new RuntimeException('Could not update the linked faculty document priority.');
                }
                mysqli_stmt_close($stmt);
                $conn->commit();
                $_SESSION['batch_flash'] = show_alert($newPriority === 1 ? 'Batch marked as priority.' : 'Batch priority removed.');
            } catch (Throwable $error) {
                $conn->rollback();
                error_log('Batch priority update failed: ' . $error->getMessage());
                $_SESSION['batch_flash'] = show_alert('Batch priority could not be updated.', 'danger');
            }
        }
        header('Location: batch_papers' . ($search !== '' ? '?search=' . rawurlencode($search) : ''));
        exit;
    } elseif (isset($_POST['save_batch_paper'])) {
        $paperId = (int)($_POST['paper_id'] ?? 0);
        $subject = trim($_POST['paper_subject'] ?? '');
        $remarks = trim($_POST['paper_remarks'] ?? '');
        $subjectLength = function_exists('mb_strlen') ? mb_strlen($subject, 'UTF-8') : strlen($subject);
        if ($paperId <= 0 || $subject === '' || $subjectLength > 255) {
            $message = show_alert('Enter a document title of 1 to 255 characters.', 'danger');
        } else {
            try {
                // Keep the batch-paper entry and its mirrored document row synchronized in one transaction.
                $conn->begin_transaction();
                $stmt = safe_query($conn, 'SELECT doc_id FROM batch_papers WHERE paper_id = ? FOR UPDATE', 'i', [$paperId]);
                if ($stmt === false) {
                    throw new RuntimeException('Could not find the paper entry.');
                }
                $paper = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);
                if (!$paper) {
                    throw new RuntimeException('The paper entry was not found.');
                }
                $stmt = safe_query($conn, 'UPDATE documents SET subject = ?, remarks = ? WHERE doc_id = ?', 'ssi', [$subject, $remarks, $paper['doc_id']]);
                if ($stmt === false) {
                    throw new RuntimeException('Could not update the document record.');
                }
                mysqli_stmt_close($stmt);
                $stmt = safe_query($conn, 'UPDATE batch_papers SET subject = ?, remarks = ? WHERE paper_id = ?', 'ssi', [$subject, $remarks, $paperId]);
                if ($stmt === false) {
                    throw new RuntimeException('Could not update the document entry.');
                }
                mysqli_stmt_close($stmt);
                $conn->commit();
                $_SESSION['batch_flash'] = show_alert('Document details updated.');
                header('Location: batch_papers' . (isset($_GET['search']) ? '?search=' . rawurlencode((string)$_GET['search']) : ''));
                exit;
            } catch (Throwable $error) {
                $conn->rollback();
                error_log('Batch paper update failed: ' . $error->getMessage());
                $message = show_alert('The document details could not be updated.', 'danger');
            }
        }
    } elseif (isset($_POST['save_batch'])) {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        if ($batchId === 0) {
            $batchDate = date('Y-m-d');
        }
        $dateObject = DateTime::createFromFormat('!Y-m-d', $batchDate);
        $validDocumentType = in_array($documentType, ['Incoming', 'Outgoing'], true);
        $validSenderType = in_array($senderType, ['Student', 'Faculty'], true);
        if (!$validDocumentType || $batchType === '' || strlen($batchType) > 255 ||
            $batchSubmitterName === '' || strlen($batchSubmitterName) > 255 ||
            ($batchId === 0 && (!$dateObject || $dateObject->format('Y-m-d') !== $batchDate || $batchDate < date('Y-m-d'))) ||
            !$validSenderType || $paperCount < 1 || $paperCount > 3 || !$validPages) {
                        $message = show_alert('Check the submission type, date (not earlier than today) for a new entry, submitter, document count (1–3), and pages per document (optional, 1–9,999).', 'danger');
            $showBatchModal = true;
        } elseif ($batchId > 0) {
            try {
                $conn->begin_transaction();
                $existsStmt = safe_query($conn, 'SELECT batch_id, status, paper_count, received_at FROM document_batches WHERE batch_id = ? FOR UPDATE', 'i', [$batchId]);
                if ($existsStmt === false) {
                    throw new RuntimeException('Could not verify the batch.');
                }
                $existingBatch = mysqli_fetch_assoc(mysqli_stmt_get_result($existsStmt));
                mysqli_stmt_close($existsStmt);
                if (!$existingBatch) {
                    throw new RuntimeException('The batch was not found.');
                }
                if ($existingBatch['received_at'] !== null && $paperCount !== (int)$existingBatch['paper_count']) {
                    throw new RuntimeException('The expected document count cannot be changed after staff confirm receipt.');
                }
                $batchStatus = $existingBatch['status'];
                $stmt = safe_query(
                    $conn,
                    'UPDATE document_batches SET document_type = ?, batch_type = ?, sender = ?, sender_type = ?, submitter_name = ?, remarks = ?, paper_count = ?, document_pages = ?, is_priority = ? WHERE batch_id = ?',
                                        'ssssssisii',
                                        [$documentType, $batchType, $senderType, $senderType, $batchSubmitterName, $batchRemarks, $paperCount, $documentPagesJson, $batchPriority, $batchId]
                );
                if ($stmt === false || mysqli_stmt_affected_rows($stmt) < 0) {
                    if ($stmt !== false) mysqli_stmt_close($stmt);
                    throw new RuntimeException('Could not update the bulk submission details.');
                }
                mysqli_stmt_close($stmt);
                // Keep legacy document fields synchronized because existing reports and screens query documents.
                $stmt = safe_query(
                    $conn,
                    'UPDATE documents d INNER JOIN batch_papers p ON p.doc_id = d.doc_id SET d.type = ?, d.sender = ?, d.submitter_type = ?, d.status = ?, d.is_priority = ? WHERE p.batch_id = ?',
                    'ssssii',
                    [$documentType, $senderType, $senderType, $batchStatus, $batchPriority, $batchId]
                );
                if ($stmt === false) {
                    throw new RuntimeException('Could not update the linked document records.');
                }
                mysqli_stmt_close($stmt);
                $conn->commit();
                $_SESSION['batch_flash'] = show_alert('Batch details updated.');
                header('Location: batch_papers' . ($search !== '' ? '?search=' . rawurlencode($search) : ''));
                exit;
            } catch (Throwable $error) {
                $conn->rollback();
                error_log('Batch update failed: ' . $error->getMessage());
                $message = show_alert('The bulk submission could not be updated.', 'danger');
                $showBatchModal = true;
            }
        } else {
            try {
                // New batch metadata is committed as one operation so failed inserts leave no partial batch.
                $conn->begin_transaction();
                $batchCode = 'BP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $trackingToken = bin2hex(random_bytes(32));
                $recipientValue = '';
                $stmt = safe_query(
                    $conn,
                    'INSERT INTO document_batches (batch_code, document_type, batch_type, status, batch_date, sender, sender_type, submitter_name, recipient, recipient_contact_type, remarks, tracking_token,                     paper_count, document_pages, is_priority) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                                        'ssssssssssssisi',
                                        [$batchCode, $documentType, $batchType, 'Pending', $batchDate, $senderType, $senderType, $batchSubmitterName, $recipientValue, $recipientContactType, $batchRemarks, $trackingToken, $paperCount, $documentPagesJson, $batchPriority]
                );
                if ($stmt === false) {
                    throw new RuntimeException('Could not create the bulk submission.');
                }
                mysqli_stmt_close($stmt);
                $conn->commit();
                $_SESSION['batch_flash'] = show_alert('Batch ' . $batchCode . ' saved with ' . $paperCount . ' paper(s).');
                header('Location: batch_papers');
                exit;
            } catch (Throwable $error) {
                $conn->rollback();
                error_log('Batch papers save failed: ' . $error->getMessage());
                $message = show_alert('The bulk submission could not be saved.', 'danger');
                $showBatchModal = true;
            }
        }
    } elseif (isset($_POST['delete_batch_paper'])) {
        $paperId = (int)($_POST['paper_id'] ?? 0);
        if ($paperId <= 0) {
            $message = show_alert('Select a valid document entry.', 'danger');
        } else {
            try {
                $conn->begin_transaction();
                $batchStmt = safe_query(
                    $conn,
                    'SELECT p.batch_id, b.received_at
                     FROM batch_papers p INNER JOIN document_batches b ON b.batch_id = p.batch_id
                     WHERE p.paper_id = ? FOR UPDATE',
                    'i',
                    [$paperId]
                );
                if ($batchStmt === false) {
                    throw new RuntimeException('Could not find the paper entry.');
                }
                $paperBatch = mysqli_fetch_assoc(mysqli_stmt_get_result($batchStmt));
                mysqli_stmt_close($batchStmt);
                if (!$paperBatch) {
                    throw new RuntimeException('The paper entry was not found.');
                }
                if ($paperBatch['received_at'] !== null) {
                    throw new DomainException('A document cannot be removed after the bulk submission receipt has been confirmed.');
                }
                $stmt = safe_query(
                    $conn,
                    'DELETE d FROM documents d INNER JOIN batch_papers p ON p.doc_id = d.doc_id WHERE p.paper_id = ?',
                    'i',
                    [$paperId]
                );
                if ($stmt === false || mysqli_stmt_affected_rows($stmt) !== 1) {
                    if ($stmt !== false) mysqli_stmt_close($stmt);
                    throw new RuntimeException('The document entry could not be deleted.');
                }
                mysqli_stmt_close($stmt);
                $countStmt = safe_query($conn, 'UPDATE document_batches SET paper_count = GREATEST(paper_count - 1, 0) WHERE batch_id = ?', 'i', [$paperBatch['batch_id']]);
                if ($countStmt === false) {
                    throw new RuntimeException('Could not update the document count.');
                }
                mysqli_stmt_close($countStmt);
                $conn->commit();
                $_SESSION['batch_flash'] = show_alert('Paper removed from the batch.');
                header('Location: batch_papers' . (isset($_GET['search']) ? '?search=' . rawurlencode((string)$_GET['search']) : ''));
                exit;
            } catch (Throwable $error) {
                $conn->rollback();
                error_log('Batch paper deletion failed: ' . $error->getMessage());
                $message = show_alert($error instanceof DomainException ? $error->getMessage() : 'The paper could not be removed from the batch.', 'danger');
            }
        }
    } elseif (isset($_POST['delete_batch'])) {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        if ($batchId > 0) {
            try {
                $conn->begin_transaction();
                $checkStmt = safe_query($conn, 'SELECT received_at FROM document_batches WHERE batch_id = ? FOR UPDATE', 'i', [$batchId]);
                if ($checkStmt === false) {
                    throw new RuntimeException('Could not verify the batch.');
                }
                $batchForDeletion = mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt));
                mysqli_stmt_close($checkStmt);
                if (!$batchForDeletion) {
                    throw new RuntimeException('The batch was not found.');
                }
                if ($batchForDeletion['received_at'] !== null) {
                    throw new DomainException('A bulk submission cannot be deleted after its receipt has been confirmed.');
                }
                $stmt = safe_query(
                    $conn,
                    'DELETE d FROM documents d INNER JOIN batch_papers p ON p.doc_id = d.doc_id WHERE p.batch_id = ?',
                    'i',
                    [$batchId]
                );
                if ($stmt === false) {
                    throw new RuntimeException('Could not delete the bulk submission documents.');
                }
                mysqli_stmt_close($stmt);
                $stmt = safe_query($conn, 'DELETE FROM document_batches WHERE batch_id = ?', 'i', [$batchId]);
                if ($stmt === false) {
                    throw new RuntimeException('Could not delete the bulk submission.');
                }
                mysqli_stmt_close($stmt);
                $conn->commit();
                $_SESSION['batch_flash'] = show_alert('Batch and its paper entries were deleted.');
                header('Location: batch_papers');
                exit;
            } catch (Throwable $error) {
                $conn->rollback();
                error_log('Batch papers deletion failed: ' . $error->getMessage());
                if ($error instanceof DomainException) {
                    $message = show_alert($error->getMessage(), 'danger');
                }
            }
        }
        if ($message === '') {
            $message = show_alert('Unable to delete the selected bulk submission.', 'danger');
        }
    }
}

if (isset($_SESSION['batch_flash'])) {
    $message = $_SESSION['batch_flash'] . $message;
    unset($_SESSION['batch_flash']);
}

$batches = [];
$papersByBatch = [];
if (!$setupError) {
    if ($search !== '') {
        $term = '%' . $search . '%';
        $stmt = safe_query(
            $conn,
            "SELECT b.*
             FROM document_batches b
             WHERE b.batch_code LIKE ? OR b.document_type LIKE ? OR b.batch_type LIKE ? OR b.status LIKE ? OR b.sender LIKE ? OR b.submitter_name LIKE ? OR b.recipient LIKE ? OR b.remarks LIKE ?
                OR EXISTS (SELECT 1 FROM batch_papers p WHERE p.batch_id = b.batch_id AND (p.subject LIKE ? OR p.remarks LIKE ?))
             ORDER BY b.is_priority DESC, (b.status = 'Released') ASC, CASE WHEN b.status <> 'Released' THEN b.batch_id END ASC, b.batch_id DESC",
            'ssssssssss',
            [$term, $term, $term, $term, $term, $term, $term, $term, $term, $term]
        );
    } else {
        $stmt = safe_query(
            $conn,
            "SELECT b.* FROM document_batches b ORDER BY b.is_priority DESC, (b.status = 'Released') ASC, CASE WHEN b.status <> 'Released' THEN b.batch_id END ASC, b.batch_id DESC"
        );
    }

    if ($stmt !== false) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            if (empty($row['tracking_token'])) {
                $row['tracking_token'] = bin2hex(random_bytes(32));
                $tokenStmt = safe_query($conn, 'UPDATE document_batches SET tracking_token = ? WHERE batch_id = ? AND tracking_token IS NULL', 'si', [$row['tracking_token'], $row['batch_id']]);
                if ($tokenStmt === false) {
                    throw new RuntimeException('Could not create the bulk submission tracking link.');
                }
                mysqli_stmt_close($tokenStmt);
            }
            $batches[] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    if (count($batches) > 0) {
        $paperStmt = safe_query($conn, 'SELECT p.batch_id, p.paper_id, p.subject, p.remarks FROM batch_papers p INNER JOIN documents d ON d.doc_id = p.doc_id ORDER BY p.paper_id');
        if ($paperStmt !== false) {
            $paperResult = mysqli_stmt_get_result($paperStmt);
            while ($paper = mysqli_fetch_assoc($paperResult)) {
                $papersByBatch[$paper['batch_id']][] = $paper;
            }
            mysqli_stmt_close($paperStmt);
        }
    }
}

?>
<?php include 'header.php'; ?>
<?php
$isEmbeddedBatchAction = ($_GET['embed'] ?? '') === '1';
$embeddedBatchAction = $isEmbeddedBatchAction ? ($_GET['open'] ?? '') : '';
?>
<div class="row g-4<?php echo $isEmbeddedBatchAction ? ' batch-papers-embedded batch-embed-' . ($embeddedBatchAction === 'scanner' ? 'scanner' : 'batch') : ''; ?>">
    <div class="col-12">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-2">
            <div>
                <h2 class="mb-1 text-primary">Bulk Documents</h2>
                <p class="text-muted mb-0">Register several documents in one submission.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#create-batch" <?php echo $setupError ? 'disabled' : ''; ?>>
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Bulk Documents
                </button>
                <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#batchScannerModal" <?php echo $setupError ? 'disabled' : ''; ?>>
                    <i class="bi bi-qr-code-scan me-1" aria-hidden="true"></i>Scan CEIT QR
                </button>
            </div>
        </div>
    </div>
    <div class="col-12">
        <?php if ($message) echo $message; ?>
        <?php if ($setupError): ?>
            <div class="alert alert-danger" role="alert">Bulk document storage could not be initialized. Check the database connection and permissions.</div>
        <?php endif; ?>
    </div>
    <div class="col-12">
        <div class="<?php echo $isEmbeddedBatchAction && $embeddedBatchAction === 'batch' ? 'embedded-batch-tool' : 'modal fade'; ?>" id="create-batch" tabindex="-1" aria-labelledby="createBatchTitle" aria-hidden="true" data-open-on-load="<?php echo $showBatchModal || ($_GET['add'] ?? '') === '1' || ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['open'] ?? '') === 'batch') ? 'true' : 'false'; ?>" data-preserve-values="<?php echo $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_batch']) ? 'true' : 'false'; ?>" data-posted-batch-id="<?php echo (int)($_POST['batch_id'] ?? 0); ?>">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title text-primary"                             id="createBatchTitle">Create Bulk Documents</h5>
                                                        <p class="mb-0 mt-1 small text-muted">Record the submission details and the total number of documents.</p>
                        </div>
                        <?php if (!$isEmbeddedBatchAction): ?><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button><?php endif; ?>
                    </div>
                    <form method="POST">
                    <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                    <input type="hidden" name="batch_id" id="editBatchId" value="">
                    <div class="row g-3">
                    <div class="col-12 col-md-6">
                    <div class="mb-3">
                        <label class="form-label" for="batchType">Document name</label>
                        <input class="form-control" type="text" id="batchType" name="batch_type" maxlength="255" value="<?php echo sanitize($batchType); ?>" placeholder="e.g. Transcript request documents" required>
                    </div>
                    <div class="mb-3">
                        <input type="hidden" id="documentType" name="document_type" value="<?php echo sanitize($documentType); ?>">
                    </div>
                    </div>
                    <div class="col-12 col-md-6">
                    <div class="mb-3">
                        <label class="form-label" for="batchDate">Submission date</label>
                        <input class="form-control" type="date" id="batchDate" value="<?php echo sanitize($batchDate); ?>" disabled>
                        <input type="hidden" id="immutableBatchDate" disabled>
                        <div id="batchDateLockedHelp" class="form-text" hidden>The submission date cannot be changed after the batch is created.</div>
                    </div>
                    </div>
                    <div class="col-12 col-md-6">
                    <div class="mb-3">
                        <label class="form-label" for="batchSubmitterName">Submitter name</label>
                        <input class="form-control" type="text" id="batchSubmitterName" name="submitter_name" maxlength="255" value="<?php echo sanitize($batchSubmitterName); ?>" autocomplete="name" placeholder="Enter the submitter's full name" required>
                    </div>
                    </div>
                    <div class="col-12 col-md-6">
                    <div class="mb-3">
                        <label class="form-label" for="batchSenderType"><?php echo sanitize($mobileFormLabels['sender']); ?></label>
                        <select class="form-select" id="batchSenderType" name="sender_type" required>
                            <option value="Student" <?php echo $senderType === 'Student' ? 'selected' : ''; ?>>Student</option>
                            <option value="Faculty" <?php echo $senderType === 'Faculty' ? 'selected' : ''; ?>>Faculty</option>
                        </select>
                    </div>
                    </div>
                    <div class="col-12">
                    <div class="mb-3">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="batchPriority" name="is_priority" value="1" <?php echo $batchPriority === 1 ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="batchPriority">Mark this bulk submission as priority</label>
                        </div>
                    </div>
                    </div>
                    <div class="col-12">
                    <div class="mb-3">
                        <label class="form-label" for="batchPaperCount">Number of documents</label>
                        <input class="form-control" type="number" id="batchPaperCount" name="paper_count" min="1" max="3" value="<?php echo $paperCount; ?>" required>
                        <div class="form-text" id="batchPaperCountLockedHelp" hidden>The expected count is locked because staff have confirmed the intake receipt.</div>
                    </div>
                    </div>
                    <div class="col-12">
                    <div class="mb-3">
                        <span class="form-label d-block">Pages per document <span class="text-muted fw-normal">(optional)</span></span>
                        <div class="form-text mb-2">Enter how many pages each document has. Leave blank if not needed.</div>
                        <div id="batchDocumentPages" class="row g-2" data-initial="<?php echo sanitize((string)($documentPagesJson ?? '')); ?>"></div>
                    </div>
                    </div>
                    <div class="col-12">
                    <div class="mb-3">
                        <label class="form-label" for="batchRemarks"><?php echo sanitize($mobileFormLabels['remarks']); ?></label>
                        <textarea class="form-control" id="batchRemarks" name="remarks" rows="2"><?php echo sanitize($batchRemarks); ?></textarea>
                    </div>
                    </div>
                    </div>
                    </div>
                    <div class="modal-footer">
                        <?php if (!$isEmbeddedBatchAction): ?><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><?php endif; ?>
                        <button class="btn btn-primary" type="submit" name="save_batch" id="saveBatchButton" <?php echo $setupError ? 'disabled' : ''; ?>><i class="bi bi-archive me-1"></i> Save Bulk Documents</button>
                    </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
                    <h5 class="card-title text-primary mb-0">Bulk Documents History</h5>
                    <form method="GET" class="d-flex gap-2">
                        <input class="form-control" type="search" name="search" placeholder="Search by reference or subject" value="<?php echo sanitize($search); ?>">
                        <button class="btn btn-outline-primary" type="submit" aria-label="Search"><i class="bi bi-search"></i></button>
                    </form>
                </div>
                <!-- Keep the desktop history table separate from the narrow-screen card list below. -->
                <div class="table-responsive d-none d-xl-block batch-history-table">
                    <table class="table table-hover align-middle mb-0 batch-history-data-table">
                        <thead class="table-light">
                            <tr><th scope="col">Date</th><th scope="col">Reference</th><th scope="col">Document name</th><th scope="col">Submitted by / recipient</th><th scope="col">Documents</th><th scope="col">Status</th><th scope="col">Priority</th><th scope="col">Tracking QR</th><th scope="col">Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php if (!$batches): ?>
                                <tr><td colspan="9" class="text-center text-muted py-4">No bulk documents found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($batches as $batch): ?>
                                    <tr>
                                        <td><?php echo sanitize(date('M j, Y', strtotime($batch['batch_date']))); ?></td>
                                        <td class="fw-semibold">
                                            <?php echo sanitize($batch['batch_code']); ?>
                                        </td>
                                        <td><?php echo sanitize($batch['batch_type']); ?></td>
                                        <td><?php echo sanitize($batch['sender_type']); ?><br><small class="text-muted">Submitted by <?php echo sanitize(trim((string)($batch['submitter_name'] ?? '')) !== '' ? $batch['submitter_name'] : 'Name not recorded'); ?></small><br><small class="text-muted"><?php echo sanitize($batch['recipient_contact_type'] === 'Unassigned' ? 'Recipient not provided' : $batch['recipient_contact_type']); ?><?php echo $batch['recipient'] !== '' ? ': ' . sanitize($batch['recipient']) : ''; ?></small></td>
                                        <td>
                                            <?php echo (int)$batch['paper_count']; ?> expected
                                            <?php if ($batch['received_at'] !== null): ?>
                                                <br><small class="<?php echo (int)$batch['received_paper_count'] === (int)$batch['intake_expected_count'] ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo (int)$batch['received_paper_count']; ?> received · <?php echo sanitize(date('M j, g:i A', strtotime($batch['received_at']))); ?>
                                                </small>
                                            <?php else: ?>
                                                <br><small class="text-muted">Awaiting count</small>
                                            <?php endif; ?>
                                                                            <?php if (batch_pages_label($batch['document_pages'] ?? null) !== ''): ?>
                                                                                <br><small class="text-muted"><?php echo sanitize(batch_pages_label($batch['document_pages'])); ?></small>
                                                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge <?php echo admin_status_badge_class($batch['status']); ?>"><?php echo sanitize($batch['status']); ?></span></td>
                                        <td>
                                            <form method="POST" class="batch-priority-control">
                                                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                                                <input type="hidden" name="priority_batch_id" value="<?php echo (int)$batch['batch_id']; ?>">
                                                <button class="btn btn-sm <?php echo (int)$batch['is_priority'] === 1 ? 'btn-warning' : 'btn-outline-secondary'; ?>" type="submit" name="toggle_batch_priority" aria-label="<?php echo (int)$batch['is_priority'] === 1 ? 'Remove priority from' : 'Mark as priority'; ?> <?php echo sanitize($batch['batch_code']); ?>">
                                                    <i class="bi bi-star<?php echo (int)$batch['is_priority'] === 1 ? '-fill' : ''; ?> me-1" aria-hidden="true"></i><?php echo (int)$batch['is_priority'] === 1 ? 'Priority' : 'Standard'; ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td class="text-center">
                                            <?php if (!empty($batch['tracking_token'])): ?>
                                                <button class="btn btn-sm btn-outline-secondary" type="button" aria-label="View tracking QR code for <?php echo sanitize($batch['batch_code']); ?>" title="View tracking QR code" onclick="showBatchQr(<?php echo htmlspecialchars(json_encode($batch['tracking_token'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($batch['batch_code'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                                                    <i class="bi bi-qr-code me-1" aria-hidden="true"></i>View QR code
                                                </button>
                                            <?php else: ?>
                                                <span class="small text-muted">Not issued</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="batch-row-actions">
                                                <?php if ($batch['status'] !== 'Released'): ?>
                                                    <form method="POST" class="m-0">
                                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                        <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                                                        <input type="hidden" name="status_batch_id" value="<?php echo (int)$batch['batch_id']; ?>">
                                                        <button class="btn btn-sm <?php echo $batch['status'] === 'For Release' ? 'btn-success' : 'btn-primary'; ?>"
                                                            type="<?php echo $batch['status'] === 'Pending' && $batchWorkflowSettings['intake_confirmation_mode'] === 'confirm_with_release' && $batch['received_at'] === null ? 'button' : 'submit'; ?>"
                                                            <?php if ($batch['status'] === 'Pending' && $batchWorkflowSettings['intake_confirmation_mode'] === 'confirm_with_release' && $batch['received_at'] === null): ?>
                                                                data-bs-toggle="modal" data-bs-target="#confirmBatchReleaseModal"
                                                                data-batch-id="<?php echo (int)$batch['batch_id']; ?>"
                                                                data-batch-code="<?php echo sanitize($batch['batch_code']); ?>"
                                                                data-paper-count="<?php echo (int)$batch['paper_count']; ?>"
                                                            <?php else: ?>
                                                                name="set_batch_status"
                                                                <?php if ($batch['status'] === 'Pending' && $batchWorkflowSettings['intake_confirmation_mode'] === 'confirm_before_release' && $batch['received_at'] === null): ?>
                                                                    disabled title="Confirm receipt using Scan CEIT QR before moving this bulk submission to For Release."
                                                                <?php else: ?>
                                                                    data-confirm-title="<?php echo $batch['status'] === 'For Release' ? 'Mark bulk submission as Released?' : 'Move bulk submission to For Release?'; ?>"
                                                                    data-confirm-message="<?php echo $batch['status'] === 'For Release' ? 'This will mark the bulk submission and its linked documents as Released.' : 'This will move the bulk submission and its linked documents to For Release.'; ?>"
                                                                    data-confirm-button="<?php echo $batch['status'] === 'For Release' ? 'Released' : 'For Release'; ?>"
                                                                <?php endif; ?>
                                                            <?php endif; ?>>
                                                            <i class="bi <?php echo $batch['status'] === 'For Release' ? 'bi-check2-circle' : 'bi-box-arrow-up-right'; ?> me-1" aria-hidden="true"></i>
                                                            <?php echo $batch['status'] === 'For Release' ? 'Mark released' : 'For release'; ?>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-outline-primary batch-action-button edit-batch" type="button" title="Edit batch" aria-label="Edit <?php echo sanitize($batch['batch_code']); ?>" data-bs-toggle="modal" data-bs-target="#create-batch"
                                                    data-batch-id="<?php echo (int)$batch['batch_id']; ?>"
                                                    data-document-type="<?php echo sanitize($batch['document_type']); ?>"
                                                    data-batch-type="<?php echo sanitize($batch['batch_type']); ?>"
                                                    data-batch-status="<?php echo sanitize($batch['status']); ?>"
                                                    data-is-priority="<?php echo (int)$batch['is_priority']; ?>"
                                                    data-batch-date="<?php echo sanitize($batch['batch_date']); ?>"
                                                    data-sender-type="<?php echo sanitize($batch['sender_type']); ?>"
                                                    data-submitter-name="<?php echo sanitize($batch['submitter_name'] ?? ''); ?>"
                                                    data-recipient-type="<?php echo sanitize($batch['recipient_contact_type']); ?>"
                                                    data-recipient="<?php echo sanitize($batch['recipient']); ?>"
                                                    data-paper-count="<?php echo (int)$batch['paper_count']; ?>"
                                                    data-remarks="<?php echo sanitize($batch['remarks'] ?? ''); ?>"
                                                    data-batch-code="<?php echo sanitize($batch['batch_code']); ?>"
                                                    data-received-at="<?php echo sanitize((string)$batch['received_at']); ?>"
                                                    data-document-pages="<?php echo sanitize((string)($batch['document_pages'] ?? '')); ?>">
                                                    <i class="bi bi-pencil" aria-hidden="true"></i>                                                    <span class="visually-hidden">Edit bulk submission</span>
                                                </button>
                                                <button class="btn btn-sm btn-outline-secondary batch-action-button" type="button" title="View documents" aria-label="View documents in <?php echo sanitize($batch['batch_code']); ?>" data-bs-toggle="collapse" data-bs-target="#papers<?php echo (int)$batch['batch_id']; ?>" aria-expanded="false" aria-controls="papers<?php echo (int)$batch['batch_id']; ?>">
                                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                                </button>
                                                <?php if ($batch['received_at'] === null): ?>
                                                    <form method="POST" class="m-0">
                                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                        <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                                                        <input type="hidden" name="batch_id" value="<?php echo (int)$batch['batch_id']; ?>">
                                                                                                            <button class="btn btn-sm btn-outline-danger batch-action-button" type="submit" name="delete_batch" title="Delete bulk submission" aria-label="Delete <?php echo sanitize($batch['batch_code']); ?>" data-confirm-title="Delete this bulk submission?" data-confirm-message="This permanently deletes the bulk submission and all of its associated documents." data-confirm-button="Delete bulk submission">
                                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr class="collapse" id="papers<?php echo (int)$batch['batch_id']; ?>">
                                        <td colspan="9" class="bg-light">
                                            <div class="py-2">
                                                <?php if (trim((string)$batch['remarks']) !== ''): ?>
                                                    <p class="small mb-2">                                                    <strong>Remarks:</strong> <?php echo nl2br(sanitize($batch['remarks'])); ?></p>
                                                <?php endif; ?>
                                                <ol class="mb-0">
                                                    <?php foreach ($papersByBatch[$batch['batch_id']] ?? [] as $paper): ?>
                                                        <li class="mb-3 d-flex flex-column flex-lg-row align-items-lg-start justify-content-between gap-2">
                                                            <div class="min-w-0">
                                                                <strong class="text-break"><?php echo sanitize($paper['subject']); ?></strong>
                                                                <?php if (trim((string)$paper['remarks']) !== ''): ?>
                                                                    <p class="small text-muted mb-0"><?php echo nl2br(sanitize($paper['remarks'])); ?></p>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="d-flex flex-wrap gap-2 flex-shrink-0">
                                                                <button class="btn btn-sm btn-outline-primary edit-batch-paper" type="button" data-bs-toggle="modal" data-bs-target="#editBatchPaperModal" data-paper-id="<?php echo (int)$paper['paper_id']; ?>" data-paper-subject="<?php echo sanitize($paper['subject']); ?>" data-paper-remarks="<?php echo sanitize($paper['remarks']); ?>">
                                                                    <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit
                                                                </button>
                                                                <?php if ($batch['received_at'] === null): ?>
                                                                    <form method="POST">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                                        <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                                                                        <input type="hidden" name="paper_id" value="<?php echo (int)$paper['paper_id']; ?>">
                                                                                                                                    <button class="btn btn-sm btn-outline-danger" type="submit" name="delete_batch_paper" data-confirm-title="Remove this document?" data-confirm-message="This removes the document and its linked record from the bulk submission." data-confirm-button="Remove document">
                                                                            <i class="bi bi-trash me-1" aria-hidden="true"></i>Remove
                                                                        </button>
                                                                    </form>
                                                                <?php endif; ?>
                                                            </div>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ol>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="batch-history-cards d-xl-none">
                    <?php if (!$batches): ?>
                        <div class="rounded-3 border bg-light px-3 py-4 text-center text-muted">No bulk documents found.</div>
                    <?php else: ?>
                        <div class="d-grid gap-3">
                            <?php foreach ($batches as $batch): ?>
                                <article class="batch-history-card border rounded-3 bg-white p-3">
                                    <div class="d-flex align-items-start justify-content-between gap-3">
                                        <div class="min-w-0">
                                            <h6 class="mb-1 text-break fw-bold">
                                                <?php echo sanitize($batch['batch_code']); ?>
                                            </h6>
                                            <p class="mb-0 small text-muted"><?php echo sanitize(date('M j, Y', strtotime($batch['batch_date']))); ?></p>
                                        </div>
                                        <span class="badge rounded-pill flex-shrink-0 <?php echo admin_status_badge_class($batch['status']); ?>"><?php echo sanitize($batch['status']); ?></span>
                                    </div>
                                        <div class="batch-priority-strip <?php echo (int)$batch['is_priority'] === 1 ? 'is-priority' : 'is-standard'; ?>">
                                            <div>
                                                <span class="small text-uppercase fw-bold">Handling</span>
                                                <span class="fw-semibold"><?php echo (int)$batch['is_priority'] === 1 ? 'Priority review' : 'Standard'; ?></span>
                                            </div>
                                            <form method="POST" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                                                <input type="hidden" name="priority_batch_id" value="<?php echo (int)$batch['batch_id']; ?>">
                                                <button class="btn btn-sm <?php echo (int)$batch['is_priority'] === 1 ? 'btn-warning' : 'btn-outline-secondary'; ?>" type="submit" name="toggle_batch_priority">
                                                    <i class="bi bi-star<?php echo (int)$batch['is_priority'] === 1 ? '-fill' : ''; ?> me-1" aria-hidden="true"></i><?php echo (int)$batch['is_priority'] === 1 ? 'Remove priority' : 'Mark priority'; ?>
                                                </button>
                                            </form>
                                        </div>
                                    <dl class="row small g-2 mt-2 mb-3">
                                        <dt class="col-4 text-muted">Document Name</dt>
                                        <dd class="col-8 mb-0 text-break"><?php echo sanitize($batch['batch_type']); ?></dd>
                                        <dt class="col-4 text-muted">Tracking QR</dt>
                                        <dd class="col-8 mb-0">
                                            <?php if (!empty($batch['tracking_token'])): ?>
                                                <button class="btn btn-sm btn-outline-secondary" type="button" aria-label="View tracking QR code for <?php echo sanitize($batch['batch_code']); ?>" onclick="showBatchQr(<?php echo htmlspecialchars(json_encode($batch['tracking_token'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($batch['batch_code'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                                                    <i class="bi bi-qr-code me-1" aria-hidden="true"></i>View QR code
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted">Not issued</span>
                                            <?php endif; ?>
                                        </dd>
                                        <dt class="col-4 text-muted">From</dt>
                                        <dd class="col-8 mb-0 text-break"><?php echo sanitize($batch['sender_type']); ?></dd>
                                        <dt class="col-4 text-muted">Submitted by</dt>
                                        <dd class="col-8 mb-0 text-break"><?php echo sanitize(trim((string)($batch['submitter_name'] ?? '')) !== '' ? $batch['submitter_name'] : 'Name not recorded'); ?></dd>
                                        <dt class="col-4 text-muted">Recipient</dt>
                                        <dd class="col-8 mb-0 text-break"><?php echo sanitize($batch['recipient_contact_type']); ?><?php echo $batch['recipient'] !== '' ? ': ' . sanitize($batch['recipient']) : ''; ?></dd>
                                        <dt class="col-4 text-muted">Documents</dt>
                                        <dd class="col-8 mb-0">
                                            <?php echo (int)$batch['paper_count']; ?> expected
                                            <?php if ($batch['received_at'] !== null): ?>
                                                <br><span class="<?php echo (int)$batch['received_paper_count'] === (int)$batch['intake_expected_count'] ? 'text-success' : 'text-danger'; ?>"><?php echo (int)$batch['received_paper_count']; ?> received at <?php echo sanitize(date('M j, g:i A', strtotime($batch['received_at']))); ?></span>
                                            <?php else: ?>
                                                <br><span class="text-muted">Awaiting count</span>
                                            <?php endif; ?>
                                                                            <?php if (batch_pages_label($batch['document_pages'] ?? null) !== ''): ?>
                                                                                <br><span class="text-muted"><?php echo sanitize(batch_pages_label($batch['document_pages'])); ?></span>
                                                                            <?php endif; ?>
                                        </dd>
                                    </dl>
                                    <div class="batch-card-actions">
                                        <?php if ($batch['status'] !== 'Released'): ?>
                                            <form method="POST" class="m-0 flex-grow-1">
                                                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                                                <input type="hidden" name="status_batch_id" value="<?php echo (int)$batch['batch_id']; ?>">
                                                <button class="btn btn-sm w-100 <?php echo $batch['status'] === 'For Release' ? 'btn-success' : 'btn-primary'; ?>"
                                                    type="<?php echo $batch['status'] === 'Pending' && $batchWorkflowSettings['intake_confirmation_mode'] === 'confirm_with_release' && $batch['received_at'] === null ? 'button' : 'submit'; ?>"
                                                    <?php if ($batch['status'] === 'Pending' && $batchWorkflowSettings['intake_confirmation_mode'] === 'confirm_with_release' && $batch['received_at'] === null): ?>
                                                        data-bs-toggle="modal" data-bs-target="#confirmBatchReleaseModal"
                                                        data-batch-id="<?php echo (int)$batch['batch_id']; ?>"
                                                        data-batch-code="<?php echo sanitize($batch['batch_code']); ?>"
                                                        data-paper-count="<?php echo (int)$batch['paper_count']; ?>"
                                                    <?php else: ?>
                                                        name="set_batch_status"
                                                        <?php if ($batch['status'] === 'Pending' && $batchWorkflowSettings['intake_confirmation_mode'] === 'confirm_before_release' && $batch['received_at'] === null): ?>
                                                            disabled title="Confirm receipt using Scan CEIT QR before moving this bulk submission to For Release."
                                                        <?php else: ?>
                                                            data-confirm-title="<?php echo $batch['status'] === 'For Release' ? 'Mark bulk submission as Released?' : 'Move bulk submission to For Release?'; ?>"
                                                            data-confirm-message="<?php echo $batch['status'] === 'For Release' ? 'This will mark the bulk submission and its linked documents as Released.' : 'This will move the bulk submission and its linked documents to For Release.'; ?>"
                                                            data-confirm-button="<?php echo $batch['status'] === 'For Release' ? 'Released' : 'For Release'; ?>"
                                                        <?php endif; ?>
                                                    <?php endif; ?>>
                                                    <i class="bi <?php echo $batch['status'] === 'For Release' ? 'bi-check2-circle' : 'bi-box-arrow-up-right'; ?> me-1" aria-hidden="true"></i>
                                                    <?php echo $batch['status'] === 'For Release' ? 'Mark released' : 'For release'; ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-primary batch-action-button edit-batch" type="button" title="Edit batch" aria-label="Edit <?php echo sanitize($batch['batch_code']); ?>" data-bs-toggle="modal" data-bs-target="#create-batch"
                                            data-batch-id="<?php echo (int)$batch['batch_id']; ?>"
                                            data-document-type="<?php echo sanitize($batch['document_type']); ?>"
                                            data-batch-type="<?php echo sanitize($batch['batch_type']); ?>"
                                            data-batch-status="<?php echo sanitize($batch['status']); ?>"
                                            data-is-priority="<?php echo (int)$batch['is_priority']; ?>"
                                            data-batch-date="<?php echo sanitize($batch['batch_date']); ?>"
                                            data-sender-type="<?php echo sanitize($batch['sender_type']); ?>"
                                            data-submitter-name="<?php echo sanitize($batch['submitter_name'] ?? ''); ?>"
                                            data-recipient-type="<?php echo sanitize($batch['recipient_contact_type']); ?>"
                                            data-recipient="<?php echo sanitize($batch['recipient']); ?>"
                                            data-paper-count="<?php echo (int)$batch['paper_count']; ?>"
                                            data-remarks="<?php echo sanitize($batch['remarks'] ?? ''); ?>"
                                            data-batch-code="<?php echo sanitize($batch['batch_code']); ?>"
                                            data-received-at="<?php echo sanitize((string)$batch['received_at']); ?>"
                                            data-document-pages="<?php echo sanitize((string)($batch['document_pages'] ?? '')); ?>">
                                            <i class="bi bi-pencil" aria-hidden="true"></i>                                            <span class="visually-hidden">Edit bulk submission</span>
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary batch-action-button" type="button" title="View documents" aria-label="View documents in <?php echo sanitize($batch['batch_code']); ?>" data-bs-toggle="collapse" data-bs-target="#mobile-papers<?php echo (int)$batch['batch_id']; ?>" aria-expanded="false" aria-controls="mobile-papers<?php echo (int)$batch['batch_id']; ?>">
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                        </button>
                                        <?php if ($batch['received_at'] === null): ?>
                                            <form method="POST" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                                                <input type="hidden" name="batch_id" value="<?php echo (int)$batch['batch_id']; ?>">
                                                                                            <button class="btn btn-sm btn-outline-danger batch-action-button" type="submit" name="delete_batch" title="Delete bulk submission" aria-label="Delete <?php echo sanitize($batch['batch_code']); ?>" data-confirm-title="Delete this bulk submission?" data-confirm-message="This permanently deletes the bulk submission and all of its associated documents." data-confirm-button="Delete bulk submission">
                                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                    <div class="collapse mt-3" id="mobile-papers<?php echo (int)$batch['batch_id']; ?>">
                                        <div class="rounded-3 bg-light p-3">
                                            <?php if (trim((string)$batch['remarks']) !== ''): ?>
                                                <p class="small mb-3"><strong>Remarks:</strong><br><?php echo nl2br(sanitize($batch['remarks'])); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($papersByBatch[$batch['batch_id']])): ?>
                                                <ol class="list-group list-group-numbered">
                                                    <?php foreach ($papersByBatch[$batch['batch_id']] as $paper): ?>
                                                        <li class="list-group-item d-flex flex-column align-items-start gap-2">
                                                            <span class="fw-semibold text-break"><?php echo sanitize($paper['subject']); ?></span>
                                                            <?php if (trim((string)$paper['remarks']) !== ''): ?>
                                                                <span class="small text-muted text-break"><?php echo nl2br(sanitize($paper['remarks'])); ?></span>
                                                            <?php endif; ?>
                                                            <div class="d-flex flex-wrap gap-2 mt-1">
                                                                <button class="btn btn-sm btn-outline-primary edit-batch-paper" type="button" data-bs-toggle="modal" data-bs-target="#editBatchPaperModal" data-paper-id="<?php echo (int)$paper['paper_id']; ?>" data-paper-subject="<?php echo sanitize($paper['subject']); ?>" data-paper-remarks="<?php echo sanitize($paper['remarks']); ?>">
                                                                    <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit
                                                                </button>
                                                                <?php if ($batch['received_at'] === null): ?>
                                                                    <form method="POST">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                                        <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                                                                        <input type="hidden" name="paper_id" value="<?php echo (int)$paper['paper_id']; ?>">
                                                                                                                                    <button class="btn btn-sm btn-outline-danger" type="submit" name="delete_batch_paper" data-confirm-title="Remove this document?" data-confirm-message="This removes the document and its linked record from the bulk submission." data-confirm-button="Remove document">
                                                                            <i class="bi bi-trash me-1" aria-hidden="true"></i>Remove
                                                                        </button>
                                                                    </form>
                                                                <?php endif; ?>
                                                            </div>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ol>
                                            <?php else: ?>
                                                <p class="small text-muted mb-0">No documents are recorded in this bulk document entry.</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="editBatchPaperModal" tabindex="-1" aria-labelledby="editBatchPaperTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title text-primary"                         id="editBatchPaperTitle">Edit Document in Bulk Documents</h5>
                                                <p class="small text-muted mb-0 mt-1">Update the document title and its notes.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                    <input type="hidden" id="editBatchPaperId" name="paper_id">
                    <div class="mb-3">
                        <label class="form-label" for="editBatchPaperSubject">Document title</label>
                        <input class="form-control" type="text" id="editBatchPaperSubject" name="paper_subject" maxlength="255" required>
                    </div>
                    <div>
                        <label class="form-label" for="editBatchPaperRemarks"><?php echo sanitize($mobileFormLabels['remarks']); ?></label>
                        <textarea class="form-control" id="editBatchPaperRemarks" name="paper_remarks" rows="4"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" name="save_batch_paper"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="batchQrModal" tabindex="-1" aria-labelledby="batchQrTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered qr-code-modal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title text-primary" id="batchQrTitle">Bulk submission tracking QR</h5>
                    <p class="mb-0 mt-1 small text-muted" id="batchQrCode"></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="qr-code-frame">
                    <canvas id="batchQrCanvas" aria-label="QR code for tracking this bulk submission"></canvas>
                </div>
                <p class="small text-muted mt-3 mb-2" id="batchQrHelp">Choose whether this QR identifies the local bulk submission or opens its tracking page.</p>
                <a id="batchTrackingLink" href="#" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm tracking-link-button"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Open tracking page</a>
                <p id="batchQrError" class="small text-danger mt-3 mb-0" role="alert" hidden></p>
            </div>
            <div class="modal-footer">
                <a id="downloadBatchQr" class="btn btn-primary disabled" aria-disabled="true" download="batch-tracking.png"><i class="bi bi-download me-1" aria-hidden="true"></i>Download QR</a>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="confirmBatchReleaseModal" tabindex="-1" aria-labelledby="confirmBatchReleaseTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="confirmBatchReleaseForm">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title text-primary" id="confirmBatchReleaseTitle">Confirm receipt and move to For Release</h5>
                        <p class="small text-muted mb-0 mt-1" id="confirmBatchReleaseSummary"></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                    <input type="hidden" name="status_batch_id" id="confirmBatchReleaseId">
                    <label class="form-label fw-semibold" for="statusReceivedCount">Documents physically received</label>
                    <input class="form-control mb-3" type="number" name="status_received_count" id="statusReceivedCount" min="0" max="9999" inputmode="numeric" required>
                    <label class="form-label fw-semibold" for="statusIntakeNotes">Count difference / condition note</label>
                    <textarea class="form-control" name="status_intake_notes" id="statusIntakeNotes" rows="3" maxlength="1000" placeholder="Required if the received count differs"></textarea>
                    <p class="form-text mb-0">The receipt and status change are saved together. If the count differs, the note appears on the submitter's receipt.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" name="set_batch_status" value="1">Confirm receipt and move to For Release</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="<?php echo $isEmbeddedBatchAction && $embeddedBatchAction === 'scanner' ? 'embedded-batch-tool embedded-scanner-tool' : 'modal fade'; ?>" id="batchScannerModal" tabindex="-1" aria-labelledby="batchScannerTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title text-primary"                     id="batchScannerTitle">CEIT QR Scanner</h5>
                                        <p class="mb-0 mt-1 small text-muted">Only valid QR codes issued by this tracking system will be accepted.</p>
                </div>
                <?php if (!$isEmbeddedBatchAction): ?><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button><?php endif; ?>
            </div>
            <div class="modal-body">
                <input type="hidden" id="batchScannerCsrf" value="<?php echo sanitize(csrf_token()); ?>">
                <input type="hidden" id="batchScannerSessionToken" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                <div class="ratio ratio-4x3 bg-dark rounded overflow-hidden">
                <video id="batchScannerVideo" class="w-100 h-100" style="object-fit: cover;" autoplay muted playsinline aria-label="Camera view for scanning a CEIT document or bulk submission QR"></video>
                </div>
                <p id="batchScannerStatus" class="small mt-3 mb-2" role="status" aria-live="polite">Scan a CEIT document QR to open its tracker, or a bulk submission QR to look up its record. Scanning alone never confirms receipt.</p>
                <div class="border rounded p-3 mb-3">
                    <label for="batchQrUpload" class="form-label fw-semibold">Or upload a QR image</label>
                    <input type="file" id="batchQrUpload" class="form-control" accept="image/png,image/jpeg,image/webp,image/gif">
                    <div class="form-text">The image is checked in your browser and is not uploaded to the server.</div>
                </div>
                <div id="batchScannerResult" class="alert alert-success mb-0" hidden></div>
            </div>
            <div class="modal-footer">
                <button type="button" id="startBatchScanner" class="btn btn-primary"><i class="bi bi-camera-video me-1" aria-hidden="true"></i>Start scanner</button>
                <button type="button" id="stopBatchScanner" class="btn btn-outline-secondary" disabled>Stop camera</button>
                <?php if (!$isEmbeddedBatchAction): ?><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/qr-tools.bundle.js"></script>
<script>

const batchIntakeConfirmationMode = <?php echo json_encode($batchWorkflowSettings['intake_confirmation_mode']); ?>;

function showBatchQr(token, batchCode) {
    const trackingUrl = new URL('batch_tracker', window.location.href);
    trackingUrl.search = new URLSearchParams({ token }).toString();
    const qrPayload = trackingUrl.href;
    const canvas = document.getElementById('batchQrCanvas');
    const error = document.getElementById('batchQrError');
    const modal = document.getElementById('batchQrModal');
    const download = document.getElementById('downloadBatchQr');
    const trackingLink = document.getElementById('batchTrackingLink');
    document.getElementById('batchQrCode').textContent = batchCode;
    trackingLink.href = trackingUrl.href;
    trackingLink.hidden = false;
    document.getElementById('batchQrTitle').textContent = 'Batch tracking QR';
    document.getElementById('batchQrCanvas').setAttribute(
        'aria-label',
        'Public bulk submission tracking QR code'
    );
    document.getElementById('batchQrHelp').textContent = 'Scan this link with a standard QR scanner to open the tracking page. The device must be able to reach this server.';
    download.classList.add('disabled');
    download.setAttribute('aria-disabled', 'true');
    download.removeAttribute('href');
    download.download = batchCode.replace(/[^a-z0-9_-]/gi, '_') + '-batch-qr.png';
    error.hidden = true;

    if (!window.QRCode || typeof window.QRCode.toCanvas !== 'function') {
        error.textContent = 'The QR generator could not be loaded. Refresh the page and try again.';
        error.hidden = false;
    } else {
        window.QRCode.toCanvas(canvas, qrPayload, { width: 480, margin: 2, errorCorrectionLevel: 'H', color: { dark: '#064e3b', light: '#ffffff' } }, function (generationError) {
            if (generationError) {
                error.textContent = 'Could not generate the tracking QR code.';
                error.hidden = false;
                return;
            }
            download.href = canvas.toDataURL('image/png');
            download.classList.remove('disabled');
            download.setAttribute('aria-disabled', 'false');
        });
    }
    bootstrap.Modal.getOrCreateInstance(modal).show();
}

document.addEventListener('DOMContentLoaded', function () {
    const releaseModal = document.getElementById('confirmBatchReleaseModal');
    const releaseForm = document.getElementById('confirmBatchReleaseForm');
    const releaseId = document.getElementById('confirmBatchReleaseId');
    const releaseCount = document.getElementById('statusReceivedCount');
    const releaseNotes = document.getElementById('statusIntakeNotes');
    releaseModal.addEventListener('show.bs.modal', function (event) {
        const trigger = event.relatedTarget;
        if (!trigger) return;
        releaseId.value = trigger.dataset.batchId || '';
        releaseCount.value = trigger.dataset.paperCount || '';
        releaseCount.dataset.expectedCount = trigger.dataset.paperCount || '';
        releaseNotes.value = '';
        document.getElementById('confirmBatchReleaseSummary').textContent =
            `${trigger.dataset.batchCode || 'Batch'} · ${trigger.dataset.paperCount || '0'} papers expected`;
        releaseNotes.setCustomValidity('');
    });
    releaseForm.addEventListener('submit', function (event) {
        const mismatch = Number(releaseCount.value) !== Number(releaseCount.dataset.expectedCount);
        if (mismatch && !releaseNotes.value.trim()) {
            event.preventDefault();
            releaseNotes.setCustomValidity('Add a note explaining the count difference.');
            releaseNotes.reportValidity();
            return;
        }
        releaseNotes.setCustomValidity('');
    });
    releaseNotes.addEventListener('input', function () { releaseNotes.setCustomValidity(''); });

    const batchModal = document.getElementById('create-batch');
    const batchForm = batchModal.querySelector('form');
    const isEmbeddedBatchAction = document.body.classList.contains('admin-embedded-body');
    const batchIdInput = document.getElementById('editBatchId');
    const batchTypeInput = document.getElementById('batchType');
    const batchSubmitterNameInput = document.getElementById('batchSubmitterName');
    const batchPriorityInput = document.getElementById('batchPriority');
    const batchPaperCountInput = document.getElementById('batchPaperCount');
    const batchPaperCountLockedHelp = document.getElementById('batchPaperCountLockedHelp');
    const title = document.getElementById('createBatchTitle');
    const saveButton = document.getElementById('saveBatchButton');
    const pagesContainer = document.getElementById('batchDocumentPages');
    const maxPageInputs = 3;

    function readPages() {
        return Array.from(pagesContainer.querySelectorAll('input')).map((input) => input.value);
    }

    function renderPages(values) {
        const count = Math.min(Math.max(parseInt(batchPaperCountInput.value, 10) || 0, 0), maxPageInputs);
        pagesContainer.innerHTML = '';
        for (let i = 0; i < count; i++) {
            const col = document.createElement('div');
            col.className = 'col-6 col-md-4';
            const label = document.createElement('label');
            label.className = 'form-label small mb-1';
            label.textContent = 'Document ' + (i + 1);
            const input = document.createElement('input');
            input.type = 'number';
            input.className = 'form-control';
            input.name = 'document_pages[' + i + ']';
            input.min = '1';
            input.max = '9999';
            input.inputMode = 'numeric';
            input.placeholder = 'Pages';
            input.setAttribute('aria-label', 'Pages in document ' + (i + 1));
            input.value = values[i] == null ? '' : values[i];
            col.append(label, input);
            pagesContainer.appendChild(col);
        }
    }

    function parsePages(json) {
        try {
            const parsed = JSON.parse(json || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    batchPaperCountInput.addEventListener('input', function () {
        renderPages(readPages());
    });

    batchModal.addEventListener('show.bs.modal', function (event) {
        const trigger = event.relatedTarget;
        if (trigger && trigger.classList.contains('edit-batch')) {
            batchIdInput.value = trigger.dataset.batchId || '';
            document.getElementById('documentType').value = trigger.dataset.documentType || 'Incoming';
            batchTypeInput.value = trigger.dataset.batchType || '';
            batchSubmitterNameInput.value = trigger.dataset.submitterName || '';
            batchPriorityInput.checked = trigger.dataset.isPriority === '1';
            document.getElementById('batchDate').value = trigger.dataset.batchDate || '';
            document.getElementById('batchDate').removeAttribute('min');
            document.getElementById('batchDate').disabled = true;
            document.getElementById('immutableBatchDate').name = 'batch_date';
            document.getElementById('immutableBatchDate').value = trigger.dataset.batchDate || '';
            document.getElementById('immutableBatchDate').disabled = false;
            document.getElementById('batchDateLockedHelp').hidden = false;
            document.getElementById('batchSenderType').value = trigger.dataset.senderType || 'Student';
            document.getElementById('batchPaperCount').value = trigger.dataset.paperCount || '1';
            renderPages(parsePages(trigger.dataset.documentPages));
            batchPaperCountInput.readOnly = Boolean(trigger.dataset.receivedAt);
            batchPaperCountLockedHelp.hidden = !batchPaperCountInput.readOnly;
            document.getElementById('batchRemarks').value = trigger.dataset.remarks || '';
            title.textContent = 'Edit Bulk Documents';
            saveButton.innerHTML = '<i class="bi bi-check-lg me-1" aria-hidden="true"></i>Save Changes';
        } else if (batchModal.dataset.preserveValues === 'true') {
            batchIdInput.value = batchModal.dataset.postedBatchId || '';
            renderPages(parsePages(pagesContainer.dataset.initial));
            const editing = batchIdInput.value !== '' && batchIdInput.value !== '0';
            document.getElementById('batchDate').disabled = true;
            document.getElementById('immutableBatchDate').disabled = !editing;
            document.getElementById('immutableBatchDate').name = editing ? 'batch_date' : '';
            document.getElementById('immutableBatchDate').value = document.getElementById('batchDate').value;
            document.getElementById('batchDateLockedHelp').hidden = !editing;
            title.textContent = editing ? 'Edit Bulk Documents' : 'Add Bulk Documents';
            saveButton.innerHTML = editing
                ? '<i class="bi bi-check-lg me-1" aria-hidden="true"></i>Save Changes'
                : '<i class="bi bi-archive me-1" aria-hidden="true"></i>Save Bulk Documents';
        } else {
            batchForm.reset();
            batchPaperCountInput.readOnly = false;
            batchPaperCountLockedHelp.hidden = true;
            batchIdInput.value = '';
            document.getElementById('batchDate').disabled = true;
            document.getElementById('immutableBatchDate').disabled = true;
            document.getElementById('immutableBatchDate').removeAttribute('name');
            document.getElementById('batchDateLockedHelp').hidden = true;
            document.getElementById('documentType').value = 'Incoming';
            batchTypeInput.value = '';
            batchSubmitterNameInput.value = '';
            batchPriorityInput.checked = false;
            document.getElementById('batchDate').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('batchDate').min = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('batchSenderType').value = 'Student';
            document.getElementById('batchPaperCount').value = '1';
            renderPages([]);
            title.textContent = 'Add Bulk Documents';
            saveButton.innerHTML = '<i class="bi bi-archive me-1" aria-hidden="true"></i>Save Bulk Documents';
        }
    });
    batchModal.addEventListener('hidden.bs.modal', function () {
        batchIdInput.value = '';
        title.textContent = 'Add Bulk Documents';
        saveButton.innerHTML = '<i class="bi bi-archive me-1" aria-hidden="true"></i>Save Bulk Documents';
    });

    if (!isEmbeddedBatchAction && (batchModal.dataset.openOnLoad === 'true' || window.location.hash === '#create-batch')) {
        new bootstrap.Modal(batchModal).show();
    }

    const scannerModal = document.getElementById('batchScannerModal');
    const video = document.getElementById('batchScannerVideo');
    const status = document.getElementById('batchScannerStatus');
    const resultPanel = document.getElementById('batchScannerResult');
    const startButton = document.getElementById('startBatchScanner');
    const stopButton = document.getElementById('stopBatchScanner');
    const uploadInput = document.getElementById('batchQrUpload');
    const scanCanvas = document.createElement('canvas');
    const scanContext = scanCanvas.getContext('2d', { willReadFrequently: true });
    document.querySelectorAll('.edit-batch-paper').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('editBatchPaperId').value = button.dataset.paperId || '';
            document.getElementById('editBatchPaperSubject').value = button.dataset.paperSubject || '';
            document.getElementById('editBatchPaperRemarks').value = button.dataset.paperRemarks || '';
        });
    });
    let cameraStream = null;
    let scanFrame = 0;
    let scanning = false;
    let verifying = false;
    let lastRejectedValue = '';
    let lastScanAt = 0;

    function stopScanner() {
        scanning = false;
        if (scanFrame) {
            cancelAnimationFrame(scanFrame);
            scanFrame = 0;
        }
        if (cameraStream) {
            cameraStream.getTracks().forEach(function (track) { track.stop(); });
            cameraStream = null;
        }
        video.srcObject = null;
        startButton.disabled = false;
        stopButton.disabled = true;
    }

    function showScannerError(message) {
        status.textContent = message;
        status.className = 'small mt-3 mb-2 text-danger';
    }

    async function readScannerResponse(response) {
        try {
            return await response.json();
        } catch (error) {
            throw new Error('The office server returned an unreadable response.');
        }
    }

    async function verifyDocumentTrackingToken(token) {
        const projectBaseUrl = new URL('.', window.location.href);
        const verificationUrl = new URL('mobile-api.php', projectBaseUrl);
        verificationUrl.search = new URLSearchParams({
            action: 'document_tracker_status',
            token: token
        }).toString();
        const response = await fetch(verificationUrl, { cache: 'no-store', credentials: 'same-origin' });
        const data = await readScannerResponse(response);
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'This document QR code could not be verified by the office.');
        }

        const trackerUrl = new URL('document-tracker', projectBaseUrl);
        trackerUrl.search = new URLSearchParams({ token: token }).toString();
        stopScanner();
        status.textContent = 'Verified CEIT document QR code. Opening its tracker…';
        status.className = 'small mt-3 mb-2 text-success';
        window.location.assign(trackerUrl.href);
    }

    function showBatchResult(batch) {
        const heading = document.createElement('strong');
        heading.textContent = batch.code + ' · ' + batch.type + ' · ' + batch.date;
        const expected = document.createElement('p');
        expected.className = 'mb-2 mt-2';
        expected.textContent = 'Expected: ' + batch.paperCount + (batch.paperCount === 1 ? ' document' : ' documents') +
            ' · Status: ' + batch.status + (batch.isPriority ? ' · Priority' : '');
        resultPanel.replaceChildren(heading, expected);

        if (batch.receivedPaperCount !== null && batch.receivedPaperCount !== undefined) {
            const receipt = document.createElement('p');
            receipt.className = 'mb-2';
            receipt.textContent = 'Receipt already confirmed: ' + batch.receivedPaperCount + ' received of ' +
                batch.intakeExpectedCount + ' expected at ' + batch.receivedAt +
                (batch.receivedBy ? ' by ' + batch.receivedBy : '') + '.';
            resultPanel.appendChild(receipt);
            if (batch.receivedPaperCount !== batch.intakeExpectedCount) {
                const countDifference = batch.receivedPaperCount - batch.intakeExpectedCount;
                const discrepancy = document.createElement('p');
                discrepancy.className = 'mb-2 fw-semibold';
                discrepancy.textContent = 'Count difference: ' + Math.abs(countDifference) +
                    (countDifference < 0 ? ' fewer' : ' more') + ' than expected.';
                resultPanel.appendChild(discrepancy);
            }
            if (batch.intakeNotes) {
                const note = document.createElement('p');
                note.className = 'mb-2';
                note.textContent = 'Receipt note: ' + batch.intakeNotes;
                resultPanel.appendChild(note);
            }
        } else if (batchIntakeConfirmationMode === 'confirm_before_release') {
            const intakeForm = document.createElement('form');
            intakeForm.className = 'mt-3 border-top pt-3';
            const countLabel = document.createElement('label');
            countLabel.className = 'form-label fw-semibold';
            countLabel.htmlFor = 'batchReceivedCount';
            countLabel.textContent = 'Count physically received';
            const countInput = document.createElement('input');
            countInput.id = 'batchReceivedCount';
            countInput.name = 'received_paper_count';
            countInput.type = 'number';
            countInput.className = 'form-control mb-3';
            countInput.min = '0';
            countInput.max = '9999';
            countInput.inputMode = 'numeric';
            countInput.required = true;
            countInput.value = String(batch.paperCount);
            const noteLabel = document.createElement('label');
            noteLabel.className = 'form-label fw-semibold';
            noteLabel.htmlFor = 'batchIntakeNotes';
            noteLabel.textContent = 'Count difference / condition note';
            const notesInput = document.createElement('textarea');
            notesInput.id = 'batchIntakeNotes';
            notesInput.name = 'intake_notes';
            notesInput.className = 'form-control mb-2';
            notesInput.maxLength = 1000;
            notesInput.rows = 2;
            notesInput.placeholder = 'Shown on the submitter receipt; required if the count differs';
            notesInput.addEventListener('input', function () { notesInput.setCustomValidity(''); });
            const help = document.createElement('p');
            help.className = 'form-text';
            help.textContent = 'Confirm only after counting. The submitter can see this note on their receipt; a note is required if the count differs.';
            const confirmButton = document.createElement('button');
            confirmButton.type = 'submit';
            confirmButton.className = 'btn btn-primary';
            confirmButton.textContent = 'Confirm receipt and issue receipt';
            intakeForm.append(countLabel, countInput, noteLabel, notesInput, help, confirmButton);
            intakeForm.addEventListener('submit', async function (event) {
                event.preventDefault();
                if (confirmButton.disabled) return;
                if (Number(countInput.value) !== Number(batch.paperCount) && !notesInput.value.trim()) {
                    notesInput.setCustomValidity('Add a note explaining the count difference.');
                    notesInput.reportValidity();
                    return;
                }
                notesInput.setCustomValidity('');
                confirmButton.disabled = true;
                confirmButton.textContent = 'Saving receipt…';
                const formData = new FormData();
                formData.append('csrf_token', document.getElementById('batchScannerCsrf').value);
                formData.append('token', document.getElementById('batchScannerSessionToken').value);
                formData.append('confirm_batch_intake', '1');
                formData.append('tracking_token', batch.trackingToken);
                formData.append('received_paper_count', countInput.value);
                formData.append('intake_notes', notesInput.value.trim());
                try {
                    const response = await fetch(window.location.pathname, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        throw new Error(data.message || 'The receipt could not be saved.');
                    }
                    showBatchResult({ ...data.batch, trackingToken: batch.trackingToken });
                    status.textContent = 'Receipt saved in the office records.';
                    status.className = 'small mt-3 mb-2 text-success';
                } catch (error) {
                    confirmButton.disabled = false;
                    confirmButton.textContent = 'Confirm receipt and issue receipt';
                    showScannerError(error instanceof Error ? error.message : 'The receipt could not be saved.');
                }
            });
            resultPanel.appendChild(intakeForm);
        } else {
            const message = document.createElement('p');
            message.className = 'mt-3 mb-0 text-muted';
            message.textContent = 'QR scan only looks up this bulk submission. To verify the count, use its For Release action in the history list.';
            resultPanel.appendChild(message);
        }
        if (batch.trackingToken) {
            const receiptLink = document.createElement('a');
            const trackingUrl = new URL('batch_tracker', window.location.href);
            trackingUrl.search = new URLSearchParams({ token: batch.trackingToken }).toString();
            receiptLink.href = trackingUrl.href;
            receiptLink.target = '_blank';
            receiptLink.rel = 'noopener noreferrer';
            receiptLink.className = batch.receivedPaperCount !== null && batch.receivedPaperCount !== undefined
                ? 'btn btn-success mt-2'
                : 'btn btn-outline-primary btn-sm tracking-link-button mt-2';
            receiptLink.textContent = batch.receivedPaperCount !== null && batch.receivedPaperCount !== undefined
                ? 'Open / print receipt'
                : 'Open tracking page';
            resultPanel.appendChild(receiptLink);
        }
        resultPanel.hidden = false;
    }

    async function validateSystemQr(value) {
        let token = '';
        let batchCode = '';
        let trackerType = '';
        value = value.trim();
        const localBatchMatch = value.match(/^CEIT-BATCH:(.+)$/i);
        if (localBatchMatch) {
            batchCode = localBatchMatch[1].trim().toUpperCase();
            if (!/^[A-Z0-9-]{1,32}$/.test(batchCode)) {
                showScannerError('Not a CEIT local bulk submission QR. Keep scanning for a valid system code.');
                return false;
            }
        } else {
            let scannedUrl;
            try {
                scannedUrl = new URL(value);
            } catch {
                showScannerError('Not a CEIT bulk submission QR. Keep scanning for a valid system code.');
                return false;
            }

            const routeName = scannedUrl.pathname.split('/').filter(Boolean).pop()?.replace(/\.php$/i, '').toLowerCase();
            token = scannedUrl.searchParams.get('token') || '';
            if (routeName === 'batch_tracker') {
                trackerType = 'batch';
            } else if (routeName === 'document-tracker' || routeName === 'document_tracker') {
                trackerType = 'document';
            }
            if (!['http:', 'https:'].includes(scannedUrl.protocol) ||
                scannedUrl.username || scannedUrl.password ||
                trackerType === '' ||
                scannedUrl.hash !== '' || [...scannedUrl.searchParams.keys()].length !== 1 ||
                !/^[a-f0-9]{64}$/.test(token)) {
                showScannerError('This is not a valid CEIT document or bulk submission tracking QR code.');
                return false;
            }
        }

        if (trackerType === 'document') {
            try {
                await verifyDocumentTrackingToken(token);
                return true;
            } catch (error) {
                showScannerError(error instanceof Error ? error.message : 'This document QR code could not be verified.');
                return false;
            }
        }

        const formData = new FormData();
        formData.append('csrf_token', document.getElementById('batchScannerCsrf').value);
        formData.append('token', document.getElementById('batchScannerSessionToken').value);
        formData.append('scan_batch_qr', '1');
        formData.append('tracking_token', token);
        formData.append('batch_code', batchCode);
        try {
            const response = await fetch(window.location.pathname, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await readScannerResponse(response);
            if (!response.ok || !data.success) {
                showScannerError(data.message || 'This QR code is not registered in the CEIT tracking system.');
                return false;
            }
            stopScanner();
            status.textContent = 'Verified CEIT QR code.';
            status.className = 'small mt-3 mb-2 text-success';
            showBatchResult({ ...data.batch, trackingToken: data.batch.trackingToken || token });
            return true;
        } catch (error) {
            showScannerError(error instanceof Error ? error.message : 'The QR code could not be verified. Check the connection and scan again.');
            return false;
        }
    }

    uploadInput.addEventListener('change', async function () {
        const file = uploadInput.files && uploadInput.files[0];
        uploadInput.value = '';
        if (!file) return;
        resultPanel.hidden = true;
        resultPanel.replaceChildren();
        if (!/^image\/(png|jpeg|webp|gif)$/.test(file.type)) {
            showScannerError('Choose a PNG, JPG, WEBP, or GIF image.');
            return;
        }
        if (file.size > 10 * 1024 * 1024) {
            showScannerError('The QR image must be 10 MB or smaller.');
            return;
        }
        if (typeof window.jsQR !== 'function' || typeof window.createImageBitmap !== 'function') {
            showScannerError('Image QR scanning is not supported by this browser.');
            return;
        }

        status.textContent = 'Reading the uploaded QR image…';
        status.className = 'small mt-3 mb-2 text-muted';
        let imageBitmap;
        try {
            imageBitmap = await window.createImageBitmap(file);
            const scale = Math.min(1, 1600 / Math.max(imageBitmap.width, imageBitmap.height));
            scanCanvas.width = Math.max(1, Math.round(imageBitmap.width * scale));
            scanCanvas.height = Math.max(1, Math.round(imageBitmap.height * scale));
            if (!scanContext) throw new Error('This browser could not prepare the QR image.');
            scanContext.drawImage(imageBitmap, 0, 0, scanCanvas.width, scanCanvas.height);
            const image = scanContext.getImageData(0, 0, scanCanvas.width, scanCanvas.height);
            const code = window.jsQR(image.data, image.width, image.height, { inversionAttempts: 'attemptBoth' });
            if (!code) {
                showScannerError('No readable QR code was found in that image.');
                return;
            }
            await validateSystemQr(code.data);
        } catch (error) {
            showScannerError(error instanceof Error ? error.message : 'Could not read the uploaded image. Try another QR image.');
        } finally {
            if (imageBitmap) imageBitmap.close();
        }
    });

    function scanFrameLoop() {
        if (!scanning) return;
        if (!scanContext || video.readyState < HTMLMediaElement.HAVE_CURRENT_DATA || video.videoWidth === 0 || video.videoHeight === 0) {
            if (scanning) scanFrame = requestAnimationFrame(scanFrameLoop);
            return;
        }
        const now = performance.now();
        if (!verifying && now - lastScanAt >= 180) {
            lastScanAt = now;
            const scale = Math.min(1, 640 / video.videoWidth);
            scanCanvas.width = Math.round(video.videoWidth * scale);
            scanCanvas.height = Math.round(video.videoHeight * scale);
            scanContext.drawImage(video, 0, 0, scanCanvas.width, scanCanvas.height);
            const image = scanContext.getImageData(0, 0, scanCanvas.width, scanCanvas.height);
            const code = window.jsQR(image.data, image.width, image.height, { inversionAttempts: 'attemptBoth' });
            if (code && code.data !== lastRejectedValue) {
                lastRejectedValue = code.data;
                verifying = true;
                validateSystemQr(code.data).then(function (accepted) {
                    verifying = false;
                    if (accepted) return;
                    window.setTimeout(function () {
                        if (lastRejectedValue === code.data) lastRejectedValue = '';
                    }, 1200);
                }).catch(function () {
                    verifying = false;
                });
            }
        }
        scanFrame = requestAnimationFrame(scanFrameLoop);
    }

    startButton.addEventListener('click', async function () {
        resultPanel.hidden = true;
        resultPanel.replaceChildren();
        lastRejectedValue = '';
        verifying = false;
        lastScanAt = 0;
        if (!window.isSecureContext) {
            showScannerError('Camera access needs HTTPS or localhost. On a phone, localhost means the phone itself. Open this Admin page using a trusted HTTPS address, or upload a QR image below.');
            return;
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showScannerError('This browser does not support camera scanning. Try a current browser or upload a QR image below.');
            return;
        }
        if (typeof window.jsQR !== 'function') {
            showScannerError('The local QR scanner could not be loaded. Refresh the page and try again.');
            return;
        }
        startButton.disabled = true;
        status.textContent = 'Waiting for camera permission…';
        status.className = 'small mt-3 mb-2 text-muted';
        try {
            cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
            video.srcObject = cameraStream;
            await video.play();
            scanning = true;
            stopButton.disabled = false;
            status.textContent = 'Camera ready. Point it at a CEIT QR code.';
            scanFrame = requestAnimationFrame(scanFrameLoop);
        } catch (error) {
            stopScanner();
            if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') {
                showScannerError('Camera access was denied. Allow camera access for this site in the browser settings and try again.');
            } else if (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError') {
                showScannerError('No camera was found on this device. Connect a camera or upload a QR image instead.');
            } else if (error.name === 'NotReadableError' || error.name === 'TrackStartError') {
                showScannerError('The camera is already in use by another app. Close that app and try again.');
            } else {
                showScannerError('Could not start the camera. Check camera permission, HTTPS access, and that the camera is not in use.');
            }
        }
    });

    stopButton.addEventListener('click', function () {
        stopScanner();
        status.textContent = 'Camera stopped.';
        status.className = 'small mt-3 mb-2 text-muted';
    });
    scannerModal.addEventListener('hidden.bs.modal', stopScanner);
    if (isEmbeddedBatchAction) {
        window.addEventListener('pagehide', stopScanner);
    }

    if (!isEmbeddedBatchAction && new URLSearchParams(window.location.search).get('open') === 'scanner') {
        bootstrap.Modal.getOrCreateInstance(scannerModal).show();
    }
});
</script>
<?php include 'footer.php'; ?>
