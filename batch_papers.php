<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'batch_support.php';
ensure_admin();

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
$batchPriority = isset($_POST['is_priority']) ? 1 : 0;
$batchDate = $_POST['batch_date'] ?? date('Y-m-d');
$senderType = $_POST['sender_type'] ?? 'Student';
$recipientContactType = $_POST['recipient_contact_type'] ?? 'Unassigned';
$recipient = contact_number_input($_POST['recipient'] ?? '') ?? '';
$paperCount = (int)($_POST['paper_count'] ?? 1);
$batchRemarks = trim($_POST['remarks'] ?? '');
$search = trim($_GET['search'] ?? '');

if (!$setupError && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    if (!hash_equals($_SESSION['batch_papers_token'], $token)) {
        $message = show_alert('Your session token is invalid. Refresh the page and try again.', 'danger');
    } elseif (isset($_POST['scan_batch_qr'])) {
        header('Content-Type: application/json; charset=utf-8');
        $trackingToken = trim($_POST['tracking_token'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $trackingToken)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'This QR code is not a valid CEIT batch tracking code.']);
            exit;
        }

        $stmt = safe_query(
            $conn,
            'SELECT b.batch_id, b.batch_code, b.document_type, b.batch_type, b.status, b.batch_date, b.paper_count, b.is_priority
             FROM document_batches b
             WHERE b.tracking_token = ?
             LIMIT 1',
            's',
            [$trackingToken]
        );
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not verify this batch QR code.']);
            exit;
        }

        $result = mysqli_stmt_get_result($stmt);
        $batch = null;
        $row = mysqli_fetch_assoc($result);
        if ($row) {
            $batch = [
                'code' => $row['batch_code'],
                'type' => $row['batch_type'],
                'date' => $row['batch_date'],
                'paperCount' => (int)$row['paper_count'],
                'status' => $row['status'],
                'isPriority' => (int)$row['is_priority'],
            ];
        }
        mysqli_stmt_close($stmt);

        if ($batch === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'This QR code was not issued by the CEIT batch tracking system.']);
            exit;
        }
        echo json_encode(['success' => true, 'batch' => $batch]);
        exit;
    } elseif (isset($_POST['set_batch_status'])) {
        $batchId = (int)($_POST['status_batch_id'] ?? 0);
        if ($batchId <= 0) {
            $_SESSION['batch_flash'] = show_alert('Select a valid batch.', 'danger');
        } else {
            try {
                $conn->begin_transaction();
                $stmt = safe_query($conn, 'SELECT status FROM document_batches WHERE batch_id = ? FOR UPDATE', 'i', [$batchId]);
                if ($stmt === false) {
                    throw new RuntimeException('Could not verify the batch status.');
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
                    throw new RuntimeException('This batch has already been released.');
                }
                $stmt = safe_query($conn, 'UPDATE document_batches SET status = ? WHERE batch_id = ?', 'si', [$nextStatus, $batchId]);
                if ($stmt === false) {
                    throw new RuntimeException('Could not update batch status.');
                }
                mysqli_stmt_close($stmt);
                $stmt = safe_query(
                    $conn,
                    'UPDATE documents d INNER JOIN batch_papers p ON p.doc_id = d.doc_id SET d.status = ? WHERE p.batch_id = ?',
                    'si',
                    [$nextStatus, $batchId]
                );
                if ($stmt === false) {
                    throw new RuntimeException('Could not update linked paper statuses.');
                }
                mysqli_stmt_close($stmt);
                $conn->commit();
                $_SESSION['batch_flash'] = show_alert('Batch status updated to ' . $nextStatus . '.');
            } catch (Throwable $error) {
                $conn->rollback();
                error_log('Batch status update failed: ' . $error->getMessage());
                $_SESSION['batch_flash'] = show_alert('Batch status could not be updated.', 'danger');
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
                    throw new RuntimeException('Could not update batch priority.');
                }
                mysqli_stmt_close($stmt);
                $stmt = safe_query(
                    $conn,
                    'UPDATE documents d INNER JOIN batch_papers p ON p.doc_id = d.doc_id SET d.is_priority = ? WHERE p.batch_id = ?',
                    'ii',
                    [$newPriority, $batchId]
                );
                if ($stmt === false) {
                    throw new RuntimeException('Could not update linked faculty paper priority.');
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
            $message = show_alert('Enter a paper title of 1 to 255 characters.', 'danger');
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
                    throw new RuntimeException('Could not update the batch paper entry.');
                }
                mysqli_stmt_close($stmt);
                $conn->commit();
                $_SESSION['batch_flash'] = show_alert('Paper details updated.');
                header('Location: batch_papers' . (isset($_GET['search']) ? '?search=' . rawurlencode((string)$_GET['search']) : ''));
                exit;
            } catch (Throwable $error) {
                $conn->rollback();
                error_log('Batch paper update failed: ' . $error->getMessage());
                $message = show_alert('The paper details could not be updated.', 'danger');
            }
        }
    } elseif (isset($_POST['save_batch'])) {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $dateObject = DateTime::createFromFormat('!Y-m-d', $batchDate);
        $validDocumentType = in_array($documentType, ['Incoming', 'Outgoing'], true);
        $validSenderType = in_array($senderType, ['Student', 'Faculty'], true);
        $validRecipientType = in_array($recipientContactType, ['Email', 'Contact', 'Unassigned'], true);
        $validRecipient = $recipientContactType === 'Unassigned'
            ? true
            : ($recipient !== '' && (
                $recipientContactType === 'Email'
                    ? filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false
                    : is_valid_contact_number($recipient)
            ));
        if (!$validDocumentType || $batchType === '' || strlen($batchType) > 255 ||
            !$dateObject || $dateObject->format('Y-m-d') !== $batchDate ||
            !$validSenderType || !$validRecipientType || !$validRecipient || $paperCount < 1 || $paperCount > 9999) {
            $message = show_alert($recipientContactType === 'Contact' && $recipient !== '' && !is_valid_contact_number($recipient)
                ? 'Enter an 11-digit recipient contact number using digits only.'
                : 'Check the batch name, date, submitter, recipient details, and paper count (1–9,999).', 'danger');
            $showBatchModal = true;
        } elseif ($batchId > 0) {
            try {
                $conn->begin_transaction();
                $existsStmt = safe_query($conn, 'SELECT batch_id, status FROM document_batches WHERE batch_id = ? FOR UPDATE', 'i', [$batchId]);
                if ($existsStmt === false) {
                    throw new RuntimeException('Could not verify the batch.');
                }
                $existingBatch = mysqli_fetch_assoc(mysqli_stmt_get_result($existsStmt));
                mysqli_stmt_close($existsStmt);
                if (!$existingBatch) {
                    throw new RuntimeException('The batch was not found.');
                }
                $batchStatus = $existingBatch['status'];
                $recipientValue = $recipientContactType === 'Unassigned' ? '' : $recipient;
                $stmt = safe_query(
                    $conn,
                    'UPDATE document_batches SET document_type = ?, batch_type = ?, batch_date = ?, sender = ?, sender_type = ?, recipient = ?, recipient_contact_type = ?, remarks = ?, paper_count = ?, is_priority = ? WHERE batch_id = ?',
                    'ssssssssiii',
                    [$documentType, $batchType, $batchDate, $senderType, $senderType, $recipientValue, $recipientContactType, $batchRemarks, $paperCount, $batchPriority, $batchId]
                );
                if ($stmt === false || mysqli_stmt_affected_rows($stmt) < 0) {
                    if ($stmt !== false) mysqli_stmt_close($stmt);
                    throw new RuntimeException('Could not update batch details.');
                }
                mysqli_stmt_close($stmt);
                $legacyRecipient = $recipientValue === '' ? 'Unassigned' : $recipientValue;
                $legacyPhone = $recipientContactType === 'Contact' ? $recipientValue : null;
                // Keep legacy document fields synchronized because existing reports and screens query documents.
                $stmt = safe_query(
                    $conn,
                    'UPDATE documents d INNER JOIN batch_papers p ON p.doc_id = d.doc_id SET d.type = ?, d.date_created = ?, d.sender = ?, d.recipient = ?, d.client_phone = ?, d.submitter_type = ?, d.status = ?, d.is_priority = ? WHERE p.batch_id = ?',
                    'sssssssii',
                    [$documentType, $batchDate, $senderType, $legacyRecipient, $legacyPhone, $senderType, $batchStatus, $batchPriority, $batchId]
                );
                if ($stmt === false) {
                    throw new RuntimeException('Could not update linked paper records.');
                }
                mysqli_stmt_close($stmt);
                $conn->commit();
                $_SESSION['batch_flash'] = show_alert('Batch details updated.');
                header('Location: batch_papers' . ($search !== '' ? '?search=' . rawurlencode($search) : ''));
                exit;
            } catch (Throwable $error) {
                $conn->rollback();
                error_log('Batch update failed: ' . $error->getMessage());
                $message = show_alert('The batch could not be updated.', 'danger');
                $showBatchModal = true;
            }
        } else {
            try {
                // New batch metadata is committed as one operation so failed inserts leave no partial batch.
                $conn->begin_transaction();
                $batchCode = 'BP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $trackingToken = bin2hex(random_bytes(32));
                $recipientValue = $recipientContactType === 'Unassigned' ? '' : $recipient;
                $stmt = safe_query(
                    $conn,
                    'INSERT INTO document_batches (batch_code, document_type, batch_type, status, batch_date, sender, sender_type, recipient, recipient_contact_type, remarks, tracking_token, paper_count, is_priority) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    'sssssssssssii',
                    [$batchCode, $documentType, $batchType, 'Pending', $batchDate, $senderType, $senderType, $recipientValue, $recipientContactType, $batchRemarks, $trackingToken, $paperCount, $batchPriority]
                );
                if ($stmt === false) {
                    throw new RuntimeException('Could not create the batch.');
                }
                mysqli_stmt_close($stmt);
                $conn->commit();
                $_SESSION['batch_flash'] = show_alert('Batch ' . $batchCode . ' saved with ' . $paperCount . ' paper(s).');
                header('Location: batch_papers');
                exit;
            } catch (Throwable $error) {
                $conn->rollback();
                error_log('Batch papers save failed: ' . $error->getMessage());
                $message = show_alert('The batch could not be saved.', 'danger');
                $showBatchModal = true;
            }
        }
    } elseif (isset($_POST['delete_batch_paper'])) {
        $paperId = (int)($_POST['paper_id'] ?? 0);
        if ($paperId <= 0) {
            $message = show_alert('Select a valid paper entry.', 'danger');
        } else {
            try {
                $conn->begin_transaction();
                $batchStmt = safe_query($conn, 'SELECT batch_id FROM batch_papers WHERE paper_id = ? FOR UPDATE', 'i', [$paperId]);
                if ($batchStmt === false) {
                    throw new RuntimeException('Could not find the paper entry.');
                }
                $paperBatch = mysqli_fetch_assoc(mysqli_stmt_get_result($batchStmt));
                mysqli_stmt_close($batchStmt);
                if (!$paperBatch) {
                    throw new RuntimeException('The paper entry was not found.');
                }
                $stmt = safe_query(
                    $conn,
                    'DELETE d FROM documents d INNER JOIN batch_papers p ON p.doc_id = d.doc_id WHERE p.paper_id = ?',
                    'i',
                    [$paperId]
                );
                if ($stmt === false || mysqli_stmt_affected_rows($stmt) !== 1) {
                    if ($stmt !== false) mysqli_stmt_close($stmt);
                    throw new RuntimeException('The paper entry could not be deleted.');
                }
                mysqli_stmt_close($stmt);
                $countStmt = safe_query($conn, 'UPDATE document_batches SET paper_count = GREATEST(paper_count - 1, 0) WHERE batch_id = ?', 'i', [$paperBatch['batch_id']]);
                if ($countStmt === false) {
                    throw new RuntimeException('Could not update the batch paper count.');
                }
                mysqli_stmt_close($countStmt);
                $conn->commit();
                $_SESSION['batch_flash'] = show_alert('Paper removed from the batch.');
                header('Location: batch_papers' . (isset($_GET['search']) ? '?search=' . rawurlencode((string)$_GET['search']) : ''));
                exit;
            } catch (Throwable $error) {
                $conn->rollback();
                error_log('Batch paper deletion failed: ' . $error->getMessage());
                $message = show_alert('The paper could not be removed from the batch.', 'danger');
            }
        }
    } elseif (isset($_POST['delete_batch'])) {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        if ($batchId > 0) {
            try {
                $conn->begin_transaction();
                $stmt = safe_query(
                    $conn,
                    'DELETE d FROM documents d INNER JOIN batch_papers p ON p.doc_id = d.doc_id WHERE p.batch_id = ?',
                    'i',
                    [$batchId]
                );
                if ($stmt === false) {
                    throw new RuntimeException('Could not delete the batch documents.');
                }
                mysqli_stmt_close($stmt);
                $stmt = safe_query($conn, 'DELETE FROM document_batches WHERE batch_id = ?', 'i', [$batchId]);
                if ($stmt === false) {
                    throw new RuntimeException('Could not delete the batch.');
                }
                mysqli_stmt_close($stmt);
                $conn->commit();
                $_SESSION['batch_flash'] = show_alert('Batch and its paper entries were deleted.');
                header('Location: batch_papers');
                exit;
            } catch (Throwable $error) {
                $conn->rollback();
                error_log('Batch papers deletion failed: ' . $error->getMessage());
            }
        }
        $message = show_alert('Unable to delete the selected batch.', 'danger');
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
             WHERE b.batch_code LIKE ? OR b.document_type LIKE ? OR b.batch_type LIKE ? OR b.status LIKE ? OR b.sender LIKE ? OR b.recipient LIKE ? OR b.remarks LIKE ?
                OR EXISTS (SELECT 1 FROM batch_papers p WHERE p.batch_id = b.batch_id AND (p.subject LIKE ? OR p.remarks LIKE ?))
             ORDER BY b.is_priority DESC, b.batch_date DESC, b.batch_id DESC",
            'sssssssss',
            [$term, $term, $term, $term, $term, $term, $term, $term, $term]
        );
    } else {
        $stmt = safe_query(
            $conn,
            'SELECT b.* FROM document_batches b ORDER BY b.is_priority DESC, b.batch_date DESC, b.batch_id DESC'
        );
    }

    if ($stmt !== false) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            if (empty($row['tracking_token'])) {
                $row['tracking_token'] = bin2hex(random_bytes(32));
                $tokenStmt = safe_query($conn, 'UPDATE document_batches SET tracking_token = ? WHERE batch_id = ? AND tracking_token IS NULL', 'si', [$row['tracking_token'], $row['batch_id']]);
                if ($tokenStmt === false) {
                    throw new RuntimeException('Could not create the batch tracking link.');
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
                <h2 class="mb-1 text-primary">Bulk Papers</h2>
                <p class="text-muted mb-0">Register multiple incoming or outgoing papers in one submission.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#create-batch" <?php echo $setupError ? 'disabled' : ''; ?>>
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Bulk Papers
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
            <div class="alert alert-danger" role="alert">Batch storage could not be initialized. Check the database connection and permissions.</div>
        <?php endif; ?>
    </div>
    <div class="col-12">
        <div class="<?php echo $isEmbeddedBatchAction && $embeddedBatchAction === 'batch' ? 'embedded-batch-tool' : 'modal fade'; ?>" id="create-batch" tabindex="-1" aria-labelledby="createBatchTitle" aria-hidden="true" data-open-on-load="<?php echo $showBatchModal || ($_GET['add'] ?? '') === '1' || ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['open'] ?? '') === 'batch') ? 'true' : 'false'; ?>" data-preserve-values="<?php echo $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_batch']) ? 'true' : 'false'; ?>" data-posted-batch-id="<?php echo (int)($_POST['batch_id'] ?? 0); ?>">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title text-primary" id="createBatchTitle">Create Bulk Papers</h5>
                            <p class="mb-0 mt-1 small text-muted">Record bulk paper details and the total number of papers.</p>
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
                        <label class="form-label" for="batchType">Batch type / document name</label>
                        <input class="form-control" type="text" id="batchType" name="batch_type" maxlength="255" value="<?php echo sanitize($batchType); ?>" placeholder="e.g. Transcript request documents" required>
                    </div>
                    <div class="mb-3">
                        <input type="hidden" id="documentType" name="document_type" value="<?php echo sanitize($documentType); ?>">
                    </div>
                    </div>
                    <div class="col-12 col-md-6">
                    <div class="mb-3">
                        <label class="form-label" for="batchDate">Batch date</label>
                        <input class="form-control" type="date" id="batchDate" name="batch_date" value="<?php echo sanitize($batchDate); ?>" required>
                    </div>
                    </div>
                    <div class="col-12 col-md-6">
                    <div class="mb-3">
                        <label class="form-label" for="batchSenderType">From</label>
                        <select class="form-select" id="batchSenderType" name="sender_type" required>
                            <option value="Student" <?php echo $senderType === 'Student' ? 'selected' : ''; ?>>Student</option>
                            <option value="Faculty" <?php echo $senderType === 'Faculty' ? 'selected' : ''; ?>>Faculty</option>
                        </select>
                    </div>
                    </div>
                    <div class="col-12 col-md-6">
                    <div class="mb-3">
                        <label class="form-label" for="batchRecipientType">Recipient contact type</label>
                        <select class="form-select" id="batchRecipientType" name="recipient_contact_type" required>
                            <option value="Email" <?php echo $recipientContactType === 'Email' ? 'selected' : ''; ?>>Email</option>
                            <option value="Contact" <?php echo $recipientContactType === 'Contact' ? 'selected' : ''; ?>>Contact</option>
                            <option value="Unassigned" <?php echo $recipientContactType === 'Unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                        </select>
                    </div>
                    </div>
                    <div class="col-12 col-md-6">
                    <div class="mb-3">
                        <label class="form-label" for="batchRecipient">Recipient</label>
                        <input class="form-control" type="text" id="batchRecipient" name="recipient" maxlength="255" value="<?php echo sanitize($recipient); ?>" placeholder="Select email or contact type" readonly>
                    </div>
                    </div>
                    <div class="col-12">
                    <div class="mb-3">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="batchPriority" name="is_priority" value="1" <?php echo $batchPriority === 1 ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="batchPriority">Mark this batch as priority</label>
                        </div>
                    </div>
                    </div>
                    <div class="col-12">
                    <div class="mb-3">
                        <label class="form-label" for="batchPaperCount">Number of papers</label>
                        <input class="form-control" type="number" id="batchPaperCount" name="paper_count" min="1" max="9999" value="<?php echo $paperCount; ?>" required>
                    </div>
                    </div>
                    <div class="col-12">
                    <div class="mb-3">
                        <label class="form-label" for="batchRemarks">Batch remarks</label>
                        <textarea class="form-control" id="batchRemarks" name="remarks" rows="2"><?php echo sanitize($batchRemarks); ?></textarea>
                    </div>
                    </div>
                    </div>
                    </div>
                    <div class="modal-footer">
                        <?php if (!$isEmbeddedBatchAction): ?><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><?php endif; ?>
                        <button class="btn btn-primary" type="submit" name="save_batch" id="saveBatchButton" <?php echo $setupError ? 'disabled' : ''; ?>><i class="bi bi-archive me-1"></i> Save Bulk Papers</button>
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
                    <h5 class="card-title text-primary mb-0">Bulk Papers History</h5>
                    <form method="GET" class="d-flex gap-2">
                        <input class="form-control" type="search" name="search" placeholder="Search batches or papers" value="<?php echo sanitize($search); ?>">
                        <button class="btn btn-outline-primary" type="submit" aria-label="Search"><i class="bi bi-search"></i></button>
                    </form>
                </div>
                <div class="table-responsive d-none d-md-block batch-history-table">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>Date</th><th>Batch</th><th>Type</th><th>Status</th><th>From / Recipient</th><th>Papers</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php if (!$batches): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No batches found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($batches as $batch): ?>
                                    <tr>
                                        <td><?php echo sanitize($batch['batch_date']); ?></td>
                                        <td class="fw-semibold">
                                            <?php echo sanitize($batch['batch_code']); ?>
                                            <?php if ((int)$batch['is_priority'] === 1): ?>
                                                <span class="badge text-bg-warning ms-1"><i class="bi bi-star-fill me-1" aria-hidden="true"></i>Priority</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo sanitize($batch['batch_type']); ?></td>
                                        <td><span class="badge <?php echo $batch['status'] === 'Released' ? 'text-bg-success' : ($batch['status'] === 'For Release' ? 'text-bg-primary' : 'text-bg-warning'); ?>"><?php echo sanitize($batch['status']); ?></span></td>
                                        <td><?php echo sanitize($batch['sender_type']); ?><br><small class="text-muted"><?php echo sanitize($batch['recipient_contact_type']); ?><?php echo $batch['recipient'] !== '' ? ': ' . sanitize($batch['recipient']) : ''; ?></small></td>
                                        <td><?php echo (int)$batch['paper_count']; ?></td>
                                        <td class="text-nowrap">
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                    <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                                                    <input type="hidden" name="priority_batch_id" value="<?php echo (int)$batch['batch_id']; ?>">
                                                    <button class="btn btn-sm <?php echo (int)$batch['is_priority'] === 1 ? 'btn-warning' : 'btn-outline-warning'; ?>" type="submit" name="toggle_batch_priority" title="<?php echo (int)$batch['is_priority'] === 1 ? 'Remove priority' : 'Mark as priority'; ?>">
                                                        <i class="bi bi-star<?php echo (int)$batch['is_priority'] === 1 ? '-fill' : ''; ?>" aria-hidden="true"></i>
                                                    </button>
                                                </form>
                                                <?php if ($batch['status'] !== 'Released'): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                        <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                                                        <input type="hidden" name="status_batch_id" value="<?php echo (int)$batch['batch_id']; ?>">
                                                        <button class="btn btn-sm <?php echo $batch['status'] === 'For Release' ? 'btn-success' : 'btn-outline-primary'; ?>" type="submit" name="set_batch_status"
                                                            data-confirm-title="<?php echo $batch['status'] === 'For Release' ? 'Mark batch as Released?' : 'Move batch to For Release?'; ?>"
                                                            data-confirm-message="<?php echo $batch['status'] === 'For Release' ? 'This will mark the batch and its linked papers as Released.' : 'This will move the batch and its linked papers to For Release.'; ?>"
                                                            data-confirm-button="<?php echo $batch['status'] === 'For Release' ? 'Released' : 'For Release'; ?>">
                                                            <?php echo $batch['status'] === 'For Release' ? 'Released' : 'For Release'; ?>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            <button class="btn btn-sm btn-outline-secondary edit-batch" type="button" data-bs-toggle="modal" data-bs-target="#create-batch"
                                                data-batch-id="<?php echo (int)$batch['batch_id']; ?>"
                                                data-document-type="<?php echo sanitize($batch['document_type']); ?>"
                                                data-batch-type="<?php echo sanitize($batch['batch_type']); ?>"
                                                data-batch-status="<?php echo sanitize($batch['status']); ?>"
                                                data-is-priority="<?php echo (int)$batch['is_priority']; ?>"
                                                data-batch-date="<?php echo sanitize($batch['batch_date']); ?>"
                                                data-sender-type="<?php echo sanitize($batch['sender_type']); ?>"
                                                data-recipient-type="<?php echo sanitize($batch['recipient_contact_type']); ?>"
                                                data-recipient="<?php echo sanitize($batch['recipient']); ?>"
                                                data-paper-count="<?php echo (int)$batch['paper_count']; ?>"
                                                data-remarks="<?php echo sanitize($batch['remarks'] ?? ''); ?>"
                                                data-batch-code="<?php echo sanitize($batch['batch_code']); ?>"
                                                title="Edit Bulk Papers"><i class="bi bi-pencil"></i></button>
                                            <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#papers<?php echo (int)$batch['batch_id']; ?>" aria-expanded="false" aria-controls="papers<?php echo (int)$batch['batch_id']; ?>" title="View papers"><i class="bi bi-eye"></i></button>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                                                <input type="hidden" name="batch_id" value="<?php echo (int)$batch['batch_id']; ?>">
                                                <?php if (!empty($batch['tracking_token'])): ?>
                                                    <button class="btn btn-sm btn-outline-success" type="button" onclick="showBatchQr(<?php echo htmlspecialchars(json_encode($batch['tracking_token'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($batch['batch_code'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)" title="Show batch tracking QR code" aria-label="Show tracking QR code for <?php echo sanitize($batch['batch_code']); ?>"><i class="bi bi-qr-code"></i></button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-outline-danger" type="submit" name="delete_batch" title="Delete batch" data-confirm-title="Delete this batch?" data-confirm-message="This permanently deletes the batch and all of its associated papers." data-confirm-button="Delete batch"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <tr class="collapse" id="papers<?php echo (int)$batch['batch_id']; ?>">
                                        <td colspan="7" class="bg-light">
                                            <div class="py-2">
                                                <?php if (trim((string)$batch['remarks']) !== ''): ?>
                                                    <p class="small mb-2"><strong>Batch remarks:</strong> <?php echo nl2br(sanitize($batch['remarks'])); ?></p>
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
                                                                <form method="POST">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                                    <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                                                                    <input type="hidden" name="paper_id" value="<?php echo (int)$paper['paper_id']; ?>">
                                                                    <button class="btn btn-sm btn-outline-danger" type="submit" name="delete_batch_paper" data-confirm-title="Remove this paper?" data-confirm-message="This removes the paper and its linked document record from the batch." data-confirm-button="Remove paper">
                                                                        <i class="bi bi-trash me-1" aria-hidden="true"></i>Remove
                                                                    </button>
                                                                </form>
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
                <div class="batch-history-cards d-md-none">
                    <?php if (!$batches): ?>
                        <div class="rounded-3 border bg-light px-3 py-4 text-center text-muted">No batches found.</div>
                    <?php else: ?>
                        <div class="d-grid gap-3">
                            <?php foreach ($batches as $batch): ?>
                                <article class="batch-history-card border rounded-3 bg-white p-3">
                                    <div class="d-flex align-items-start justify-content-between gap-3">
                                        <div class="min-w-0">
                                            <h6 class="mb-1 text-break fw-bold">
                                                <?php echo sanitize($batch['batch_code']); ?>
                                                <?php if ((int)$batch['is_priority'] === 1): ?>
                                                    <span class="badge text-bg-warning"><i class="bi bi-star-fill me-1" aria-hidden="true"></i>Priority</span>
                                                <?php endif; ?>
                                            </h6>
                                            <p class="mb-0 small text-muted"><?php echo sanitize(date('M j, Y', strtotime($batch['batch_date']))); ?></p>
                                        </div>
                                        <span class="badge rounded-pill flex-shrink-0 <?php echo $batch['status'] === 'Released' ? 'text-bg-success' : ($batch['status'] === 'For Release' ? 'text-bg-primary' : 'text-bg-warning'); ?>"><?php echo sanitize($batch['status']); ?></span>
                                    </div>
                                    <dl class="row small g-2 mt-2 mb-3">
                                        <dt class="col-4 text-muted">Type</dt>
                                        <dd class="col-8 mb-0 text-break"><?php echo sanitize($batch['batch_type']); ?></dd>
                                        <dt class="col-4 text-muted">From</dt>
                                        <dd class="col-8 mb-0 text-break"><?php echo sanitize($batch['sender_type']); ?></dd>
                                        <dt class="col-4 text-muted">Recipient</dt>
                                        <dd class="col-8 mb-0 text-break"><?php echo sanitize($batch['recipient_contact_type']); ?><?php echo $batch['recipient'] !== '' ? ': ' . sanitize($batch['recipient']) : ''; ?></dd>
                                        <dt class="col-4 text-muted">Papers</dt>
                                        <dd class="col-8 mb-0"><?php echo (int)$batch['paper_count']; ?></dd>
                                    </dl>
                                    <div class="d-flex flex-wrap gap-2">
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                                                <input type="hidden" name="priority_batch_id" value="<?php echo (int)$batch['batch_id']; ?>">
                                                <button class="btn btn-sm <?php echo (int)$batch['is_priority'] === 1 ? 'btn-warning' : 'btn-outline-warning'; ?>" type="submit" name="toggle_batch_priority">
                                                    <i class="bi bi-star<?php echo (int)$batch['is_priority'] === 1 ? '-fill' : ''; ?> me-1" aria-hidden="true"></i><?php echo (int)$batch['is_priority'] === 1 ? 'Priority' : 'Set priority'; ?>
                                                </button>
                                            </form>
                                            <?php if ($batch['status'] !== 'Released'): ?>
                                                <form method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                    <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                                                    <input type="hidden" name="status_batch_id" value="<?php echo (int)$batch['batch_id']; ?>">
                                                    <button class="btn btn-sm <?php echo $batch['status'] === 'For Release' ? 'btn-success' : 'btn-outline-primary'; ?>" type="submit" name="set_batch_status"
                                                        data-confirm-title="<?php echo $batch['status'] === 'For Release' ? 'Mark batch as Released?' : 'Move batch to For Release?'; ?>"
                                                        data-confirm-message="<?php echo $batch['status'] === 'For Release' ? 'This will mark the batch and its linked papers as Released.' : 'This will move the batch and its linked papers to For Release.'; ?>"
                                                        data-confirm-button="<?php echo $batch['status'] === 'For Release' ? 'Released' : 'For Release'; ?>">
                                                        <?php echo $batch['status'] === 'For Release' ? 'Released' : 'For Release'; ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-secondary edit-batch" type="button" data-bs-toggle="modal" data-bs-target="#create-batch"
                                            data-batch-id="<?php echo (int)$batch['batch_id']; ?>"
                                            data-document-type="<?php echo sanitize($batch['document_type']); ?>"
                                            data-batch-type="<?php echo sanitize($batch['batch_type']); ?>"
                                            data-batch-status="<?php echo sanitize($batch['status']); ?>"
                                            data-is-priority="<?php echo (int)$batch['is_priority']; ?>"
                                            data-batch-date="<?php echo sanitize($batch['batch_date']); ?>"
                                            data-sender-type="<?php echo sanitize($batch['sender_type']); ?>"
                                            data-recipient-type="<?php echo sanitize($batch['recipient_contact_type']); ?>"
                                            data-recipient="<?php echo sanitize($batch['recipient']); ?>"
                                            data-paper-count="<?php echo (int)$batch['paper_count']; ?>"
                                            data-remarks="<?php echo sanitize($batch['remarks'] ?? ''); ?>"
                                            data-batch-code="<?php echo sanitize($batch['batch_code']); ?>">
                                            <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit Bulk Papers
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#mobile-papers<?php echo (int)$batch['batch_id']; ?>" aria-expanded="false" aria-controls="mobile-papers<?php echo (int)$batch['batch_id']; ?>">
                                            <i class="bi bi-eye me-1" aria-hidden="true"></i>View papers
                                        </button>
                                        <?php if (!empty($batch['tracking_token'])): ?>
                                            <button class="btn btn-sm btn-outline-success" type="button" onclick="showBatchQr(<?php echo htmlspecialchars(json_encode($batch['tracking_token'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($batch['batch_code'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                                                <i class="bi bi-qr-code me-1" aria-hidden="true"></i>Tracking QR
                                            </button>
                                        <?php endif; ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                            <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                                            <input type="hidden" name="batch_id" value="<?php echo (int)$batch['batch_id']; ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit" name="delete_batch" data-confirm-title="Delete this batch?" data-confirm-message="This permanently deletes the batch and all of its associated papers." data-confirm-button="Delete batch">
                                                <i class="bi bi-trash me-1" aria-hidden="true"></i>Delete
                                            </button>
                                        </form>
                                    </div>
                                    <div class="collapse mt-3" id="mobile-papers<?php echo (int)$batch['batch_id']; ?>">
                                        <div class="rounded-3 bg-light p-3">
                                            <?php if (trim((string)$batch['remarks']) !== ''): ?>
                                                <p class="small mb-3"><strong>Batch remarks:</strong><br><?php echo nl2br(sanitize($batch['remarks'])); ?></p>
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
                                                                <form method="POST">
                                                                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                                                                    <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                                                                    <input type="hidden" name="paper_id" value="<?php echo (int)$paper['paper_id']; ?>">
                                                                    <button class="btn btn-sm btn-outline-danger" type="submit" name="delete_batch_paper" data-confirm-title="Remove this paper?" data-confirm-message="This removes the paper and its linked document record from the batch." data-confirm-button="Remove paper">
                                                                        <i class="bi bi-trash me-1" aria-hidden="true"></i>Remove
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ol>
                                            <?php else: ?>
                                                <p class="small text-muted mb-0">No papers are recorded in this batch.</p>
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
                        <h5 class="modal-title text-primary" id="editBatchPaperTitle">Edit Paper in Bulk Papers</h5>
                        <p class="small text-muted mb-0 mt-1">Update the paper title and its notes.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
                    <input type="hidden" name="token" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                    <input type="hidden" id="editBatchPaperId" name="paper_id">
                    <div class="mb-3">
                        <label class="form-label" for="editBatchPaperSubject">Paper title</label>
                        <input class="form-control" type="text" id="editBatchPaperSubject" name="paper_subject" maxlength="255" required>
                    </div>
                    <div>
                        <label class="form-label" for="editBatchPaperRemarks">Paper notes</label>
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
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title text-primary" id="batchQrTitle">Batch tracking QR</h5>
                    <p class="mb-0 mt-1 small text-muted" id="batchQrCode"></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <canvas id="batchQrCanvas" class="img-fluid mx-auto" aria-label="QR code for tracking this batch"></canvas>
                <p class="small text-muted mt-3 mb-2">Scan to view the batch status, priority, and paper count.</p>
                <a id="batchTrackingLink" href="#" target="_blank" rel="noopener noreferrer" class="small text-break">Open batch tracker</a>
                <p id="batchQrError" class="small text-danger mt-3 mb-0" role="alert" hidden></p>
            </div>
            <div class="modal-footer">
                <a id="downloadBatchQr" class="btn btn-primary disabled" aria-disabled="true" download="batch-tracking.png"><i class="bi bi-download me-1" aria-hidden="true"></i>Download QR</a>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<div class="<?php echo $isEmbeddedBatchAction && $embeddedBatchAction === 'scanner' ? 'embedded-batch-tool embedded-scanner-tool' : 'modal fade'; ?>" id="batchScannerModal" tabindex="-1" aria-labelledby="batchScannerTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title text-primary" id="batchScannerTitle">CEIT Batch QR Scanner</h5>
                    <p class="mb-0 mt-1 small text-muted">Only valid QR codes issued by this batch tracking system will be accepted.</p>
                </div>
                <?php if (!$isEmbeddedBatchAction): ?><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button><?php endif; ?>
            </div>
            <div class="modal-body">
                <input type="hidden" id="batchScannerCsrf" value="<?php echo sanitize(csrf_token()); ?>">
                <input type="hidden" id="batchScannerSessionToken" value="<?php echo sanitize($_SESSION['batch_papers_token']); ?>">
                <div class="ratio ratio-4x3 bg-dark rounded overflow-hidden">
                <video id="batchScannerVideo" class="w-100 h-100" style="object-fit: cover;" autoplay muted playsinline aria-label="Camera view for scanning a CEIT batch QR"></video>
                </div>
                <p id="batchScannerStatus" class="small mt-3 mb-2" role="status" aria-live="polite">Start the camera and point it at a CEIT batch tracking QR code.</p>
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
<script src="qr-tools.bundle.js"></script>
<script>

function showBatchQr(token, batchCode) {
    const trackingUrl = new URL('batch_tracker', window.location.href);
    trackingUrl.search = new URLSearchParams({ token }).toString();
    const canvas = document.getElementById('batchQrCanvas');
    const error = document.getElementById('batchQrError');
    const modal = document.getElementById('batchQrModal');
    const download = document.getElementById('downloadBatchQr');
    document.getElementById('batchQrCode').textContent = batchCode;
    document.getElementById('batchTrackingLink').href = trackingUrl.href;
    download.classList.add('disabled');
    download.setAttribute('aria-disabled', 'true');
    download.removeAttribute('href');
    download.download = batchCode.replace(/[^a-z0-9_-]/gi, '_') + '-tracking-qr.png';
    error.hidden = true;

    if (!window.QRCode || typeof window.QRCode.toCanvas !== 'function') {
        error.textContent = 'The QR generator could not be loaded. Refresh the page and try again.';
        error.hidden = false;
    } else {
        window.QRCode.toCanvas(canvas, trackingUrl.href, { width: 480, margin: 2, errorCorrectionLevel: 'H', color: { dark: '#064e3b', light: '#ffffff' } }, function (generationError) {
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
    const batchModal = document.getElementById('create-batch');
    const batchForm = batchModal.querySelector('form');
    const isEmbeddedBatchAction = document.body.classList.contains('admin-embedded-body');
    const recipientType = document.getElementById('batchRecipientType');
    const recipientInput = document.getElementById('batchRecipient');
    const recipientLabel = document.querySelector('label[for="batchRecipient"]');
    const batchIdInput = document.getElementById('editBatchId');
    const batchTypeInput = document.getElementById('batchType');
    const batchPriorityInput = document.getElementById('batchPriority');
    const title = document.getElementById('createBatchTitle');
    const saveButton = document.getElementById('saveBatchButton');

    function updateRecipientField(clearUnassignedValue) {
        const type = recipientType.value;
        if (type === 'Unassigned') {
            recipientLabel.textContent = 'Recipient';
            recipientInput.type = 'text';
            recipientInput.placeholder = 'No recipient assigned';
            recipientInput.required = false;
            recipientInput.readOnly = true;
            if (clearUnassignedValue) recipientInput.value = '';
            return;
        }
        recipientLabel.textContent = type === 'Email' ? 'Recipient email' : 'Recipient contact number';
        recipientInput.type = type === 'Email' ? 'email' : 'tel';
        recipientInput.placeholder = type === 'Email' ? 'name@example.com' : 'e.g. 09123456789';
        recipientInput.required = true;
        recipientInput.readOnly = false;
        if (type === 'Contact') {
            recipientInput.inputMode = 'numeric';
            recipientInput.maxLength = 11;
            recipientInput.pattern = '[0-9]{11}';
            recipientInput.title = 'Enter exactly 11 digits.';
        } else {
            recipientInput.removeAttribute('inputmode');
            recipientInput.removeAttribute('pattern');
            recipientInput.removeAttribute('title');
            recipientInput.maxLength = 255;
        }
    }

    recipientInput.addEventListener('input', function () {
        if (recipientType.value === 'Contact') {
            recipientInput.value = recipientInput.value.replace(/\D/g, '').slice(0, 11);
        }
    });
    recipientType.addEventListener('change', function () { updateRecipientField(true); });
    batchModal.addEventListener('show.bs.modal', function (event) {
        const trigger = event.relatedTarget;
        if (trigger && trigger.classList.contains('edit-batch')) {
            batchIdInput.value = trigger.dataset.batchId || '';
            document.getElementById('documentType').value = trigger.dataset.documentType || 'Incoming';
            batchTypeInput.value = trigger.dataset.batchType || '';
            batchPriorityInput.checked = trigger.dataset.isPriority === '1';
            document.getElementById('batchDate').value = trigger.dataset.batchDate || '';
            document.getElementById('batchSenderType').value = trigger.dataset.senderType || 'Student';
            recipientType.value = trigger.dataset.recipientType || 'Unassigned';
            recipientInput.value = trigger.dataset.recipient || '';
            document.getElementById('batchPaperCount').value = trigger.dataset.paperCount || '1';
            document.getElementById('batchRemarks').value = trigger.dataset.remarks || '';
            title.textContent = 'Edit Bulk Papers';
            saveButton.innerHTML = '<i class="bi bi-check-lg me-1" aria-hidden="true"></i>Save Bulk Papers Changes';
        } else if (batchModal.dataset.preserveValues === 'true') {
            batchIdInput.value = batchModal.dataset.postedBatchId || '';
            const editing = batchIdInput.value !== '' && batchIdInput.value !== '0';
            title.textContent = editing ? 'Edit Bulk Papers' : 'Create Bulk Papers';
            saveButton.innerHTML = editing
                ? '<i class="bi bi-check-lg me-1" aria-hidden="true"></i>Save Bulk Papers Changes'
                : '<i class="bi bi-archive me-1" aria-hidden="true"></i>Save Bulk Papers';
        } else {
            batchForm.reset();
            batchIdInput.value = '';
            document.getElementById('documentType').value = 'Incoming';
            batchTypeInput.value = '';
            batchPriorityInput.checked = false;
            document.getElementById('batchDate').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('batchSenderType').value = 'Student';
            recipientType.value = 'Unassigned';
            document.getElementById('batchPaperCount').value = '1';
            title.textContent = 'Create Bulk Papers';
            saveButton.innerHTML = '<i class="bi bi-archive me-1" aria-hidden="true"></i>Save Bulk Papers';
        }
        updateRecipientField(false);
    });
    batchModal.addEventListener('hidden.bs.modal', function () {
        batchIdInput.value = '';
        title.textContent = 'Create Bulk Papers';
        saveButton.innerHTML = '<i class="bi bi-archive me-1" aria-hidden="true"></i>Save Bulk Papers';
        updateRecipientField(false);
    });

    if (isEmbeddedBatchAction) {
        updateRecipientField(false);
    } else if (batchModal.dataset.openOnLoad === 'true' || window.location.hash === '#create-batch') {
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

    function showBatchResult(batch) {
            const heading = document.createElement('strong');
            heading.textContent = batch.code + ' · ' + batch.type + ' · ' + batch.date;
            const count = document.createElement('p');
            count.className = 'mb-0 mt-2';
            count.textContent = batch.paperCount + (batch.paperCount === 1 ? ' paper' : ' papers') +
                ' · Status: ' + batch.status + (batch.isPriority ? ' · Priority' : '');
            resultPanel.replaceChildren(heading, count);
        resultPanel.hidden = false;
    }

    async function validateSystemQr(value) {
        let scannedUrl;
        try {
            scannedUrl = new URL(value);
        } catch (error) {
            showScannerError('Not a CEIT batch tracking QR. Keep scanning for a valid system code.');
            return false;
        }

        const expectedPath = new URL('batch_tracker', window.location.href).pathname;
        const token = scannedUrl.searchParams.get('token');
        const expectedUrl = new URL('batch_tracker', window.location.href);
        expectedUrl.search = new URLSearchParams({ token: token || '' }).toString();
        if (scannedUrl.origin !== window.location.origin || scannedUrl.pathname !== expectedPath ||
            scannedUrl.hash !== '' || [...scannedUrl.searchParams.keys()].length !== 1 ||
            !/^[a-f0-9]{64}$/.test(token || '') || scannedUrl.href !== expectedUrl.href) {
            showScannerError('Not a CEIT batch tracking QR. Keep scanning for a valid system code.');
            return false;
        }

        const formData = new FormData();
        formData.append('csrf_token', document.getElementById('batchScannerCsrf').value);
        formData.append('token', document.getElementById('batchScannerSessionToken').value);
        formData.append('scan_batch_qr', '1');
        formData.append('tracking_token', token);
        try {
            const response = await fetch(window.location.pathname, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                showScannerError(data.message || 'This QR code is not registered in the CEIT batch system.');
                return false;
            }
            stopScanner();
            status.textContent = 'Verified CEIT batch QR code.';
            status.className = 'small mt-3 mb-2 text-success';
            showBatchResult(data.batch);
            return true;
        } catch (error) {
            showScannerError('The QR code could not be verified. Check the connection and scan again.');
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
            scanContext.drawImage(imageBitmap, 0, 0, scanCanvas.width, scanCanvas.height);
            const image = scanContext.getImageData(0, 0, scanCanvas.width, scanCanvas.height);
            const code = window.jsQR(image.data, image.width, image.height, { inversionAttempts: 'attemptBoth' });
            if (!code) {
                showScannerError('No readable QR code was found in that image.');
                return;
            }
            await validateSystemQr(code.data);
        } catch (error) {
            showScannerError('Could not read the uploaded image. Try another QR image.');
        } finally {
            if (imageBitmap) imageBitmap.close();
        }
    });

    function scanFrameLoop() {
        if (!scanning || video.readyState < HTMLMediaElement.HAVE_CURRENT_DATA) {
            if (scanning) scanFrame = requestAnimationFrame(scanFrameLoop);
            return;
        }
        const scale = Math.min(1, 640 / video.videoWidth);
        scanCanvas.width = Math.round(video.videoWidth * scale);
        scanCanvas.height = Math.round(video.videoHeight * scale);
        scanContext.drawImage(video, 0, 0, scanCanvas.width, scanCanvas.height);
        const image = scanContext.getImageData(0, 0, scanCanvas.width, scanCanvas.height);
        const code = window.jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' });
        if (code && !verifying && code.data !== lastRejectedValue) {
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
        scanFrame = requestAnimationFrame(scanFrameLoop);
    }

    startButton.addEventListener('click', async function () {
        resultPanel.hidden = true;
        resultPanel.replaceChildren();
        lastRejectedValue = '';
        verifying = false;
        if (!window.isSecureContext) {
            showScannerError('Camera access is blocked because this page is not using a secure HTTPS connection. On a phone, “localhost” means the phone itself. Open this site using an HTTPS address with a trusted certificate, or upload a QR image below.');
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
            status.textContent = 'Camera ready. Point it at a CEIT batch tracking QR code.';
            scanFrame = requestAnimationFrame(scanFrameLoop);
        } catch (error) {
            stopScanner();
            showScannerError(error.name === 'NotAllowedError'
                ? 'Camera access was denied. Allow camera access in the browser settings and try again.'
                : 'Could not start the camera. Check that a camera is available and not in use.');
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
