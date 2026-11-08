function editDocument(data) {
    document.getElementById('editId').value = data.id;
    document.getElementById('editClientPhone').value = data.clientPhone || '';
    document.getElementById('editSubject').value = data.subject || '';
    document.getElementById('editRemarks').value = data.remarks || '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
}

function showDocumentTrackingQr(trackerUrl, reference) {
    const canvas = document.getElementById('documentTrackingQrCanvas');
    const error = document.getElementById('documentTrackingQrError');
    const link = document.getElementById('documentTrackingQrLink');
    const download = document.getElementById('downloadDocumentTrackingQr');
    document.getElementById('documentTrackingQrReference').textContent = reference;
    link.href = trackerUrl;
    link.title = 'Open the tracking page in a new tab';
    error.hidden = true;

    // Clear any QR left over from a previously opened document.
    const context = canvas.getContext('2d');
    if (context) {
        context.clearRect(0, 0, canvas.width, canvas.height);
    }

    download.classList.add('disabled');
    download.setAttribute('aria-disabled', 'true');
    download.removeAttribute('href');
    download.download = reference.toLowerCase() + '-tracking-qr.png';

    if (!window.QRCode || typeof window.QRCode.toCanvas !== 'function') {
        error.textContent = 'The QR generator could not be loaded. Refresh the page and try again.';
        error.hidden = false;
    } else {
        window.QRCode.toCanvas(canvas, trackerUrl, { width: 480, margin: 2, errorCorrectionLevel: 'H', color: { dark: '#064e3b', light: '#ffffff' } }, function (generationError) {
            if (generationError) {
                if (context) {
                    context.clearRect(0, 0, canvas.width, canvas.height);
                }
                error.textContent = 'Could not generate the document tracking QR code.';
                error.hidden = false;
                return;
            }
            download.href = canvas.toDataURL('image/png');
            download.classList.remove('disabled');
            download.setAttribute('aria-disabled', 'false');
        });
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('documentTrackingQrModal')).show();
}

// Row buttons carry their data in attributes, so one handler covers every row.
document.addEventListener('click', function (event) {
    const referenceButton = event.target.closest('.js-university-reference');
    if (referenceButton) {
        document.getElementById('universityReferenceDocumentId').value = referenceButton.dataset.documentId;
        document.getElementById('universityReferenceDocument').textContent = referenceButton.dataset.documentReference;
        document.getElementById('universityReferenceInput').value = referenceButton.dataset.universityReference || '';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('universityReferenceModal')).show();
        return;
    }
    const editButton = event.target.closest('.js-edit-document');
    if (editButton) {
        let data = null;
        try {
            data = JSON.parse(editButton.dataset.document || '{}');
        } catch (error) {
            return;
        }
        editDocument(data);
        return;
    }
    const qrButton = event.target.closest('.js-show-tracking-qr');
    if (qrButton) {
        showDocumentTrackingQr(qrButton.dataset.trackerUrl, qrButton.dataset.reference);
    }
});

document.addEventListener('DOMContentLoaded', function () {
    const confirmModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('releaseSmsConfirmModal'));
    const smsForm = document.getElementById('releaseSmsForm');
    const smsSubmit = document.getElementById('releaseSmsSubmit');
    const validPhonePattern = /^[0-9]{11}$/;

    document.querySelectorAll('.js-send-release-sms').forEach(function (button) {
        button.addEventListener('click', function () {
            const status = button.dataset.documentStatus;
            const phone = button.dataset.documentPhone.trim();
            const hasValidPhone = validPhonePattern.test(phone);

            document.getElementById('releaseSmsDocumentId').value = button.dataset.documentId;
            document.getElementById('releaseSmsSubject').textContent = button.dataset.documentSubject;
            document.getElementById('releaseSmsRecipient').textContent = button.dataset.documentRecipient;
            document.getElementById('releaseSmsPhone').textContent = phone || 'Not provided';
            document.getElementById('releaseSmsDescription').textContent = status === 'For Release'
                ? 'This will notify the recipient that their document is ready to claim.'
                : 'This will notify the recipient that their document has been released.';
            document.getElementById('releaseSmsMissingPhone').hidden = hasValidPhone;
            smsSubmit.disabled = !hasValidPhone;
            confirmModal.show();
        });
    });

    // Prevent a double click from sending the same SMS twice. The disabled button
    // is not included in the POST, so submit via the form's own name/value pair.
    smsForm.addEventListener('submit', function () {
        window.setTimeout(function () {
            smsSubmit.disabled = true;
            smsSubmit.textContent = 'Sending…';
        }, 0);
    });

    const outcomeModal = document.getElementById('releaseSmsOutcomeModal');
    if (outcomeModal) {
        bootstrap.Modal.getOrCreateInstance(outcomeModal).show();
    }
});
